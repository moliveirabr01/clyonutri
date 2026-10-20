<?php

declare(strict_types=1);

namespace ClyoNutri\Domain\PreConsultation;

/**
 * Propõe o questionário a partir dos objetivos marcados (NI2-437).
 *
 * Regra determinística e explicável: perguntas 'base' + perguntas marcadas com algum dos objetivos.
 * Cada pergunta sugerida diz por que foi incluída. É só uma proposta: o nutricionista edita
 * (inclui, exclui, muda obrigatoriedade) ou ignora a sugestão e monta do zero.
 * Não há inferência clínica, pontuação ou diagnóstico.
 */
final class QuestionnaireSuggester
{
    /**
     * @param list<string> $objectives
     * @return list<array{id: string, required: bool, reason: string}>
     */
    public static function suggest(array $objectives): array
    {
        $objectives = array_values(array_unique(array_filter($objectives, fn($o) => isset(QuestionBank::OBJECTIVES[$o]))));
        $out = [];
        foreach (QuestionBank::questions() as $id => $q) {
            $matched = array_values(array_intersect($q['tags'], $objectives));
            if (in_array('base', $q['tags'], true)) {
                $out[$id] = ['id' => $id, 'required' => $q['required'], 'reason' => 'Pergunta geral, usada em toda pré-consulta'];
            } elseif ($matched !== []) {
                $labels = array_map(fn($o) => QuestionBank::OBJECTIVES[$o]['label'], $matched);
                $out[$id] = ['id' => $id, 'required' => $q['required'], 'reason' => 'Objetivo: ' . implode(', ', $labels)];
            }
        }
        return array_values(self::withDependencies($out));
    }

    /** Somente as perguntas gerais (para quem ignora a sugestão). */
    public static function baseOnly(): array
    {
        return self::suggest([]);
    }

    /**
     * Garante que pergunta condicional venha com a pergunta da qual depende, na ordem do banco.
     *
     * @param array<string, array> $selected
     * @return array<string, array>
     */
    public static function withDependencies(array $selected): array
    {
        foreach (array_keys($selected) as $id) {
            $dep = QuestionBank::dependencyOf($id);
            if ($dep !== null && !isset($selected[$dep])) {
                $q = QuestionBank::question($dep);
                $selected[$dep] = ['id' => $dep, 'required' => $q['required'], 'reason' => 'Necessária para a pergunta condicional'];
            }
        }
        $order = array_flip(array_keys(QuestionBank::questions()));
        uksort($selected, fn($a, $b) => $order[$a] <=> $order[$b]);
        return $selected;
    }
}
