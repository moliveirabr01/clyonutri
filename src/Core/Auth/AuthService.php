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

            // 2. Criar Usuário
            $insUser = $this->db->prepare("INSERT INTO users (uuid, name, email, password_hash, role, is_verified, status) VALUES (:uuid, :name, :email, :hash, 'nutritionist', 1, 'active')");
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
     * Autenticação segura com verificação de tenant
     */
    public function attempt(string $email, string $password): ?array
    {
        $email = strtolower(trim($email));
        $stmt = $this->db->prepare("
            SELECT u.id, u.uuid, u.name, u.email, u.password_hash, u.role, u.status,
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

    public static function generateUuid(): string
    {
        $data = random_bytes(16);
        $data[6] = chr(ord($data[6]) & 0x0f | 0x40); // version 4
        $data[8] = chr(ord($data[8]) & 0x3f | 0x80); // variant RFC 4122
        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($data), 4));
    }
}
