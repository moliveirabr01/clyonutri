<?php

declare(strict_types=1);

namespace ClyoNutri\Domain\Nutrition;

use ClyoNutri\Core\Audit\AuditLogger;
use ClyoNutri\Core\Auth\TenantContext;
use ClyoNutri\Core\Database\Connection;
use InvalidArgumentException;
use PDO;
use Throwable;

/**
 * Medidas caseiras cadastradas pela clínica (NI2-514, fase 1).
 *
 * Não há, neste código, uma base de medidas caseiras com fonte verificável embutida. Em vez de inventar valores,
 * a clínica cadastra ou importa as suas, sempre com a FONTE de cada valor (obrigatória). Cada medida vale só para a
 * clínica que a cadastrou (clinic_id) e tem prioridade sobre a medida global e sobre a tabela padrão estimada.
 *
 * Importação por CSV: tudo ou nada. Se qualquer linha tiver problema, nada é gravado e o relatório lista as linhas.
 */
class ClinicHouseholdMeasureService
{
    public const MAX_ROWS = 2000;
    public const MAX_BYTES = 1048576;
    public const MIN_GRAMS = 0.1;
    public const MAX_GRAMS = 5000.0;
    public const CSV_HEADER = ['alimento_id', 'taco_numero', 'medida', 'gramas', 'fonte', 'referencia'];

    private PDO $db;

    public function __construct(?PDO $db = null)
    {
        $this->db = $db ?? Connection::getInstance();
    }

    /** O alimento precisa ser do catálogo global (TACO/interno) ou cadastro da própria clínica. */
    private function assertFoodVisible(int $foodId): array
    {
        $st = $this->db->prepare('SELECT id, name, taco_code FROM foods WHERE id = :id AND (clinic_id IS NULL OR clinic_id = :c)');
        $st->execute(['id' => $foodId, 'c' => TenantContext::getClinicId()]);
        $row = $st->fetch(PDO::FETCH_ASSOC);
        if (!$row) {
            throw new InvalidArgumentException('Alimento não encontrado no catálogo desta clínica.');
        }
        return $row;
    }

    /** @return array{name:string,grams:float,source:string,source_ref:?string} */
    public static function validate(string $name, mixed $grams, string $source, ?string $sourceRef): array
    {
        $name = trim(preg_replace('/\s+/u', ' ', $name) ?? '');
        $len = mb_strlen($name);
        if ($len < 2 || $len > 100) {
            throw new InvalidArgumentException('O nome da medida deve ter de 2 a 100 caracteres (ex.: colher de sopa cheia).');
        }
        $key = HouseholdMeasureConverter::normalizeUnit($name);
        if (in_array($key, ['g', 'grama', 'gr', 'mg', 'miligrama', 'kg', 'quilo', 'quilograma'], true)) {
            throw new InvalidArgumentException('Gramas e quilos já são aceitos em qualquer alimento. Cadastre uma medida caseira (colher, fatia, unidade...).');
        }
        $g = self::parseNumber($grams);
        if ($g === null || $g < self::MIN_GRAMS || $g > self::MAX_GRAMS) {
            throw new InvalidArgumentException('Informe o peso em gramas entre 0,1 e 5.000 (use vírgula ou ponto para decimais).');
        }
        $source = trim($source);
        if (mb_strlen($source) < 3 || mb_strlen($source) > 255) {
            throw new InvalidArgumentException('Informe a fonte do valor (ex.: pesagem na clínica, rótulo do fabricante, tabela publicada com edição e página).');
        }
        $ref = $sourceRef !== null ? trim($sourceRef) : null;
        if ($ref !== null && mb_strlen($ref) > 255) {
            throw new InvalidArgumentException('A referência da fonte passa de 255 caracteres.');
        }
        return ['name' => $name, 'grams' => round($g, 2), 'source' => $source, 'source_ref' => $ref === '' ? null : $ref];
    }

    private static function parseNumber(mixed $v): ?float
    {
        if (is_int($v) || is_float($v)) {
            return (float)$v;
        }
        $s = trim((string)$v);
        if ($s === '') {
            return null;
        }
        $s = str_replace(' ', '', $s);
        if (str_contains($s, ',') && str_contains($s, '.')) {
            $s = str_replace('.', '', $s);
        }
        $s = str_replace(',', '.', $s);
        return preg_match('/^\d+(\.\d+)?$/', $s) === 1 ? (float)$s : null;
    }

