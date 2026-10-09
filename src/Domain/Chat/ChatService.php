<?php

declare(strict_types=1);

namespace ClyoNutri\Domain\Chat;

use ClyoNutri\Core\Auth\TenantContext;
use ClyoNutri\Core\Database\Connection;
use ClyoNutri\Core\Security\TenantGuard;
use ClyoNutri\Core\Security\TenantSecurityException;
use InvalidArgumentException;
use PDO;
use RuntimeException;

class ChatService
{
    private PDO $db;
    private ChatAttachmentService $attachmentService;

    public function __construct(?PDO $db = null, ?ChatAttachmentService $attachmentService = null)
    {
        $this->db = $db ?? Connection::getInstance();
        $this->attachmentService = $attachmentService ?? new ChatAttachmentService($this->db);
    }

    /**
     * Obtém ou cria conversa segura entre paciente e profissional autorizados no mesmo tenant.
     */
    public function getOrCreateConversation(int $patientId, int $professionalId): int
    {
        $clinicId = TenantContext::getClinicId();
        TenantGuard::assertPatientBelongsToClinic($patientId, $clinicId, $this->db);
        TenantGuard::assertProfessionalBelongsToClinic($professionalId, $clinicId, $this->db);

        $stmt = $this->db->prepare("
            SELECT id FROM chat_conversations
            WHERE patient_id = :patient_id AND professional_id = :professional_id AND clinic_id = :clinic_id
            LIMIT 1
        ");
        $stmt->execute([
            'patient_id' => $patientId,
            'professional_id' => $professionalId,
            'clinic_id' => $clinicId,
        ]);
        $convId = $stmt->fetchColumn();

        if ($convId) {
            return (int)$convId;
        }

        $ins = $this->db->prepare("
            INSERT INTO chat_conversations (clinic_id, patient_id, professional_id, status, created_at, updated_at)
            VALUES (:clinic_id, :patient_id, :professional_id, 'active', CURRENT_TIMESTAMP, CURRENT_TIMESTAMP)
        ");
        $ins->execute([
            'clinic_id' => $clinicId,
            'patient_id' => $patientId,
            'professional_id' => $professionalId,
        ]);

        return (int)$this->db->lastInsertId();
    }

    /**
     * Retorna detalhes da conversa assegurando isolamento de tenant.
     */
    public function getConversation(int $conversationId): array
    {
        $clinicId = TenantContext::getClinicId();
        TenantGuard::assertConversationBelongsToClinic($conversationId, $clinicId, $this->db);

        $stmt = $this->db->prepare("
            SELECT c.*, p.name AS patient_name, p.email AS patient_email, p.phone AS patient_phone,
                   u.name AS professional_name, pr.crn AS professional_crn
            FROM chat_conversations c
            JOIN patients p ON p.id = c.patient_id
            JOIN professionals pr ON pr.id = c.professional_id
            JOIN users u ON u.id = pr.user_id
            WHERE c.id = :id AND c.clinic_id = :clinic_id
            LIMIT 1
        ");
        $stmt->execute(['id' => $conversationId, 'clinic_id' => $clinicId]);
        $conv = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$conv) {
            throw new TenantSecurityException("Conversa #{$conversationId} não encontrada no tenant ativo.", 404);
        }

        return $conv;
    }

    /**
     * Envia mensagem segura com suporte a idempotência, anti-duplicação, anexos protegidos
     * e notificações sem exposição de dados clínicos.
     */
    public function sendMessage(
        int $conversationId,
        string $senderType,
        int $senderId,
        string $message,
        ?string $attachment = null,
        ?string $idempotencyKey = null,
        ?array $attachmentFile = null
    ): array {
        $clinicId = TenantContext::getClinicId();
        $conversation = TenantGuard::assertConversationBelongsToClinic($conversationId, $clinicId, $this->db);

        // 1. Validar autorização estrita do participante (RBAC / Ownership)
        $this->attachmentService->assertUploaderIsParticipant($conversationId, $senderType, $senderId);

        $cleanMsg = trim($message);
        if ($cleanMsg === '' && empty($attachment) && empty($attachmentFile)) {
            throw new InvalidArgumentException("A mensagem não pode ser vazia sem anexo.");
        }

        // 2. Proteção Anti-Duplicação / Idempotência (Double-click e retry de rede)
        if (!empty($idempotencyKey)) {
            $checkStmt = $this->db->prepare("
                SELECT id, conversation_id, sender_type, sender_id, message, attachment_path,
                       attachment_name, attachment_mime, attachment_size, status, idempotency_key, created_at
                FROM chat_messages
                WHERE conversation_id = :conv_id AND idempotency_key = :idemp
                LIMIT 1
            ");
            $checkStmt->execute([
                'conv_id' => $conversationId,
                'idemp' => $idempotencyKey,
            ]);
            $existing = $checkStmt->fetch(PDO::FETCH_ASSOC);

            if ($existing) {
                return array_merge($existing, [
                    'is_duplicate' => true,
                    'replayed' => true,
                ]);
            }
        }

        $this->db->beginTransaction();
        try {
            // 3. Processar Anexo Físico se fornecido
            $attMeta = null;
            $attPath = $attachment;
            $attName = null;
            $attMime = null;
            $attSize = null;

            if ($attachmentFile !== null && !empty($attachmentFile['tmp_name'])) {
                $attMeta = $this->attachmentService->storeAttachment(
                    $conversationId,
                    $senderType,
                    $senderId,
                    $attachmentFile
                );
                $attPath = $attMeta['storage_path'];
                $attName = $attMeta['original_name'];
                $attMime = $attMeta['mime_type'];
                $attSize = $attMeta['size_bytes'];
            }

            // 4. Inserir Mensagem com status inicial 'sent'
            $stmt = $this->db->prepare("
                INSERT INTO chat_messages (
                    conversation_id, clinic_id, sender_type, sender_id, message,
                    attachment_path, attachment_name, attachment_mime, attachment_size,
                    idempotency_key, status, created_at
                ) VALUES (
                    :conv_id, :clinic_id, :sender_type, :sender_id, :message,
                    :attachment_path, :attachment_name, :attachment_mime, :attachment_size,
                    :idempotency_key, 'sent', CURRENT_TIMESTAMP
                )
            ");

            $stmt->execute([
                'conv_id' => $conversationId,
                'clinic_id' => $clinicId,
                'sender_type' => $senderType,
                'sender_id' => $senderId,
                'message' => $cleanMsg,
                'attachment_path' => $attPath,
                'attachment_name' => $attName,
                'attachment_mime' => $attMime,
                'attachment_size' => $attSize,
                'idempotency_key' => $idempotencyKey,
            ]);

            $msgId = (int)$this->db->lastInsertId();

            if ($attMeta !== null) {
                // Vincular message_id no anexo
                $upAtt = $this->db->prepare("UPDATE chat_attachments SET message_id = :mid WHERE id = :aid");
                $upAtt->execute(['mid' => $msgId, 'aid' => $attMeta['id']]);
            }

            // 5. Atualizar timestamp da conversa
            $upConv = $this->db->prepare("UPDATE chat_conversations SET updated_at = CURRENT_TIMESTAMP WHERE id = :id");
            $upConv->execute(['id' => $conversationId]);

            // 6. Gerar Notificação Segura (Garantia de ZERO dados clínicos / PII médico no alerta)
            $recipientType = ($senderType === 'professional') ? 'patient' : 'professional';
            $recipientId = ($senderType === 'professional') ? (int)$conversation['patient_id'] : (int)$conversation['professional_id'];

            $this->createSecureNotificationLog(
                $clinicId,
                $conversationId,
                $msgId,
                $recipientType,
                $recipientId,
                $senderType
            );

            $this->db->commit();

            return [
                'id' => $msgId,
                'conversation_id' => $conversationId,
                'sender_type' => $senderType,
                'sender_id' => $senderId,
                'message' => $cleanMsg,
                'attachment_path' => $attPath,
                'attachment_name' => $attName,
                'attachment_mime' => $attMime,
                'attachment_size' => $attSize,
                'status' => 'sent',
                'idempotency_key' => $idempotencyKey,
                'is_duplicate' => false,
                'created_at' => date('c'),
            ];
        } catch (\Throwable $e) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            throw $e;
        }
    }

    /**
     * Consulta mensagens legadas (para compatibilidade com suítes existentes).
     */
    public function getMessages(int $conversationId, int $limit = 50): array
    {
        $clinicId = TenantContext::getClinicId();
        TenantGuard::assertConversationBelongsToClinic($conversationId, $clinicId, $this->db);

        $stmt = $this->db->prepare("
            SELECT * FROM chat_messages
            WHERE conversation_id = :conv_id AND clinic_id = :clinic_id
            ORDER BY id ASC
            LIMIT :limit
        ");
        $stmt->bindValue(':conv_id', $conversationId, PDO::PARAM_INT);
        $stmt->bindValue(':clinic_id', $clinicId, PDO::PARAM_INT);
        $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
        $stmt->execute();

        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Polling otimizado com cursor incremental (after_id) e política responsiva de taxa.
     */
    public function getMessagesWithCursor(
        int $conversationId,
        ?int $afterId = null,
        int $limit = 50,
        ?string $readerType = null,
        ?int $readerId = null
    ): array {
        $clinicId = TenantContext::getClinicId();
        TenantGuard::assertConversationBelongsToClinic($conversationId, $clinicId, $this->db);

        if ($readerType !== null && $readerId !== null) {
            $this->attachmentService->assertUploaderIsParticipant($conversationId, $readerType, $readerId);
            // Marcar como entregues mensagens destinadas a este leitor
            $this->markAsDelivered($conversationId, $readerType);
        }

        $sql = "
            SELECT m.*, 
                   CASE WHEN m.attachment_path IS NOT NULL THEN 1 ELSE 0 END AS has_attachment
            FROM chat_messages m
            WHERE m.conversation_id = :conv_id AND m.clinic_id = :clinic_id
        ";
        $params = [
            'conv_id' => $conversationId,
            'clinic_id' => $clinicId,
        ];

        if ($afterId !== null && $afterId > 0) {
            $sql .= " AND m.id > :after_id";
            $params['after_id'] = $afterId;
        }

        $sql .= " ORDER BY m.id ASC LIMIT :limit";

        $stmt = $this->db->prepare($sql);
        foreach ($params as $key => $val) {
            $stmt->bindValue(':' . $key, $val, PDO::PARAM_INT);
        }
        $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
        $stmt->execute();
        $messages = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

        $lastId = $afterId ?? 0;
        if (!empty($messages)) {
            $lastId = (int)end($messages)['id'];
        }

        return [
            'conversation_id' => $conversationId,
            'messages' => $messages,
            'cursor' => [
                'after_id' => $lastId,
                'count' => count($messages),
                'has_more' => count($messages) === $limit,
            ],
            // Política adaptativa de polling para preservar recursos de hospedagem
            'polling_policy' => [
                'active_window_ms' => 3000,     // 3 segundos com janela em foco
                'background_window_ms' => 15000, // 15 segundos em background
                'idle_window_ms' => 60000,      // 60 segundos se ocioso há mais de 5min
                'max_requests_per_minute' => 60,
                'transport' => 'cursor_delta_polling',
                'zero_websockets' => true,
            ],
        ];
    }

    /**
     * Marca mensagens enviadas para o destinatário como 'delivered' (entregues no client).
     */
    public function markAsDelivered(int $conversationId, string $readerType): int
    {
        $clinicId = TenantContext::getClinicId();
        $otherSender = ($readerType === 'professional') ? 'patient' : 'professional';

        $stmt = $this->db->prepare("
            UPDATE chat_messages
            SET status = 'delivered', delivered_at = CURRENT_TIMESTAMP
            WHERE conversation_id = :conv_id 
              AND clinic_id = :clinic_id 
              AND sender_type = :other_sender 
              AND status = 'sent'
        ");
        $stmt->execute([
            'conv_id' => $conversationId,
            'clinic_id' => $clinicId,
            'other_sender' => $otherSender,
        ]);

        return $stmt->rowCount();
    }

    /**
     * Marca mensagens enviadas pelo outro participante como lidas com validação estrita do participante (PX-25 / NI2-318).
     */
    public function markAsRead(int $conversationId, string $currentReaderType, ?int $readerId = null): void
    {
        $clinicId = TenantContext::getClinicId();

        // Validar propriedade da conversa quando readerId for informado (PX-25 / NI2-318)
        if ($readerId !== null) {
            if ($currentReaderType === 'patient') {
                $chk = $this->db->prepare("SELECT id FROM chat_conversations WHERE id = :conv_id AND clinic_id = :clinic_id AND patient_id = :reader_id");
                $chk->execute(['conv_id' => $conversationId, 'clinic_id' => $clinicId, 'reader_id' => $readerId]);
                if (!$chk->fetchColumn()) {
                    throw new \ClyoNutri\Core\Security\AuthorizationException("Acesso não autorizado: conversa não pertence ao paciente autenticado.");
                }
            } elseif ($currentReaderType === 'professional') {
                $chk = $this->db->prepare("SELECT id FROM chat_conversations WHERE id = :conv_id AND clinic_id = :clinic_id AND professional_id = :reader_id");
                $chk->execute(['conv_id' => $conversationId, 'clinic_id' => $clinicId, 'reader_id' => $readerId]);
                if (!$chk->fetchColumn()) {
                    throw new \ClyoNutri\Core\Security\AuthorizationException("Acesso não autorizado: conversa não pertence ao profissional autenticado.");
                }
            }
        }

        $otherType = ($currentReaderType === 'professional') ? 'patient' : 'professional';

        $stmt = $this->db->prepare("
            UPDATE chat_messages
            SET status = 'read', read_at = CURRENT_TIMESTAMP
            WHERE conversation_id = :conv_id 
              AND clinic_id = :clinic_id 
              AND sender_type = :other_type 
              AND read_at IS NULL
        ");
        $stmt->execute([
            'conv_id' => $conversationId,
            'clinic_id' => $clinicId,
            'other_type' => $otherType,
        ]);
    }

    /**
     * Lista conversas ativas do nutricionista com contagem de não lidas e última mensagem.
     */
    public function listConversationsForProfessional(int $professionalId): array
    {
        $clinicId = TenantContext::getClinicId();
        TenantGuard::assertProfessionalBelongsToClinic($professionalId, $clinicId, $this->db);

        $stmt = $this->db->prepare("
            SELECT c.*, p.name AS patient_name, p.email AS patient_email, p.phone AS patient_phone,
                   (
                       SELECT m.message 
                       FROM chat_messages m 
                       WHERE m.conversation_id = c.id 
                       ORDER BY m.id DESC LIMIT 1
                   ) AS last_message,
                   (
                       SELECT m.created_at 
                       FROM chat_messages m 
                       WHERE m.conversation_id = c.id 
                       ORDER BY m.id DESC LIMIT 1
                   ) AS last_message_at,
                   (
                       SELECT COUNT(*) 
                       FROM chat_messages m 
                       WHERE m.conversation_id = c.id 
                         AND m.sender_type = 'patient' 
                         AND m.read_at IS NULL
                   ) AS unread_count
            FROM chat_conversations c
            JOIN patients p ON p.id = c.patient_id
            WHERE c.professional_id = :prof_id AND c.clinic_id = :clinic_id
            ORDER BY c.updated_at DESC
        ");
        $stmt->execute([
            'prof_id' => $professionalId,
            'clinic_id' => $clinicId,
        ]);

        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    /**
     * Lista conversas para o paciente com dados do nutricionista responsável.
     */
    public function listConversationsForPatient(int $patientId): array
    {
        $clinicId = TenantContext::getClinicId();
        TenantGuard::assertPatientBelongsToClinic($patientId, $clinicId, $this->db);

        $stmt = $this->db->prepare("
            SELECT c.*, u.name AS professional_name, pr.crn AS professional_crn,
                   (
                       SELECT m.message 
                       FROM chat_messages m 
                       WHERE m.conversation_id = c.id 
                       ORDER BY m.id DESC LIMIT 1
                   ) AS last_message,
                   (
                       SELECT m.created_at 
                       FROM chat_messages m 
                       WHERE m.conversation_id = c.id 
                       ORDER BY m.id DESC LIMIT 1
                   ) AS last_message_at,
                   (
                       SELECT COUNT(*) 
                       FROM chat_messages m 
                       WHERE m.conversation_id = c.id 
                         AND m.sender_type = 'professional' 
                         AND m.read_at IS NULL
                   ) AS unread_count
            FROM chat_conversations c
            JOIN professionals pr ON pr.id = c.professional_id
            JOIN users u ON u.id = pr.user_id
            WHERE c.patient_id = :patient_id AND c.clinic_id = :clinic_id
            ORDER BY c.updated_at DESC
        ");
        $stmt->execute([
            'patient_id' => $patientId,
            'clinic_id' => $clinicId,
        ]);

        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    public function getAttachmentService(): ChatAttachmentService
    {
        return $this->attachmentService;
    }

    /**
     * Registra notificação garantindo conformidade rigorosa com a LGPD:
     * O corpo NUNCA contém diagnósticos, dietas, condutas ou termos clínicos sensíveis.
     */
    private function createSecureNotificationLog(
        int $clinicId,
        int $conversationId,
        int $messageId,
        string $recipientType,
        int $recipientId,
        string $senderType
    ): void {
        $senderLabel = ($senderType === 'professional') ? 'Seu nutricionista' : 'Seu paciente';
        $title = "Nova mensagem no canal seguro ClyoNutri";
        $body = "{$senderLabel} enviou uma nova mensagem no chat. Acesse a plataforma segura para visualizar.";

        $stmt = $this->db->prepare("
            INSERT INTO chat_notifications_log (
                clinic_id, conversation_id, message_id, recipient_type, recipient_id,
                channel, title, body_preview, contains_clinical_data, status, sent_at
            ) VALUES (
                :clinic_id, :conversation_id, :message_id, :recipient_type, :recipient_id,
                'in_app', :title, :body_preview, 0, 'sent', CURRENT_TIMESTAMP
            )
        ");
        $stmt->execute([
            'clinic_id' => $clinicId,
            'conversation_id' => $conversationId,
            'message_id' => $messageId,
            'recipient_type' => $recipientType,
            'recipient_id' => $recipientId,
            'title' => $title,
            'body_preview' => $body,
        ]);
    }
}
