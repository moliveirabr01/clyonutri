<?php

declare(strict_types=1);

namespace ClyoNutri\Domain\ClinicalProtocol;

/**
 * NI2-640: detecção de termos clínicos em texto livre por regras transparentes (sem serviço externo).
 *
 * O texto é dividido em trechos (frase e vírgula; "mas"/"porém" também separam). Em cada trecho onde o termo aparece:
 *  - negação antes do termo, no mesmo trecho ("não", "nega", "nunca", "sem", "descartou"…) ou depois ("… descartado", "… negativo") → ignora;
 *  - outra pessoa no trecho (mãe, pai, irmã, avô, filho, esposa, família, "histórico familiar"…) → ignora;
 *    um trecho que começa com "que" herda a pessoa do trecho anterior ("minha mãe, que tem diabetes");
 *  - dúvida ("acho que", "talvez", "suspeita", "investigando"…) → sugere, marcado como incerto;
 *  - passado ("já tive", "tive", "tratei") → sugere, marcado como relato antigo.
 * Comparação sem acentos e sem diferença de maiúsculas; o termo precisa ser palavra inteira.
 * Resultado sempre explica o motivo (trecho do texto), inclusive do que foi ignorado.
 */
final class TermDetector
{
    private const NEG_BEFORE = '/(?:^|\s)(nao|nega|negou|negam|nunca|sem|jamais|descart\w*|exclu\w*|ausencia de|livre de|nenhum\w*)(?:\s|$)/';
    private const NEG_AFTER = '/^\s*(?:foi\s+|esta\s+|ja\s+)?(descartad\w*|afastad\w*|exclu\w*|negativ\w*|nao confirmad\w*)/';
    private const OTHER = '/(?:^|\s)(mae|pai|irma|irmao|irmaos|avo|avos|tia|tio|filho|filha|filhos|esposo|esposa|marido|namorad\w|primo|prima|parente\w*|familia\w*|vizinh\w*|amig\w*|sogr\w*|cunhad\w*)(?:\s|$)/';
    private const DOUBT = '/(?:^|\s)(acho que|acha que|talvez|suspeit\w*|investig\w*|possivel\w*|provavel\w*|pode ser|sera que|nao sei se|duvida)(?:\s|$)/';
    private const PAST = '/(?:^|\s)(ja tive|tive|teve|tratei|tratou|curad\w*|ja tratei)(?:\s|$)/';

    /**
     * @param list<string> $terms
     * @return array{match: ?array{term:string,snippet:string,certainty:string}, skipped: list<array{term:string,snippet:string,reason:string}>}
     */
    public static function detect(string $text, array $terms): array
    {
        $skipped = [];
        $prevOther = false;
        foreach (self::clauses($text) as $clause) {
            $n = self::norm($clause);
            $other = (bool)preg_match(self::OTHER, $n);
            if (!$other && $prevOther && preg_match('/^(que|a qual|o qual|quem)\s/', $n)) {
                $other = true;
            }
            $prevOther = $other;
            foreach ($terms as $term) {
                $t = self::norm((string)$term);
                if ($t === '' || !preg_match('/(?<![a-z0-9])' . preg_quote($t, '/') . '(?![a-z0-9])/', $n, $m, PREG_OFFSET_CAPTURE)) {
                    continue;
                }
                $before = substr($n, 0, (int)$m[0][1]);
                $after = substr($n, (int)$m[0][1] + strlen($t));
                $snippet = self::snippet($clause);
                $w = preg_split('/\s+/', trim($before)) ?: [];
                $window = str_replace(['nao sei se', 'nao tenho certeza se'], 'duvida', implode(' ', $w));
                // "não fuma e tem diabetes": um novo verbo depois de "e"/"ou" encerra o alcance da negação
                $parts = preg_split('/(?:^|\s)(?:e|ou)\s+(?=(?:tem|tenho|teve|tive|relata|apresenta|possui|sofre|convive)(?:\s|$))/', $window) ?: [$window];
                $window = (string)end($parts);
                if (preg_match(self::NEG_BEFORE, $window) || preg_match(self::NEG_AFTER, $after)) {
                    $skipped[] = ['term' => (string)$term, 'snippet' => $snippet, 'reason' => 'negação'];
                    continue;
                }
                if ($other) {
                    $skipped[] = ['term' => (string)$term, 'snippet' => $snippet, 'reason' => 'outra pessoa ou histórico familiar'];
                    continue;
                }
                $certainty = (str_contains($clause, '?') || preg_match(self::DOUBT, $n)) ? 'uncertain' : (preg_match(self::PAST, $before) ? 'past' : 'stated');
                return ['match' => ['term' => (string)$term, 'snippet' => $snippet, 'certainty' => $certainty], 'skipped' => $skipped];
            }
        }
        return ['match' => null, 'skipped' => $skipped];
    }

    public static function explain(array $match): string
    {
        $note = ['uncertain' => ' O texto indica dúvida.', 'past' => ' O texto fala de algo no passado.'][$match['certainty']] ?? '';
        return mb_substr('Sugerido porque o texto diz: "' . $match['snippet'] . '".' . $note, 0, 480, 'UTF-8');
    }

    /** minúsculas, sem acentos, espaços simples */
    public static function norm(string $s): string
    {
        $s = mb_strtolower($s, 'UTF-8');
        $s = strtr($s, ['á' => 'a', 'à' => 'a', 'â' => 'a', 'ã' => 'a', 'ä' => 'a', 'é' => 'e', 'ê' => 'e', 'è' => 'e', 'ë' => 'e',
            'í' => 'i', 'ì' => 'i', 'î' => 'i', 'ï' => 'i', 'ó' => 'o', 'ô' => 'o', 'õ' => 'o', 'ò' => 'o', 'ö' => 'o',
            'ú' => 'u', 'ù' => 'u', 'û' => 'u', 'ü' => 'u', 'ç' => 'c', 'ñ' => 'n']);
        $s = preg_replace('/[^a-z0-9]+/', ' ', $s) ?? '';
        return trim($s);
    }

    /** @return list<string> */
    private static function clauses(string $text): array
    {
        $parts = preg_split('/[.;:\n\r,()]+|(?<=[!?])\s+|\s+(?:mas|porem|porém|contudo|entretanto)\s+/iu', $text) ?: [];
        return array_values(array_filter(array_map('trim', $parts), static fn($p) => $p !== ''));
    }

    private static function snippet(string $clause): string
    {
        $c = preg_replace('/\s+/u', ' ', trim($clause)) ?? '';
        return mb_strlen($c, 'UTF-8') > 160 ? mb_substr($c, 0, 157, 'UTF-8') . '…' : $c;
    }
}
