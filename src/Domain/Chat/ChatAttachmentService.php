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

class ChatAttachmentService
{
    private PDO $db;
    private string $storageDir;

    private const ALLOWED_MIME_TYPES = [
        'image/png' => 'png',
        'image/jpeg' => 'jpg',
        'application/pdf' => 'pdf',
    ];

    private const MAX_FILE_SIZE = 10 * 1024 * 1024; // 10MB

    public function __construct(?PDO $db = null, ?string $storageDir = null)
    {
        $this->db = $db ?? Connection::getInstance();
        $this->storageDir = $storageDir ?? (__DIR__ . '/../../../storage/app/chat_attachments');

        if (!is_dir($this->storageDir)) {
            @mkdir($this->storageDir, 0750, true);
        }
    }

    /**
     * Valida, armazena em disco fora do webroot e registra anexo privado no banco de dados.
     */
    public function storeAttachment(
        int $conversationId,
        string $uploaderType,
        int $uploaderId,
        array $fileInfo,
        ?int $messageId = null
    ): array {
        $clinicId = TenantContext::getClinicId();
        TenantGuard::assertConversationBelongsToClinic($conversationId, $clinicId, $this->db);

        // Validar vínculo do uploader na conversa
        $this->assertUploaderIsParticipant($conversationId, $uploaderType, $uploaderId);

        if (empty($fileInfo['tmp_name']) || !file_exists($fileInfo['tmp_name'])) {
            throw new InvalidArgumentException('Arquivo anexado ausente ou corrompido.');
        }

        if (($fileInfo['error'] ?? UPLOAD_ERR_OK) !== UPLOAD_ERR_OK) {
            throw new RuntimeException('Falha no upload do arquivo (código: ' . ($fileInfo['error'] ?? 0) . ').');
        }

        $size = (int)($fileInfo['size'] ?? 0);
        if ($size <= 0 || $size > self::MAX_FILE_SIZE) {
            throw new InvalidArgumentException('Tamanho de anexo inválido. Limite máximo permitido: 10 MB.');
        }

        // Detectar MIME real via libmagic (finfo)
        $finfo = finfo_open(FILEINFO_MIME_TYPE);
        $detectedMime = finfo_file($finfo, $fileInfo['tmp_name']);
        if (PHP_VERSION_ID < 80500 && is_resource($finfo)) {
            finfo_close($finfo);
        }

        if (!isset(self::ALLOWED_MIME_TYPES[$detectedMime])) {
            throw new InvalidArgumentException(
                "Tipo de anexo '{$detectedMime}' não permitido por segurança. Formatos aceitos: PNG, JPG e PDF."
            );
        }

        $origName = basename((string)($fileInfo['name'] ?? 'anexo'));
        $sanitizedOrigName = preg_replace('/[^\w\.\-\(\) ]+/u', '_', $origName);
        $ext = self::ALLOWED_MIME_TYPES[$detectedMime];
        $storedFilename = sprintf('chat_%d_%s_%s.%s', $conversationId, time(), bin2hex(random_bytes(8)), $ext);
        $destinationPath = $this->storageDir . DIRECTORY_SEPARATOR . $storedFilename;

        $sha256 = hash_file('sha256', $fileInfo['tmp_name']);

        $moved = false;
        if (is_uploaded_file($fileInfo['tmp_name'])) {
            $moved = move_uploaded_file($fileInfo['tmp_name'], $destinationPath);
        } else {
            $moved = copy($fileInfo['tmp_name'], $destinationPath);
        }

        if (!$moved) {
            throw new RuntimeException('Falha interna ao persistir o arquivo no armazenamento seguro.');
        }

        $stmt = $this->db->prepare("
            INSERT INTO chat_attachments (
                clinic_id, conversation_id, message_id, original_name, stored_name,
                mime_type, size_bytes, sha256_hash, storage_path, uploaded_by_type, uploaded_by_id, created_at
            ) VALUES (
                :clinic_id, :conversation_id, :message_id, :original_name, :stored_name,
                :mime_type, :size_bytes, :sha256_hash, :storage_path, :uploaded_by_type, :uploaded_by_id, CURRENT_TIMESTAMP
            )
        ");
        $stmt->execute([
            'clinic_id' => $clinicId,
            'conversation_id' => $conversationId,
            'message_id' => $messageId,
            'original_name' => $sanitizedOrigName,
            'stored_name' => $storedFilename,
            'mime_type' => $detectedMime,
            'size_bytes' => $size,
            'sha256_hash' => $sha256,
            'storage_path' => $destinationPath,
            'uploaded_by_type' => $uploaderType,
            'uploaded_by_id' => $uploaderId,
        ]);

        $attachmentId = (int)$this->db->lastInsertId();

        return [
            'id' => $attachmentId,
            'conversation_id' => $conversationId,
            'message_id' => $messageId,
            'original_name' => $sanitizedOrigName,
            'mime_type' => $detectedMime,
            'size_bytes' => $size,
            'sha256_hash' => $sha256,
            'storage_path' => $destinationPath,
        ];
    }

    /**
     * Valida autorização estrita e retorna o anexo para download seguro.
     */
    public function getAttachmentForDownload(int $attachmentId, string $requestorType, int $requestorId): array
    {
        $clinicId = TenantContext::getClinicId();

        $stmt = $this->db->prepare("
            SELECT a.*, c.patient_id, c.professional_id
            FROM chat_attachments a
            JOIN chat_conversations c ON c.id = a.conversation_id
            WHERE a.id = :id AND a.clinic_id = :clinic_id
            LIMIT 1
        ");
        $stmt->execute(['id' => $attachmentId, 'clinic_id' => $clinicId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$row) {
            throw new TenantSecurityException("Anexo não encontrado ou acesso não autorizado (403 Forbidden).", 403);
        }

        // Validar se o solicitante é participante legítimo da conversa
        $this->assertUploaderIsParticipant((int)$row['conversation_id'], $requestorType, $requestorId);

        if (!file_exists($row['storage_path'])) {
            throw new RuntimeException("Arquivo físico não encontrado no repositório de armazenamento.");
        }

        return [
            'file_path' => $row['storage_path'],
            'file_name' => $row['original_name'],
            'mime_type' => $row['mime_type'],
            'size_bytes' => (int)$row['size_bytes'],
            'sha256_hash' => $row['sha256_hash'],
        ];
    }

    /**
     * Garante que apenas o paciente vinculado ou profissional vinculado tenham acesso.
     */
    public function assertUploaderIsParticipant(int $conversationId, string $type, int $id): void
    {
        $stmt = $this->db->prepare("
            SELECT patient_id, professional_id, clinic_id 
            FROM chat_conversations 
            WHERE id = :id LIMIT 1
        ");
        $stmt->execute(['id' => $conversationId]);
        $conv = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$conv) {
            throw new TenantSecurityException("Conversa #{$conversationId} não encontrada.", 404);
        }

        if ($type === 'patient') {
            if ((int)$conv['patient_id'] !== $id) {
                throw new TenantSecurityException("Acesso negado: o paciente #{$id} não participa desta conversa (403).", 403);
            }
        } elseif ($type === 'professional') {
            if ((int)$conv['professional_id'] !== $id) {
                throw new TenantSecurityException("Acesso negado: o profissional #{$id} não participa desta conversa (403).", 403);
            }
        } else {
            throw new TenantSecurityException("Tipo de participante '{$type}' inválido.", 403);
        }
    }
}
