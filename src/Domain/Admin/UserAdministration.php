<?php

declare(strict_types=1);

namespace ClyoNutri\Domain\Admin;

use ClyoNutri\Core\Auth\PasswordResetService;
use ClyoNutri\Core\Auth\ProfessionalSecurityNotifier;
use ClyoNutri\Core\Http\Transport;
use ClyoNutri\Core\Mail\Mailer;
use ClyoNutri\Core\Mail\MailerFactory;
use ClyoNutri\Core\Mail\SecurityNoticeEmail;
use ClyoNutri\Core\Security\Env;
use ClyoNutri\Core\Security\PlatformPolicy;
use PDO;

/**
 * v33 — Gestão de usuários pelo console da plataforma (super admin).
 *
 * Ações sobre um usuário existente: suspender, reativar, bloquear, liberar, redefinir senha,
 * reenviar convite e encerrar sessões. Mais a criação de usuários da equipe (suporte etc.).
 *
 * Regras que valem para todas as ações:
 *  - permissão pelo PlatformPolicy (nada de checar papel "na mão");
 *  - ninguém age sobre a própria conta nem sobre papel de nível igual ou superior
 *    (exceção: superadmin sobre outro superadmin) e o último superadmin ativo nunca fica fora;
 *  - motivo obrigatório (5 a 300 caracteres) nas ações que cortam ou devolvem acesso;
 *  - sessões e tokens de API são derrubados na hora (auth_version + sessions + personal_access_tokens);
 *  - tudo vai para audit_logs (quem, alvo, ação, motivo, IP). Nunca senha, token ou link;
 *  - limite de ações por operador e de links por usuário, contados no próprio audit_logs;
 *  - o link de senha só existe em claro uma vez: vai por e-mail (se o servidor realmente entrega)
 *    ou é mostrado uma única vez na resposta. Só o hash (SHA-256) fica no banco.
 *
 * Estados de users.status: active, suspended (pausa administrativa), blocked (corte de segurança),
 * invited (equipe convidada, ainda sem senha). Não exige migração: a coluna é varchar(20).
 */
final class UserAdministration
{
    public const STATUS_ACTIVE = 'active';
    public const STATUS_SUSPENDED = 'suspended';
    public const STATUS_BLOCKED = 'blocked';
    public const STATUS_INVITED = 'invited';

    public const RESET_VALIDITY_SECONDS = 3600;       // 60 min
    public const INVITE_VALIDITY_SECONDS = 259200;    // 72 h
    public const REASON_MIN = 5;
    public const REASON_MAX = 300;
    public const ACTOR_LIMIT = 40;       // ações por operador
    public const ACTOR_WINDOW = 600;     // em 10 min
    public const LINK_LIMIT = 5;         // links por usuário
    public const LINK_WINDOW = 3600;     // em 1 h

    public const PLATFORM_CLINIC_NAME = 'Clyo Nutri — Operação da Plataforma';

    /** Papéis que a tela pode criar. Superadmin fica de fora de propósito (só pelo servidor, bin/create_superadmin.php). */
    public const STAFF_ROLES = ['admin_operacional', 'operator', 'support_agent', 'finance_admin', 'security_auditor'];

    /** ação => [permissão, rótulo, exige motivo] */
    private const ACTIONS = [
        'suspender'        => ['users.suspend', 'Suspender', true],
        'reativar'         => ['users.activate', 'Reativar', true],
        'bloquear'         => ['users.manage', 'Bloquear', true],
        'liberar'          => ['users.manage', 'Liberar acesso', true],
        'redefinir-senha'  => ['users.manage', 'Redefinir senha', true],
        'reenviar-convite' => ['users.manage', 'Reenviar convite', false],
        'revogar-sessoes'  => ['sessions.revoke', 'Encerrar sessões', true],
    ];

    /** Nível hierárquico: só se age sobre nível estritamente menor (superadmin sobre superadmin é tratado à parte). */
    private const RANK = [
        'superadmin' => 100,
        'admin' => 90,
        'admin_operacional' => 80,
        'operator' => 70,
        'support_agent' => 50,
        'finance_admin' => 50,
        'security_auditor' => 50,
    ];

    public const ROLE_LABELS = [
        'superadmin' => 'Super admin',
        'admin' => 'Administrador (legado)',
        'admin_operacional' => 'Administrador de operação',
        'operator' => 'Operador',
        'support_agent' => 'Suporte',
        'finance_admin' => 'Financeiro',
        'security_auditor' => 'Auditor de segurança',
        'nutritionist' => 'Nutricionista',
        'receptionist' => 'Recepcionista',
        'owner' => 'Proprietário',
    ];

