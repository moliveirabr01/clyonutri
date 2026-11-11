<?php

declare(strict_types=1);

namespace ClyoNutri\Domain\Notification;

use ClyoNutri\Core\Database\Connection;
use ClyoNutri\Core\Database\SchemaInfo;
use ClyoNutri\Core\Mail\Mailer;
use ClyoNutri\Core\Mail\MailerFactory;
use ClyoNutri\Core\Security\PlatformPolicy;
use PDO;

/**
 * v34: central de notificações do backoffice (equipe da plataforma, principalmente o superadmin).
 *
 * NÃO cria tabela. Reaproveita o domínio de avisos do NI2-95 (migration 52) com recipient_type = 'staff':
 *  - notification_events       um aviso por pessoa da equipe (read_at = lido); dedupe_key evita aviso repetido;
 *  - notification_deliveries   no máximo uma linha "email" por aviso (nunca "push"), drenada pelo mesmo cron;
 *  - notification_preferences  uma linha por (pessoa, tipo de evento). Reuso de colunas, de propósito:
 *                              push_enabled  = "mostrar na central" (padrão 1, sem linha vale ligado);
 *                              email_enabled = "avisar também por e-mail" (padrão 0, sem linha vale desligado).
 *
 * Regras:
 *  - Cada evento tem um papel mínimo de leitura (permission) e a lista de papéis que o recebem (roles; o padrão
 *    é só o superadmin). Só recebe quem está ativo (users.status = 'active') e a política permite.
 *  - notify*() NUNCA lança: o cadastro, o cancelamento etc. já foram gravados e seguem, mesmo que o aviso falhe.
 *    Falha vai para o log só com o nome da classe do erro (sem e-mail, token ou texto de exceção).
 *  - Quem dispara publica DEPOIS do commit da ação. O que se perder (queda, outra transação aberta) é recuperado
 *    por sweep(): cadastros e chamados dos últimos dias e os avisos de teste vencendo/vencido. Idempotente.
 *  - E-mail: curto, sem nome, clínica nem e-mail de terceiros; só diz que há aviso novo e aponta para a central.
 *    Só é pedido se o servidor de e-mail estiver configurado (MailerFactory::describe()).
 *  - Nada de dado clínico: o texto é montado só com nome da clínica, nome/e-mail de quem se cadastrou ou foi
 *    afetado, plano, datas e protocolo.
 */
final class StaffNotificationService
{
    public const RECIPIENT_TYPE = 'staff';
    public const CATEGORY = 'platform';
    public const RETENTION_READ_DAYS = 90;
    public const TRIAL_WARNING_DAYS = 3;
    /** Janela de recuperação (dias) de cadastros e chamados cujo aviso se perdeu; e de testes já vencidos. */
    public const RECONCILE_DAYS = 3;
    public const SWEEP_MIN_SECONDS = 600;
    private const SWEEP_LIMIT = 50;

    /** Perfis que existem no console (users.role). */
    public const STAFF_ROLES = ['superadmin', 'admin_operacional', 'operator', 'support_agent', 'finance_admin', 'security_auditor'];

    public const GROUPS = [
        'cadastros' => 'Cadastros',
        'assinaturas' => 'Assinaturas e testes',
        'suporte' => 'Suporte',
        'equipe' => 'Equipe',
        'seguranca' => 'Contas e segurança',
    ];

    /**
     * Catálogo fechado. title/body usam {chaves} preenchidas por texto já limpo (nunca HTML).
     * link: caminho interno do console; só aparece para quem tem a permissão do evento.
     */
    public const EVENTS = [
        'clinic.signup' => [
            'group' => 'cadastros', 'label' => 'Nova clínica e novo profissional cadastrados',
            'title' => 'Nova clínica cadastrada',
            'body' => '{clinic} se cadastrou com {name} ({email}).{trial}',
            'permission' => 'clinics.read', 'roles' => ['superadmin'], 'link' => '/admin/clinicas?q={q}',
        ],
        'subscription.trial_started' => [
            'group' => 'assinaturas', 'label' => 'Teste grátis iniciado',
            'title' => 'Teste grátis iniciado',
            'body' => '{clinic} começou o teste do plano {plan}, válido até {date}.',
            'permission' => 'billing.read', 'roles' => ['superadmin'], 'link' => '/admin/comercial#assinantes',
        ],
        'subscription.trial_expiring' => [
            'group' => 'assinaturas', 'label' => 'Teste grátis perto de acabar (3 dias)',
            'title' => 'Teste grátis termina em breve',
            'body' => 'O teste de {clinic} (plano {plan}) termina em {date}.',
            'permission' => 'billing.read', 'roles' => ['superadmin'], 'link' => '/admin/comercial#assinantes',
        ],
        'subscription.trial_expired' => [
            'group' => 'assinaturas', 'label' => 'Teste grátis encerrado',
            'title' => 'Teste grátis encerrado',
            'body' => 'O teste de {clinic} terminou em {date} e não virou assinatura.',
            'permission' => 'billing.read', 'roles' => ['superadmin'], 'link' => '/admin/comercial#assinantes',
        ],
        'subscription.canceled' => [
            'group' => 'assinaturas', 'label' => 'Assinatura cancelada',
            'title' => 'Assinatura cancelada',
            'body' => '{clinic} cancelou o plano {plan}. O acesso segue até {date}. Protocolo {protocol}.',
            'permission' => 'billing.read', 'roles' => ['superadmin'], 'link' => '/admin/comercial#assinantes',
        ],
        'subscription.plan_changed' => [
            'group' => 'assinaturas', 'label' => 'Troca de plano feita pela clínica',
            'title' => 'Troca de plano: {kind}',
            'body' => '{clinic} trocou do plano {from} para o {to}. Já vale desde agora. A cobrança é manual: combine a diferença com a clínica. Protocolo {protocol}.',
            'permission' => 'billing.read', 'roles' => ['superadmin'], 'link' => '/admin/comercial#trocas-de-plano',
        ],
        'helpdesk.ticket_created' => [
            'group' => 'suporte', 'label' => 'Novo chamado no Service Desk',
            'title' => 'Novo chamado no Service Desk',
            'body' => '{clinic} abriu o chamado {protocol} ({type}, prioridade {priority}). Categoria: {category}.',
            'permission' => 'tickets.read', 'roles' => ['superadmin'], 'link' => '/admin/servicedesk',
        ],
        'staff.invited' => [
            'group' => 'equipe', 'label' => 'Novo usuário da equipe criado',
            'title' => 'Novo usuário da equipe',
            'body' => '{actor} criou o acesso de {name} ({role}). O convite vale por 72 horas.',
            'permission' => 'users.read', 'roles' => ['superadmin'], 'link' => '/admin/usuarios/{id}',
        ],
        'staff.invite_accepted' => [
            'group' => 'equipe', 'label' => 'Convite da equipe aceito',
            'title' => 'Convite da equipe aceito',
            'body' => '{name} ({role}) criou a senha e já pode entrar no console.',
            'permission' => 'users.read', 'roles' => ['superadmin'], 'link' => '/admin/usuarios/{id}',
        ],
        'user.suspended' => [
            'group' => 'seguranca', 'label' => 'Usuário suspenso',
            'title' => 'Usuário suspenso',
            'body' => '{actor} suspendeu {name} ({role}).{reason}',
            'permission' => 'users.read', 'roles' => ['superadmin'], 'link' => '/admin/usuarios/{id}',
        ],
        'user.reactivated' => [
            'group' => 'seguranca', 'label' => 'Usuário reativado',
            'title' => 'Usuário reativado',
            'body' => '{actor} reativou {name} ({role}).{reason}',
            'permission' => 'users.read', 'roles' => ['superadmin'], 'link' => '/admin/usuarios/{id}',
        ],
        'user.blocked' => [
            'group' => 'seguranca', 'label' => 'Usuário bloqueado',
            'title' => 'Usuário bloqueado',
            'body' => '{actor} bloqueou {name} ({role}).{reason}',
            'permission' => 'users.read', 'roles' => ['superadmin'], 'link' => '/admin/usuarios/{id}',
        ],
        'user.released' => [
            'group' => 'seguranca', 'label' => 'Usuário liberado',
            'title' => 'Usuário liberado',
            'body' => '{actor} liberou {name} ({role}) e emitiu um link para criar nova senha.{reason}',
            'permission' => 'users.read', 'roles' => ['superadmin'], 'link' => '/admin/usuarios/{id}',
        ],
        'user.password_reset' => [
            'group' => 'seguranca', 'label' => 'Senha redefinida pelo backoffice',
            'title' => 'Senha redefinida pelo backoffice',
            'body' => '{actor} pediu a redefinição de senha de {name} ({role}).{reason}',
            'permission' => 'users.read', 'roles' => ['superadmin'], 'link' => '/admin/usuarios/{id}',
        ],
        'security.login_locked' => [
            'group' => 'seguranca', 'label' => 'Conta da equipe travada por tentativas de entrada',
            'title' => 'Conta da equipe travada',
            'body' => 'O acesso de {email} ({role}) foi travado por alguns minutos depois de várias tentativas de entrada com senha errada.',
            'permission' => 'sessions.read', 'roles' => ['superadmin'], 'link' => '/admin/usuarios/{id}',
        ],
        'account.deletion_requested' => [
            'group' => 'seguranca', 'label' => 'Pedido de exclusão de conta e dos dados',
            'title' => 'Pedido de exclusão de conta',
            'body' => '{clinic} pediu a exclusão da conta e dos dados. Nada foi apagado. Protocolo {protocol}, resposta até {date}.',
            'permission' => 'users.manage', 'roles' => ['superadmin'], 'link' => '/admin/comercial#pedidos-conta',
        ],
        'account.deletion_canceled' => [
            'group' => 'seguranca', 'label' => 'Pedido de exclusão retirado pela clínica',
            'title' => 'Pedido de exclusão retirado',
            'body' => '{clinic} retirou o pedido de exclusão {protocol}. A conta segue normal, não há o que processar.',
            'permission' => 'users.manage', 'roles' => ['superadmin'], 'link' => '/admin/comercial#pedidos-conta',
        ],
    ];

