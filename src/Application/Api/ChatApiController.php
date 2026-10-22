<?php

declare(strict_types=1);

namespace ClyoNutri\Application\Api;

use ClyoNutri\Core\Auth\TenantContext;
use ClyoNutri\Core\Security\AuthorizationException;
use ClyoNutri\Core\Security\TenantSecurityException;
use ClyoNutri\Core\Settings\PlatformSettings;
use ClyoNutri\Domain\Chat\ChatAttachmentService;
use ClyoNutri\Domain\Chat\ChatException;
use ClyoNutri\Domain\Chat\ChatService;
use ClyoNutri\Domain\Chat\ChatSettingsService;
use PDO;

/**
 * API do chat (NI2-138) para a paciente (app e portal web) e para o profissional no app.
 * A autenticação é feita antes, em public/index.php (Bearer ou cookie web com Origin e cabeçalho do app).
 *
 * Erros com código estável: 404 CONVERSATION_NOT_FOUND/ATTACHMENT_NOT_FOUND (sem ids), 403 NO_ACTIVE_LINK/LINK_INACTIVE,
 * 422 VALIDATION_ERROR/MESSAGE_TOO_LONG/ATTACHMENT_INVALID, 429 RATE_LIMITED (Retry-After), 503 CHAT_DISABLED.
 */
final class ChatApiController
{
    public const NO_LINK_TEXT = 'Seu consultório ainda não liberou o chat. Para falar com a equipe, use o telefone do consultório.';

    public function __construct(private PDO $db)
    {
    }

    private function chat(): ChatService
    {
        return new ChatService($this->db);
    }

    /** M3: o desligamento do chat pela administração vale também para a API. */
    public function enabled(): bool
    {
        if (PlatformSettings::isChatEnabled($this->db)) {
            return true;
        }
        $this->respond(503, ['error' => 'O chat está temporariamente indisponível. Para falar com a equipe, use o telefone do consultório.', 'code' => 'CHAT_DISABLED']);
        return false;
    }

    // ------------------------------------------------------------------ paciente

    public function patientMessages(int $patientId, ?int $afterId, ?int $professionalId): void
    {
        $this->run(function () use ($patientId, $afterId, $professionalId) {
            $chat = $this->chat();
            $ch = $chat->patientChannels($patientId, $professionalId);
            $notice = (new ChatSettingsService($this->db))->notice(TenantContext::getClinicId());
            $channels = array_map(static fn($c) => [
                'professional_id' => $c['professional_id'], 'professional_name' => $c['professional_name'],
                'unread_count' => $c['unread_count'], 'can_start' => $c['linked'],
            ], $ch['channels']);
            $sel = $ch['selected'];
            if ($sel === null || $sel['conversation_id'] === null) {
                return [
                    'conversation_id' => null,
                    'professional' => null,
                    'channels' => $channels,
                    'access' => ['can_send' => false, 'code' => 'NO_ACTIVE_LINK', 'reason' => self::NO_LINK_TEXT],
                    'notice' => $notice,
                    'messages' => [],
                    'cursor' => ['after_id' => 0, 'count' => 0, 'has_more' => false],
                ];
            }
            $data = $chat->getMessagesWithCursor((int)$sel['conversation_id'], $afterId, 50, 'patient', $patientId);
            return [
                'conversation_id' => (int)$sel['conversation_id'],
                'professional' => ['id' => $sel['professional_id'], 'name' => $sel['professional_name'] ?? 'Nutricionista', 'crn' => $sel['professional_crn'] ?? null],
                'channels' => $channels,
                'notice' => $notice,
            ] + $data;
        });
    }

    public function patientSend(int $patientId, array $input, ?array $file): void
    {
        $this->run(function () use ($patientId, $input, $file) {
            $message = is_string($input['message'] ?? null) ? $input['message'] : '';
            $key = is_string($input['idempotency_key'] ?? null) ? $input['idempotency_key'] : null;
            $convId = is_numeric($input['conversation_id'] ?? null) ? (int)$input['conversation_id'] : 0;
            $profId = is_numeric($input['professional_id'] ?? null) ? (int)$input['professional_id'] : null;
            $chat = $this->chat();
            if ($convId <= 0) {
                $sel = $chat->patientChannels($patientId, $profId)['selected'];
                if ($sel === null || $sel['conversation_id'] === null) {
                    throw new ChatException(self::NO_LINK_TEXT, 'NO_ACTIVE_LINK', 403);
                }
                $convId = (int)$sel['conversation_id'];
            }
            $msg = $chat->sendMessage($convId, 'patient', $patientId, $message, null, $key, $file);
            return ['success' => true, 'message' => $msg];
        }, 201);
    }

