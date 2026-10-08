<?php

declare(strict_types=1);

namespace ClyoNutri\Domain\Chat;

use ClyoNutri\Core\Auth\TenantContext;
use ClyoNutri\Core\Database\Connection;
use PDO;

class ChatService
{
    private PDO $db;

    public function __construct(?PDO $db = null)
    {
        $this->db = $db ?? Connection::getInstance();
    }

    public function getOrCreateConversation(int $patientId, int $professionalId): int
    {
        $clinicId = TenantContext::getClinicId();
        \ClyoNutri\Core\Security\TenantGuard::assertPatientBelongsToClinic($patientId, $clinicId, $this->db);
        \ClyoNutri\Core\Security\TenantGuard::assertProfessionalBelongsToClinic($professionalId, $clinicId, $this->db);

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
            INSERT INTO chat_conversations (clinic_id, patient_id, professional_id)
            VALUES (:clinic_id, :patient_id, :professional_id)
        ");
        $ins->execute([
            'clinic_id' => $clinicId,
            'patient_id' => $patientId,
            'professional_id' => $professionalId,
        ]);

        return (int)$this->db->lastInsertId();
    }

    public function sendMessage(int $conversationId, string $senderType, int $senderId, string $message, ?string $attachment = null): array
    {
        $clinicId = TenantContext::getClinicId();
        \ClyoNutri\Core\Security\TenantGuard::assertConversationBelongsToClinic($conversationId, $clinicId, $this->db);

        $stmt = $this->db->prepare("
            INSERT INTO chat_messages (conversation_id, clinic_id, sender_type, sender_id, message, attachment_path)
            VALUES (:conv_id, :clinic_id, :sender_type, :sender_id, :message, :attachment)
        ");

        $stmt->execute([
            'conv_id' => $conversationId,
            'clinic_id' => $clinicId,
            'sender_type' => $senderType,
            'sender_id' => $senderId,
            'message' => trim($message),
            'attachment' => $attachment,
        ]);

        $msgId = (int)$this->db->lastInsertId();

        // Atualizar timestamp da conversa
        $up = $this->db->prepare("UPDATE chat_conversations SET updated_at = CURRENT_TIMESTAMP WHERE id = :id");
        $up->execute(['id' => $conversationId]);

        return [
            'id' => $msgId,
            'conversation_id' => $conversationId,
            'sender_type' => $senderType,
            'message' => $message,
            'created_at' => date('c'),
        ];
    }

    public function getMessages(int $conversationId, int $limit = 50): array
    {
        $clinicId = TenantContext::getClinicId();
        \ClyoNutri\Core\Security\TenantGuard::assertConversationBelongsToClinic($conversationId, $clinicId, $this->db);

        $stmt = $this->db->prepare("
            SELECT * FROM chat_messages
            WHERE conversation_id = :conv_id AND clinic_id = :clinic_id
            ORDER BY created_at ASC
            LIMIT :limit
        ");
        $stmt->bindValue(':conv_id', $conversationId, PDO::PARAM_INT);
        $stmt->bindValue(':clinic_id', $clinicId, PDO::PARAM_INT);
        $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
        $stmt->execute();

        return $stmt->fetchAll();
    }

    public function markAsRead(int $conversationId, string $currentReaderType): void
    {
        $clinicId = TenantContext::getClinicId();
        // Marca como lidas as mensagens enviadas pela outra ponta
        $otherType = $currentReaderType === 'professional' ? 'patient' : 'professional';

        $stmt = $this->db->prepare("
            UPDATE chat_messages
            SET read_at = CURRENT_TIMESTAMP
            WHERE conversation_id = :conv_id AND clinic_id = :clinic_id AND sender_type = :other_type AND read_at IS NULL
        ");
        $stmt->execute([
            'conv_id' => $conversationId,
            'clinic_id' => $clinicId,
            'other_type' => $otherType,
        ]);
    }
}
