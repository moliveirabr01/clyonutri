<?php

declare(strict_types=1);

namespace ClyoNutri\Core\Auth;

use ClyoNutri\Core\Database\Connection;
use PDO;

class AuthService
{
    private PDO $db;

    public function __construct(?PDO $db = null)
    {
        $this->db = $db ?? Connection::getInstance();
    }

    /**
     * Registra novo nutricionista e sua clínica (Tenant)
     */
    public function registerNutritionist(
        string $name,
        string $email,
        string $password,
        string $clinicName,
        string $crn,
        string $crnRegion
    ): array {
        $email = strtolower(trim($email));

        // Checar se já existe
        $stmt = $this->db->prepare("SELECT id FROM users WHERE email = :email");
        $stmt->execute(['email' => $email]);
        if ($stmt->fetch()) {
            throw new \InvalidArgumentException("Este e-mail já está cadastrado.");
        }

        $passwordHash = password_hash($password, PASSWORD_BCRYPT, ['cost' => 12]);
        $userUuid = self::generateUuid();
        $clinicUuid = self::generateUuid();

        $this->db->beginTransaction();
        try {
            // 1. Criar Clínica / Tenant
            $insClinic = $this->db->prepare("INSERT INTO clinics (uuid, name, email, status) VALUES (:uuid, :name, :email, 'active')");
            $insClinic->execute([
                'uuid' => $clinicUuid,
                'name' => $clinicName,
                'email' => $email,
            ]);
            $clinicId = (int)$this->db->lastInsertId();

            // 2. Criar Usuário (Inicia com is_verified = 0 até confirmação efetiva de e-mail/CRN - NI2-209)
            $insUser = $this->db->prepare("INSERT INTO users (uuid, name, email, password_hash, role, is_verified, status) VALUES (:uuid, :name, :email, :hash, 'nutritionist', 0, 'active')");
            $insUser->execute([
                'uuid' => $userUuid,
                'name' => $name,
                'email' => $email,
                'hash' => $passwordHash,
            ]);
            $userId = (int)$this->db->lastInsertId();

            // 3. Vincular Membro com papel de Admin/Proprietário
            $insMember = $this->db->prepare("INSERT INTO clinic_members (clinic_id, user_id, role, status) VALUES (:clinic_id, :user_id, 'owner', 'active')");
            $insMember->execute([
                'clinic_id' => $clinicId,
                'user_id' => $userId,
            ]);

            // 4. Criar Registro Profissional
            $insProf = $this->db->prepare("INSERT INTO professionals (user_id, crn, crn_region) VALUES (:user_id, :crn, :region)");
            $insProf->execute([
                'user_id' => $userId,
                'crn' => trim($crn),
                'region' => trim($crnRegion),
            ]);

            // 5. Auditoria de Criação
            $insAudit = $this->db->prepare("INSERT INTO audit_logs (clinic_id, user_id, action, resource, resource_id, details) VALUES (:clinic_id, :user_id, 'USER_REGISTER', 'users', :res_id, :details)");
            $insAudit->execute([
                'clinic_id' => $clinicId,
                'user_id' => $userId,
                'res_id' => (string)$userId,
                'details' => json_encode(['email' => $email, 'clinic' => $clinicName]),
            ]);

            $this->db->commit();

            return [
                'user_id' => $userId,
                'user_uuid' => $userUuid,
                'clinic_id' => $clinicId,
                'email' => $email,
                'name' => $name,
            ];
        } catch (\Throwable $e) {
            $this->db->rollBack();
            throw $e;
        }
    }

    /**
     * Login web com limitador persistente (F03): a tentativa é reservada ANTES do password_verify.
     * Retorna ['status' => 'ok'|'invalid'|'locked', 'user' => ?array, 'retry_after' => int].
     * Conta inexistente e senha errada produzem o mesmo 'invalid' e consomem o mesmo limite.
     */
    public function attemptWithRateLimit(string $email, string $password, string $ip, ?\ClyoNutri\Core\Security\LoginRateLimiter $limiter = null): array
    {
        $limiter ??= new \ClyoNutri\Core\Security\LoginRateLimiter($this->db);
        $gate = $limiter->attempt('staff', $email, $ip);
        if (!$gate['allowed']) {
            return ['status' => 'locked', 'user' => null, 'retry_after' => (int)$gate['retry_after']];
        }
        $user = $this->attempt($email, $password);
        if ($user === null) {
            $limiter->failure('staff', $email, $ip);
            return ['status' => 'invalid', 'user' => null, 'retry_after' => 0];
        }
        $limiter->success('staff', $email, $ip);
        return ['status' => 'ok', 'user' => $user, 'retry_after' => 0];
    }

