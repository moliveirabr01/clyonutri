<?php

declare(strict_types=1);

namespace ClyoNutri\Domain\Helpdesk;

use ClyoNutri\Core\Database\Connection;
use InvalidArgumentException;
use PDO;
use RuntimeException;

class HelpdeskAttachmentService
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
        $this->storageDir = $storageDir ?? (__DIR__ . '/../../../storage/app/helpdesk_attachments');

        if (!is_dir($this->storageDir)) {
            @mkdir($this->storageDir, 0750, true);
        }
    }

    /**
     * Valida e armazena um arquivo anexado a um chamado de suporte.
     *
     * @param int $ticketId
     * @param int $userId
     * @param array $fileInfo Ex: $_FILES['attachment']
     * @param int|null $messageId
     * @return array Metadados do anexo registrado
     */
    public function storeAttachment(int $ticketId, int $userId, array $fileInfo, ?int $messageId = null): array
    {
        if (empty($fileInfo['tmp_name']) || !file_exists($fileInfo['tmp_name'])) {
            throw new InvalidArgumentException('Nenhum arquivo enviado ou upload corrompido.');
        }

        if (($fileInfo['error'] ?? UPLOAD_ERR_OK) !== UPLOAD_ERR_OK) {
            throw new RuntimeException('Falha no upload do arquivo (código de erro: ' . ($fileInfo['error'] ?? 0) . ').');
        }

        $size = (int)($fileInfo['size'] ?? 0);
        if ($size <= 0 || $size > self::MAX_FILE_SIZE) {
            throw new InvalidArgumentException('Tamanho de arquivo excede o limite permitido de 10 MB.');
        }

        // Detectar MIME real via finfo
        $finfo = finfo_open(FILEINFO_MIME_TYPE);
        $detectedMime = finfo_file($finfo, $fileInfo['tmp_name']);
        if (PHP_VERSION_ID < 80500 && is_resource($finfo)) {
            finfo_close($finfo);
        }

        if (!isset(self::ALLOWED_MIME_TYPES[$detectedMime])) {
            throw new InvalidArgumentException(
                "Tipo de arquivo não permitido ('{$detectedMime}'). Formatos aceitos: PNG, JPG e PDF."
            );
        }

        $origName = basename((string)($fileInfo['name'] ?? 'arquivo'));
        // Sanitizar nome original removendo caracteres de controle
        $sanitizedOrigName = preg_replace('/[^\w\.\-\(\) ]+/u', '_', $origName);
        $ext = self::ALLOWED_MIME_TYPES[$detectedMime];
        $storedFilename = sprintf('att_%d_%s_%s.%s', $ticketId, time(), bin2hex(random_bytes(8)), $ext);
        $destinationPath = $this->storageDir . DIRECTORY_SEPARATOR . $storedFilename;

        // Salvar em disco fora do webroot
        $moved = false;
        if (is_uploaded_file($fileInfo['tmp_name'])) {
            $moved = move_uploaded_file($fileInfo['tmp_name'], $destinationPath);
        } else {
            // Em testes ou chamadas diretas
            $moved = copy($fileInfo['tmp_name'], $destinationPath);
        }

        if (!$moved) {
            throw new RuntimeException('Não foi possível persistir o anexo no armazenamento seguro.');
        }

        // Registrar no banco
        $stmt = $this->db->prepare("
            INSERT INTO helpdesk_attachments 
            (ticket_id, message_id, file_name, stored_path, mime_type, file_size, uploaded_by_user_id, created_at)
            VALUES (:tid, :mid, :fname, :path, :mime, :size, :uid, CURRENT_TIMESTAMP)
        ");
        $stmt->execute([
            'tid' => $ticketId,
            'mid' => $messageId,
            'fname' => $sanitizedOrigName,
            'path' => $destinationPath,
            'mime' => $detectedMime,
            'size' => $size,
            'uid' => $userId,
        ]);

        $attachmentId = (int)$this->db->lastInsertId();

        return [
            'id' => $attachmentId,
            'ticket_id' => $ticketId,
            'file_name' => $sanitizedOrigName,
            'mime_type' => $detectedMime,
            'file_size' => $size,
            'stored_path' => $destinationPath,
        ];
    }

    /**
     * Recupera um anexo garantindo autorização de acesso.
     *
     * @param int $attachmentId
     * @param int $userId ID do usuário solicitante
     * @param int $clinicId ID da clínica ativa
     * @param string $userRole Papel do usuário (admin, operator, nutritionist, patient)
     * @return array Dados do anexo e caminho absoluto
     */
    public function getAttachmentForDownload(int $attachmentId, int $userId, int $clinicId, string $userRole): array
    {
        $stmt = $this->db->prepare("
            SELECT a.*, t.clinic_id, t.user_id as ticket_author_id, t.protocol
            FROM helpdesk_attachments a
            JOIN helpdesk_tickets t ON t.id = a.ticket_id
            WHERE a.id = :id
            LIMIT 1
        ");
        $stmt->execute(['id' => $attachmentId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$row) {
            throw new RuntimeException('Anexo não encontrado.');
        }

        // Autorização estrita:
        // 1. Membro da mesma clínica que é autor do chamado OU
        // 2. Operador de suporte / Admin global OU
        // 3. Usuário que fez o upload
        $isAuthor = ((int)$row['ticket_author_id'] === $userId);
        $isSameClinic = ((int)$row['clinic_id'] === $clinicId);
        $isSupportOperator = in_array($userRole, ['admin', 'operator', 'support_agent'], true);

        if (!$isSupportOperator && !($isSameClinic && $isAuthor) && ((int)$row['uploaded_by_user_id'] !== $userId)) {
            throw new RuntimeException('Acesso negado: Você não tem autorização para visualizar este anexo.');
        }

        if (!file_exists($row['stored_path'])) {
            throw new RuntimeException('Arquivo de anexo não encontrado no disco seguro.');
        }

        return $row;
    }

    /**
     * Retorna os anexos associados a um ticket.
     */
    public function getAttachmentsByTicket(int $ticketId): array
    {
        $stmt = $this->db->prepare("
            SELECT id, ticket_id, message_id, file_name, mime_type, file_size, uploaded_by_user_id, created_at
            FROM helpdesk_attachments
            WHERE ticket_id = :tid
            ORDER BY id ASC
        ");
        $stmt->execute(['tid' => $ticketId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }
}