    /** Ação do backoffice (UserAdministration) => evento. */
    private const USER_ACTION_EVENTS = [
        'suspender' => 'user.suspended',
        'reativar' => 'user.reactivated',
        'bloquear' => 'user.blocked',
        'liberar' => 'user.released',
        'redefinir-senha' => 'user.password_reset',
    ];

    private const LINK_PATTERN = '#^/admin/[a-z0-9/_-]{0,60}(\?q=[A-Za-z0-9%._~-]{1,240})?(\#[a-z][a-z-]{0,30})?$#';

    private PDO $db;

    public function __construct(?PDO $db = null, private ?Mailer $mailer = null, private ?\Closure $clock = null)
    {
        $this->db = $db ?? Connection::getInstance();
    }

    // ------------------------------------------------------------------ catálogo e permissões

    public function available(): bool
    {
        return SchemaInfo::hasTable($this->db, 'notification_events') && SchemaInfo::hasTable($this->db, 'notification_deliveries');
    }

    /** O papel recebe este evento? (está na lista do evento E a política permite ler o recurso) */
    public static function roleReceives(string $role, string $type): bool
    {
        $def = self::EVENTS[$type] ?? null;
        return $def !== null
            && in_array($role, $def['roles'], true)
            && PlatformPolicy::can($role, $def['permission']);
    }

    /** @return list<string> tipos de evento que o papel recebe */
    public static function typesForRole(string $role): array
    {
        return array_values(array_filter(array_keys(self::EVENTS), static fn(string $t): bool => self::roleReceives($role, $t)));
    }

    public static function roleReceivesAny(string $role): bool
    {
        return self::typesForRole($role) !== [];
    }

    /**
     * Link seguro do aviso para quem lê: só caminho interno do console, no formato esperado, e só se o papel
     * tem a permissão do evento. Qualquer outra coisa devolve null (o aviso aparece sem botão de abrir).
     */
    public static function linkFor(string $type, ?string $storedLink, string $role): ?string
    {
        $def = self::EVENTS[$type] ?? null;
        if ($def === null || $storedLink === null || $storedLink === '' || !PlatformPolicy::can($role, $def['permission'])) {
            return null;
        }
        return preg_match(self::LINK_PATTERN, $storedLink) === 1 ? $storedLink : null;
    }

    /** E-mail só vale se o servidor de saída está configurado. @return array{available:bool,reason:?string} */
    public function mailStatus(): array
    {
        if ($this->mailer !== null) {
            return ['available' => true, 'reason' => null];
        }
        try {
            $d = MailerFactory::describe();
        } catch (\Throwable) {
            return ['available' => false, 'reason' => 'O servidor de e-mail ainda não está configurado.'];
        }
        if (!empty($d['delivers']) && empty($d['problems'])) {
            return ['available' => true, 'reason' => null];
        }
        return ['available' => false, 'reason' => 'O servidor de e-mail ainda não está configurado neste ambiente.'];
    }

    // ------------------------------------------------------------------ publicação

