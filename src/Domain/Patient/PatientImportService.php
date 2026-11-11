<?php

declare(strict_types=1);

namespace ClyoNutri\Domain\Patient;

use ClyoNutri\Core\Audit\AuditLogger;
use ClyoNutri\Core\Auth\TenantContext;
use ClyoNutri\Core\Database\Connection;
use PDO;

/**
 * NI2-522 (v42): importação de pacientes por planilha CSV, sempre em três passos.
 *
 *   1. prévia: lê o arquivo, reconhece as colunas pelo cabeçalho, valida cada linha com as mesmas regras do
 *      cadastro manual e marca duplicados (na clínica e dentro do próprio arquivo). Nada vai para patients.
 *   2. confirmar: grava as linhas válidas numa única transação (tudo ou nada), com consentimento "não coletado",
 *      e apaga os dados brutos guardados para a prévia.
 *   3. desfazer: remove só os pacientes criados por este lote que ainda não têm nenhum registro ligado.
 *
 * O mesmo arquivo (hash SHA-256) não é importado duas vezes na mesma clínica. Prévias abandonadas são apagadas
 * depois de 24 horas. Tudo filtrado por clinic_id da sessão.
 */
final class PatientImportService
{
    public const MAX_BYTES = 2_000_000;
    public const MAX_ROWS = 1000;
    public const PREVIEW_TTL_HOURS = 24;
    public const FIELDS = ['name', 'email', 'phone', 'birth_date', 'gender', 'notes'];
    public const FIELD_LABELS = [
        'name' => 'Nome', 'email' => 'E-mail', 'phone' => 'Celular', 'birth_date' => 'Data de nascimento',
        'gender' => 'Gênero', 'notes' => 'Observações',
    ];
    /** Cabeçalhos aceitos (já normalizados: minúsculas, sem acento, só letras e números). */
    private const ALIASES = [
        'name' => ['nome', 'nomecompleto', 'paciente', 'nomedopaciente', 'name', 'fullname'],
        'email' => ['email', 'e-mail', 'emaildopaciente', 'correioeletronico'],
        'phone' => ['celular', 'telefone', 'whatsapp', 'fone', 'tel', 'telefonecelular', 'phone', 'mobile'],
        'birth_date' => ['datadenascimento', 'nascimento', 'datanascimento', 'dtnascimento', 'birthdate', 'dataniver', 'aniversario'],
        'gender' => ['genero', 'sexo', 'gender'],
        'notes' => ['observacoes', 'observacao', 'obs', 'notas', 'notes', 'anotacoes'],
    ];

    private PDO $db;

    public function __construct(?PDO $db = null)
    {
        $this->db = $db ?? Connection::getInstance();
    }

    private static function key(string $header): string
    {
        $h = strtr(mb_strtolower(trim($header), 'UTF-8'), [
            'á' => 'a', 'à' => 'a', 'â' => 'a', 'ã' => 'a', 'é' => 'e', 'ê' => 'e', 'í' => 'i', 'ó' => 'o', 'ô' => 'o',
            'õ' => 'o', 'ú' => 'u', 'ü' => 'u', 'ç' => 'c',
        ]);
        return preg_replace('/[^a-z0-9]+/', '', $h) ?? '';
    }

    /**
     * Lê o CSV (separador ; ou , detectado pelo cabeçalho; BOM e Latin-1 aceitos).
     * @return array{header: list<string>, rows: list<array{line:int, cells:list<string>}>}
     */
    public static function parse(string $content): array
    {
        if (strlen($content) > self::MAX_BYTES) {
            throw new \InvalidArgumentException('O arquivo passa de 2 MB. Divida a planilha em partes menores.');
        }
        if (str_starts_with($content, "\xEF\xBB\xBF")) {
            $content = substr($content, 3);
        }
        if (!mb_check_encoding($content, 'UTF-8')) {
            $content = mb_convert_encoding($content, 'UTF-8', 'ISO-8859-1'); // Excel antigo salva em Latin-1
        }
        $content = str_replace(["\r\n", "\r"], "\n", $content);
        if (trim($content) === '') {
            throw new \InvalidArgumentException('O arquivo está vazio.');
        }
        $firstLine = strtok($content, "\n") ?: '';
        $delimiter = substr_count($firstLine, ';') >= substr_count($firstLine, ',') ? ';' : ',';
        $fh = fopen('php://temp', 'r+');
        fwrite($fh, $content);
        rewind($fh);
        $header = null;
        $rows = [];
        $line = 0;
        while (($cells = fgetcsv($fh, 0, $delimiter, '"', '')) !== false) {
            $line++;
            if ($cells === [null] || implode('', array_map('strval', $cells)) === '') {
                continue;
            }
            $cells = array_map(static fn($c) => trim((string)$c), $cells);
            if ($header === null) {
                $header = $cells;
                continue;
            }
            $rows[] = ['line' => $line, 'cells' => $cells];
            if (count($rows) > self::MAX_ROWS) {
                fclose($fh);
                throw new \InvalidArgumentException('A planilha tem mais de ' . self::MAX_ROWS . ' linhas. Divida em partes menores.');
            }
        }
        fclose($fh);
        if ($header === null) {
            throw new \InvalidArgumentException('Não encontramos o cabeçalho da planilha.');
        }
        return ['header' => $header, 'rows' => $rows];
    }

