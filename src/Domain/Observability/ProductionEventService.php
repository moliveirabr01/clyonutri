<?php

declare(strict_types=1);

namespace ClyoNutri\Domain\Observability;

use ClyoNutri\Core\Database\Connection;
use ClyoNutri\Domain\Helpdesk\HelpdeskTicketService;
use PDO;
use Throwable;

/**
 * Serviço de Ingestão, Consulta e Observabilidade da Central de Eventos de Produção (NI2-767).
 *
 * Princípios fundamentais:
 * 1. Fail-Safe: a emissão de evento operacional NUNCA derruba o fluxo clínico principal.
 * 2. Idempotência: deduplicação por event_id.
 * 3. Minimização de Dados: zero dados sensíveis de saúde (LGPD/CFP).
 * 4. Rastreabilidade de Incidentes: correlação via correlation_id e ligação ao Service Desk.
 */
class ProductionEventService
{
    private PDO $db;
    private ?HelpdeskTicketService $helpdesk;

    public function __construct(?PDO $db = null, ?HelpdeskTicketService $helpdesk = null)
    {
        $this->db = $db ?? Connection::getInstance();
        $this->helpdesk = $helpdesk;
    }

    /**
     * NI2-871 (F-12): remove campos de texto livre clínico da telemetria em production_events.metadata,
     * mantendo apenas indicadores booleanos e tamanho do texto.
     */
    public static function stripClinicalFreeText(array $meta, bool $strict = false): array
    {
        $blockedKeys = [
            'allergen_override_reason', 'adequacy_override_reason', 'evolution_summary',
            'notes', 'allergies', 'objectives', 'chief_complaint', 'routine',
            'clinical_history', 'symptoms', 'medications_details', 'pathologies',
            'restrictions', 'food_preferences', 'habits', 'reason',
        ];
        $clean = [];
        foreach ($meta as $k => $v) {
            $normalized=strtolower((string)preg_replace('/(?<!^)[A-Z]/','_$0',(string)$k));
            if (in_array(strtolower((string)$k), $blockedKeys, true) || in_array($normalized, $blockedKeys, true)) {
                if (is_string($v) && $v !== '') {
                    $clean[$k . '_present'] = true;
                    $clean[$k . '_len'] = mb_strlen($v, 'UTF-8');
                }
                continue;
            }
            if ($strict && !preg_match('/^[a-zA-Z0-9_.-]{1,80}$/D',(string)$k)) continue;
            if (is_array($v)) {
                $clean[$k]=self::stripClinicalFreeText($v,$strict);
            } elseif ($strict && is_string($v)) {
                // Clinical events default to numeric/boolean signals; strings require a typed operational field.
                $states=['draft','review','published','superseded','issued','cancelled','completed','in_progress','scheduled','confirmed','no_show','active','inactive','success','failure','denied','saved','signed','not_started'];
                $isState=preg_match('/(?:^|_)(?:status|state)$/D',$normalized) && in_array($v,$states,true);
                $isId=preg_match('/(?:^|_)(?:id|ids)$/D',$normalized) && ctype_digit($v);
                $isHash=preg_match('/(?:^|_)(?:hash|sha256|digest)$/D',$normalized) && preg_match('/^[a-f0-9]{64}$/Di',$v);
                if($isState || $isId || $isHash)$clean[$k]=$v;
                elseif($v!==''){$clean[$k.'_present']=true;$clean[$k.'_len']=mb_strlen($v,'UTF-8');}
            } elseif(is_scalar($v)||$v===null){$clean[$k]=$v;}

        }
        return $clean;
    }

    public static function clinicalContext(string $category, ?string $resource, string $eventType): bool
    {
        return in_array($category,[ProductionEvent::CATEGORY_CLINICAL_WORKFLOW,ProductionEvent::CATEGORY_DOCUMENT],true)
            || in_array($resource,['patients','clinical_records','anamnesis','anamneses','anthropometric_assessments','meal_plans','meal_plan_versions','prescriptions','rx_prescriptions','exam_requests','protocols','protocol_responses'],true)
            || preg_match('/^(?:CLINICAL|ANAMNESIS|ANTHROPOMETRY|MEAL_PLAN|PRESCRIPTION|EXAM|PATIENT|RX)_/i',$eventType)===1;
    }