    /**
     * Cria o aviso para cada pessoa elegível. Nunca lança. Devolve o resumo (ou 'error' => true).
     *
     * @param array<string,scalar|null> $vars valores do texto e do link ({clinic}, {name} ... {q}, {id})
     * @return array{recipients:int,created:int,duplicates:int,error?:bool}
     */
    public function notify(string $type, array $vars, string $dedupeKey, ?int $excludeUserId = null): array
    {
        $out = ['recipients' => 0, 'created' => 0, 'duplicates' => 0];
        try {
            return $this->publish($type, $vars, $dedupeKey, $excludeUserId);
        } catch (\Throwable $e) {
            error_log('[ClyoNutri][staff-notify] falha ao gerar aviso ' . $type . ': ' . get_class($e));
            return $out + ['error' => true];
        }
    }

    /** @return array{recipients:int,created:int,duplicates:int} */
    public function publish(string $type, array $vars, string $dedupeKey, ?int $excludeUserId = null): array
    {
        $def = self::EVENTS[$type] ?? null;
        if ($def === null) {
            throw new \InvalidArgumentException('Evento desconhecido.');
        }
        $out = ['recipients' => 0, 'created' => 0, 'duplicates' => 0];
        if (!$this->available()) {
            return $out;
        }
        $clean = [];
        foreach ($vars as $k => $v) {
            $clean[(string)$k] = self::cleanText($v === null ? '' : (string)$v, in_array($k, ['reason'], true) ? 140 : 90);
        }
        $title = self::fill($def['title'], $clean, 120);
        $body = self::fill($def['body'], $clean, 500);
        $link = $this->buildLink($def['link'] ?? null, $clean, $vars);
        $dedupeKey = substr($dedupeKey, 0, 120);

        $recipients = $this->recipients($type, $excludeUserId);
        $out['recipients'] = count($recipients);
        if ($recipients === []) {
            return $out;
        }
        $prefs = $this->loadPreferences(array_column($recipients, 'id'));
        $mail = $this->mailStatus();
        $now = $this->now();

        foreach ($recipients as $r) {
            $pref = $prefs[(int)$r['id']][$type] ?? ['app' => true, 'email' => false];
            if (!$pref['app']) {
                continue; // preferência desligada: não gera aviso, portanto também não entrega e-mail
            }
            $st = $this->db->prepare('SELECT 1 FROM notification_events WHERE recipient_type = ? AND recipient_id = ? AND dedupe_key = ?');
            $st->execute([self::RECIPIENT_TYPE, (int)$r['id'], $dedupeKey]);
            if ($st->fetchColumn()) {
                $out['duplicates']++;
                continue;
            }
            $own = !$this->db->inTransaction();
            if ($own) {
                $this->db->beginTransaction();
            }
            try {
                $this->db->prepare('INSERT INTO notification_events (clinic_id, event_type, category, recipient_type, recipient_id, title, body, link, critical, dedupe_key, created_at)
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, 0, ?, ?)')
                    ->execute([(int)$r['clinic_id'], $type, self::CATEGORY, self::RECIPIENT_TYPE, (int)$r['id'], $title, $body, $link, $dedupeKey, $now]);
                $eventId = (int)$this->db->lastInsertId();
                if ($pref['email']) {
                    $this->db->prepare("INSERT INTO notification_deliveries (clinic_id, event_id, channel, status, reason, attempts, next_attempt_at, sent_at, created_at, updated_at)
                        VALUES (?, ?, 'email', ?, ?, 0, ?, NULL, ?, ?)")
                        ->execute([(int)$r['clinic_id'], $eventId, $mail['available'] ? 'queued' : 'skipped', $mail['available'] ? null : 'mail_disabled', $mail['available'] ? $now : null, $now, $now]);
                }
                if ($own) {
                    $this->db->commit();
                }
                $out['created']++;
            } catch (\Throwable $e) {
                if ($own && $this->db->inTransaction()) {
                    $this->db->rollBack();
                }
                if ($e instanceof \PDOException && (string)$e->getCode() === '23000') { // corrida: outro processo criou o mesmo aviso
                    $out['duplicates']++;
                    continue;
                }
                throw $e;
            }
        }
        return $out;
    }

    /**
     * Quem recebe: ativos, com papel na lista do evento e permissão de leitura. Clínica do aviso = a clínica ativa
     * da pessoa (a "plataforma"); sem vínculo, a primeira clínica cadastrada (a coluna é obrigatória e com FK).
     *
     * @return list<array{id:int,role:string,clinic_id:int}>
     */
    private function recipients(string $type, ?int $excludeUserId): array
    {
        $def = self::EVENTS[$type];
        $roles = array_values(array_filter($def['roles'], static fn(string $r): bool => PlatformPolicy::can($r, $def['permission'])));
        if ($roles === []) {
            return [];
        }
        $in = implode(',', array_fill(0, count($roles), '?'));
        $st = $this->db->prepare("SELECT u.id, u.role,
                (SELECT cm.clinic_id FROM clinic_members cm WHERE cm.user_id = u.id AND cm.status = 'active' ORDER BY cm.id LIMIT 1) AS clinic_id
            FROM users u WHERE u.status = 'active' AND u.role IN ({$in}) ORDER BY u.id");
        $st->execute($roles);
        $fallback = null;
        $list = [];
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $row) {
            if ($excludeUserId !== null && (int)$row['id'] === $excludeUserId) {
                continue;
            }
            $cid = $row['clinic_id'] !== null ? (int)$row['clinic_id'] : 0;
            if ($cid === 0) {
                $fallback ??= (int)$this->db->query('SELECT MIN(id) FROM clinics')->fetchColumn();
                $cid = $fallback;
            }
            if ($cid > 0) {
                $list[] = ['id' => (int)$row['id'], 'role' => (string)$row['role'], 'clinic_id' => $cid];
            }
        }
        return $list;
    }

    /** @return array<int,array<string,array{app:bool,email:bool}>> */
    private function loadPreferences(array $userIds): array
    {
        $map = [];
        if ($userIds === []) {
            return $map;
        }
        $in = implode(',', array_fill(0, count($userIds), '?'));
        $st = $this->db->prepare("SELECT recipient_id, category, email_enabled, push_enabled FROM notification_preferences
            WHERE recipient_type = ? AND recipient_id IN ({$in})");
        $st->execute(array_merge([self::RECIPIENT_TYPE], array_map('intval', $userIds)));
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $row) {
            if (isset(self::EVENTS[$row['category']])) {
                $map[(int)$row['recipient_id']][(string)$row['category']] = ['app' => (bool)$row['push_enabled'], 'email' => (bool)$row['email_enabled']];
            }
        }
        return $map;
    }

    // ------------------------------------------------------------------ eventos de origem (um por ponto de disparo)

    /** Cadastro de clínica + profissional (AuthService::registerNutritionist; recuperado por sweep). */
    public function notifyClinicSignup(int $clinicId): array
    {
        try {
            $st = $this->db->prepare("SELECT c.id, c.name AS clinic_name, u.name AS user_name, u.email AS user_email
                FROM clinics c
                JOIN clinic_members cm ON cm.clinic_id = c.id AND cm.role = 'owner'
                JOIN users u ON u.id = cm.user_id AND u.role = 'nutritionist'
                WHERE c.id = ? ORDER BY cm.id LIMIT 1");
            $st->execute([$clinicId]);
            $row = $st->fetch(PDO::FETCH_ASSOC);
            if (!$row) {
                return ['recipients' => 0, 'created' => 0, 'duplicates' => 0];
            }
            $trial = '';
            $sub = $this->subscriptionRow($clinicId);
            if ($sub !== null && $sub['status'] === 'trialing' && !empty($sub['trial_ends_at'])) {
                $trial = ' Teste grátis do plano ' . $sub['plan_name'] . ' até ' . self::dateBr((string)$sub['trial_ends_at']) . '.';
            }
            return $this->notify('clinic.signup', [
                'clinic' => $row['clinic_name'], 'name' => $row['user_name'], 'email' => $row['user_email'],
                'trial' => $trial, 'q' => self::searchTerm((string)$row['clinic_name']),
            ], 'clinic.signup:' . $clinicId);
        } catch (\Throwable $e) {
            error_log('[ClyoNutri][staff-notify] falha no aviso de cadastro: ' . get_class($e));
            return ['recipients' => 0, 'created' => 0, 'duplicates' => 0, 'error' => true];
        }
    }

    /** Teste grátis iniciado fora do cadastro (assinatura criada sob demanda). */
    public function notifyTrialStarted(int $clinicId): array
    {
        try {
            $sub = $this->subscriptionRow($clinicId);
            if ($sub === null || $sub['status'] !== 'trialing') {
                return ['recipients' => 0, 'created' => 0, 'duplicates' => 0];
            }
            return $this->notify('subscription.trial_started', [
                'clinic' => $sub['clinic_name'], 'plan' => $sub['plan_name'], 'date' => self::dateBr((string)($sub['trial_ends_at'] ?? '')),
            ], 'trial_started:' . (int)$sub['id']);
        } catch (\Throwable $e) {
            error_log('[ClyoNutri][staff-notify] falha no aviso de teste iniciado: ' . get_class($e));
            return ['recipients' => 0, 'created' => 0, 'duplicates' => 0, 'error' => true];
        }
    }

    /** Assinatura cancelada (SubscriptionService::cancelSubscription, depois do commit). */
    public function notifySubscriptionCanceled(int $clinicId, string $protocol, ?string $effectiveUntil): array
    {
        try {
            $sub = $this->subscriptionRow($clinicId);
            return $this->notify('subscription.canceled', [
                'clinic' => $sub['clinic_name'] ?? ('clínica ' . $clinicId),
                'plan' => $sub['plan_name'] ?? 'contratado',
                'date' => $effectiveUntil ? self::dateBr($effectiveUntil) : 'o fim do ciclo',
                'protocol' => $protocol,
            ], 'sub_canceled:' . $protocol);
        } catch (\Throwable $e) {
            error_log('[ClyoNutri][staff-notify] falha no aviso de cancelamento: ' . get_class($e));
            return ['recipients' => 0, 'created' => 0, 'duplicates' => 0, 'error' => true];
        }
    }

    /** Troca de plano registrada (PlanChangeService::changePlan). Texto só com nomes de plano e protocolo, sem uso nem dados de pacientes. */
    public function notifyPlanChanged(int $changeId): array
    {
        try {
            $st = $this->db->prepare('SELECT h.id, h.protocol, h.change_type, c.name AS clinic_name, pf.name AS from_name, pt.name AS to_name
                FROM subscription_plan_changes h
                JOIN clinics c ON c.id = h.clinic_id
                JOIN saas_plans pf ON pf.id = h.from_plan_id
                JOIN saas_plans pt ON pt.id = h.to_plan_id
                WHERE h.id = ?');
            $st->execute([$changeId]);
            $h = $st->fetch(PDO::FETCH_ASSOC);
            if (!$h) {
                return ['recipients' => 0, 'created' => 0, 'duplicates' => 0];
            }
            return $this->notify('subscription.plan_changed', [
                'clinic' => $h['clinic_name'],
                'from' => $h['from_name'],
                'to' => $h['to_name'],
                'kind' => $h['change_type'] === 'upgrade' ? 'upgrade' : 'downgrade',
                'protocol' => $h['protocol'],
            ], 'plan_change:' . $h['protocol']);
        } catch (\Throwable $e) {
            error_log('[ClyoNutri][staff-notify] falha no aviso de troca de plano: ' . get_class($e));
            return ['recipients' => 0, 'created' => 0, 'duplicates' => 0, 'error' => true];
        }
    }

    /** Pedido de exclusão de conta registrado (AccountDeletionRequestService::request; recuperado por sweep). */
    public function notifyAccountDeletionRequested(int $requestId): array
    {
        try {
            $st = $this->db->prepare('SELECT protocol, clinic_name, deadline_at FROM account_deletion_requests WHERE id = ?');
            $st->execute([$requestId]);
            $r = $st->fetch(PDO::FETCH_ASSOC);
            if (!$r) {
                return ['recipients' => 0, 'created' => 0, 'duplicates' => 0];
            }
            return $this->notify('account.deletion_requested', [
                'clinic' => $r['clinic_name'],
                'protocol' => $r['protocol'],
                'date' => self::dateBr((string)$r['deadline_at']),
            ], 'deletion_req:' . $r['protocol']);
        } catch (\Throwable $e) {
            error_log('[ClyoNutri][staff-notify] falha no aviso de pedido de exclusão: ' . get_class($e));
            return ['recipients' => 0, 'created' => 0, 'duplicates' => 0, 'error' => true];
        }
    }

    /** Pedido de exclusão retirado pela própria clínica antes do processamento. */
    public function notifyAccountDeletionCanceled(int $requestId): array
    {
        try {
            $st = $this->db->prepare('SELECT protocol, clinic_name FROM account_deletion_requests WHERE id = ?');
            $st->execute([$requestId]);
            $r = $st->fetch(PDO::FETCH_ASSOC);
            if (!$r) {
                return ['recipients' => 0, 'created' => 0, 'duplicates' => 0];
            }
            return $this->notify('account.deletion_canceled', [
                'clinic' => $r['clinic_name'],
                'protocol' => $r['protocol'],
            ], 'deletion_cancel:' . $r['protocol']);
        } catch (\Throwable $e) {
            error_log('[ClyoNutri][staff-notify] falha no aviso de pedido de exclusão retirado: ' . get_class($e));
            return ['recipients' => 0, 'created' => 0, 'duplicates' => 0, 'error' => true];
        }
    }

    /** Chamado novo (HelpdeskTicketService::createTicket; recuperado por sweep). Sem assunto nem descrição. */
    public function notifyTicketCreated(int $ticketId): array
    {
        try {
            $st = $this->db->prepare('SELECT t.id, t.protocol, t.type, t.category, t.priority_operational, c.name AS clinic_name
                FROM helpdesk_tickets t LEFT JOIN clinics c ON c.id = t.clinic_id WHERE t.id = ?');
            $st->execute([$ticketId]);
            $t = $st->fetch(PDO::FETCH_ASSOC);
            if (!$t) {
                return ['recipients' => 0, 'created' => 0, 'duplicates' => 0];
            }
            return $this->notify('helpdesk.ticket_created', [
                'clinic' => $t['clinic_name'] ?? 'Clínica',
                'protocol' => $t['protocol'],
                'type' => \ClyoNutri\Domain\Helpdesk\HelpdeskTicketService::TYPES[$t['type']] ?? $t['type'],
                'priority' => $t['priority_operational'],
                'category' => \ClyoNutri\Domain\Helpdesk\KnowledgeBaseService::CATEGORIES[$t['category']]['name'] ?? $t['category'],
            ], 'ticket_created:' . (int)$t['id']);
        } catch (\Throwable $e) {
            error_log('[ClyoNutri][staff-notify] falha no aviso de chamado: ' . get_class($e));
            return ['recipients' => 0, 'created' => 0, 'duplicates' => 0, 'error' => true];
        }
    }

    /**
     * Ação do backoffice sobre um usuário (suspender, reativar, bloquear, liberar, redefinir senha).
     * Quem executou não recebe o próprio aviso: o aviso é para os outros superadmins.
     *
     * @param array{id:int,name?:string} $actor
     * @param array{id:int,name?:string,role?:string} $target
     */
    public function notifyUserAction(string $action, array $actor, array $target, ?string $reason): array
    {
        try {
            $type = self::USER_ACTION_EVENTS[$action] ?? null;
            if ($type === null) {
                return ['recipients' => 0, 'created' => 0, 'duplicates' => 0];
            }
            $reasonText = $reason !== null && trim($reason) !== '' ? ' Motivo: ' . $reason : '';
            return $this->notify($type, [
                'actor' => $actor['name'] ?? 'Um superadmin',
                'name' => $target['name'] ?? 'usuário',
                'role' => \ClyoNutri\Domain\Admin\UserAdministration::roleLabel((string)($target['role'] ?? '')),
                'reason' => $reasonText, 'id' => (int)$target['id'],
            ], $type . ':' . (int)$target['id'] . ':' . bin2hex(random_bytes(6)), (int)$actor['id']);
        } catch (\Throwable $e) {
            error_log('[ClyoNutri][staff-notify] falha no aviso de ação de usuário: ' . get_class($e));
            return ['recipients' => 0, 'created' => 0, 'duplicates' => 0, 'error' => true];
        }
    }

    /** Usuário da equipe criado (convite). */
    public function notifyStaffInvited(array $actor, array $target): array
    {
        try {
            return $this->notify('staff.invited', [
                'actor' => $actor['name'] ?? 'Um superadmin',
                'name' => $target['name'] ?? 'usuário',
                'role' => \ClyoNutri\Domain\Admin\UserAdministration::roleLabel((string)($target['role'] ?? '')),
                'id' => (int)$target['id'],
            ], 'staff_invited:' . (int)$target['id'], (int)$actor['id']);
        } catch (\Throwable $e) {
            error_log('[ClyoNutri][staff-notify] falha no aviso de convite: ' . get_class($e));
            return ['recipients' => 0, 'created' => 0, 'duplicates' => 0, 'error' => true];
        }
    }

    /** Convite aceito: a pessoa da equipe definiu a primeira senha. Só para papéis do console. */
    public function notifyStaffInviteAccepted(int $userId): array
    {
        try {
            $st = $this->db->prepare('SELECT id, name, role FROM users WHERE id = ?');
            $st->execute([$userId]);
            $u = $st->fetch(PDO::FETCH_ASSOC);
            if (!$u || !in_array((string)$u['role'], self::STAFF_ROLES, true)) {
                return ['recipients' => 0, 'created' => 0, 'duplicates' => 0];
            }
            return $this->notify('staff.invite_accepted', [
                'name' => $u['name'],
                'role' => \ClyoNutri\Domain\Admin\UserAdministration::roleLabel((string)$u['role']),
                'id' => (int)$u['id'],
            ], 'staff_invite_accepted:' . (int)$u['id'], (int)$u['id']);
        } catch (\Throwable $e) {
            error_log('[ClyoNutri][staff-notify] falha no aviso de convite aceito: ' . get_class($e));
            return ['recipients' => 0, 'created' => 0, 'duplicates' => 0, 'error' => true];
        }
    }

    /**
     * Conta da EQUIPE travada por tentativas de entrada. Contas de clínica/paciente não geram aviso, para
     * ninguém de fora encher a central digitando senhas erradas. No máximo um aviso por conta a cada 30 minutos.
     */
    public function notifyLoginLocked(string $email): array
    {
        try {
            $st = $this->db->prepare("SELECT id, role FROM users WHERE LOWER(email) = ? AND status = 'active'");
            $st->execute([strtolower(trim($email))]);
            $u = $st->fetch(PDO::FETCH_ASSOC);
            if (!$u || !in_array((string)$u['role'], self::STAFF_ROLES, true)) {
                return ['recipients' => 0, 'created' => 0, 'duplicates' => 0];
            }
            return $this->notify('security.login_locked', [
                'email' => strtolower(trim($email)),
                'role' => \ClyoNutri\Domain\Admin\UserAdministration::roleLabel((string)$u['role']),
                'id' => (int)$u['id'],
            ], 'login_locked:' . (int)$u['id'] . ':' . intdiv($this->ts(), 1800));
        } catch (\Throwable $e) {
            error_log('[ClyoNutri][staff-notify] falha no aviso de conta travada: ' . get_class($e));
            return ['recipients' => 0, 'created' => 0, 'duplicates' => 0, 'error' => true];
        }
    }

    // ------------------------------------------------------------------ varredura (cron e sob demanda)

    /**
     * Gera o que só existe com o passar do tempo (teste vencendo e vencido) e recupera avisos perdidos de
     * cadastros e chamados recentes. Idempotente (dedupe_key). Nunca lança.
     *
     * @return array<string,int|string>
     */
    public function sweep(): array
    {
        $r = ['signups' => 0, 'tickets' => 0, 'trial_expiring' => 0, 'trial_expired' => 0, 'plan_changes' => 0, 'deletions' => 0, 'status' => 'OK'];
        try {
            if (!$this->available()) {
                return ['status' => 'NOT_MIGRATED'] + $r;
            }
            $since = date('Y-m-d H:i:s', $this->ts() - (self::RECONCILE_DAYS + 1) * 86400);

            // Cadastros (clínicas de verdade: dono profissional; a clínica da plataforma só tem equipe)
            $st = $this->db->prepare("SELECT DISTINCT c.id FROM clinics c
                JOIN clinic_members cm ON cm.clinic_id = c.id AND cm.role = 'owner'
                JOIN users u ON u.id = cm.user_id AND u.role = 'nutritionist'
                WHERE c.created_at >= ? ORDER BY c.id DESC LIMIT " . self::SWEEP_LIMIT);
            $st->execute([$since]);
            foreach ($st->fetchAll(PDO::FETCH_COLUMN) as $cid) {
                if (!$this->eventExists('clinic.signup:' . (int)$cid)) {
                    $r['signups'] += $this->notifyClinicSignup((int)$cid)['created'];
                }
            }

            // Chamados
            if (SchemaInfo::hasTable($this->db, 'helpdesk_tickets')) {
                $st = $this->db->prepare('SELECT id FROM helpdesk_tickets WHERE created_at >= ? ORDER BY id DESC LIMIT ' . self::SWEEP_LIMIT);
                $st->execute([$since]);
                foreach ($st->fetchAll(PDO::FETCH_COLUMN) as $tid) {
                    if (!$this->eventExists('ticket_created:' . (int)$tid)) {
                        $r['tickets'] += $this->notifyTicketCreated((int)$tid)['created'];
                    }
                }
            }

            // Trocas de plano e pedidos de exclusão (recupera aviso perdido de quem acabou de pedir)
            if (SchemaInfo::hasTable($this->db, 'subscription_plan_changes')) {
                $st = $this->db->prepare('SELECT id, protocol FROM subscription_plan_changes WHERE created_at >= ? ORDER BY id DESC LIMIT ' . self::SWEEP_LIMIT);
                $st->execute([$since]);
                foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $pc) {
                    if (!$this->eventExists('plan_change:' . $pc['protocol'])) {
                        $r['plan_changes'] += $this->notifyPlanChanged((int)$pc['id'])['created'];
                    }
                }
            }
            if (SchemaInfo::hasTable($this->db, 'account_deletion_requests')) {
                $st = $this->db->prepare("SELECT id, protocol FROM account_deletion_requests WHERE requested_at >= ? AND status <> 'canceled' ORDER BY id DESC LIMIT " . self::SWEEP_LIMIT);
                $st->execute([$since]);
                foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $dr) {
                    if (!$this->eventExists('deletion_req:' . $dr['protocol'])) {
                        $r['deletions'] += $this->notifyAccountDeletionRequested((int)$dr['id'])['created'];
                    }
                }
            }

            // Testes grátis
            if (SchemaInfo::hasTable($this->db, 'clinic_subscriptions') && SchemaInfo::hasColumn($this->db, 'clinic_subscriptions', 'trial_ends_at')) {
                $sp = new \DateTimeZone('America/Sao_Paulo');
                $now = new \DateTimeImmutable('@' . $this->ts());
                $now = $now->setTimezone($sp);
                $fmt = static fn(\DateTimeImmutable $d): string => $d->format('Y-m-d H:i:s');
                $q = "SELECT cs.id, cs.trial_ends_at, c.name AS clinic_name, p.name AS plan_name
                    FROM clinic_subscriptions cs
                    JOIN clinics c ON c.id = cs.clinic_id
                    JOIN saas_plans p ON p.id = cs.plan_id
                    WHERE cs.trial_ends_at IS NOT NULL AND ";
                $st = $this->db->prepare($q . "cs.status = 'trialing' AND cs.trial_ends_at > ? AND cs.trial_ends_at <= ? ORDER BY cs.id LIMIT " . self::SWEEP_LIMIT);
                $st->execute([$fmt($now), $fmt($now->modify('+' . self::TRIAL_WARNING_DAYS . ' days'))]);
                foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $s) {
                    $r['trial_expiring'] += $this->notify('subscription.trial_expiring', [
                        'clinic' => $s['clinic_name'], 'plan' => $s['plan_name'], 'date' => self::dateBr((string)$s['trial_ends_at']),
                    ], 'trial_expiring:' . (int)$s['id'])['created'];
                }
                // Vencido: só os dos últimos dias, para não despejar histórico antigo de uma vez na primeira rodada
                $st = $this->db->prepare($q . "cs.status IN ('trialing', 'expired') AND cs.trial_ends_at <= ? AND cs.trial_ends_at >= ? ORDER BY cs.id LIMIT " . self::SWEEP_LIMIT);
                $st->execute([$fmt($now), $fmt($now->modify('-' . self::RECONCILE_DAYS . ' days'))]);
                foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $s) {
                    $r['trial_expired'] += $this->notify('subscription.trial_expired', [
                        'clinic' => $s['clinic_name'], 'plan' => $s['plan_name'], 'date' => self::dateBr((string)$s['trial_ends_at']),
                    ], 'trial_expired:' . (int)$s['id'])['created'];
                }
            }
        } catch (\Throwable $e) {
            error_log('[ClyoNutri][staff-notify] falha na varredura: ' . get_class($e));
            $r['status'] = 'error: ' . get_class($e);
        }
        return $r;
    }

    /**
     * Varredura sob demanda, no máximo uma vez a cada SWEEP_MIN_SECONDS (a mesma marca do cron: se o cron roda,
     * a tela quase nunca precisa varrer). Só para quem recebe algum aviso. Nunca lança.
     */
    public static function sweepIfDue(PDO $db, string $role, ?string $markerFile = null, int $minSeconds = self::SWEEP_MIN_SECONDS): bool
    {
        try {
            if (!self::roleReceivesAny($role)) {
                return false;
            }
            $file = $markerFile ?? (dirname(__DIR__, 3) . '/storage/staff_notifications_last_sweep');
            $last = is_file($file) ? (int)@file_get_contents($file) : 0;
            if (time() - $last < $minSeconds) {
                return false;
            }
            // Marca ANTES de varrer: duas telas abertas ao mesmo tempo não varrem juntas.
            if (@file_put_contents($file, (string)time()) === false) {
                // sem escrita em storage/: usa a sessão como limite, para não varrer a cada tela
                $sessKey = 'staff_notifications_swept_at';
                if (session_status() === PHP_SESSION_ACTIVE) {
                    if (time() - (int)($_SESSION[$sessKey] ?? 0) < $minSeconds) {
                        return false;
                    }
                    $_SESSION[$sessKey] = time();
                }
            }
            (new self($db))->sweep();
            return true;
        } catch (\Throwable $e) {
            error_log('[ClyoNutri][staff-notify] falha na varredura sob demanda: ' . get_class($e));
            return false;
        }
    }

    private function eventExists(string $dedupeKey): bool
    {
        $st = $this->db->prepare('SELECT 1 FROM notification_events WHERE recipient_type = ? AND dedupe_key = ? LIMIT 1');
        $st->execute([self::RECIPIENT_TYPE, $dedupeKey]);
        return (bool)$st->fetchColumn();
    }

    // ------------------------------------------------------------------ caixa de entrada da pessoa

    /**
     * @param array{tipo?:string,estado?:string} $filters tipo = chave do evento; estado = 'nao-lidas' | 'lidas'
     * @return array{items:list<array<string,mixed>>,total:int}
     */
    public function inbox(int $userId, string $role, array $filters, int $limit, int $offset): array
    {
        $types = $this->visibleTypes($role, $filters['tipo'] ?? '');
        if (!$this->available() || $types === []) {
            return ['items' => [], 'total' => 0];
        }
        [$where, $params] = $this->inboxWhere($userId, $types, (string)($filters['estado'] ?? ''));
        $st = $this->db->prepare('SELECT COUNT(*) FROM notification_events WHERE ' . $where);
        $st->execute($params);
        $total = (int)$st->fetchColumn();
        $st = $this->db->prepare('SELECT id, event_type, title, body, link, created_at, read_at FROM notification_events WHERE ' . $where
            . ' ORDER BY id DESC LIMIT ' . max(1, min(100, $limit)) . ' OFFSET ' . max(0, $offset));
        $st->execute($params);
        $items = [];
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $def = self::EVENTS[$r['event_type']] ?? null;
            $items[] = [
                'id' => (int)$r['id'],
                'type' => (string)$r['event_type'],
                'group' => $def['group'] ?? '',
                'label' => $def['label'] ?? (string)$r['event_type'],
                'title' => (string)$r['title'],
                'body' => (string)$r['body'],
                'href' => self::linkFor((string)$r['event_type'], $r['link'] !== null ? (string)$r['link'] : null, $role),
                'created_at' => (string)$r['created_at'],
                'read' => $r['read_at'] !== null,
            ];
        }
        return ['items' => $items, 'total' => $total];
    }

