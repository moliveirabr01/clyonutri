<?php

declare(strict_types=1);

namespace ClyoNutri\Core\Audit;

use ClyoNutri\Core\Auth\TenantContext;
use ClyoNutri\Core\Database\Connection;
use ClyoNutri\Domain\Observability\ProductionEvent;
use ClyoNutri\Domain\Observability\ProductionEventService;
use PDO;

class AuditLogger
{
    private PDO $db;

    public function __construct(?PDO $db = null)
    {
        $this->db = $db ?? Connection::getInstance();
    }

    public function log(string $action, string $resource, ?string $resourceId = null, ?array $details = null): void
    {
        $clinicId = TenantContext::hasClinic() ? TenantContext::getClinicId() : null;
        $user = TenantContext::getUser();
        $userId = ($user['role']??'') === 'patient' ? null : ($user['id'] ?? null);

        $ip = str_starts_with($action,'SECURITY_') ? null : ($_SERVER['REMOTE_ADDR'] ?? '127.0.0.1');
        $ua = str_starts_with($action, 'SECURITY_') ? 'security-event' : ($_SERVER['HTTP_USER_AGENT'] ?? 'CLI/Internal');

        $stmt = $this->db->prepare("
            INSERT INTO audit_logs (clinic_id, user_id, action, resource, resource_id, ip_address, user_agent, details)
            VALUES (:clinic_id, :user_id, :action, :resource, :resource_id, :ip, :ua, :details)
        ");

        $stmt->execute([
            'clinic_id' => $clinicId,
            'user_id' => $userId,
            'action' => $action,
            'resource' => $resource,
            'resource_id' => $resourceId,
            'ip' => $ip,
            'ua' => substr($ua, 0, 255),
            'details' => $details ? json_encode($details, JSON_UNESCAPED_UNICODE) : null,
        ]);

        // NI2-767: Emissão correlata para a Central de Eventos de Produção (fail-safe)
        try {
            $category = match (true) {
                str_starts_with($action, 'AUTH_') || str_contains($action, 'LOGIN') => ProductionEvent::CATEGORY_AUTH,
                str_starts_with($action, 'SECURITY_') || str_contains($action, 'DENIED') => ProductionEvent::CATEGORY_SECURITY,
                str_starts_with($action, 'ADMIN_') => ProductionEvent::CATEGORY_ADMIN,
                $resource === 'appointments' => ProductionEvent::CATEGORY_SCHEDULING,
                in_array($resource, ['clinical_records', 'anamnesis', 'protocols'], true) => ProductionEvent::CATEGORY_CLINICAL_WORKFLOW,
                in_array($resource, ['meal_plans', 'prescriptions', 'exam_requests'], true) => ProductionEvent::CATEGORY_DOCUMENT,
                in_array($resource, ['subscriptions', 'invoices', 'asaas'], true) => ProductionEvent::CATEGORY_BILLING,
                default => ProductionEvent::CATEGORY_SYSTEM,
            };

            $severity = match (true) {
                str_contains($action, 'CRITICAL') || str_contains($action, 'ATTACK') => ProductionEvent::SEVERITY_CRITICAL,
                str_contains($action, 'DENIED') || str_contains($action, 'FAILED') || str_contains($action, 'ERROR') => ProductionEvent::SEVERITY_WARNING,
                default => ProductionEvent::SEVERITY_INFO,
            };

            $outcome = match (true) {
                str_contains($action, 'DENIED') => ProductionEvent::OUTCOME_DENIED,
                str_contains($action, 'FAILED') || str_contains($action, 'ERROR') => ProductionEvent::OUTCOME_FAILURE,
                default => ProductionEvent::OUTCOME_SUCCESS,
            };

            $correlationId = $_SERVER['HTTP_X_CORRELATION_ID'] ?? ($_SERVER['HTTP_X_REQUEST_ID'] ?? null);
            $event = new ProductionEvent(
                category: $category,
                eventType: $action,
                severity: $severity,
                outcome: $outcome,
                clinicId: $clinicId,
                actorType: $user ? ($user['role'] ?? 'user') : 'system',
                actorId: $userId ? (string)$userId : null,
                resourceType: $resource,
                resourceRef: $resourceId,
                correlationId: $correlationId,
                metadata: $details ?? []
            );

            (new ProductionEventService($this->db))->emit($event);
        } catch (\Throwable) {
            // Emissão de evento operacional é estritamente não-bloqueante
        }
    }
}
