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

    /**
     * Autorrelato (NI2-135): só métricas do catálogo habilitadas para a paciente, na unidade e faixa do catálogo.
     * Ver ClyoNutri\Domain\Evolution\PatientEvolutionService.
     */
    public function recordSelfReportedMetric(int $patientId, string $metricType, float|int|string $value, ?string $unit = null, ?string $notes = null): array
    {
        return (new \ClyoNutri\Domain\Evolution\PatientEvolutionService($this->db))->recordSelfMetric($patientId, $metricType, $value, $unit, $notes);
    }

    /**
     * Alias canônico para recordSelfReportedMetric
     */
    public function recordSelfMetric(int $patientId, string $metricType, float|int|string $value, ?string $unit = null, ?string $notes = null): array
    {
        return $this->recordSelfReportedMetric($patientId, $metricType, $value, $unit, $notes);
    }

    /** Linha do tempo da paciente (NI2-135): clínico liberado x autorrelato, séries, metas e filtro de período. */
    public function getEvolutionTimeline(int $patientId, ?string $from = null, ?string $to = null): array
    {
        return (new \ClyoNutri\Domain\Evolution\PatientEvolutionService($this->db))->timeline($patientId, $from, $to);
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

    /**
     * Card da próxima consulta (NI2-137): lista fechada de campos, data com fuso, modalidade, local,
     * link da sala só na janela de entrada e as ações permitidas pela clínica.
     */
    public function getNextAppointment(int $patientId): ?array
    {
        return (new \ClyoNutri\Domain\Appointment\PatientAppointmentService($this->db, $this->fixedNow))->nextAppointment($patientId);
    }

    /**
     * Confirmação pela paciente, pelo motor da agenda (NI2-137). Devolve false quando a consulta não está mais
     * em condição de ser confirmada (a API responde 409 com o motivo); consulta de outra paciente: exceção.
     */
    public function confirmAppointment(int $patientId, int $appointmentId): bool
    {
        $clinicId = TenantContext::getClinicId();
        TenantGuard::assertPatientBelongsToClinic($patientId, $clinicId, $this->db);
        try {
            (new \ClyoNutri\Domain\Appointment\PatientAppointmentService($this->db, $this->fixedNow))->confirm($patientId, $appointmentId);
            return true;
        } catch (\ClyoNutri\Domain\Appointment\PatientAppointmentActionException $e) {
            if ($e->errorCode === 'APPOINTMENT_NOT_FOUND') {
                throw new \ClyoNutri\Core\Security\TenantSecurityException('Consulta não encontrada.');
            }
            return false;
        }
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
     * - Só anamneses finalizadas: rascunho nunca vira meta da paciente (NI2-92).
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
              AND COALESCE(is_draft, 0) = 0
            ORDER BY version DESC, id DESC
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

