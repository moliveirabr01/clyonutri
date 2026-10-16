<?php

declare(strict_types=1);

namespace ClyoNutri\Core\Auth;

use ClyoNutri\Core\Database\Connection;
use ClyoNutri\Core\Mail\Mailer;
use ClyoNutri\Core\Mail\MailerFactory;
use ClyoNutri\Core\Mail\PasswordResetEmail;
use ClyoNutri\Core\Security\Env;
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
 * - Envio do link por e-mail (Mailer), com URL montada a partir de APP_URL (nunca do Host da requisição).
 * - Somente o hash SHA-256 do token é persistido em password_resets.token.
 * - Datas de expiração em UTC (gmdate) comparadas com "agora" em UTC fornecido pelo PHP,
 *   independente do fuso do servidor de banco (SQLite, MySQL 5.7/8).
 */
class PasswordResetService
{
    public const TOKEN_VALIDITY_SECONDS = 1800; // 30 minutos
    public const MAX_REQUESTS_PER_EMAIL_15M = 5;
    public const MAX_REQUESTS_PER_IP_15M = 10;

    private PDO $db;
    private ?Mailer $mailer;
    private ?string $appUrl;
    /** @var (callable(\Closure): void)|null Agenda o envio para depois da resposta HTTP. */
    private $deliveryScheduler = null;

    /**
     * @param Mailer|null $mailer Transporte de e-mail (padrão: MailerFactory::fromEnv(), criado sob demanda)
     * @param string|null $appUrl URL pública da aplicação (padrão: env APP_URL)
     */
    public function __construct(?PDO $db = null, ?Mailer $mailer = null, ?string $appUrl = null)
    {
        $this->db = $db ?? Connection::getInstance();
        $this->mailer = $mailer;
        $this->appUrl = $appUrl;
    }

    /**
     * Faz o envio do e-mail ser agendado em vez de executado na hora. O controller usa isso para
     * mandar a resposta ao navegador antes do SMTP, para que o tempo de resposta não revele se a
     * conta existe. O token fica só dentro da closure; nunca volta no array de requestReset().
     */
    public function deferEmailDelivery(?callable $scheduler): void
    {
        $this->deliveryScheduler = $scheduler;
    }

    /** Hash persistido do token (SHA-256 hex, 64 caracteres). */
    public static function hashToken(string $token): string
    {
        return hash('sha256', $token);
    }

    /** "Agora" em UTC no formato DATETIME, usado em todas as comparações de expiração. */
    private static function nowUtc(int $offsetSeconds = 0): string
    {
        return gmdate('Y-m-d H:i:s', time() + $offsetSeconds);
    }

    /**
     * Solicita redefinição de senha para um e-mail.
     * Retorna resposta segura e uniforme, sem revelar se a conta existe ou não.
     *
     * @param string $email E-mail fornecido pelo usuário
     * @param string|null $ipAddress Endereço IP do cliente
     * @param string|null $userAgent User Agent do cliente
     * @return array Metadados da operação (para o controller/view). Nunca contém o token:
     *               ele só sai do servidor dentro do e-mail.
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
                'email_sent' => false,
                'expires_at' => null,
                'message' => $genericMessage,
            ];
        }

        $userId = (int)$user['id'];
        $clinicId = !empty($user['clinic_id']) ? (int)$user['clinic_id'] : null;

        // 3. Geração de token criptográfico forte e expiração
        $token = bin2hex(random_bytes(32)); // 64 caracteres
        $tokenHash = self::hashToken($token);
        $createdAt = self::nowUtc();
        $expiresAt = self::nowUtc(self::TOKEN_VALIDITY_SECONDS); // UTC

        $this->db->beginTransaction();
        try {
            // Invalida tokens anteriores existentes para este e-mail
            $del = $this->db->prepare("DELETE FROM password_resets WHERE LOWER(email) = :email");
            $del->execute(['email' => $email]);

            // Insere o novo token único
            $ins = $this->db->prepare("
                INSERT INTO password_resets (email, token, created_at, expires_at)
                VALUES (:email, :token, :created_at, :expires_at)
            ");
            $ins->execute([
                'email' => $email,
                'token' => $tokenHash,
                'created_at' => $createdAt,
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
                    'expires_at_utc' => $expiresAt,
                ]
            );

            $this->db->commit();
        } catch (\Throwable $e) {
            $this->db->rollBack();
            throw $e;
        }

        // 4. Envio do e-mail. Falha de envio NÃO altera a resposta (não-enumerável):
        //    registra em error_log + auditoria e segue com a mensagem genérica.
        if ($this->deliveryScheduler !== null) {
            ($this->deliveryScheduler)(fn () => $this->sendResetEmail($user, $email, $token, $clinicId, $userId, $ipAddress, $userAgent));
            $emailSent = null; // agendado: o resultado vai para a auditoria
        } else {
            $emailSent = $this->sendResetEmail($user, $email, $token, $clinicId, $userId, $ipAddress, $userAgent);
        }
        unset($token);

        return [
            'success' => true,
            'email' => $email,
            'user_found' => true,
            'user_id' => $userId,
            'email_sent' => $emailSent,
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
        if (strlen($token) !== 64 || !ctype_xdigit($token)) {
            return null;
        }
        $tokenHash = self::hashToken(strtolower($token));

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
        $stmt->execute(['token' => $tokenHash]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$row || !hash_equals((string)$row['token'], $tokenHash)) {
            return null;
        }

        // Verifica status da conta
        if ($row['user_status'] !== 'active') {
            return null;
        }

        // Verifica expiração (expires_at está em UTC)
        $expiresTimestamp = strtotime((string)$row['expires_at'] . ' UTC');
        if ($expiresTimestamp === false || $expiresTimestamp <= time()) {
            // Token expirado: limpa da base
            $del = $this->db->prepare("DELETE FROM password_resets WHERE token = :token");
            $del->execute(['token' => $tokenHash]);
            return null;
        }

        return [
            'email' => $row['email'],
            'token_hash' => $row['token'],
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
                'token' => (string)$validToken['token_hash'],
                'email' => strtolower($email),
            ]);

            // 3. Invalidação de sessões antigas web (sessions)
            $delSessions = $this->db->prepare("DELETE FROM sessions WHERE user_id = :user_id");
            $delSessions->execute(['user_id' => $userId]);

            // 4. Invalidação de tokens Sanctum/API pessoais ativos
            $delApiTokens = $this->db->prepare("
                DELETE FROM personal_access_tokens
                WHERE tokenable_type IN ('user','professional') AND tokenable_id = :user_id
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
     * Retorna o HASH (SHA-256) do token ativo de um e-mail (testes/verificação).
     * O token em claro não é recuperável: ele só existe no e-mail enviado.
     */
    public function getActiveTokenForEmail(string $email): ?string
    {
        $stmt = $this->db->prepare("
            SELECT token FROM password_resets
            WHERE LOWER(email) = LOWER(:email) AND expires_at > :now
            ORDER BY created_at DESC
            LIMIT 1
        ");
        $stmt->execute(['email' => trim($email), 'now' => self::nowUtc()]);
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
        $stmt = $this->db->prepare("DELETE FROM password_resets WHERE expires_at <= :now");
        $stmt->execute(['now' => self::nowUtc()]);
        return $stmt->rowCount();
    }

    /**
     * Validação estrita de rate limiting (máximo 5 pedidos por e-mail e 10 por IP nos últimos 15 min).
     */
    private function assertRateLimitNotExceeded(string $email, ?string $ipAddress): void
    {
        $cutoff15m = self::nowUtc(-900); // audit_logs desta classe são gravados em UTC

        // 1. Checagem por e-mail
        $stmt = $this->db->prepare("
            SELECT COUNT(*) FROM audit_logs
            WHERE action = 'PASSWORD_RESET_REQUESTED'
              AND details LIKE :emailPattern
              AND created_at >= :cutoff
        ");
        $stmt->execute([
            'emailPattern' => '%' . $email . '%',
            'cutoff' => $cutoff15m,
        ]);
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
                  AND created_at >= :cutoff
            ");
            $stmtIp->execute([
                'ip' => $ipAddress,
                'cutoff' => $cutoff15m,
            ]);
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
            VALUES (:clinic_id, :user_id, :action, :resource, :resource_id, :ip_address, :user_agent, :details, :created_at)
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
            'created_at' => self::nowUtc(),
        ]);
    }

