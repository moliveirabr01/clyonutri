<?php

declare(strict_types=1);

namespace ClyoNutri\Domain\PatientPortal;

use ClyoNutri\Core\Auth\TenantContext;
use ClyoNutri\Core\Database\Connection;
use ClyoNutri\Core\Security\TenantGuard;
use PDO;
use RuntimeException;
use InvalidArgumentException;

class PatientPortalService
{
    private PDO $db;

    /** Relógio fixo opcional (testes); padrão: agora. */
    private ?\DateTimeImmutable $fixedNow;

    public function __construct(?PDO $db = null, ?\DateTimeImmutable $now = null)
    {
        $this->db = $db ?? Connection::getInstance();
        $this->fixedNow = $now;
    }

    // =========================================================================
    // NI2-131: Onboarding, Convite Seguro e Vínculo Profissional
    // =========================================================================

    public function createInvitation(int $patientId, int $professionalId, int $validHours = 48): array
    {
        $clinicId = TenantContext::getClinicId();
        TenantGuard::assertPatientBelongsToClinic($patientId, $clinicId, $this->db);
        TenantGuard::assertProfessionalBelongsToClinic($professionalId, $clinicId, $this->db);

        $token = bin2hex(random_bytes(32));
        $expiresAt = date('Y-m-d H:i:s', time() + ($validHours * 3600));

        $stmt = $this->db->prepare("
            INSERT INTO patient_invitations (clinic_id, patient_id, professional_id, token, expires_at)
            VALUES (:clinic_id, :patient_id, :professional_id, :token, :expires_at)
        ");
        $stmt->execute([
            'clinic_id' => $clinicId,
            'patient_id' => $patientId,
            'professional_id' => $professionalId,
            'token' => $token,
            'expires_at' => $expiresAt,
        ]);

        return [
            'id' => (int)$this->db->lastInsertId(),
            'token' => $token,
            'patient_id' => $patientId,
            'expires_at' => $expiresAt,
        ];
    }

    public function acceptInvitation(string $token, array $preferences = [], array $consents = []): array
    {
        $stmt = $this->db->prepare("
            SELECT * FROM patient_invitations
            WHERE token = :token AND revoked_at IS NULL
            LIMIT 1
        ");
        $stmt->execute(['token' => $token]);
        $invitation = $stmt->fetch();

        if (!$invitation) {
            throw new InvalidArgumentException("Convite inválido ou revogado.");
        }

        if (strtotime($invitation['expires_at']) < time()) {
            throw new RuntimeException("Convite expirado. Solicite um novo link ao seu nutricionista.");
        }

        if (!empty($invitation['accepted_at'])) {
            throw new RuntimeException("Este convite já foi utilizado anteriormente.");
        }

        $clinicId = (int)$invitation['clinic_id'];
        $patientId = (int)$invitation['patient_id'];

        // Salvar preferências nutricionais e alergias na ficha
        if (!empty($preferences)) {
            $notes = json_encode($preferences, JSON_UNESCAPED_UNICODE);
            $upStmt = $this->db->prepare("
                UPDATE patients SET notes = :notes, status = 'active', updated_at = CURRENT_TIMESTAMP
                WHERE id = :id AND clinic_id = :clinic_id
            ");
            $upStmt->execute(['notes' => $notes, 'id' => $patientId, 'clinic_id' => $clinicId]);
        }

        // Salvar consentimentos LGPD
        foreach ($consents as $type => $granted) {
            $cStmt = $this->db->prepare("
                INSERT INTO patient_consents (clinic_id, patient_id, consent_type, granted, granted_at)
                VALUES (:clinic_id, :patient_id, :type, :granted, CURRENT_TIMESTAMP)
            ");
            $cStmt->execute([
                'clinic_id' => $clinicId,
                'patient_id' => $patientId,
                'type' => $type,
                'granted' => $granted ? 1 : 0,
            ]);
        }

        // Marcar convite como aceito
        $mStmt = $this->db->prepare("
            UPDATE patient_invitations SET accepted_at = CURRENT_TIMESTAMP WHERE id = :id
        ");
        $mStmt->execute(['id' => $invitation['id']]);

        return [
            'success' => true,
            'patient_id' => $patientId,
            'clinic_id' => $clinicId,
            'status' => 'activated',
        ];
    }

    public function revokeInvitation(int $invitationId): bool
    {
        $clinicId = TenantContext::getClinicId();
        $stmt = $this->db->prepare("
            UPDATE patient_invitations SET revoked_at = CURRENT_TIMESTAMP
            WHERE id = :id AND clinic_id = :clinic_id
        ");
        return $stmt->execute(['id' => $invitationId, 'clinic_id' => $clinicId]);
    }

    // =========================================================================
    // NI2-134: Substituições Alimentares e Receitas Vinculadas
    // =========================================================================

    public function getSubstitutionsAndRecipesForPlan(int $patientId): array
    {
        $clinicId = TenantContext::getClinicId();
        TenantGuard::assertPatientBelongsToClinic($patientId, $clinicId, $this->db);

        // Buscar plano publicado vigente
        $pStmt = $this->db->prepare("
            SELECT id, title, version FROM meal_plans
            WHERE patient_id = :patient_id AND clinic_id = :clinic_id AND status = 'published'
            ORDER BY id DESC LIMIT 1
        ");
        $pStmt->execute(['patient_id' => $patientId, 'clinic_id' => $clinicId]);
        $plan = $pStmt->fetch();

        if (!$plan) {
            return ['plan' => null, 'substitutions' => [], 'recipes' => []];
        }

        // Buscar substituições autorizadas
        $sStmt = $this->db->prepare("
            SELECT s.id, s.item_id, s.food_id, s.amount, s.measure_unit, f.name AS food_name,
                   f.energy_kcal, f.protein_g, f.carbohydrate_g, f.lipid_g
            FROM meal_plan_substitutions s
            JOIN foods f ON f.id = s.food_id
            JOIN meal_plan_items i ON i.id = s.item_id
            JOIN meal_plan_meals m ON m.id = i.meal_id
            WHERE m.meal_plan_id = :plan_id
        ");
        $sStmt->execute(['plan_id' => $plan['id']]);
        $substitutions = $sStmt->fetchAll(PDO::FETCH_ASSOC);

        // Buscar receitas clínicas aprovadas do tenant
        $rStmt = $this->db->prepare("
            SELECT r.* FROM recipes r
            WHERE r.clinic_id = :clinic_id
            ORDER BY r.title ASC
        ");
        $rStmt->execute(['clinic_id' => $clinicId]);
        $recipes = $rStmt->fetchAll(PDO::FETCH_ASSOC);

        return [
            'plan_id' => (int)$plan['id'],
            'plan_title' => $plan['title'],
            'version' => (int)$plan['version'],
            'substitutions' => $substitutions,
            'recipes' => $recipes,
        ];
    }

    // =========================================================================
    // NI2-135: Evolução, Metas e Distinção Clínico vs. Autorrelato
    // =========================================================================

    public function recordSelfReportedMetric(int $patientId, string $metricType, float $value, string $unit, ?string $notes = null): array
    {
        $clinicId = TenantContext::getClinicId();
        TenantGuard::assertPatientBelongsToClinic($patientId, $clinicId, $this->db);

        $metricType = trim($metricType);
        $unit = trim($unit);
        if ($metricType === '') {
            throw new \InvalidArgumentException("O tipo de métrica (metric_type) é obrigatório.");
        }
        if ($unit === '') {
            throw new \InvalidArgumentException("A unidade de medida (unit) é obrigatória.");
        }
        if (!is_finite($value) || $value <= 0) {
            throw new \InvalidArgumentException("O valor da métrica deve ser um número positivo e finito.");
        }

        $stmt = $this->db->prepare("
            INSERT INTO patient_self_metrics (
                clinic_id, patient_id, metric_type, value, unit, source, notes, recorded_at
            ) VALUES (
                :clinic_id, :patient_id, :metric_type, :value, :unit, 'patient_self_report', :notes, CURRENT_TIMESTAMP
            )
        ");
        $stmt->execute([
            'clinic_id' => $clinicId,
            'patient_id' => $patientId,
            'metric_type' => $metricType,
            'value' => $value,
            'unit' => $unit,
            'notes' => $notes,
        ]);

        $id = (int)$this->db->lastInsertId();

        return [
            'id' => $id,
            'clinic_id' => $clinicId,
            'patient_id' => $patientId,
            'metric_type' => $metricType,
            'value' => $value,
            'unit' => $unit,
            'source' => 'patient_self_report',
            'notes' => $notes,
            'is_clinical' => false,
            'recorded_at' => date('Y-m-d H:i:s'),
        ];
    }

    /**
     * Alias canônico para recordSelfReportedMetric
     */
    public function recordSelfMetric(int $patientId, string $metricType, float $value, string $unit, ?string $notes = null): array
    {
        return $this->recordSelfReportedMetric($patientId, $metricType, $value, $unit, $notes);
    }

    public function getEvolutionTimeline(int $patientId): array
    {
        $clinicId = TenantContext::getClinicId();
        TenantGuard::assertPatientBelongsToClinic($patientId, $clinicId, $this->db);

        // 1. Medições clínicas oficiais do nutricionista
        $cStmt = $this->db->prepare("
            SELECT id, assessed_at AS date, weight_kg, height_cm, bmi, waist_cm, notes,
                   'clinical_official' AS source
            FROM anthropometric_assessments
            WHERE patient_id = :patient_id AND clinic_id = :clinic_id AND is_shared = 1
            ORDER BY assessed_at ASC
        ");
        $cStmt->execute(['patient_id' => $patientId, 'clinic_id' => $clinicId]);
        $clinical = $cStmt->fetchAll(PDO::FETCH_ASSOC);

        // 2. Autorrelatos registrados pelo paciente
        $sStmt = $this->db->prepare("
            SELECT id, recorded_at AS date, metric_type, value, unit, notes,
                   'patient_self_report' AS source
            FROM patient_self_metrics
            WHERE patient_id = :patient_id AND clinic_id = :clinic_id
            ORDER BY recorded_at ASC
        ");
        $sStmt->execute(['patient_id' => $patientId, 'clinic_id' => $clinicId]);
        $selfReports = $sStmt->fetchAll(PDO::FETCH_ASSOC);

        $clinicalCount = count($clinical);
        $selfReportCount = count($selfReports);

        return [
            'patient_id' => $patientId,
            'clinical_records' => $clinical,
            'self_reports' => $selfReports,
            'summary' => [
                'clinical_count' => $clinicalCount,
                'self_report_count' => $selfReportCount,
                'total_entries' => $clinicalCount + $selfReportCount,
                'has_data' => ($clinicalCount + $selfReportCount) > 0,
            ],
        ];
    }

    // =========================================================================
    // NI2-136: Documentos, Exames e Orientações Seguras
    // =========================================================================

    public function uploadDocument(int $patientId, array $fileData, string $uploadedByType, int $uploadedById): array
    {
        $clinicId = TenantContext::getClinicId();
        TenantGuard::assertPatientBelongsToClinic($patientId, $clinicId, $this->db);

        if ((TenantContext::getUser()['role']??'')==='patient' && $uploadedByType!=='patient') throw new \ClyoNutri\Core\Security\TenantSecurityException('Autor do documento não autorizado.');
        if ($uploadedByType === 'patient') {
            if ($uploadedById !== $patientId) throw new \ClyoNutri\Core\Security\TenantSecurityException('Autor do documento não autorizado.');
        } elseif ($uploadedByType === 'professional') {
            TenantGuard::assertProfessionalBelongsToClinic($uploadedById,$clinicId,$this->db);
        } else { throw new \ClyoNutri\Core\Security\TenantSecurityException('Autor do documento não autorizado.'); }

        $allowedMimes = ['application/pdf', 'image/jpeg', 'image/png'];
        if (!in_array($fileData['mime_type'], $allowedMimes, true)) {
            throw new InvalidArgumentException("Tipo de arquivo não permitido. Apenas PDF, JPG e PNG são aceitos.");
        }

        if ($fileData['size_bytes'] > 15 * 1024 * 1024) { // 15MB
            throw new InvalidArgumentException("Arquivo excede o limite máximo permitido de 15MB.");
        }

        $accessToken = bin2hex(random_bytes(24));

        $stmt = $this->db->prepare("
            INSERT INTO patient_documents (
                clinic_id, patient_id, uploaded_by_type, uploaded_by_id, title,
                category, file_path, mime_type, size_bytes, access_token
            ) VALUES (
                :clinic_id, :patient_id, :uploaded_by_type, :uploaded_by_id, :title,
                :category, :file_path, :mime_type, :size_bytes, :access_token
            )
        ");
        $stmt->execute([
            'clinic_id' => $clinicId,
            'patient_id' => $patientId,
            'uploaded_by_type' => $uploadedByType,
            'uploaded_by_id' => $uploadedById,
            'title' => $fileData['title'],
            'category' => $fileData['category'] ?? 'exam',
            'file_path' => $fileData['file_path'],
            'mime_type' => $fileData['mime_type'],
            'size_bytes' => $fileData['size_bytes'],
            'access_token' => $accessToken,
        ]);

        return [
            'id' => (int)$this->db->lastInsertId(),
            'title' => $fileData['title'],
            'access_token' => $accessToken,
            'size_bytes' => $fileData['size_bytes'],
        ];
    }

    public function listDocuments(int $patientId): array
    {
        $clinicId = TenantContext::getClinicId();
        TenantGuard::assertPatientBelongsToClinic($patientId, $clinicId, $this->db);

        $stmt = $this->db->prepare("
            SELECT id, title, category, mime_type, size_bytes, uploaded_by_type, access_token, created_at
            FROM patient_documents
            WHERE patient_id = :patient_id AND clinic_id = :clinic_id AND revoked_at IS NULL
            ORDER BY created_at DESC
        ");
        $stmt->execute(['patient_id' => $patientId, 'clinic_id' => $clinicId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function getDocument(int $patientId, int $documentId): array
    {
        $clinicId = TenantContext::getClinicId();
        TenantGuard::assertPatientBelongsToClinic($patientId, $clinicId, $this->db);

        $stmt = $this->db->prepare("
            SELECT id, clinic_id, patient_id, title, category, file_path, mime_type, size_bytes, access_token, created_at
            FROM patient_documents
            WHERE id = :doc_id
        ");
        $stmt->execute(['doc_id' => $documentId]);
        $doc = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$doc || (int)$doc['clinic_id'] !== $clinicId || (int)$doc['patient_id'] !== $patientId) {
            throw new \ClyoNutri\Core\Security\TenantSecurityException(
                "Acesso negado: o documento ID {$documentId} não pertence ao paciente ou clínica ativa (403 Forbidden).",
                403
            );
        }

        return $doc;
    }

    // =========================================================================
    // NI2-137: Próxima Consulta e Agenda Integrada
    // =========================================================================

    public function getNextAppointment(int $patientId): ?array
    {
        $clinicId = TenantContext::getClinicId();
        TenantGuard::assertPatientBelongsToClinic($patientId, $clinicId, $this->db);

        $stmt = $this->db->prepare("
            SELECT a.*, u.name AS professional_name
            FROM appointments a
            LEFT JOIN professionals p ON p.id = a.professional_id
            LEFT JOIN users u ON u.id = p.user_id
            WHERE a.patient_id = :patient_id AND a.clinic_id = :clinic_id AND a.status IN ('scheduled', 'confirmed')
              AND a.scheduled_at >= :floor
            ORDER BY a.scheduled_at ASC LIMIT 50
        ");
        // v13: consultas passadas não são "próxima consulta". scheduled_at está no fuso da aplicação;
        // a consulta continua válida até início + duração + tolerância (TELECONSULT_JOIN_GRACE_MINUTES).
        $appTz = new \DateTimeZone(date_default_timezone_get());
        $now = ($this->fixedNow ?? new \DateTimeImmutable('now'))->setTimezone($appTz);
        $stmt->execute(['patient_id' => $patientId, 'clinic_id' => $clinicId, 'floor' => $now->modify('-1 day')->format('Y-m-d H:i:s')]);
        $graceRaw = getenv('TELECONSULT_JOIN_GRACE_MINUTES');
        $grace = is_numeric($graceRaw) ? max(0, min(240, (int)$graceRaw)) : 30;
        $row = false;
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $candidate) {
            try {
                $start = new \DateTimeImmutable((string)$candidate['scheduled_at'], $appTz);
            } catch (\Exception $e) {
                continue;
            }
            $end = $start->modify('+' . (max(15, (int)($candidate['duration_minutes'] ?? 60)) + $grace) . ' minutes');
            if ($end >= $now) {
                $row = $candidate;
                break;
            }
        }
        if (!$row) {
            return null;
        }
        // NI2-221: a URL vem do Consultório Online (bilhete assinado e curto para a sala de espera),
        // nunca da tabela legada telehealth_sessions (URLs fictícias/públicas).
        $row['meeting_url'] = null;
        try {
            $ticket = (new \ClyoNutri\Domain\Telehealth\TeleconsultationService($this->db))
                ->issuePortalTicket($patientId, (int)$row['id'], $clinicId);
            if ($ticket !== null) {
                $base = rtrim((string)(getenv('APP_URL') ?: ($_ENV['APP_URL'] ?? '')), '/');
                $row['meeting_url'] = ($base !== '' ? $base : '') . '/teleconsulta/sala-espera/' . $ticket;
            }
        } catch (\Throwable $e) {
            $row['meeting_url'] = null; // sem teleconsulta disponível; nunca um link inventado
        }
        // v11 (NI2-221 F19): estado honesto da modalidade para o app.
        $row['is_online'] = \ClyoNutri\Domain\Telehealth\TeleconsultationService::isOnlineType($row['type'] ?? null);
        $row['video_status'] = !$row['is_online'] ? 'presencial' : ($row['meeting_url'] !== null ? 'sala_disponivel' : 'aguardando_sala');
        return $row;
    }

    public function confirmAppointment(int $patientId, int $appointmentId): bool
    {
        $clinicId = TenantContext::getClinicId();
        TenantGuard::assertPatientBelongsToClinic($patientId, $clinicId, $this->db);
        $appointment=TenantGuard::assertAppointmentBelongsToClinic($appointmentId,$clinicId,$this->db);
        if ((int)$appointment['patient_id']!==$patientId) throw new \ClyoNutri\Core\Security\TenantSecurityException('Consulta não autorizada para este paciente.');

        $stmt = $this->db->prepare("
            UPDATE appointments
            SET status = 'confirmed', updated_at = CURRENT_TIMESTAMP
            WHERE id = :id AND patient_id = :patient_id AND clinic_id = :clinic_id AND status = 'scheduled'
        ");
        $stmt->execute([
            'id' => $appointmentId,
            'patient_id' => $patientId,
            'clinic_id' => $clinicId,
        ]);

        $affected = $stmt->rowCount();
        if ($affected > 0) {
            try {
                $hist = $this->db->prepare("
                    INSERT INTO appointment_status_history (
                        clinic_id, appointment_id, from_status, to_status, notes, created_at
                    ) VALUES (
                        :clinic_id, :app_id, 'scheduled', 'confirmed', 'Confirmado pelo paciente via aplicativo/portal', CURRENT_TIMESTAMP
                    )
                ");
                $hist->execute([
                    'clinic_id' => $clinicId,
                    'app_id' => $appointmentId,
                ]);
            } catch (\Throwable $e) {
                // Silencioso se tabela não existir
            }
            return true;
        }

        return false;
    }

    // =========================================================================
    // NI2-138: Comunicação Segura / Mensagens com Nutricionista
    // =========================================================================

    public function sendMessageToProfessional(int $patientId, int $professionalId, string $message, ?string $attachmentPath = null): array
    {
        $clinicId = TenantContext::getClinicId();
        TenantGuard::assertPatientBelongsToClinic($patientId, $clinicId, $this->db);
        TenantGuard::assertProfessionalBelongsToClinic($professionalId, $clinicId, $this->db);

        // Buscar ou criar conversa
        $cStmt = $this->db->prepare("
            SELECT id FROM chat_conversations
            WHERE clinic_id = :clinic_id AND patient_id = :patient_id AND professional_id = :professional_id
            LIMIT 1
        ");
        $cStmt->execute([
            'clinic_id' => $clinicId,
            'patient_id' => $patientId,
            'professional_id' => $professionalId,
        ]);
        $conv = $cStmt->fetch();

        if (!$conv) {
            $inStmt = $this->db->prepare("
                INSERT INTO chat_conversations (clinic_id, patient_id, professional_id, status)
                VALUES (:clinic_id, :patient_id, :professional_id, 'active')
            ");
            $inStmt->execute([
                'clinic_id' => $clinicId,
                'patient_id' => $patientId,
                'professional_id' => $professionalId,
            ]);
            $convId = (int)$this->db->lastInsertId();
        } else {
            $convId = (int)$conv['id'];
        }

        $mStmt = $this->db->prepare("
            INSERT INTO chat_messages (
                conversation_id, clinic_id, sender_type, sender_id, message, attachment_path
            ) VALUES (
                :conv_id, :clinic_id, 'patient', :patient_id, :message, :attachment_path
            )
        ");
        $mStmt->execute([
            'conv_id' => $convId,
            'clinic_id' => $clinicId,
            'patient_id' => $patientId,
            'message' => trim($message),
            'attachment_path' => $attachmentPath,
        ]);

        return [
            'id' => (int)$this->db->lastInsertId(),
            'conversation_id' => $convId,
            'sender_type' => 'patient',
            'message' => $message,
            'created_at' => date('Y-m-d H:i:s'),
        ];
    }

    // =========================================================================
    // NI2-139: Offline Sync Idempotente e Consistência
    // =========================================================================

    public function syncOfflineBatch(int $patientId, array $operations): array
    {
        $clinicId = TenantContext::getClinicId();
        TenantGuard::assertPatientBelongsToClinic($patientId, $clinicId, $this->db);

        $results = [];

        foreach ($operations as $op) {
            $idempotencyKey = $op['idempotency_key'];
            $action = $op['action'];
            $payload = $op['payload'];

            // Verificar idempotência
            $chk = $this->db->prepare("
                SELECT id, status FROM offline_sync_queue
                WHERE idempotency_key = :key AND clinic_id = :clinic_id
            ");
            $chk->execute(['key' => $idempotencyKey, 'clinic_id' => $clinicId]);
            $existing = $chk->fetch();

            if ($existing) {
                $results[] = [
                    'idempotency_key' => $idempotencyKey,
                    'status' => 'already_processed',
                    'action' => $action,
                ];
                continue;
            }

            // Executar ação de acordo com a operação
            if ($action === 'log_meal') {
                $stmt = $this->db->prepare("
                    INSERT INTO food_diary_entries (
                        clinic_id, patient_id, meal_name, consumed_at, description
                    ) VALUES (
                        :clinic_id, :patient_id, :meal_name, :consumed_at, :description
                    )
                ");
                $stmt->execute([
                    'clinic_id' => $clinicId,
                    'patient_id' => $patientId,
                    'meal_name' => $payload['meal_name'],
                    'consumed_at' => $payload['consumed_at'] ?? date('Y-m-d H:i:s'),
                    'description' => $payload['description'] ?? null,
                ]);
            } elseif ($action === 'log_water') {
                $stmt = $this->db->prepare("
                    INSERT INTO hydration_logs (clinic_id, patient_id, amount_ml, logged_at)
                    VALUES (:clinic_id, :patient_id, :amount_ml, CURRENT_TIMESTAMP)
                ");
                $stmt->execute([
                    'clinic_id' => $clinicId,
                    'patient_id' => $patientId,
                    'amount_ml' => (int)$payload['amount_ml'],
                ]);
            }

            // Registrar na fila de idempotência
            $insQ = $this->db->prepare("
                INSERT INTO offline_sync_queue (clinic_id, patient_id, idempotency_key, action, payload, status)
                VALUES (:clinic_id, :patient_id, :key, :action, :payload, 'processed')
            ");
            $insQ->execute([
                'clinic_id' => $clinicId,
                'patient_id' => $patientId,
                'key' => $idempotencyKey,
                'action' => $action,
                'payload' => json_encode($payload),
            ]);

            $results[] = [
                'idempotency_key' => $idempotencyKey,
                'status' => 'processed',
                'action' => $action,
            ];
        }

        return [
            'synced_count' => count($results),
            'items' => $results,
        ];
    }

    /**
     * Retorna a meta hídrica prescrita para o paciente na anamnese clínica vigente (PX-20 / NI2-313).
     *
     * Regras clínicas e de integridade:
     * - Fonte única da verdade: anamnese clínica mais recente do paciente no tenant.
     * - "Ausência é ausência": se nenhuma anamnese existe ou o campo não foi preenchido,
     *   retorna estritamente null (sem valores fictícios como 2500ml).
     * - Prioriza anamneses finalizadas sobre rascunhos.
     * - Isolamento multi-tenant estrito via TenantGuard.
     *
     * @param int $patientId
     * @return int|null Meta prescrita em ml ou null se ausente/não definida
     */
    public function getPrescribedWaterGoal(int $patientId): ?int
    {
        $clinicId = TenantContext::getClinicId();
        TenantGuard::assertPatientBelongsToClinic($patientId, $clinicId, $this->db);

        $stmt = $this->db->prepare("
            SELECT water_intake_ml
            FROM anamneses
            WHERE clinic_id = :clinic_id AND patient_id = :patient_id
            ORDER BY COALESCE(is_draft, 0) ASC, version DESC, id DESC
            LIMIT 1
        ");
        $stmt->execute([
            'clinic_id' => $clinicId,
            'patient_id' => $patientId,
        ]);

        $val = $stmt->fetchColumn();
        if ($val === false || $val === null || trim((string)$val) === '') {
            return null;
        }

        $intVal = (int)$val;
        return $intVal > 0 ? $intVal : null;
    }
}