    /** @return array<string,int> campo => índice da coluna, pelo nome do cabeçalho */
    public static function mapHeader(array $header): array
    {
        $map = [];
        foreach ($header as $i => $h) {
            $k = self::key((string)$h);
            foreach (self::ALIASES as $field => $aliases) {
                if (!isset($map[$field]) && in_array($k, array_map([self::class, 'key'], $aliases), true)) {
                    $map[$field] = $i;
                    break;
                }
            }
        }
        return $map;
    }

    /**
     * Valida uma linha com as regras do cadastro manual (DashboardController::savePatient).
     * @return array{data: array<string,?string>, error: ?string}
     */
    public static function validateRow(array $cells, array $map): array
    {
        $get = static fn(string $f): string => isset($map[$f]) ? trim((string)($cells[$map[$f]] ?? '')) : '';
        $data = [];
        foreach (self::FIELDS as $f) {
            $data[$f] = $get($f);
        }
        if ($data['name'] === '') {
            return ['data' => $data, 'error' => 'Sem nome.'];
        }
        if (mb_strlen($data['name']) > 150) {
            return ['data' => $data, 'error' => 'Nome com mais de 150 caracteres.'];
        }
        if ($data['email'] !== '' && !filter_var($data['email'], FILTER_VALIDATE_EMAIL)) {
            return ['data' => $data, 'error' => 'E-mail inválido.'];
        }
        if ($data['phone'] !== '' && (mb_strlen($data['phone']) > 30 || !preg_match('/^[0-9 ()+.\-\/]+$/', $data['phone']))) {
            return ['data' => $data, 'error' => 'Celular inválido.'];
        }
        if ($data['birth_date'] !== '') {
            $iso = null;
            foreach (['!d/m/Y', '!Y-m-d', '!d-m-Y', '!d.m.Y'] as $fmt) {
                $d = \DateTimeImmutable::createFromFormat($fmt, $data['birth_date']);
                if ($d && $d->format(substr($fmt, 1)) === $data['birth_date']) {
                    $iso = $d;
                    break;
                }
            }
            if ($iso === null || $iso > new \DateTimeImmutable('today') || (int)$iso->format('Y') < 1900) {
                return ['data' => $data, 'error' => 'Data de nascimento inválida (use dd/mm/aaaa).'];
            }
            $data['birth_date'] = $iso->format('Y-m-d');
        }
        if ($data['gender'] !== '') {
            $g = self::key($data['gender']);
            $data['gender'] = match ($g) {
                'f', 'feminino', 'female', 'mulher' => 'Feminino',
                'm', 'masculino', 'male', 'homem' => 'Masculino',
                'o', 'outro', 'other', 'naobinario' => 'Outro',
                default => '',
            };
            if ($data['gender'] === '') {
                return ['data' => $data, 'error' => 'Gênero não reconhecido (use Feminino, Masculino ou Outro).'];
            }
        }
        if (mb_strlen($data['notes']) > 2000) {
            return ['data' => $data, 'error' => 'Observações com mais de 2.000 caracteres.'];
        }
        return ['data' => array_map(static fn($v) => $v === '' ? null : $v, $data) + ['name' => $data['name']], 'error' => null];
    }

    private function clinicId(): int
    {
        return (int)TenantContext::getClinicId();
    }

    public static function normalizePhoneDigits(string $phone): string
    {
        $digits = preg_replace('/\D/', '', $phone) ?? '';
        if ((strlen($digits) === 12 || strlen($digits) === 13) && str_starts_with($digits, '55')) {
            $digits = substr($digits, 2);
        }
        return $digits;
    }

