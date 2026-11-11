<?php

declare(strict_types=1);

namespace ClyoNutri\Domain\ClinicalProtocol;

use InvalidArgumentException;

/**
 * NI2-635: formato e validação da definição de um protocolo (o conteúdo de uma versão).
 *
 * {
 *   "title": "…", "summary": "…",
 *   "sections": {"rotina": "Rotina"},
 *   "suggestion_terms": ["termo"],               // texto livre → só SUGESTÃO pendente de confirmação
 *   "questions": [{
 *      "key": "glicemia_jejum_casa",            // [a-z0-9_], única no protocolo
 *      "shared_key": "base.medicamentos",        // opcional: mesma pergunta em vários módulos → aparece uma vez
 *      "section": "rotina", "text": "…", "help": "…",
 *      "type": "text|textarea|number|single|multi|yesno|date|scale",
 *      "options": {"valor": "Rótulo"},           // single/multi/yesno
 *      "min": 0, "max": 10, "unit": "mg/dL",     // number/scale
 *      "required": true,
 *      "audience": "both|professional",          // pergunta só do profissional não vai para a pré-consulta
 *      "condition": {...},                       // ramificação (ver Conditions)
 *      "red_flag": {"when": {...}, "action": "medical_evaluation|urgent_evaluation|nutritionist_review", "message": "…"}
 *   }]
 * }
 *
 * Tipos e condições seguem o banco de perguntas da pré-consulta (NI2-437), ampliados com date/scale e all/any.
 * O motor nunca decide conduta: sinal de alerta só encaminha para avaliação, com o texto revisado no protocolo.
 */
final class ProtocolDefinition
{
    public const TYPES = ['text', 'textarea', 'number', 'single', 'multi', 'yesno', 'date', 'scale'];
    public const RED_FLAG_ACTIONS = [
        'urgent_evaluation' => 'Encaminhar para avaliação com urgência',
        'medical_evaluation' => 'Encaminhar para avaliação médica',
        'nutritionist_review' => 'Revisar com o nutricionista antes de seguir',
    ];
    /** Palavras de conduta que não podem aparecer em alerta (o protocolo encaminha; não prescreve). */
    private const PRESCRIPTIVE = ['suspenda', 'suspender', 'aumente a dose', 'reduza a dose', 'tome ', 'administre', 'diagnóstico confirmado'];

