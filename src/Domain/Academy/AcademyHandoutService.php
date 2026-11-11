<?php

declare(strict_types=1);

namespace ClyoNutri\Domain\Academy;

use ClyoNutri\Domain\LaboratoryExam\ExamFileInspector;
use DomainException;
use InvalidArgumentException;
use PDO;

/**
 * v54.23 · Lâminas para pacientes na Clyo Academy (folhas de orientação de uma página, em PDF).
 *
 * Quem cadastra, troca arquivo, publica e arquiva é a equipe Clyo Nutri no console (/admin/academy). A clínica só pesquisa,
 * abre, baixa e favorita lâmina publicada. Rascunho e arquivada não existem para a clínica (404).
 *
 * Arquivos: fora da pasta pública, em storage/app/academy/laminas/ (ou ACADEMY_HANDOUTS_DIR), com nome aleatório e SHA-256
 * conferido a cada entrega. PDF até 10 MB, prévia PNG ou JPG até 3 MB, os dois inspecionados pelo ExamFileInspector
 * (tipo real pelos bytes, nome coerente, PDF sem conteúdo ativo, imagem que abre de verdade).
 * Importação em lote: bin/importar_laminas_academy.php (sempre como rascunho, idempotente pelo slug).
 */
final class AcademyHandoutService
{
    public const STATUS = ['draft' => 'Rascunho', 'published' => 'Publicada', 'archived' => 'Arquivada'];
    public const MAX_PDF_BYTES = 10 * 1024 * 1024;
    public const MAX_PREVIEW_BYTES = 3 * 1024 * 1024;

    /** @var array<string, bool> */
    private static array $ready = [];

    private string $dir;

    public function __construct(private PDO $db, ?string $dir = null)
    {
        $this->dir = rtrim($dir ?? (getenv('ACADEMY_HANDOUTS_DIR') ?: dirname(__DIR__, 3) . '/storage/app/academy/laminas'), '/');
    }

    /** A tabela da migração 85 existe? Sem ela as lâminas simplesmente não aparecem (o resto da Academy segue). */
    public static function ready(PDO $db): bool
    {
        $k = spl_object_hash($db);
        if (!isset(self::$ready[$k])) {
            try {
                $db->query('SELECT id FROM academy_handouts WHERE 1 = 0');
                $db->query('SELECT id FROM academy_handout_favorites WHERE 1 = 0');
                self::$ready[$k] = true;
            } catch (\Throwable $e) {
                self::$ready[$k] = false;
            }
        }
        return self::$ready[$k];
    }

    public function storageDir(): string
    {
        return $this->dir;
    }

    // ------------------------------------------------------------------ leitura

    /**
     * Busca por título, resumo, etiquetas e fonte. Clínica: só publicadas (padrão). Console: include_unpublished.
     * @return array{total:int, items:list<array<string,mixed>>}
     */
    public function search(array $filters = []): array
    {
        $where = ['1=1'];
        $params = [];
        if (empty($filters['include_unpublished'])) {
            $where[] = "status = 'published'";
        }
        $q = trim((string)($filters['q'] ?? ''));
        if ($q !== '') {
            // cada palavra precisa aparecer em algum campo ("sal hipertensão" acha a lâmina de sal com a etiqueta hipertensão)
            foreach (array_slice(preg_split('/\s+/u', mb_substr($q, 0, 200, 'UTF-8')) ?: [], 0, 6) as $word) {
                $like = '%' . self::escapeLike($word) . '%';
                $where[] = "(title LIKE ? ESCAPE '!' OR summary LIKE ? ESCAPE '!' OR tags LIKE ? ESCAPE '!' OR source_citation LIKE ? ESCAPE '!' OR slug LIKE ? ESCAPE '!')";
                array_push($params, $like, $like, $like, $like, $like);
            }
        }
        $tag = self::normalizeTag((string)($filters['tag'] ?? ''));
        if ($tag !== '' && $tag !== 'all') {
            $where[] = $this->concat("','", "COALESCE(tags, '')", "','") . " LIKE ? ESCAPE '!'";
            $params[] = '%,' . self::escapeLike($tag) . ',%';
        }
        $sqlWhere = implode(' AND ', $where);
        $c = $this->db->prepare("SELECT COUNT(*) FROM academy_handouts WHERE $sqlWhere");
        $c->execute($params);
        $limit = max(1, min(100, (int)($filters['limit'] ?? 20)));
        $offset = max(0, (int)($filters['offset'] ?? 0));
        $s = $this->db->prepare("SELECT * FROM academy_handouts WHERE $sqlWhere ORDER BY title ASC, id ASC LIMIT $limit OFFSET $offset");
        $s->execute($params);
        return ['total' => (int)$c->fetchColumn(), 'items' => $s->fetchAll(PDO::FETCH_ASSOC) ?: []];
    }