    /** Expurga prévias não confirmadas com mais de 24 horas de todas as clínicas (cron). */
    public static function purgeAllStalePreviews(PDO $db): int
    {
        $limit = date('Y-m-d H:i:s', time() - self::PREVIEW_TTL_HOURS * 3600);
        $st = $db->prepare("SELECT id FROM patient_import_batches WHERE status = 'preview' AND created_at < ?");
        $st->execute([$limit]);
        $ids = array_map('intval', $st->fetchAll(PDO::FETCH_COLUMN));
        if ($ids === []) {
            return 0;
        }
        foreach ($ids as $id) {
            $db->prepare('DELETE FROM patient_import_rows WHERE batch_id = ?')->execute([$id]);
            $db->prepare('DELETE FROM patient_import_batches WHERE id = ?')->execute([$id]);
        }
        return count($ids);
    }

    /** Apaga prévias não confirmadas com mais de 24 horas (e as linhas com dados brutos). */
    public function purgeStalePreviews(): int
    {
        $limit = date('Y-m-d H:i:s', time() - self::PREVIEW_TTL_HOURS * 3600);
        $st = $this->db->prepare("SELECT id FROM patient_import_batches WHERE clinic_id = ? AND status = 'preview' AND created_at < ?");
        $st->execute([$this->clinicId(), $limit]);
        $ids = array_map('intval', $st->fetchAll(PDO::FETCH_COLUMN));
        foreach ($ids as $id) {
            $this->deleteBatch($id);
        }
        return count($ids);
    }

    private function deleteBatch(int $id): void
    {
        $this->db->prepare('DELETE FROM patient_import_rows WHERE batch_id = ? AND clinic_id = ?')->execute([$id, $this->clinicId()]);
        $this->db->prepare('DELETE FROM patient_import_batches WHERE id = ? AND clinic_id = ?')->execute([$id, $this->clinicId()]);
    }

    /**
     * Cria a prévia. Se o mesmo arquivo já tem lote na clínica, devolve esse lote (sem duplicar).
     * @return array{batch_id:int, reused:bool, status:string}
     */
    public function preview(string $content, ?string $fileName, int $userId): array
    {
        $sha = hash('sha256', $content);
        $existing = $this->db->prepare('SELECT id, status FROM patient_import_batches WHERE clinic_id = ? AND file_sha256 = ?');
        $existing->execute([$this->clinicId(), $sha]);
        $old = $existing->fetch(PDO::FETCH_ASSOC);
        if ($old) {
            if (in_array($old['status'], ['preview', 'confirmed', 'rolled_back'], true)) {
                return ['batch_id' => (int)$old['id'], 'reused' => true, 'status' => (string)$old['status']];
            }
            $this->deleteBatch((int)$old['id']); // descartado: pode gerar a prévia de novo
        }

        $parsed = self::parse($content);
        $map = self::mapHeader($parsed['header']);
        if (!isset($map['name'])) {
            throw new \InvalidArgumentException('Não encontramos a coluna de nome. Use o modelo ou um cabeçalho como "Nome".');
        }
        if ($parsed['rows'] === []) {
            throw new \InvalidArgumentException('A planilha só tem o cabeçalho. Inclua pelo menos um paciente.');
        }
        $mapping = ['columns' => [], 'ignored' => []];
        foreach ($parsed['header'] as $i => $h) {
            $field = array_search($i, $map, true);
            if ($field === false) {
                $mapping['ignored'][] = mb_substr((string)$h, 0, 60);
            } else {
                $mapping['columns'][] = ['header' => mb_substr((string)$h, 0, 60), 'field' => $field];
            }
        }

        $own = !$this->db->inTransaction();
        if ($own) {
            $this->db->beginTransaction();
        }
        try {
            $this->db->prepare('INSERT INTO patient_import_batches (clinic_id, file_sha256, file_name, status, mapping_json, total_rows, created_by_user_id, created_at)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?)')->execute([
                $this->clinicId(), $sha, $fileName !== null ? mb_substr($fileName, 0, 255) : null, 'preview',
                json_encode($mapping, JSON_UNESCAPED_UNICODE), count($parsed['rows']), $userId > 0 ? $userId : null, date('Y-m-d H:i:s'),
            ]);
            $batchId = (int)$this->db->lastInsertId();
            $ins = $this->db->prepare('INSERT INTO patient_import_rows (batch_id, clinic_id, line_no, raw_json, outcome, message) VALUES (?, ?, ?, ?, ?, ?)');
            $seen = ['email' => [], 'phone' => []];
            $valid = 0;
            $errors = 0;
            $dups = 0;
            foreach ($parsed['rows'] as $row) {
                $v = self::validateRow($row['cells'], $map);
                $outcome = 'valid';
                $msg = null;
                if ($v['error'] !== null) {
                    $outcome = 'error';
                    $msg = $v['error'];
                } else {
                    $email = mb_strtolower((string)($v['data']['email'] ?? ''));
                    $phone = self::normalizePhoneDigits((string)($v['data']['phone'] ?? ''));
                    if (($email !== '' && isset($seen['email'][$email])) || ($phone !== '' && isset($seen['phone'][$phone]))) {
                        $outcome = 'duplicate';
                        $msg = 'Mesmo e-mail ou celular da linha ' . ($seen['email'][$email] ?? $seen['phone'][$phone]) . ' do arquivo.';
                    } elseif (($dupId = $this->existingPatient($email, $phone)) !== null) {
                        $outcome = 'duplicate';
                        $msg = 'Já existe paciente com este e-mail ou celular na clínica (nº ' . $dupId . ').';
                    }
                    if ($email !== '') {
                        $seen['email'][$email] ??= $row['line'];
                    }
                    if ($phone !== '') {
                        $seen['phone'][$phone] ??= $row['line'];
                    }
                }
                $outcome === 'valid' ? $valid++ : ($outcome === 'error' ? $errors++ : $dups++);
                $ins->execute([$batchId, $this->clinicId(), $row['line'], json_encode($v['data'], JSON_UNESCAPED_UNICODE), $outcome, $msg]);
            }
            $this->db->prepare('UPDATE patient_import_batches SET valid_rows = ?, error_rows = ?, skipped_rows = ? WHERE id = ? AND clinic_id = ?')
                ->execute([$valid, $errors, $dups, $batchId, $this->clinicId()]);
            if ($own) {
                $this->db->commit();
            }
        } catch (\Throwable $e) {
            if ($own && $this->db->inTransaction()) {
                $this->db->rollBack();
            }
            throw $e;
        }
        return ['batch_id' => $batchId, 'reused' => false, 'status' => 'preview'];
    }