    public const ROLE_DESCRIPTIONS = [
        'admin_operacional' => 'Opera a plataforma: clínicas, usuários (suspender e reativar), chamados e cobrança. Não bloqueia nem cria usuários.',
        'operator' => 'Atendimento operacional: fila de suporte, suspender e reativar usuários.',
        'support_agent' => 'Suporte: atende chamados e consulta clínicas e usuários. Só leitura em usuários.',
        'finance_admin' => 'Financeiro: consulta e gere cobrança e assinaturas. Sem acesso a usuários.',
        'security_auditor' => 'Auditoria: leitura de usuários, sessões e trilha de auditoria. Não altera nada.',
    ];

    public const STATUS_LABELS = [
        'active' => 'Ativo',
        'suspended' => 'Suspenso',
        'blocked' => 'Bloqueado',
        'invited' => 'Convite pendente',
    ];

    public const AUDIT_LABELS = [
        'ADMIN_USER_SUSPENDED' => 'Usuário suspenso',
        'ADMIN_USER_REACTIVATED' => 'Usuário reativado',
        'ADMIN_USER_BLOCKED' => 'Usuário bloqueado',
        'ADMIN_USER_UNBLOCKED' => 'Acesso liberado após bloqueio',
        'ADMIN_USER_PASSWORD_RESET_ISSUED' => 'Redefinição de senha solicitada',
        'ADMIN_STAFF_CREATED' => 'Usuário da equipe criado',
        'ADMIN_STAFF_INVITE_REISSUED' => 'Convite reenviado',
        'ADMIN_USER_LINK_DELIVERY' => 'Entrega do link',
        'ADMIN_USER_SESSIONS_REVOKED' => 'Sessões encerradas',
        'ADMIN_USER_STATUS_CHANGED' => 'Status alterado',
        'ADMIN_USER_ACTION_DENIED' => 'Ação negada',
        'AUTH_LOGIN_DENIED_INACTIVE_ACCOUNT' => 'Tentativa de login com acesso indisponível',
    ];

    private PasswordResetService $resetService;

    public function __construct(
        private PDO $db,
        private ?Mailer $mailer = null,
        private ?string $appUrl = null
    ) {
        $this->resetService = new PasswordResetService($db, $mailer, $appUrl);
    }

    // ------------------------------------------------------------------ catálogo

    public static function isAction(string $action): bool
    {
        return isset(self::ACTIONS[$action]);
    }

    public static function permissionFor(string $action): string
    {
        return self::ACTIONS[$action][0] ?? 'users.manage';
    }

    public static function actionLabel(string $action): string
    {
        return self::ACTIONS[$action][1] ?? $action;
    }

    public static function requiresReason(string $action): bool
    {
        return (bool)(self::ACTIONS[$action][2] ?? true);
    }

    public static function roleLabel(string $role): string
    {
        return self::ROLE_LABELS[$role] ?? $role;
    }

    public static function statusLabel(string $status): string
    {
        return self::STATUS_LABELS[$status] ?? $status;
    }

    public static function rank(string $role): int
    {
        return self::RANK[$role] ?? 10;
    }

    // ------------------------------------------------------------------ consultas

