<?php

declare(strict_types=1);

namespace ClyoNutri\Core\Security;

use ClyoNutri\Core\Settings\PlatformSettings;
use PDO;

/**
 * Limitador persistente e compartilhado de tentativas de login (auditoria 14/10/2026, F03).
 *
 * - Baldes: (conta normalizada + IP) com limite = PlatformSettings::getMaxLoginAttempts (padrão 5)
 *   e (somente IP) com limite 10x maior, ambos numa janela de 15 minutos.
 * - A tentativa é RESERVADA (contada) antes do password_verify, com UPDATE atômico: requisições
 *   concorrentes não ultrapassam a política. Sucesso limpa o balde da conta.
 * - Ao atingir o limite, bloqueio temporário progressivo (60s, 120s, 240s... até 15 min). Nunca é permanente:
 *   ao fim do bloqueio e da janela a contagem é zerada.
 * - O contador vive no banco: trocar de sessão/cookie ou alternar senha/PIN não zera nada.
 *
 * Reauditoria V2-02 — contador agregado por conta, independente de IP:
 * - Terceiro balde "acct|realm|conta" (sem IP), limite = max × ACCOUNT_MULTIPLIER (padrão 5×4 = 20) na mesma
 *   janela de 15 min. Tentativas da mesma conta vindas de IPs diferentes somam aqui: um ataque distribuído
 *   recebe no máximo ~20 tentativas por janela, mais o atraso progressivo.
 * - Ao estourar: atraso temporário progressivo (60s, 120s... até 15 min). Nunca permanente; ao fim da
 *   janela e do atraso a contagem recomeça.
 * - Proteção da vítima contra bloqueio induzido: um IP que já teve login BEM-SUCEDIDO nessa conta nos últimos
 *   TRUSTED_IP_DAYS dias ("IP conhecido") não é barrado pelo balde agregado — continua sujeito aos baldes
 *   conta+IP (5) e IP (50). O atacante, de IPs desconhecidos, fica limitado; o dono, do IP de costume, entra.
 *   O registro de IP conhecido usa a mesma tabela (scope 'trusted_ip', locked_until = validade): sem mudança de esquema.
 * - Sucesso NÃO mexe no balde agregado (nem devolve a reserva), para que o login da vítima não
 *   "recarregue" o orçamento do atacante. Logins seguintes do mesmo IP já são de IP conhecido.
 * - Para pacientes, o chamador resolve e-mail/telefone para a MESMA identidade (patient#ID) antes de chamar
 *   o limitador (resolvePatientIdentity), então alternar identificadores não zera nada.
 * - Nada de senha/PIN é armazenado ou auditado; a conta é guardada só como hash SHA-256.
 */
final class LoginRateLimiter
{
    public const WINDOW_SECONDS = 900;
    public const BASE_LOCK_SECONDS = 60;
    public const MAX_LOCK_SECONDS = 900;
    public const IP_MULTIPLIER = 10;
    public const ACCOUNT_MULTIPLIER = 4;
    public const TRUSTED_IP_DAYS = 30;

    /** @var callable():int */
    private $clock;

    public function __construct(private PDO $db, ?callable $clock = null, private ?int $maxAttempts = null)
    {
        $this->clock = $clock ?? static fn(): int => time();
    }

    public static function normalizeAccount(string $account): string
    {
        $a = mb_strtolower(trim($account), 'UTF-8');
        if ($a !== '' && !str_contains($a, '@')) {
            $digits = preg_replace('/\D+/', '', $a);
            if ($digits !== '') {
                return $digits;
            }
        }
        return $a;
    }

