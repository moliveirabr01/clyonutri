<?php
namespace ClyoNutri\Domain\Patient;
final class DuplicatePatientException extends \InvalidArgumentException
{
    public function __construct(public readonly int $patientId)
    {
        parent::__construct('Já existe um paciente com este e-mail ou celular nesta clínica. Confira o cadastro existente antes de continuar.');
    }
}
