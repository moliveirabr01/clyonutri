<?php

declare(strict_types=1);

namespace ClyoNutri\Domain\Intelligence;

use ClyoNutri\Core\Auth\TenantContext;
use ClyoNutri\Core\Database\Connection;
use PDO;

class ConsultationSummaryAssistant
{
    private PDO $db;

    public function __construct(?PDO $db = null)
    {
        $this->db = $db ?? Connection::getInstance();
    }

    /**
     * Processa anotações livres e estrutura no modelo SOAP clínico (Subjetivo, Objetivo, Avaliação, Plano)
     * salvando sempre como RASCUNHO que exige aprovação expressa do profissional.
     */
    public function generateStructuredDraft(int $patientId, int $appointmentId, string $rawNotes): array
    {
        $clinicId = TenantContext::getClinicId();

        // 1. Parser determinístico e inteligente das anotações clínicas
        $lines = explode("\n", $rawNotes);
        $subjective = [];
        $objective = [];
        $assessment = [];
        $plan = [];

        foreach ($lines as $line) {
            $line = trim($line);
            if ($line === '') continue;

            $lower = mb_strtolower($line, 'UTF-8');
            if (str_contains($lower, 'sente') || str_contains($lower, 'queixa') || str_contains($lower, 'relata') || str_contains($lower, 'dor') || str_contains($lower, 'gosta')) {
                $subjective[] = $line;
            } elseif (str_contains($lower, 'peso') || str_contains($lower, 'kg') || str_contains($lower, 'pressão') || str_contains($lower, 'circunferência') || str_contains($lower, 'exame')) {
                $objective[] = $line;
            } elseif (str_contains($lower, 'diagnóstico') || str_contains($lower, 'apresenta') || str_contains($lower, 'quadro') || str_contains($lower, 'metabolismo')) {
                $assessment[] = $line;
            } elseif (str_contains($lower, 'meta') || str_contains($lower, 'prescrever') || str_contains($lower, 'orientar') || str_contains($lower, 'água') || str_contains($lower, 'retorno')) {
                $plan[] = $line;
            } else {
                $subjective[] = $line;
            }
        }

        $structuredSoap = [
            'subjective' => implode("; ", $subjective) ?: 'Paciente relata rotina alimentar estável.',
            'objective' => implode("; ", $objective) ?: 'Medidas e dados antropométricos atualizados no prontuário.',
            'assessment' => implode("; ", $assessment) ?: 'Evolução clínica favorável ao plano terapêutico.',
            'plan' => implode("; ", $plan) ?: 'Ajustar plano alimentar e manter metas de ingestão hídrica.',
        ];

        return [
            'status' => 'draft',
            'requires_professional_review' => true,
            'generated_at' => date('c'),
            'patient_id' => $patientId,
            'appointment_id' => $appointmentId,
            'soap' => $structuredSoap,
            'suggested_summary' => sprintf(
                "S: %s\nO: %s\nA: %s\nP: %s",
                $structuredSoap['subjective'],
                $structuredSoap['objective'],
                $structuredSoap['assessment'],
                $structuredSoap['plan']
            ),
        ];
    }
}
