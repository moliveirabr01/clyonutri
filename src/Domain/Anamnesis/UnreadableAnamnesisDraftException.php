<?php

declare(strict_types=1);

namespace ClyoNutri\Domain\Anamnesis;

/**
 * v54.57 (UX-103): o rascunho da anamnese tem campo cifrado que não pode ser lido com a chave atual.
 * Nada é gravado por cima dele até o nutricionista recuperar o rascunho (descarte auditado só dos campos ilegíveis).
 */
final class UnreadableAnamnesisDraftException extends \DomainException
{
    /** @param list<string> $fields */
    public function __construct(private readonly array $fields, private readonly int $patientId)
    {
        parent::__construct('O rascunho da anamnese em andamento tem ' . count($fields) . ' campo(s) que não puderam ser lidos (' . implode(', ', array_map([AnamnesisService::class, 'fieldLabel'], $fields)) . '). Nada foi gravado. Abra a anamnese do paciente e use "Recuperar rascunho" para manter o que está legível e descartar só o que está corrompido.');
    }

    /** @return list<string> */
    public function fields(): array
    {
        return $this->fields;
    }

    public function patientId(): int
    {
        return $this->patientId;
    }
}
