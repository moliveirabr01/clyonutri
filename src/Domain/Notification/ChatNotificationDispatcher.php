<?php

declare(strict_types=1);

namespace ClyoNutri\Domain\Notification;

use ClyoNutri\Core\Database\Connection;
use ClyoNutri\Core\Database\SchemaInfo;
use ClyoNutri\Core\Mail\MailMessage;
use ClyoNutri\Core\Mail\Mailer;
use ClyoNutri\Core\Mail\MailerFactory;
use PDO;

/**
 * Notificações do domínio de engajamento (NI2-12) para o evento chat.message_created (NI2-138).
 *
 * Cada canal tem uma linha em chat_notifications_log com o status REAL:
 * - in_app: a mensagem já está disponível no app/web do destinatário (sent).
 * - email: sent (SMTP entregou ao servidor), logged (driver de log, só desenvolvimento), failed (com motivo) ou
 *   skipped (preferência desligada, horário de silêncio, e-mail recente da mesma conversa, sem e-mail, e-mail desligado).
 * - push: blocked — não há provedor de push configurado (Expo/FCM). Nada é marcado como enviado sem ter sido.
 *
 * Texto neutro: sem nome, sem conteúdo da mensagem e sem dado clínico.
 *
 * NI2-95: com a migration 51 aplicada, a decisão por canal passa pelo NotificationService (preferências por
 * categoria, silêncio, caixa de avisos). Sem ela, vale o caminho antigo abaixo.
 */
final class ChatNotificationDispatcher
{
    public const EVENT = 'chat.message_created';
    public const TITLE = 'Nova mensagem no ClyoNutri';
    public const BODY = 'Você recebeu uma nova mensagem no chat do consultório. Entre no ClyoNutri para ler.';
    private const EMAIL_COOLDOWN_MINUTES = 30;

    private PDO $db;

    public function __construct(?PDO $db = null, private ?Mailer $mailer = null)
    {
        $this->db = $db ?? Connection::getInstance();
    }

    /** @return array<string, array{status: string, reason: ?string}> resultado por canal */
    public function notifyNewMessage(int $clinicId, int $conversationId, int $messageId, string $recipientType, int $recipientId): array
    {
        $result = [];
        try {
            $domain = new NotificationService($this->db, $this->mailer);
            if ($domain->available()) {
                return $this->viaDomain($domain, $clinicId, $conversationId, $messageId, $recipientType, $recipientId);
            }
            // Banco ainda sem a migration 51: segue o caminho do NI2-138.
            $prefs = $this->preferences($clinicId, $recipientType, $recipientId);
            $result['in_app'] = $this->record($clinicId, $conversationId, $messageId, $recipientType, $recipientId, 'in_app', 'sent', null);
            $result['email'] = $this->email($clinicId, $conversationId, $messageId, $recipientType, $recipientId, $prefs);
            $result['push'] = $prefs['push_enabled']
                ? $this->record($clinicId, $conversationId, $messageId, $recipientType, $recipientId, 'push', 'blocked', 'push_provider_not_configured')
                : $this->record($clinicId, $conversationId, $messageId, $recipientType, $recipientId, 'push', 'skipped', 'preference_off');
        } catch (\Throwable $e) {
            // A notificação nunca derruba o envio da mensagem.
            error_log('NI2-138 notificação do chat falhou: ' . $e->getMessage());
        }
        return $result;
    }

    /**
     * NI2-95: o aviso nasce no domínio (caixa de avisos + entregas com status real) e continua espelhado em
     * chat_notifications_log, que é o histórico do chat lido pela tela e pela guarda/anonimização do NI2-138.
     */
    private function viaDomain(NotificationService $domain, int $clinicId, int $conversationId, int $messageId, string $type, int $id): array
    {
        $link = $type === 'professional' ? '/chat/' . $conversationId : '/';
        $out = $domain->publish($clinicId, self::EVENT, $type, $id, 'chat.message:' . $messageId, $link, [],
            $this->recentEmail($conversationId, $type, $id) ? ['email_skip_reason' => 'recent_email'] : []);
        if ($out['duplicate']) {
            return [];
        }
        $result = ['in_app' => $this->record($clinicId, $conversationId, $messageId, $type, $id, 'in_app', 'sent', null)];
        foreach (['email', 'push'] as $channel) {
            $d = $out['deliveries'][$channel] ?? ['status' => 'failed', 'reason' => 'internal_error'];
            $result[$channel] = $this->record($clinicId, $conversationId, $messageId, $type, $id, $channel, $d['status'], $d['reason']);
        }
        return $result;
    }

