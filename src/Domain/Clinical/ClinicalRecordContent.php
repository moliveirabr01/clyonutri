<?php

declare(strict_types=1);

namespace ClyoNutri\Domain\Clinical;

/**
 * v54.27: leitura do conteúdo de um registro do prontuário para exibição.
 *
 * O content_json de cada versão faz parte do código de integridade, então nada aqui altera o que está
 * gravado: só decide o que mostrar. Os formatos conhecidos são a anotação comum (text), a conclusão da
 * consulta presencial (evolution_summary), a sala de teleconsulta (notes/goals) e a retificação
 * (revised_note, com text ou notes/goals). Identificadores internos, chaves técnicas e campos que não
 * conhecemos nunca vão para a tela; JSON inválido vira texto simples.
 */
final class ClinicalRecordContent
{
    /** Primeira chave preenchida vira o texto principal ("Anotações"). */
    private const MAIN_KEYS = ['evolution_summary', 'text', 'notes'];

    /** Campos de texto secundários que podem ser mostrados, com o rótulo usado na tela e na impressão. */
    public const SECTION_LABELS = [
        'goals' => 'Metas até o retorno',
        'complaint' => 'Queixa principal',
        'diagnosis' => 'Diagnóstico nutricional',
        'conduct' => 'Conduta',
        'plan' => 'Plano',
        'observations' => 'Observações',
    ];

    public const CATEGORY_LABELS = [
        'evolucao' => 'Evolução clínica / atendimento',
        'observacao' => 'Observação clínica geral',
        'retorno' => 'Consulta de retorno',
        'intercorrencia' => 'Intercorrência clínica',
    ];

    /** Normaliza o conteúdo: array decodificado, string JSON (ou texto qualquer) ou nulo. */
    public static function decode(mixed $content): array
    {
        if (is_array($content)) {
            return $content;
        }
        if ($content === null || $content === false) {
            return [];
        }
        if (is_string($content)) {
            $trim = trim($content);
            if ($trim === '') {
                return [];
            }
            $decoded = json_decode($trim, true);
            if (is_array($decoded)) {
                return $decoded;
            }
            if (is_string($decoded)) {
                return ['text' => $decoded];
            }
            return ['text' => $content];
        }
        if (is_scalar($content)) {
            return ['text' => (string)$content];
        }
        return [];
    }

    /**
     * Estrutura pronta para a tela:
     *  text: texto principal (vazio se não houver), sections: [[key, label, value]], weight_kg, waist_cm,
     *  category (rótulo), via_teleconsultation (bool), appointment_id (?int, só para montar link com checagem de acesso),
     *  structured (true quando o registro usa notes/goals; a retificação mantém esse formato).
     */
    public static function present(mixed $content): array
    {
        $c = self::decode($content);
        $text = '';
        $mainKey = null;
        foreach (self::MAIN_KEYS as $k) {
            $v = self::str($c[$k] ?? null);
            if ($v !== '') {
                $text = $v;
                $mainKey = $k;
                break;
            }
        }
        // Texto que é, ele mesmo, um JSON de outro formato conhecido (gravação antiga em dupla codificação).
        if ($text !== '' && ($text[0] === '{') && is_array($inner = json_decode($text, true))) {
            $innerP = self::present($inner);
            if ($innerP['text'] !== '' || $innerP['sections']) {
                $innerP['via_teleconsultation'] = $innerP['via_teleconsultation'] || self::isTele($c);
                $innerP['appointment_id'] = $innerP['appointment_id'] ?? self::appointmentId($c);
                return $innerP;
            }
        }
        $sections = [];
        foreach (self::SECTION_LABELS as $k => $label) {
            $v = self::str($c[$k] ?? null);
            if ($v !== '') {
                $sections[] = ['key' => $k, 'label' => $label, 'value' => $v];
            }
        }
        $cat = is_string($c['category'] ?? null) ? $c['category'] : null;
        return [
            'text' => $text,
            'main_key' => $mainKey,
            'sections' => $sections,
            'weight_kg' => self::num($c['weight_kg'] ?? null),
            'waist_cm' => self::num($c['waist_cm'] ?? null),
            'category' => $cat !== null ? (self::CATEGORY_LABELS[$cat] ?? null) : null,
            'via_teleconsultation' => self::isTele($c),
            'appointment_id' => self::appointmentId($c),
            'structured' => array_key_exists('notes', $c) || array_key_exists('goals', $c),
            'notes' => $text,
            'goals' => self::str($c['goals'] ?? null),
        ];
    }

    /** Pares rótulo/valor para a impressão, na mesma ordem da tela e sem campos técnicos. */
    public static function printFields(mixed $content): array
    {
        $p = self::present($content);
        $out = [];
        if ($p['text'] !== '') {
            $label = $p['structured'] ? 'Anotações' : ($p['main_key'] === 'evolution_summary' ? 'Resumo da evolução' : 'Anotação');
            $out[] = ['label' => $label, 'value' => $p['text']];
        }
        foreach ($p['sections'] as $s) {
            $out[] = ['label' => $s['label'], 'value' => $s['value']];
        }
        if ($p['weight_kg'] !== null) {
            $out[] = ['label' => 'Peso aferido', 'value' => self::br($p['weight_kg']) . ' kg'];
        }
        if ($p['waist_cm'] !== null) {
            $out[] = ['label' => 'Cintura', 'value' => self::br($p['waist_cm']) . ' cm'];
        }
        if ($p['category'] !== null) {
            $out[] = ['label' => 'Categoria', 'value' => $p['category']];
        }
        return $out;
    }

    private static function br(float $v): string
    {
        return number_format($v, 1, ',', '.');
    }

    /** Texto corrido, sem chaves nem ids: resumo do cartão do paciente e pré-preenchimento simples. */
    public static function plainText(mixed $content): string
    {
        $p = self::present($content);
        $parts = [];
        if ($p['text'] !== '') {
            $parts[] = $p['text'];
        }
        foreach ($p['sections'] as $s) {
            $parts[] = $s['label'] . ': ' . $s['value'];
        }
        return implode("\n\n", $parts);
    }

    private static function isTele(array $c): bool
    {
        return !empty($c['recorded_during_telehealth'])
            || ($c['source'] ?? null) === 'teleconsultation_room'
            || !empty($c['teleconsultation_session_id']);
    }

    private static function appointmentId(array $c): ?int
    {
        $id = $c['appointment_id'] ?? null;
        return (is_int($id) || (is_string($id) && ctype_digit($id))) && (int)$id > 0 ? (int)$id : null;
    }

    private static function str(mixed $v): string
    {
        if (is_string($v)) {
            return trim($v);
        }
        if (is_int($v) || is_float($v)) {
            return (string)$v;
        }
        return '';
    }

    private static function num(mixed $v): ?float
    {
        return is_numeric($v) && (float)$v > 0 ? (float)$v : null;
    }
}
