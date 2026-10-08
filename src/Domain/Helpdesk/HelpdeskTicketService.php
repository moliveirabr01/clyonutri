<?php

declare(strict_types=1);

namespace ClyoNutri\Domain\Helpdesk;

use ClyoNutri\Core\Database\Connection;
use InvalidArgumentException;
use PDO;
use RuntimeException;

class HelpdeskTicketService
{
    private PDO $db;

    public const STATUS_OPEN = 'open';
    public const STATUS_TRIAGE = 'triage';
    public const STATUS_IN_PROGRESS = 'in_progress';
    public const STATUS_WAITING_CLIENT = 'waiting_client';
    public const STATUS_WAITING_INTERNAL = 'waiting_internal';
    public const STATUS_ESCALATED = 'escalated';
    public const STATUS_RESOLVED = 'resolved';
    public const STATUS_CLOSED = 'closed';
    public const STATUS_REOPENED = 'reopened';

    public const STATUS_LABELS = [
        self::STATUS_OPEN => 'Aberto',
        self::STATUS_TRIAGE => 'Em triagem',
        self::STATUS_IN_PROGRESS => 'Em atendimento',
        self::STATUS_WAITING_CLIENT => 'Aguardando cliente',
        self::STATUS_WAITING_INTERNAL => 'Aguardando time interno',
        self::STATUS_ESCALATED => 'Escalado',
        self::STATUS_RESOLVED => 'Resolvido',
        self::STATUS_CLOSED => 'Fechado',
        self::STATUS_REOPENED => 'Reaberto',
    ];

    public const STATUS_COLORS = [
        self::STATUS_OPEN => 'blue',
        self::STATUS_TRIAGE => 'purple',
        self::STATUS_IN_PROGRESS => 'amber',
        self::STATUS_WAITING_CLIENT => 'orange',
        self::STATUS_WAITING_INTERNAL => 'gray',
        self::STATUS_ESCALATED => 'rose',
        self::STATUS_RESOLVED => 'emerald',
        self::STATUS_CLOSED => 'slate',
        self::STATUS_REOPENED => 'indigo',
    ];

    public const TYPES = [
        'question' => 'Dúvida de Uso',
        'incident' => 'Incidente Técnico',
        'request' => 'Solicitação de Serviço',
        'billing' => 'Assinatura e Faturamento',
        'suggestion' => 'Sugestão ou Melhoria',
        'bug' => 'Relato de Falha / Bug',
        'privacy' => 'Segurança e LGPD',
    ];

    public const PRIORITIES = [
        'P0' => 'P0 - Crítico (Interrupção Geral)',
        'P1' => 'P1 - Alto (Degradação Severa)',
        'P2' => 'P2 - Médio (Operação Normal)',
        'P3' => 'P3 - Baixo (Dúvida ou Sugestão)',
    ];

    public function __construct(?PDO $db = null)
    {
        $this->db = $db ?? Connection::getInstance();
    }

    /**
     * Gera um protocolo único legível para o chamado.
     * Exemplo: SUP-2026-48291
     */
    public function generateUniqueProtocol(): string
    {
        $year = date('Y');
        $maxAttempts = 10;
        for ($i = 0; $i < $maxAttempts; $i++) {
            $num = str_pad((string)random_int(10000, 99999), 5, '0', STR_PAD_LEFT);
            $proto = "SUP-{$year}-{$num}";
            $stmt = $this->db->prepare("SELECT id FROM helpdesk_tickets WHERE protocol = :p LIMIT 1");
            $stmt->execute(['p' => $proto]);
            if (!$stmt->fetch()) {
                return $proto;
            }
        }
        // Fallback com timestamp
        return "SUP-{$year}-" . substr((string)time(), -5);
    }