    public function patientRead(int $patientId, array $input): void
    {
        $this->run(function () use ($patientId, $input) {
            $convId = is_numeric($input['conversation_id'] ?? null) ? (int)$input['conversation_id'] : 0;
            if ($convId <= 0) {
                $sel = $this->chat()->patientChannels($patientId)['selected'];
                $convId = (int)($sel['conversation_id'] ?? 0);
            }
            if ($convId <= 0) {
                throw ChatException::conversationNotFound();
            }
            $this->chat()->markAsRead($convId, 'patient', $patientId);
            return ['success' => true];
        });
    }

    public function patientAttachmentLink(int $patientId, int $attachmentId): void
    {
        $this->run(function () use ($patientId, $attachmentId) {
            $token = (new ChatAttachmentService($this->db))->issuePatientDownloadToken($attachmentId, $patientId);
            return ['url' => '/api/v1/chat/anexo/' . $token, 'expires_in' => 120];
        });
    }

    /** Download pelo link assinado (sem Bearer): o próprio link diz quem é a paciente, o anexo e a clínica. */
    public function downloadByToken(string $token): void
    {
        try {
            $claims = ChatAttachmentService::verifyPatientDownloadToken($token);
            TenantContext::set($claims['clinic_id'], ['id' => $claims['patient_id'], 'role' => 'patient']);
            $file = (new ChatAttachmentService($this->db))->getAttachmentForDownload($claims['attachment_id'], 'patient', $claims['patient_id']);
            ChatAttachmentService::sendDownloadHeaders($file);
            readfile($file['file_path']);
        } catch (ChatException $e) {
            $this->respond($e->httpStatus, $e->toArray());
        }
    }

    public function patientDownload(int $patientId, int $attachmentId): void
    {
        try {
            $file = (new ChatAttachmentService($this->db))->getAttachmentForDownload($attachmentId, 'patient', $patientId);
            ChatAttachmentService::sendDownloadHeaders($file);
            readfile($file['file_path']);
        } catch (ChatException $e) {
            $this->respond($e->httpStatus, $e->toArray());
        }
    }

    // ------------------------------------------------------------------ profissional (app)

    public function professionalConversations(int $professionalId): void
    {
        $this->run(fn() => ['conversations' => array_map(static fn($c) => [
            'id' => (int)$c['id'], 'patient_id' => (int)$c['patient_id'], 'patient_name' => $c['patient_name'], 'status' => $c['status'],
            'last_message' => $c['last_message'], 'last_message_at' => $c['last_message_at'], 'unread_count' => (int)$c['unread_count'],
            'can_send' => (bool)$c['can_send'],
        ], $this->chat()->listConversationsForProfessional($professionalId))]);
    }

    public function professionalMessages(int $professionalId, int $convId, ?int $afterId): void
    {
        $this->run(fn() => $this->chat()->getMessagesWithCursor($convId, $afterId, 50, 'professional', $professionalId));
    }

    public function professionalSend(int $professionalId, int $convId, array $input): void
    {
        $this->run(function () use ($professionalId, $convId, $input) {
            $message = is_string($input['message'] ?? null) ? $input['message'] : '';
            $key = is_string($input['idempotency_key'] ?? null) ? $input['idempotency_key'] : null;
            return ['success' => true, 'message' => $this->chat()->sendMessage($convId, 'professional', $professionalId, $message, null, $key)];
        }, 201);
    }

    public function professionalRead(int $professionalId, int $convId): void
    {
        $this->run(function () use ($professionalId, $convId) {
            $this->chat()->markAsRead($convId, 'professional', $professionalId);
            return ['success' => true];
        });
    }

    // ------------------------------------------------------------------ apoio

    private function run(callable $fn, int $successStatus = 200): void
    {
        try {
            $result = $fn();
            $this->respond(!empty($result['message']['replayed']) ? 200 : $successStatus, $result);
        } catch (ChatException $e) {
            if ($e->errorCode === 'RATE_LIMITED' && isset($e->extra['retry_after'])) {
                header('Retry-After: ' . (int)$e->extra['retry_after']);
            }
            $this->respond($e->httpStatus, $e->toArray());
        } catch (TenantSecurityException | AuthorizationException) {
            $this->respond(404, ['error' => 'Conversa não encontrada.', 'code' => 'CONVERSATION_NOT_FOUND']);
        }
    }

    private function respond(int $status, array $body): void
    {
        http_response_code($status);
        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: no-store');
        echo json_encode($body, JSON_UNESCAPED_UNICODE);
    }
}
