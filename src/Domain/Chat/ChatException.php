<?php

declare(strict_types=1);

namespace ClyoNutri\Domain\Chat;

/**
 * Recusa do canal de mensagens (NI2-138) com código estável e status HTTP para as APIs e a tela web.
 *
 * Códigos: CONVERSATION_NOT_FOUND / ATTACHMENT_NOT_FOUND (404, sem ids), NO_ACTIVE_LINK / LINK_INACTIVE (403),
 * MESSAGE_TOO_LONG / VALIDATION_ERROR / ATTACHMENT_INVALID (422), RATE_LIMITED (429, com retry_after),
 * CHAT_DISABLED (503), DOWNLOAD_UNAVAILABLE (503).
 */
final class ChatException extends \RuntimeException
{
    public function __construct(
        string $message,
        public readonly string $errorCode,
        public readonly int $httpStatus,
        public readonly array $extra = []
    ) {
        parent::__construct($message);
    }

    public static function conversationNotFound(): self
    {
        return new self('Conversa não encontrada.', 'CONVERSATION_NOT_FOUND', 404);
    }

    public static function attachmentNotFound(): self
    {
        return new self('Anexo não encontrado.', 'ATTACHMENT_NOT_FOUND', 404);
    }

    public function toArray(): array
    {
        return ['error' => $this->getMessage(), 'code' => $this->errorCode] + $this->extra;
    }
}
