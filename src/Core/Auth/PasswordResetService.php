<?php

declare(strict_types=1);

namespace ClyoNutri\Core\Auth;

use ClyoNutri\Core\Database\Connection;
use InvalidArgumentException;
use PDO;
use RuntimeException;

/**
 * ClyoNutri II — Serviço Canônico de Recuperação de Senha & Gestão de Acesso (PX-29 / NI2-322)
 *
 * Implementa recuperação de acesso segura e não-enumerável:
 * - Tokens criptográficos unívocos gerados com random_bytes (64 caracteres hexadecimais).
 * - Expiração estrita de 30 minutos por token.
 * - Respostas uniformes e não-enumeráveis para prevenir enumeração de contas.
 * - Anti-abuso / Rate limiting por e-mail e endereço IP via trilha de auditoria.
 * - Revalidação e invalidação de tokens anteriores na solicitação de novo token.
 * - Descarte atômico do token após consumo (Single-Use Token).
 * - Revogação imediata de sessões ativas anteriores (sessões web e tokens Sanctum/API).
 * - Trilha de auditoria completa em audit_logs.
 */
class PasswordResetService
{
    public const TOKEN_VALIDITY_SECONDS = 1800; // 30 minutos
    public const MAX_REQUESTS_PER_EMAIL_15M = 5;
    public const MAX_REQUESTS_PER_IP_15M = 10;

    private PDO $db;

    public function __construct(?PDO $db = null)
    {
        $this->db = $db ?? Connection::getInstance();
    }

    /**
     * Solicita redefinição de senha para um e-mail.
     * Retorna resposta segura e uniforme, sem revelar se a conta existe ou não.
     *
     * @param string $email E-mail fornecido pelo usuário
     * @param string|null $ipAddress Endereço IP do cliente
     * @param string|null $userAgent User Agent do cliente
     * @return array Metadados da operação (para o controller/view)
     */
    public function requestReset(string $email, ?string $ipAddress = null, ?string $userAgent = null): array
    {
        $email = strtolower(trim($email));

        if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            throw new InvalidArgumentException('Por favor, informe um endereço de e-mail válido.');
        }

        // 1. Verificação de Rate Limiting / Anti-Abuso (15 minutos)
        $this->assertRateLimitNotExceeded($email, $ipAddress);

