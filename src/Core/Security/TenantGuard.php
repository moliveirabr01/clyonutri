<?php

declare(strict_types=1);

namespace ClyoNutri\Core\Security;

use ClyoNutri\Core\Audit\AuditLogger;
use PDO;

/**
 * Barreira de Segurança e Validador de Posse de Recursos Multi-Tenant.
 * Garante que nenhuma operação de leitura ou escrita acesse recursos de outro tenant.
 */
class TenantGuard
{
    /**
     * Valida que o paciente pertence à clínica ativa.
     *
     * @throws TenantSecurityException Se o paciente não existir ou pertencer a outra clínica
     */
    public static function assertPatientBelongsToClinic(int $patientId, int $clinicId, PDO $db): array
    {
        $stmt = $db->prepare("SELECT id, clinic_id, name, email FROM patients WHERE id = :id");
        $stmt->execute(['id' => $patientId]);
        $patient = $stmt->fetch();

        if (!$patient || (int)$patient['clinic_id'] !== $clinicId) {
            self::logSecurityViolation($db, $clinicId, 'patients', $patientId, 'Tentativa de acesso a paciente de outro tenant');
            throw new TenantSecurityException("Acesso negado: o paciente ID {$patientId} não pertence à clínica ativa (403 Forbidden).", 403);
        }

        return $patient;
    }

