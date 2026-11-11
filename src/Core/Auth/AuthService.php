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

    public function getDb(): PDO
    {
        return $this->db;
    }

    /**
     * Registra novo nutricionista e sua clínica (Tenant).
     *
     * NI2-449: grava, na mesma transação, o aceite dos Termos de Uso e da Política de Privacidade
     * (user_legal_acceptances). Se a gravação falhar, o cadastro inteiro é desfeito.
     * $legalAcceptance: ['source' => ..., 'ip' => ..., 'user_agent' => ..., 'versions' => [documento => versão vista]].
     * O cadastro pela tela (/cadastro) informa tudo; chamadas internas (seed local, testes) gravam origem 'internal'.
     */
    public function registerNutritionist(
        string $name,
        string $email,
        string $password,
        string $clinicName,
        string $crn,
        string $crnRegion,
        ?array $legalAcceptance = null,
        ?string $planCode = null
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

            // 5. Aceite versionado dos Termos e da Política (NI2-449)
            (new \ClyoNutri\Domain\Legal\LegalAcceptanceService($this->db))->recordUserAcceptance(
                $userId,
                $clinicId,
                (string)($legalAcceptance['source'] ?? 'internal'),
                isset($legalAcceptance['ip']) ? (string)$legalAcceptance['ip'] : null,
                isset($legalAcceptance['user_agent']) ? (string)$legalAcceptance['user_agent'] : null,
                isset($legalAcceptance['versions']) && is_array($legalAcceptance['versions']) ? $legalAcceptance['versions'] : null
            );

            // 6. Período de teste de 14 dias contado da criação da conta (NI2-450), como dizem os Termos.
            // Valida estritamente o plano informado contra o catálogo oficial (NI2-804)
            $effectivePlan = ($planCode !== null && in_array(strtoupper(trim($planCode)), \ClyoNutri\Application\Controllers\AuthController::ALLOWED_REGISTER_PLANS, true))
                ? strtoupper(trim($planCode))
                : \ClyoNutri\Domain\Subscription\SubscriptionService::DEFAULT_TRIAL_PLAN;

            $trialPlan = $this->db->prepare('SELECT 1 FROM saas_plans WHERE code = ?');
            $trialPlan->execute([$effectivePlan]);
            $hasTrialPlan = $trialPlan->fetchColumn() !== false;
            $trialPlan = null;
            if ($hasTrialPlan) {
                (new \ClyoNutri\Domain\Subscription\SubscriptionService($this->db))->initializeDefaultSubscription($clinicId, $effectivePlan);
            }

            // 7. Auditoria de Criação
            $insAudit = $this->db->prepare("INSERT INTO audit_logs (clinic_id, user_id, action, resource, resource_id, details) VALUES (:clinic_id, :user_id, 'USER_REGISTER', 'users', :res_id, :details)");
            $insAudit->execute([
                'clinic_id' => $clinicId,
                'user_id' => $userId,
                'res_id' => (string)$userId,
                'details' => json_encode(['email' => $email, 'clinic' => $clinicName]),
            ]);

            $this->db->commit();

            // v34: aviso ao backoffice DEPOIS do commit. Nunca lança; se falhar, o cadastro segue e a varredura recupera.
            if (!$this->db->inTransaction()) {
                (new \ClyoNutri\Domain\Notification\StaffNotificationService($this->db))->notifyClinicSignup($clinicId);
            }

            return [
                'user_id' => $userId,
                'user_uuid' => $userUuid,
                'clinic_id' => $clinicId,
                'email' => $email,
                'name' => $name,
            ];
        } catch (\Throwable $e) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
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
            // v34: conta da EQUIPE travada avisa os superadmins (no máximo 1 aviso por conta a cada 30 min; nunca lança)
            (new \ClyoNutri\Domain\Notification\StaffNotificationService($this->db))->notifyLoginLocked($email);
            return ['status' => 'locked', 'user' => null, 'retry_after' => (int)$gate['retry_after']];
        }
        $user = $this->attempt($email, $password);
        if ($user === null) {
            $limiter->failure('staff', $email, $ip);
            // v33: conta suspensa ou bloqueada. A mensagem diferente só aparece quando a senha confere,
            // para não revelar a ninguém que um e-mail existe (sem enumeração de contas).
            if ($this->inactiveAccountMatches($email, $password)) {
                return ['status' => 'inactive', 'user' => null, 'retry_after' => 0];
            }
            return ['status' => 'invalid', 'user' => null, 'retry_after' => 0];
        }
        $limiter->success('staff', $email, $ip);
        $this->recordLogin((int)$user['id']);
        return ['status' => 'ok', 'user' => $user, 'retry_after' => 0];
    }

    /** True se o e-mail é de uma conta suspensa/bloqueada E a senha confere. Nunca registra a senha. */
    private function inactiveAccountMatches(string $email, string $password): bool
    {
        try {
            $stmt = $this->db->prepare("SELECT id, password_hash, status FROM users WHERE email = :email AND status IN ('suspended', 'blocked') LIMIT 1");
            $stmt->execute(['email' => strtolower(trim($email))]);
            $row = $stmt->fetch(\PDO::FETCH_ASSOC);
            if (!$row || !password_verify($password, (string)$row['password_hash'])) {
                return false;
            }
            try {
                $this->db->prepare("INSERT INTO audit_logs (clinic_id, user_id, action, resource, resource_id, ip_address, user_agent, details, created_at) VALUES (NULL, :uid, 'AUTH_LOGIN_DENIED_INACTIVE_ACCOUNT', 'users', :rid, :ip, :ua, :details, :created_at)")->execute([
                    'uid' => (int)$row['id'],
                    'rid' => (string)$row['id'],
                    'ip' => substr((string)($_SERVER['REMOTE_ADDR'] ?? ''), 0, 45),
                    'ua' => substr((string)($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 255),
                    'details' => json_encode(['account_status' => (string)$row['status']]),
                    'created_at' => date('Y-m-d H:i:s'),
                ]);
            } catch (\Throwable) {
                // auditoria indisponível não muda a resposta ao usuário
            }
            return true;
        } catch (\Throwable) {
            return false;
        }
    }

    /**
     * Registra o último login bem-sucedido (backoffice: "Último login"). Só profissional/equipe; paciente fica de fora.
     * Melhor esforço: se a coluna ainda não existe ou o banco falha, o login segue normalmente.
     */
    public function recordLogin(int $userId): void
    {
        (new LastLoginService($this->db))->record($userId);
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
     * Incrementa o auth_version do usuário para invalidar imediatamente outras sessões ativas (SEC-F005).
     * Retorna a nova versão para persistência na sessão atual recém-iniciada.
     */
    public function bumpAuthVersion(int $userId): int
    {
        $this->db->prepare("UPDATE users SET auth_version = auth_version + 1, updated_at = CURRENT_TIMESTAMP WHERE id = :id")
            ->execute(['id' => $userId]);

        try {
            $this->db->prepare("DELETE FROM sessions WHERE user_id = :id")->execute(['id' => $userId]);
        } catch (\Throwable) {}

        $stmt = $this->db->prepare("SELECT auth_version FROM users WHERE id = :id");
        $stmt->execute(['id' => $userId]);
        return (int)$stmt->fetchColumn();
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

        // Papéis de plataforma (users.role 'admin' = administrador da PLATAFORMA, operator, support_agent)
        // só podem ser concedidos por equipe da plataforma; CLI (bin/) conta como plataforma.
        if (in_array($newRole, \ClyoNutri\Core\Security\RoleModel::PLATFORM_ROLES, true)) {
            $grantorRole = null;
            if ($changedByUserId !== null) {
                $g = $this->db->prepare("SELECT role FROM users WHERE id = :id AND status = 'active'");
                $g->execute(['id' => $changedByUserId]);
                $grantorRole = (string)$g->fetchColumn();
            }
            $isCli = PHP_SAPI === 'cli' && $changedByUserId === null;
            if (!$isCli && !\ClyoNutri\Core\Security\RoleModel::canGrantUserRole($grantorRole, $newRole)) {
                throw new \ClyoNutri\Core\Security\AuthorizationException("Somente a equipe da plataforma pode conceder o papel {$newRole}.");
            }
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