    /** @return array normalizado; lança InvalidArgumentException com todos os problemas encontrados */
    public static function validate(array $d): array
    {
        \ClyoNutri\Domain\Dietary\DietaryDefinition::validate($d);
        $err = [];
        if (trim((string)($d['title'] ?? '')) === '') {
            $err[] = 'title obrigatório';
        }
        $sections = is_array($d['sections'] ?? null) ? $d['sections'] : [];
        $qs = is_array($d['questions'] ?? null) ? array_values($d['questions']) : [];
        if ($qs === []) {
            $err[] = 'o protocolo precisa de ao menos uma pergunta';
        }
        $keys = [];
        foreach ($qs as $i => $q) {
            if (!is_array($q)) throw new InvalidArgumentException('Pergunta deve ser um objeto.');
            $p = 'pergunta ' . ($i + 1);
            $k = (string)($q['key'] ?? '');
            if (!preg_match('/^[a-z][a-z0-9_]{1,59}$/', $k)) {
                $err[] = "$p: key inválida";
            } elseif (isset($keys[$k])) {
                $err[] = "$p: key repetida ($k)";
            }
            $keys[$k] = $q;
            if (isset($q['shared_key']) && !preg_match('/^[a-z][a-z0-9_]*\.[a-z0-9_]+$/', (string)$q['shared_key'])) {
                $err[] = "$p: shared_key deve ter o formato grupo.nome";
            }
            if (trim((string)($q['text'] ?? '')) === '') {
                $err[] = "$p: texto obrigatório";
            }
            $t = (string)($q['type'] ?? '');
            if (!in_array($t, self::TYPES, true)) {
                $err[] = "$p: tipo inválido ($t)";
            }
            if (in_array($t, ['single', 'multi', 'yesno'], true) && (!is_array($q['options'] ?? null) || count($q['options']) < 2)) {
                $err[] = "$p: opções obrigatórias (mínimo 2)";
            }
            if ($t === 'scale' && (!isset($q['min'], $q['max']) || $q['min'] >= $q['max'])) {
                $err[] = "$p: escala precisa de min < max";
            }
            foreach (['text','help','unit','key','shared_key','section'] as $field) {
                if (isset($q[$field]) && !is_string($q[$field])) $err[] = "$p: $field deve ser texto";
            }
            foreach (['min','max'] as $field) {
                if (isset($q[$field]) && (!is_numeric($q[$field]) || !is_finite((float)$q[$field]))) $err[] = "$p: limite inválido";
            }
            if (isset($q['min'], $q['max']) && $q['min'] > $q['max']) $err[] = "$p: mínimo maior que máximo";
            if (isset($q['section']) && !isset($sections[$q['section']])) {
                $err[] = "$p: seção desconhecida";
            }
            if (isset($q['audience']) && !in_array($q['audience'], ['both', 'professional'], true)) {
                $err[] = "$p: audience inválido";
            }
            if (isset($q['red_flag'])) {
                $rf = $q['red_flag'];
                if (!isset(self::RED_FLAG_ACTIONS[$rf['action'] ?? ''])) {
                    $err[] = "$p: red_flag.action deve ser " . implode(', ', array_keys(self::RED_FLAG_ACTIONS));
                }
                $msg = mb_strtolower((string)($rf['message'] ?? ''), 'UTF-8');
                if ($msg === '') {
                    $err[] = "$p: red_flag.message obrigatório";
                }
                foreach (self::PRESCRIPTIVE as $w) {
                    if (str_contains($msg, $w)) {
                        $err[] = "$p: alerta não pode trazer conduta (\"$w\"): encaminhe para avaliação";
                    }
                }
                if (!is_array($rf['when'] ?? null)) {
                    $err[] = "$p: red_flag.when obrigatório";
                }
            }
        }
        $order = array_flip(array_column($qs, 'key'));
        $shared = array_flip(array_column($qs, 'shared_key'));
        // condições só podem citar perguntas do próprio protocolo ou chaves compartilhadas
        foreach ($qs as $i => $q) {
            foreach (['condition' => $q['condition'] ?? null, 'red_flag' => $q['red_flag']['when'] ?? null] as $what => $c) {
                if ($c === null) {
                    continue;
                }
                foreach (Conditions::referencedKeys($c) as $ref) {
                    if (!isset($keys[$ref]) && !isset($shared[$ref])) {
                        $err[] = 'pergunta ' . ($i + 1) . ": $what cita pergunta inexistente ($ref)";
                    }
                }
                if ($what === 'condition') {
                    foreach (Conditions::referencedKeys($c) as $ref) {
                        $dependency = $order[$ref] ?? null;
                        if ($dependency === null) foreach ($qs as $j => $candidate) if (($candidate['shared_key'] ?? null) === $ref) { $dependency = $j; break; }
                        if ($dependency !== null && $dependency >= $i) $err[] = 'pergunta ' . ($i + 1) . ': condição deve citar uma pergunta anterior';
                    }
                }
                try {
                    Conditions::assertValid($c);
                } catch (InvalidArgumentException $e) {
                    $err[] = 'pergunta ' . ($i + 1) . ": $what — " . $e->getMessage();
                }
            }
        }
        if ($err) {
            throw new InvalidArgumentException('Definição do protocolo inválida: ' . implode('; ', array_unique($err)));
        }
        foreach ($qs as &$q) {
            $q += ['required' => false, 'audience' => 'both', 'section' => null];
        }
        unset($q);
        $d['questions'] = $qs;
        $d['sections'] = $sections;
        $d['suggestion_terms'] = array_values(array_filter(array_map(static fn($t) => mb_strtolower(trim((string)$t), 'UTF-8'), (array)($d['suggestion_terms'] ?? []))));
        return $d;
    }

    /** Hash do conteúdo canônico (detecta alteração de versão aprovada). */
    public static function hash(array $d): string
    {
        return hash('sha256', json_encode(self::canonical($d), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    }

    private static function canonical(mixed $v): mixed
    {
        if (!is_array($v)) {
            return $v;
        }
        if (array_is_list($v)) {
            return array_map([self::class, 'canonical'], $v);
        }
        ksort($v);
        return array_map([self::class, 'canonical'], $v);
    }
}