    /**
     * Resolve o identificador de login do paciente (e-mail e/ou telefone) para uma identidade única,
     * usando a MESMA regra de correspondência do login. Se exatamente um paciente ativo corresponde,
     * a chave é "patient#ID" (e-mail e telefone do mesmo paciente caem no mesmo balde). Caso contrário,
     * cai no identificador normalizado (conta inexistente continua consumindo limite igualmente).
     */
    public static function resolvePatientIdentity(PDO $db, string $email, string $phone): string
    {
        $email = trim($email);
        $phone = trim($phone);
        try {
            // Comparação tolerante (maiúsculas/espaços no e-mail; pontuação no telefone), mais ampla que a do
            // login: qualquer variação que possa autenticar como o paciente cai na mesma identidade.
            $normEmail = $email !== '' ? mb_strtolower($email, 'UTF-8') : '';
            $normPhone = $phone !== '' ? (string)preg_replace('/\D+/', '', $phone) : '';
            $digits = "REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(COALESCE(p.phone,''),' ',''),'-',''),'(',''),')',''),'+',''),'.','')";
            $st = $db->prepare("SELECT p.id FROM patients p JOIN clinics c ON c.id = p.clinic_id
                WHERE ((:ep <> '' AND LOWER(TRIM(p.email)) = :e) OR (:pp <> '' AND {$digits} = :p))
                  AND p.status = 'active' AND c.status = 'active' LIMIT 2");
            $st->execute(['ep' => $normEmail, 'e' => $normEmail, 'pp' => $normPhone, 'p' => $normPhone]);
            $ids = $st->fetchAll(PDO::FETCH_COLUMN);
            if (count($ids) === 1) {
                return 'patient#' . (int)$ids[0];
            }
        } catch (\Throwable) {
            // falha de leitura: usa o identificador normalizado
        }
        return self::normalizeAccount($email !== '' ? $email : $phone);
    }

    public function maxAttempts(): int
    {
        if ($this->maxAttempts === null) {
            try {
                $this->maxAttempts = PlatformSettings::getMaxLoginAttempts($this->db);
            } catch (\Throwable) {
                $this->maxAttempts = 5;
            }
        }
        return $this->maxAttempts;
    }

    /**
     * Reserva uma tentativa. Retorna ['allowed'=>bool, 'retry_after'=>int].
     * $realm separa populações de contas (ex.: 'staff' para users, 'patient' para pacientes).
     */
    public function attempt(string $realm, string $account, string $ip): array
    {
        $now = ($this->clock)();
        $buckets = $this->buckets($realm, $account, $ip);
        if ($this->isTrustedIp($realm, $account, $ip, $now)) {
            $buckets = array_values(array_filter($buckets, static fn($b) => $b[1] !== 'account'));
        }
        $reserved = [];
        foreach ($buckets as [$key, $scope, $limit]) {
            $r = $this->reserve($key, $scope, $limit, $now);
            if (!$r['allowed']) {
                // devolve as reservas já feitas para não penalizar o outro balde à toa
                foreach ($reserved as $k) {
                    $this->db->prepare("UPDATE login_attempts SET failures = failures - 1 WHERE bucket_key = :k AND failures > 0")->execute(['k' => $k]);
                }
                $this->audit('LOGIN_RATE_LIMITED', $realm, $scope, $ip);
                return $r;
            }
            $reserved[] = $key;
        }
        return ['allowed' => true, 'retry_after' => 0];
    }

    /**
     * Credencial correta: zera o balde conta+IP, devolve a reserva do balde de IP e marca este IP como
     * conhecido para a conta. O balde agregado da conta NÃO é tocado: o login da vítima não pode
     * zerar nem aliviar o orçamento/atraso imposto a um ataque distribuído em andamento.
     */
    public function success(string $realm, string $account, string $ip): void
    {
        [$acct, , $ipb] = $this->buckets($realm, $account, $ip);
        $this->db->prepare("DELETE FROM login_attempts WHERE bucket_key = :k")->execute(['k' => $acct[0]]);
        $this->db->prepare("UPDATE login_attempts
            SET locked_until = CASE WHEN failures - 1 < CAST(:lim AS SIGNED) THEN NULL ELSE locked_until END,
                failures = failures - 1
            WHERE bucket_key = :k AND failures > 0")->execute(['k' => $ipb[0], 'lim' => $ipb[2]]);
        $this->markTrustedIp($realm, $account, $ip);
    }

    private function trustKey(string $realm, string $account, string $ip): string
    {
        return hash('sha256', 'trust|' . $realm . '|' . self::normalizeAccount($account) . '|' . $ip);
    }

    private function isTrustedIp(string $realm, string $account, string $ip, int $now): bool
    {
        $st = $this->db->prepare("SELECT 1 FROM login_attempts WHERE bucket_key = :k AND scope = 'trusted_ip' AND locked_until > :n");
        $st->execute(['k' => $this->trustKey($realm, $account, $ip), 'n' => gmdate('Y-m-d H:i:s', $now)]);
        return (bool)$st->fetchColumn();
    }

    private function markTrustedIp(string $realm, string $account, string $ip): void
    {
        $now = ($this->clock)();
        $k = $this->trustKey($realm, $account, $ip);
        $nowStr = gmdate('Y-m-d H:i:s', $now);
        $until = gmdate('Y-m-d H:i:s', $now + self::TRUSTED_IP_DAYS * 86400);
        try {
            $this->db->prepare("INSERT INTO login_attempts (bucket_key, scope, failures, window_started_at, locked_until, last_attempt_at) VALUES (:k, 'trusted_ip', 0, :n, :u, :n2)")
                ->execute(['k' => $k, 'n' => $nowStr, 'u' => $until, 'n2' => $nowStr]);
        } catch (\PDOException) {
            $this->db->prepare("UPDATE login_attempts SET locked_until = :u, last_attempt_at = :n WHERE bucket_key = :k AND scope = 'trusted_ip'")
                ->execute(['u' => $until, 'n' => $nowStr, 'k' => $k]);
        }
    }

    /** Credencial errada: a tentativa já foi contada; apenas audita (sem segredo). */
    public function failure(string $realm, string $account, string $ip): void
    {
        $this->audit('LOGIN_FAILED', $realm, 'account_ip', $ip);
    }

    private function buckets(string $realm, string $account, string $ip): array
    {
        $acct = self::normalizeAccount($account);
        $max = $this->maxAttempts();
        return [
            [hash('sha256', "acct|{$realm}|{$acct}|{$ip}"), 'account_ip', $max],
            [hash('sha256', "acct|{$realm}|{$acct}"), 'account', $max * self::ACCOUNT_MULTIPLIER],
            [hash('sha256', "ip|{$ip}"), 'ip', $max * self::IP_MULTIPLIER],
        ];
    }

    private function reserve(string $key, string $scope, int $limit, int $now): array
    {
        $nowStr = gmdate('Y-m-d H:i:s', $now);
        $cutoff = gmdate('Y-m-d H:i:s', $now - self::WINDOW_SECONDS);

        try {
            $this->db->prepare("INSERT INTO login_attempts (bucket_key, scope, failures, window_started_at, locked_until, last_attempt_at) VALUES (:k, :s, 0, :n, NULL, :n2)")
                ->execute(['k' => $key, 's' => $scope, 'n' => $nowStr, 'n2' => $nowStr]);
        } catch (\PDOException) {
            // já existe (UNIQUE): segue
        }

        // Janela expirada e sem bloqueio vigente: recomeça a contagem (sem bloqueio permanente).
        $this->db->prepare("UPDATE login_attempts SET failures = 0, locked_until = NULL, window_started_at = :n
            WHERE bucket_key = :k AND window_started_at < :cutoff AND (locked_until IS NULL OR locked_until <= :n2)")
            ->execute(['n' => $nowStr, 'k' => $key, 'cutoff' => $cutoff, 'n2' => $nowStr]);

        $row = $this->db->prepare("SELECT failures, locked_until FROM login_attempts WHERE bucket_key = :k");
        $row->execute(['k' => $key]);
        $cur = $row->fetch(PDO::FETCH_ASSOC) ?: ['failures' => 0, 'locked_until' => null];
        $over = max(0, (int)$cur['failures'] + 1 - $limit);
        $lockSeconds = min(self::MAX_LOCK_SECONDS, self::BASE_LOCK_SECONDS * (2 ** min($over, 10)));
        $lockUntil = gmdate('Y-m-d H:i:s', $now + $lockSeconds);

        // Atômico: locked_until é atribuído ANTES de failures (MySQL avalia SET da esquerda p/ direita;
        // SQLite usa valores antigos) — nos dois casos a expressão enxerga o valor antigo de failures.
        $upd = $this->db->prepare("UPDATE login_attempts
            SET locked_until = CASE WHEN failures + 1 >= CAST(:lim AS SIGNED) THEN :lu ELSE locked_until END,
                failures = failures + 1,
                last_attempt_at = :n
            WHERE bucket_key = :k AND (locked_until IS NULL OR locked_until <= :n2)");
        $upd->execute(['lim' => $limit, 'lu' => $lockUntil, 'n' => $nowStr, 'k' => $key, 'n2' => $nowStr]);

        if ($upd->rowCount() === 0) {
            $row->execute(['k' => $key]);
            $lu = (string)($row->fetchColumn(1) ?: '');
            $retry = $lu !== '' ? max(1, strtotime($lu . ' UTC') - $now) : self::BASE_LOCK_SECONDS;
            return ['allowed' => false, 'retry_after' => $retry];
        }
        return ['allowed' => true, 'retry_after' => 0];
    }

    private function audit(string $action, string $realm, string $scope, string $ip): void
    {
        try {
            $this->db->prepare("INSERT INTO audit_logs (clinic_id, user_id, action, resource, resource_id, ip_address, user_agent, details)
                VALUES (NULL, NULL, :a, 'auth', NULL, :ip, :ua, :d)")
                ->execute([
                    'a' => $action,
                    'ip' => $ip,
                    'ua' => substr((string)($_SERVER['HTTP_USER_AGENT'] ?? 'CLI/Internal'), 0, 255),
                    'd' => json_encode(['realm' => $realm, 'scope' => $scope]),
                ]);
        } catch (\Throwable) {
            // auditoria nunca derruba o login
        }
    }
}