    /** @return array<string,mixed>|null */
    public function findUser(int $id, bool $forUpdate = false): ?array
    {
        $sql = 'SELECT u.id, u.uuid, u.name, u.email, u.role, u.status, u.auth_version, u.created_at, u.updated_at FROM users u WHERE u.id = :id';
        if ($forUpdate && $this->isMysql()) {
            $sql .= ' FOR UPDATE';
        }
        $st = $this->db->prepare($sql);
        $st->execute(['id' => $id]);
        $row = $st->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    /**
     * Ações que o ator pode fazer neste usuário, para a tela mostrar só botões válidos.
     *
     * @param array{id:int,role:string} $actor
     * @return list<array{action:string,label:string}>
     */
    public function availableActions(array $actor, array $target): array
    {
        $out = [];
        foreach (array_keys(self::ACTIONS) as $action) {
            if ($this->evaluate($actor, $target, $action)['ok']) {
                $out[] = ['action' => $action, 'label' => self::actionLabel($action)];
            }
        }
        return $out;
    }

    /**
     * Regras de negócio da ação (sem efeito colateral).
     *
     * @param array{id:int,role:string} $actor
     * @param array<string,mixed> $target
     * @return array{ok:bool,code:string,message:string}
     */
    public function evaluate(array $actor, array $target, string $action): array
    {
        $deny = static fn (string $code, string $message): array => ['ok' => false, 'code' => $code, 'message' => $message];

        if (!isset(self::ACTIONS[$action])) {
            return $deny('unknown_action', 'Ação desconhecida.');
        }
        if (!PlatformPolicy::can((string)$actor['role'], self::permissionFor($action))) {
            return $deny('permission', 'Seu perfil não tem permissão para esta ação.');
        }
        $actorId = (int)$actor['id'];
        $targetId = (int)$target['id'];
        $targetRole = (string)$target['role'];
        $status = (string)$target['status'];

        if ($actorId === $targetId) {
            return $deny('self', 'Você não pode fazer isso na sua própria conta.');
        }
        $actorRole = (string)$actor['role'];
        $superOnSuper = $actorRole === 'superadmin' && $targetRole === 'superadmin';
        if (!$superOnSuper && self::rank($actorRole) <= self::rank($targetRole)) {
            return $deny('hierarchy', 'Você não pode gerir um usuário de nível igual ou superior ao seu.');
        }

        $needs = [
            'suspender' => [self::STATUS_ACTIVE],
            'reativar' => [self::STATUS_SUSPENDED],
            'bloquear' => [self::STATUS_ACTIVE, self::STATUS_SUSPENDED, self::STATUS_INVITED],
            'liberar' => [self::STATUS_BLOCKED],
            'redefinir-senha' => [self::STATUS_ACTIVE],
            'reenviar-convite' => [self::STATUS_INVITED],
            'revogar-sessoes' => [self::STATUS_ACTIVE, self::STATUS_SUSPENDED, self::STATUS_BLOCKED, self::STATUS_INVITED],
        ];
        if (!in_array($status, $needs[$action], true)) {
            $why = match ($action) {
                'suspender' => 'Só dá para suspender um usuário ativo.',
                'reativar' => $status === self::STATUS_BLOCKED
                    ? 'Usuário bloqueado não é reativado: use "Liberar acesso" (só o super admin) e ele cria uma nova senha.'
                    : 'Só dá para reativar um usuário suspenso.',
                'bloquear' => 'Este usuário já está bloqueado.',
                'liberar' => 'Só dá para liberar um usuário bloqueado.',
                'redefinir-senha' => $status === self::STATUS_INVITED
                    ? 'Este usuário ainda não definiu a primeira senha: use "Reenviar convite".'
                    : 'Reative ou libere o usuário antes de redefinir a senha.',
                'reenviar-convite' => 'Só usuários com convite pendente recebem o convite de novo.',
                default => 'A ação não se aplica ao estado atual do usuário.',
            };
            return $deny('state', $why);
        }

        if (in_array($action, ['suspender', 'bloquear'], true) && $targetRole === 'superadmin' && $status === self::STATUS_ACTIVE) {
            $others = $this->db->prepare("SELECT COUNT(*) FROM users WHERE role = 'superadmin' AND status = 'active' AND id <> :id");
            $others->execute(['id' => $targetId]);
            if ((int)$others->fetchColumn() < 1) {
                return $deny('last_superadmin', 'Este é o último super admin ativo: ele não pode ser suspenso nem bloqueado.');
            }
        }
        return ['ok' => true, 'code' => 'ok', 'message' => ''];
    }

    /** Motivo limpo, ou null se não atende 5 a 300 caracteres. */
    public static function cleanReason(?string $reason): ?string
    {
        $reason = trim(preg_replace('/[\x00-\x1F\x7F]+/u', ' ', (string)$reason) ?? '');
        $len = mb_strlen($reason);
        return ($len >= self::REASON_MIN && $len <= self::REASON_MAX) ? $reason : null;
    }

    // ------------------------------------------------------------------ execução

    /**
     * Executa uma ação. Nunca lança por regra de negócio: devolve ok=false e a mensagem.
     *
     * @param array{id:int,role:string,name?:string,email?:string,clinic_id?:int|null} $actor
     * @return array<string,mixed> ok, message, delivery (email|manual|null), link, expires_at, target
     */
    public function execute(array $actor, int $targetId, string $action, ?string $reason, ?string $ip, ?string $ua): array
    {
        if (!self::isAction($action)) {
            return $this->fail('Ação desconhecida.');
        }
        $reasonClean = null;
        if (self::requiresReason($action)) {
            $reasonClean = self::cleanReason($reason);
            if ($reasonClean === null) {
                return $this->fail('Informe o motivo, com ' . self::REASON_MIN . ' a ' . self::REASON_MAX . ' caracteres.');
            }
        }
        if ($this->actorRateLimited((int)$actor['id'])) {
            return $this->fail('Muitas ações administrativas em pouco tempo. Aguarde alguns minutos e tente de novo.', 'rate_limit');
        }

        $issuesLink = in_array($action, ['liberar', 'redefinir-senha', 'reenviar-convite'], true);
        if ($issuesLink && $this->linkRateLimited($targetId)) {
            $this->audit($actor, 'ADMIN_USER_ACTION_DENIED', (string)$targetId, ['action' => $action, 'code' => 'link_rate_limit'], $ip, $ua);
            return $this->fail('Já foram emitidos muitos links para este usuário na última hora. Aguarde antes de pedir outro.', 'rate_limit');
        }

        $own = !$this->db->inTransaction();
        if ($own) {
            $this->db->beginTransaction();
        }
        $issued = null;
        try {
            $target = $this->findUser($targetId, true);
            if ($target === null) {
                throw new BusinessRule('Usuário não encontrado.', 'not_found');
            }
            $check = $this->evaluate($actor, $target, $action);
            if (!$check['ok']) {
                throw new BusinessRule($check['message'], $check['code']);
            }
            $oldStatus = (string)$target['status'];
            $details = [
                'operator_id' => (int)$actor['id'],
                'target_user_id' => $targetId,
                'target_role' => (string)$target['role'],
                'previous_status' => $oldStatus,
            ];
            if ($reasonClean !== null) {
                $details['reason'] = $reasonClean;
            }

            switch ($action) {
                case 'suspender':
                    $this->setStatus($targetId, self::STATUS_SUSPENDED);
                    $this->cutAccess($targetId, (string)$target['email']);
                    $details['new_status'] = self::STATUS_SUSPENDED;
                    $auditAction = 'ADMIN_USER_SUSPENDED';
                    break;
                case 'reativar':
                    $this->setStatus($targetId, self::STATUS_ACTIVE);
                    $details['new_status'] = self::STATUS_ACTIVE;
                    $auditAction = 'ADMIN_USER_REACTIVATED';
                    break;
                case 'bloquear':
                    $this->setStatus($targetId, self::STATUS_BLOCKED);
                    $this->cutAccess($targetId, (string)$target['email']);
                    $details['new_status'] = self::STATUS_BLOCKED;
                    $auditAction = 'ADMIN_USER_BLOCKED';
                    break;
                case 'liberar':
                    // Volta ativo, mas a senha antiga morre: só entra de novo criando uma nova senha pelo link.
                    $this->setStatus($targetId, self::STATUS_ACTIVE);
                    $this->cutAccess($targetId, (string)$target['email'], true);
                    $issued = $this->resetService->issueAdminToken((string)$target['email'], self::RESET_VALIDITY_SECONDS);
                    $details['new_status'] = self::STATUS_ACTIVE;
                    $details['password_invalidated'] = true;
                    $details['token_expires_at_utc'] = $issued['expires_at'];
                    $auditAction = 'ADMIN_USER_UNBLOCKED';
                    break;
                case 'redefinir-senha':
                    $this->cutAccess($targetId, (string)$target['email'], true);
                    $issued = $this->resetService->issueAdminToken((string)$target['email'], self::RESET_VALIDITY_SECONDS);
                    $details['password_invalidated'] = true;
                    $details['token_expires_at_utc'] = $issued['expires_at'];
                    $auditAction = 'ADMIN_USER_PASSWORD_RESET_ISSUED';
                    break;
                case 'reenviar-convite':
                    $issued = $this->resetService->issueAdminToken((string)$target['email'], self::INVITE_VALIDITY_SECONDS);
                    $details['token_expires_at_utc'] = $issued['expires_at'];
                    $auditAction = 'ADMIN_STAFF_INVITE_REISSUED';
                    break;
                case 'revogar-sessoes':
                    $this->cutAccess($targetId, (string)$target['email'], false, false);
                    $auditAction = 'ADMIN_USER_SESSIONS_REVOKED';
                    break;
                default:
                    throw new BusinessRule('Ação desconhecida.', 'unknown_action');
            }
            $this->audit($actor, $auditAction, (string)$targetId, $details, $ip, $ua);
            if ($own) {
                $this->db->commit();
            }
        } catch (BusinessRule $e) {
            if ($own && $this->db->inTransaction()) {
                $this->db->rollBack();
            }
            $this->audit($actor, 'ADMIN_USER_ACTION_DENIED', (string)$targetId, ['action' => $action, 'code' => $e->codeName], $ip, $ua);
            return $this->fail($e->getMessage(), $e->codeName);
        } catch (\Throwable $e) {
            if ($own && $this->db->inTransaction()) {
                $this->db->rollBack();
            }
            error_log('[ClyoNutri][admin-users] falha em ' . $action . ' (alvo ' . $targetId . '): ' . get_class($e));
            return $this->fail('Não foi possível concluir a ação. Nada foi alterado.');
        }

        $result = [
            'ok' => true,
            'code' => 'ok',
            'action' => $action,
            'target' => $target,
            'delivery' => null,
            'link' => null,
            'expires_at' => null,
            'message' => $this->successMessage($action, $target),
        ];
        // v34: avisa os OUTROS superadmins (quem agiu não recebe o próprio aviso). Nunca lança.
        if (!$this->db->inTransaction()) {
            (new \ClyoNutri\Domain\Notification\StaffNotificationService($this->db))
                ->notifyUserAction($action, $actor, $target, $reasonClean);
        }
        $notifier = $this->notifier();
        try {
            match ($action) {
                'suspender' => $notifier->accountSuspended($targetId, $ip, $ua),
                'reativar' => $notifier->accountReactivated($targetId, $ip, $ua),
                'bloquear' => $notifier->accountBlocked($targetId, $ip, $ua),
                'revogar-sessoes' => $notifier->sessionsRevoked($targetId, $ip, $ua),
                default => null,
            };
        } catch (\Throwable) {
            // aviso ao usuário nunca desfaz a ação
        }
        if ($issued !== null) {
            $result = array_merge($result, $this->deliver($actor, $target, $action, $issued, $ip, $ua));
        }
        return $result;
    }

    /**
     * Cria um usuário da equipe (convite). Sem senha digitada por ninguém.
     *
     * @param array{id:int,role:string,name?:string,email?:string,clinic_id?:int|null} $actor
     * @return array<string,mixed>
     */
    public function createStaff(array $actor, string $name, string $email, string $role, ?string $ip, ?string $ua): array
    {
        if (!PlatformPolicy::can((string)$actor['role'], 'users.manage')) {
            return $this->fail('Seu perfil não tem permissão para criar usuários.', 'permission');
        }
        $name = trim(preg_replace('/\s+/u', ' ', strip_tags($name)) ?? '');
        $email = strtolower(trim($email));
        if (mb_strlen($name) < 2 || mb_strlen($name) > 120) {
            return $this->fail('Informe o nome completo (2 a 120 caracteres).', 'validation');
        }
        if (strlen($email) > 190 || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return $this->fail('Informe um e-mail válido.', 'validation');
        }
        if (!in_array($role, self::STAFF_ROLES, true)) {
            return $this->fail('Escolha um dos perfis da lista.', 'validation');
        }
        if ($this->actorRateLimited((int)$actor['id'])) {
            return $this->fail('Muitas ações administrativas em pouco tempo. Aguarde alguns minutos e tente de novo.', 'rate_limit');
        }
        $exists = $this->db->prepare('SELECT COUNT(*) FROM users WHERE LOWER(email) = :email');
        $exists->execute(['email' => $email]);
        if ((int)$exists->fetchColumn() > 0) {
            return $this->fail('Já existe um usuário com este e-mail.', 'duplicate');
        }

        $own = !$this->db->inTransaction();
        if ($own) {
            $this->db->beginTransaction();
        }
        try {
            $unusable = $this->unusableHash();
            $this->db->prepare("INSERT INTO users (uuid, name, email, password_hash, role, is_verified, status) VALUES (:uuid, :name, :email, :hash, :role, 1, 'invited')")
                ->execute(['uuid' => self::uuidV4(), 'name' => $name, 'email' => $email, 'hash' => $unusable, 'role' => $role]);
            $userId = (int)$this->db->lastInsertId();
            $clinicId = $this->platformClinicId($actor, $email);
            $this->db->prepare("INSERT INTO clinic_members (clinic_id, user_id, role, status) VALUES (:cid, :uid, 'admin', 'active')")
                ->execute(['cid' => $clinicId, 'uid' => $userId]);
            $issued = $this->resetService->issueAdminToken($email, self::INVITE_VALIDITY_SECONDS);
            $this->audit($actor, 'ADMIN_STAFF_CREATED', (string)$userId, [
                'operator_id' => (int)$actor['id'],
                'target_user_id' => $userId,
                'target_role' => $role,
                'new_status' => self::STATUS_INVITED,
                'token_expires_at_utc' => $issued['expires_at'],
            ], $ip, $ua);
            if ($own) {
                $this->db->commit();
            }
        } catch (\Throwable $e) {
            if ($own && $this->db->inTransaction()) {
                $this->db->rollBack();
            }
            if ($e instanceof \PDOException && (string)$e->getCode() === '23000') {
                return $this->fail('Já existe um usuário com este e-mail.', 'duplicate');
            }
            error_log('[ClyoNutri][admin-users] falha ao criar usuário da equipe: ' . get_class($e));
            return $this->fail('Não foi possível criar o usuário. Nada foi gravado.');
        }

        $target = ['id' => $userId, 'name' => $name, 'email' => $email, 'role' => $role, 'status' => self::STATUS_INVITED];
        if (!$this->db->inTransaction()) {
            (new \ClyoNutri\Domain\Notification\StaffNotificationService($this->db))->notifyStaffInvited($actor, $target); // v34, nunca lança
        }
        $result = [
            'ok' => true, 'code' => 'ok', 'action' => 'criar', 'target' => $target, 'delivery' => null, 'link' => null,
            'expires_at' => null, 'message' => 'Usuário da equipe criado. Ele define a própria senha pelo convite.',
        ];
        return array_merge($result, $this->deliver($actor, $target, 'criar', $issued, $ip, $ua));
    }

    // ------------------------------------------------------------------ histórico

    /**
     * Linhas do histórico administrativo do usuário (mais recentes primeiro). Sem token, link ou senha.
     *
     * @return list<array<string,mixed>>
     */
    public function history(int $userId, int $limit = 30): array
    {
        $actions = array_keys(self::AUDIT_LABELS);
        $in = implode(',', array_fill(0, count($actions), '?'));
        $st = $this->db->prepare(
            "SELECT a.id, a.action, a.user_id, a.ip_address, a.details, a.created_at, op.name AS operator_name
             FROM audit_logs a LEFT JOIN users op ON op.id = a.user_id
             WHERE a.resource IN ('user', 'users') AND a.resource_id = ? AND a.action IN ($in)
             ORDER BY a.id DESC LIMIT " . max(1, min(100, $limit))
        );
        $st->execute(array_merge([(string)$userId], $actions));
        $rows = [];
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $d = json_decode((string)($r['details'] ?? ''), true);
            $d = is_array($d) ? $d : [];
            unset($d['token'], $d['link'], $d['password']);
            $r['detail'] = $d;
            $r['label'] = self::AUDIT_LABELS[(string)$r['action']] ?? (string)$r['action'];
            $rows[] = $r;
        }
        return $rows;
    }

