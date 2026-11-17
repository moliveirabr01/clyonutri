<?php

declare(strict_types=1);

namespace ClyoNutri\Domain\Nutrition;

use PDO;

/**
 * NI2-886 — revisão profissional das medidas caseiras estimadas ("Estimativa ClyoNutri (revisar)").
 *
 * - Levantamento: contagem por grupo e por uso em planos; exportação CSV para o revisor.
 * - Revisão: aprovar, corrigir e aprovar, remover (uma a uma ou em lote). Fonte obrigatória; grava revisor e data;
 *   cada mudança vai para audit_logs com o antes e o depois.
 * - Importação do CSV revisado: valida linha a linha e rejeita só a linha inválida.
 * - SQL idempotente (MySQL 5.7) das revisões feitas, para aplicar em produção pelo phpMyAdmin.
 *
 * Planos publicados não mudam: a versão publicada é um snapshot (meal_plan_versions.payload_json) e os itens guardam
 * kcal/macros calculados no salvamento. A medida removida não é apagada; só deixa de ser oferecida.
 */
final class HouseholdMeasureReviewService
{
    public const ESTIMATED = HouseholdMeasureConverter::ESTIMATED_SOURCE;
    public const MAX_GRAMS = 5000.0;
    public const CSV_HEADER = ['medida_id', 'alimento_id', 'alimento', 'grupo', 'medida', 'gramas_atuais', 'fonte_atual', 'usos_em_planos', 'situacao', 'acao', 'gramas_revisados', 'fonte_revisao'];

    public function __construct(private PDO $db)
    {
    }

    public static function ready(PDO $db): bool
    {
        return \ClyoNutri\Core\Database\SchemaInfo::hasColumn($db, 'food_household_measures', 'review_status');
    }

    private function usesSql(): string
    {
        return '(SELECT COUNT(*) FROM meal_plan_items i WHERE i.food_id = m.food_id AND LOWER(TRIM(i.measure_unit)) = LOWER(TRIM(m.measure_name)))'
            . ' + (SELECT COUNT(*) FROM meal_plan_substitutions s WHERE s.food_id = m.food_id AND LOWER(TRIM(s.measure_unit)) = LOWER(TRIM(m.measure_name)))';
    }

