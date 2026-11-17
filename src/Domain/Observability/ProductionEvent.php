<?php

declare(strict_types=1);

namespace ClyoNutri\Domain\Observability;

use InvalidArgumentException;

/**
 * Value Object para Eventos de Produção estruturados do Clyo SaaS (NI2-767 / NI2-769).
 *
 * Imutável, versionado, auditável e com política rigorosa de proteção contra dados clínicos (LGPD/CFP).
 */
final class ProductionEvent
{
    public const SCHEMA_VERSION = 'v1';

    public const CATEGORY_AUTH = 'AUTH';
    public const CATEGORY_SECURITY = 'SECURITY';
    public const CATEGORY_CLINICAL_WORKFLOW = 'CLINICAL_WORKFLOW';
    public const CATEGORY_SCHEDULING = 'SCHEDULING';
    public const CATEGORY_DOCUMENT = 'DOCUMENT';
    public const CATEGORY_BILLING = 'BILLING';
    public const CATEGORY_NOTIFICATION = 'NOTIFICATION';
    public const CATEGORY_SYSTEM = 'SYSTEM';
    public const CATEGORY_ADMIN = 'ADMIN';

    public const CATEGORIES = [
        self::CATEGORY_AUTH,
        self::CATEGORY_SECURITY,
        self::CATEGORY_CLINICAL_WORKFLOW,
        self::CATEGORY_SCHEDULING,
        self::CATEGORY_DOCUMENT,
        self::CATEGORY_BILLING,
        self::CATEGORY_NOTIFICATION,
        self::CATEGORY_SYSTEM,
        self::CATEGORY_ADMIN,
    ];

    public const SEVERITY_INFO = 'info';
    public const SEVERITY_NOTICE = 'notice';
    public const SEVERITY_WARNING = 'warning';
    public const SEVERITY_ERROR = 'error';
    public const SEVERITY_CRITICAL = 'critical';

    public const SEVERITIES = [
        self::SEVERITY_INFO,
        self::SEVERITY_NOTICE,
        self::SEVERITY_WARNING,
        self::SEVERITY_ERROR,
        self::SEVERITY_CRITICAL,
    ];

    public const OUTCOME_SUCCESS = 'success';
    public const OUTCOME_FAILURE = 'failure';
    public const OUTCOME_DENIED = 'denied';
    public const OUTCOME_DEGRADED = 'degraded';

    public const OUTCOMES = [
        self::OUTCOME_SUCCESS,
        self::OUTCOME_FAILURE,
        self::OUTCOME_DENIED,
        self::OUTCOME_DEGRADED,
    ];

    /** Chaves estritamente proibidas em metadados para evitar vazamento de PHI ou credenciais */
    private const FORBIDDEN_KEY_PATTERNS = [
        '/pass(word)?/i',
        '/token/i',
        '/secret/i',
        '/anamnese/i',
        '/conduta/i',
        '/diagnostico/i',
        '/cpf/i',
        '/card(_number)?/i',
        '/cvv/i',
        '/prontuario/i',
        '/cookie/i',
        '/session_id/i',
        '/bearer/i',
        '/auth(orization)?/i',
        '/sintoma/i',
        '/refeicao/i',
        '/medicamento/i',
    ];

    private string $eventId;
    private string $schemaVersion;
    private string $occurredAt;
    private string $environment;
    private string $service;
    private string $category;
    private string $eventType;
    private string $severity;
    private string $outcome;
    private ?int $clinicId;
    private string $actorType;
    private ?string $actorId;
    private ?string $resourceType;
    private ?string $resourceRef;
    private ?string $correlationId;
    private ?string $requestId;
    private ?int $durationMs;
    private int $retryCount;
    private array $metadata;

    public function __construct(
        string $category,
        string $eventType,
        string $severity = self::SEVERITY_INFO,
        string $outcome = self::OUTCOME_SUCCESS,
        ?int $clinicId = null,
        string $actorType = 'system',
        ?string $actorId = null,
        ?string $resourceType = null,
        ?string $resourceRef = null,
        ?string $correlationId = null,
        ?string $requestId = null,
        ?int $durationMs = null,
        int $retryCount = 0,
        array $metadata = [],
        ?string $eventId = null,
        ?string $occurredAt = null,
        string $environment = 'production',
        string $service = 'clyo_core',
        string $schemaVersion = self::SCHEMA_VERSION
    ) {
        if (!in_array($category, self::CATEGORIES, true)) {
            throw new InvalidArgumentException("Categoria de evento inválida: {$category}");
        }
        if (!in_array($severity, self::SEVERITIES, true)) {
            throw new InvalidArgumentException("Severidade inválida: {$severity}");
        }
        if (!in_array($outcome, self::OUTCOMES, true)) {
            throw new InvalidArgumentException("Resultado (outcome) inválido: {$outcome}");
        }

        $this->category = $category;
        $this->eventType = trim($eventType);
        if ($this->eventType === '') {
            throw new InvalidArgumentException("Tipo de evento não pode ser vazio.");
        }

        $this->severity = $severity;
        $this->outcome = $outcome;
        $this->clinicId = $clinicId;
        $this->actorType = $actorType;
        $this->actorId = $actorId ? substr(trim($actorId), 0, 64) : null;
        $this->resourceType = $resourceType ? substr(trim($resourceType), 0, 50) : null;
        $this->resourceRef = $resourceRef ? substr(trim($resourceRef), 0, 64) : null;
        $this->correlationId = $correlationId ? substr(trim($correlationId), 0, 64) : null;
        $this->requestId = $requestId ? substr(trim($requestId), 0, 64) : null;
        $this->durationMs = $durationMs !== null ? max(0, $durationMs) : null;
        $this->retryCount = max(0, $retryCount);

        $this->eventId = $eventId ?: self::generateUuid();
        $this->occurredAt = $occurredAt ?: gmdate('Y-m-d H:i:s');
        $this->environment = $environment;
        $this->service = $service;
        $this->schemaVersion = $schemaVersion;

        // Sanitização compulsória na criação
        $this->metadata = $this->sanitizeMetadata(ProductionEventService::stripClinicalFreeText($metadata, ProductionEventService::clinicalContext($category,$resourceType,$eventType)));
    }