    /**
     * Emite e persiste um evento operacional de produção.
     * Resiliente: falhas internas de banco são capturadas e não propagadas para o fluxo chamador.
     */
    public function emit(ProductionEvent $event): bool
    {
        try {
            $data = $event->toArray();
            $meta = is_array($data['metadata'] ?? null) ? self::stripClinicalFreeText($data['metadata'],self::clinicalContext((string)$data['category'],$data['resource_type']??null,(string)$data['event_type'])) : [];
            $metaJson = !empty($meta) ? json_encode($meta, JSON_UNESCAPED_UNICODE) : null;

            $stmt = $this->db->prepare("
                INSERT INTO production_events (
                    event_id, schema_version, occurred_at, environment, service,
                    category, event_type, severity, outcome, clinic_id,
                    actor_type, actor_id, resource_type, resource_ref,
                    correlation_id, request_id, duration_ms, retry_count, metadata
                ) VALUES (
                    :event_id, :schema_version, :occurred_at, :environment, :service,
                    :category, :event_type, :severity, :outcome, :clinic_id,
                    :actor_type, :actor_id, :resource_type, :resource_ref,
                    :correlation_id, :request_id, :duration_ms, :retry_count, :metadata
                )
            ");

            $stmt->execute([
                'event_id' => $data['event_id'],
                'schema_version' => $data['schema_version'],
                'occurred_at' => $data['occurred_at'],
                'environment' => $data['environment'],
                'service' => $data['service'],
                'category' => $data['category'],
                'event_type' => $data['event_type'],
                'severity' => $data['severity'],
                'outcome' => $data['outcome'],
                'clinic_id' => $data['clinic_id'],
                'actor_type' => $data['actor_type'],
                'actor_id' => $data['actor_id'],
                'resource_type' => $data['resource_type'],
                'resource_ref' => $data['resource_ref'],
                'correlation_id' => $data['correlation_id'],
                'request_id' => $data['request_id'],
                'duration_ms' => $data['duration_ms'],
                'retry_count' => $data['retry_count'],
                'metadata' => $metaJson,
            ]);

            return true;
        } catch (\PDOException $e) {
            // Deduplicação idempotente: chave duplicada (SQLSTATE 23000 / code 1062) não é erro
            if ($e->getCode() === '23000' || str_contains($e->getMessage(), 'UNIQUE')) {
                return true;
            }
            error_log('[ProductionEventService] Falha de persistência de evento: ' . $e->getMessage());
            return false;
        } catch (Throwable $e) {
            error_log('[ProductionEventService] Falha inesperada ao emitir evento: ' . $e->getMessage());
            return false;
        }
    }

    /**
     * Consulta paginada com filtros seguros para a interface de investigação do Backoffice.
     */
    public function query(array $filters = [], int $limit = 50, int $offset = 0): array
    {
        $limit = max(1, min(100, $limit));
        $offset = max(0, $offset);

        $where = ['1=1'];
        $params = [];

        if (!empty($filters['category'])) {
            $where[] = 'category = :category';
            $params['category'] = (string)$filters['category'];
        }
        if (!empty($filters['severity'])) {
            $where[] = 'severity = :severity';
            $params['severity'] = (string)$filters['severity'];
        }
        if (!empty($filters['outcome'])) {
            $where[] = 'outcome = :outcome';
            $params['outcome'] = (string)$filters['outcome'];
        }
        if (!empty($filters['clinic_id'])) {
            $where[] = 'clinic_id = :clinic_id';
            $params['clinic_id'] = (int)$filters['clinic_id'];
        }
        if (!empty($filters['correlation_id'])) {
            $where[] = 'correlation_id = :correlation_id';
            $params['correlation_id'] = trim((string)$filters['correlation_id']);
        }
        if (!empty($filters['event_type'])) {
            $where[] = 'event_type LIKE :event_type';
            $params['event_type'] = '%' . trim((string)$filters['event_type']) . '%';
        }
        if (!empty($filters['from'])) {
            $where[] = 'occurred_at >= :from_date';
            $params['from_date'] = (string)$filters['from'];
        }
        if (!empty($filters['to'])) {
            $where[] = 'occurred_at <= :to_date';
            $params['to_date'] = (string)$filters['to'];
        }

        $sqlWhere = implode(' AND ', $where);

        // Contagem total para paginação
        $countStmt = $this->db->prepare("SELECT COUNT(*) FROM production_events WHERE {$sqlWhere}");
        $countStmt->execute($params);
        $total = (int)$countStmt->fetchColumn();

        // Busca paginada
        $sql = "
            SELECT * FROM production_events 
            WHERE {$sqlWhere} 
            ORDER BY occurred_at DESC, id DESC 
            LIMIT :limit OFFSET :offset
        ";
        $stmt = $this->db->prepare($sql);
        foreach ($params as $k => $v) {
            $stmt->bindValue($k, $v);
        }
        $stmt->bindValue('limit', $limit, PDO::PARAM_INT);
        $stmt->bindValue('offset', $offset, PDO::PARAM_INT);
        $stmt->execute();

        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        foreach ($rows as &$r) {
            $r['metadata'] = !empty($r['metadata']) ? json_decode($r['metadata'], true) : [];
        }

        return [
            'total' => $total,
            'limit' => $limit,
            'offset' => $offset,
            'events' => $rows,
        ];
    }

    /**
     * Obtém um evento pelo event_id UUID ou id numérico.
     */
    public function getEvent(string $eventId): ?array
    {
        $stmt = $this->db->prepare("
            SELECT * FROM production_events 
            WHERE event_id = :eid OR id = :id 
            LIMIT 1
        ");
        $stmt->execute(['eid' => $eventId, 'id' => ctype_digit($eventId) ? (int)$eventId : 0]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row) {
            return null;
        }

        $row['metadata'] = !empty($row['metadata']) ? json_decode($row['metadata'], true) : [];

        // Verifica quantos eventos compartilham a mesma correlação
        if (!empty($row['correlation_id'])) {
            $corrStmt = $this->db->prepare("SELECT COUNT(*) FROM production_events WHERE correlation_id = :cid");
            $corrStmt->execute(['cid' => $row['correlation_id']]);
            $row['correlated_count'] = (int)$corrStmt->fetchColumn();
        } else {
            $row['correlated_count'] = 1;
        }

        return $row;
    }

    /**
     * Retorna a linha do tempo completa de eventos correlacionados (mesmo correlation_id).
     */
    public function getCorrelatedTimeline(string $correlationId): array
    {
        if (trim($correlationId) === '') {
            return [];
        }

        $stmt = $this->db->prepare("
            SELECT * FROM production_events 
            WHERE correlation_id = :cid 
            ORDER BY occurred_at ASC, id ASC
        ");
        $stmt->execute(['cid' => trim($correlationId)]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        foreach ($rows as &$r) {
            $r['metadata'] = !empty($r['metadata']) ? json_decode($r['metadata'], true) : [];
        }

        return $rows;
    }

    /**
     * Painel Executivo de Produção (Métricas das últimas 24 horas).
     */
    public function getDashboardSummary(): array
    {
        $cutoff = gmdate('Y-m-d H:i:s', time() - 86400);

        // Totais e erros 24h
        $stmtTotal = $this->db->prepare("SELECT COUNT(*) FROM production_events WHERE occurred_at >= :c");
        $stmtTotal->execute(['c' => $cutoff]);
        $total24h = (int)$stmtTotal->fetchColumn();

        $stmtErrors = $this->db->prepare("SELECT COUNT(*) FROM production_events WHERE occurred_at >= :c AND (severity IN ('error', 'critical') OR outcome = 'failure')");
        $stmtErrors->execute(['c' => $cutoff]);
        $errors24h = (int)$stmtErrors->fetchColumn();

        $errorRate = $total24h > 0 ? round(($errors24h / $total24h) * 100, 2) : 0.0;

        // Distribuição por categoria
        $stmtCat = $this->db->prepare("
            SELECT category, COUNT(*) as cnt 
            FROM production_events 
            WHERE occurred_at >= :c 
            GROUP BY category 
            ORDER BY cnt DESC
        ");
        $stmtCat->execute(['c' => $cutoff]);
        $categories = $stmtCat->fetchAll(PDO::FETCH_KEY_PAIR) ?: [];

        // Distribuição por severidade
        $stmtSev = $this->db->prepare("
            SELECT severity, COUNT(*) as cnt 
            FROM production_events 
            WHERE occurred_at >= :c 
            GROUP BY severity 
            ORDER BY cnt DESC
        ");
        $stmtSev->execute(['c' => $cutoff]);
        $severities = $stmtSev->fetchAll(PDO::FETCH_KEY_PAIR) ?: [];

        // Alertas ativos
        $alertsStmt = $this->db->query("SELECT COUNT(*) FROM production_alerts WHERE status = 'firing'");
        $activeAlertsCount = (int)($alertsStmt ? $alertsStmt->fetchColumn() : 0);

        return [
            'total_24h' => $total24h,
            'errors_24h' => $errors24h,
            'error_rate' => $errorRate,
            'categories' => $categories,
            'severities' => $severities,
            'active_alerts_count' => $activeAlertsCount,
        ];
    }

    /**
     * Motor de Regras de Alertas Operacionais (NI2-776 / OPS EVENT 09).
     */
    public function evaluateAlertRules(): array
    {
        $alertsTriggered = [];
        $windowCutoff = gmdate('Y-m-d H:i:s', time() - 900); // Janela de 15 minutos

        // Regra 1: Pico de Erros em 15 minutos (> 5 erros)
        $errStmt = $this->db->prepare("
            SELECT COUNT(*) FROM production_events 
            WHERE occurred_at >= :c AND (severity IN ('error', 'critical') OR outcome = 'failure')
        ");
        $errStmt->execute(['c' => $windowCutoff]);
        $recentErrors = (int)$errStmt->fetchColumn();

        if ($recentErrors >= 5) {
            $alert = $this->recordAlert(
                'HIGH_ERROR_RATE',
                'critical',
                "Taxa de erro anormal detectada: {$recentErrors} falhas nos últimos 15 minutos.",
                ['errors_count' => $recentErrors, 'window_seconds' => 900]
            );
            $alertsTriggered[] = $alert;
        }

        // Regra 2: Falha em Webhooks de Cobrança / Billing
        $billStmt = $this->db->prepare("
            SELECT COUNT(*) FROM production_events 
            WHERE occurred_at >= :c AND category = 'BILLING' AND outcome = 'failure'
        ");
        $billStmt->execute(['c' => $windowCutoff]);
        $billingFailures = (int)$billStmt->fetchColumn();

        if ($billingFailures >= 2) {
            $alert = $this->recordAlert(
                'BILLING_WEBHOOK_FAILURE',
                'warning',
                "Falhas repetidas no processamento de webhooks financeiros do Asaas ({$billingFailures} ocorrências).",
                ['billing_failures' => $billingFailures]
            );
            $alertsTriggered[] = $alert;
        }

        return $alertsTriggered;
    }

    private function recordAlert(string $ruleName, string $severity, string $description, array $context): array
    {
        // Cooldown: se já existe alerta ativo para a mesma regra nas últimas 2 horas, não duplica
        $existing = $this->db->prepare("
            SELECT id, alert_id FROM production_alerts 
            WHERE rule_name = :rule AND status = 'firing' AND created_at >= :c
            LIMIT 1
        ");
        $cooldownTime = gmdate('Y-m-d H:i:s', time() - 7200);
        $existing->execute(['rule' => $ruleName, 'c' => $cooldownTime]);
        $row = $existing->fetch(PDO::FETCH_ASSOC);

        if ($row) {
            return ['alert_id' => $row['alert_id'], 'rule_name' => $ruleName, 'status' => 'already_firing'];
        }

        $alertId = bin2hex(random_bytes(16));
        $stmt = $this->db->prepare("
            INSERT INTO production_alerts (alert_id, rule_name, severity, status, description, context_json, triggered_at)
            VALUES (:id, :rule, :sev, 'firing', :desc, :ctx, :now)
        ");
        $stmt->execute([
            'id' => $alertId,
            'rule' => $ruleName,
            'sev' => $severity,
            'desc' => $description,
            'ctx' => json_encode($context),
            'now' => gmdate('Y-m-d H:i:s'),
        ]);

        return ['alert_id' => $alertId, 'rule_name' => $ruleName, 'status' => 'firing', 'severity' => $severity];
    }

    /**
     * Política de Retenção e Purga (NI2-777 / OPS EVENT 10).
     */
    public function purgeOldEvents(int $retentionDays = 90): int
    {
        $cutoff = gmdate('Y-m-d H:i:s', time() - ($retentionDays * 86400));
        $stmt = $this->db->prepare("DELETE FROM production_events WHERE occurred_at < :cutoff");
        $stmt->execute(['cutoff' => $cutoff]);
        return $stmt->rowCount();
    }

    /**
     * Integração com Service Desk: Cria incidente operacional a partir de um evento (NI2-778 / OPS EVENT 11).
     */
    public function createIncidentFromEvent(string $eventId, int $operatorUserId): array
    {
        $event = $this->getEvent($eventId);
        if (!$event) {
            throw new \InvalidArgumentException("Evento {$eventId} não encontrado.");
        }

        $targetClinicId = (int)($event['clinic_id'] ?: 1);
        try {
            $checkStmt = $this->db->prepare("SELECT id FROM clinics WHERE id = :id LIMIT 1");
            $checkStmt->execute(['id' => $targetClinicId]);
            if (!$checkStmt->fetchColumn()) {
                $fallbackStmt = $this->db->query("SELECT id FROM clinics ORDER BY id ASC LIMIT 1");
                $targetClinicId = (int)($fallbackStmt ? $fallbackStmt->fetchColumn() : 1);
            }
        } catch (\Throwable $e) {
            // Se tabela de clínicas não existir ou falhar, mantém fallback
            $targetClinicId = 1;
        }
        $subject = sprintf("[INCIDENTE] %s — Evento %s", $event['event_type'], substr($event['event_id'], 0, 8));
        $priority = match ($event['severity']) {
            'critical' => 'P1',
            'error' => 'P2',
            'warning' => 'P3',
            default => 'P3',
        };

        $description = sprintf(
            "Incidente aberto a partir da Central de Eventos de Produção.\n" .
            "• Event ID: %s\n" .
            "• Tipo de Evento: %s\n" .
            "• Categoria: %s\n" .
            "• Severidade: %s\n" .
            "• Outcome: %s\n" .
            "• Correlação: %s\n" .
            "• Ocorrido em (UTC): %s\n" .
            "• Metadados Sanitizados: %s\n",
            $event['event_id'],
            $event['event_type'],
            $event['category'],
            $event['severity'],
            $event['outcome'],
            $event['correlation_id'] ?: 'N/A',
            $event['occurred_at'],
            json_encode($event['metadata'], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)
        );

        $perceived = match ($event['severity']) {
            'critical' => 'urgent',
            'error' => 'high',
            'warning' => 'medium',
            default => 'low',
        };

        if ($this->helpdesk !== null) {
            return $this->helpdesk->createTicket(
                $targetClinicId,
                $operatorUserId,
                $subject,
                'sugestoes_outros',
                'incident',
                $description,
                $perceived
            );
        }

        // Fallback direto no banco caso HelpdeskTicketService não seja injetado
        $protocol = 'INC-' . strtoupper(bin2hex(random_bytes(4)));
        $stmt = $this->db->prepare("
            INSERT INTO helpdesk_tickets (
                clinic_id, user_id, protocol, subject, category, priority, priority_operational, description, status, created_at, updated_at
            ) VALUES (
                :clinic_id, :user_id, :protocol, :subject, 'sistema', 'alta', :prio_op, :desc, 'open', :now, :now
            )
        ");
        $now = date('Y-m-d H:i:s');
        $stmt->execute([
            'clinic_id' => $clinicId,
            'user_id' => $operatorUserId,
            'protocol' => $protocol,
            'subject' => $subject,
            'prio_op' => $priority,
            'desc' => $description,
            'now' => $now,
        ]);
        $ticketId = (int)$this->db->lastInsertId();

        return [
            'id' => $ticketId,
            'protocol' => $protocol,
            'subject' => $subject,
            'status' => 'open',
            'priority' => $priority,
        ];
    }
}
