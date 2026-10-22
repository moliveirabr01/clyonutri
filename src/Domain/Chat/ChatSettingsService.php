<?php

declare(strict_types=1);

namespace ClyoNutri\Domain\Chat;

use ClyoNutri\Core\Audit\AuditLogger;
use ClyoNutri\Core\Database\Connection;
use ClyoNutri\Core\Database\SchemaInfo;
use PDO;

/**
 * Regras do chat por clínica (NI2-138): tempo de resposta informado à paciente (sem prazo garantido) e prazo de
 * guarda das mensagens e anexos (padrão 20 anos, o mesmo do prontuário).
 */
final class ChatSettingsService
{
    public const RESPONSE_TIMES = [
        'no mesmo dia útil',
        'em até 1 dia útil',
        'em até 2 dias úteis',
        'em até 3 dias úteis',
        'em até 1 semana',
    ];
    public const DEFAULT_RETENTION_YEARS = 20;
    public const MIN_RETENTION_YEARS = 5;
    public const MAX_RETENTION_YEARS = 50;
    public const EMERGENCY_NOTICE = 'Este chat não é para urgências. Em caso de emergência, ligue 192 (SAMU) ou procure um pronto-atendimento.';

    private PDO $db;

    public function __construct(?PDO $db = null)
    {
        $this->db = $db ?? Connection::getInstance();
    }

    /** @return array{response_time_label: ?string, retention_years: int} */
    public function get(int $clinicId): array
    {
        if (SchemaInfo::hasTable($this->db, 'chat_clinic_settings')) {
            $st = $this->db->prepare('SELECT response_time_label, retention_years FROM chat_clinic_settings WHERE clinic_id = ?');
            $st->execute([$clinicId]);
            if ($row = $st->fetch(PDO::FETCH_ASSOC)) {
                return [
                    'response_time_label' => $row['response_time_label'] !== null && $row['response_time_label'] !== '' ? (string)$row['response_time_label'] : null,
                    'retention_years' => (int)$row['retention_years'],
                ];
            }
        }
        return ['response_time_label' => null, 'retention_years' => self::DEFAULT_RETENTION_YEARS];
    }

    public function update(int $clinicId, ?string $responseTime, $retentionYears, ?int $userId): array
    {
        if (!SchemaInfo::hasTable($this->db, 'chat_clinic_settings')) {
            throw new \InvalidArgumentException('As regras do chat ainda não estão disponíveis neste servidor (migração 48 pendente).');
        }
        $responseTime = $responseTime !== null && trim($responseTime) !== '' ? trim($responseTime) : null;
        if ($responseTime !== null && !in_array($responseTime, self::RESPONSE_TIMES, true)) {
            throw new \InvalidArgumentException('Escolha um tempo de resposta da lista.');
        }
        if (!is_numeric($retentionYears) || (int)$retentionYears != $retentionYears
            || (int)$retentionYears < self::MIN_RETENTION_YEARS || (int)$retentionYears > self::MAX_RETENTION_YEARS) {
            throw new \InvalidArgumentException('Prazo de guarda inválido: use de ' . self::MIN_RETENTION_YEARS . ' a ' . self::MAX_RETENTION_YEARS . ' anos.');
        }
        $before = $this->get($clinicId);
        $now = date('Y-m-d H:i:s');
        $has = $this->db->prepare('SELECT 1 FROM chat_clinic_settings WHERE clinic_id = ?');
        $has->execute([$clinicId]);
        if ($has->fetchColumn()) {
            $this->db->prepare('UPDATE chat_clinic_settings SET response_time_label = ?, retention_years = ?, updated_by_user_id = ?, updated_at = ? WHERE clinic_id = ?')
                ->execute([$responseTime, (int)$retentionYears, $userId, $now, $clinicId]);
        } else {
            $this->db->prepare('INSERT INTO chat_clinic_settings (clinic_id, response_time_label, retention_years, updated_by_user_id, updated_at) VALUES (?, ?, ?, ?, ?)')
                ->execute([$clinicId, $responseTime, (int)$retentionYears, $userId, $now]);
        }
        $after = $this->get($clinicId);
        (new AuditLogger($this->db))->log('CHAT_SETTINGS_UPDATED', 'chat_clinic_settings', (string)$clinicId, ['before' => $before, 'after' => $after]);
        return $after;
    }

    /** Aviso exibido acima do campo de mensagem (app e web). */
    public function notice(int $clinicId): array
    {
        $s = $this->get($clinicId);
        return [
            'response_time' => $s['response_time_label'],
            'response_time_text' => $s['response_time_label'] !== null
                ? 'O consultório costuma responder ' . $s['response_time_label'] . '. Não é um prazo garantido.'
                : 'O consultório responde quando possível. Não há prazo garantido.',
            'emergency_text' => self::EMERGENCY_NOTICE,
        ];
    }
}