        // 2. Busca do usuário na base
        $stmt = $this->db->prepare("
            SELECT u.id, u.uuid, u.name, u.email, u.status,
                   cm.clinic_id
            FROM users u
            LEFT JOIN clinic_members cm ON cm.user_id = u.id AND cm.status = 'active'
            WHERE LOWER(u.email) = :email
            LIMIT 1
        ");
        $stmt->execute(['email' => $email]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);

        $genericMessage = 'Se o e-mail informado estiver cadastrado em nossa base, enviamos um link de recuperação com validade de 30 minutos. Verifique sua caixa de entrada e pasta de spam.';

        // Se o usuário não existir ou estiver inativo, registra log de segurança mas retorna a MESMA mensagem (não enumerável)
        if (!$user || $user['status'] !== 'active') {
            $this->logAudit(
                clinicId: null,
                userId: null,
                action: 'PASSWORD_RESET_ATTEMPT_UNKNOWN',
                resource: 'password_resets',
                resourceId: null,
                ipAddress: $ipAddress,
                userAgent: $userAgent,
                details: ['email' => $email, 'reason' => $user ? 'user_inactive' : 'user_not_found']
            );

            return [
                'success' => true,
                'email' => $email,
                'user_found' => false,
                'token' => null,
                'expires_at' => null,
                'message' => $genericMessage,
            ];
        }

        $userId = (int)$user['id'];
        $clinicId = !empty($user['clinic_id']) ? (int)$user['clinic_id'] : null;

        // 3. Geração de token criptográfico forte e expiração
        $token = bin2hex(random_bytes(32)); // 64 caracteres
        $expiresAt = date('Y-m-d H:i:s', time() + self::TOKEN_VALIDITY_SECONDS);

        $this->db->beginTransaction();
        try {
            // Invalida tokens anteriores existentes para este e-mail
            $del = $this->db->prepare("DELETE FROM password_resets WHERE LOWER(email) = :email");
            $del->execute(['email' => $email]);

            // Insere o novo token único
            $ins = $this->db->prepare("
                INSERT INTO password_resets (email, token, created_at, expires_at)
                VALUES (:email, :token, CURRENT_TIMESTAMP, :expires_at)
            ");
            $ins->execute([
                'email' => $email,
                'token' => $token,
                'expires_at' => $expiresAt,
            ]);

            // Registra auditoria
            $this->logAudit(
                clinicId: $clinicId,
                userId: $userId,
                action: 'PASSWORD_RESET_REQUESTED',
                resource: 'password_resets',
                resourceId: (string)$userId,
                ipAddress: $ipAddress,
                userAgent: $userAgent,
                details: [
                    'email' => $email,
                    'expires_at' => $expiresAt,
                    'token_prefix' => substr($token, 0, 8) . '...',
                ]
            );

            $this->db->commit();
        } catch (\Throwable $e) {
            $this->db->rollBack();
            throw $e;
        }

        return [
            'success' => true,
            'email' => $email,
            'user_found' => true,
            'user_id' => $userId,
            'token' => $token,
            'expires_at' => $expiresAt,
            'message' => $genericMessage,
        ];
    }

    /**
     * Valida um token de recuperação.
     * Retorna os dados do reset e do usuário caso válido e não expirado, ou null.
     */
    public function validateToken(string $token): ?array
    {
        $token = trim($token);
        if ($token === '' || strlen($token) < 32) {
            return null;
        }

        $stmt = $this->db->prepare("
            SELECT pr.email, pr.token, pr.created_at, pr.expires_at,
                   u.id AS user_id, u.uuid AS user_uuid, u.name AS user_name, u.status AS user_status,
                   cm.clinic_id
            FROM password_resets pr
            JOIN users u ON LOWER(u.email) = LOWER(pr.email)
            LEFT JOIN clinic_members cm ON cm.user_id = u.id AND cm.status = 'active'
            WHERE pr.token = :token
            LIMIT 1
        ");
        $stmt->execute(['token' => $token]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$row) {
            return null;
        }

        // Verifica status da conta
        if ($row['user_status'] !== 'active') {
            return null;
        }

        // Verifica expiração
        $expiresTimestamp = strtotime($row['expires_at']);
        if ($expiresTimestamp === false || $expiresTimestamp <= time()) {
            // Token expirado: limpa da base
            $del = $this->db->prepare("DELETE FROM password_resets WHERE token = :token");
            $del->execute(['token' => $token]);
            return null;
        }

        return [
            'email' => $row['email'],
            'token' => $row['token'],
            'user_id' => (int)$row['user_id'],
            'user_uuid' => $row['user_uuid'],
            'user_name' => $row['user_name'],
            'clinic_id' => !empty($row['clinic_id']) ? (int)$row['clinic_id'] : null,
            'expires_at' => $row['expires_at'],
        ];
    }

    /**
     * Redefine a senha de acesso usando o token.
     * Exclui o token (single-use) e revoga todas as sessões anteriores do usuário.
     *
     * @param string $token Token de recuperação
     * @param string $newPassword Nova senha escolhida
     * @param string|null $ipAddress Endereço IP do cliente
     * @param string|null $userAgent User Agent do cliente
     * @return array Resultado da redefinição
     */
    public function resetPassword(
        string $token,
        string $newPassword,
        ?string $ipAddress = null,
        ?string $userAgent = null
    ): array {
        if (strlen($newPassword) < 8) {
            throw new InvalidArgumentException('A nova senha deve possuir pelo menos 8 caracteres.');
        }

        $validToken = $this->validateToken($token);
        if (!$validToken) {
            throw new InvalidArgumentException('Link de recuperação inválido ou expirado. Por favor, solicite um novo.');
        }

        $userId = (int)$validToken['user_id'];
        $email = (string)$validToken['email'];
        $clinicId = $validToken['clinic_id'];

        $newPasswordHash = password_hash($newPassword, PASSWORD_BCRYPT, ['cost' => 12]);

        $this->db->beginTransaction();
        try {
            // 1. Atualiza hash da senha do usuário
            $upd = $this->db->prepare("
                UPDATE users
                SET password_hash = :hash, auth_version = auth_version + 1, updated_at = CURRENT_TIMESTAMP
                WHERE id = :id
            ");
            $upd->execute([
                'hash' => $newPasswordHash,
                'id' => $userId,
            ]);

            // 2. Remove o token utilizado (garantindo Single-Use)
            $delToken = $this->db->prepare("DELETE FROM password_resets WHERE token = :token OR LOWER(email) = :email");
            $delToken->execute([
                'token' => $token,
                'email' => strtolower($email),
            ]);

            // 3. Invalidação de sessões antigas web (sessions)
            $delSessions = $this->db->prepare("DELETE FROM sessions WHERE user_id = :user_id");
            $delSessions->execute(['user_id' => $userId]);

            // 4. Invalidação de tokens Sanctum/API pessoais ativos
            $delApiTokens = $this->db->prepare("
                DELETE FROM personal_access_tokens
                WHERE tokenable_type = 'user' AND tokenable_id = :user_id
            ");
            $delApiTokens->execute(['user_id' => $userId]);

            // 5. Registro na trilha de auditoria
            $this->logAudit(
                clinicId: $clinicId,
                userId: $userId,
                action: 'PASSWORD_RESET_COMPLETED',
                resource: 'users',
                resourceId: (string)$userId,
                ipAddress: $ipAddress,
                userAgent: $userAgent,
                details: [
                    'email' => $email,
                    'sessions_revoked' => true,
                    'method' => 'token_recovery',
                ]
            );

            $this->db->commit();

            return [
                'success' => true,
                'user_id' => $userId,
                'email' => $email,
                'message' => 'Senha alterada com sucesso! Você já pode entrar com sua nova senha.',
            ];
        } catch (\Throwable $e) {
            $this->db->rollBack();
            throw $e;
        }
    }

    /**
     * Retorna o token ativo de um e-mail (utilizado por testes e verificação automatizada).
     */
    public function getActiveTokenForEmail(string $email): ?string
    {
        $stmt = $this->db->prepare("
            SELECT token FROM password_resets
            WHERE LOWER(email) = LOWER(:email) AND expires_at > CURRENT_TIMESTAMP
            ORDER BY created_at DESC
            LIMIT 1
        ");
        $stmt->execute(['email' => trim($email)]);
        $res = $stmt->fetchColumn();
        return $res ?: null;
    }

    /**
     * Revoga todos os tokens pendentes de um e-mail.
     */
    public function revokeTokensForEmail(string $email): void
    {
        $stmt = $this->db->prepare("DELETE FROM password_resets WHERE LOWER(email) = LOWER(:email)");
        $stmt->execute(['email' => trim($email)]);
    }

    /**
     * Remove tokens expirados da base de dados.
     */
    public function cleanExpiredTokens(): int
    {
        $stmt = $this->db->prepare("DELETE FROM password_resets WHERE expires_at <= CURRENT_TIMESTAMP");
        $stmt->execute();
        return $stmt->rowCount();
    }

    /**
     * Validação estrita de rate limiting (máximo 5 pedidos por e-mail e 10 por IP nos últimos 15 min).
     */
    private function assertRateLimitNotExceeded(string $email, ?string $ipAddress): void
    {
        // 1. Checagem por e-mail
        $stmt = $this->db->prepare("
            SELECT COUNT(*) FROM audit_logs
            WHERE action = 'PASSWORD_RESET_REQUESTED'
              AND details LIKE :emailPattern
              AND created_at >= datetime('now', '-15 minutes')
        ");
        $stmt->execute(['emailPattern' => '%' . $email . '%']);
        $countEmail = (int)$stmt->fetchColumn();

        if ($countEmail >= self::MAX_REQUESTS_PER_EMAIL_15M) {
            throw new RuntimeException(
                'Muitas solicitações de recuperação para este e-mail em um curto intervalo. ' .
                'Por segurança, aguarde alguns minutos antes de tentar novamente.'
            );
        }

        // 2. Checagem por IP
        if ($ipAddress !== null && $ipAddress !== '') {
            $stmtIp = $this->db->prepare("
                SELECT COUNT(*) FROM audit_logs
                WHERE action IN ('PASSWORD_RESET_REQUESTED', 'PASSWORD_RESET_ATTEMPT_UNKNOWN')
                  AND ip_address = :ip
                  AND created_at >= datetime('now', '-15 minutes')
            ");
            $stmtIp->execute(['ip' => $ipAddress]);
            $countIp = (int)$stmtIp->fetchColumn();

            if ($countIp >= self::MAX_REQUESTS_PER_IP_15M) {
                throw new RuntimeException(
                    'Muitas solicitações originadas deste endereço IP. ' .
                    'Por segurança, aguarde alguns minutos antes de tentar novamente.'
                );
            }
        }
    }

    /**
     * Gravação segura na tabela audit_logs.
     */
    private function logAudit(
        ?int $clinicId,
        ?int $userId,
        string $action,
        string $resource,
        ?string $resourceId,
        ?string $ipAddress,
        ?string $userAgent,
        array $details
    ): void {
        $stmt = $this->db->prepare("
            INSERT INTO audit_logs (clinic_id, user_id, action, resource, resource_id, ip_address, user_agent, details, created_at)
            VALUES (:clinic_id, :user_id, :action, :resource, :resource_id, :ip_address, :user_agent, :details, CURRENT_TIMESTAMP)
        ");
        $stmt->execute([
            'clinic_id' => $clinicId,
            'user_id' => $userId,
            'action' => $action,
            'resource' => $resource,
            'resource_id' => $resourceId,
            'ip_address' => $ipAddress,
            'user_agent' => $userAgent,
            'details' => json_encode($details, JSON_UNESCAPED_UNICODE),
        ]);
    }
}