    public static function create(
        string $category,
        string $eventType,
        array $params = []
    ): self {
        return new self(
            $category,
            $eventType,
            $params['severity'] ?? self::SEVERITY_INFO,
            $params['outcome'] ?? self::OUTCOME_SUCCESS,
            isset($params['clinic_id']) ? (int)$params['clinic_id'] : null,
            $params['actor_type'] ?? 'system',
            isset($params['actor_id']) ? (string)$params['actor_id'] : null,
            $params['resource_type'] ?? null,
            $params['resource_ref'] ?? null,
            $params['correlation_id'] ?? null,
            $params['request_id'] ?? null,
            isset($params['duration_ms']) ? (int)$params['duration_ms'] : null,
            isset($params['retry_count']) ? (int)$params['retry_count'] : 0,
            $params['metadata'] ?? []
        );
    }

    public function toArray(): array
    {
        return [
            'event_id' => $this->eventId,
            'schema_version' => $this->schemaVersion,
            'occurred_at' => $this->occurredAt,
            'environment' => $this->environment,
            'service' => $this->service,
            'category' => $this->category,
            'event_type' => $this->eventType,
            'severity' => $this->severity,
            'outcome' => $this->outcome,
            'clinic_id' => $this->clinicId,
            'actor_type' => $this->actorType,
            'actor_id' => $this->actorId,
            'resource_type' => $this->resourceType,
            'resource_ref' => $this->resourceRef,
            'correlation_id' => $this->correlationId,
            'request_id' => $this->requestId,
            'duration_ms' => $this->durationMs,
            'retry_count' => $this->retryCount,
            'metadata' => $this->metadata,
        ];
    }

    public function getEventId(): string { return $this->eventId; }
    public function getSchemaVersion(): string { return $this->schemaVersion; }
    public function getOccurredAt(): string { return $this->occurredAt; }
    public function getEnvironment(): string { return $this->environment; }
    public function getService(): string { return $this->service; }
    public function getCategory(): string { return $this->category; }
    public function getEventType(): string { return $this->eventType; }
    public function getSeverity(): string { return $this->severity; }
    public function getOutcome(): string { return $this->outcome; }
    public function getClinicId(): ?int { return $this->clinicId; }
    public function getActorType(): string { return $this->actorType; }
    public function getActorId(): ?string { return $this->actorId; }
    public function getResourceType(): ?string { return $this->resourceType; }
    public function getResourceRef(): ?string { return $this->resourceRef; }
    public function getCorrelationId(): ?string { return $this->correlationId; }
    public function getRequestId(): ?string { return $this->requestId; }
    public function getDurationMs(): ?int { return $this->durationMs; }
    public function getRetryCount(): int { return $this->retryCount; }
    public function getMetadata(): array { return $this->metadata; }

    /** Sanitiza recursivamente metadados removendo segredos, dados de saúde e tokens */
    public function sanitizeMetadata(array $data): array
    {
        $clean = [];
        foreach ($data as $key => $val) {
            $keyStr = (string)$key;
            $isForbidden = false;
            foreach (self::FORBIDDEN_KEY_PATTERNS as $pattern) {
                if (preg_match($pattern, $keyStr)) {
                    $isForbidden = true;
                    break;
                }
            }
            if ($isForbidden) {
                continue; // Remove chave sensível
            }

            if (is_array($val)) {
                $clean[$keyStr] = $this->sanitizeMetadata($val);
            } elseif (is_string($val)) {
                // Mascara potenciais JWTs ou strings de token longas
                if (str_starts_with($val, 'eyJ') && strlen($val) > 40) {
                    $clean[$keyStr] = '[REDACTED_JWT]';
                } elseif (preg_match('/^[a-f0-9]{32,64}$/i', $val) && stripos($keyStr, 'id') === false && stripos($keyStr, 'hash') === false) {
                    $clean[$keyStr] = '[REDACTED_SECRET]';
                } else {
                    $clean[$keyStr] = substr($val, 0, 500); // Limite por campo
                }
            } elseif (is_scalar($val) || $val === null) {
                $clean[$keyStr] = $val;
            }
        }
        return $clean;
    }

    private static function generateUuid(): string
    {
        $bytes = random_bytes(16);
        $bytes[6] = chr((ord($bytes[6]) & 0x0f) | 0x40);
        $bytes[8] = chr((ord($bytes[8]) & 0x3f) | 0x80);
        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($bytes), 4));
    }
}