    public function unreadCount(int $userId, string $role): int
    {
        try {
            $types = $this->visibleTypes($role, '');
            if (!$this->available() || $types === []) {
                return 0;
            }
            [$where, $params] = $this->inboxWhere($userId, $types, 'nao-lidas');
            $st = $this->db->prepare('SELECT COUNT(*) FROM notification_events WHERE ' . $where);
            $st->execute($params);
            return (int)$st->fetchColumn();
        } catch (\Throwable $e) {
            error_log('[ClyoNutri][staff-notify] falha ao contar avisos: ' . get_class($e));
            return 0;
        }
    }

    /** Marca UM aviso (só se for da própria pessoa e de um tipo que o papel ainda enxerga). */
    public function markRead(int $userId, string $role, int $id): bool
    {
        $types = $this->visibleTypes($role, '');
        if (!$this->available() || $types === [] || $id < 1) {
            return false;
        }
        $in = implode(',', array_fill(0, count($types), '?'));
        $st = $this->db->prepare("UPDATE notification_events SET read_at = ? WHERE id = ? AND recipient_type = ? AND recipient_id = ? AND read_at IS NULL AND event_type IN ({$in})");
        $st->execute(array_merge([$this->now(), $id, self::RECIPIENT_TYPE, $userId], $types));
        return $st->rowCount() === 1;
    }

