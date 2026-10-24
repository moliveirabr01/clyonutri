<?php

declare(strict_types=1);

namespace ClyoNutri\Domain\Storage;

use ClyoNutri\Core\Auth\TenantContext;
use ClyoNutri\Core\Database\Connection;
use ClyoNutri\Core\Security\TenantGuard;
use ClyoNutri\Core\Security\TenantSecurityException;
use InvalidArgumentException;
use PDO;

class SecureClinicalStorageService
{
    private PDO $db;
    private string $storageBasePath;

    public const ALLOWED_MIME_TYPES = [
        'image/jpeg' => 'jpg',
        'image/png' => 'png',
        'image/webp' => 'webp',
        'application/pdf' => 'pdf',
    ];

    public const DANGEROUS_EXTENSIONS = [
        'php', 'phtml', 'php3', 'php4', 'php5', 'phar', 'sh', 'bash', 'exe', 'bat', 'cmd', 'js', 'html', 'htm', 'py', 'pl'
    ];

    public const MAX_FILE_SIZE_BYTES = 10 * 1024 * 1024; // 10MB

    public function __construct(?PDO $db = null, ?string $storageBasePath = null)
    {
        $this->db = $db ?? Connection::getInstance();
        $this->storageBasePath = $storageBasePath ?? sys_get_temp_dir() . '/clyonutri_private_storage';

        if (!is_dir($this->storageBasePath)) {
            @mkdir($this->storageBasePath, 0750, true);
        }
    }

    /**
     * Armazena de forma segura e privada um arquivo clínico (exame ou anexo).
     */
    public function storeAttachment(
        int $patientId,
        int $professionalId,
        string $originalName,
        string $fileContent,
        string $declaredMime
    ): array {
        $clinicId = TenantContext::getClinicId();
        TenantGuard::assertPatientBelongsToClinic($patientId, $clinicId, $this->db);
        TenantGuard::assertProfessionalBelongsToClinic($professionalId, $clinicId, $this->db);

        // 1. Validação de tamanho
        $sizeBytes = strlen($fileContent);
        if ($sizeBytes === 0 || $sizeBytes > self::MAX_FILE_SIZE_BYTES) {
            throw new InvalidArgumentException("Tamanho de arquivo inválido. O arquivo deve ter entre 1 byte e 10MB.");
        }

        // 2. Validação estrita de extensão e MIME
        $ext = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));
        if (in_array($ext, self::DANGEROUS_EXTENSIONS, true)) {
            throw new InvalidArgumentException("Segurança: extensão de arquivo '{$ext}' é terminantemente proibida.");
        }

        if (!array_key_exists($declaredMime, self::ALLOWED_MIME_TYPES)) {
            throw new InvalidArgumentException("Segurança: tipo MIME '{$declaredMime}' não é permitido para arquivos clínicos.");
        }

        // 3. Gerar nome e hash criptográfico seguro
        $hashSha256 = hash('sha256', $fileContent);
        $safeFileName = bin2hex(random_bytes(16)) . '.' . self::ALLOWED_MIME_TYPES[$declaredMime];
        $targetDir = $this->storageBasePath . "/clinic_{$clinicId}/patient_{$patientId}";

        if (!is_dir($targetDir)) {
            @mkdir($targetDir, 0750, true);
        }

        $fullPath = $targetDir . '/' . $safeFileName;
        file_put_contents($fullPath, $fileContent);

        // 4. Inserir metadados no banco
        $stmt = $this->db->prepare("
            INSERT INTO clinical_attachments (
                clinic_id, patient_id, professional_id, original_name,
                mime_type, file_size_bytes, storage_path, hash_sha256, is_private
            ) VALUES (
                :clinic_id, :patient_id, :professional_id, :orig_name,
                :mime, :size, :path, :hash, 1
            )
        ");

        $stmt->execute([
            'clinic_id' => $clinicId,
            'patient_id' => $patientId,
            'professional_id' => $professionalId,
            'orig_name' => $originalName,
            'mime' => $declaredMime,
            'size' => $sizeBytes,
            'path' => $fullPath,
            'hash' => $hashSha256,
        ]);

        $attachmentId = (int)$this->db->lastInsertId();

        // 5. Auditoria de segurança sem PII
        $aStmt = $this->db->prepare("
            INSERT INTO audit_logs (clinic_id, action, resource, resource_id, details)
            VALUES (:clinic_id, 'CLINICAL_FILE_STORED', 'clinical_attachments', :res_id, :details)
        ");
        $aStmt->execute([
            'clinic_id' => $clinicId,
            'res_id' => (string)$attachmentId,
            'details' => json_encode([
                'patient_id' => $patientId,
                'mime_type' => $declaredMime,
                'size_bytes' => $sizeBytes,
                'hash_sha256' => $hashSha256,
            ], JSON_UNESCAPED_UNICODE),
        ]);

        return [
            'id' => $attachmentId,
            'original_name' => $originalName,
            'hash_sha256' => $hashSha256,
            'file_size_bytes' => $sizeBytes,
            'storage_path' => $fullPath,
        ];
    }

    /**
     * Recupera arquivo com autorização estrita multi-tenant.
     */
    public function getAttachmentContent(int $attachmentId): array
    {
        $clinicId = TenantContext::getClinicId();

        $stmt = $this->db->prepare("SELECT * FROM clinical_attachments WHERE id = :id AND clinic_id = :clinic_id");
        $stmt->execute(['id' => $attachmentId, 'clinic_id' => $clinicId]);
        $att = $stmt->fetch();

        if (!$att) {
            throw new TenantSecurityException("Acesso negado: anexo não encontrado nesta clínica.", 403);
        }

        TenantGuard::assertPatientBelongsToClinic((int)$att['patient_id'], $clinicId, $this->db);

        if (!file_exists($att['storage_path'])) {
            throw new InvalidArgumentException("Arquivo físico não encontrado no storage privado.");
        }

        return [
            'metadata' => $att,
            'content' => file_get_contents($att['storage_path']),
        ];
    }
}
