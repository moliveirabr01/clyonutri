<?php

declare(strict_types=1);

namespace ClyoNutri\Domain\Team;

use ClyoNutri\Core\Audit\AuditLogger;
use ClyoNutri\Core\Auth\AuthService;
use ClyoNutri\Core\Auth\PasswordResetService;
use ClyoNutri\Core\Http\Transport;
use ClyoNutri\Core\Mail\Mailer;
use ClyoNutri\Core\Mail\MailerFactory;
use ClyoNutri\Core\Mail\SecurityNoticeEmail;
use ClyoNutri\Core\Security\Env;
use ClyoNutri\Domain\Subscription\SubscriptionService;
use DomainException;
use InvalidArgumentException;
use PDO;

/**
 * NI2-606 (UX-R3-05): equipe da clínica — listar, convidar, mudar papel, desativar e reativar.
 *
 * Regras:
 *  - Só titular (owner) e gestor (admin) ativos da clínica gerenciam. Ninguém altera o titular nem a si mesmo.
 *  - Papéis oferecidos: Nutricionista (nutritionist), Nutricionista gestor(a) (admin) e Recepção (receptionist).
 *    Nutricionistas entram com CRN (a prescrição exige). Recepção não tem CRN e não troca de papel com nutricionista
 *    (desative e convide de novo), porque o papel da conta muda as permissões clínicas.
 *  - Limite do plano: membros ativos da clínica (a mesma conta de "Profissionais N de M" da assinatura). O convidado
 *    passa a contar no envio do convite. max_professionals = 0 é "sem limite".
 *  - Convite: usuário 'invited' com senha inutilizável + link de criação de senha (72 h, uso único), o mesmo fluxo da
 *    equipe da plataforma. E-mail quando o servidor entrega; senão o link aparece uma vez para copiar.
 *  - Desativar: vínculo 'revoked' e sessões encerradas (AuthService::revokeClinicMembership). Nada é apagado: o
 *    histórico (prontuários, planos, auditoria) continua com o autor.
 *  - Toda ação vai para a auditoria (TEAM_*), sem senha nem token.
 */
final class TeamService
{
    public const ROLES = [
        'nutritionist' => 'Nutricionista',
        'admin' => 'Nutricionista gestor(a)',
        'receptionist' => 'Recepção',
    ];
    public const INVITE_VALIDITY_SECONDS = 259200; // 72 h
    private const CRN_REGIONS = ['CRN-1', 'CRN-2', 'CRN-3', 'CRN-4', 'CRN-5', 'CRN-6', 'CRN-7', 'CRN-8', 'CRN-9', 'CRN-10', 'CRN-11'];

    public function __construct(private PDO $db, private ?Mailer $mailer = null, private ?string $appUrl = null)
    {
    }

    public static function roleLabel(string $memberRole): string
    {
        return $memberRole === 'owner' ? 'Titular' : (self::ROLES[$memberRole] ?? $memberRole);
    }

    /** Titular ou gestor ativo da clínica. */
    public function canManage(int $clinicId, int $userId): bool
    {
        $st = $this->db->prepare("SELECT role FROM clinic_members WHERE clinic_id = ? AND user_id = ? AND status = 'active'");
        $st->execute([$clinicId, $userId]);
        return in_array((string)$st->fetchColumn(), ['owner', 'admin'], true);
    }