    /** Dados de um aviso da pessoa (para abrir o link); null se não for dela. */
    public function find(int $userId, string $role, int $id): ?array
    {
        $types = $this->visibleTypes($role, '');
        if (!$this->available() || $types === [] || $id < 1) {
            return null;
        }
        $in = implode(',', array_fill(0, count($types), '?'));
        $st = $this->db->prepare("SELECT id, event_type, link, read_at FROM notification_events WHERE id = ? AND recipient_type = ? AND recipient_id = ? AND event_type IN ({$in})");
        $st->execute(array_merge([$id, self::RECIPIENT_TYPE, $userId], $types));
        $r = $st->fetch(PDO::FETCH_ASSOC);
        if (!$r) {
            return null;
        }
        return ['id' => (int)$r['id'], 'type' => (string)$r['event_type'], 'href' => self::linkFor((string)$r['event_type'], $r['link'] !== null ? (string)$r['link'] : null, $role), 'read' => $r['read_at'] !== null];
    }

    /** Marca todos os avisos não lidos da pessoa. @return int quantos mudaram */
    public function markAllRead(int $userId, string $role): int
    {
        $types = $this->visibleTypes($role, '');
        if (!$this->available() || $types === []) {
            return 0;
        }
        $in = implode(',', array_fill(0, count($types), '?'));
        $st = $this->db->prepare("UPDATE notification_events SET read_at = ? WHERE recipient_type = ? AND recipient_id = ? AND read_at IS NULL AND event_type IN ({$in})");
        $st->execute(array_merge([$this->now(), self::RECIPIENT_TYPE, $userId], $types));
        return $st->rowCount();
    }

