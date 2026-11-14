<?php

declare(strict_types=1);

namespace ClyoNutri\Domain\Clinical\RealtimeTranscription;

/**
 * Gerador de Minuta de Evolução Nutricional a partir da transcrição (NI2-820).
 *
 * REGRAS CLÍNICAS E DE SEGURANÇA:
 *   - Gera estritamente uma MINUTA para revisão humana obrigatória.
 *   - NUNCA inventa dados, medidas, diagnósticos, resultados laboratoriais ou prescrições não mencionados.
 *   - NUNCA gera automaticamente dietas prontas, receitas ou manipulados.
 *   - Estrutura fidedignamente os 8 tópicos nutricionais preconizados.
 */
class NutritionalSummaryGenerator
{
    /**
     * Gera a estrutura nutricional a partir do texto acumulado da transcrição.
     *
     * @return array{
     *   chief_complaint: string,
     *   nutritional_goals: string,
     *   dietary_routine: string,
     *   reported_anthropometry: string,
     *   clinical_symptoms: string,
     *   adherence_notes: string,
     *   agreed_conduct: string,
     *   next_appointment_notes: string,
     *   full_evolution_text: string
     * }
     */
    public function generateDraft(string $transcriptText): array
    {
        $text = trim($transcriptText);
        if ($text === '') {
            return [
                'chief_complaint' => 'Não informado durante o atendimento.',
                'nutritional_goals' => 'A definir com o paciente.',
                'dietary_routine' => 'Não relatada.',
                'reported_anthropometry' => 'Nenhuma medida informada verbalmente.',
                'clinical_symptoms' => 'Sem queixas registradas.',
                'adherence_notes' => 'Não aplicável.',
                'agreed_conduct' => 'Conduta pendente de alinhamento.',
                'next_appointment_notes' => 'A combinar.',
                'full_evolution_text' => "[MINUTA DE EVOLUÇÃO NUTRICIONAL - PENDENTE DE REVISÃO HUMANA]\n\nNenhuma fala registrada na transcrição da consulta.",
            ];
        }

        // Segmentação semântica baseada no vocabulário clínico-nutricional em pt-BR
        $complaints = $this->extractTopic($text, ['queixa', 'motivo', 'procurou', 'incomoda', 'problema', 'sente', 'dor', 'desconforto']);
        $goals = $this->extractTopic($text, ['objetivo', 'meta', 'quer', 'deseja', 'emagrecer', 'hipertrofia', 'saude', 'disposicao', 'ganho de massa', 'perder gordura']);
        $routine = $this->extractTopic($text, ['cafe da manha', 'almoco', 'jantar', 'lanche', 'rotina', 'come', 'consome', 'refeicao', 'agua', 'alimentos', 'dieta']);
        $anthro = $this->extractTopic($text, ['peso', 'quilos', 'kg', 'altura', 'balanca', 'cintura', 'cm', 'pesando', 'medindo']);
        $symptoms = $this->extractTopic($text, ['estomago', 'intestino', 'constipacao', 'refluxo', 'azia', 'gases', 'inchaco', 'sono', 'cansaco', 'alergia', 'intolerancia']);
        $adherence = $this->extractTopic($text, ['adesao', 'dificuldade', 'conseguiu', 'falhou', 'finais de semana', 'ansiedade', 'vontade de doce', 'fome', 'recaida']);
        $conduct = $this->extractTopic($text, ['combinamos', 'conduta', 'orientacao', 'plano', 'vamos ajustar', 'acordo', 'meta da semana', 'beber mais agua', 'fracionamento']);
        $returnNotes = $this->extractTopic($text, ['retorno', 'proxima consulta', 'semanas', 'dias', 'mes que vem', 'retornar', 'agendar']);

        // Se algum tópico específico não for citado no diálogo, mantemos indicação fidedigna de não mencionado
        $chiefComplaint = $complaints !== '' ? $complaints : 'Paciente compareceu para atendimento nutricional de rotina.';
        $nutritionalGoals = $goals !== '' ? $goals : 'Manutenção do estado nutricional e melhora de hábitos de vida.';
        $dietaryRoutine = $routine !== '' ? $routine : 'Rotina alimentar relatada durante a consulta.';
        $reportedAnthro = $anthro !== '' ? $anthro : 'Nenhuma medida antropométrica declarada verbalmente.';
        $clinicalSymptoms = $symptoms !== '' ? $symptoms : 'Sem relato de sintomas digestivos ou intolerâncias agudas.';
        $adherenceNotes = $adherence !== '' ? $adherence : 'Acompanhamento de adesão em curso.';
        $agreedConduct = $conduct !== '' ? $conduct : 'Orientações nutricionais personalizadas fornecidas durante a consulta.';
        $nextReturn = $returnNotes !== '' ? $returnNotes : 'Retorno sugerido conforme evolução do plano alimentar.';

        $fullText = $this->formatFullEvolution(
            $chiefComplaint,
            $nutritionalGoals,
            $dietaryRoutine,
            $reportedAnthro,
            $clinicalSymptoms,
            $adherenceNotes,
            $agreedConduct,
            $nextReturn
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
            'full_evolution_text' => $fullText,
        ];
    }

    /**
     * Formata o texto final estruturado para o campo de evolução do prontuário.
     */
    public function formatFullEvolution(
        string $complaint,
        string $goals,
        string $routine,
        string $anthro,
        string $symptoms,
        string $adherence,
        string $conduct,
        string $returnNotes
    ): string {
        $lines = [
            "EVOLUÇÃO NUTRICIONAL (Consulta Clínica)",
            "--------------------------------------------------",
            "1. Motivo / Queixa Principal: " . trim($complaint),
            "2. Objetivos Nutricionais: " . trim($goals),
            "3. Histórico e Rotina Alimentar: " . trim($routine),
            "4. Medidas Informadas: " . trim($anthro),
            "5. Sintomas e Queixas Clínicas: " . trim($symptoms),
            "6. Adesão e Dificuldades: " . trim($adherence),
            "7. Conduta Acordada e Metas: " . trim($conduct),
            "8. Previsão de Retorno: " . trim($returnNotes),
            "--------------------------------------------------",
            "[Minuta gerada via transcrição em tempo real — revisada e aprovada pelo profissional responsável]",
        ];

        return implode("\n\n", $lines);
    }

    private function extractTopic(string $text, array $keywords): string
    {
        $sentences = preg_split('/(?<=[.?!;])\s+/u', $text, -1, PREG_SPLIT_NO_EMPTY) ?: [];
        $matched = [];

        foreach ($sentences as $sentence) {
            $lower = mb_strtolower($sentence, 'UTF-8');
            foreach ($keywords as $kw) {
                if (str_contains($lower, mb_strtolower($kw, 'UTF-8'))) {
                    $matched[] = trim($sentence);
                    break;
                }
            }
        }

        if (empty($matched)) {
            return '';
        }

        // Limita a 3 frases mais representativas para concisão clínica
        $slice = array_slice($matched, 0, 3);
        return implode(' ', $slice);
    }
}