    /**
     * Valida que o profissional pertence à clínica ativa.
     *
     * @throws TenantSecurityException Se o profissional não pertencer à clínica
     */
    public static function assertProfessionalBelongsToClinic(int $professionalId, int $clinicId, PDO $db): array
    {
        // 1. Tentar casar por p.id (ID do profissional) vinculado a usuário membro da clínica
        $stmt = $db->prepare("
            SELECT p.id, cm.clinic_id, u.name 
            FROM professionals p
            JOIN users u ON u.id = p.user_id
            JOIN clinic_members cm ON cm.user_id = p.user_id
            WHERE p.id = :prof_id AND cm.clinic_id = :clinic_id
        ");
        $stmt->execute(['prof_id' => $professionalId, 'clinic_id' => $clinicId]);
        $professional = $stmt->fetch();

        // 2. Se não encontrado, tentar casar por p.user_id (se o chamador passou o user_id do membro da clínica)
        if (!$professional) {
            $stmtFallback = $db->prepare("
                SELECT p.id, cm.clinic_id, u.name 
                FROM professionals p
                JOIN users u ON u.id = p.user_id
                JOIN clinic_members cm ON cm.user_id = p.user_id
                WHERE p.user_id = :user_id AND cm.clinic_id = :clinic_id
            ");
            $stmtFallback->execute(['user_id' => $professionalId, 'clinic_id' => $clinicId]);
            $professional = $stmtFallback->fetch();
        }

        if (!$professional) {
            self::logSecurityViolation($db, $clinicId, 'professionals', $professionalId, 'Tentativa de acesso a profissional de outro tenant');
            throw new TenantSecurityException("Acesso negado: o profissional ID {$professionalId} não pertence à clínica ativa (403 Forbidden).", 403);
        }

        return $professional;
    }

    /**
     * Valida que a consulta agendada pertence à clínica ativa.
     */
    public static function assertAppointmentBelongsToClinic(int $appointmentId, int $clinicId, PDO $db): array
    {
        $stmt = $db->prepare("SELECT id, clinic_id, patient_id, professional_id, status FROM appointments WHERE id = :id");
        $stmt->execute(['id' => $appointmentId]);
        $appointment = $stmt->fetch();

        if (!$appointment || (int)$appointment['clinic_id'] !== $clinicId) {
            self::logSecurityViolation($db, $clinicId, 'appointments', $appointmentId, 'Tentativa de acesso a consulta de outro tenant');
            throw new TenantSecurityException("Acesso negado: o agendamento ID {$appointmentId} não pertence à clínica ativa (403 Forbidden).", 403);
        }

        return $appointment;
    }

    /**
     * Valida que o recebível financeiro pertence à clínica ativa.
     */
    public static function assertReceivableBelongsToClinic(int $receivableId, int $clinicId, PDO $db): array
    {
        $stmt = $db->prepare("SELECT id, clinic_id, patient_id, amount_cents, status FROM receivables WHERE id = :id");
        $stmt->execute(['id' => $receivableId]);
        $receivable = $stmt->fetch();

        if (!$receivable || (int)$receivable['clinic_id'] !== $clinicId) {
            self::logSecurityViolation($db, $clinicId, 'receivables', $receivableId, 'Tentativa de acesso a recebível de outro tenant');
            throw new TenantSecurityException("Acesso negado: o recebível ID {$receivableId} não pertence à clínica ativa (403 Forbidden).", 403);
        }

        return $receivable;
    }

    /**
     * Valida que a conversa de chat pertence à clínica ativa.
     */
    public static function assertConversationBelongsToClinic(int $conversationId, int $clinicId, PDO $db): array
    {
        $stmt = $db->prepare("SELECT id, clinic_id, patient_id, professional_id FROM chat_conversations WHERE id = :id");
        $stmt->execute(['id' => $conversationId]);
        $conversation = $stmt->fetch();

        if (!$conversation || (int)$conversation['clinic_id'] !== $clinicId) {
            self::logSecurityViolation($db, $clinicId, 'chat_conversations', $conversationId, 'Tentativa de acesso a conversa de outro tenant');
            throw new TenantSecurityException("Acesso negado: a conversa ID {$conversationId} não pertence à clínica ativa (403 Forbidden).", 403);
        }

        return $conversation;
    }

    /**
     * Valida que o plano alimentar pertence à clínica ativa.
     */
    public static function assertMealPlanBelongsToClinic(int $planId, int $clinicId, PDO $db): array
    {
        $stmt = $db->prepare("SELECT id, clinic_id, patient_id, status, title FROM meal_plans WHERE id = :id");
        $stmt->execute(['id' => $planId]);
        $plan = $stmt->fetch();

        if (!$plan || (int)$plan['clinic_id'] !== $clinicId) {
            self::logSecurityViolation($db, $clinicId, 'meal_plans', $planId, 'Tentativa de acesso a plano alimentar de outro tenant');
            throw new TenantSecurityException("Acesso negado: o plano alimentar ID {$planId} não pertence à clínica ativa (403 Forbidden).", 403);
        }

        return $plan;
    }

    /**
     * Valida que o alimento pertence à clínica ativa ou é global (clinic_id IS NULL).
     */
    public static function assertFoodBelongsToClinicOrGlobal(int $foodId, int $clinicId, PDO $db): array
    {
        $stmt = $db->prepare("SELECT id, clinic_id, name FROM foods WHERE id = :id");
        $stmt->execute(['id' => $foodId]);
        $food = $stmt->fetch();

        if (!$food) {
            throw new TenantSecurityException("Alimento não encontrado (404 Not Found).", 404);
        }

        if ($food['clinic_id'] !== null && (int)$food['clinic_id'] !== $clinicId) {
            self::logSecurityViolation($db, $clinicId, 'foods', $foodId, 'Tentativa de uso de alimento customizado de outro tenant');
            throw new TenantSecurityException("Acesso negado: o alimento ID {$foodId} pertence a outra organização (403 Forbidden).", 403);
        }

        return $food;
    }

    private static function logSecurityViolation(PDO $db, int $clinicId, string $resource, int $resourceId, string $reason): void
    {
        try {
            $logger = new AuditLogger($db);
            // Registra evento de auditoria sem PII
            $logger->log('SECURITY_CROSS_TENANT_VIOLATION_BLOCKED', $resource, (string)$resourceId, [
                'reason' => $reason,
                'target_resource_id' => $resourceId,
                'blocked_at' => date('c'),
            ]);
        } catch (\Throwable $e) {
            // Falha de log não impede bloqueio da requisição
        }
    }
}