    /** @return list<string> */
    private function visibleTypes(string $role, mixed $only): array
    {
        $types = self::typesForRole($role);
        if (is_string($only) && $only !== '') {
            return in_array($only, $types, true) ? [$only] : [];
        }
        return $types;
    }

    /** @return array{0:string,1:list<mixed>} */
    private function inboxWhere(int $userId, array $types, string $estado): array
    {
        $in = implode(',', array_fill(0, count($types), '?'));
        $where = "recipient_type = ? AND recipient_id = ? AND event_type IN ({$in})";
        if ($estado === 'nao-lidas') {
            $where .= ' AND read_at IS NULL';
        } elseif ($estado === 'lidas') {
            $where .= ' AND read_at IS NOT NULL';
        }
        return [$where, array_merge([self::RECIPIENT_TYPE, $userId], $types)];
    }

    // ------------------------------------------------------------------ preferências

    /**
     * @return list<array{type:string,group:string,label:string,app:bool,email:bool}>
     */
    public function preferences(int $userId, string $role): array
    {
        $stored = $this->available() ? ($this->loadPreferences([$userId])[$userId] ?? []) : [];
        $list = [];
        foreach (self::typesForRole($role) as $type) {
            $p = $stored[$type] ?? ['app' => true, 'email' => false];
            $list[] = ['type' => $type, 'group' => self::EVENTS[$type]['group'], 'label' => self::EVENTS[$type]['label'], 'app' => $p['app'], 'email' => $p['email']];
        }
        return $list;
    }