    /**
     * Autenticação segura com verificação de tenant
     */
    public function attempt(string $email, string $password): ?array
    {
        $email = strtolower(trim($email));
        $stmt = $this->db->prepare("
            SELECT u.id, u.uuid, u.name, u.email, u.password_hash, u.auth_version, u.role, u.status,
                   cm.clinic_id, cm.role AS member_role, c.name AS clinic_name
            FROM users u
            LEFT JOIN clinic_members cm ON cm.user_id = u.id AND cm.status = 'active'
            LEFT JOIN clinics c ON c.id = cm.clinic_id AND c.status = 'active'
            WHERE u.email = :email AND u.status = 'active'
            LIMIT 1
        ");
        $stmt->execute(['email' => $email]);
        $user = $stmt->fetch();

        if (!$user) {
            return null;
        }

        if (!password_verify($password, $user['password_hash'])) {
            return null;
        }

        unset($user['password_hash']);
        return $user;
    }

    /**
     * Valida explicitamente o e-mail ou credencial do profissional (ativa is_verified)
     */
    public function verifyUserEmail(int $userId): bool
    {
        $stmt = $this->db->prepare("UPDATE users SET is_verified = 1, updated_at = CURRENT_TIMESTAMP WHERE id = :id");
        $stmt->execute(['id' => $userId]);
        return $stmt->rowCount() > 0;
    }

    /**
     * Consulta se a conta do usuário já foi verificada
     */
    public function isVerified(int $userId): bool
    {
        $stmt = $this->db->prepare("SELECT is_verified FROM users WHERE id = :id");
        $stmt->execute(['id' => $userId]);
        $val = $stmt->fetchColumn();
        return (int)$val === 1;
    }

    public static function generateUuid(): string
    {
        $data = random_bytes(16);
        $data[6] = chr(ord($data[6]) & 0x0f | 0x40); // version 4
        $data[8] = chr(ord($data[8]) & 0x3f | 0x80); // variant RFC 4122
        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($data), 4));
    }

    /**
     * Invalida todas as sessões e tokens de API ativos do usuário (PX-23 / NI2-316)
     */
    public function revokeUserSessions(int $userId): void
    {
        $this->db->prepare("UPDATE users SET auth_version = auth_version + 1, updated_at = CURRENT_TIMESTAMP WHERE id = :id")
            ->execute(['id' => $userId]);

        try {
            $this->db->prepare("DELETE FROM sessions WHERE user_id = :id")->execute(['id' => $userId]);
        } catch (\Throwable) {}

        try {
            $this->db->prepare("DELETE FROM personal_access_tokens WHERE tokenable_type IN ('user','professional') AND tokenable_id = :id")
                ->execute(['id' => $userId]);
        } catch (\Throwable) {}
    }

    /**
     * Revoga vínculo de um profissional com uma clínica e invalida sessões (PX-23 / NI2-316)
     */
    public function revokeClinicMembership(int $clinicId, int $userId): void
    {
        $this->db->prepare("UPDATE clinic_members SET status = 'revoked' WHERE clinic_id = :clinic_id AND user_id = :user_id")
            ->execute(['clinic_id' => $clinicId, 'user_id' => $userId]);

        $this->revokeUserSessions($userId);
    }

    /**
     * Atualiza o papel canônico do usuário e invalida sessões antigas para forçar re-autenticação (NI2-256 / F01)
     */
    public function updateUserRole(int $userId, string $newRole, ?int $changedByUserId = null): void
    {
        $validRoles = ['admin', 'nutritionist', 'operator', 'support_agent', 'receptionist'];
        if (!in_array($newRole, $validRoles, true)) {
            throw new \InvalidArgumentException("Papel de usuário inválido: {$newRole}");
        }

        $stmtOld = $this->db->prepare("SELECT role FROM users WHERE id = :id");
        $stmtOld->execute(['id' => $userId]);
        $oldRole = (string)$stmtOld->fetchColumn();

        $this->db->prepare("
            UPDATE users
            SET role = :role, auth_version = auth_version + 1, updated_at = CURRENT_TIMESTAMP
            WHERE id = :id
        ")->execute([
            'role' => $newRole,
            'id' => $userId,
        ]);

        $audit = new \ClyoNutri\Core\Audit\AuditLogger($this->db);
        $audit->log('USER_ROLE_CHANGED', 'users', (string)$userId, [
            'old_role' => $oldRole,
            'new_role' => $newRole,
            'changed_by' => $changedByUserId,
        ]);
    }

    /**
     * Atualiza o papel do membro na clínica e incrementa auth_version (NI2-256 / F01)
     */
    public function updateMembershipRole(int $clinicId, int $userId, string $newMemberRole, ?int $changedByUserId = null): void
    {
        $validRoles = ['owner', 'admin', 'nutritionist', 'operator', 'support_agent', 'receptionist'];
        if (!in_array($newMemberRole, $validRoles, true)) {
            throw new \InvalidArgumentException("Papel de membro inválido: {$newMemberRole}");
        }

        $stmtOld = $this->db->prepare("SELECT role FROM clinic_members WHERE clinic_id = :cid AND user_id = :uid");
        $stmtOld->execute(['cid' => $clinicId, 'uid' => $userId]);
        $oldRole = (string)$stmtOld->fetchColumn();

        $this->db->prepare("
            UPDATE clinic_members
            SET role = :role
            WHERE clinic_id = :cid AND user_id = :uid
        ")->execute([
            'role' => $newMemberRole,
            'cid' => $clinicId,
            'uid' => $userId,
        ]);

        $this->db->prepare("UPDATE users SET auth_version = auth_version + 1, updated_at = CURRENT_TIMESTAMP WHERE id = :id")
            ->execute(['id' => $userId]);

        $audit = new \ClyoNutri\Core\Audit\AuditLogger($this->db);
        $audit->log('MEMBERSHIP_ROLE_CHANGED', 'clinic_members', "{$clinicId}:{$userId}", [
            'clinic_id' => $clinicId,
            'user_id' => $userId,
            'old_role' => $oldRole,
            'new_role' => $newMemberRole,
            'changed_by' => $changedByUserId,
        ]);
    }
}