    /** @return list<array<string,mixed>> */
    public function members(int $clinicId): array
    {
        $st = $this->db->prepare("SELECT u.id AS user_id, u.name, u.email, u.status AS user_status, cm.role AS member_role, cm.status AS member_status,
                cm.created_at AS member_since, p.crn, p.crn_region
            FROM clinic_members cm JOIN users u ON u.id = cm.user_id LEFT JOIN professionals p ON p.user_id = u.id
            WHERE cm.clinic_id = ?
            ORDER BY CASE WHEN cm.status = 'active' THEN 0 ELSE 1 END, CASE cm.role WHEN 'owner' THEN 0 WHEN 'admin' THEN 1 WHEN 'nutritionist' THEN 2 ELSE 3 END, u.name");
        $st->execute([$clinicId]);
        $rows = $st->fetchAll(PDO::FETCH_ASSOC);
        foreach ($rows as &$r) {
            $r['state'] = $r['member_status'] !== 'active' ? 'inactive' : ($r['user_status'] === 'invited' ? 'invited' : 'active');
            $r['state_label'] = ['inactive' => 'Desativado', 'invited' => 'Convite pendente', 'active' => 'Ativo'][$r['state']];
            $r['role_label'] = self::roleLabel((string)$r['member_role']);
        }
        unset($r);
        return $rows;
    }

    /** @return array{used:int, max:int, unlimited:bool, available:bool, plan_name:string} */
    public function limit(int $clinicId): array
    {
        $sub = new SubscriptionService($this->db);
        $max = 0;
        $plan = '';
        try {
            $s = $sub->getClinicSubscription($clinicId);
            $max = (int)($s['max_professionals'] ?? 0);
            $plan = (string)($s['plan_name'] ?? '');
        } catch (\Throwable $e) {
            error_log('NI2-606 plano indisponível: ' . $e->getMessage());
        }
        $st = $this->db->prepare("SELECT COUNT(*) FROM clinic_members WHERE clinic_id = ? AND status = 'active'");
        $st->execute([$clinicId]);
        $used = (int)$st->fetchColumn();
        return ['used' => $used, 'max' => $max, 'unlimited' => $max <= 0, 'available' => $max <= 0 || $used < $max, 'plan_name' => $plan];
    }

    /**
     * @return array{user_id:int, delivery:string, link:?string, expires_at:string, note:string}
     * @throws InvalidArgumentException|DomainException
     */
    public function invite(int $clinicId, int $actorId, array $input, ?string $ip = null, ?string $ua = null): array
    {
        $this->assertManager($clinicId, $actorId);
        $limit = $this->limit($clinicId);
        if (!$limit['available']) {
            throw new DomainException("Seu plano permite {$limit['max']} profissionais e a equipe já tem {$limit['used']} ativos. Desative alguém ou troque de plano para convidar.");
        }
        $name = trim((string)preg_replace('/\s+/u', ' ', strip_tags((string)($input['name'] ?? ''))));
        $email = strtolower(trim((string)($input['email'] ?? '')));
        $role = (string)($input['role'] ?? '');
        if (mb_strlen($name) < 2 || mb_strlen($name) > 120) {
            throw new InvalidArgumentException('Informe o nome completo (2 a 120 caracteres).');
        }
        if (strlen($email) > 190 || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            throw new InvalidArgumentException('Informe um e-mail válido.');
        }
        if (!isset(self::ROLES[$role])) {
            throw new InvalidArgumentException('Escolha o papel: Nutricionista, Nutricionista gestor(a) ou Recepção.');
        }
        $needsCrn = $role !== 'receptionist';
        $crn = strtoupper(trim((string)($input['crn'] ?? '')));
        $region = trim((string)($input['crn_region'] ?? ''));
        if ($needsCrn && (!preg_match('/^[0-9A-Z\/.\-]{3,20}$/', $crn) || !in_array($region, self::CRN_REGIONS, true))) {
            throw new InvalidArgumentException('Para nutricionista, informe o número do CRN e a região.');
        }
        $exists = $this->db->prepare('SELECT COUNT(*) FROM users WHERE LOWER(email) = ?');
        $exists->execute([$email]);
        if ((int)$exists->fetchColumn() > 0) {
            throw new DomainException('Já existe uma conta com este e-mail. Use outro e-mail ou peça ajuda ao suporte para vincular a conta existente.');
        }

        $own = !$this->db->inTransaction();
        if ($own) {
            $this->db->beginTransaction();
        }
        try {
            $this->db->prepare("INSERT INTO users (uuid, name, email, password_hash, role, is_verified, status) VALUES (?, ?, ?, ?, ?, 1, 'invited')")
                ->execute([AuthService::generateUuid(), $name, $email, password_hash(bin2hex(random_bytes(32)), PASSWORD_BCRYPT), $role === 'receptionist' ? 'receptionist' : 'nutritionist']);
            $userId = (int)$this->db->lastInsertId();
            $this->db->prepare("INSERT INTO clinic_members (clinic_id, user_id, role, status) VALUES (?, ?, ?, 'active')")->execute([$clinicId, $userId, $role]);
            if ($needsCrn) {
                $this->db->prepare('INSERT INTO professionals (user_id, crn, crn_region) VALUES (?, ?, ?)')->execute([$userId, $crn, $region]);
            }
            $issued = (new PasswordResetService($this->db))->issueAdminToken($email, self::INVITE_VALIDITY_SECONDS);
            $this->audit('TEAM_MEMBER_INVITED', $clinicId, $actorId, $userId, ['role' => $role, 'expires_at_utc' => $issued['expires_at']]);
            if ($own) {
                $this->db->commit();
            }
        } catch (\Throwable $e) {
            if ($own && $this->db->inTransaction()) {
                $this->db->rollBack();
            }
            if ($e instanceof \PDOException && (string)$e->getCode() === '23000') {
                throw new DomainException('Já existe uma conta com este e-mail.');
            }
            throw $e;
        }
        return ['user_id' => $userId] + $this->deliver($clinicId, $actorId, $userId, $name, $email, $role, $issued);
    }

    /** Novo link para quem ainda não criou a senha. */
    public function resendInvite(int $clinicId, int $actorId, int $userId): array
    {
        $this->assertManager($clinicId, $actorId);
        $m = $this->member($clinicId, $userId);
        if ($m['member_status'] !== 'active' || $m['user_status'] !== 'invited') {
            throw new DomainException('Só dá para reenviar convite de quem ainda não criou a senha.');
        }
        $issued = (new PasswordResetService($this->db))->issueAdminToken((string)$m['email'], self::INVITE_VALIDITY_SECONDS);
        $this->audit('TEAM_INVITE_RESENT', $clinicId, $actorId, $userId, ['expires_at_utc' => $issued['expires_at']]);
        return $this->deliver($clinicId, $actorId, $userId, (string)$m['name'], (string)$m['email'], (string)$m['member_role'], $issued);
    }

    public function changeRole(int $clinicId, int $actorId, int $userId, string $role): void
    {
        $this->assertManager($clinicId, $actorId);
        $m = $this->assertTarget($clinicId, $actorId, $userId);
        if (!in_array($role, ['nutritionist', 'admin'], true) || !in_array($m['member_role'], ['nutritionist', 'admin'], true)) {
            throw new DomainException('A troca de papel vale entre Nutricionista e Nutricionista gestor(a). Para Recepção, desative a conta e convide de novo com o papel certo.');
        }
        if ($m['member_role'] === $role) {
            return;
        }
        (new AuthService($this->db))->updateMembershipRole($clinicId, $userId, $role, $actorId); // incrementa auth_version: sessões antigas recarregam o papel
        $this->audit('TEAM_MEMBER_ROLE_CHANGED', $clinicId, $actorId, $userId, ['old_role' => $m['member_role'], 'new_role' => $role]);
    }

    public function deactivate(int $clinicId, int $actorId, int $userId): void
    {
        $this->assertManager($clinicId, $actorId);
        $m = $this->assertTarget($clinicId, $actorId, $userId);
        if ($m['member_status'] !== 'active') {
            return;
        }
        (new AuthService($this->db))->revokeClinicMembership($clinicId, $userId);
        if ($m['user_status'] === 'invited') {
            $this->db->prepare('DELETE FROM password_resets WHERE LOWER(email) = ?')->execute([strtolower((string)$m['email'])]); // convite deixa de valer
        }
        $this->audit('TEAM_MEMBER_DEACTIVATED', $clinicId, $actorId, $userId, ['role' => $m['member_role']]);
    }

    public function reactivate(int $clinicId, int $actorId, int $userId): void
    {
        $this->assertManager($clinicId, $actorId);
        $m = $this->assertTarget($clinicId, $actorId, $userId);
        if ($m['member_status'] === 'active') {
            return;
        }
        $limit = $this->limit($clinicId);
        if (!$limit['available']) {
            throw new DomainException("Seu plano permite {$limit['max']} profissionais e a equipe já tem {$limit['used']} ativos. Troque de plano ou desative outra pessoa antes de reativar.");
        }
        $this->db->prepare("UPDATE clinic_members SET status = 'active' WHERE clinic_id = ? AND user_id = ?")->execute([$clinicId, $userId]);
        $this->audit('TEAM_MEMBER_REACTIVATED', $clinicId, $actorId, $userId, ['role' => $m['member_role']]);
    }

    /** Histórico das ações de equipe (mais recentes primeiro), sem dados sensíveis. */
    public function history(int $clinicId, int $limit = 20): array
    {
        try {
            $st = $this->db->prepare("SELECT action, resource_id, details, created_at FROM audit_logs WHERE clinic_id = ? AND action LIKE 'TEAM_%' ORDER BY id DESC LIMIT " . max(1, min(100, $limit)));
            $st->execute([$clinicId]);
            return $st->fetchAll(PDO::FETCH_ASSOC);
        } catch (\Throwable $e) {
            return [];
        }
    }

    private function member(int $clinicId, int $userId): array
    {
        $st = $this->db->prepare("SELECT u.id, u.name, u.email, u.status AS user_status, cm.role AS member_role, cm.status AS member_status
            FROM clinic_members cm JOIN users u ON u.id = cm.user_id WHERE cm.clinic_id = ? AND cm.user_id = ?");
        $st->execute([$clinicId, $userId]);
        $m = $st->fetch(PDO::FETCH_ASSOC);
        if (!$m) {
            throw new DomainException('Pessoa não encontrada nesta clínica.');
        }
        return $m;
    }

    private function assertManager(int $clinicId, int $actorId): void
    {
        if (!$this->canManage($clinicId, $actorId)) {
            throw new DomainException('Somente o titular ou um nutricionista gestor da clínica gerencia a equipe.');
        }
    }

    private function assertTarget(int $clinicId, int $actorId, int $userId): array
    {
        if ($userId === $actorId) {
            throw new DomainException('Você não pode alterar o seu próprio acesso por aqui.');
        }
        $m = $this->member($clinicId, $userId);
        if ($m['member_role'] === 'owner') {
            throw new DomainException('O acesso do titular da clínica não é alterado por aqui. Fale com o suporte.');
        }
        return $m;
    }

    private function audit(string $action, int $clinicId, int $actorId, int $userId, array $details): void
    {
        (new AuditLogger($this->db))->log($action, 'clinic_members', $clinicId . ':' . $userId, $details + ['clinic_id' => $clinicId, 'actor_user_id' => $actorId, 'target_user_id' => $userId]);
    }

    /** E-mail quando o servidor entrega; senão o link aparece uma vez (nunca em log). */
    private function deliver(int $clinicId, int $actorId, int $userId, string $name, string $email, string $role, array $issued): array
    {
        $url = $this->linkFor($issued['token']);
        $expires = date('d/m/Y H:i', (int)strtotime($issued['expires_at'] . ' UTC'));
        $clinic = '';
        try {
            $c = $this->db->prepare('SELECT name FROM clinics WHERE id = ?');
            $c->execute([$clinicId]);
            $clinic = (string)$c->fetchColumn();
        } catch (\Throwable $e) {
            /* NI2-892: sem o nome da clínica o convite sai com texto genérico. */
        }
        $delivery = 'manual';
        $note = 'O servidor de e-mail não está entregando mensagens: copie o link agora e envie à pessoa por um canal seguro.';
        if ($this->mailDelivers()) {
            try {
                $msg = SecurityNoticeEmail::build($email, $name, 'Convite para a equipe ' . ($clinic !== '' ? $clinic : 'Clyo Nutri'), 'Seu convite para a equipe',
                    ['Você foi convidado(a) para a equipe ' . ($clinic !== '' ? 'de ' . $clinic . ' ' : '') . 'na Clyo Nutri, com o papel ' . self::roleLabel($role) . '.',
                        'Crie a sua senha pelo botão abaixo. O link vale até ' . $expires . ' (horário de Brasília) e só funciona uma vez.',
                        'Se você não esperava este convite, ignore este e-mail.'],
                    'Criar minha senha', $url, null);
                ($this->mailer ?? MailerFactory::fromEnv())->send($msg);
                $delivery = 'email';
                $note = 'Convite enviado por e-mail para ' . $email . '.';
            } catch (\Throwable $e) {
                $note = 'O envio por e-mail falhou: copie o link agora e envie à pessoa por um canal seguro.';
            }
        }
        $this->audit('TEAM_INVITE_DELIVERY', $clinicId, $actorId, $userId, ['mode' => $delivery === 'email' ? 'email_sent' : 'shown_once']);
        return ['delivery' => $delivery, 'link' => $delivery === 'manual' ? $url : null, 'expires_at' => $expires, 'note' => $note];
    }

    private function mailDelivers(): bool
    {
        if ($this->mailer !== null) {
            return true;
        }
        try {
            $d = MailerFactory::describe();
            return (bool)$d['delivers'] && empty($d['problems']);
        } catch (\Throwable $e) {
            return false;
        }
    }

    private function linkFor(string $token): string
    {
        $path = '/convite/' . rawurlencode($token);
        $base = rtrim(trim((string)($this->appUrl ?? Env::get('APP_URL') ?? '')), '/');
        if (!preg_match('#^https?://[^/\s]+#i', $base)) {
            return $path;
        }
        if (Transport::isProduction() || Transport::forceHttpsEnabled()) {
            $base = (string)preg_replace('#^http://#i', 'https://', $base);
        }
        return $base . $path;
    }
}