    /**
     * Grava as escolhas. Só os tipos que o papel recebe. "E-mail" só liga se o servidor de e-mail está
     * configurado; sem ele, a escolha anterior fica como estava (a tela mostra a opção desligada).
     *
     * @param array<string,mixed> $app   tipo => qualquer valor = mostrar na central
     * @param array<string,mixed> $email tipo => qualquer valor = avisar também por e-mail
     */
    public function savePreferences(int $userId, string $role, array $app, array $email): int
    {
        if (!$this->available()) {
            return 0;
        }
        $mailOk = $this->mailStatus()['available'];
        $stored = $this->loadPreferences([$userId])[$userId] ?? [];
        $clinicId = $this->clinicIdFor($userId);
        $saved = 0;
        $now = $this->now();
        foreach (self::typesForRole($role) as $type) {
            $showApp = isset($app[$type]);
            $wantsMail = $mailOk ? (isset($email[$type]) && $showApp) : ($stored[$type]['email'] ?? false);
            $ex = $this->db->prepare('SELECT id FROM notification_preferences WHERE recipient_type = ? AND recipient_id = ? AND category = ?');
            $ex->execute([self::RECIPIENT_TYPE, $userId, $type]);
            $id = $ex->fetchColumn();
            if ($id) {
                $this->db->prepare('UPDATE notification_preferences SET email_enabled = ?, push_enabled = ?, updated_at = ? WHERE id = ?')
                    ->execute([$wantsMail ? 1 : 0, $showApp ? 1 : 0, $now, $id]);
            } else {
                $this->db->prepare('INSERT INTO notification_preferences (clinic_id, recipient_type, recipient_id, category, email_enabled, push_enabled, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?)')
                    ->execute([$clinicId, self::RECIPIENT_TYPE, $userId, $type, $wantsMail ? 1 : 0, $showApp ? 1 : 0, $now]);
            }
            $saved++;
        }
        return $saved;
    }

