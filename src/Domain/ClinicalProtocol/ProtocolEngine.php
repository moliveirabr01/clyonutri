<?php

declare(strict_types=1);

namespace ClyoNutri\Domain\ClinicalProtocol;

use InvalidArgumentException;

/**
 * NI2-635: monta o questionário a partir dos módulos ativos do paciente (sem banco; regra pura e testável).
 *
 * - Deduplicação: perguntas com o mesmo shared_key em módulos diferentes aparecem UMA vez ("usada por: …").
 *   Se o tipo ou as opções divergirem, a combinação é recusada (ProtocolConflict): melhor não montar do que
 *   misturar respostas incompatíveis.
 * - Ramificação: a pergunta aparece se a condição de algum módulo que a usa for satisfeita (ou se não houver).
 * - Endereço da resposta: shared_key ("base.medicamentos") ou "codigo_do_protocolo.key".
 * - Completude por módulo, erros de validação por resposta e sinais de alerta (encaminhamento, nunca conduta).
 */
final class ProtocolEngine
{
    /**
     * @param list<array{code:string, name:string, version:int, version_id:int, definition:array}> $modules (versões aprovadas)
     * @param array<string,mixed> $answers
     * @param string $audience 'professional' (tudo) ou 'patient' (pré-consulta: sem perguntas só do profissional)
     */
    public static function compose(array $modules, array $answers, string $audience = 'professional'): array
    {
        $items = [];      // answer_key → item
        $localToKey = []; // code → key → answer_key
        foreach ($modules as $m) {
            foreach ($m['definition']['questions'] as $q) {
                $ak = isset($q['shared_key']) ? (string)$q['shared_key'] : $m['code'] . '.' . $q['key'];
                $localToKey[$m['code']][$q['key']] = $ak;
                if ($audience === 'patient' && ($q['audience'] ?? 'both') === 'professional') {
                    continue;
                }
                if (isset($items[$ak])) {
                    $prev = $items[$ak];
                    if ($prev['type'] !== $q['type'] || ($prev['options'] ?? null) != ($q['options'] ?? null) || ($prev['min'] ?? null) != ($q['min'] ?? null) || ($prev['max'] ?? null) != ($q['max'] ?? null) || ($prev['unit'] ?? null) != ($q['unit'] ?? null)) {
                        throw new ProtocolConflict(sprintf('A pergunta compartilhada "%s" tem formatos diferentes em %s e %s. Ajuste um dos protocolos antes de usá-los juntos.',
                            $ak, implode(', ', $prev['modules']), $m['name']));
                    }
                    $items[$ak]['modules'][] = $m['name'];
                    $items[$ak]['module_codes'][] = $m['code'];
                    $items[$ak]['uses'][] = ['code' => $m['code'], 'condition' => $q['condition'] ?? null, 'required' => (bool)$q['required']];
                    continue;
                }
                $items[$ak] = [
                    'answer_key' => $ak, 'text' => $q['text'], 'help' => $q['help'] ?? null, 'type' => $q['type'],
                    'options' => $q['options'] ?? null, 'min' => $q['min'] ?? null, 'max' => $q['max'] ?? null, 'unit' => $q['unit'] ?? null,
                    'section' => $q['section'] ? ($m['definition']['sections'][$q['section']] ?? $q['section']) : $m['name'],
                    'modules' => [$m['name']], 'module_codes' => [$m['code']], 'shared' => isset($q['shared_key']),
                    'uses' => [['code' => $m['code'], 'condition' => $q['condition'] ?? null, 'required' => (bool)$q['required']]],
                ];
            }
        }
        $visibleAnswers = $answers;
        $answerOfIn = static function (string $code) use (&$visibleAnswers, $localToKey): callable {
            return static function (string $ref) use (&$visibleAnswers, $localToKey, $code) {
                $ak = str_contains($ref, '.') ? $ref : ($localToKey[$code][$ref] ?? $code . '.' . $ref);
                return $visibleAnswers[$ak] ?? null;
            };
        };

        $errors = [];
        $completeness = [];
        foreach ($modules as $m) {
            $completeness[$m['code']] = ['name' => $m['name'], 'version' => $m['version'], 'required' => 0, 'answered' => 0];
        }
        foreach ($items as $ak => &$it) {
            $visible = false;
            $required = false;
            foreach ($it['uses'] as $u) {
                $ok = $u['condition'] === null || Conditions::holds($u['condition'], $answerOfIn($u['code']));
                $visible = $visible || $ok;
                $required = $required || ($ok && $u['required']);
            }
            if (!$visible) unset($visibleAnswers[$ak]);
            $it['visible'] = $visible;
            $it['required'] = $required;
            $val = $answers[$ak] ?? null;
            $answered = !($val === null || $val === '' || $val === []);
            $it['answered'] = $answered && $visible;
            if ($visible && $answered && ($e = self::typeError($it, $val)) !== null) {
                $errors[$ak] = $e;
            }
            $requiredFor = [];
            foreach ($it['uses'] as $use) {
                if ($use['required'] && ($use['condition'] === null || Conditions::holds($use['condition'], $answerOfIn($use['code'])))) $requiredFor[$use['code']] = true;
            }
            if ($visible) foreach (array_keys($requiredFor) as $code) {
                $completeness[$code]['required']++;
                if ($answered && !isset($errors[$ak])) $completeness[$code]['answered']++;
            }
            unset($it['uses']);
        }
        unset($it);
        foreach ($completeness as &$c) {
            $c['pct'] = $c['required'] ? (int)floor($c['answered'] * 100 / $c['required']) : 100;
            $c['complete'] = $c['answered'] === $c['required'];
        }
        unset($c);

        $flags = [];
        foreach ($modules as $m) {
            foreach ($m['definition']['questions'] as $q) {
                if (!isset($q['red_flag'])) {
                    continue;
                }
                $ak = $localToKey[$m['code']][$q['key']];
                $vis = isset($items[$ak]) && $items[$ak]['visible'];
                if ($vis && Conditions::holds($q['red_flag']['when'], $answerOfIn($m['code']))) {
                    $flags[] = ['module' => $m['name'], 'answer_key' => $ak, 'action' => $q['red_flag']['action'],
                        'action_label' => ProtocolDefinition::RED_FLAG_ACTIONS[$q['red_flag']['action']], 'message' => $q['red_flag']['message']];
                }
            }
        }
        usort($flags, static fn($a, $b) => array_search($a['action'], array_keys(ProtocolDefinition::RED_FLAG_ACTIONS), true) <=> array_search($b['action'], array_keys(ProtocolDefinition::RED_FLAG_ACTIONS), true));

        return [
            'items' => array_values($items),
            'visible_count' => count(array_filter($items, static fn($i) => $i['visible'])),
            'errors' => $errors,
            'completeness' => $completeness,
            'red_flags' => $flags,
        ];
    }

