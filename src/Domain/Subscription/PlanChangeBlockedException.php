<?php

declare(strict_types=1);

namespace ClyoNutri\Domain\Subscription;

/**
 * Troca de plano barrada porque o uso atual passa dos limites do plano de destino.
 * getBlockers() traz uma frase por limite excedido, já em português e sem dados de pacientes.
 */
final class PlanChangeBlockedException extends \DomainException
{
    /** @param list<array{key:string,message:string}> $blockers */
    public function __construct(private array $blockers)
    {
        parent::__construct(
            'Não é possível trocar para este plano agora. '
            . implode(' ', array_map(static fn(array $b): string => $b['message'], $blockers))
        );
    }

    /** @return list<array{key:string,message:string}> */
    public function getBlockers(): array
    {
        return $this->blockers;
    }
}