    /**
     * Abre um novo chamado com validação rigorosa e auditoria.
     */
    public function createTicket(
        int $clinicId,
        int $userId,
        string $subject,
        string $category,
        string $type,
        string $description,
        string $priorityPerceived = 'medium',
        ?string $originRoute = null
    ): array {
        $subject = trim($subject);
        $description = trim($description);
        $category = trim($category);
        $type = trim($type);

        if (mb_strlen($subject) < 5) {
            throw new InvalidArgumentException('O assunto do chamado deve ter pelo menos 5 caracteres.');
        }

        if (mb_strlen($description) < 10) {
            throw new InvalidArgumentException('A descrição detalhada do chamado deve ter pelo menos 10 caracteres.');
        }

        if (!isset(KnowledgeBaseService::CATEGORIES[$category])) {
            throw new InvalidArgumentException("Categoria '{$category}' inválida.");
        }

        if (!isset(self::TYPES[$type])) {
            throw new InvalidArgumentException("Tipo de chamado '{$type}' inválido.");
        }

        // Determinar prioridade operacional padrão
        $operationalPriority = 'P2';
        if ($type === 'incident' || $priorityPerceived === 'urgent') {
            $operationalPriority = 'P1';
        } elseif ($type === 'suggestion' || $type === 'question') {
            $operationalPriority = 'P3';
        }

        $protocol = $this->generateUniqueProtocol();

        $startedTx = false;
        if (!$this->db->inTransaction()) {
            $this->db->beginTransaction();
            $startedTx = true;
        }

        try {
            $stmt = $this->db->prepare("
                INSERT INTO helpdesk_tickets (
                    protocol, clinic_id, user_id, type, category, 
                    priority_perceived, priority_operational, status, 
                    subject, description, origin_route, 
                    created_at, updated_at
                ) VALUES (
                    :protocol, :clinic_id, :user_id, :type, :category,
                    :priority_perceived, :priority_operational, :status,
                    :subject, :description, :origin_route,
                    CURRENT_TIMESTAMP, CURRENT_TIMESTAMP
                )
            ");
            $stmt->execute([
                'protocol' => $protocol,
                'clinic_id' => $clinicId,
                'user_id' => $userId,
                'type' => $type,
                'category' => $category,
                'priority_perceived' => $priorityPerceived,
                'priority_operational' => $operationalPriority,
                'status' => self::STATUS_OPEN,
                'subject' => $subject,
                'description' => $description,
                'origin_route' => $originRoute,
            ]);

            $ticketId = (int)$this->db->lastInsertId();

            // Mensagem inicial na timeline
            $msgStmt = $this->db->prepare("
                INSERT INTO helpdesk_ticket_messages (ticket_id, user_id, is_internal_note, message, created_at)
                VALUES (:tid, :uid, 0, :msg, CURRENT_TIMESTAMP)
            ");
            $msgStmt->execute([
                'tid' => $ticketId,
                'uid' => $userId,
                'msg' => $description,
            ]);

            // Auditoria
            $this->logAuditEvent($ticketId, $userId, 'ticket_created', [
                'protocol' => $protocol,
                'category' => $category,
                'type' => $type,
                'priority_operational' => $operationalPriority,
            ]);

            // Notificação in-app sem PII sensível
            $this->createNotification(
                $clinicId,
                $userId,
                $ticketId,
                "Chamado Aberto: {$protocol}",
                "Recebemos seu chamado sobre " . (KnowledgeBaseService::CATEGORIES[$category]['name'] ?? $category) . ". Nossa equipe entrará em contato em breve."
            );

            if ($startedTx && $this->db->inTransaction()) {
                $this->db->commit();
            }

            return $this->getTicketById($ticketId, $userId, $clinicId, false);
        } catch (\Throwable $e) {
            if ($startedTx && $this->db->inTransaction()) {
                $this->db->rollBack();
            }
            throw $e;
        }
    }

    /**
     * Retorna os detalhes do chamado com mensagens e anexos.
     * Garante isolamento rigoroso: se $isSupportUser for falso, notas internas são estritamente filtradas.
     */
    public function getTicketById(int $ticketId, int $userId, int $clinicId, bool $isSupportUser = false): array
    {
        $stmt = $this->db->prepare("
            SELECT t.*, u.name as author_name, u.email as author_email, c.name as clinic_name,
                   op.name as assigned_operator_name
            FROM helpdesk_tickets t
            JOIN users u ON u.id = t.user_id
            JOIN clinics c ON c.id = t.clinic_id
            LEFT JOIN users op ON op.id = t.assigned_to_user_id
            WHERE t.id = :id
            LIMIT 1
        ");
        $stmt->execute(['id' => $ticketId]);
        $ticket = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$ticket) {
            throw new RuntimeException('Chamado não encontrado.');
        }

        // Validação de acesso do cliente
        if (!$isSupportUser && ((int)$ticket['clinic_id'] !== $clinicId || (int)$ticket['user_id'] !== $userId)) {
            throw new RuntimeException('Acesso negado: Você não tem permissão para visualizar este chamado.');
        }

        // Buscar mensagens da timeline
        $msgSql = "
            SELECT m.*, u.name as author_name, u.role as author_role
            FROM helpdesk_ticket_messages m
            JOIN users u ON u.id = m.user_id
            WHERE m.ticket_id = :tid
        ";
        if (!$isSupportUser) {
            $msgSql .= " AND m.is_internal_note = 0";
        }
        $msgSql .= " ORDER BY m.id ASC";

        $msgStmt = $this->db->prepare($msgSql);
        $msgStmt->execute(['tid' => $ticketId]);
        $ticket['messages'] = $msgStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

        // Buscar anexos
        $attStmt = $this->db->prepare("
            SELECT id, ticket_id, message_id, file_name, mime_type, file_size, uploaded_by_user_id, created_at
            FROM helpdesk_attachments
            WHERE ticket_id = :tid
            ORDER BY id ASC
        ");
        $attStmt->execute(['tid' => $ticketId]);
        $ticket['attachments'] = $attStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

        // Buscar feedback CSAT se houver
        $fbStmt = $this->db->prepare("SELECT * FROM helpdesk_ticket_feedbacks WHERE ticket_id = :tid LIMIT 1");
        $fbStmt->execute(['tid' => $ticketId]);
        $ticket['feedback'] = $fbStmt->fetch(PDO::FETCH_ASSOC) ?: null;

        // Metadados amigáveis
        $ticket['status_label'] = self::STATUS_LABELS[$ticket['status']] ?? $ticket['status'];
        $ticket['status_color'] = self::STATUS_COLORS[$ticket['status']] ?? 'gray';
        $ticket['type_label'] = self::TYPES[$ticket['type']] ?? $ticket['type'];
        $ticket['category_label'] = KnowledgeBaseService::CATEGORIES[$ticket['category']]['name'] ?? $ticket['category'];

        return $ticket;
    }

    /**
     * Retorna o chamado pelo seu protocolo legível (SUP-XXXX-XXXXX).
     */
    public function getTicketByProtocol(string $protocol, int $userId, int $clinicId, bool $isSupportUser = false): array
    {
        $stmt = $this->db->prepare("SELECT id FROM helpdesk_tickets WHERE protocol = :proto LIMIT 1");
        $stmt->execute(['proto' => trim($protocol)]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row) {
            throw new RuntimeException("Chamado com protocolo '{$protocol}' não encontrado.");
        }
        return $this->getTicketById((int)$row['id'], $userId, $clinicId, $isSupportUser);
    }

    /**
     * Lista chamados de um usuário/clínica com filtros.
     */
    public function listUserTickets(int $userId, int $clinicId, ?string $statusFilter = null): array
    {
        $sql = "
            SELECT t.*, u.name as author_name,
                   (SELECT COUNT(*) FROM helpdesk_ticket_messages WHERE ticket_id = t.id AND is_internal_note = 0) as messages_count,
                   (SELECT COUNT(*) FROM helpdesk_attachments WHERE ticket_id = t.id) as attachments_count
            FROM helpdesk_tickets t
            JOIN users u ON u.id = t.user_id
            WHERE t.clinic_id = :cid AND t.user_id = :uid
        ";
        $params = ['cid' => $clinicId, 'uid' => $userId];

        if (!empty($statusFilter) && isset(self::STATUS_LABELS[$statusFilter])) {
            $sql .= " AND t.status = :status";
            $params['status'] = $statusFilter;
        }

        $sql .= " ORDER BY t.updated_at DESC, t.id DESC";

        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        $tickets = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

        foreach ($tickets as &$t) {
            $t['status_label'] = self::STATUS_LABELS[$t['status']] ?? $t['status'];
            $t['status_color'] = self::STATUS_COLORS[$t['status']] ?? 'gray';
            $t['type_label'] = self::TYPES[$t['type']] ?? $t['type'];
            $t['category_label'] = KnowledgeBaseService::CATEGORIES[$t['category']]['name'] ?? $t['category'];
        }

        return $tickets;
    }

    /**
     * Fila de atendimento e triagem do backoffice (operadores / administradores).
     */
    public function listBackofficeQueue(?string $status = null, ?string $priority = null, ?int $assignedTo = null): array
    {
        $sql = "
            SELECT t.*, u.name as author_name, u.email as author_email, c.name as clinic_name,
                   op.name as assigned_operator_name,
                   (SELECT COUNT(*) FROM helpdesk_ticket_messages WHERE ticket_id = t.id) as total_messages,
                   (SELECT COUNT(*) FROM helpdesk_attachments WHERE ticket_id = t.id) as attachments_count,
                   fb.rating as csat_rating
            FROM helpdesk_tickets t
            JOIN users u ON u.id = t.user_id
            JOIN clinics c ON c.id = t.clinic_id
            LEFT JOIN users op ON op.id = t.assigned_to_user_id
            LEFT JOIN helpdesk_ticket_feedbacks fb ON fb.ticket_id = t.id
            WHERE 1=1
        ";
        $params = [];

        if (!empty($status)) {
            $sql .= " AND t.status = :status";
            $params['status'] = $status;
        }

        if (!empty($priority)) {
            $sql .= " AND t.priority_operational = :priority";
            $params['priority'] = $priority;
        }

        if ($assignedTo !== null) {
            $sql .= " AND t.assigned_to_user_id = :assigned";
            $params['assigned'] = $assignedTo;
        }

        // Ordenação inteligente: P0 primeiro, depois P1, P2, P3, e chamados mais antigos
        $sql .= " ORDER BY CASE t.priority_operational 
                            WHEN 'P0' THEN 1 
                            WHEN 'P1' THEN 2 
                            WHEN 'P2' THEN 3 
                            WHEN 'P3' THEN 4 
                            ELSE 5 END ASC, 
                 t.updated_at ASC";

        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

        foreach ($rows as &$r) {
            $r['status_label'] = self::STATUS_LABELS[$r['status']] ?? $r['status'];
            $r['status_color'] = self::STATUS_COLORS[$r['status']] ?? 'gray';
            $r['type_label'] = self::TYPES[$r['type']] ?? $r['type'];
            $r['category_label'] = KnowledgeBaseService::CATEGORIES[$r['category']]['name'] ?? $r['category'];
        }

        return $rows;
    }

    /**
     * Adiciona uma mensagem ou resposta na timeline do chamado.
     * Suporta notas internas exclusivas para o time de suporte.
     */
    public function addMessage(
        int $ticketId,
        int $userId,
        string $message,
        bool $isInternalNote = false,
        bool $isSupportUser = false
    ): array {
        $message = trim($message);
        if (mb_strlen($message) < 2) {
            throw new InvalidArgumentException('A mensagem não pode estar vazia.');
        }

        // Cliente comum nunca pode postar nota interna
        if ($isInternalNote && !$isSupportUser) {
            throw new RuntimeException('Operação não permitida: Notas internas são restritas à equipe de atendimento.');
        }

        $stmt = $this->db->prepare("SELECT * FROM helpdesk_tickets WHERE id = :id LIMIT 1");
        $stmt->execute(['id' => $ticketId]);
        $ticket = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$ticket) {
            throw new RuntimeException('Chamado não encontrado.');
        }

        // Se o ticket já estiver fechado, rejeita nova mensagem direta
        if ($ticket['status'] === self::STATUS_CLOSED) {
            throw new RuntimeException('Este chamado está fechado. Para continuar o atendimento, solicite a reabertura.');
        }

        $startedTx = false;
        if (!$this->db->inTransaction()) {
            $this->db->beginTransaction();
            $startedTx = true;
        }
        try {
            $msgStmt = $this->db->prepare("
                INSERT INTO helpdesk_ticket_messages (ticket_id, user_id, is_internal_note, message, created_at)
                VALUES (:tid, :uid, :is_internal, :msg, CURRENT_TIMESTAMP)
            ");
            $msgStmt->execute([
                'tid' => $ticketId,
                'uid' => $userId,
                'is_internal' => $isInternalNote ? 1 : 0,
                'msg' => $message,
            ]);
            $msgId = (int)$this->db->lastInsertId();

            // Atualizar status do chamado se aplicável
            $updates = ["updated_at = CURRENT_TIMESTAMP"];
            $params = ['tid' => $ticketId];

            if ($isSupportUser && !$isInternalNote) {
                // Primeira resposta do agente registra SLA
                if (empty($ticket['first_response_at'])) {
                    $updates[] = "first_response_at = CURRENT_TIMESTAMP";
                }
                // Resposta pública do agente coloca em waiting_client se estiver in_progress
                if (in_array($ticket['status'], [self::STATUS_OPEN, self::STATUS_TRIAGE, self::STATUS_IN_PROGRESS])) {
                    $updates[] = "status = 'waiting_client'";
                }
            } elseif (!$isSupportUser) {
                // Cliente respondeu: se estava waiting_client, volta para in_progress
                if ($ticket['status'] === self::STATUS_WAITING_CLIENT) {
                    $updates[] = "status = 'in_progress'";
                }
            }

            $updateSql = "UPDATE helpdesk_tickets SET " . implode(', ', $updates) . " WHERE id = :tid";
            $uStmt = $this->db->prepare($updateSql);
            $uStmt->execute($params);

            // Notificação in-app para o cliente se o suporte postou mensagem pública
            if ($isSupportUser && !$isInternalNote) {
                $this->createNotification(
                    (int)$ticket['clinic_id'],
                    (int)$ticket['user_id'],
                    $ticketId,
                    "Atualização no Chamado: {$ticket['protocol']}",
                    "Sua solicitação recebeu uma nova resposta da nossa equipe de suporte."
                );
            }

            $this->logAuditEvent($ticketId, $userId, $isInternalNote ? 'internal_note_added' : 'message_added', [
                'message_id' => $msgId,
                'is_internal' => $isInternalNote,
            ]);

            if ($startedTx && $this->db->inTransaction()) {
                $this->db->commit();
            }

            return [
                'id' => $msgId,
                'ticket_id' => $ticketId,
                'user_id' => $userId,
                'is_internal_note' => $isInternalNote ? 1 : 0,
                'message' => $message,
                'created_at' => date('Y-m-d H:i:s'),
            ];
        } catch (\Throwable $e) {
            if ($startedTx && $this->db->inTransaction()) {
                $this->db->rollBack();
            }
            throw $e;
        }
    }

    /**
     * Transiciona o status do chamado com validação das regras de negócio.
     */
    public function updateStatus(
        int $ticketId,
        int $actorUserId,
        string $newStatus,
        ?string $resolutionSummary = null,
        bool $isSupportUser = false
    ): void {
        if (!isset(self::STATUS_LABELS[$newStatus])) {
            throw new InvalidArgumentException("Status '{$newStatus}' inválido.");
        }

        $stmt = $this->db->prepare("SELECT * FROM helpdesk_tickets WHERE id = :id LIMIT 1");
        $stmt->execute(['id' => $ticketId]);
        $ticket = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$ticket) {
            throw new RuntimeException('Chamado não encontrado.');
        }

        $oldStatus = $ticket['status'];
        if ($oldStatus === $newStatus) {
            return;
        }

        // Regras de autorização de status
        // Apenas agentes de suporte podem colocar em: triage, waiting_internal, escalated
        if (in_array($newStatus, [self::STATUS_TRIAGE, self::STATUS_WAITING_INTERNAL, self::STATUS_ESCALATED], true) && !$isSupportUser) {
            throw new RuntimeException('Apenas operadores de suporte podem alterar o chamado para esta etapa.');
        }

        $startedTx = false;
        if (!$this->db->inTransaction()) {
            $this->db->beginTransaction();
            $startedTx = true;
        }
        try {
            $updates = ["status = :status", "updated_at = CURRENT_TIMESTAMP"];
            $params = ['status' => $newStatus, 'tid' => $ticketId];

            if ($newStatus === self::STATUS_RESOLVED) {
                $updates[] = "resolved_at = CURRENT_TIMESTAMP";
                if ($resolutionSummary) {
                    $updates[] = "resolution_summary = :res";
                    $params['res'] = trim($resolutionSummary);
                }
            } elseif ($newStatus === self::STATUS_CLOSED) {
                $updates[] = "closed_at = CURRENT_TIMESTAMP";
            } elseif ($newStatus === self::STATUS_REOPENED) {
                $updates[] = "reopened_at = CURRENT_TIMESTAMP";
            }

            $sql = "UPDATE helpdesk_tickets SET " . implode(', ', $updates) . " WHERE id = :tid";
            $uStmt = $this->db->prepare($sql);
            $uStmt->execute($params);

            // Notificação ao cliente caso seja resolvido
            if ($newStatus === self::STATUS_RESOLVED) {
                $this->createNotification(
                    (int)$ticket['clinic_id'],
                    (int)$ticket['user_id'],
                    $ticketId,
                    "Chamado Resolvido: {$ticket['protocol']}",
                    "Seu chamado foi marcado como resolvido. Por favor, confirme a solução e avalie nosso atendimento."
                );
            }

            $this->logAuditEvent($ticketId, $actorUserId, 'status_changed', [
                'from' => $oldStatus,
                'to' => $newStatus,
                'resolution_summary' => $resolutionSummary,
            ]);

            if ($startedTx && $this->db->inTransaction()) {
                $this->db->commit();
            }
        } catch (\Throwable $e) {
            if ($startedTx && $this->db->inTransaction()) {
                $this->db->rollBack();
            }
            throw $e;
        }
    }

    /**
     * Atribui um operador ao chamado.
     */
    public function assignOperator(int $ticketId, int $operatorUserId, int $actorUserId): void
    {
        $stmt = $this->db->prepare("
            UPDATE helpdesk_tickets 
            SET assigned_to_user_id = :op, updated_at = CURRENT_TIMESTAMP
            WHERE id = :id
        ");
        $stmt->execute(['op' => $operatorUserId, 'id' => $ticketId]);

        $this->logAuditEvent($ticketId, $actorUserId, 'operator_assigned', [
            'operator_user_id' => $operatorUserId,
        ]);
    }

    /**
     * Define a prioridade operacional (P0, P1, P2, P3).
     */
    public function setPriority(int $ticketId, string $priority, int $actorUserId): void
    {
        if (!isset(self::PRIORITIES[$priority])) {
            throw new InvalidArgumentException("Prioridade '{$priority}' inválida.");
        }

        $stmt = $this->db->prepare("
            UPDATE helpdesk_tickets 
            SET priority_operational = :p, updated_at = CURRENT_TIMESTAMP
            WHERE id = :id
        ");
        $stmt->execute(['p' => $priority, 'id' => $ticketId]);

        $this->logAuditEvent($ticketId, $actorUserId, 'priority_changed', ['priority' => $priority]);
    }

    /**
     * Registra avaliação CSAT pós-resolução.
     */
    public function submitFeedback(int $ticketId, int $userId, int $rating, ?string $comment = null): array
    {
        if ($rating < 1 || $rating > 5) {
            throw new InvalidArgumentException('A nota de avaliação CSAT deve ser entre 1 e 5 estrelas.');
        }

        $stmt = $this->db->prepare("SELECT * FROM helpdesk_tickets WHERE id = :id LIMIT 1");
        $stmt->execute(['id' => $ticketId]);
        $ticket = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$ticket) {
            throw new RuntimeException('Chamado não encontrado.');
        }

        if ((int)$ticket['user_id'] !== $userId) {
            throw new RuntimeException('Apenas o autor do chamado pode avaliar o atendimento.');
        }

        if (!in_array($ticket['status'], [self::STATUS_RESOLVED, self::STATUS_CLOSED], true)) {
            throw new RuntimeException('A avaliação só pode ser realizada para chamados resolvidos ou fechados.');
        }

        $comment = $comment ? trim(strip_tags($comment)) : null;

        $insStmt = $this->db->prepare("
            INSERT INTO helpdesk_ticket_feedbacks (ticket_id, user_id, rating, comment, created_at)
            VALUES (:tid, :uid, :rating, :comment, CURRENT_TIMESTAMP)
        ");
        $insStmt->execute([
            'tid' => $ticketId,
            'uid' => $userId,
            'rating' => $rating,
            'comment' => $comment,
        ]);

        $this->logAuditEvent($ticketId, $userId, 'csat_submitted', [
            'rating' => $rating,
            'has_comment' => !empty($comment),
        ]);

        return [
            'ticket_id' => $ticketId,
            'rating' => $rating,
            'comment' => $comment,
        ];
    }

    /**
     * Retorna indicadores operacionais e saúde do Service Desk (SLA & Indicadores - NI2-206).
     */
    public function getOperationsMetrics(): array
    {
        // 1. Contagem por status
        $statusCounts = $this->db->query("
            SELECT status, COUNT(*) as count 
            FROM helpdesk_tickets 
            GROUP BY status
        ")->fetchAll(PDO::FETCH_KEY_PAIR) ?: [];

        // 2. Contagem por prioridade
        $priorityCounts = $this->db->query("
            SELECT priority_operational, COUNT(*) as count 
            FROM helpdesk_tickets 
            GROUP BY priority_operational
        ")->fetchAll(PDO::FETCH_KEY_PAIR) ?: [];

        // 3. Tempo Médio de Primeira Resposta (FRT) em minutos
        $frtMinutes = (float)$this->db->query("
            SELECT AVG((strftime('%s', first_response_at) - strftime('%s', created_at)) / 60.0) 
            FROM helpdesk_tickets 
            WHERE first_response_at IS NOT NULL
        ")->fetchColumn();

        // 4. Tempo Médio de Resolução (MTTR) em horas
        $mttrHours = (float)$this->db->query("
            SELECT AVG((strftime('%s', resolved_at) - strftime('%s', created_at)) / 3600.0) 
            FROM helpdesk_tickets 
            WHERE resolved_at IS NOT NULL
        ")->fetchColumn();

        // 5. Chamados Envelhecidos (mais de 48h abertos sem resolução)
        $agedTickets = (int)$this->db->query("
            SELECT COUNT(*) 
            FROM helpdesk_tickets 
            WHERE status NOT IN ('resolved', 'closed')
              AND datetime(created_at) <= datetime('now', '-2 days')
        ")->fetchColumn();

        // 6. Média CSAT
        $csatAvg = (float)$this->db->query("
            SELECT AVG(rating) FROM helpdesk_ticket_feedbacks
        ")->fetchColumn();

        $totalFeedbacks = (int)$this->db->query("
            SELECT COUNT(*) FROM helpdesk_ticket_feedbacks
        ")->fetchColumn();

        return [
            'status_counts' => $statusCounts,
            'priority_counts' => $priorityCounts,
            'avg_first_response_minutes' => round($frtMinutes, 1),
            'avg_resolution_hours' => round($mttrHours, 1),
            'aged_tickets_count' => $agedTickets,
            'csat_average' => round($csatAvg, 2),
            'csat_total_feedbacks' => $totalFeedbacks,
            'total_tickets' => (int)$this->db->query("SELECT COUNT(*) FROM helpdesk_tickets")->fetchColumn(),
        ];
    }

    /**
     * Cria notificação in-app sem expor dados clínicos ou confidenciais.
     */
    private function createNotification(int $clinicId, int $userId, int $ticketId, string $title, string $body): void
    {
        $stmt = $this->db->prepare("
            INSERT INTO helpdesk_notifications (clinic_id, user_id, ticket_id, title, body, created_at)
            VALUES (:cid, :uid, :tid, :title, :body, CURRENT_TIMESTAMP)
        ");
        $stmt->execute([
            'cid' => $clinicId,
            'uid' => $userId,
            'tid' => $ticketId,
            'title' => $title,
            'body' => $body,
        ]);
    }

    /**
     * Registra evento na trilha de auditoria do chamado.
     */
    private function logAuditEvent(int $ticketId, int $actorUserId, string $eventType, array $payload = []): void
    {
        $stmt = $this->db->prepare("
            INSERT INTO helpdesk_audit_events (ticket_id, actor_user_id, event_type, payload_json, ip_address, user_agent, created_at)
            VALUES (:tid, :uid, :ev, :payload, :ip, :ua, CURRENT_TIMESTAMP)
        ");
        $stmt->execute([
            'tid' => $ticketId,
            'uid' => $actorUserId,
            'ev' => $eventType,
            'payload' => json_encode($payload, JSON_UNESCAPED_UNICODE),
            'ip' => $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1',
            'ua' => substr((string)($_SERVER['HTTP_USER_AGENT'] ?? 'CLI/System'), 0, 250),
        ]);
    }
}
