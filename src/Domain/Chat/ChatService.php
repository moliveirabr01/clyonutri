<?php

declare(strict_types=1);

namespace ClyoNutri\Domain\Chat;

use ClyoNutri\Core\Audit\AuditLogger;
use ClyoNutri\Core\Auth\TenantContext;
use ClyoNutri\Core\Database\Connection;
use ClyoNutri\Core\Database\SchemaInfo;
use ClyoNutri\Core\Security\TenantGuard;
use ClyoNutri\Core\Security\TenantSecurityException;
use ClyoNutri\Domain\Notification\ChatNotificationDispatcher;
use InvalidArgumentException;
use PDO;

/**
 * Canal seguro de mensagens entre paciente e profissional (NI2-189, revisto na NI2-138).
 *
 * - Conversa só abre com vínculo ativo (PatientLinkService); enviar exige vínculo, conversa, paciente e
 *   profissional ativos (ChatAccessPolicy). Sem isso, o histórico fica só para leitura.
 * - Idempotência garantida pelo banco (índice único conversa + chave): a mesma chave nunca grava duas vezes.
 * - Limites no servidor: 2.000 caracteres por mensagem e 20 envios por minuto por remetente na conversa.
 * - Respostas com lista fechada de campos (sem caminho de arquivo, ids internos nem chave de idempotência).
 * - Datas no fuso da aplicação, devolvidas em ISO 8601 com offset.
 */
class ChatService
{
    public const MAX_MESSAGE_CHARS = 2000;
    public const RATE_LIMIT_PER_MINUTE = 20;

    private PDO $db;
    private ChatAttachmentService $attachmentService;

    public function __construct(?PDO $db = null, ?ChatAttachmentService $attachmentService = null, private ?ChatNotificationDispatcher $notifier = null)
    {
        $this->db = $db ?? Connection::getInstance();
        $this->attachmentService = $attachmentService ?? new ChatAttachmentService($this->db);
    }

    private function now(): string
    {
        return date('Y-m-d H:i:s');
    }

    private function iso(?string $sql): ?string
    {
        if ($sql === null || $sql === '') {
            return null;
        }
        try {
            return (new \DateTimeImmutable($sql, new \DateTimeZone(date_default_timezone_get())))->format(DATE_ATOM);
        } catch (\Exception) {
            return null;
        }
    }

