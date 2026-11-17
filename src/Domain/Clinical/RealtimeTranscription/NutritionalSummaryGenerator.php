<?php

declare(strict_types=1);

namespace ClyoNutri\Domain\Clinical\RealtimeTranscription;

/**
 * Gerador de Minuta de Evolução Nutricional a partir da transcrição (NI2-820 / NI2-882).
 *
 * REGRAS CLÍNICAS E DE SEGURANÇA:
 *   - Gera estritamente uma MINUTA para revisão humana obrigatória (montada por regras).
 *   - NUNCA inventa frases ou textos padrão afirmativos/negativos para tópicos não ditos.
 *   - Tópico sem fala correspondente recebe exatamente "Não mencionado na consulta".
 *   - Classifica falas por locutor (paciente vs profissional) e ignora perguntas do profissional.
 *   - Mantém rastreabilidade dos segmentos de origem por tópico.
 */
class NutritionalSummaryGenerator
{
    public const NOT_MENTIONED = 'Não mencionado na consulta';

    private const PATIENT_TOPICS = [
        'chief_complaint' => ['queixa', 'motivo', 'procurou', 'incomoda', 'problema', 'preocupacao'],
        'clinical_symptoms' => [
            'dor de cabeca', 'cefaleia', 'enxaqueca', 'cansaco', 'fadiga', 'tontura', 'vertigem',
            'nausea', 'enjoo', 'vomito', 'insonia', 'sono', 'estomago', 'intestino', 'constipacao',
            'prisao de ventre', 'diarreia', 'refluxo', 'azia', 'gases', 'inchaco', 'distensao',
            'colica', 'alergia', 'intolerancia', 'fraqueza', 'mal-estar', 'palpitacao', 'dor', 'desconforto',
        ],
        'reported_anthropometry' => ['peso', 'quilos', 'kg', 'altura', 'balanca', 'cintura', 'cm', 'pesando', 'medindo'],
        'nutritional_goals' => ['objetivo', 'meta', 'deseja', 'emagrecer', 'hipertrofia', 'ganho de massa', 'perder gordura', 'disposicao'],
        'dietary_routine' => ['cafe da manha', 'almoco', 'jantar', 'lanche', 'ceia', 'rotina', 'come', 'consome', 'refeicao', 'agua', 'alimentos', 'dieta', 'fruta', 'legume'],
        'adherence_notes' => ['adesao', 'dificuldade', 'conseguiu', 'falhou', 'finais de semana', 'ansiedade', 'vontade de doce', 'fome', 'recaida', 'belisco'],
    ];

    private const PROFESSIONAL_TOPICS = [
        'next_appointment_notes' => ['retorno', 'proxima consulta', 'semanas', 'mes que vem', 'retornar', 'agendar'],
        'agreed_conduct' => ['combinamos', 'conduta', 'orientacao', 'orientar', 'plano', 'vamos ajustar', 'acordo', 'meta da semana', 'beber mais agua', 'fracionamento', 'prescrever', 'incluir', 'reduzir', 'manter', 'evitar', 'recomendo', 'vamos'],
    ];

    public function generateDraft(string $transcriptText): array
    {
        $text = trim($transcriptText);
        if ($text === '') {
            return $this->emptyDraft();
        }
        return $this->generateDraftFromSegments([
            ['id' => 0, 'sequence_number' => 1, 'speaker' => 'unknown', 'text' => $text],
        ]);
    }