    /** O e-mail deste aviso ainda é desejado? (usado pela entrega, que roda depois, no cron) */
    public function emailWanted(int $userId, string $type): bool
    {
        return (bool)(($this->loadPreferences([$userId])[$userId][$type]['email'] ?? false));
    }

    // ------------------------------------------------------------------ retenção

    /**
     * Apaga avisos LIDOS há mais de RETENTION_READ_DAYS (90) e suas entregas. Não apaga aviso não lido e
     * nunca toca em audit_logs (a trilha de auditoria é outra tabela e não passa por aqui).
     *
     * @return array{events:int,status:string}
     */
    public function purge(?int $days = null): array
    {
        if (!$this->available()) {
            return ['events' => 0, 'status' => 'NOT_MIGRATED'];
        }
        $cut = date('Y-m-d H:i:s', $this->ts() - 86400 * ($days ?? self::RETENTION_READ_DAYS));
        $this->db->beginTransaction();
        try {
            $this->db->prepare('DELETE FROM notification_deliveries WHERE event_id IN (SELECT id FROM notification_events WHERE recipient_type = ? AND read_at IS NOT NULL AND read_at < ?)')
                ->execute([self::RECIPIENT_TYPE, $cut]);
            $st = $this->db->prepare('DELETE FROM notification_events WHERE recipient_type = ? AND read_at IS NOT NULL AND read_at < ?');
            $st->execute([self::RECIPIENT_TYPE, $cut]);
            $n = $st->rowCount();
            $this->db->commit();
            return ['events' => $n, 'status' => 'OK'];
        } catch (\Throwable $e) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            throw $e;
        }
    }

    // ------------------------------------------------------------------ utilidades

    private function clinicIdFor(int $userId): int
    {
        $st = $this->db->prepare("SELECT clinic_id FROM clinic_members WHERE user_id = ? AND status = 'active' ORDER BY id LIMIT 1");
        $st->execute([$userId]);
        $v = $st->fetchColumn();
        return $v !== false ? (int)$v : (int)$this->db->query('SELECT MIN(id) FROM clinics')->fetchColumn();
    }

    /** @return array<string,mixed>|null */
    private function subscriptionRow(int $clinicId): ?array
    {
        $st = $this->db->prepare('SELECT cs.id, cs.status, cs.trial_ends_at, c.name AS clinic_name, p.name AS plan_name
            FROM clinic_subscriptions cs JOIN clinics c ON c.id = cs.clinic_id JOIN saas_plans p ON p.id = cs.plan_id
            WHERE cs.clinic_id = ? ORDER BY cs.id DESC LIMIT 1');
        $st->execute([$clinicId]);
        $r = $st->fetch(PDO::FETCH_ASSOC);
        return $r ?: null;
    }

    /** Texto de uma linha só, sem controle, sem tag, com limite. O HTML é escapado de novo na tela. */
    public static function cleanText(string $v, int $max = 90): string
    {
        $v = strip_tags($v);
        $v = preg_replace('/[\x00-\x1F\x7F]+/u', ' ', $v) ?? '';
        $v = trim(preg_replace('/\s+/u', ' ', $v) ?? '');
        return mb_strlen($v) > $max ? rtrim(mb_substr($v, 0, $max - 1)) . '…' : $v;
    }

    /** @param array<string,string> $vars */
    private static function fill(string $template, array $vars, int $max): string
    {
        $text = preg_replace_callback('/\{([a-z_]+)\}/', static fn(array $m): string => $vars[$m[1]] ?? '', $template) ?? $template;
        $text = trim(preg_replace('/\s+/u', ' ', $text) ?? '');
        return mb_strlen($text) > $max ? rtrim(mb_substr($text, 0, $max - 1)) . '…' : $text;
    }

    /** @param array<string,string> $clean */
    private function buildLink(?string $template, array $clean, array $raw): ?string
    {
        if ($template === null) {
            return null;
        }
        $link = preg_replace_callback('/\{([a-z_]+)\}/', static function (array $m) use ($clean, $raw): string {
            if ($m[1] === 'id') {
                return (string)(int)($raw['id'] ?? 0);
            }
            return (string)($clean[$m[1]] ?? '');
        }, $template) ?? $template;
        if (str_contains($link, '?q=') && (strlen($link) > 240 || str_ends_with($link, '?q='))) {
            $link = strstr($link, '?q=', true) ?: $link;
        }
        return preg_match(self::LINK_PATTERN, $link) === 1 ? $link : null;
    }

    /** Termo para /admin/clinicas?q=, já codificado e curto (o link tem limite de 255 bytes). */
    private static function searchTerm(string $name): string
    {
        $term = rawurlencode(mb_substr(self::cleanText($name, 40), 0, 24));
        return preg_match('/^[A-Za-z0-9%._~-]{1,200}$/', $term) === 1 ? $term : '';
    }

    private static function dateBr(string $datetime): string
    {
        $ts = $datetime !== '' ? strtotime($datetime) : false;
        return $ts !== false ? date('d/m/Y', $ts) : 'data a confirmar';
    }

    private function ts(): int
    {
        return $this->clock ? (int)($this->clock)() : time();
    }

    private function now(): string
    {
        return date('Y-m-d H:i:s', $this->ts());
    }
}