    /**
     * Obtém ou cria a conversa entre paciente e profissional da mesma clínica. Criar exige vínculo ativo (NI2-138, A1).
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
        $stmt->execute(['patient_id' => $patientId, 'professional_id' => $professionalId, 'clinic_id' => $clinicId]);
        $convId = $stmt->fetchColumn();
        if ($convId) {
            return (int)$convId;
        }

        $links = new PatientLinkService($this->db);
        if ($links->available() && $links->activeLink($clinicId, $patientId, $professionalId) === null) {
            throw new ChatException('O chat com esta paciente ainda não foi liberado. Libere no perfil da paciente (vínculo de acompanhamento).', 'NO_ACTIVE_LINK', 403);
        }

        $now = $this->now();
        $ins = $this->db->prepare("
            INSERT INTO chat_conversations (clinic_id, patient_id, professional_id, status, created_at, updated_at)
            VALUES (:clinic_id, :patient_id, :professional_id, 'active', :now, :now2)
        ");
        $ins->execute(['clinic_id' => $clinicId, 'patient_id' => $patientId, 'professional_id' => $professionalId, 'now' => $now, 'now2' => $now]);
        $id = (int)$this->db->lastInsertId();
        (new AuditLogger($this->db))->log('CHAT_CONVERSATION_OPENED', 'chat_conversation', (string)$id, [
            'patient_id' => $patientId, 'professional_id' => $professionalId,
        ] + $this->actor());
        return $id;
    }

    /**
     * Retorna detalhes da conversa assegurando isolamento de tenant.
     */
    public function getConversation(int $conversationId): array
    {
        $clinicId = TenantContext::getClinicId();
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
            throw new TenantSecurityException('Conversa não encontrada.', 404);
        }
        return $conv;
    }

    /** Pode enviar nesta conversa? (A2) */
    public function accessState(int $conversationId, string $viewer): array
    {
        $st = $this->db->prepare('SELECT * FROM chat_conversations WHERE id = ? AND clinic_id = ?');
        $st->execute([$conversationId, TenantContext::getClinicId()]);
        $conv = $st->fetch(PDO::FETCH_ASSOC);
        if (!$conv) {
            throw ChatException::conversationNotFound();
        }
        return (new ChatAccessPolicy($this->db))->evaluate($conv, $viewer);
    }

    /**
     * Envia mensagem com idempotência (índice único), limites, bloqueio por vínculo inativo e anexo opcional.
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
        $this->attachmentService->assertUploaderIsParticipant($conversationId, $senderType, $senderId);

        $cleanMsg = trim($message);
        $hasFile = $attachmentFile !== null && !empty($attachmentFile['tmp_name']);
        if ($cleanMsg === '' && !$hasFile) {
            throw new ChatException('Escreva uma mensagem ou anexe um arquivo.', 'VALIDATION_ERROR', 422);
        }
        if (mb_strlen($cleanMsg) > self::MAX_MESSAGE_CHARS) {
            throw new ChatException('A mensagem pode ter até ' . number_format(self::MAX_MESSAGE_CHARS, 0, ',', '.') . ' caracteres.', 'MESSAGE_TOO_LONG', 422);
        }
        $idempotencyKey = $idempotencyKey !== null ? trim($idempotencyKey) : '';
        if ($idempotencyKey === '') {
            $idempotencyKey = bin2hex(random_bytes(16));
        }
        if (strlen($idempotencyKey) > 64 || !preg_match('/^[A-Za-z0-9_.:-]+$/', $idempotencyKey)) {
            throw new ChatException('Chave de envio inválida.', 'VALIDATION_ERROR', 422);
        }

        // Reenvio da mesma mensagem (retentativa da rede): devolve a que já existe, sem regravar nem notificar.
        if ($existing = $this->findByKey($conversationId, $idempotencyKey)) {
            return $this->present($existing) + ['is_duplicate' => true, 'replayed' => true];
        }

        $full = $this->db->prepare('SELECT * FROM chat_conversations WHERE id = ? AND clinic_id = ?');
        $full->execute([$conversationId, $clinicId]);
        $conversation = $full->fetch(PDO::FETCH_ASSOC) ?: $conversation;
        $full->closeCursor(); // SQLite: cursor aberto segura a leitura e trava a escrita logo abaixo
        unset($full);
        $access = (new ChatAccessPolicy($this->db))->evaluate($conversation, $senderType);
        if (!$access['can_send']) {
            throw new ChatException((string)$access['reason'], 'LINK_INACTIVE', 403);
        }
        $this->assertRate($conversationId, $senderType);

        $attMeta = null;
        // Leituras antes da transação: no SQLite (WAL), ler e depois escrever na mesma transação pode falhar com
        // "database is locked" sob concorrência; começando pela escrita, a espera (busy_timeout) funciona.
        $hasAttCol = SchemaInfo::hasColumn($this->db, 'chat_messages', 'attachment_id');
        if ($hasFile) {
            $attMeta = $this->attachmentService->storeAttachment($conversationId, $senderType, $senderId, $attachmentFile);
        }
        $msgId = null;
        for ($attempt = 1; $msgId === null; $attempt++) {
            $this->db->beginTransaction();
            try {
                $msgId = $this->insertMessage($conversationId, $clinicId, $senderType, $senderId, $cleanMsg, $idempotencyKey, $attMeta, $hasAttCol);
                $this->db->commit();
            } catch (\PDOException $e) {
                $msgId = null;
                if ($this->db->inTransaction()) {
                    $this->db->rollBack();
                }
                // Corrida: outra requisição gravou a mesma chave antes (índice único) — devolve a gravada.
                if ($e->getCode() === '23000' && ($existing = $this->findByKey($conversationId, $idempotencyKey))) {
                    $this->rollbackAndCleanup($attMeta);
                    return $this->present($existing) + ['is_duplicate' => true, 'replayed' => true];
                }
                // SQLite (desenvolvimento/testes) sob escrita concorrente: tenta de novo, poucas vezes.
                if (($e->errorInfo[1] ?? null) === 5 && $attempt < 6 && $this->db->getAttribute(PDO::ATTR_DRIVER_NAME) === 'sqlite') {
                    usleep(random_int(20000, 120000));
                    if ($existing = $this->findByKey($conversationId, $idempotencyKey)) {
                        $this->rollbackAndCleanup($attMeta);
                        return $this->present($existing) + ['is_duplicate' => true, 'replayed' => true];
                    }
                    continue;
                }
                $this->rollbackAndCleanup($attMeta);
                throw $e;
            } catch (\Throwable $e) {
                $this->rollbackAndCleanup($attMeta);
                throw $e;
            }
        }

        $row = $this->findById($msgId);
        (new AuditLogger($this->db))->log('CHAT_MESSAGE_SENT', 'chat_message', (string)$msgId, [
            'conversation_id' => $conversationId,
            'sender_type' => $senderType,
            'has_attachment' => $attMeta !== null,
        ] + $this->actor($senderType, $senderId));

        $recipientType = $senderType === 'professional' ? 'patient' : 'professional';
        $recipientId = $senderType === 'professional' ? (int)$conversation['patient_id'] : (int)$conversation['professional_id'];
        ($this->notifier ?? new ChatNotificationDispatcher($this->db))->notifyNewMessage($clinicId, $conversationId, $msgId, $recipientType, $recipientId);

        return $this->present($row) + ['is_duplicate' => false];
    }

    private function insertMessage(int $conversationId, int $clinicId, string $senderType, int $senderId, string $cleanMsg, string $idempotencyKey, ?array $attMeta, bool $hasAttCol): int
    {
        $now = $this->now();
        $stmt = $this->db->prepare('
            INSERT INTO chat_messages (
                conversation_id, clinic_id, sender_type, sender_id, message,
                attachment_path, attachment_name, attachment_mime, attachment_size,
                idempotency_key, status, created_at' . ($hasAttCol ? ', attachment_id' : '') . '
            ) VALUES (
                :conv_id, :clinic_id, :sender_type, :sender_id, :message,
                :attachment_path, :attachment_name, :attachment_mime, :attachment_size,
                :idempotency_key, \'sent\', :created_at' . ($hasAttCol ? ', :attachment_id' : '') . '
            )
        ');
        $params = [
            'conv_id' => $conversationId,
            'clinic_id' => $clinicId,
            'sender_type' => $senderType,
            'sender_id' => $senderId,
            'message' => $cleanMsg,
            'attachment_path' => $attMeta['storage_path'] ?? null,
            'attachment_name' => $attMeta['original_name'] ?? null,
            'attachment_mime' => $attMeta['mime_type'] ?? null,
            'attachment_size' => $attMeta['size_bytes'] ?? null,
            'idempotency_key' => $idempotencyKey,
            'created_at' => $now,
        ];
        if ($hasAttCol) {
            $params['attachment_id'] = $attMeta['id'] ?? null;
        }
        $stmt->execute($params);
        $msgId = (int)$this->db->lastInsertId();
        if ($attMeta !== null) {
            $this->db->prepare('UPDATE chat_attachments SET message_id = :mid WHERE id = :aid')->execute(['mid' => $msgId, 'aid' => $attMeta['id']]);
        }
        $this->db->prepare('UPDATE chat_conversations SET updated_at = :now WHERE id = :id')->execute(['now' => $now, 'id' => $conversationId]);
        return $msgId;
    }

    private function rollbackAndCleanup(?array $attMeta): void
    {
        if ($this->db->inTransaction()) {
            $this->db->rollBack();
        }
        // B2: o anexo (linha e arquivo) não fica órfão se a mensagem não foi salva.
        if ($attMeta !== null) {
            try {
                $this->db->prepare('DELETE FROM chat_attachments WHERE id = ? AND message_id IS NULL')->execute([(int)$attMeta['id']]);
            } catch (\Throwable) {
                // a rotina de guarda apaga arquivos sem registro depois de 24 horas
            }
            if (!empty($attMeta['storage_path']) && is_file($attMeta['storage_path'])) {
                @unlink($attMeta['storage_path']);
            }
        }
    }

    private function assertRate(int $conversationId, string $senderType): void
    {
        $since = date('Y-m-d H:i:s', time() - 60);
        $st = $this->db->prepare('SELECT COUNT(*), MIN(created_at) FROM chat_messages WHERE conversation_id = ? AND sender_type = ? AND created_at >= ?');
        $st->execute([$conversationId, $senderType, $since]);
        [$count, $oldest] = $st->fetch(PDO::FETCH_NUM);
        if ((int)$count >= self::RATE_LIMIT_PER_MINUTE) {
            $retry = max(1, 60 - (time() - (int)strtotime((string)$oldest)));
            throw new ChatException('Muitas mensagens em pouco tempo. Aguarde ' . $retry . ' segundos e tente de novo.', 'RATE_LIMITED', 429, ['retry_after' => $retry]);
        }
    }

    private function findByKey(int $conversationId, string $key): ?array
    {
        $st = $this->db->prepare('SELECT * FROM chat_messages WHERE conversation_id = ? AND idempotency_key = ? LIMIT 1');
        $st->execute([$conversationId, $key]);
        return $st->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    private function findById(int $id): array
    {
        $st = $this->db->prepare('SELECT * FROM chat_messages WHERE id = ?');
        $st->execute([$id]);
        return $st->fetch(PDO::FETCH_ASSOC) ?: [];
    }

    /** Lista fechada de campos de uma mensagem (M5). */
    public function present(array $m): array
    {
        $attachmentId = isset($m['attachment_id']) && $m['attachment_id'] !== null ? (int)$m['attachment_id'] : null;
        if ($attachmentId === null && !empty($m['attachment_path']) && !empty($m['id'])) {
            $a = $this->db->prepare('SELECT MIN(id) FROM chat_attachments WHERE message_id = ?');
            $a->execute([(int)$m['id']]);
            $attachmentId = ($v = $a->fetchColumn()) ? (int)$v : null;
        }
        return [
            'id' => (int)$m['id'],
            'conversation_id' => (int)$m['conversation_id'],
            'sender_type' => (string)$m['sender_type'],
            'message' => (string)($m['message'] ?? ''),
            'status' => (string)($m['status'] ?? 'sent'),
            'created_at' => $this->iso($m['created_at'] ?? null),
            'delivered_at' => $this->iso($m['delivered_at'] ?? null),
            'read_at' => $this->iso($m['read_at'] ?? null),
            'has_attachment' => $attachmentId !== null,
            'attachment' => $attachmentId !== null ? [
                'id' => $attachmentId,
                'name' => (string)($m['attachment_name'] ?? 'anexo'),
                'mime_type' => (string)($m['attachment_mime'] ?? ''),
                'size_bytes' => (int)($m['attachment_size'] ?? 0),
            ] : null,
        ];
    }

    /**
     * Consulta mensagens (compatibilidade com suítes existentes), com lista fechada de campos.
     */
    public function getMessages(int $conversationId, int $limit = 50): array
    {
        $clinicId = TenantContext::getClinicId();
        TenantGuard::assertConversationBelongsToClinic($conversationId, $clinicId, $this->db);
        $stmt = $this->db->prepare('SELECT * FROM chat_messages WHERE conversation_id = :conv_id AND clinic_id = :clinic_id ORDER BY id ASC LIMIT :limit');
        $stmt->bindValue(':conv_id', $conversationId, PDO::PARAM_INT);
        $stmt->bindValue(':clinic_id', $clinicId, PDO::PARAM_INT);
        $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
        $stmt->execute();
        return array_map(fn(array $m) => $this->present($m), $stmt->fetchAll(PDO::FETCH_ASSOC));
    }

    /**
     * Polling com cursor incremental (after_id). Ler é permitido aos participantes mesmo sem vínculo ativo;
     * `access` diz se o leitor pode enviar.
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
            $this->markAsDelivered($conversationId, $readerType);
        }

        $sql = 'SELECT m.* FROM chat_messages m WHERE m.conversation_id = :conv_id AND m.clinic_id = :clinic_id';
        $params = ['conv_id' => $conversationId, 'clinic_id' => $clinicId];
        if ($afterId !== null && $afterId > 0) {
            $sql .= ' AND m.id > :after_id';
            $params['after_id'] = $afterId;
        }
        $sql .= ' ORDER BY m.id ASC LIMIT :limit';
        $stmt = $this->db->prepare($sql);
        foreach ($params as $key => $val) {
            $stmt->bindValue(':' . $key, $val, PDO::PARAM_INT);
        }
        $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
        $stmt->execute();
        $messages = array_map(fn(array $m) => $this->present($m), $stmt->fetchAll(PDO::FETCH_ASSOC) ?: []);

        $lastId = $afterId ?? 0;
        if (!empty($messages)) {
            $lastId = (int)end($messages)['id'];
        }

        // Recibos: status atual das últimas mensagens do próprio leitor (o polling incremental só traz mensagens novas).
        $receipts = [];
        if ($readerType !== null) {
            $rs = $this->db->prepare('SELECT id, status FROM chat_messages WHERE conversation_id = ? AND clinic_id = ? AND sender_type = ? ORDER BY id DESC LIMIT 30');
            $rs->execute([$conversationId, $clinicId, $readerType]);
            $receipts = array_map(static fn($r) => ['id' => (int)$r['id'], 'status' => (string)$r['status']], $rs->fetchAll(PDO::FETCH_ASSOC));
        }

        return [
            'conversation_id' => $conversationId,
            'messages' => $messages,
            'receipts' => $receipts,
            'access' => $readerType !== null ? $this->accessState($conversationId, $readerType) : null,
            'cursor' => [
                'after_id' => $lastId,
                'count' => count($messages),
                'has_more' => count($messages) === $limit,
            ],
            // Política de polling: o cliente usa estes intervalos (não fixos), e pausa em segundo plano.
            'polling_policy' => [
                'active_window_ms' => 3000,
                'background_window_ms' => 15000,
                'idle_window_ms' => 60000,
                'max_requests_per_minute' => 60,
                'transport' => 'cursor_delta_polling',
                'zero_websockets' => true,
            ],
        ];
    }

    /**
     * Marca mensagens enviadas para o destinatário como 'delivered' (entregues no cliente).
     */
    public function markAsDelivered(int $conversationId, string $readerType): int
    {
        $clinicId = TenantContext::getClinicId();
        $otherSender = ($readerType === 'professional') ? 'patient' : 'professional';
        $stmt = $this->db->prepare("
            UPDATE chat_messages SET status = 'delivered', delivered_at = :now
            WHERE conversation_id = :conv_id AND clinic_id = :clinic_id AND sender_type = :other_sender AND status = 'sent'
        ");
        $stmt->execute(['now' => $this->now(), 'conv_id' => $conversationId, 'clinic_id' => $clinicId, 'other_sender' => $otherSender]);
        return $stmt->rowCount();
    }

    /**
     * Marca mensagens do outro participante como lidas, com validação estrita do participante (PX-25 / NI2-318).
     */
    public function markAsRead(int $conversationId, string $currentReaderType, ?int $readerId = null): void
    {
        $clinicId = TenantContext::getClinicId();
        if ($readerId !== null) {
            $col = $currentReaderType === 'patient' ? 'patient_id' : 'professional_id';
            $chk = $this->db->prepare("SELECT id FROM chat_conversations WHERE id = :conv_id AND clinic_id = :clinic_id AND {$col} = :reader_id");
            $chk->execute(['conv_id' => $conversationId, 'clinic_id' => $clinicId, 'reader_id' => $readerId]);
            if (!in_array($currentReaderType, ['patient', 'professional'], true) || !$chk->fetchColumn()) {
                throw new \ClyoNutri\Core\Security\AuthorizationException('Conversa não encontrada.');
            }
        }
        $otherType = ($currentReaderType === 'professional') ? 'patient' : 'professional';
        $stmt = $this->db->prepare("
            UPDATE chat_messages SET status = 'read', read_at = :now
            WHERE conversation_id = :conv_id AND clinic_id = :clinic_id AND sender_type = :other_type AND read_at IS NULL
        ");
        $stmt->execute(['now' => $this->now(), 'conv_id' => $conversationId, 'clinic_id' => $clinicId, 'other_type' => $otherType]);
        if ($stmt->rowCount() > 0 && $readerId !== null) {
            (new AuditLogger($this->db))->log('CHAT_MESSAGES_READ', 'chat_conversation', (string)$conversationId, [
                'count' => $stmt->rowCount(),
            ] + $this->actor($currentReaderType, $readerId));
        }
    }

    /**
     * Lista conversas do nutricionista com contagem de não lidas, última mensagem e se ainda pode enviar.
     */
    public function listConversationsForProfessional(int $professionalId): array
    {
        $clinicId = TenantContext::getClinicId();
        TenantGuard::assertProfessionalBelongsToClinic($professionalId, $clinicId, $this->db);
        $stmt = $this->db->prepare("
            SELECT c.*, p.name AS patient_name, p.email AS patient_email, p.phone AS patient_phone,
                   (SELECT m.message FROM chat_messages m WHERE m.conversation_id = c.id ORDER BY m.id DESC LIMIT 1) AS last_message,
                   (SELECT m.created_at FROM chat_messages m WHERE m.conversation_id = c.id ORDER BY m.id DESC LIMIT 1) AS last_message_at,
                   (SELECT COUNT(*) FROM chat_messages m WHERE m.conversation_id = c.id AND m.sender_type = 'patient' AND m.read_at IS NULL) AS unread_count
            FROM chat_conversations c
            JOIN patients p ON p.id = c.patient_id
            WHERE c.professional_id = :prof_id AND c.clinic_id = :clinic_id
            ORDER BY c.updated_at DESC
        ");
        $stmt->execute(['prof_id' => $professionalId, 'clinic_id' => $clinicId]);
        $policy = new ChatAccessPolicy($this->db);
        return array_map(static function (array $c) use ($policy) {
            $c['can_send'] = $policy->evaluate($c, 'professional')['can_send'];
            return $c;
        }, $stmt->fetchAll(PDO::FETCH_ASSOC) ?: []);
    }

    /**
     * Lista conversas da paciente com o nutricionista responsável.
     */
    public function listConversationsForPatient(int $patientId): array
    {
        $clinicId = TenantContext::getClinicId();
        TenantGuard::assertPatientBelongsToClinic($patientId, $clinicId, $this->db);
        $stmt = $this->db->prepare("
            SELECT c.*, u.name AS professional_name, pr.crn AS professional_crn,
                   (SELECT m.message FROM chat_messages m WHERE m.conversation_id = c.id ORDER BY m.id DESC LIMIT 1) AS last_message,
                   (SELECT m.created_at FROM chat_messages m WHERE m.conversation_id = c.id ORDER BY m.id DESC LIMIT 1) AS last_message_at,
                   (SELECT COUNT(*) FROM chat_messages m WHERE m.conversation_id = c.id AND m.sender_type = 'professional' AND m.read_at IS NULL) AS unread_count
            FROM chat_conversations c
            JOIN professionals pr ON pr.id = c.professional_id
            JOIN users u ON u.id = pr.user_id
            WHERE c.patient_id = :patient_id AND c.clinic_id = :clinic_id
            ORDER BY c.updated_at DESC
        ");
        $stmt->execute(['patient_id' => $patientId, 'clinic_id' => $clinicId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    /**
     * Canal da paciente (A1): conversas existentes e profissionais com vínculo ativo. Nunca escolhe um
     * profissional por ela; sem vínculo, não há conversa.
     *
     * @return array{channels: array<int, array>, selected: ?array}
     */
    public function patientChannels(int $patientId, ?int $preferredProfessionalId = null): array
    {
        $clinicId = TenantContext::getClinicId();
        $byProf = [];
        foreach ($this->listConversationsForPatient($patientId) as $c) {
            $byProf[(int)$c['professional_id']] = ['professional_id' => (int)$c['professional_id'], 'professional_name' => $c['professional_name'],
                'professional_crn' => $c['professional_crn'], 'conversation_id' => (int)$c['id'], 'unread_count' => (int)$c['unread_count'], 'linked' => false];
        }
        foreach ((new PatientLinkService($this->db))->activeLinksForPatient($patientId) as $l) {
            $pid = (int)$l['professional_id'];
            $byProf[$pid] = ($byProf[$pid] ?? ['professional_id' => $pid, 'professional_name' => $l['professional_name'], 'professional_crn' => $l['professional_crn'],
                'conversation_id' => null, 'unread_count' => 0]) + [];
            $byProf[$pid]['linked'] = true;
        }
        $channels = array_values($byProf);
        // Primeiro os com vínculo ativo e mensagens não lidas.
        usort($channels, static fn($a, $b) => [$b['linked'], $b['unread_count']] <=> [$a['linked'], $a['unread_count']]);
        $selected = null;
        foreach ($channels as $ch) {
            if ($preferredProfessionalId !== null && $ch['professional_id'] === $preferredProfessionalId) {
                $selected = $ch;
                break;
            }
        }
        $selected ??= $channels[0] ?? null;
        if ($selected !== null && $selected['conversation_id'] === null && $selected['linked']) {
            $selected['conversation_id'] = $this->getOrCreateConversation($patientId, $selected['professional_id']);
        }
        unset($clinicId);
        return ['channels' => $channels, 'selected' => $selected];
    }

    public function getAttachmentService(): ChatAttachmentService
    {
        return $this->attachmentService;
    }

    private function actor(?string $type = null, ?int $id = null): array
    {
        if ($type !== null) {
            return ['actor_type' => $type, 'actor_id' => $id];
        }
        $u = TenantContext::getUser();
        return ['actor_type' => ($u['role'] ?? '') === 'patient' ? 'patient' : 'user', 'actor_id' => $u['id'] ?? null];
    }
}
