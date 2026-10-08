<?php

declare(strict_types=1);

namespace ClyoNutri\Domain\Telehealth\Exceptions;

use RuntimeException;

/**
 * Exceção disparada quando a integração com provedor de mídia WebRTC/SFU
 * não pode ser completada em virtude de ausência de credenciais contratadas
 * ou indisponibilidade de rede, em estrita conformidade com o protocolo do DoD
 * (não gerar links ou vídeos simulados; reportar BLOCKED).
 */
class ExternalMediaProviderBlockedException extends RuntimeException
{
    public function __construct(string $message = "Integração de mídia externa WebRTC BLOCKED: Credenciais do provedor (DAILY_API_KEY ou LIVEKIT_API_KEY) não configuradas no ambiente de produção.", int $code = 503, ?\Throwable $previous = null)
    {
        parent::__construct($message, $code, $previous);
    }
}
