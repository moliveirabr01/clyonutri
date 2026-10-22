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
 * Domínio de notificações (NI2-95).
 *
 * Um evento vira um aviso na caixa do destinatário (notification_events, canal in-app) e uma entrega por canal
 * externo (notification_deliveries: email e push) com status real. Regras:
 * - Catálogo fechado de eventos (EVENTS); textos neutros, sem conteúdo de mensagem e sem dado clínico.
 * - dedupe_key: o mesmo aviso não é criado duas vezes para o mesmo destinatário.
 * - Preferência por categoria e canal; eventos críticos ignoram a preferência e o horário de silêncio.
 * - Horário de silêncio: o chat descarta o e-mail (a mensagem já está no app); os demais adiam para o fim do silêncio.
 * - Eventos imediatos (chat) entregam na hora; os demais ficam na fila drenada pelo cron (drain()).
 * - Falha de e-mail na fila: até MAX_ATTEMPTS tentativas com espera crescente; depois fica failed com motivo.
 * - Push: não há provedor configurado (Expo/FCM). A entrega fica blocked, nunca "enviada".
 */
final class NotificationService
{
    public const RECIPIENT_TYPES = ['patient', 'professional'];
    public const CATEGORIES = [
        'chat' => 'Mensagens do chat',
        'appointments' => 'Lembretes de consulta',
        'plan' => 'Plano alimentar',
    ];
    public const EVENTS = [
        'chat.message_created' => [
            'category' => 'chat', 'critical' => false, 'immediate' => true, 'quiet' => 'skip',
            'title' => 'Nova mensagem no ClyoNutri',
            'body' => 'Você recebeu uma nova mensagem no chat do consultório. Entre no ClyoNutri para ler.',
        ],
        'appointment.reminder' => [
            'category' => 'appointments', 'critical' => false, 'immediate' => false, 'quiet' => 'defer',
            'title' => 'Lembrete de consulta',
            'body' => 'Você tem consulta marcada em {date} às {time}. Abra o ClyoNutri para ver os detalhes.',
        ],
        'meal_plan.published' => [
            'category' => 'plan', 'critical' => false, 'immediate' => false, 'quiet' => 'defer',
            'title' => 'Plano alimentar disponível',
            'body' => 'Seu plano alimentar foi atualizado pelo consultório. Abra o ClyoNutri para ver.',
        ],
    ];
    public const CHANNELS = ['email', 'push'];
    public const MAX_ATTEMPTS = 3;
    private const STALE_SENDING_MINUTES = 30;

    private PDO $db;

    public function __construct(?PDO $db = null, private ?Mailer $mailer = null, private ?\Closure $clock = null)
    {
        $this->db = $db ?? Connection::getInstance();
    }

    public function available(): bool
    {
        return SchemaInfo::hasTable($this->db, 'notification_events') && SchemaInfo::hasTable($this->db, 'notification_deliveries');
    }