    /**
     * Monta e envia o e-mail de redefinição. Retorna true se enviado.
     * Nunca lança: falhas são registradas e a resposta ao usuário continua genérica.
     */
    private function sendResetEmail(
        array $user,
        string $email,
        string $token,
        ?int $clinicId,
        int $userId,
        ?string $ipAddress,
        ?string $userAgent
    ): bool {
        try {
            $baseUrl = rtrim(trim((string)($this->appUrl ?? Env::get('APP_URL') ?? '')), '/');
            if (!preg_match('#^https?://[^/\s]+#i', $baseUrl)) {
                // Nunca usa o Host da requisição como fallback (host header injection).
                throw new \RuntimeException('APP_URL ausente ou inválida; link de redefinição não pode ser montado.');
            }
            // v10: link de e-mail sempre em https:// em produção (mesmo que APP_URL esteja com http://).
            if (\ClyoNutri\Core\Http\Transport::isProduction() || \ClyoNutri\Core\Http\Transport::forceHttpsEnabled()) {
                $baseUrl = (string)preg_replace('#^http://#i', 'https://', $baseUrl);
            }
            $resetUrl = $baseUrl . '/recuperar-senha/' . rawurlencode($token);

            $message = PasswordResetEmail::build(
                (string)$user['email'],
                (string)($user['name'] ?? ''),
                $resetUrl,
                intdiv(self::TOKEN_VALIDITY_SECONDS, 60),
                self::supportReplyTo()
            );

            $this->mailer ??= MailerFactory::fromEnv();
            $this->mailer->send($message);

            $this->logAudit($clinicId, $userId, 'PASSWORD_RESET_EMAIL_SENT', 'password_resets', (string)$userId, $ipAddress, $userAgent, ['email' => $email]);
            return true;
        } catch (\Throwable $e) {
            error_log('[ClyoNutri][password-reset] Falha ao enviar e-mail de redefinição (user_id=' . $userId . '): ' . $e->getMessage());
            try {
                $this->logAudit($clinicId, $userId, 'PASSWORD_RESET_EMAIL_FAILED', 'password_resets', (string)$userId, $ipAddress, $userAgent, [
                    'email' => $email,
                    'error' => mb_substr($e->getMessage(), 0, 300),
                ]);
            } catch (\Throwable) {
                // auditoria indisponível não pode quebrar a resposta genérica
            }
            return false;
        }
    }

    /** APP_SUPPORT_EMAIL vira o responder-para do e-mail (o remetente costuma ser nao-responda@). */
    private static function supportReplyTo(): ?string
    {
        $support = trim((string)(Env::get('APP_SUPPORT_EMAIL') ?? ''));
        return filter_var($support, FILTER_VALIDATE_EMAIL) ? $support : null;
    }
}