    /** @return list<string> etiquetas usadas nas lâminas publicadas, para o filtro */
    public function publishedTags(): array
    {
        $tags = [];
        foreach ($this->db->query("SELECT tags FROM academy_handouts WHERE status = 'published'")->fetchAll(PDO::FETCH_COLUMN) ?: [] as $t) {
            foreach (self::splitTags((string)$t) as $one) {
                $tags[$one] = true;
            }
        }
        $tags = array_keys($tags);
        sort($tags);
        return $tags;
    }

    public function getById(int $id): ?array
    {
        $s = $this->db->prepare('SELECT * FROM academy_handouts WHERE id = ?');
        $s->execute([$id]);
        return $s->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    public function getBySlug(string $slug): ?array
    {
        $s = $this->db->prepare('SELECT * FROM academy_handouts WHERE slug = ?');
        $s->execute([$slug]);
        return $s->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    /** Leitura do lado da clínica: só lâmina publicada. */
    public function getPublished(int $id): ?array
    {
        $s = $this->db->prepare("SELECT * FROM academy_handouts WHERE id = ? AND status = 'published'");
        $s->execute([$id]);
        return $s->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    /** @return list<array<string,mixed>> */
    public function listForConsole(int $limit = 200): array
    {
        $limit = max(1, min(500, $limit));
        return $this->db->query("SELECT id, slug, title, status, size_bytes, preview_storage_name, updated_at, published_at FROM academy_handouts ORDER BY id DESC LIMIT $limit")
            ->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    // ------------------------------------------------------------------ equipe Clyo Nutri (console e importação)

    /**
     * Cadastra como rascunho. $pdf e $preview: ['tmp_name' => caminho, 'name' => nome original, 'error' => UPLOAD_ERR_*].
     * @param array{title?:string,slug?:string,summary?:string,tags?:string|array,source_citation?:string} $meta
     */
    public function create(array $meta, array $pdf, ?array $preview, int $userId): int
    {
        $v = $this->validate($meta, null);
        $p = $this->inspect($pdf, 'pdf');
        $img = $preview !== null && ($preview['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE ? $this->inspect($preview, 'preview') : null;
        $stored = $this->store($p);
        $prev = $img ? $this->store($img) : null;
        $now = date('Y-m-d H:i:s');
        try {
            $this->db->prepare("INSERT INTO academy_handouts (slug, title, summary, tags, source_citation, status, file_name, mime_type, size_bytes, sha256, storage_name,
                preview_storage_name, preview_mime, preview_size, preview_sha256, created_by_user_id, updated_by_user_id, created_at, updated_at)
                VALUES (?, ?, ?, ?, ?, 'draft', ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)")
                ->execute([$v['slug'], $v['title'], $v['summary'], $v['tags'], $v['source_citation'], $p['original_name'], $p['mime'], $p['size'], $p['sha256'], $stored,
                    $prev, $img['mime'] ?? null, $img['size'] ?? null, $img['sha256'] ?? null, $userId, $userId, $now, $now]);
        } catch (\PDOException $e) {
            @unlink($this->dir . '/' . $stored);
            if ($prev) {
                @unlink($this->dir . '/' . $prev);
            }
            throw new DomainException('Já existe uma lâmina com este endereço (slug).');
        }
        return (int)$this->db->lastInsertId();
    }

    /** Edita metadados e, se vierem, troca o PDF e/ou a prévia (os arquivos antigos são apagados depois de gravar). */
    public function update(int $id, array $meta, ?array $pdf, ?array $preview, int $userId): void
    {
        $cur = $this->getById($id);
        if (!$cur) {
            throw new DomainException('Lâmina não encontrada.');
        }
        $v = $this->validate($meta + ['slug' => $cur['slug']], $id);
        $sets = ['slug = ?', 'title = ?', 'summary = ?', 'tags = ?', 'source_citation = ?', 'updated_by_user_id = ?', 'updated_at = ?'];
        $vals = [$v['slug'], $v['title'], $v['summary'], $v['tags'], $v['source_citation'], $userId, date('Y-m-d H:i:s')];
        $remove = [];
        if ($pdf !== null && ($pdf['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE) {
            $p = $this->inspect($pdf, 'pdf');
            array_push($sets, 'file_name = ?', 'mime_type = ?', 'size_bytes = ?', 'sha256 = ?', 'storage_name = ?');
            array_push($vals, $p['original_name'], $p['mime'], $p['size'], $p['sha256'], $this->store($p));
            $remove[] = (string)$cur['storage_name'];
        }
        if ($preview !== null && ($preview['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE) {
            $img = $this->inspect($preview, 'preview');
            array_push($sets, 'preview_storage_name = ?', 'preview_mime = ?', 'preview_size = ?', 'preview_sha256 = ?');
            array_push($vals, $this->store($img), $img['mime'], $img['size'], $img['sha256']);
            if (!empty($cur['preview_storage_name'])) {
                $remove[] = (string)$cur['preview_storage_name'];
            }
        }
        $vals[] = $id;
        $this->db->prepare('UPDATE academy_handouts SET ' . implode(', ', $sets) . ' WHERE id = ?')->execute($vals);
        foreach ($remove as $r) {
            @unlink($this->dir . '/' . basename($r));
        }
    }

    /** Publica ou arquiva. Devolve a situação anterior. */
    public function setStatus(int $id, string $status, int $userId, string $reason = ''): string
    {
        if (!isset(self::STATUS[$status]) || $status === 'draft') {
            throw new InvalidArgumentException('Situação inválida.');
        }
        $cur = $this->getById($id);
        if (!$cur) {
            throw new DomainException('Lâmina não encontrada.');
        }
        if ($cur['status'] === $status) {
            throw new DomainException($status === 'published' ? 'Esta lâmina já está publicada.' : 'Esta lâmina já está arquivada.');
        }
        $now = date('Y-m-d H:i:s');
        if ($status === 'published') {
            $this->fileOf($cur, 'pdf'); // não publica lâmina cujo arquivo sumiu ou foi alterado no disco
            $this->db->prepare("UPDATE academy_handouts SET status = 'published', published_at = ?, published_by_user_id = ?, archived_reason = NULL, updated_at = ? WHERE id = ?")
                ->execute([$now, $userId, $now, $id]);
        } else {
            $reason = trim($reason);
            if (mb_strlen($reason, 'UTF-8') < 5) {
                throw new InvalidArgumentException('Escreva o motivo para arquivar (pelo menos 5 letras).');
            }
            $this->db->prepare("UPDATE academy_handouts SET status = 'archived', archived_reason = ?, updated_by_user_id = ?, updated_at = ? WHERE id = ?")
                ->execute([mb_substr($reason, 0, 255, 'UTF-8'), $userId, $now, $id]);
        }
        return (string)$cur['status'];
    }

    // ------------------------------------------------------------------ arquivos

    /**
     * Caminho e metadados para entregar o PDF ou a prévia. Confere o SHA-256: arquivo alterado no disco não sai.
     * @return array{path:string,file_name:string,mime_type:string,size_bytes:int}
     */
    public function fileOf(array $h, string $kind = 'pdf'): array
    {
        if ($kind === 'preview') {
            if (empty($h['preview_storage_name'])) {
                throw new DomainException('Esta lâmina não tem prévia.');
            }
            $path = $this->dir . '/' . basename((string)$h['preview_storage_name']);
            $sha = (string)$h['preview_sha256'];
            $name = $h['slug'] . ($h['preview_mime'] === 'image/png' ? '.png' : '.jpg');
            $mime = (string)$h['preview_mime'];
            $size = (int)$h['preview_size'];
        } else {
            $path = $this->dir . '/' . basename((string)$h['storage_name']);
            $sha = (string)$h['sha256'];
            $name = $h['slug'] . '.pdf';
            $mime = (string)$h['mime_type'];
            $size = (int)$h['size_bytes'];
        }
        if (!is_file($path) || !hash_equals($sha, (string)hash_file('sha256', $path))) {
            throw new \RuntimeException('Arquivo da lâmina indisponível ou alterado no armazenamento.');
        }
        return ['path' => $path, 'file_name' => $name, 'mime_type' => $mime, 'size_bytes' => $size];
    }

    // ------------------------------------------------------------------ favoritos (coleção pessoal, mesma pasta dos artigos)

    public function toggleFavorite(int $clinicId, int $userId, int $handoutId, string $collection = 'Geral'): bool
    {
        $collection = mb_substr(trim($collection), 0, 100, 'UTF-8') ?: 'Geral';
        $s = $this->db->prepare('SELECT id FROM academy_handout_favorites WHERE clinic_id = ? AND user_id = ? AND handout_id = ?');
        $s->execute([$clinicId, $userId, $handoutId]);
        if ($fid = $s->fetchColumn()) {
            $this->db->prepare('DELETE FROM academy_handout_favorites WHERE id = ? AND clinic_id = ? AND user_id = ?')->execute([$fid, $clinicId, $userId]);
            return false;
        }
        if (!$this->getPublished($handoutId)) {
            throw new DomainException('Lâmina não encontrada.');
        }
        $this->db->prepare('INSERT INTO academy_handout_favorites (clinic_id, user_id, handout_id, collection_name, created_at) VALUES (?, ?, ?, ?, ?)')
            ->execute([$clinicId, $userId, $handoutId, $collection, date('Y-m-d H:i:s')]);
        return true;
    }

    public function isFavorite(int $clinicId, int $userId, int $handoutId): bool
    {
        $s = $this->db->prepare('SELECT 1 FROM academy_handout_favorites WHERE clinic_id = ? AND user_id = ? AND handout_id = ?');
        $s->execute([$clinicId, $userId, $handoutId]);
        return (bool)$s->fetchColumn();
    }

    /** @return list<array<string,mixed>> lâminas favoritas e publicadas da pessoa */
    public function listFavorites(int $clinicId, int $userId, ?string $collection = null): array
    {
        $sql = "SELECT f.id AS favorite_id, f.collection_name, f.created_at AS saved_at, h.* FROM academy_handout_favorites f
            JOIN academy_handouts h ON h.id = f.handout_id WHERE f.clinic_id = ? AND f.user_id = ? AND h.status = 'published'";
        $params = [$clinicId, $userId];
        if ($collection !== null && $collection !== '' && $collection !== 'all') {
            $sql .= ' AND f.collection_name = ?';
            $params[] = $collection;
        }
        $s = $this->db->prepare($sql . ' ORDER BY f.id DESC');
        $s->execute($params);
        return $s->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    /** @return list<string> */
    public function collections(int $clinicId, int $userId): array
    {
        $s = $this->db->prepare('SELECT DISTINCT collection_name FROM academy_handout_favorites WHERE clinic_id = ? AND user_id = ?');
        $s->execute([$clinicId, $userId]);
        return $s->fetchAll(PDO::FETCH_COLUMN) ?: [];
    }

    // ------------------------------------------------------------------ apoio

    public static function slugify(string $text): string
    {
        $t = mb_strtolower(trim($text), 'UTF-8');
        $t = strtr($t, ['á' => 'a', 'à' => 'a', 'â' => 'a', 'ã' => 'a', 'ä' => 'a', 'é' => 'e', 'ê' => 'e', 'è' => 'e', 'ë' => 'e', 'í' => 'i', 'ì' => 'i', 'î' => 'i', 'ï' => 'i',
            'ó' => 'o', 'ò' => 'o', 'ô' => 'o', 'õ' => 'o', 'ö' => 'o', 'ú' => 'u', 'ù' => 'u', 'û' => 'u', 'ü' => 'u', 'ç' => 'c', 'ñ' => 'n']);
        $t = (string)preg_replace('/[^a-z0-9]+/', '-', $t);
        return substr(trim($t, '-'), 0, 120);
    }

    /** @return list<string> */
    public static function splitTags(string $tags): array
    {
        $out = [];
        foreach (explode(',', $tags) as $t) {
            $n = self::normalizeTag($t);
            if ($n !== '' && !in_array($n, $out, true)) {
                $out[] = $n;
            }
        }
        return $out;
    }

    public static function humanSize(int $bytes): string
    {
        return $bytes >= 1048576 ? number_format($bytes / 1048576, 1, ',', '.') . ' MB' : max(1, (int)round($bytes / 1024)) . ' KB';
    }

    /** @return array{slug:string,title:string,summary:?string,tags:?string,source_citation:string} */
    private function validate(array $m, ?int $selfId): array
    {
        $title = trim((string)($m['title'] ?? ''));
        if ($title === '' || mb_strlen($title, 'UTF-8') > 200) {
            throw new InvalidArgumentException('Dê um título à lâmina (até 200 caracteres).');
        }
        $slug = trim((string)($m['slug'] ?? ''));
        $slug = $slug === '' ? self::slugify($title) : $slug;
        if (!preg_match('/^[a-z0-9]+(?:-[a-z0-9]+)*$/', $slug) || strlen($slug) > 120) {
            throw new InvalidArgumentException('O endereço (slug) aceita só letras minúsculas sem acento, números e hífen.');
        }
        $s = $this->db->prepare('SELECT id FROM academy_handouts WHERE slug = ?' . ($selfId ? ' AND id <> ?' : ''));
        $s->execute($selfId ? [$slug, $selfId] : [$slug]);
        if ($s->fetchColumn()) {
            throw new DomainException('Já existe uma lâmina com o endereço "' . $slug . '".');
        }
        $summary = trim((string)($m['summary'] ?? ''));
        if (mb_strlen($summary, 'UTF-8') > 1000) {
            throw new InvalidArgumentException('O resumo pode ter até 1.000 caracteres.');
        }
        $tagsIn = $m['tags'] ?? '';
        $tags = self::splitTags(is_array($tagsIn) ? implode(',', array_map('strval', $tagsIn)) : (string)$tagsIn);
        $tagStr = implode(',', $tags);
        if (strlen($tagStr) > 500) {
            throw new InvalidArgumentException('Etiquetas demais (até 500 caracteres no total).');
        }
        $source = trim((string)($m['source_citation'] ?? ''));
        if (mb_strlen($source, 'UTF-8') < 5 || mb_strlen($source, 'UTF-8') > 2000) {
            throw new InvalidArgumentException('Informe a fonte da lâmina (ex.: Guia Alimentar para a População Brasileira, Ministério da Saúde, 2014).');
        }
        return ['slug' => $slug, 'title' => $title, 'summary' => $summary !== '' ? $summary : null, 'tags' => $tagStr !== '' ? $tagStr : null, 'source_citation' => $source];
    }

    /** @return array{mime:string,ext:string,size:int,sha256:string,original_name:string,tmp:string} */
    private function inspect(array $file, string $kind): array
    {
        $isPdf = $kind === 'pdf';
        $err = (int)($file['error'] ?? UPLOAD_ERR_NO_FILE);
        if ($err !== UPLOAD_ERR_OK) {
            $big = in_array($err, [UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE], true);
            throw new InvalidArgumentException($isPdf
                ? ($big ? 'O PDF passa de 10 MB.' : 'Escolha o arquivo PDF da lâmina.')
                : ($big ? 'A prévia passa de 3 MB.' : 'A prévia não chegou inteira. Tente de novo.'));
        }
        $tmp = (string)($file['tmp_name'] ?? '');
        if ($tmp === '' || !is_file($tmp)) {
            throw new InvalidArgumentException('O arquivo não chegou inteiro. Tente de novo.');
        }
        $max = $isPdf ? self::MAX_PDF_BYTES : self::MAX_PREVIEW_BYTES;
        if ((int)filesize($tmp) > $max) {
            throw new InvalidArgumentException($isPdf ? 'O PDF precisa ter até 10 MB.' : 'A prévia precisa ter até 3 MB.');
        }
        try {
            $r = (new ExamFileInspector())->inspect($tmp, (string)($file['name'] ?? ($isPdf ? 'lamina.pdf' : 'previa.png')));
        } catch (InvalidArgumentException $e) {
            $msg = str_replace(['Envie o laudo original do laboratório ou uma foto/imagem dele.', 'Baixe o laudo de novo no site do laboratório.', 'Envie a versão sem senha do laudo.',
                'Envie uma foto ou PDF do laudo.', 'Envie o laudo em PDF, JPG ou PNG.', 'Envie o laudo original em PDF, JPG ou PNG.'],
                ['Gere o PDF de novo, sem formulário nem script.', 'Gere o PDF de novo.', 'Envie a versão sem senha.', 'Envie outra imagem.', 'Envie PDF, PNG ou JPG.', 'Confira a extensão do arquivo.'], $e->getMessage());
            throw new InvalidArgumentException(str_replace('laudo', 'arquivo', $msg));
        }
        if ($isPdf && $r['mime'] !== 'application/pdf') {
            throw new InvalidArgumentException('A lâmina precisa ser um arquivo PDF.');
        }
        if (!$isPdf && !in_array($r['mime'], ['image/png', 'image/jpeg'], true)) {
            throw new InvalidArgumentException('A prévia precisa ser PNG ou JPG.');
        }
        return $r + ['tmp' => $tmp];
    }

    private function store(array $f): string
    {
        if (!is_dir($this->dir) && !mkdir($this->dir, 0750, true) && !is_dir($this->dir)) {
            throw new \RuntimeException('Não foi possível preparar o armazenamento das lâminas.');
        }
        $name = bin2hex(random_bytes(16)) . '.' . $f['ext'];
        $dest = $this->dir . '/' . $name;
        $ok = \ClyoNutri\Core\Http\UploadMover::move((string)$f['tmp'], $dest); // CR-NI2-011
        if (!$ok || !hash_equals($f['sha256'], (string)hash_file('sha256', $dest))) {
            @unlink($dest);
            throw new \RuntimeException('Falha ao gravar o arquivo da lâmina.');
        }
        @chmod($dest, 0640);
        return $name;
    }

    private static function normalizeTag(string $t): string
    {
        return mb_substr(trim(mb_strtolower($t, 'UTF-8')), 0, 60, 'UTF-8');
    }

    private static function escapeLike(string $s): string
    {
        return strtr($s, ['!' => '!!', '%' => '!%', '_' => '!_']);
    }

    private function concat(string ...$parts): string
    {
        return $this->db->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql' ? 'CONCAT(' . implode(', ', $parts) . ')' : implode(' || ', $parts);
    }
}