    // ------------------------------------------------------------------ internos

    private function setStatus(int $id, string $status): void
    {
        $this->db->prepare('UPDATE users SET status = :s, updated_at = :u WHERE id = :id')
            ->execute(['s' => $status, 'u' => date('Y-m-d H:i:s'), 'id' => $id]);
    }

    /** Derruba sessões web e tokens de API na hora; opcionalmente invalida a senha atual. */
    private function cutAccess(int $id, string $email, bool $invalidatePassword = false, bool $dropResetTokens = true): void
    {
        $sql = 'UPDATE users SET auth_version = auth_version + 1, updated_at = :u' . ($invalidatePassword ? ', password_hash = :h' : '') . ' WHERE id = :id';
        $params = ['u' => date('Y-m-d H:i:s'), 'id' => $id];
        if ($invalidatePassword) {
            $params['h'] = $this->unusableHash();
        }
        $this->db->prepare($sql)->execute($params);
        $this->db->prepare('DELETE FROM sessions WHERE user_id = :id')->execute(['id' => $id]);
        $this->db->prepare("DELETE FROM personal_access_tokens WHERE tokenable_type IN ('user','professional') AND tokenable_id = :id")->execute(['id' => $id]);
        if ($dropResetTokens) {
            $this->db->prepare('DELETE FROM password_resets WHERE LOWER(email) = :e')->execute(['e' => strtolower($email)]);
        }
    }

