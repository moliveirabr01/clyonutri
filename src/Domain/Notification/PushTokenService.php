<?php

declare(strict_types=1);

namespace ClyoNutri\Domain\Notification;

use ClyoNutri\Core\Database\Connection;
use ClyoNutri\Core\Database\SchemaInfo;
use PDO;

/**
 * NI2-95: aparelhos que recebem push (token do Expo). Hoje só a paciente pelo app.
 *
 * - Um token é um aparelho: se outra conta entrar no mesmo celular, o token passa para ela (a anterior para de receber).
 * - Sair do app desativa o token (remove()); o Expo dizendo DeviceNotRegistered também (deactivate()).
 * - No máximo MAX_ACTIVE_PER_RECIPIENT aparelhos ativos por pessoa; o mais antigo sai quando passa disso.
 * - O token nunca volta em resposta de API, log ou exportação LGPD (a exportação lista só plataforma e datas).
 */
final class PushTokenService
{
    public const PLATFORMS = ['ios', 'android', 'web', 'unknown'];
    public const MAX_ACTIVE_PER_RECIPIENT = 10;

    private PDO $db;

    public function __construct(?PDO $db = null, private ?\Closure $clock = null)
    {
        $this->db = $db ?? Connection::getInstance();
    }

    public function available(): bool
    {
        return SchemaInfo::hasTable($this->db, 'notification_push_tokens');
    }

    /** @return array{registered: bool, push_enabled: bool} */
    public function register(int $clinicId, string $recipientType, int $recipientId, mixed $token, mixed $platform): array
    {
        if (!$this->available()) {
            throw new \RuntimeException('O aviso no celular ainda não está disponível. Tente mais tarde.');
        }
        if (!in_array($recipientType, NotificationService::RECIPIENT_TYPES, true)) {
            throw new \InvalidArgumentException('Destinatário de aviso inválido.');
        }
        if (!is_string($token) || !ExpoPushClient::validToken(trim($token))) {
            throw new \InvalidArgumentException('Não foi possível ativar o aviso neste aparelho. Feche e abra o app de novo.');
        }
        $token = trim($token);
        $platform = is_string($platform) && in_array(strtolower($platform), self::PLATFORMS, true) ? strtolower($platform) : 'unknown';
        $now = $this->now();
        $this->db->beginTransaction();
        try {
            $st = $this->db->prepare('SELECT id FROM notification_push_tokens WHERE token = ?');
            $st->execute([$token]);
            $id = $st->fetchColumn();
            if ($id !== false) {
                $this->db->prepare('UPDATE notification_push_tokens SET clinic_id = ?, recipient_type = ?, recipient_id = ?, platform = ?, active = 1, last_error = NULL, updated_at = ? WHERE id = ?')
                    ->execute([$clinicId, $recipientType, $recipientId, $platform, $now, (int)$id]);
            } else {
                $this->db->prepare('INSERT INTO notification_push_tokens (clinic_id, recipient_type, recipient_id, token, platform, active, created_at, updated_at) VALUES (?, ?, ?, ?, ?, 1, ?, ?)')
                    ->execute([$clinicId, $recipientType, $recipientId, $token, $platform, $now, $now]);
            }
            $st = $this->db->prepare('SELECT id FROM notification_push_tokens WHERE clinic_id = ? AND recipient_type = ? AND recipient_id = ? AND active = 1 ORDER BY updated_at DESC, id DESC');
            $st->execute([$clinicId, $recipientType, $recipientId]);
            foreach (array_slice($st->fetchAll(PDO::FETCH_COLUMN), self::MAX_ACTIVE_PER_RECIPIENT) as $old) {
                $this->deactivate((int)$old, 'replaced');
            }
            $this->db->commit();
        } catch (\Throwable $e) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            throw $e;
        }
        return ['registered' => true, 'push_enabled' => ExpoPushClient::enabled()];
    }

    /** Sair do app: o token deixa de receber. Token de outra pessoa não é tocado (e a resposta não diz se existe). */
    public function remove(int $clinicId, string $recipientType, int $recipientId, mixed $token): bool
    {
        if (!$this->available() || !is_string($token) || !ExpoPushClient::validToken(trim($token))) {
            return false;
        }
        $st = $this->db->prepare("UPDATE notification_push_tokens SET active = 0, last_error = 'logged_out', updated_at = ?
            WHERE token = ? AND clinic_id = ? AND recipient_type = ? AND recipient_id = ? AND active = 1");
        $st->execute([$this->now(), trim($token), $clinicId, $recipientType, $recipientId]);
        return $st->rowCount() > 0;
    }

    public function deactivate(int $tokenId, string $reason): void
    {
        $this->db->prepare('UPDATE notification_push_tokens SET active = 0, last_error = ?, updated_at = ? WHERE id = ?')
            ->execute([substr($reason, 0, 60), $this->now(), $tokenId]);
    }

    /** @return list<array{id: int, token: string}> */
    public function activeTokens(int $clinicId, string $recipientType, int $recipientId): array
    {
        if (!$this->available()) {
            return [];
        }
        $st = $this->db->prepare('SELECT id, token FROM notification_push_tokens WHERE clinic_id = ? AND recipient_type = ? AND recipient_id = ? AND active = 1 ORDER BY id');
        $st->execute([$clinicId, $recipientType, $recipientId]);
        return array_map(static fn(array $r) => ['id' => (int)$r['id'], 'token' => (string)$r['token']], $st->fetchAll(PDO::FETCH_ASSOC));
    }

    /** Para a exportação LGPD: aparelhos sem o token. */
    public function devicesForExport(int $clinicId, string $recipientType, int $recipientId): array
    {
        if (!$this->available()) {
            return [];
        }
        $st = $this->db->prepare('SELECT platform, active, created_at, updated_at, last_sent_at FROM notification_push_tokens WHERE clinic_id = ? AND recipient_type = ? AND recipient_id = ? ORDER BY id');
        $st->execute([$clinicId, $recipientType, $recipientId]);
        return array_map(static fn(array $r) => ['platform' => $r['platform'], 'active' => (bool)$r['active'], 'created_at' => $r['created_at'], 'updated_at' => $r['updated_at'], 'last_sent_at' => $r['last_sent_at']], $st->fetchAll(PDO::FETCH_ASSOC));
    }

    private function now(): string
    {
        return date('Y-m-d H:i:s', $this->clock ? (int)($this->clock)() : time());
    }
}