    private function existingPatient(string $email, string $phone): ?int
    {
        if ($email === '' && $phone === '') {
            return null;
        }
        $phoneDigits = self::normalizePhoneDigits($phone);
        $phoneWithDdi = $phoneDigits !== '' ? '55' . $phoneDigits : '';

        $phoneSql = "COALESCE(phone, '')";
        foreach ([' ', '(', ')', '-', '+', '.', '/', "\t"] as $char) {
            $phoneSql = "REPLACE($phoneSql, " . $this->db->quote($char) . ", '')";
        }
        $st = $this->db->prepare("SELECT id FROM patients WHERE clinic_id = ? AND status <> 'anonymized'
            AND ((? <> '' AND LOWER(TRIM(email)) = ?) OR (? <> '' AND ($phoneSql = ? OR $phoneSql = ?))) LIMIT 1");
        $st->execute([$this->clinicId(), $email, $email, $phoneDigits, $phoneDigits, $phoneWithDdi]);
        $id = $st->fetchColumn();
        return $id === false ? null : (int)$id;
    }

    public function find(int $batchId): ?array
    {
        $st = $this->db->prepare('SELECT * FROM patient_import_batches WHERE id = ? AND clinic_id = ?');
        $st->execute([$batchId, $this->clinicId()]);
        $b = $st->fetch(PDO::FETCH_ASSOC);
        if (!$b) {
            return null;
        }
        $b['mapping'] = json_decode((string)($b['mapping_json'] ?? ''), true) ?: ['columns' => [], 'ignored' => []];
        $rows = $this->db->prepare('SELECT r.line_no, r.raw_json, r.outcome, r.message, r.patient_id, p.name AS patient_name
            FROM patient_import_rows r LEFT JOIN patients p ON p.id = r.patient_id AND p.clinic_id = r.clinic_id
            WHERE r.batch_id = ? AND r.clinic_id = ? ORDER BY r.line_no');
        $rows->execute([$batchId, $this->clinicId()]);
        $b['rows'] = array_map(static function (array $r): array {
            $r['data'] = json_decode((string)($r['raw_json'] ?? ''), true) ?: [];
            unset($r['raw_json']);
            return $r;
        }, $rows->fetchAll(PDO::FETCH_ASSOC));
        return $b;
    }

    /** @return list<array<string,mixed>> últimos lotes da clínica */
    public function recent(int $limit = 10): array
    {
        $st = $this->db->prepare('SELECT id, file_name, status, total_rows, valid_rows, created_rows, skipped_rows, error_rows, rolled_back_rows, created_at, confirmed_at, rolled_back_at
            FROM patient_import_batches WHERE clinic_id = ? ORDER BY id DESC LIMIT ' . max(1, min(50, $limit)));
        $st->execute([$this->clinicId()]);
        return $st->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Grava as linhas válidas da prévia. Tudo numa transação: se uma falhar sem ser duplicidade, nada fica gravado.
     * @return array{created:int, skipped:int}
     */
    public function confirm(int $batchId): array
    {
        $repo = new PatientRepository($this->db);
        $own = !$this->db->inTransaction();
        if ($own) {
            $this->db->beginTransaction();
        }
        try {
            $lock = $this->db->prepare("UPDATE patient_import_batches SET status = 'confirming' WHERE id = ? AND clinic_id = ? AND status = 'preview'");
            $lock->execute([$batchId, $this->clinicId()]);
            if ($lock->rowCount() !== 1) {
                throw new \DomainException('Esta prévia já foi confirmada, descartada ou não existe mais.');
            }
            $st = $this->db->prepare("SELECT id, line_no, raw_json FROM patient_import_rows WHERE batch_id = ? AND clinic_id = ? AND outcome = 'valid' ORDER BY line_no");
            $st->execute([$batchId, $this->clinicId()]);
            $upd = $this->db->prepare('UPDATE patient_import_rows SET outcome = ?, message = ?, patient_id = ? WHERE id = ? AND clinic_id = ?');
            $created = 0;
            $skipped = 0;
            foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
                $d = json_decode((string)$r['raw_json'], true) ?: [];
                try {
                    $p = $repo->create([
                        'name' => (string)($d['name'] ?? ''),
                        'email' => $d['email'] ?? null,
                        'phone' => $d['phone'] ?? null,
                        'birth_date' => $d['birth_date'] ?? null,
                        'gender' => $d['gender'] ?? null,
                        'notes' => $d['notes'] ?? null,
                        'consent_choice' => 'not_collected',
                    ]);
                    $upd->execute(['created', null, (int)$p['id'], (int)$r['id'], $this->clinicId()]);
                    $created++;
                } catch (DuplicatePatientException $e) {
                    $upd->execute(['duplicate', 'Cadastrado por outra pessoa enquanto a prévia estava aberta (nº ' . $e->patientId . ').', null, (int)$r['id'], $this->clinicId()]);
                    $skipped++;
                }
            }
            // Dados brutos só servem para a prévia: apagados ao confirmar.
            $this->db->prepare('UPDATE patient_import_rows SET raw_json = NULL WHERE batch_id = ? AND clinic_id = ?')->execute([$batchId, $this->clinicId()]);
            $this->db->prepare("UPDATE patient_import_batches SET status = 'confirmed', created_rows = ?, skipped_rows = skipped_rows + ?, confirmed_at = ? WHERE id = ? AND clinic_id = ?")
                ->execute([$created, $skipped, date('Y-m-d H:i:s'), $batchId, $this->clinicId()]);
            (new AuditLogger($this->db))->log('PATIENT_IMPORT_CONFIRMED', 'patient_import_batches', (string)$batchId, ['created' => $created, 'skipped_at_confirm' => $skipped]);
            if ($own) {
                $this->db->commit();
            }
            return ['created' => $created, 'skipped' => $skipped];
        } catch (\Throwable $e) {
            if ($own && $this->db->inTransaction()) {
                $this->db->rollBack();
            }
            throw $e;
        }
    }

    public function discard(int $batchId): void
    {
        $st = $this->db->prepare("SELECT status FROM patient_import_batches WHERE id = ? AND clinic_id = ?");
        $st->execute([$batchId, $this->clinicId()]);
        $status = $st->fetchColumn();
        if ($status === false) {
            throw new \DomainException('Prévia não encontrada.');
        }
        if ($status !== 'preview') {
            throw new \DomainException('Só uma prévia ainda não confirmada pode ser descartada.');
        }
        $this->db->prepare('DELETE FROM patient_import_rows WHERE batch_id = ? AND clinic_id = ?')->execute([$batchId, $this->clinicId()]);
        $this->db->prepare("UPDATE patient_import_batches SET status = 'discarded' WHERE id = ? AND clinic_id = ?")->execute([$batchId, $this->clinicId()]);
    }

    /** Tabelas com patient_id (menos auditoria e o próprio lote): qualquer linha nelas impede desfazer aquele paciente. */
    private function linkedTables(): array
    {
        $skip = ['audit_logs', 'patient_import_rows'];
        if ($this->db->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql') {
            $tables = $this->db->query("SELECT TABLE_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND COLUMN_NAME = 'patient_id'")->fetchAll(PDO::FETCH_COLUMN);
        } else {
            $tables = [];
            foreach ($this->db->query("SELECT name FROM sqlite_master WHERE type = 'table' AND name NOT LIKE 'sqlite_%'")->fetchAll(PDO::FETCH_COLUMN) as $t) {
                foreach ($this->db->query('PRAGMA table_info(' . $this->db->quote((string)$t) . ')')->fetchAll(PDO::FETCH_ASSOC) as $c) {
                    if (($c['name'] ?? '') === 'patient_id') {
                        $tables[] = $t;
                        break;
                    }
                }
            }
        }
        return array_values(array_diff(array_unique(array_map('strval', $tables)), $skip));
    }

    /**
     * Remove os pacientes criados pelo lote que não têm nada ligado (consulta, plano, prontuário, consentimento,
     * acesso ao app...). Os demais ficam e aparecem no relatório.
     * @return array{removed:int, kept:int}
     */
    public function rollback(int $batchId): array
    {
        $own = !$this->db->inTransaction();
        if ($own) {
            $this->db->beginTransaction();
        }
        try {
            $lock = $this->db->prepare("UPDATE patient_import_batches SET status = 'rolling_back' WHERE id = ? AND clinic_id = ? AND status = 'confirmed'");
            $lock->execute([$batchId, $this->clinicId()]);
            if ($lock->rowCount() !== 1) {
                throw new \DomainException('Só uma importação confirmada pode ser desfeita, e uma vez só.');
            }
            $tables = $this->linkedTables();
            $st = $this->db->prepare("SELECT r.id, r.patient_id, p.password_hash, p.access_pin FROM patient_import_rows r
                JOIN patients p ON p.id = r.patient_id AND p.clinic_id = r.clinic_id
                WHERE r.batch_id = ? AND r.clinic_id = ? AND r.outcome = 'created'");
            $st->execute([$batchId, $this->clinicId()]);
            $removed = 0;
            $kept = 0;
            $mark = $this->db->prepare('UPDATE patient_import_rows SET outcome = ?, message = ?, patient_id = ? WHERE id = ? AND clinic_id = ?');
            $del = $this->db->prepare('DELETE FROM patients WHERE id = ? AND clinic_id = ?');
            foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
                $pid = (int)$r['patient_id'];
                $linked = !empty($r['password_hash']) || !empty($r['access_pin']);
                foreach ($tables as $t) {
                    if ($linked) {
                        break;
                    }
                    $q = $this->db->prepare('SELECT 1 FROM ' . $t . ' WHERE patient_id = ? LIMIT 1');
                    $q->execute([$pid]);
                    $linked = (bool)$q->fetchColumn();
                }
                if ($linked) {
                    $mark->execute(['kept', 'Mantido: o paciente já tem registros ou acesso ao app.', $pid, (int)$r['id'], $this->clinicId()]);
                    $kept++;
                    continue;
                }
                $del->execute([$pid, $this->clinicId()]);
                $mark->execute(['rolled_back', null, null, (int)$r['id'], $this->clinicId()]);
                $removed++;
            }
            $this->db->prepare("UPDATE patient_import_batches SET status = 'rolled_back', rolled_back_rows = ?, rolled_back_at = ? WHERE id = ? AND clinic_id = ?")
                ->execute([$removed, date('Y-m-d H:i:s'), $batchId, $this->clinicId()]);
            (new AuditLogger($this->db))->log('PATIENT_IMPORT_ROLLED_BACK', 'patient_import_batches', (string)$batchId, ['removed' => $removed, 'kept' => $kept]);
            if ($own) {
                $this->db->commit();
            }
            return ['removed' => $removed, 'kept' => $kept];
        } catch (\Throwable $e) {
            if ($own && $this->db->inTransaction()) {
                $this->db->rollBack();
            }
            throw $e;
        }
    }

    /** Modelo de planilha: só o cabeçalho, separado por ponto e vírgula (abre direto no Excel em pt-BR). */
    public static function templateCsv(): string
    {
        return "\xEF\xBB\xBF" . "Nome;E-mail;Celular;Data de nascimento;Gênero;Observações\r\n";
    }
}