    /**
     * @param list<array<string,mixed>> $segments
     * @return array<string,mixed>
     */
    public function generateDraftFromSegments(array $segments): array
    {
        $buckets = [
            'chief_complaint' => [],
            'nutritional_goals' => [],
            'dietary_routine' => [],
            'reported_anthropometry' => [],
            'clinical_symptoms' => [],
            'adherence_notes' => [],
            'agreed_conduct' => [],
            'next_appointment_notes' => [],
            'other_notes' => [],
        ];
        $sources = array_map(static fn(): array => [], $buckets);

        foreach ($segments as $idx => $seg) {
            $rawText = trim((string)($seg['text'] ?? ''));
            if ($rawText === '') {
                continue;
            }
            $segId = (int)($seg['id'] ?? ($idx + 1));
            $seq = (int)($seg['sequence_number'] ?? ($idx + 1));
            $speaker = $this->normalizeSpeaker((string)($seg['speaker'] ?? ''));

            $sentences = preg_split('/(?<=[.?!;])\s+/u', $rawText, -1, PREG_SPLIT_NO_EMPTY) ?: [$rawText];
            foreach ($sentences as $sentence) {
                $sentence = trim($sentence);
                if ($sentence === '') {
                    continue;
                }
                if ($speaker === 'professional' && $this->isQuestion($sentence)) {
                    // Frase interrogativa do profissional não entra em nenhum tópico de conteúdo.
                    continue;
                }

                $topic = $this->classifySentence($sentence, $speaker);
                $buckets[$topic][] = $sentence;
                $sources[$topic][] = [
                    'id' => $segId,
                    'sequence_number' => $seq,
                    'speaker' => $speaker,
                    'text' => $sentence,
                ];
            }
        }

        $hasAnySpeech = false;
        foreach ($buckets as $list) {
            if ($list !== []) {
                $hasAnySpeech = true;
                break;
            }
        }
        if (!$hasAnySpeech) {
            return $this->emptyDraft();
        }

        $join = static fn(array $list): string => $list !== [] ? implode(' ', $list) : self::NOT_MENTIONED;

        $chiefComplaint = $join($buckets['chief_complaint']);
        $nutritionalGoals = $join($buckets['nutritional_goals']);
        $dietaryRoutine = $join($buckets['dietary_routine']);
        $reportedAnthro = $join($buckets['reported_anthropometry']);
        $clinicalSymptoms = $join($buckets['clinical_symptoms']);
        $adherenceNotes = $join($buckets['adherence_notes']);
        $agreedConduct = $join($buckets['agreed_conduct']);
        $nextReturn = $join($buckets['next_appointment_notes']);
        $otherNotes = $join($buckets['other_notes']);

        $fullText = $this->formatFullEvolution(
            $chiefComplaint,
            $nutritionalGoals,
            $dietaryRoutine,
            $reportedAnthro,
            $clinicalSymptoms,
            $adherenceNotes,
            $agreedConduct,
            $nextReturn,
            $otherNotes
        );

        return [
            'chief_complaint' => $chiefComplaint,
            'nutritional_goals' => $nutritionalGoals,
            'dietary_routine' => $dietaryRoutine,
            'reported_anthropometry' => $reportedAnthro,
            'clinical_symptoms' => $clinicalSymptoms,
            'adherence_notes' => $adherenceNotes,
            'agreed_conduct' => $agreedConduct,
            'next_appointment_notes' => $nextReturn,
            'other_notes' => $otherNotes,
            'segment_sources' => $sources,
            'generator_notice' => 'Minuta montada por regras a partir da transcrição — revisão obrigatória antes de aprovar.',
            'full_evolution_text' => $fullText,
        ];
    }

    public function formatFullEvolution(
        string $complaint,
        string $goals,
        string $routine,
        string $anthro,
        string $symptoms,
        string $adherence,
        string $conduct,
        string $returnNotes,
        string $otherNotes = self::NOT_MENTIONED
    ): string {
        $lines = [
            "EVOLUÇÃO NUTRICIONAL (Minuta por regras — revisão obrigatória)",
            "--------------------------------------------------",
            "1. Motivo / Queixa Principal: " . trim($complaint),
            "2. Objetivos Nutricionais: " . trim($goals),
            "3. Histórico e Rotina Alimentar: " . trim($routine),
            "4. Medidas Informadas: " . trim($anthro),
            "5. Sintomas e Queixas Clínicas: " . trim($symptoms),
            "6. Adesão e Dificuldades: " . trim($adherence),
            "7. Conduta Acordada e Metas: " . trim($conduct),
            "8. Previsão de Retorno: " . trim($returnNotes),
        ];
        if ($otherNotes !== '' && $otherNotes !== self::NOT_MENTIONED) {
            $lines[] = "9. Outros Relatos: " . trim($otherNotes);
        }
        $lines[] = "--------------------------------------------------";
        $lines[] = "[Minuta gerada por regras a partir dos segmentos transcritos — exige revisão e aprovação do profissional]";

        return implode("\n", $lines);
    }