    /** Cria ou atualiza (mesmo alimento + mesmo nome de medida). @return array{id:int,created:bool} */
    public function upsert(int $foodId, string $name, mixed $grams, string $source, ?string $sourceRef = null): array
    {
        $this->assertFoodVisible($foodId);
        $v = self::validate($name, $grams, $source, $sourceRef);
        $result = $this->write($foodId, $v);
        (new AuditLogger($this->db))->log($result['created'] ? 'CLINIC_HOUSEHOLD_MEASURE_CREATED' : 'CLINIC_HOUSEHOLD_MEASURE_UPDATED', 'clinic_household_measures', (string)$result['id'], [
            'food_id' => $foodId, 'measure' => $v['name'], 'grams' => $v['grams'], 'source' => $v['source'],
        ]);
        return $result;
    }

    /** @param array{name:string,grams:float,source:string,source_ref:?string} $v */
    private function write(int $foodId, array $v): array
    {
        $clinicId = TenantContext::getClinicId();
        $userId = TenantContext::getUserId();
        // Mesmo alimento e mesma medida pela chave normalizada (caixa, acento e plural não criam duplicata).
        $find = $this->db->prepare('SELECT id, measure_name FROM clinic_household_measures WHERE clinic_id = :c AND food_id = :f');
        $find->execute(['c' => $clinicId, 'f' => $foodId]);
        $id = false;
        $newKey = HouseholdMeasureConverter::normalizeUnit($v['name']);
        foreach ($find->fetchAll(PDO::FETCH_ASSOC) as $existing) {
            if (HouseholdMeasureConverter::normalizeUnit((string)$existing['measure_name']) === $newKey) {
                $id = $existing['id'];
                break;
            }
        }
        if ($id !== false) {
            $this->db->prepare('UPDATE clinic_household_measures SET measure_name = :n, grams_equivalent = :g, source = :s, source_ref = :r, updated_by_user_id = :u, updated_at = :now
                WHERE id = :id AND clinic_id = :c')
                ->execute(['n' => $v['name'], 'g' => $v['grams'], 's' => $v['source'], 'r' => $v['source_ref'], 'u' => $userId, 'now' => date('Y-m-d H:i:s'), 'id' => (int)$id, 'c' => $clinicId]);
            return ['id' => (int)$id, 'created' => false];
        }
        $this->db->prepare('INSERT INTO clinic_household_measures (clinic_id, food_id, measure_name, grams_equivalent, source, source_ref, created_by_user_id, updated_by_user_id)
            VALUES (:c, :f, :n, :g, :s, :r, :u, :u2)')
            ->execute(['c' => $clinicId, 'f' => $foodId, 'n' => $v['name'], 'g' => $v['grams'], 's' => $v['source'], 'r' => $v['source_ref'], 'u' => $userId, 'u2' => $userId]);
        return ['id' => (int)$this->db->lastInsertId(), 'created' => true];
    }

    public function delete(int $id): void
    {
        $clinicId = TenantContext::getClinicId();
        $st = $this->db->prepare('SELECT id, food_id, measure_name, grams_equivalent FROM clinic_household_measures WHERE id = :id AND clinic_id = :c');
        $st->execute(['id' => $id, 'c' => $clinicId]);
        $row = $st->fetch(PDO::FETCH_ASSOC);
        if (!$row) {
            throw new InvalidArgumentException('Medida não encontrada.');
        }
        $this->db->prepare('DELETE FROM clinic_household_measures WHERE id = :id AND clinic_id = :c')->execute(['id' => $id, 'c' => $clinicId]);
        (new AuditLogger($this->db))->log('CLINIC_HOUSEHOLD_MEASURE_DELETED', 'clinic_household_measures', (string)$id, [
            'food_id' => (int)$row['food_id'], 'measure' => $row['measure_name'], 'grams' => (float)$row['grams_equivalent'],
        ]);
    }

    /** @return list<array<string,mixed>> medidas da clínica (opcionalmente de um alimento), com o nome do alimento */
    public function list(?int $foodId = null, string $q = '', int $limit = 100, int $offset = 0): array
    {
        $sql = 'SELECT m.id, m.food_id, f.name AS food_name, f.taco_code, m.measure_name, m.grams_equivalent, m.source, m.source_ref, m.updated_at
            FROM clinic_household_measures m JOIN foods f ON f.id = m.food_id
            WHERE m.clinic_id = :c';
        $p = ['c' => TenantContext::getClinicId()];
        if ($foodId !== null) {
            $sql .= ' AND m.food_id = :f';
            $p['f'] = $foodId;
        }
        if (trim($q) !== '') {
            $sql .= ' AND (f.name LIKE :q OR m.measure_name LIKE :q)';
            $p['q'] = '%' . str_replace(['%', '_'], ['\\%', '\\_'], trim($q)) . '%';
        }
        $sql .= ' ORDER BY f.name, m.measure_name LIMIT ' . max(1, min(500, $limit)) . ' OFFSET ' . max(0, $offset);
        $st = $this->db->prepare($sql);
        $st->execute($p);
        return $st->fetchAll(PDO::FETCH_ASSOC);
    }

    /** Busca de alimentos visíveis para a clínica (catálogo global e cadastros dela), para a tela de cadastro de medidas. */
    public function searchFoods(string $q, int $limit = 15): array
    {
        $q = trim($q);
        if (mb_strlen($q) < 2) {
            return [];
        }
        // v44: a mesma busca do editor de plano e da Tabela de Alimentos (FoodSearchIndex): termos em qualquer ordem,
        // sem acento, plural simples e sinônimos regionais ("aipim" acha Mandioca antes de "Bolo, pronto, aipim").
        // Antes era name LIKE '%termo%' em ordem alfabética.
        \ClyoNutri\Domain\Food\FoodSearchIndex::sync($this->db);
        $terms = \ClyoNutri\Domain\Food\FoodSearchIndex::terms($q);
        if ($terms === []) {
            return [];
        }
        $fs = \ClyoNutri\Domain\Food\FoodSearchIndex::sql($terms, 'search_text', 'name', $this->db, ['synonyms' => true]);
        $tenant = '(clinic_id IS NULL OR clinic_id = :c)';
        if (\ClyoNutri\Core\Database\SchemaInfo::hasColumn($this->db, 'foods', 'is_current')) {
            $tenant .= ' AND COALESCE(is_current, 1) = 1'; // versão antiga de fonte externa fica fora, como no editor
        }
        $st = $this->db->prepare("SELECT id, name, taco_code, source FROM foods WHERE {$tenant} AND {$fs['where']} ORDER BY {$fs['order']}, id LIMIT " . max(1, min(50, $limit)));
        $st->execute(['c' => TenantContext::getClinicId()] + $fs['where_params'] + $fs['order_params']);
        $rows = $st->fetchAll(PDO::FETCH_ASSOC);
        foreach ($rows as &$r) {
            $r['synonym_match'] = \ClyoNutri\Domain\Food\FoodSearchIndex::synonymLabel($terms, (string)$r['name']);
        }
        unset($r);
        return $rows;
    }

    /**
     * Cobertura do catálogo: quantos alimentos têm medida (da clínica ou global). Mostra o tamanho do buraco.
     * @return array{foods:int,with_clinic:int,with_global:int,with_any:int,clinic_measures:int}
     */
    public function coverage(): array
    {
        $c = TenantContext::getClinicId();
        $one = function (string $sql, array $p = []): int {
            $st = $this->db->prepare($sql);
            $st->execute($p);
            return (int)$st->fetchColumn();
        };
        $visible = '(clinic_id IS NULL OR clinic_id = :c)';
        return [
            'foods' => $one("SELECT COUNT(*) FROM foods WHERE {$visible}", ['c' => $c]),
            'with_clinic' => $one('SELECT COUNT(DISTINCT food_id) FROM clinic_household_measures WHERE clinic_id = :c', ['c' => $c]),
            'with_global' => $one("SELECT COUNT(DISTINCT m.food_id) FROM food_household_measures m JOIN foods f ON f.id = m.food_id WHERE f.clinic_id IS NULL OR f.clinic_id = :c", ['c' => $c]),
            'with_any' => $one("SELECT COUNT(*) FROM foods f WHERE (f.clinic_id IS NULL OR f.clinic_id = :c)
                AND (EXISTS (SELECT 1 FROM clinic_household_measures m WHERE m.clinic_id = :c2 AND m.food_id = f.id)
                  OR EXISTS (SELECT 1 FROM food_household_measures g WHERE g.food_id = f.id))", ['c' => $c, 'c2' => $c]),
            'clinic_measures' => $one('SELECT COUNT(*) FROM clinic_household_measures WHERE clinic_id = :c', ['c' => $c]),
        ];
    }

    /** Modelo de CSV (cabeçalho e uma linha de exemplo claramente fictícia). */
    public static function csvTemplate(): string
    {
        $rows = [
            self::CSV_HEADER,
            ['', '', 'EXEMPLO colher de sopa cheia (apague esta linha)', '12,5', 'pesagem na clínica em balança de cozinha', 'ficha 1'],
        ];
        $out = "\xEF\xBB\xBF";
        foreach ($rows as $r) {
            $out .= implode(';', array_map(static fn($v) => preg_match('/[;"\r\n]/', (string)$v) ? '"' . str_replace('"', '""', (string)$v) . '"' : (string)$v, $r)) . "\r\n";
        }
        return $out;
    }

    /**
     * Valida e importa um CSV. Cada linha identifica o alimento por alimento_id OU taco_numero.
     * Linhas sem "fonte" usam $defaultSource (obrigatória no formulário). Tudo ou nada.
     *
     * @return array{ok:bool,imported:int,created:int,updated:int,errors:list<string>}
     */
    public function importCsv(string $content, string $defaultSource): array
    {
        $errors = [];
        if (strlen($content) > self::MAX_BYTES) {
            return ['ok' => false, 'imported' => 0, 'created' => 0, 'updated' => 0, 'errors' => ['O arquivo passa de 1 MB.']];
        }
        if (str_starts_with($content, "\xEF\xBB\xBF")) {
            $content = substr($content, 3);
        }
        if (!mb_check_encoding($content, 'UTF-8')) {
            $converted = @mb_convert_encoding($content, 'UTF-8', 'ISO-8859-1');
            $content = is_string($converted) ? $converted : $content;
        }
        $lines = preg_split('/\r\n|\n|\r/', $content) ?: [];
        $lines = array_values(array_filter($lines, static fn($l) => trim($l) !== ''));
        if ($lines === []) {
            return ['ok' => false, 'imported' => 0, 'created' => 0, 'updated' => 0, 'errors' => ['O arquivo está vazio.']];
        }
        $delim = substr_count($lines[0], ';') >= substr_count($lines[0], ',') ? ';' : ',';
        $header = array_map(static fn($h) => self::headerKey((string)$h), str_getcsv($lines[0], $delim, '"', ''));
        foreach (['medida', 'gramas'] as $need) {
            if (!in_array($need, $header, true)) {
                $errors[] = "Falta a coluna «{$need}» no cabeçalho. Use o modelo para baixar o formato.";
            }
        }
        if (!in_array('alimento_id', $header, true) && !in_array('taco_numero', $header, true)) {
            $errors[] = 'Falta a coluna «alimento_id» ou «taco_numero» no cabeçalho.';
        }
        if ($errors !== []) {
            return ['ok' => false, 'imported' => 0, 'created' => 0, 'updated' => 0, 'errors' => $errors];
        }
        $dataLines = array_slice($lines, 1);
        if (count($dataLines) > self::MAX_ROWS) {
            return ['ok' => false, 'imported' => 0, 'created' => 0, 'updated' => 0, 'errors' => ['O arquivo passa de ' . self::MAX_ROWS . ' linhas. Divida em arquivos menores.']];
        }

        $plan = [];
        $seen = [];
        foreach ($dataLines as $i => $line) {
            $n = $i + 2;
            $cells = str_getcsv($line, $delim, '"', '');
            $row = [];
            foreach ($header as $k => $h) {
                $row[$h] = trim((string)($cells[$k] ?? ''));
            }
            try {
                $food = $this->resolveFood($row['alimento_id'] ?? '', $row['taco_numero'] ?? '');
                $source = ($row['fonte'] ?? '') !== '' ? $row['fonte'] : $defaultSource;
                $v = self::validate($row['medida'] ?? '', $row['gramas'] ?? '', $source, $row['referencia'] ?? null);
                $dupKey = $food['id'] . '|' . $v['name'];
                if (isset($seen[$dupKey])) {
                    throw new InvalidArgumentException("a medida «{$v['name']}» aparece duas vezes para o mesmo alimento (linha {$seen[$dupKey]}).");
                }
                $seen[$dupKey] = $n;
                $plan[] = ['food_id' => (int)$food['id'], 'v' => $v];
            } catch (InvalidArgumentException $e) {
                $errors[] = "Linha {$n}: " . $e->getMessage();
            }
        }
        if ($errors !== []) {
            $total = count($errors);
            $errors = array_slice($errors, 0, 20);
            if ($total > 20) {
                $errors[] = 'Há mais ' . ($total - 20) . ' linhas com problema. Nada foi importado.';
            } else {
                $errors[] = 'Nada foi importado. Corrija as linhas e envie de novo.';
            }
            return ['ok' => false, 'imported' => 0, 'created' => 0, 'updated' => 0, 'errors' => $errors];
        }
        if ($plan === []) {
            return ['ok' => false, 'imported' => 0, 'created' => 0, 'updated' => 0, 'errors' => ['O arquivo não tem linhas de dados.']];
        }

        $created = $updated = 0;
        $own = !$this->db->inTransaction();
        if ($own) {
            $this->db->beginTransaction();
        }
        try {
            foreach ($plan as $item) {
                $r = $this->write($item['food_id'], $item['v']);
                $r['created'] ? $created++ : $updated++;
            }
            (new AuditLogger($this->db))->log('CLINIC_HOUSEHOLD_MEASURES_IMPORTED', 'clinic_household_measures', null, [
                'rows' => count($plan), 'created' => $created, 'updated' => $updated, 'default_source' => $defaultSource,
            ]);
            if ($own) {
                $this->db->commit();
            }
        } catch (Throwable $e) {
            if ($own && $this->db->inTransaction()) {
                $this->db->rollBack();
            }
            throw $e;
        }
        return ['ok' => true, 'imported' => count($plan), 'created' => $created, 'updated' => $updated, 'errors' => []];
    }

    private static function headerKey(string $h): string
    {
        $h = mb_strtolower(trim($h));
        $h = strtr($h, ['á' => 'a', 'à' => 'a', 'ã' => 'a', 'â' => 'a', 'é' => 'e', 'ê' => 'e', 'í' => 'i', 'ó' => 'o', 'ô' => 'o', 'õ' => 'o', 'ú' => 'u', 'ç' => 'c']);
        $h = preg_replace('/[^a-z0-9]+/', '_', $h) ?? $h;
        $h = trim($h, '_');
        return match ($h) {
            'alimento', 'id_alimento', 'food_id' => 'alimento_id',
            'taco', 'taco_n', 'taco_no', 'numero_taco', 'taco_numero', 'taco_code' => 'taco_numero',
            'medida_caseira', 'measure', 'measure_name' => 'medida',
            'gramas_equivalentes', 'peso_g', 'g', 'grams' => 'gramas',
            'fonte_do_valor', 'source' => 'fonte',
            'ref', 'referencia_da_fonte', 'source_ref' => 'referencia',
            default => $h,
        };
    }

    /** @return array{id:int,name:string} */
    private function resolveFood(string $foodId, string $tacoNumber): array
    {
        if ($foodId !== '') {
            if (!ctype_digit($foodId)) {
                throw new InvalidArgumentException("alimento_id «{$foodId}» não é um número.");
            }
            return $this->assertFoodVisible((int)$foodId);
        }
        if ($tacoNumber !== '') {
            if (!ctype_digit($tacoNumber)) {
                throw new InvalidArgumentException("taco_numero «{$tacoNumber}» não é um número.");
            }
            $st = $this->db->prepare('SELECT id, name, taco_code FROM foods WHERE taco_code = :t AND clinic_id IS NULL ORDER BY id LIMIT 1');
            $st->execute(['t' => (int)$tacoNumber]);
            $row = $st->fetch(PDO::FETCH_ASSOC);
            if (!$row) {
                throw new InvalidArgumentException("não há alimento com nº TACO {$tacoNumber} no catálogo.");
            }
            return $row;
        }
        throw new InvalidArgumentException('informe alimento_id ou taco_numero.');
    }
}