    /**
     * Cria o aviso e as entregas. $vars preenche {date}/{time} do texto. $options:
     * - email_skip_reason: o chamador já decidiu não mandar e-mail (ex.: recent_email no chat).
     *
     * @return array{event_id: ?int, duplicate: bool, deliveries: array<string, array{status: string, reason: ?string}>}
     */
    public function publish(int $clinicId, string $eventType, string $recipientType, int $recipientId, string $dedupeKey, ?string $link = null, array $vars = [], array $options = []): array
    {
        $def = self::EVENTS[$eventType] ?? throw new \InvalidArgumentException('Evento de notificação desconhecido.');
        self::assertRecipientType($recipientType);
        if (!$this->available()) {
            return ['event_id' => null, 'duplicate' => false, 'deliveries' => []];
        }
        $dedupeKey = substr($dedupeKey, 0, 120);
        $existing = $this->findEventId($clinicId, $recipientType, $recipientId, $dedupeKey);
        if ($existing !== null) {
            return ['event_id' => $existing, 'duplicate' => true, 'deliveries' => []];
        }
        $body = strtr($def['body'], array_map('strval', array_combine(array_map(fn($k) => '{' . $k . '}', array_keys($vars)), array_values($vars)) ?: []));
        $now = $this->now();
        try {
            $this->db->prepare('INSERT INTO notification_events (clinic_id, event_type, category, recipient_type, recipient_id, title, body, link, critical, dedupe_key, created_at)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)')
                ->execute([$clinicId, $eventType, $def['category'], $recipientType, $recipientId, $def['title'], $body, $link, $def['critical'] ? 1 : 0, $dedupeKey, $now]);
        } catch (\PDOException $e) {
            // Corrida com outra requisição: o índice único decide; quem perdeu devolve o aviso existente.
            $existing = $this->findEventId($clinicId, $recipientType, $recipientId, $dedupeKey);
            if ($existing !== null) {
                return ['event_id' => $existing, 'duplicate' => true, 'deliveries' => []];
            }
            throw $e;
        }
        $eventId = (int)$this->db->lastInsertId();
        $deliveries = [];
        foreach (self::CHANNELS as $channel) {
            $this->db->prepare("INSERT INTO notification_deliveries (clinic_id, event_id, channel, status, attempts, next_attempt_at, created_at, updated_at)
                VALUES (?, ?, ?, 'queued', 0, ?, ?, ?)")->execute([$clinicId, $eventId, $channel, $now, $now, $now]);
            $deliveryId = (int)$this->db->lastInsertId();
            if ($channel === 'email' && !empty($options['email_skip_reason'])) {
                $deliveries[$channel] = $this->finish($deliveryId, 'skipped', (string)$options['email_skip_reason'], 0);
            } elseif ($def['immediate'] && $this->claim($deliveryId)) {
                $deliveries[$channel] = $this->deliver($deliveryId);
            } else {
                $deliveries[$channel] = ['status' => 'queued', 'reason' => null];
            }
        }
        return ['event_id' => $eventId, 'duplicate' => false, 'deliveries' => $deliveries];
    }

    /** Fila: entrega o que venceu. Chamado pelo cron de 15 min. */
    public function drain(int $limit = 200): array
    {
        $report = ['processed' => 0, 'sent' => 0, 'logged' => 0, 'failed' => 0, 'skipped' => 0, 'blocked' => 0, 'requeued' => 0];
        if (!$this->available()) {
            return $report + ['status' => 'NOT_MIGRATED'];
        }
        $now = $this->now();
        // Entregas presas em "sending" (processo morto no meio) voltam para a fila.
        $this->db->prepare("UPDATE notification_deliveries SET status = 'queued', updated_at = ? WHERE status = 'sending' AND updated_at < ?")
            ->execute([$now, date('Y-m-d H:i:s', $this->ts() - self::STALE_SENDING_MINUTES * 60)]);
        $st = $this->db->prepare("SELECT id FROM notification_deliveries WHERE status = 'queued' AND (next_attempt_at IS NULL OR next_attempt_at <= ?) ORDER BY id LIMIT " . max(1, min(1000, $limit)));
        $st->execute([$now]);
        foreach ($st->fetchAll(PDO::FETCH_COLUMN) as $id) {
            if (!$this->claim((int)$id)) {
                continue;
            }
            $r = $this->deliver((int)$id);
            $report['processed']++;
            $report[$r['status'] === 'queued' ? 'requeued' : $r['status']]++;
        }
        $pending = $this->db->query("SELECT COUNT(*) FROM notification_deliveries WHERE status = 'queued'")->fetchColumn();
        return $report + ['pending' => (int)$pending, 'status' => 'OK'];
    }

    // ---------------------------------------------------------------- caixa de avisos

    /** @return array{items: list<array>, unread_count: int, next_before_id: ?int} */
    public function inbox(int $clinicId, string $recipientType, int $recipientId, ?int $beforeId = null, int $limit = 20): array
    {
        self::assertRecipientType($recipientType);
        if (!$this->available()) {
            return ['items' => [], 'unread_count' => 0, 'next_before_id' => null];
        }
        $limit = max(1, min(50, $limit));
        $sql = 'SELECT id, event_type, category, title, body, link, created_at, read_at FROM notification_events
            WHERE clinic_id = ? AND recipient_type = ? AND recipient_id = ?' . ($beforeId ? ' AND id < ?' : '') . ' ORDER BY id DESC LIMIT ' . ($limit + 1);
        $st = $this->db->prepare($sql);
        $st->execute(array_merge([$clinicId, $recipientType, $recipientId], $beforeId ? [$beforeId] : []));
        $rows = $st->fetchAll(PDO::FETCH_ASSOC);
        $more = count($rows) > $limit;
        $rows = array_slice($rows, 0, $limit);
        $items = array_map(static fn(array $r) => [
            'id' => (int)$r['id'],
            'event_type' => $r['event_type'],
            'category' => $r['category'],
            'title' => $r['title'],
            'body' => $r['body'],
            'link' => $r['link'],
            'created_at' => $r['created_at'],
            'read' => $r['read_at'] !== null,
        ], $rows);
        return [
            'items' => $items,
            'unread_count' => $this->unreadCount($clinicId, $recipientType, $recipientId),
            'next_before_id' => $more && $items ? $items[count($items) - 1]['id'] : null,
        ];
    }

    public function unreadCount(int $clinicId, string $recipientType, int $recipientId): int
    {
        if (!$this->available()) {
            return 0;
        }
        $st = $this->db->prepare('SELECT COUNT(*) FROM notification_events WHERE clinic_id = ? AND recipient_type = ? AND recipient_id = ? AND read_at IS NULL');
        $st->execute([$clinicId, $recipientType, $recipientId]);
        return (int)$st->fetchColumn();
    }

    /** Marca um aviso como lido. Aviso de outra pessoa ou de outra clínica = não encontrado (sem revelar que existe). */
    public function markRead(int $clinicId, string $recipientType, int $recipientId, int $eventId): bool
    {
        $st = $this->db->prepare('SELECT read_at FROM notification_events WHERE id = ? AND clinic_id = ? AND recipient_type = ? AND recipient_id = ?');
        $st->execute([$eventId, $clinicId, $recipientType, $recipientId]);
        $row = $st->fetch(PDO::FETCH_ASSOC);
        if (!$row) {
            return false;
        }
        if ($row['read_at'] === null) {
            $this->db->prepare('UPDATE notification_events SET read_at = ? WHERE id = ? AND read_at IS NULL')->execute([$this->now(), $eventId]);
        }
        return true;
    }

    public function markAllRead(int $clinicId, string $recipientType, int $recipientId): int
    {
        if (!$this->available()) {
            return 0;
        }
        $st = $this->db->prepare('UPDATE notification_events SET read_at = ? WHERE clinic_id = ? AND recipient_type = ? AND recipient_id = ? AND read_at IS NULL');
        $st->execute([$this->now(), $clinicId, $recipientType, $recipientId]);
        return $st->rowCount();
    }

    // ---------------------------------------------------------------- preferências

    /**
     * Preferências efetivas. Sem linha própria, vale o padrão (tudo ligado, sem silêncio); para o chat e o silêncio,
     * vale o que já estiver em chat_preferences (NI2-138), para não mudar o comportamento de quem já configurou.
     */
    public function preferences(int $clinicId, string $recipientType, int $recipientId): array
    {
        self::assertRecipientType($recipientType);
        $legacy = $this->legacyChatPreferences($clinicId, $recipientType, $recipientId);
        $categories = [];
        foreach (self::CATEGORIES as $cat => $label) {
            $categories[$cat] = ['category' => $cat, 'label' => $label, 'email_enabled' => true, 'push_enabled' => true];
            if ($cat === 'chat' && $legacy) {
                $categories[$cat]['email_enabled'] = $legacy['email_enabled'];
                $categories[$cat]['push_enabled'] = $legacy['push_enabled'];
            }
        }
        $quiet = $legacy ? $legacy['quiet'] : ['enabled' => false, 'start' => '22:00', 'end' => '07:00'];
        if (SchemaInfo::hasTable($this->db, 'notification_preferences')) {
            $st = $this->db->prepare('SELECT category, email_enabled, push_enabled FROM notification_preferences WHERE clinic_id = ? AND recipient_type = ? AND recipient_id = ?');
            $st->execute([$clinicId, $recipientType, $recipientId]);
            foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $row) {
                if (isset($categories[$row['category']])) {
                    $categories[$row['category']]['email_enabled'] = (bool)$row['email_enabled'];
                    $categories[$row['category']]['push_enabled'] = (bool)$row['push_enabled'];
                }
            }
        }
        if (SchemaInfo::hasTable($this->db, 'notification_quiet_hours')) {
            $st = $this->db->prepare('SELECT enabled, start_time, end_time FROM notification_quiet_hours WHERE clinic_id = ? AND recipient_type = ? AND recipient_id = ?');
            $st->execute([$clinicId, $recipientType, $recipientId]);
            if ($row = $st->fetch(PDO::FETCH_ASSOC)) {
                $quiet = ['enabled' => (bool)$row['enabled'], 'start' => (string)$row['start_time'], 'end' => (string)$row['end_time']];
            }
        }
        return [
            'categories' => array_values($categories),
            'quiet_hours' => $quiet,
            'timezone' => date_default_timezone_get(),
            'push_available' => false,
        ];
    }

