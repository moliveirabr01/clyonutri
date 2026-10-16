<?php

declare(strict_types=1);

namespace ClyoNutri\Domain\Telehealth\Exceptions;

use ClyoNutri\Core\Security\TenantSecurityException;

/** v13: o código de acesso do app é de uso único e já foi trocado por outro navegador. */
final class TeleconsultationTicketUsedException extends TenantSecurityException
{
    public function __construct()
    {
        parent::__construct('Este link de acesso já foi usado em outro navegador. Abra a teleconsulta novamente pelo aplicativo para gerar um novo acesso.', 403);
    }
}
