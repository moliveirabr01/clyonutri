<?php

declare(strict_types=1);

namespace ClyoNutri\Core\Audit;

use ClyoNutri\Core\Auth\TenantContext;
use ClyoNutri\Core\Database\Connection;
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
    }
}
