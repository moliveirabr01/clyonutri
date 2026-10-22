<?php

declare(strict_types=1);

namespace ClyoNutri\Domain\Chat;

use ClyoNutri\Core\Audit\AuditLogger;
use ClyoNutri\Core\Database\Connection;
use ClyoNutri\Core\Database\SchemaInfo;
use PDO;

/**
 * Política de guarda do chat (NI2-138, A5). Por clínica, depois do prazo configurado (padrão 20 anos, o mesmo do
 * prontuário): apaga mensagens, anexos (linha e arquivo) e registros de notificação. Também apaga arquivos que
 * ficaram sem registro no banco (envio que falhou, exclusão em cascata) com mais de 24 horas.
 * Roda pelo bin/cron_scheduler.php (uma vez por dia) ou por bin/chat_retention.php (com --dry-run).
 */
final class ChatRetentionService
{
    private PDO $db;
    private string $storageDir;

    public function __construct(?PDO $db = null, ?string $storageDir = null)
    {
        $this->db = $db ?? Connection::getInstance();
        $this->storageDir = $storageDir ?? dirname(__DIR__, 3) . '/storage/app/chat_attachments';
    }

    /** @return array{clinics: array<int, array>, orphan_files: int, dry_run: bool} */
    public function run(bool $dryRun = false, ?\DateTimeImmutable $now = null): array
    {
        $now ??= new \DateTimeImmutable('now');
        $settings = new ChatSettingsService($this->db);
        $report = ['clinics' => [], 'orphan_files' => 0, 'dry_run' => $dryRun];
        $clinicIds = $this->db->query('SELECT DISTINCT clinic_id FROM chat_conversations')->fetchAll(PDO::FETCH_COLUMN);
        foreach ($clinicIds as $clinicId) {
            $clinicId = (int)$clinicId;
            $years = $settings->get($clinicId)['retention_years'];
            $cutoff = $now->modify("-{$years} years")->format('Y-m-d H:i:s');
            $r = ['retention_years' => $years, 'cutoff' => $cutoff, 'messages' => 0, 'attachments' => 0, 'files' => 0, 'notifications' => 0, 'conversations' => 0];

            $att = $this->db->prepare('SELECT a.id, a.storage_path FROM chat_attachments a
                LEFT JOIN chat_messages m ON m.id = a.message_id
                WHERE a.clinic_id = ? AND COALESCE(m.created_at, a.created_at) < ?');
            $att->execute([$clinicId, $cutoff]);
            $attachments = $att->fetchAll(PDO::FETCH_ASSOC);
            $count = static function (PDO $db, string $sql, array $p): int { $s = $db->prepare($sql); $s->execute($p); return (int)$s->fetchColumn(); };
            $r['attachments'] = count($attachments);
            $r['messages'] = $count($this->db, 'SELECT COUNT(*) FROM chat_messages WHERE clinic_id = ? AND created_at < ?', [$clinicId, $cutoff]);
            $r['notifications'] = $count($this->db, 'SELECT COUNT(*) FROM chat_notifications_log WHERE clinic_id = ? AND sent_at < ?', [$clinicId, $cutoff]);

            if (!$dryRun && ($r['attachments'] + $r['messages'] + $r['notifications']) > 0) {
                $this->db->beginTransaction();
                try {
                    $delAtt = $this->db->prepare('DELETE FROM chat_attachments WHERE id = ? AND clinic_id = ?');
                    foreach ($attachments as $a) {
                        $delAtt->execute([(int)$a['id'], $clinicId]);
                    }
                    $this->db->prepare('DELETE FROM chat_notifications_log WHERE clinic_id = ? AND sent_at < ?')->execute([$clinicId, $cutoff]);
                    $this->db->prepare('DELETE FROM chat_messages WHERE clinic_id = ? AND created_at < ?')->execute([$clinicId, $cutoff]);
                    $del = $this->db->prepare('DELETE FROM chat_conversations WHERE clinic_id = ? AND updated_at < ?
                        AND NOT EXISTS (SELECT 1 FROM chat_messages m WHERE m.conversation_id = chat_conversations.id)');
                    $del->execute([$clinicId, $cutoff]);
                    $r['conversations'] = $del->rowCount();
                    $this->db->commit();
                } catch (\Throwable $e) {
                    if ($this->db->inTransaction()) {
                        $this->db->rollBack();
                    }
                    throw $e;
                }
                // Arquivos só depois do commit: se o banco falhar, nada se perde.
                foreach ($attachments as $a) {
                    if ($this->insideStorage((string)$a['storage_path']) && is_file($a['storage_path']) && @unlink($a['storage_path'])) {
                        $r['files']++;
                    }
                }
                (new AuditLogger($this->db))->log('CHAT_RETENTION_PURGED', 'chat_retention', (string)$clinicId, $r);
            }
            if (($r['attachments'] + $r['messages'] + $r['notifications']) > 0) {
                $report['clinics'][$clinicId] = $r;
            }
        }
        $report['orphan_files'] = $this->sweepOrphans($dryRun, $now);
        return $report;
    }

    private function sweepOrphans(bool $dryRun, \DateTimeImmutable $now): int
    {
        if (!is_dir($this->storageDir) || !SchemaInfo::hasTable($this->db, 'chat_attachments')) {
            return 0;
        }
        $known = array_flip($this->db->query('SELECT stored_name FROM chat_attachments')->fetchAll(PDO::FETCH_COLUMN));
        $n = 0;
        foreach (glob($this->storageDir . '/chat_*') ?: [] as $file) {
            if (!is_file($file) || isset($known[basename($file)]) || filemtime($file) > $now->getTimestamp() - 86400) {
                continue;
            }
            if ($dryRun || @unlink($file)) {
                $n++;
            }
        }
        return $n;
    }

    private function insideStorage(string $path): bool
    {
        $real = realpath($path);
        $base = realpath($this->storageDir);
        return $real !== false && $base !== false && str_starts_with($real, $base . DIRECTORY_SEPARATOR);
    }
}