    private function unusableHash(): string
    {
        return password_hash(bin2hex(random_bytes(32)), PASSWORD_BCRYPT, ['cost' => 12]);
    }

    private function isMysql(): bool
    {
        return $this->db->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql';
    }

    private static function uuidV4(): string
    {
        $b = random_bytes(16);
        $b[6] = chr((ord($b[6]) & 0x0f) | 0x40);
        $b[8] = chr((ord($b[8]) & 0x3f) | 0x80);
        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($b), 4));
    }

    private function platformClinicId(array $actor, string $fallbackEmail): int
    {
        $find = $this->db->prepare('SELECT id FROM clinics WHERE name = :n ORDER BY id LIMIT 1');
        $find->execute(['n' => self::PLATFORM_CLINIC_NAME]);
        $id = $find->fetchColumn();
        if ($id) {
            return (int)$id;
        }
        $actorClinic = (int)($actor['clinic_id'] ?? 0);
        if ($actorClinic > 0) {
            $ok = $this->db->prepare("SELECT id FROM clinics WHERE id = :id AND status = 'active'");
            $ok->execute(['id' => $actorClinic]);
            if ($ok->fetchColumn()) {
                return $actorClinic;
            }
        }
        $this->db->prepare("INSERT INTO clinics (uuid, name, email, status) VALUES (:uuid, :name, :email, 'active')")
            ->execute(['uuid' => self::uuidV4(), 'name' => self::PLATFORM_CLINIC_NAME, 'email' => (string)($actor['email'] ?? $fallbackEmail)]);
        return (int)$this->db->lastInsertId();
    }

    /** Escreve no audit_logs com created_at no horário do servidor (mesma base da contagem de limites). */
    private function audit(array $actor, string $action, string $resourceId, array $details, ?string $ip, ?string $ua): void
    {
        foreach (['token', 'link', 'password', 'url'] as $forbidden) {
            unset($details[$forbidden]);
        }
        try {
            $this->db->prepare(
                'INSERT INTO audit_logs (clinic_id, user_id, action, resource, resource_id, ip_address, user_agent, details, created_at)
                 VALUES (:cid, :uid, :action, :resource, :rid, :ip, :ua, :details, :created)'
            )->execute([
                'cid' => !empty($actor['clinic_id']) ? (int)$actor['clinic_id'] : null,
                'uid' => (int)$actor['id'],
                'action' => $action,
                'resource' => 'user',
                'rid' => $resourceId,
                'ip' => $ip !== null ? substr($ip, 0, 45) : null,
                'ua' => $ua !== null ? substr($ua, 0, 255) : null,
                'details' => json_encode($details, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                'created' => date('Y-m-d H:i:s'),
            ]);
        } catch (\Throwable $e) {
            if ($action !== 'ADMIN_USER_ACTION_DENIED') {
                throw $e; // ação que muda acesso sem trilha não pode passar
            }
        }
    }

    private function actorRateLimited(int $actorId): bool
    {
        $since = date('Y-m-d H:i:s', time() - self::ACTOR_WINDOW);
        $st = $this->db->prepare("SELECT COUNT(*) FROM audit_logs WHERE user_id = :u AND resource = 'user' AND action LIKE 'ADMIN_%' AND created_at >= :s");
        $st->execute(['u' => $actorId, 's' => $since]);
        return (int)$st->fetchColumn() >= self::ACTOR_LIMIT;
    }

    private function linkRateLimited(int $targetId): bool
    {
        $since = date('Y-m-d H:i:s', time() - self::LINK_WINDOW);
        $st = $this->db->prepare("SELECT COUNT(*) FROM audit_logs WHERE resource = 'user' AND resource_id = :r AND action IN ('ADMIN_USER_UNBLOCKED','ADMIN_USER_PASSWORD_RESET_ISSUED','ADMIN_STAFF_INVITE_REISSUED') AND created_at >= :s");
        $st->execute(['r' => (string)$targetId, 's' => $since]);
        return (int)$st->fetchColumn() >= self::LINK_LIMIT;
    }

    /** @return array{ok:false,code:string,message:string} */
    private function fail(string $message, string $code = 'error'): array
    {
        return ['ok' => false, 'code' => $code, 'message' => $message, 'delivery' => null, 'link' => null, 'expires_at' => null];
    }

    private function successMessage(string $action, array $target): string
    {
        $who = (string)$target['name'];
        return match ($action) {
            'suspender' => $who . ' foi suspenso(a). As sessões foram encerradas e o acesso volta com "Reativar".',
            'reativar' => $who . ' foi reativado(a) e já pode entrar com a senha de sempre.',
            'bloquear' => $who . ' foi bloqueado(a) por segurança. As sessões foram encerradas e só o super admin libera.',
            'liberar' => 'Acesso de ' . $who . ' liberado. A senha antiga foi invalidada: é preciso criar uma nova senha pelo link.',
            'redefinir-senha' => 'Redefinição de senha pedida para ' . $who . '. A senha antiga foi invalidada e as sessões encerradas.',
            'reenviar-convite' => 'Novo convite emitido para ' . $who . '. O convite anterior deixou de valer.',
            'revogar-sessoes' => 'Todas as sessões de ' . $who . ' foram encerradas agora.',
            default => 'Ação concluída.',
        };
    }

    private function notifier(): ProfessionalSecurityNotifier
    {
        return new ProfessionalSecurityNotifier($this->db, $this->mailer);
    }

    /** Link absoluto de APP_URL (https em produção), ou caminho relativo se APP_URL não estiver configurada. */
    private function linkFor(string $token): string
    {
        $path = '/recuperar-senha/' . rawurlencode($token);
        $base = rtrim(trim((string)($this->appUrl ?? Env::get('APP_URL') ?? '')), '/');
        if (!preg_match('#^https?://[^/\s]+#i', $base)) {
            return $path;
        }
        if (Transport::isProduction() || Transport::forceHttpsEnabled()) {
            $base = (string)preg_replace('#^http://#i', 'https://', $base);
        }
        return $base . $path;
    }

    private function mailDelivers(): bool
    {
        if ($this->mailer !== null) {
            return true;
        }
        try {
            $d = MailerFactory::describe();
            return (bool)$d['delivers'] && empty($d['problems']);
        } catch (\Throwable) {
            return false;
        }
    }

    /**
     * Entrega o link: e-mail quando o servidor realmente envia; senão (ou se falhar) mostra uma vez.
     * Nenhum mailer de log/null é usado para links, porque o driver de log gravaria o link em disco.
     *
     * @param array{token:string,expires_at:string} $issued
     * @return array{delivery:string,link:?string,expires_at:string,delivery_note:string}
     */
    private function deliver(array $actor, array $target, string $action, array $issued, ?string $ip, ?string $ua): array
    {
        $url = $this->linkFor($issued['token']);
        $expiresLocal = self::localExpiry($issued['expires_at']);
        $invite = in_array($action, ['criar', 'reenviar-convite'], true);
        $delivery = 'manual';
        $note = 'Servidor de e-mail não está entregando mensagens: copie o link agora e envie por um canal seguro.';

        if ($this->mailDelivers()) {
            try {
                $role = self::roleLabel((string)$target['role']);
                $lines = $invite
                    ? [
                        'Você foi convidado(a) para a equipe da Clyo Nutri com o perfil ' . $role . '.',
                        'Crie a sua senha pelo botão abaixo. O link vale até ' . $expiresLocal . ' (horário de Brasília) e só funciona uma vez.',
                        'Se você não esperava este convite, ignore este e-mail.',
                    ]
                    : [
                        'Um administrador da Clyo Nutri pediu a criação de uma nova senha para a sua conta. A senha anterior deixou de valer.',
                        'Crie a nova senha pelo botão abaixo. O link vale até ' . $expiresLocal . ' (horário de Brasília) e só funciona uma vez.',
                        'Se você não esperava isto, avise o suporte da Clyo Nutri.',
                    ];
                $message = SecurityNoticeEmail::build(
                    (string)$target['email'],
                    (string)$target['name'],
                    $invite ? 'Seu convite para a equipe Clyo Nutri' : 'Crie uma nova senha na Clyo Nutri',
                    $invite ? 'Seu convite para a equipe Clyo Nutri' : 'Crie uma nova senha',
                    $lines,
                    $invite ? 'Criar minha senha' : 'Criar nova senha',
                    $url,
                    self::supportReplyTo()
                );
                ($this->mailer ?? MailerFactory::fromEnv())->send($message);
                $delivery = 'email';
                $note = 'O link foi enviado por e-mail para ' . (string)$target['email'] . '.';
            } catch (\Throwable) {
                $note = 'O envio por e-mail falhou: copie o link agora e envie por um canal seguro.';
            }
        }

        try {
            $this->audit($actor, 'ADMIN_USER_LINK_DELIVERY', (string)$target['id'], [
                'operator_id' => (int)$actor['id'],
                'target_user_id' => (int)$target['id'],
                'for_action' => $action,
                'mode' => $delivery === 'email' ? 'email_sent' : 'shown_once',
                'token_expires_at_utc' => $issued['expires_at'],
            ], $ip, $ua);
        } catch (\Throwable) {
            // a trilha da ação já foi gravada; a nota de entrega é complementar
        }

        return [
            'delivery' => $delivery,
            'link' => $delivery === 'manual' ? $url : null,
            'expires_at' => $expiresLocal,
            'delivery_note' => $note,
        ];
    }

    /** "28/10/2026 22:15" no fuso da aplicação, a partir do DATETIME em UTC. */
    public static function localExpiry(string $utc): string
    {
        $ts = strtotime($utc . ' UTC');
        return $ts === false ? $utc : date('d/m/Y H:i', $ts);
    }

    private static function supportReplyTo(): ?string
    {
        $support = trim((string)(Env::get('APP_SUPPORT_EMAIL') ?? ''));
        return filter_var($support, FILTER_VALIDATE_EMAIL) ? $support : null;
    }
}