    private function recentEmail(int $conversationId, string $type, int $id): bool
    {
        $recent = $this->db->prepare("SELECT 1 FROM chat_notifications_log WHERE conversation_id = ? AND recipient_type = ? AND recipient_id = ?
            AND channel = 'email' AND status IN ('sent', 'logged') AND sent_at >= ? LIMIT 1");
        $recent->execute([$conversationId, $type, $id, date('Y-m-d H:i:s', time() - self::EMAIL_COOLDOWN_MINUTES * 60)]);
        return (bool)$recent->fetchColumn();
    }

    private function email(int $clinicId, int $conversationId, int $messageId, string $type, int $id, array $prefs): array
    {
        $rec = fn(string $status, ?string $reason) => $this->record($clinicId, $conversationId, $messageId, $type, $id, 'email', $status, $reason);
        if (!$prefs['email_enabled']) {
            return $rec('skipped', 'preference_off');
        }
        if ($this->inQuietHours($prefs)) {
            return $rec('skipped', 'quiet_hours');
        }
        $recent = $this->db->prepare("SELECT 1 FROM chat_notifications_log WHERE conversation_id = ? AND recipient_type = ? AND recipient_id = ?
            AND channel = 'email' AND status IN ('sent', 'logged') AND sent_at >= ? LIMIT 1");
        $recent->execute([$conversationId, $type, $id, date('Y-m-d H:i:s', time() - self::EMAIL_COOLDOWN_MINUTES * 60)]);
        if ($recent->fetchColumn()) {
            return $rec('skipped', 'recent_email');
        }
        [$address, $name] = $this->recipientEmail($clinicId, $type, $id);
        if ($address === null) {
            return $rec('skipped', 'no_email');
        }
        $describe = MailerFactory::describe();
        if ($this->mailer === null && in_array($describe['driver'], ['null', 'none'], true)) {
            return $rec('skipped', 'mail_disabled');
        }
        $base = rtrim((string)(getenv('APP_URL') ?: ($_ENV['APP_URL'] ?? '')), '/');
        $link = $base !== '' ? ($type === 'professional' ? $base . '/chat/' . $conversationId : $base . '/') : null;
        $text = "Olá,\n\n" . self::BODY . ($link ? "\n\nAcesse: {$link}" : '') . "\n\nPor segurança, o conteúdo da mensagem não vai por e-mail.\n" . \ClyoNutri\Domain\Chat\ChatSettingsService::EMERGENCY_NOTICE;
        $html = '<p>Olá,</p><p>' . htmlspecialchars(self::BODY) . '</p>'
            . ($link ? '<p><a href="' . htmlspecialchars($link) . '">Abrir o ClyoNutri</a></p>' : '')
            . '<p>Por segurança, o conteúdo da mensagem não vai por e-mail.</p><p>' . htmlspecialchars(\ClyoNutri\Domain\Chat\ChatSettingsService::EMERGENCY_NOTICE) . '</p>';
        try {
            $message = new MailMessage($address, $name, self::TITLE, $html, $text);
            ($this->mailer ?? MailerFactory::fromEnv())->send($message);
        } catch (\Throwable $e) {
            return $rec('failed', str_contains($e->getMessage(), 'não configurado') ? 'mail_not_configured' : 'mail_error');
        }
        return $rec($this->mailer === null && !$describe['delivers'] ? 'logged' : 'sent', null);
    }

    /** @return array{0: ?string, 1: string} */
    private function recipientEmail(int $clinicId, string $type, int $id): array
    {
        if ($type === 'patient') {
            $st = $this->db->prepare("SELECT email FROM patients WHERE id = ? AND clinic_id = ? AND status = 'active'");
            $st->execute([$id, $clinicId]);
        } else {
            $st = $this->db->prepare("SELECT u.email FROM professionals pr JOIN users u ON u.id = pr.user_id WHERE pr.id = ?");
            $st->execute([$id]);
        }
        $email = trim((string)($st->fetchColumn() ?: ''));
        return [filter_var($email, FILTER_VALIDATE_EMAIL) ? $email : null, ''];
    }

    private function preferences(int $clinicId, string $type, int $id): array
    {
        $prefs = ['email_enabled' => true, 'push_enabled' => true, 'quiet_hours_enabled' => false, 'quiet_hours_start' => '22:00', 'quiet_hours_end' => '07:00'];
        if (SchemaInfo::hasTable($this->db, 'chat_preferences')) {
            $st = $this->db->prepare('SELECT * FROM chat_preferences WHERE clinic_id = ? AND user_type = ? AND user_id = ? ORDER BY id DESC LIMIT 1');
            $st->execute([$clinicId, $type, $id]);
            if ($row = $st->fetch(PDO::FETCH_ASSOC)) {
                $prefs = [
                    'email_enabled' => (bool)$row['email_enabled'],
                    'push_enabled' => (bool)$row['push_enabled'],
                    'quiet_hours_enabled' => (bool)$row['quiet_hours_enabled'],
                    'quiet_hours_start' => (string)($row['quiet_hours_start'] ?? '22:00'),
                    'quiet_hours_end' => (string)($row['quiet_hours_end'] ?? '07:00'),
                ];
            }
        }
        return $prefs;
    }

    private function inQuietHours(array $prefs): bool
    {
        if (!$prefs['quiet_hours_enabled']) {
            return false;
        }
        $now = date('H:i');
        [$start, $end] = [$prefs['quiet_hours_start'], $prefs['quiet_hours_end']];
        return $start <= $end ? ($now >= $start && $now < $end) : ($now >= $start || $now < $end);
    }

    private function record(int $clinicId, int $conversationId, int $messageId, string $type, int $id, string $channel, string $status, ?string $reason): array
    {
        $hasReason = SchemaInfo::hasColumn($this->db, 'chat_notifications_log', 'failure_reason');
        $this->db->prepare('INSERT INTO chat_notifications_log (clinic_id, conversation_id, message_id, recipient_type, recipient_id, channel, title, body_preview,
                contains_clinical_data, status, sent_at' . ($hasReason ? ', failure_reason' : '') . ')
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, 0, ?, ?' . ($hasReason ? ', ?' : '') . ')')
            ->execute(array_merge([$clinicId, $conversationId, $messageId, $type, $id, $channel, self::TITLE, self::BODY, $status, date('Y-m-d H:i:s')], $hasReason ? [$reason] : []));
        return ['status' => $status, 'reason' => $reason];
    }
}
