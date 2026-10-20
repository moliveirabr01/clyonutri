<?php

declare(strict_types=1);

namespace ClyoNutri\Domain\PreConsultation;

/** Erro de regra da pré-consulta, com mensagem segura para exibir e código HTTP sugerido. */
class PreConsultationException extends \RuntimeException
{
    public function __construct(string $message, private int $httpStatus = 422)
    {
        parent::__construct($message);
    }

    public function httpStatus(): int
    {
        return $this->httpStatus;
    }
}
