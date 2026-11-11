<?php

declare(strict_types=1);

namespace ClyoNutri\Core\Security;

use ClyoNutri\Core\Audit\AuditLogger;
use ClyoNutri\Core\Auth\TenantContext;
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
        $patient = self::findScoped($db, 'patients', $patientId, $clinicId, 'id, clinic_id, name, email'); // CR-NI2-004: id+clinic_id no SQL

        $actor = TenantContext::getUser();
        if (!$patient || (int)$patient['clinic_id'] !== $clinicId || (($actor['role']??'')==='patient' && (int)($actor['id']??0)!==$patientId)) {
            self::logSecurityViolation($db, $clinicId, 'patients', $patientId, 'Tentativa de acesso a paciente de outro tenant');
            throw new TenantSecurityException("Acesso negado: registro não encontrado nesta clínica.", 403); // NI2-24 (M4): sem id nem jargão; o detalhe fica na auditoria
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
        // Validação estrita pelo ID canônico da tabela professionals (p.id)
        // Fallback por user_id removido para impedir ambiguidade de identidade profissional (NI2-209)
        $stmt = $db->prepare("
            SELECT p.id, p.user_id, cm.clinic_id, u.name 
            FROM professionals p
            JOIN users u ON u.id = p.user_id
            JOIN clinic_members cm ON cm.user_id = p.user_id
            JOIN clinics c ON c.id=cm.clinic_id AND c.status='active'
            WHERE p.id = :prof_id AND cm.clinic_id = :clinic_id AND cm.status='active' AND u.status='active' AND cm.role IN ('owner','admin','nutritionist') AND u.role IN ('nutritionist','admin','owner')
        ");
        $stmt->execute(['prof_id' => $professionalId, 'clinic_id' => $clinicId]);
        $professional = $stmt->fetch();

        if (!$professional) {
            self::logSecurityViolation($db, $clinicId, 'professionals', $professionalId, 'Tentativa de acesso a profissional de outro tenant ou ID não-canônico');
            throw new TenantSecurityException("Acesso negado: registro não encontrado nesta clínica.", 403); // NI2-24 (M4): sem id nem jargão; o detalhe fica na auditoria
        }

        return $professional;
    }

    /**
     * Valida que a consulta agendada pertence à clínica ativa.
     */
    public static function assertAppointmentBelongsToClinic(int $appointmentId, int $clinicId, PDO $db): array
    {
        $appointment = self::findScoped($db, 'appointments', $appointmentId, $clinicId, 'id, clinic_id, patient_id, professional_id, status'); // CR-NI2-004: id+clinic_id no SQL

        if (!$appointment || (int)$appointment['clinic_id'] !== $clinicId) {
            self::logSecurityViolation($db, $clinicId, 'appointments', $appointmentId, 'Tentativa de acesso a consulta de outro tenant');
            throw new TenantSecurityException("Acesso negado: registro não encontrado nesta clínica.", 403); // NI2-24 (M4): sem id nem jargão; o detalhe fica na auditoria
        }

        if ((TenantContext::getUser()['role']??'')==='patient') self::assertPatientBelongsToClinic((int)$appointment['patient_id'],$clinicId,$db);
        return $appointment;
    }

    /**
     * Valida que o recebível financeiro pertence à clínica ativa.
     */
    public static function assertReceivableBelongsToClinic(int $receivableId, int $clinicId, PDO $db): array
    {
        $receivable = self::findScoped($db, 'receivables', $receivableId, $clinicId, 'id, clinic_id, patient_id, amount_cents, status'); // CR-NI2-004: id+clinic_id no SQL

        if (!$receivable || (int)$receivable['clinic_id'] !== $clinicId) {
            self::logSecurityViolation($db, $clinicId, 'receivables', $receivableId, 'Tentativa de acesso a recebível de outro tenant');
            throw new TenantSecurityException("Acesso negado: registro não encontrado nesta clínica.", 403); // NI2-24 (M4): sem id nem jargão; o detalhe fica na auditoria
        }

        if ((TenantContext::getUser()['role']??'')==='patient') self::assertPatientBelongsToClinic((int)$receivable['patient_id'],$clinicId,$db);
        return $receivable;
    }

    /**
     * Valida que a conversa de chat pertence à clínica ativa.
     */
    public static function assertConversationBelongsToClinic(int $conversationId, int $clinicId, PDO $db): array
    {
        $conversation = self::findScoped($db, 'chat_conversations', $conversationId, $clinicId, 'id, clinic_id, patient_id, professional_id'); // CR-NI2-004: id+clinic_id no SQL

        if (!$conversation || (int)$conversation['clinic_id'] !== $clinicId) {
            self::logSecurityViolation($db, $clinicId, 'chat_conversations', $conversationId, 'Tentativa de acesso a conversa de outro tenant');
            throw new TenantSecurityException("Acesso negado: registro não encontrado nesta clínica.", 403); // NI2-24 (M4): sem id nem jargão; o detalhe fica na auditoria
        }

        if ((TenantContext::getUser()['role']??'')==='patient') self::assertPatientBelongsToClinic((int)$conversation['patient_id'],$clinicId,$db);
        return $conversation;
    }

    /**
     * Valida que o plano alimentar pertence à clínica ativa.
     */
    public static function assertMealPlanBelongsToClinic(int $planId, int $clinicId, PDO $db): array
    {
        $plan = self::findScoped($db, 'meal_plans', $planId, $clinicId, 'id, clinic_id, patient_id, status, title'); // CR-NI2-004: id+clinic_id no SQL

        if (!$plan || (int)$plan['clinic_id'] !== $clinicId) {
            self::logSecurityViolation($db, $clinicId, 'meal_plans', $planId, 'Tentativa de acesso a plano alimentar de outro tenant');
            throw new TenantSecurityException("Acesso negado: registro não encontrado nesta clínica.", 403); // NI2-24 (M4): sem id nem jargão; o detalhe fica na auditoria
        }

        if ((TenantContext::getUser()['role']??'')==='patient') self::assertPatientBelongsToClinic((int)$plan['patient_id'],$clinicId,$db);
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
            throw new TenantSecurityException("Acesso negado: alimento não disponível nesta clínica.", 403);
        }

        return $food;
    }

    /** v54.46 / CR-NI2-004 (NI2-720): tabelas com clinic_id aceitas pelo helper de busca escopada. */
    public const SCOPED_TABLES = ['patients', 'appointments', 'receivables', 'chat_conversations', 'meal_plans', 'exam_requests', 'anamneses', 'clinical_records', 'anthropometric_assessments'];

    /**
     * v54.46 / CR-NI2-004 (NI2-720): busca por id SEMPRE com clinic_id no WHERE (não "busca e depois compara").
     * Linha de outra clínica nem sai do banco. Tabela e colunas por lista fechada (sem SQL dinâmico do usuário).
     */
    public static function findScoped(PDO $db, string $table, int $id, int $clinicId, string $columns = '*'): ?array
    {
        if (!in_array($table, self::SCOPED_TABLES, true) || !preg_match('/^(\*|[a-z_]+(\s*,\s*[a-z_]+)*)$/', $columns)) {
            throw new \InvalidArgumentException('TenantGuard::findScoped: tabela/colunas fora da lista permitida.');
        }
        if ($id <= 0 || $clinicId <= 0) {
            return null;
        }
        $st = $db->prepare("SELECT {$columns} FROM {$table} WHERE id = :id AND clinic_id = :clinic_id LIMIT 1");
        $st->execute(['id' => $id, 'clinic_id' => $clinicId]);
        $row = $st->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    /** v54.46 / CR-NI2-004: pedido de exame da clínica ativa (escopado no SQL). */
    public static function assertExamRequestBelongsToClinic(int $requestId, int $clinicId, PDO $db): array
    {
        $req = self::findScoped($db, 'exam_requests', $requestId, $clinicId, 'id, clinic_id, patient_id, status');
        if (!$req) {
            self::logSecurityViolation($db, $clinicId, 'exam_requests', $requestId, 'Tentativa de acesso a pedido de exame de outro tenant');
            throw new TenantSecurityException("Acesso negado: registro não encontrado nesta clínica.", 403);
        }
        if ((TenantContext::getUser()['role']??'')==='patient') self::assertPatientBelongsToClinic((int)$req['patient_id'],$clinicId,$db);
        return $req;
    }

    /** NI2-24: o CrossTenantAccessAuditor não registra de novo o que já foi registrado aqui no mesmo pedido. */
    public static bool $violationLogged = false;

    private static function logSecurityViolation(PDO $db, int $clinicId, string $resource, int $resourceId, string $reason): void
    {
        self::$violationLogged = true;
        try {
            $logger = new AuditLogger($db);
            // Registra evento de auditoria sem PII
            $logger->log('SECURITY_CROSS_TENANT_VIOLATION_BLOCKED', $resource, (string)$resourceId, [
                'reason' => $reason,
                'target_resource_id' => $resourceId,
                'blocked_at' => date('c'),
            ]);
        } catch (\Throwable $e) {
            error_log('SECURITY_CROSS_TENANT_VIOLATION_BLOCKED audit persistence unavailable');
            // Falha de log não impede bloqueio da requisição
        }
    }
}