    private function emptyDraft(): array
    {
        return [
            'chief_complaint' => self::NOT_MENTIONED,
            'nutritional_goals' => self::NOT_MENTIONED,
            'dietary_routine' => self::NOT_MENTIONED,
            'reported_anthropometry' => self::NOT_MENTIONED,
            'clinical_symptoms' => self::NOT_MENTIONED,
            'adherence_notes' => self::NOT_MENTIONED,
            'agreed_conduct' => self::NOT_MENTIONED,
            'next_appointment_notes' => self::NOT_MENTIONED,
            'other_notes' => self::NOT_MENTIONED,
            'segment_sources' => [],
            'generator_notice' => 'Minuta montada por regras a partir da transcrição — revisão obrigatória antes de aprovar.',
            'full_evolution_text' => $this->formatFullEvolution(
                self::NOT_MENTIONED,
                self::NOT_MENTIONED,
                self::NOT_MENTIONED,
                self::NOT_MENTIONED,
                self::NOT_MENTIONED,
                self::NOT_MENTIONED,
                self::NOT_MENTIONED,
                self::NOT_MENTIONED,
                self::NOT_MENTIONED
            ),
        ];
    }

    private function normalizeSpeaker(string $speaker): string
    {
        $s = mb_strtolower(trim($speaker), 'UTF-8');
        if (in_array($s, ['professional', 'nutritionist', 'nutricionista', 'profissional', 'clinician', 'doctor'], true)) {
            return 'professional';
        }
        if (in_array($s, ['patient', 'paciente'], true)) {
            return 'patient';
        }
        return 'unknown';
    }

    private function isQuestion(string $sentence): bool
    {
        return str_ends_with(trim($sentence), '?');
    }

    private function classifySentence(string $sentence, string $speaker): string
    {
        $norm = $this->normalizeText($sentence);

        if ($speaker === 'professional') {
            foreach (self::PROFESSIONAL_TOPICS as $topic => $keywords) {
                if ($this->matchesAny($norm, $keywords)) {
                    return $topic;
                }
            }
            return 'agreed_conduct';
        }

        if ($speaker === 'patient') {
            foreach (self::PATIENT_TOPICS as $topic => $keywords) {
                if ($this->matchesAny($norm, $keywords)) {
                    return $topic;
                }
            }
            return 'other_notes';
        }

        // Locutor desconhecido: tenta paciente primeiro, depois profissional, senão Outros relatos.
        foreach (self::PATIENT_TOPICS as $topic => $keywords) {
            if ($this->matchesAny($norm, $keywords)) {
                return $topic;
            }
        }
        if (!$this->isQuestion($sentence)) {
            foreach (self::PROFESSIONAL_TOPICS as $topic => $keywords) {
                if ($this->matchesAny($norm, $keywords)) {
                    return $topic;
                }
            }
        }
        return 'other_notes';
    }

    private function matchesAny(string $normalizedSentence, array $keywords): bool
    {
        foreach ($keywords as $kw) {
            if (str_contains($normalizedSentence, $this->normalizeText($kw))) {
                return true;
            }
        }
        return false;
    }

    private function normalizeText(string $text): string
    {
        $lower = mb_strtolower($text, 'UTF-8');
        return strtr($lower, [
            'á' => 'a', 'à' => 'a', 'â' => 'a', 'ã' => 'a',
            'é' => 'e', 'ê' => 'e',
            'í' => 'i',
            'ó' => 'o', 'ô' => 'o', 'õ' => 'o',
            'ú' => 'u', 'ü' => 'u',
            'ç' => 'c',
        ]);
    }
}