    /** Respostas só das chaves que existem no questionário (o resto é descartado, nunca gravado). */
    public static function sanitize(array $composed, array $answers): array
    {
        $allowed = array_column($composed['items'], null, 'answer_key');
        $out = [];
        foreach ($answers as $k => $v) {
            if (!isset($allowed[$k])) {
                continue;
            }
            if (is_array($v)) {
                $v = array_values(array_map(static fn($x) => mb_substr(trim((string)$x), 0, 100, 'UTF-8'), array_filter($v, 'is_scalar')));
            } elseif ($v !== null) {
                $v = mb_substr(trim((string)$v), 0, 4000, 'UTF-8');
            }
            $out[$k] = $v;
        }
        return $out;
    }

    private static function typeError(array $it, mixed $v): ?string
    {
        if (is_array($v) && $it['type'] !== 'multi' || is_object($v)) return 'Resposta inválida.';
        $opts = is_array($it['options']) ? array_map('strval', array_keys($it['options'])) : [];
        switch ($it['type']) {
            case 'number':
            case 'scale':
                if (!is_numeric($v) || !is_finite((float)$v)) {
                    return 'Informe um número.';
                }
                if ($it['min'] !== null && $v < $it['min'] || $it['max'] !== null && $v > $it['max']) {
                    return 'Valor fora do intervalo (' . $it['min'] . ' a ' . $it['max'] . ($it['unit'] ? ' ' . $it['unit'] : '') . ').';
                }
                return null;
            case 'single':
            case 'yesno':
                return in_array((string)$v, $opts, true) ? null : 'Escolha uma das opções.';
            case 'multi':
                return is_array($v) && !array_diff(array_map('strval', $v), $opts) ? null : 'Escolha entre as opções.';
            case 'date':
                $d = \DateTimeImmutable::createFromFormat('!Y-m-d', (string)$v);
                return $d && $d->format('Y-m-d') === (string)$v ? null : 'Data inválida.';
            default:
                return is_string($v) || is_numeric($v) ? null : 'Resposta inválida.';
        }
    }
}
