<?php

declare(strict_types=1);

namespace ClyoNutri\Domain\Admin;

/** Regra de negócio da gestão de usuários que impede a ação (não é erro de sistema). */
final class BusinessRule extends \RuntimeException
{
    public function __construct(string $message, public readonly string $codeName)
    {
        parent::__construct($message);
    }
}