    /**
     * Atualização parcial e validada. Formato:
     * {"categories": {"chat": {"email_enabled": false}}, "quiet_hours": {"enabled": true, "start": "22:00", "end": "07:00"}}
     * @return array{before: array, after: array}
     */
    public function savePreferences(int $clinicId, string $recipientType, int $recipientId, array $input): array
    {
        if (!SchemaInfo::hasTable($this->db, 'notification_preferences') || !SchemaInfo::hasTable($this->db, 'notification_quiet_hours')) {
            throw new \RuntimeException('As preferências de aviso ainda não estão disponíveis. Tente mais tarde.');
        }
        $before = $this->preferences($clinicId, $recipientType, $recipientId);
        $cats = $input['categories'] ?? [];
        $quiet = $input['quiet_hours'] ?? null;
        if (!is_array($cats) || ($quiet !== null && !is_array($quiet)) || ($cats === [] && $quiet === null)) {
            throw new \InvalidArgumentException('Envie as preferências de aviso que deseja alterar.');
        }
        $current = [];
        foreach ($before['categories'] as $c) {
            $current[$c['category']] = $c;
        }
        $writes = [];
        foreach ($cats as $cat => $vals) {
            if (!is_string($cat) || !isset(self::CATEGORIES[$cat]) || !is_array($vals)) {
                throw new \InvalidArgumentException('Tipo de aviso inválido.');
            }
            $row = ['email_enabled' => $current[$cat]['email_enabled'], 'push_enabled' => $current[$cat]['push_enabled']];
            foreach ($vals as $k => $v) {
                if (!in_array($k, ['email_enabled', 'push_enabled'], true) || !is_bool($v)) {
                    throw new \InvalidArgumentException('Use verdadeiro ou falso para ligar ou desligar o aviso.');
                }
                $row[$k] = $v;
            }
            $writes[$cat] = $row;
        }
        $q = null;
        if ($quiet !== null) {
            $q = $before['quiet_hours'];
            if (array_key_exists('enabled', $quiet)) {
                if (!is_bool($quiet['enabled'])) {
                    throw new \InvalidArgumentException('Use verdadeiro ou falso para o horário de silêncio.');
                }
                $q['enabled'] = $quiet['enabled'];
            }
            foreach (['start', 'end'] as $k) {
                if (array_key_exists($k, $quiet)) {
                    if (!is_string($quiet[$k]) || !preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $quiet[$k])) {
                        throw new \InvalidArgumentException('Informe o horário no formato HH:MM, por exemplo 22:00.');
                    }
                    $q[$k] = $quiet[$k];
                }
            }
            if ($q['enabled'] && $q['start'] === $q['end']) {
                throw new \InvalidArgumentException('O início e o fim do horário de silêncio precisam ser diferentes.');
            }
        }
        $now = $this->now();
        $this->db->beginTransaction();
        try {
            foreach ($writes as $cat => $row) {
                $upd = $this->db->prepare('UPDATE notification_preferences SET email_enabled = ?, push_enabled = ?, updated_at = ? WHERE clinic_id = ? AND recipient_type = ? AND recipient_id = ? AND category = ?');
                $upd->execute([$row['email_enabled'] ? 1 : 0, $row['push_enabled'] ? 1 : 0, $now, $clinicId, $recipientType, $recipientId, $cat]);
                if ($upd->rowCount() === 0 && !$this->prefRowExists($clinicId, $recipientType, $recipientId, $cat)) {
                    $this->db->prepare('INSERT INTO notification_preferences (clinic_id, recipient_type, recipient_id, category, email_enabled, push_enabled, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?)')
                        ->execute([$clinicId, $recipientType, $recipientId, $cat, $row['email_enabled'] ? 1 : 0, $row['push_enabled'] ? 1 : 0, $now]);
                }
            }
            if ($q !== null) {
                $upd = $this->db->prepare('UPDATE notification_quiet_hours SET enabled = ?, start_time = ?, end_time = ?, updated_at = ? WHERE clinic_id = ? AND recipient_type = ? AND recipient_id = ?');
                $upd->execute([$q['enabled'] ? 1 : 0, $q['start'], $q['end'], $now, $clinicId, $recipientType, $recipientId]);
                $exists = $this->db->prepare('SELECT 1 FROM notification_quiet_hours WHERE clinic_id = ? AND recipient_type = ? AND recipient_id = ?');
                $exists->execute([$clinicId, $recipientType, $recipientId]);
                if (!$exists->fetchColumn()) {
                    $this->db->prepare('INSERT INTO notification_quiet_hours (clinic_id, recipient_type, recipient_id, enabled, start_time, end_time, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?)')
                        ->execute([$clinicId, $recipientType, $recipientId, $q['enabled'] ? 1 : 0, $q['start'], $q['end'], $now]);
                }
            }
            $this->db->commit();
        } catch (\Throwable $e) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            throw $e;
        }
        return ['before' => $before, 'after' => $this->preferences($clinicId, $recipientType, $recipientId)];
    }

    /** Hora local está dentro do silêncio? Janela pode virar a meia-noite (22:00–07:00). */
    public static function inQuietHours(array $quiet, string $hhmm): bool
    {
        if (empty($quiet['enabled'])) {
            return false;
        }
        [$start, $end] = [(string)$quiet['start'], (string)$quiet['end']];
        if ($start === $end) {
            return false;
        }
        return $start < $end ? ($hhmm >= $start && $hhmm < $end) : ($hhmm >= $start || $hhmm < $end);
    }

    // ---------------------------------------------------------------- entrega

    private function claim(int $deliveryId): bool
    {
        $st = $this->db->prepare("UPDATE notification_deliveries SET status = 'sending', updated_at = ? WHERE id = ? AND status = 'queued'");
        $st->execute([$this->now(), $deliveryId]);
        return $st->rowCount() === 1;
    }

    /** @return array{status: string, reason: ?string} */
    private function deliver(int $deliveryId): array
    {
        $st = $this->db->prepare('SELECT d.id, d.channel, d.attempts, e.clinic_id, e.event_type, e.category, e.recipient_type, e.recipient_id, e.title, e.body, e.link, e.critical
            FROM notification_deliveries d JOIN notification_events e ON e.id = d.event_id WHERE d.id = ?');
        $st->execute([$deliveryId]);
        $d = $st->fetch(PDO::FETCH_ASSOC);
        if (!$d) {
            return ['status' => 'failed', 'reason' => 'event_missing'];
        }
        $def = self::EVENTS[$d['event_type']] ?? ['immediate' => false, 'quiet' => 'defer'];
        $attempts = (int)$d['attempts'];
        $critical = (bool)$d['critical'];
        try {
            $prefs = $this->preferences((int)$d['clinic_id'], (string)$d['recipient_type'], (int)$d['recipient_id']);
            $cat = null;
            foreach ($prefs['categories'] as $c) {
                if ($c['category'] === $d['category']) {
                    $cat = $c;
                }
            }
            if ($d['channel'] === 'push') {
                if (!$critical && $cat && !$cat['push_enabled']) {
                    return $this->finish($deliveryId, 'skipped', 'preference_off', $attempts);
                }
                return $this->finish($deliveryId, 'blocked', 'push_provider_not_configured', $attempts);
            }
            if (!$critical && $cat && !$cat['email_enabled']) {
                return $this->finish($deliveryId, 'skipped', 'preference_off', $attempts);
            }
            if (!$critical && self::inQuietHours($prefs['quiet_hours'], date('H:i', $this->ts()))) {
                if ($def['quiet'] === 'skip') {
                    return $this->finish($deliveryId, 'skipped', 'quiet_hours', $attempts);
                }
                $this->db->prepare("UPDATE notification_deliveries SET status = 'queued', reason = 'quiet_hours', next_attempt_at = ?, updated_at = ? WHERE id = ?")
                    ->execute([$this->quietEnd($prefs['quiet_hours']), $this->now(), $deliveryId]);
                return ['status' => 'queued', 'reason' => 'quiet_hours'];
            }
            $address = $this->recipientEmail((int)$d['clinic_id'], (string)$d['recipient_type'], (int)$d['recipient_id']);
            if ($address === null) {
                return $this->finish($deliveryId, 'skipped', 'no_email', $attempts);
            }
            $describe = MailerFactory::describe();
            if ($this->mailer === null && in_array($describe['driver'], ['null', 'none'], true)) {
                return $this->finish($deliveryId, 'skipped', 'mail_disabled', $attempts);
            }
            $base = rtrim((string)(getenv('APP_URL') ?: ($_ENV['APP_URL'] ?? '')), '/');
            $url = $base !== '' ? $base . ($d['link'] && str_starts_with((string)$d['link'], '/') ? $d['link'] : '/') : null;
            $text = "Olá,\n\n" . $d['body'] . ($url ? "\n\nAcesse: {$url}" : '') . "\n\nPor segurança, os detalhes ficam só no ClyoNutri."
                . "\nVocê pode mudar estes avisos no seu perfil.";
            $html = '<p>Olá,</p><p>' . htmlspecialchars((string)$d['body']) . '</p>'
                . ($url ? '<p><a href="' . htmlspecialchars($url) . '">Abrir o ClyoNutri</a></p>' : '')
                . '<p>Por segurança, os detalhes ficam só no ClyoNutri.<br>Você pode mudar estes avisos no seu perfil.</p>';
            if ($d['event_type'] === 'chat.message_created') {
                $text .= "\n" . \ClyoNutri\Domain\Chat\ChatSettingsService::EMERGENCY_NOTICE;
                $html .= '<p>' . htmlspecialchars(\ClyoNutri\Domain\Chat\ChatSettingsService::EMERGENCY_NOTICE) . '</p>';
            }
            try {
                ($this->mailer ?? MailerFactory::fromEnv())->send(new MailMessage($address, '', (string)$d['title'], $html, $text));
            } catch (\Throwable $e) {
                $reason = str_contains($e->getMessage(), 'não configurado') ? 'mail_not_configured' : 'mail_error';
                $attempts++;
                if (!$def['immediate'] && $reason === 'mail_error' && $attempts < self::MAX_ATTEMPTS) {
                    $this->db->prepare("UPDATE notification_deliveries SET status = 'queued', reason = ?, attempts = ?, next_attempt_at = ?, updated_at = ? WHERE id = ?")
                        ->execute([$reason, $attempts, date('Y-m-d H:i:s', $this->ts() + 900 * $attempts), $this->now(), $deliveryId]);
                    return ['status' => 'queued', 'reason' => $reason];
                }
                return $this->finish($deliveryId, 'failed', $reason, $attempts);
            }
            return $this->finish($deliveryId, $this->mailer === null && !$describe['delivers'] ? 'logged' : 'sent', null, $attempts + 1);
        } catch (\Throwable $e) {
            error_log('NI2-95 entrega de aviso falhou: ' . get_class($e));
            return $this->finish($deliveryId, 'failed', 'internal_error', $attempts + 1);
        }
    }

    private function finish(int $deliveryId, string $status, ?string $reason, int $attempts): array
    {
        $now = $this->now();
        $this->db->prepare('UPDATE notification_deliveries SET status = ?, reason = ?, attempts = ?, sent_at = ?, next_attempt_at = NULL, updated_at = ? WHERE id = ?')
            ->execute([$status, $reason, $attempts, in_array($status, ['sent', 'logged'], true) ? $now : null, $now, $deliveryId]);
        return ['status' => $status, 'reason' => $reason];
    }

    private function quietEnd(array $quiet): string
    {
        $ts = $this->ts();
        $end = strtotime(date('Y-m-d', $ts) . ' ' . $quiet['end'] . ':00');
        if ($end <= $ts) {
            $end = strtotime('+1 day', $end);
        }
        return date('Y-m-d H:i:s', $end);
    }

    private function recipientEmail(int $clinicId, string $type, int $id): ?string
    {
        if ($type === 'patient') {
            $st = $this->db->prepare("SELECT email FROM patients WHERE id = ? AND clinic_id = ? AND status = 'active'");
            $st->execute([$id, $clinicId]);
        } else {
            $st = $this->db->prepare('SELECT u.email FROM professionals pr JOIN users u ON u.id = pr.user_id WHERE pr.id = ?');
            $st->execute([$id]);
        }
        $email = trim((string)($st->fetchColumn() ?: ''));
        return filter_var($email, FILTER_VALIDATE_EMAIL) ? $email : null;
    }

    private function legacyChatPreferences(int $clinicId, string $type, int $id): ?array
    {
        if (!SchemaInfo::hasTable($this->db, 'chat_preferences')) {
            return null;
        }
        $st = $this->db->prepare('SELECT * FROM chat_preferences WHERE clinic_id = ? AND user_type = ? AND user_id = ? ORDER BY id DESC LIMIT 1');
        $st->execute([$clinicId, $type, $id]);
        $row = $st->fetch(PDO::FETCH_ASSOC);
        if (!$row) {
            return null;
        }
        return [
            'email_enabled' => (bool)$row['email_enabled'],
            'push_enabled' => (bool)$row['push_enabled'],
            'quiet' => [
                'enabled' => (bool)$row['quiet_hours_enabled'],
                'start' => substr((string)($row['quiet_hours_start'] ?? '22:00'), 0, 5),
                'end' => substr((string)($row['quiet_hours_end'] ?? '07:00'), 0, 5),
            ],
        ];
    }

    private function prefRowExists(int $clinicId, string $type, int $id, string $cat): bool
    {
        $st = $this->db->prepare('SELECT 1 FROM notification_preferences WHERE clinic_id = ? AND recipient_type = ? AND recipient_id = ? AND category = ?');
        $st->execute([$clinicId, $type, $id, $cat]);
        return (bool)$st->fetchColumn();
    }

    private function findEventId(int $clinicId, string $type, int $id, string $key): ?int
    {
        $st = $this->db->prepare('SELECT id FROM notification_events WHERE clinic_id = ? AND recipient_type = ? AND recipient_id = ? AND dedupe_key = ?');
        $st->execute([$clinicId, $type, $id, $key]);
        $v = $st->fetchColumn();
        return $v === false ? null : (int)$v;
    }

    private static function assertRecipientType(string $type): void
    {
        if (!in_array($type, self::RECIPIENT_TYPES, true)) {
            throw new \InvalidArgumentException('Destinatário de aviso inválido.');
        }
    }

    private function ts(): int
    {
        return $this->clock ? (int)($this->clock)() : time();
    }

    private function now(): string
    {
        return date('Y-m-d H:i:s', $this->ts());
    }
}
