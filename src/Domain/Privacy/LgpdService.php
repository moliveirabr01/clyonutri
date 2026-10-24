<?php

declare(strict_types=1);

namespace ClyoNutri\Domain\Privacy;

use ClyoNutri\Core\Audit\AuditLogger;
use ClyoNutri\Core\Auth\TenantContext;
use ClyoNutri\Core\Database\Connection;
use PDO;

class LgpdService
{
    /** Tabelas exportadas => colunas omitidas (segredos, caminhos internos de arquivo, tokens). */
    public const EXPORTED_TABLES = [
        'clinical_records' => [],
        'nutrition_records' => [],
        'meal_plans' => [],
        'meal_plan_versions' => [],
        'appointments' => [],
        'checkins' => [],
        'receivables' => [],
        'food_diary_entries' => ['photo_path', 'idempotency_key'],
        'hydration_logs' => ['idempotency_key'],
        'skinfold_measurements' => [],
        'patient_self_metrics' => [],
        'health_sync_data' => [],
        'preconsultation_questionnaires' => ['token_hash', 'token_generation', 'token_expires_at', 'failed_attempts', 'locked_until'],
        'recipe_prescriptions' => [],
        'chat_conversations' => [],
        'patient_documents' => ['file_path', 'access_token'],
        'clinical_attachments' => ['storage_path'],
        'teleconsultation_sessions' => ['external_room_id', 'external_room_url', 'external_room_name', 'patient_token_hash', 'professional_token_hash', 'token_generation', 'token_issued_at', 'token_expires_at', 'token_revoked_at', 'invite_generation', 'invite_issued_at_utc', 'invite_revoked_at_utc'],
        'patient_packages' => [],
        'data_subject_requests' => [],
        'patient_goals' => [],
        'patient_self_metric_permissions' => [],
        'appointment_change_requests' => [],
        'patient_professional_links' => [],
    ];

    /** Tabelas com patient_id que NÃO entram no pacote, e por quê (revisar com o jurídico). */
    public const NOT_EXPORTED_TABLES = [
        'patients' => 'exportada à parte, só com campos cadastrais',
        'patient_consents' => 'exportada à parte (consents)',
        'anamneses' => 'exportada à parte (anamneses)',
        'anthropometric_assessments' => 'exportada à parte (assessments)',
        'recipe_patient_favorites' => 'exportada à parte (sem clinic_id na tabela)',
        'chat_messages' => 'exportada à parte, a partir das conversas da paciente',
        'patient_app_invitations' => 'operacional: só hashes de convite, sem dado do titular',
        'clinical_integrity_anchors' => 'operacional: hashes de integridade do prontuário',
        'booking_holds' => 'operacional: reservas temporárias de horário (dados pessoais são apagados na anonimização)',
        'onboarding_progress' => 'dados do profissional (first_patient_id é só referência)',
        'personal_access_tokens' => 'segredo de autenticação',
    ];

    private PDO $db;
    private AuditLogger $auditLogger;

    public function __construct(?PDO $db = null)
    {
        $this->db = $db ?? Connection::getInstance();
        $this->auditLogger = new AuditLogger($this->db);
    }

