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

        if (($fileInfo['error'] ?? UPLOAD_ERR_OK) !== UPLOAD_ERR_OK) {
            $tooBig = in_array((int)$fileInfo['error'], [UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE], true);
            throw new ChatException($tooBig ? 'O arquivo passa de 10 MB.' : 'O arquivo não chegou inteiro. Tente de novo.', 'ATTACHMENT_INVALID', 422);
        }
        if (empty($fileInfo['tmp_name']) || !file_exists($fileInfo['tmp_name'])) {
            throw new ChatException('O arquivo não chegou inteiro. Tente de novo.', 'ATTACHMENT_INVALID', 422);
        }

        $size = (int)($fileInfo['size'] ?? 0);
        if ($size <= 0 || $size > self::MAX_FILE_SIZE) {
            throw new ChatException('O arquivo precisa ter até 10 MB.', 'ATTACHMENT_INVALID', 422);
        }

        // Detectar MIME real via libmagic (finfo)
        $finfo = finfo_open(FILEINFO_MIME_TYPE);
        $detectedMime = finfo_file($finfo, $fileInfo['tmp_name']);
        if (PHP_VERSION_ID < 80500 && is_resource($finfo)) {
            finfo_close($finfo);
        }

        if (!isset(self::ALLOWED_MIME_TYPES[$detectedMime])) {
            throw new ChatException('Formato não aceito. Envie PNG, JPG ou PDF.', 'ATTACHMENT_INVALID', 422);
        }

        $origName = basename(str_replace('\\', '/', (string)($fileInfo['name'] ?? 'anexo')));
        $sanitizedOrigName = mb_substr((string)preg_replace('/[^\w\.\-\(\) ]+/u', '_', $origName), 0, 120) ?: 'anexo';
        $ext = self::ALLOWED_MIME_TYPES[$detectedMime];
        $storedFilename = sprintf('chat_%d_%s_%s.%s', $conversationId, time(), bin2hex(random_bytes(8)), $ext);
        $destinationPath = $this->storageDir . DIRECTORY_SEPARATOR . $storedFilename;

        $sha256 = hash_file('sha256', $fileInfo['tmp_name']);

        $moved = \ClyoNutri\Core\Http\UploadMover::move((string)$fileInfo['tmp_name'], $destinationPath); // CR-NI2-011

        if (!$moved) {
            throw new RuntimeException('Falha interna ao persistir o arquivo no armazenamento seguro.');
        }
        @chmod($destinationPath, 0640);

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
            throw ChatException::attachmentNotFound();
        }

        // Só os participantes da conversa (sem dizer a quem pertence).
        if (($requestorType === 'patient' && (int)$row['patient_id'] !== $requestorId)
            || ($requestorType === 'professional' && (int)$row['professional_id'] !== $requestorId)
            || !in_array($requestorType, ['patient', 'professional'], true)) {
            throw ChatException::attachmentNotFound();
        }

        if (!is_file($row['storage_path'])) {
            throw ChatException::attachmentNotFound();
        }

        (new \ClyoNutri\Core\Audit\AuditLogger($this->db))->log('CHAT_ATTACHMENT_DOWNLOADED', 'chat_attachment', (string)$attachmentId, [
            'conversation_id' => (int)$row['conversation_id'], 'actor_type' => $requestorType, 'actor_id' => $requestorId,
        ]);

        return [
            'file_path' => $row['storage_path'],
            'file_name' => $row['original_name'],
            'mime_type' => $row['mime_type'],
            'size_bytes' => (int)$row['size_bytes'],
            'sha256_hash' => $row['sha256_hash'],
        ];
    }

    /**
     * NI2-138 (A3): link de download curto e assinado para o app da paciente (o Bearer não vai num link aberto pelo
     * navegador ou pelo sistema). Vale 2 minutos, só para esta paciente, este anexo e esta clínica.
     */
    public function issuePatientDownloadToken(int $attachmentId, int $patientId): string
    {
        $clinicId = TenantContext::getClinicId();
        $st = $this->db->prepare('SELECT a.id FROM chat_attachments a JOIN chat_conversations c ON c.id = a.conversation_id
            WHERE a.id = ? AND a.clinic_id = ? AND c.patient_id = ?');
        $st->execute([$attachmentId, $clinicId, $patientId]);
        if (!$st->fetchColumn()) {
            throw ChatException::attachmentNotFound();
        }
        $key = self::tokenKey();
        if ($key === null) {
            throw new ChatException('Download indisponível no momento (configuração do servidor).', 'DOWNLOAD_UNAVAILABLE', 503);
        }
        // Sem pontos no link: servidores que tratam "x.y" como arquivo estático (php -S, alguns proxies) não o confundem.
        $body = 'a-' . $attachmentId . '-' . $patientId . '-' . $clinicId . '-' . (time() + 120);
        return $body . '-' . rtrim(strtr(base64_encode(hash_hmac('sha256', $body, $key, true)), '+/', '-_'), '=');
    }

    /** @return array{attachment_id: int, patient_id: int, clinic_id: int} */
    public static function verifyPatientDownloadToken(string $token): array
    {
        if (!preg_match('/^(a-(\d{1,10})-(\d{1,10})-(\d{1,10})-(\d{1,12}))-([A-Za-z0-9_-]{43})$/D', $token, $m)) {
            throw ChatException::attachmentNotFound();
        }
        $key = self::tokenKey();
        $expected = $key !== null ? rtrim(strtr(base64_encode(hash_hmac('sha256', $m[1], $key, true)), '+/', '-_'), '=') : '';
        if ($key === null || !hash_equals($expected, $m[6]) || (int)$m[5] < time()) {
            throw new ChatException('Este link de anexo expirou. Toque no anexo de novo.', 'ATTACHMENT_NOT_FOUND', 404);
        }
        return ['attachment_id' => (int)$m[2], 'patient_id' => (int)$m[3], 'clinic_id' => (int)$m[4]];
    }

    private static function tokenKey(): ?string
    {
        $appKey = getenv('APP_KEY') ?: ($_ENV['APP_KEY'] ?? '');
        return strlen((string)$appKey) < 16 ? null : hash_hmac('sha256', 'clyonutri-chat-anexo-v1', (string)$appKey, true);
    }

    /** Cabeçalhos de download: PDF sempre como arquivo (não abre no navegador), imagens inline, tudo isolado. */
    public static function sendDownloadHeaders(array $fileData): void
    {
        $name = preg_replace('/["\\\r\n]+/', '_', (string)$fileData['file_name']);
        $disposition = $fileData['mime_type'] === 'application/pdf' ? 'attachment' : 'inline';
        header('Content-Type: ' . $fileData['mime_type']);
        header('Content-Length: ' . $fileData['size_bytes']);
        header('Content-Disposition: ' . $disposition . '; filename="' . $name . '"; filename*=UTF-8\'\'' . rawurlencode((string)$fileData['file_name']));
        header('X-Content-Type-Options: nosniff');
        header("Content-Security-Policy: sandbox; default-src 'none'");
        header('Cache-Control: private, no-store');
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

        // NI2-138 (M5): mensagem sem ids; a API responde 404 "Conversa não encontrada".
        if (!$conv
            || ($type === 'patient' && (int)$conv['patient_id'] !== $id)
            || ($type === 'professional' && (int)$conv['professional_id'] !== $id)
            || !in_array($type, ['patient', 'professional'], true)) {
            throw new TenantSecurityException('Conversa não encontrada.', 404);
        }
    }
}