    /** @return array{pending:int, approved:int, removed:int, by_group: list<array{grupo:string, pendentes:int, usadas:int}>} */
    public function stats(): array
    {
        $row = $this->db->query("SELECT
                SUM(CASE WHEN m.source LIKE '%revisar%' AND (m.review_status IS NULL OR m.review_status = '') THEN 1 ELSE 0 END) AS pending,
                SUM(CASE WHEN m.review_status = 'approved' THEN 1 ELSE 0 END) AS approved,
                SUM(CASE WHEN m.review_status = 'removed' THEN 1 ELSE 0 END) AS removed
            FROM food_household_measures m")->fetch(PDO::FETCH_ASSOC) ?: [];
        $groups = $this->db->query("SELECT COALESCE(NULLIF(TRIM(f.category), ''), 'Sem grupo') AS grupo, COUNT(*) AS pendentes,
                SUM(CASE WHEN (" . $this->usesSql() . ") > 0 THEN 1 ELSE 0 END) AS usadas
            FROM food_household_measures m JOIN foods f ON f.id = m.food_id
            WHERE m.source LIKE '%revisar%' AND (m.review_status IS NULL OR m.review_status = '')
            GROUP BY COALESCE(NULLIF(TRIM(f.category), ''), 'Sem grupo') ORDER BY pendentes DESC, grupo")->fetchAll(PDO::FETCH_ASSOC);
        return [
            'pending' => (int)($row['pending'] ?? 0),
            'approved' => (int)($row['approved'] ?? 0),
            'removed' => (int)($row['removed'] ?? 0),
            'by_group' => array_map(static fn($g) => ['grupo' => (string)$g['grupo'], 'pendentes' => (int)$g['pendentes'], 'usadas' => (int)$g['usadas']], $groups),
        ];
    }

    /** @return list<string> */
    public function groups(): array
    {
        return array_map('strval', $this->db->query("SELECT DISTINCT COALESCE(NULLIF(TRIM(f.category), ''), 'Sem grupo') FROM food_household_measures m JOIN foods f ON f.id = m.food_id WHERE m.source LIKE '%revisar%' OR m.review_status IS NOT NULL ORDER BY 1")->fetchAll(PDO::FETCH_COLUMN));
    }

    /**
     * @param array{status?:string, group?:string, q?:string, order?:string, limit?:int, offset?:int} $f
     * @return list<array<string,mixed>>
     */
    public function list(array $f = []): array
    {
        [$where, $args] = $this->filterSql($f);
        $order = ($f['order'] ?? 'uso') === 'nome' ? 'f.name, m.measure_name' : 'usos DESC, f.name, m.measure_name';
        $limit = max(1, min(2000, (int)($f['limit'] ?? 100)));
        $offset = max(0, (int)($f['offset'] ?? 0));
        $st = $this->db->prepare("SELECT m.id, m.food_id, f.name AS alimento, COALESCE(NULLIF(TRIM(f.category), ''), 'Sem grupo') AS grupo,
                m.measure_name, m.grams_equivalent, m.source, m.review_status, m.reviewer_name, m.reviewed_at, m.grams_before_review,
                (" . $this->usesSql() . ") AS usos
            FROM food_household_measures m JOIN foods f ON f.id = m.food_id
            WHERE {$where} ORDER BY {$order} LIMIT {$limit} OFFSET {$offset}");
        $st->execute($args);
        return $st->fetchAll(PDO::FETCH_ASSOC);
    }

    public function count(array $f = []): int
    {
        [$where, $args] = $this->filterSql($f);
        $st = $this->db->prepare("SELECT COUNT(*) FROM food_household_measures m JOIN foods f ON f.id = m.food_id WHERE {$where}");
        $st->execute($args);
        return (int)$st->fetchColumn();
    }

    /** @return array{0:string,1:list<mixed>} */
    private function filterSql(array $f): array
    {
        $w = [];
        $a = [];
        switch ($f['status'] ?? 'pending') {
            case 'approved':
                $w[] = "m.review_status = 'approved'";
                break;
            case 'removed':
                $w[] = "m.review_status = 'removed'";
                break;
            case 'all':
                $w[] = "(m.source LIKE '%revisar%' OR m.review_status IS NOT NULL)";
                break;
            default:
                $w[] = "m.source LIKE '%revisar%' AND (m.review_status IS NULL OR m.review_status = '')";
        }
        $g = trim((string)($f['group'] ?? ''));
        if ($g !== '') {
            $w[] = "COALESCE(NULLIF(TRIM(f.category), ''), 'Sem grupo') = ?";
            $a[] = $g;
        }
        $q = trim((string)($f['q'] ?? ''));
        if ($q !== '') {
            $w[] = '(LOWER(f.name) LIKE ? OR LOWER(m.measure_name) LIKE ?)';
            $a[] = '%' . mb_strtolower($q) . '%';
            $a[] = '%' . mb_strtolower($q) . '%';
        }
        return [implode(' AND ', $w), $a];
    }

    /** @return array<string,mixed>|null */
    public function find(int $id): ?array
    {
        $st = $this->db->prepare('SELECT m.*, f.name AS alimento FROM food_household_measures m JOIN foods f ON f.id = m.food_id WHERE m.id = ?');
        $st->execute([$id]);
        $r = $st->fetch(PDO::FETCH_ASSOC);
        return $r ?: null;
    }

    /**
     * Aprova (com ou sem correção do peso). Fonte obrigatória.
     * @throws \InvalidArgumentException|\DomainException
     */
    public function approve(int $id, int $reviewerId, string $reviewerName, string $source, ?float $grams = null): array
    {
        $source = trim(preg_replace('/\s+/u', ' ', $source) ?? '');
        if (mb_strlen($source) < 3) {
            throw new \InvalidArgumentException('Informe a fonte do peso (ex.: "Pinheiro, Tabela de medidas caseiras, 5ª ed." ou "pesagem própria").');
        }
        if (mb_strlen($source) > 250) {
            throw new \InvalidArgumentException('A fonte pode ter no máximo 250 caracteres.');
        }
        if (stripos($source, 'revisar') !== false || $source === self::ESTIMATED) {
            throw new \InvalidArgumentException('A fonte não pode ser a própria estimativa. Informe de onde vem o peso conferido.');
        }
        if ($grams !== null && (!is_finite($grams) || $grams <= 0 || $grams > self::MAX_GRAMS)) {
            throw new \InvalidArgumentException('O peso corrigido precisa ser maior que 0 e no máximo 5000 g.');
        }
        $m = $this->requirePending($id);
        $before = (float)$m['grams_equivalent'];
        $after = $grams !== null ? round($grams, 2) : $before;
        $this->db->prepare("UPDATE food_household_measures SET grams_equivalent = ?, grams_before_review = ?, source = ?, source_version = 'revisao-ni2-886',
                review_status = 'approved', reviewed_by = ?, reviewer_name = ?, reviewed_at = ? WHERE id = ?")
            ->execute([$after, $before, $source, $reviewerId ?: null, $reviewerName, date('Y-m-d H:i:s'), $id]);
        $this->audit($reviewerId, abs($after - $before) > 0.001 ? 'HOUSEHOLD_MEASURE_CORRECTED' : 'HOUSEHOLD_MEASURE_APPROVED', $id, [
            'food_id' => (int)$m['food_id'], 'alimento' => $m['alimento'], 'medida' => $m['measure_name'],
            'gramas_antes' => $before, 'gramas_depois' => $after, 'fonte_antes' => $m['source'], 'fonte_depois' => $source, 'revisor' => $reviewerName,
        ]);
        return ['id' => $id, 'grams_before' => $before, 'grams_after' => $after];
    }

    public function remove(int $id, int $reviewerId, string $reviewerName, string $reason): void
    {
        $reason = trim($reason);
        if (mb_strlen($reason) < 3) {
            throw new \InvalidArgumentException('Diga por que a medida sai (ex.: "medida não usual para este alimento").');
        }
        $m = $this->requirePending($id);
        $this->db->prepare("UPDATE food_household_measures SET review_status = 'removed', reviewed_by = ?, reviewer_name = ?, reviewed_at = ? WHERE id = ?")
            ->execute([$reviewerId ?: null, $reviewerName, date('Y-m-d H:i:s'), $id]);
        $this->audit($reviewerId, 'HOUSEHOLD_MEASURE_REMOVED', $id, [
            'food_id' => (int)$m['food_id'], 'alimento' => $m['alimento'], 'medida' => $m['measure_name'],
            'gramas' => (float)$m['grams_equivalent'], 'motivo' => mb_substr($reason, 0, 250), 'revisor' => $reviewerName,
        ]);
    }

    /** @param list<int> $ids @return array{approved:int, errors: list<string>} */
    public function approveBatch(array $ids, int $reviewerId, string $reviewerName, string $source): array
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', $ids), static fn($i) => $i > 0)));
        if ($ids === []) {
            throw new \InvalidArgumentException('Selecione ao menos uma medida.');
        }
        if (count($ids) > 500) {
            throw new \InvalidArgumentException('Aprove no máximo 500 medidas por vez.');
        }
        $ok = 0;
        $errors = [];
        $this->db->beginTransaction();
        try {
            foreach ($ids as $id) {
                try {
                    $this->approve($id, $reviewerId, $reviewerName, $source);
                    $ok++;
                } catch (\DomainException $e) {
                    $errors[] = "#{$id}: " . $e->getMessage();
                }
            }
            $this->db->commit();
        } catch (\Throwable $e) {
            $this->db->rollBack();
            throw $e;
        }
        return ['approved' => $ok, 'errors' => $errors];
    }

    /** @return array<string,mixed> */
    private function requirePending(int $id): array
    {
        $m = $this->find($id);
        if ($m === null) {
            throw new \DomainException('Medida não encontrada.');
        }
        if (!empty($m['review_status'])) {
            throw new \DomainException('Esta medida já foi revisada (' . ($m['review_status'] === 'removed' ? 'removida' : 'aprovada') . ' por ' . ($m['reviewer_name'] ?: 'outra pessoa') . ').');
        }
        if (stripos((string)$m['source'], 'revisar') === false) {
            throw new \DomainException('Esta medida não é uma estimativa a revisar.');
        }
        return $m;
    }

    private function audit(int $userId, string $action, int $measureId, array $details): void
    {
        $this->db->prepare('INSERT INTO audit_logs (clinic_id, user_id, action, resource, resource_id, ip_address, user_agent, details) VALUES (NULL, ?, ?, ?, ?, ?, ?, ?)')
            ->execute([$userId ?: null, $action, 'food_household_measure', (string)$measureId, $_SERVER['REMOTE_ADDR'] ?? null, $_SERVER['HTTP_USER_AGENT'] ?? 'CLI', json_encode($details, JSON_UNESCAPED_UNICODE)]);
    }

    /** CSV (UTF-8 com BOM, ";" para abrir no Excel em pt-BR). */
    public function exportCsv(array $f = []): string
    {
        $f['limit'] = 2000;
        $h = fopen('php://temp', 'w+');
        fwrite($h, "\xEF\xBB\xBF");
        fputcsv($h, self::CSV_HEADER, ';', '"', '');
        $offset = 0;
        do {
            $rows = $this->list($f + ['offset' => $offset]);
            foreach ($rows as $r) {
                $sit = $r['review_status'] === 'approved' ? 'aprovada' : ($r['review_status'] === 'removed' ? 'removida' : 'estimativa');
                fputcsv($h, [$r['id'], $r['food_id'], $r['alimento'], $r['grupo'], $r['measure_name'], self::num((float)$r['grams_equivalent']), $r['source'], (int)$r['usos'], $sit, '', '', ''], ';', '"', '');
            }
            $offset += count($rows);
        } while (count($rows) === 2000);
        rewind($h);
        $csv = (string)stream_get_contents($h);
        fclose($h);
        return $csv;
    }

    private static function num(float $v): string
    {
        return rtrim(rtrim(number_format($v, 2, ',', ''), '0'), ',');
    }

    /**
     * Importa o CSV revisado. Ações: aprovar | corrigir | remover (vazio = ignora a linha).
     * Linha inválida é rejeitada sozinha, com o motivo; as válidas são aplicadas.
     * @return array{applied:int, ignored:int, rejected: list<array{linha:int, motivo:string}>, dry_run:bool}
     */
    public function importCsv(string $csv, int $reviewerId, string $reviewerName, bool $dryRun = false): array
    {
        if (str_starts_with($csv, "\xEF\xBB\xBF")) {
            $csv = substr($csv, 3);
        }
        if (strlen($csv) > 5 * 1024 * 1024) {
            throw new \InvalidArgumentException('Arquivo grande demais (máximo 5 MB).');
        }
        $lines = preg_split('/\r\n|\n|\r/', $csv) ?: [];
        $first = (string)array_shift($lines);
        $delim = substr_count($first, ';') >= substr_count($first, ',') ? ';' : ',';
        $head = array_map(static fn($c) => mb_strtolower(trim((string)$c)), str_getcsv($first, $delim, '"', ''));
        $idx = array_flip($head);
        foreach (['medida_id', 'alimento_id', 'acao', 'gramas_revisados', 'fonte_revisao'] as $need) {
            if (!isset($idx[$need])) {
                throw new \InvalidArgumentException("Cabeçalho sem a coluna \"{$need}\". Use o CSV exportado por esta tela.");
            }
        }
        $applied = 0;
        $ignored = 0;
        $rejected = [];
        foreach ($lines as $n => $line) {
            $lineNo = $n + 2;
            if (trim($line) === '') {
                continue;
            }
            $c = str_getcsv($line, $delim, '"', '');
            $get = static fn(string $k): string => trim((string)($c[$idx[$k]] ?? ''));
            $acao = mb_strtolower($get('acao'));
            if ($acao === '') {
                $ignored++;
                continue;
            }
            $id = (int)$get('medida_id');
            $foodId = (int)$get('alimento_id');
            $fonte = $get('fonte_revisao');
            $gRaw = str_replace(',', '.', $get('gramas_revisados'));
            try {
                $m = $this->find($id);
                if ($m === null || (int)$m['food_id'] !== $foodId) {
                    throw new \InvalidArgumentException('medida_id/alimento_id não existe ou não confere');
                }
                if (!in_array($acao, ['aprovar', 'corrigir', 'remover'], true)) {
                    throw new \InvalidArgumentException("ação \"{$acao}\" inválida (use aprovar, corrigir ou remover)");
                }
                $grams = null;
                if ($acao === 'corrigir') {
                    if ($gRaw === '' || !is_numeric($gRaw) || (float)$gRaw <= 0) {
                        throw new \InvalidArgumentException('gramas_revisados precisa ser um número maior que 0');
                    }
                    $grams = (float)$gRaw;
                }
                if ($acao !== 'remover' && $fonte === '') {
                    throw new \InvalidArgumentException('fonte_revisao é obrigatória');
                }
                if ($dryRun) {
                    $this->requirePending($id);
                    if ($acao !== 'remover') {
                        // mesmas regras da aprovação, sem gravar
                        if (mb_strlen($fonte) < 3 || stripos($fonte, 'revisar') !== false) {
                            throw new \InvalidArgumentException('fonte_revisao inválida');
                        }
                        if ($grams !== null && $grams > self::MAX_GRAMS) {
                            throw new \InvalidArgumentException('gramas_revisados acima de 5000');
                        }
                    }
                } elseif ($acao === 'remover') {
                    $this->remove($id, $reviewerId, $reviewerName, $fonte !== '' ? $fonte : 'removida na revisão por CSV');
                } else {
                    $this->approve($id, $reviewerId, $reviewerName, $fonte, $grams);
                }
                $applied++;
            } catch (\InvalidArgumentException | \DomainException $e) {
                $rejected[] = ['linha' => $lineNo, 'motivo' => $e->getMessage()];
            }
        }
        if (!$dryRun && $applied > 0) {
            $this->audit($reviewerId, 'HOUSEHOLD_MEASURE_CSV_IMPORTED', 0, ['aplicadas' => $applied, 'rejeitadas' => count($rejected), 'ignoradas' => $ignored, 'revisor' => $reviewerName]);
        }
        return ['applied' => $applied, 'ignored' => $ignored, 'rejected' => $rejected, 'dry_run' => $dryRun];
    }

    /**
     * SQL idempotente (MySQL 5.7) com todas as revisões feitas neste banco, para o phpMyAdmin de produção.
     * Casa por alimento (id + nome) e nome da medida; só altera medida que ainda é estimativa sem revisão.
     */
    public function productionSql(): string
    {
        $rows = $this->db->query("SELECT m.food_id, f.name AS alimento, m.measure_name, m.grams_equivalent, m.grams_before_review, m.source, m.review_status, m.reviewer_name, m.reviewed_at
            FROM food_household_measures m JOIN foods f ON f.id = m.food_id WHERE m.review_status IN ('approved','removed') ORDER BY m.reviewed_at, m.id")->fetchAll(PDO::FETCH_ASSOC);
        $q = fn(?string $v): string => $v === null ? 'NULL' : $this->db->quote($v);
        $out = ["-- NI2-886: revisões das medidas caseiras estimadas (" . count($rows) . " medidas). Gerado em " . date('d/m/Y H:i') . '.',
            '-- MySQL 5.7+, idempotente: só altera a medida que ainda está como estimativa sem revisão. Rode depois do SQL da migração 99.',
            "-- Selecione o banco do sistema no phpMyAdmin antes de executar.", ''];
        foreach ($rows as $r) {
            // UPDATE de tabela única (no MySQL a ordem das atribuições só é garantida nele): grams_before_review recebe o peso antigo.
            $cond = static fn(string $t): string => "{$t}.food_id = " . (int)$r['food_id'] . " AND {$t}.measure_name = " . $q($r['measure_name'])
                . " AND {$t}.source LIKE '%revisar%' AND ({$t}.review_status IS NULL OR {$t}.review_status = '')"
                . " AND EXISTS (SELECT 1 FROM foods f WHERE f.id = {$t}.food_id AND f.name = " . $q($r['alimento']) . ')';
            $details = json_encode(['alimento' => $r['alimento'], 'medida' => $r['measure_name'], 'situacao' => $r['review_status'],
                'gramas_antes' => (float)($r['grams_before_review'] ?? $r['grams_equivalent']), 'gramas_depois' => (float)$r['grams_equivalent'],
                'fonte' => $r['review_status'] === 'approved' ? $r['source'] : null, 'revisor' => $r['reviewer_name'], 'origem' => 'sql NI2-886'], JSON_UNESCAPED_UNICODE);
            $action = $r['review_status'] === 'removed' ? 'HOUSEHOLD_MEASURE_REMOVED' : 'HOUSEHOLD_MEASURE_APPROVED';
            // 1º o registro em audit_logs (enquanto a medida ainda está pendente), depois a alteração.
            $out[] = "INSERT INTO audit_logs (clinic_id, user_id, action, resource, resource_id, ip_address, user_agent, details) SELECT NULL, NULL, '{$action}', 'food_household_measure', m.id, NULL, 'SQL NI2-886', " . $q((string)$details)
                . ' FROM food_household_measures m WHERE ' . $cond('m') . ';';
            if ($r['review_status'] === 'removed') {
                $out[] = "UPDATE food_household_measures SET review_status = 'removed', reviewer_name = " . $q($r['reviewer_name']) . ', reviewed_at = ' . $q($r['reviewed_at']) . ' WHERE ' . $cond('food_household_measures') . ';';
            } else {
                $out[] = 'UPDATE food_household_measures SET grams_before_review = grams_equivalent, grams_equivalent = ' . number_format((float)$r['grams_equivalent'], 2, '.', '')
                    . ', source = ' . $q($r['source']) . ", source_version = 'revisao-ni2-886', review_status = 'approved', reviewer_name = " . $q($r['reviewer_name']) . ', reviewed_at = ' . $q($r['reviewed_at']) . ' WHERE ' . $cond('food_household_measures') . ';';
            }
        }
        $out[] = '';
        $out[] = "SELECT review_status, COUNT(*) AS medidas FROM food_household_measures WHERE source LIKE '%revisar%' OR review_status IS NOT NULL GROUP BY review_status;";
        return implode("\n", $out) . "\n";
    }
}