    /**
     * Exporta todos os dados do titular em JSON estruturado (Portabilidade de dados)
     */
    public function exportPatientData(int $patientId): ?array
    {
        $clinicId = TenantContext::getClinicId();

        $stmt = $this->db->prepare("SELECT * FROM patients WHERE id = :id AND clinic_id = :clinic_id");
        $stmt->execute(['id' => $patientId, 'clinic_id' => $clinicId]);
        $patient = $stmt->fetch();

        if (!$patient) {
            return null;
        }

        // Anamneses
        $aStmt = $this->db->prepare("SELECT * FROM anamneses WHERE patient_id = :id AND clinic_id = :clinic_id");
        $aStmt->execute(['id' => $patientId, 'clinic_id' => $clinicId]);
        $anamneses = $aStmt->fetchAll();

        // Antropometrias
        $antStmt = $this->db->prepare("SELECT * FROM anthropometric_assessments WHERE patient_id = :id AND clinic_id = :clinic_id");
        $antStmt->execute(['id' => $patientId, 'clinic_id' => $clinicId]);
        $assessments = $antStmt->fetchAll();

        // Consentimentos
        $cStmt = $this->db->prepare("SELECT * FROM patient_consents WHERE patient_id = :id AND clinic_id = :clinic_id");
        $cStmt->execute(['id' => $patientId, 'clinic_id' => $clinicId]);
        $consents = $cStmt->fetchAll();

        // Authentication secrets never belong in a portability package.
        $patient = array_intersect_key($patient, array_flip(['id','clinic_id','uuid','name','email','phone','birth_date','gender','status','notes','created_at','updated_at']));
        $related = [];
        // NI2-91: inventário explícito. Toda tabela com patient_id está em EXPORTED_TABLES ou em NOT_EXPORTED_TABLES (com motivo).
        foreach (self::EXPORTED_TABLES as $table => $omit) {
            if (!\ClyoNutri\Core\Database\SchemaInfo::hasTable($this->db, $table)) {
                continue; // migração ainda não aplicada neste servidor
            }
            $stmt = $this->db->prepare("SELECT * FROM $table WHERE clinic_id = ? AND patient_id = ? ORDER BY id");
            $stmt->execute([$clinicId, $patientId]);
            $related[$table] = array_map(fn(array $row) => array_diff_key($row, array_flip($omit)), $stmt->fetchAll(PDO::FETCH_ASSOC));
        }
        // Favoritos não têm clinic_id próprio: o vínculo com a clínica vem do paciente, já conferido acima.
        $fav = $this->db->prepare('SELECT id, recipe_id, created_at FROM recipe_patient_favorites WHERE patient_id = ? ORDER BY id');
        $fav->execute([$patientId]);
        $related['recipe_patient_favorites'] = $fav->fetchAll(PDO::FETCH_ASSOC);
        // Mensagens das conversas da própria paciente.
        $msg = $this->db->prepare('SELECT m.id, m.conversation_id, m.sender_type, m.message, m.attachment_name, m.attachment_mime, m.attachment_size, m.status, m.read_at, m.created_at
            FROM chat_messages m JOIN chat_conversations c ON c.id = m.conversation_id AND c.clinic_id = m.clinic_id
            WHERE c.clinic_id = ? AND c.patient_id = ? ORDER BY m.id');
        $msg->execute([$clinicId, $patientId]);
        $related['chat_messages'] = $msg->fetchAll(PDO::FETCH_ASSOC);
        // NI2-95: avisos recebidos e preferências de aviso (texto neutro; sem dados de entrega interna).
        if (\ClyoNutri\Core\Database\SchemaInfo::hasTable($this->db, 'notification_events')) {
            $ntf = $this->db->prepare("SELECT id, event_type, category, title, body, created_at, read_at FROM notification_events
                WHERE clinic_id = ? AND recipient_type = 'patient' AND recipient_id = ? ORDER BY id");
            $ntf->execute([$clinicId, $patientId]);
            $related['notifications'] = $ntf->fetchAll(PDO::FETCH_ASSOC);
            $related['notification_preferences'] = (new \ClyoNutri\Domain\Notification\NotificationService($this->db))->preferences((int)$clinicId, 'patient', $patientId);
            // Aparelhos cadastrados para o aviso no celular: plataforma e datas (o token em si não sai na exportação).
            $related['push_devices'] = (new \ClyoNutri\Domain\Notification\PushTokenService($this->db))->devicesForExport((int)$clinicId, 'patient', $patientId);
        }
        // Registrar auditoria de exportação
        $this->auditLogger->log('LGPD_DATA_EXPORT', 'patients', (string)$patientId, ['patient_id' => $patientId, 'tables' => array_keys($related)]);

        return [
            'exported_at' => date('c'),
            'patient' => $patient,
            'consents' => $consents,
            'anamneses' => $anamneses,
            'assessments' => $assessments,
        ] + $related;
    }

    /**
     * Anonimiza os dados do paciente mediante solicitação de exclusão (Direito ao Esquecimento LGPD)
     * Preserva dados clínicos descaracterizados para fins legais e estatísticos se necessário
     */
    public function anonymizePatient(int $patientId): bool
    {
        $clinicId = TenantContext::getClinicId();

        $manageTx = !$this->db->inTransaction();
        if ($manageTx) {
            $this->db->beginTransaction();
        }
        try {
            $stmt = $this->db->prepare("
                UPDATE patients
                SET name = 'PACIENTE ANONIMIZADO',
                    email = NULL,
                    phone = NULL,
                    notes = 'Dados anonimizados conforme Art. 18 da LGPD.',
                    status = 'anonymized',
                    updated_at = CURRENT_TIMESTAMP
                WHERE id = :id AND clinic_id = :clinic_id
            ");
            $stmt->execute(['id' => $patientId, 'clinic_id' => $clinicId]);

            // Revogar atomicamente tokens móveis e de acesso do paciente (PX-24 / NI2-317)
            $delTok = $this->db->prepare("
                DELETE FROM personal_access_tokens
                WHERE tokenable_type = 'patient' AND tokenable_id = :id
            ");
            $delTok->execute(['id' => $patientId]);

            // NI2-91: credenciais e convites deixam de existir; dados pessoais de reservas antigas são apagados.
            $this->db->prepare("UPDATE patients SET password_hash = NULL, access_pin = NULL, otp_code = NULL, otp_expires_at = NULL WHERE id = :id AND clinic_id = :clinic_id")
                ->execute(['id' => $patientId, 'clinic_id' => $clinicId]);
            $this->db->prepare("UPDATE patient_app_invitations SET revoked_at = CURRENT_TIMESTAMP WHERE patient_id = :id AND clinic_id = :clinic_id AND accepted_at IS NULL AND revoked_at IS NULL")
                ->execute(['id' => $patientId, 'clinic_id' => $clinicId]);
            $this->db->prepare("UPDATE booking_holds SET patient_name = NULL, patient_email = NULL, patient_phone = NULL, patient_cpf = NULL, patient_notes = NULL WHERE patient_id = :id AND clinic_id = :clinic_id")
                ->execute(['id' => $patientId, 'clinic_id' => $clinicId]);
            $this->db->prepare("UPDATE preconsultation_questionnaires SET revoked_at = CURRENT_TIMESTAMP WHERE patient_id = :id AND clinic_id = :clinic_id AND submitted_at IS NULL AND revoked_at IS NULL")
                ->execute(['id' => $patientId, 'clinic_id' => $clinicId]);

            // NI2-138: o chat fica só para leitura sob guarda legal (vínculos encerrados, conversas arquivadas);
            // mensagens e anexos seguem a política de guarda do chat (ChatRetentionService).
            if (\ClyoNutri\Core\Database\SchemaInfo::hasTable($this->db, 'patient_professional_links')) {
                $this->db->prepare("UPDATE patient_professional_links SET status = 'inactive', ended_at = :now, ended_reason = 'LGPD: cadastro anonimizado', updated_at = :now2 WHERE patient_id = :id AND clinic_id = :clinic_id AND status = 'active'")
                    ->execute(['now' => date('Y-m-d H:i:s'), 'now2' => date('Y-m-d H:i:s'), 'id' => $patientId, 'clinic_id' => $clinicId]);
            }
            $this->db->prepare("UPDATE chat_conversations SET status = 'archived' WHERE patient_id = :id AND clinic_id = :clinic_id")
                ->execute(['id' => $patientId, 'clinic_id' => $clinicId]);

            // NI2-95: avisos e preferências de aviso saem junto (nada de aviso pendente para quem foi anonimizado).
            if (\ClyoNutri\Core\Database\SchemaInfo::hasTable($this->db, 'notification_events')) {
                if (\ClyoNutri\Core\Database\SchemaInfo::hasTable($this->db, 'notification_push_tokens')) {
                    $this->db->prepare("DELETE FROM notification_push_tickets WHERE token_id IN (SELECT id FROM notification_push_tokens WHERE clinic_id = :clinic_id AND recipient_type = 'patient' AND recipient_id = :id)")
                        ->execute(['id' => $patientId, 'clinic_id' => $clinicId]);
                    $this->db->prepare("DELETE FROM notification_push_tickets WHERE delivery_id IN (SELECT d.id FROM notification_deliveries d JOIN notification_events e ON e.id = d.event_id WHERE e.clinic_id = :clinic_id AND e.recipient_type = 'patient' AND e.recipient_id = :id)")
                        ->execute(['id' => $patientId, 'clinic_id' => $clinicId]);
                    $this->db->prepare("DELETE FROM notification_push_tokens WHERE clinic_id = :clinic_id AND recipient_type = 'patient' AND recipient_id = :id")
                        ->execute(['id' => $patientId, 'clinic_id' => $clinicId]);
                }
                $this->db->prepare("DELETE FROM notification_deliveries WHERE event_id IN (SELECT id FROM notification_events WHERE clinic_id = :clinic_id AND recipient_type = 'patient' AND recipient_id = :id)")
                    ->execute(['id' => $patientId, 'clinic_id' => $clinicId]);
                foreach (['notification_events', 'notification_preferences', 'notification_quiet_hours'] as $nt) {
                    $this->db->prepare("DELETE FROM {$nt} WHERE clinic_id = :clinic_id AND recipient_type = 'patient' AND recipient_id = :id")
                        ->execute(['id' => $patientId, 'clinic_id' => $clinicId]);
                }
                // Avisos de check-in recebido que apontam para esta paciente, na caixa dos profissionais.
                $this->db->prepare("DELETE FROM notification_deliveries WHERE event_id IN (SELECT id FROM notification_events WHERE clinic_id = :clinic_id AND recipient_type = 'professional' AND event_type = 'checkin.submitted' AND link = :link)")
                    ->execute(['clinic_id' => $clinicId, 'link' => '/pacientes/' . $patientId]);
                $this->db->prepare("DELETE FROM notification_events WHERE clinic_id = :clinic_id AND recipient_type = 'professional' AND event_type = 'checkin.submitted' AND link = :link")
                    ->execute(['clinic_id' => $clinicId, 'link' => '/pacientes/' . $patientId]);
                if (\ClyoNutri\Core\Database\SchemaInfo::hasTable($this->db, 'notification_checkin_settings')) {
                    $this->db->prepare('DELETE FROM notification_checkin_settings WHERE clinic_id = :clinic_id AND patient_id = :id')
                        ->execute(['id' => $patientId, 'clinic_id' => $clinicId]);
                }
            }

            // Registrar revogação de consentimentos
            $revStmt = $this->db->prepare("
                UPDATE patient_consents
                SET granted = 0
                WHERE patient_id = :id AND clinic_id = :clinic_id
            ");
            $revStmt->execute(['id' => $patientId, 'clinic_id' => $clinicId]);

            $this->auditLogger->log('LGPD_ANONYMIZATION', 'patients', (string)$patientId);

            if ($manageTx) {
                $this->db->commit();
            }
            return true;
        } catch (\Throwable $e) {
            if ($manageTx && $this->db->inTransaction()) {
                $this->db->rollBack();
            }
            throw $e;
        }
    }
}
