<?php

declare(strict_types=1);

namespace ClyoNutri\Domain\Academy;

use DomainException;
use InvalidArgumentException;
use PDO;

/**
 * NI2-663 · Acervo da Clyo Academy. Só metadados com proveniência (DOI/PMID, fonte, URL oficial, data de obtenção).
 *
 * Revisão de 03/11/2026 (code review do commit fae6581):
 *  - sem semente: nenhum artigo nasce do código; o acervo entra pelo console editorial (e, depois, pelos conectores NI2-667);
 *  - duplicata por DOI/PMID é recusada (não sobrescreve em silêncio);
 *  - o texto do resumo só é guardado e exibido com licença declarada que permita (metadados ≠ direito de reproduzir);
 *  - SQL portátil (SQLite e MySQL): upsert por SELECT seguido de UPDATE ou INSERT.
 *
 * v54.22: a biblioteca é só de leitura para as clínicas. O artigo nasce como rascunho no console da equipe da Clyo Nutri e
 * só aparece para as clínicas depois de publicado; a equipe pode editar os metadados, arquivar e publicar de novo.
 * Tudo o que a clínica enxerga passa por publishedOnly (busca, detalhe, vínculos com protocolos e favoritos).
 */
final class AcademyArticleService
{
    public const DESIGNS = [
        'systematic_review' => 'Revisão sistemática', 'meta_analysis' => 'Metanálise', 'rct' => 'Ensaio clínico randomizado',
        'clinical_guideline' => 'Diretriz clínica', 'cohort' => 'Coorte', 'case_control' => 'Caso-controle',
        'cross_sectional' => 'Estudo transversal', 'case_report' => 'Relato de caso', 'narrative_review' => 'Revisão narrativa',
        'preprint' => 'Preprint (não revisado por pares)', 'editorial' => 'Editorial ou opinião',
    ];
    public const ACCESS = ['open_access' => 'Acesso aberto', 'publisher_link' => 'No site do periódico', 'subscription' => 'Assinatura do periódico'];
    public const SOURCES = ['pubmed' => 'PubMed', 'crossref' => 'Crossref', 'publisher' => 'Site do periódico', 'pmc' => 'PubMed Central'];
    public const STATUS = ['draft' => 'Rascunho', 'published' => 'Publicado', 'archived' => 'Arquivado'];
    public const RETRACTION = ['none' => 'Sem sinalização', 'corrected' => 'Correção publicada', 'expression_of_concern' => 'Manifestação de preocupação', 'retracted' => 'Retratado'];

    /** @var array<string, bool> */
    private static array $ready = [];

    public function __construct(private PDO $db)
    {
    }

    /** A coluna de situação (migração 84) existe? Sem ela a Academy não abre para as clínicas. */
    public static function ready(PDO $db): bool
    {
        $k = spl_object_hash($db);
        if (!isset(self::$ready[$k])) {
            try {
                $db->query('SELECT status FROM academy_articles WHERE 1 = 0');
                self::$ready[$k] = true;
            } catch (\Throwable $e) {
                self::$ready[$k] = false;
            }
        }
        return self::$ready[$k];
    }

    /** @return array{total: int, items: list<array<string, mixed>>} */
    public function search(array $filters = []): array
    {
        $where = ['1=1'];
        $params = [];
        if (empty($filters['include_unpublished'])) { // a clínica só enxerga o que a equipe publicou
            $where[] = "status = 'published'";
        }
        $q = trim((string)($filters['q'] ?? ''));
        if ($q !== '') {
            $like = '%' . self::escapeLike(mb_substr($q, 0, 200, 'UTF-8')) . '%';
            $where[] = "(title LIKE ? ESCAPE '!' OR authors LIKE ? ESCAPE '!' OR journal LIKE ? ESCAPE '!' OR doi LIKE ? ESCAPE '!' OR pmid LIKE ? ESCAPE '!')";
            array_push($params, $like, $like, $like, $like, $like);
        }
        $design = (string)($filters['study_design'] ?? '');
        if (isset(self::DESIGNS[$design])) {
            $where[] = 'study_design = ?';
            $params[] = $design;
        }
        $access = (string)($filters['access_type'] ?? '');
        if (isset(self::ACCESS[$access])) {
            $where[] = 'access_type = ?';
            $params[] = $access;
        }
        $tag = self::normalizeTag((string)($filters['condition_tag'] ?? ''));
        if ($tag !== '' && $tag !== 'all') { // etiqueta inteira, não pedaço de palavra
            $where[] = $this->concat("','", 'condition_tags', "','") . " LIKE ? ESCAPE '!'";
            $params[] = '%,' . self::escapeLike($tag) . ',%';
        }
        foreach (['year_min' => '>=', 'year_max' => '<='] as $k => $op) {
            if (!empty($filters[$k]) && is_numeric($filters[$k])) {
                $where[] = "publication_year $op ?";
                $params[] = (int)$filters[$k];
            }
        }
        if (!empty($filters['has_evidence_sheet'])) {
            $where[] = 'has_evidence_sheet = 1';
        }
        $sqlWhere = implode(' AND ', $where);
        $c = $this->db->prepare("SELECT COUNT(*) FROM academy_articles WHERE $sqlWhere");
        $c->execute($params);
        $limit = max(1, min(100, (int)($filters['limit'] ?? 20)));
        $offset = max(0, (int)($filters['offset'] ?? 0));
        $s = $this->db->prepare("SELECT * FROM academy_articles WHERE $sqlWhere ORDER BY publication_year DESC, id DESC LIMIT $limit OFFSET $offset");
        $s->execute($params);
        return ['total' => (int)$c->fetchColumn(), 'items' => $s->fetchAll(PDO::FETCH_ASSOC) ?: []];
    }

    public function getById(int $id): ?array
    {
        $s = $this->db->prepare('SELECT * FROM academy_articles WHERE id = ?');
        $s->execute([$id]);
        return $s->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    /** Leitura do lado da clínica: só artigo publicado. */
    public function getPublishedById(int $id): ?array
    {
        $s = $this->db->prepare("SELECT * FROM academy_articles WHERE id = ? AND status = 'published'");
        $s->execute([$id]);
        return $s->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    /** @return list<array<string, mixed>> Lista do console (todas as situações). */
    public function listForConsole(int $limit = 200): array
    {
        $limit = max(1, min(500, $limit));
        return $this->db->query("SELECT id, title, journal, publication_year, doi, pmid, study_design, retraction_status, has_evidence_sheet, retrieved_at, status
            FROM academy_articles ORDER BY id DESC LIMIT $limit")->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    /**
     * Cadastro pelo console editorial. Exige proveniência mínima: DOI ou PMID, URL oficial https, fonte e data de obtenção.
     */
    public function register(array $d, int $userId): int
    {
        $v = $this->validate($d);
        $this->assertUnique($v, null);
        $now = date('Y-m-d H:i:s');
        $this->db->prepare("INSERT INTO academy_articles (pmid, doi, title, authors, journal, publication_year, study_design, access_type, abstract, url, source,
            condition_tags, has_evidence_sheet, retraction_status, license, retrieved_at, created_by_user_id, created_at, updated_at, status)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 0, ?, ?, ?, ?, ?, ?, 'draft')")
            ->execute([$v['pmid'], $v['doi'], $v['title'], $v['authors'], $v['journal'], $v['year'], $v['design'], $v['access'], $v['abstract'], $v['url'],
                $v['source'], $v['tags'], $v['retraction'], $v['license'], $v['retrieved_at'], $userId, $now, $now]);
        return (int)$this->db->lastInsertId();
    }

    /** Edição dos metadados pela equipe (mesmas regras do cadastro; a situação e a sinalização não mudam aqui). */
    public function update(int $id, array $d): void
    {
        $cur = $this->getById($id);
        if (!$cur) {
            throw new DomainException('Artigo não encontrado.');
        }
        $v = $this->validate($d + ['retraction_status' => $cur['retraction_status']]);
        $this->assertUnique($v, $id);
        $this->db->prepare('UPDATE academy_articles SET pmid = ?, doi = ?, title = ?, authors = ?, journal = ?, publication_year = ?, study_design = ?, access_type = ?,
            abstract = ?, url = ?, source = ?, condition_tags = ?, license = ?, retrieved_at = ?, updated_at = ? WHERE id = ?')
            ->execute([$v['pmid'], $v['doi'], $v['title'], $v['authors'], $v['journal'], $v['year'], $v['design'], $v['access'], $v['abstract'], $v['url'],
                $v['source'], $v['tags'], $v['license'], $v['retrieved_at'], date('Y-m-d H:i:s'), $id]);
    }

    /** Publicar (rascunho ou arquivado → publicado) ou arquivar (publicado ou rascunho → arquivado). Devolve a situação anterior. */
    public function setStatus(int $id, string $status): string
    {
        if (!in_array($status, ['published', 'archived'], true)) {
            throw new InvalidArgumentException('Situação inválida.');
        }
        $cur = $this->getById($id);
        if (!$cur) {
            throw new DomainException('Artigo não encontrado.');
        }
        if ($cur['status'] === $status) {
            throw new DomainException($status === 'published' ? 'O artigo já está publicado.' : 'O artigo já está arquivado.');
        }
        $this->db->prepare('UPDATE academy_articles SET status = ?, updated_at = ? WHERE id = ?')->execute([$status, date('Y-m-d H:i:s'), $id]);
        return (string)$cur['status'];
    }

    private function assertUnique(array $v, ?int $selfId): void
    {
        foreach (['doi' => $v['doi'], 'pmid' => $v['pmid']] as $col => $val) {
            if ($val !== null) {
                $s = $this->db->prepare("SELECT id FROM academy_articles WHERE $col = ? AND id <> ?");
                $s->execute([$val, $selfId ?? 0]);
                if ($dup = $s->fetchColumn()) {
                    throw new DomainException(strtoupper($col) . ' já está no acervo (artigo #' . (int)$dup . '). Atualize o registro existente.');
                }
            }
        }
    }

    /** Sinalização de correção/retratação (informada pela fonte). */
    public function setRetraction(int $id, string $status): void
    {
        if (!isset(self::RETRACTION[$status])) {
            throw new InvalidArgumentException('Sinalização inválida.');
        }
        if (!$this->getById($id)) {
            throw new DomainException('Artigo não encontrado.');
        }
        $this->db->prepare('UPDATE academy_articles SET retraction_status = ?, updated_at = ? WHERE id = ?')->execute([$status, date('Y-m-d H:i:s'), $id]);
    }

    /** Vínculo a um protocolo da NI2-635 (código existente no catálogo). Portátil: confere e atualiza ou insere. */
    public function linkToProtocol(string $protocolCode, int $articleId, ?string $note, ?int $userId = null): void
    {
        $protocolCode = trim($protocolCode);
        if (!preg_match('/^[a-z][a-z0-9_]{2,59}$/', $protocolCode)) {
            throw new InvalidArgumentException('Código de protocolo inválido.');
        }
        $p = $this->db->prepare('SELECT 1 FROM clinical_protocols WHERE code = ?');
        $p->execute([$protocolCode]);
        if (!$p->fetchColumn()) {
            throw new DomainException('Protocolo não encontrado no catálogo.');
        }
        if (!$this->getById($articleId)) {
            throw new DomainException('Artigo não encontrado.');
        }
        $note = $note !== null ? mb_substr(trim($note), 0, 255, 'UTF-8') : null;
        $s = $this->db->prepare('SELECT id FROM academy_protocol_links WHERE protocol_code = ? AND article_id = ?');
        $s->execute([$protocolCode, $articleId]);
        if ($id = $s->fetchColumn()) {
            $this->db->prepare('UPDATE academy_protocol_links SET relevance_note = ? WHERE id = ?')->execute([$note, $id]);
            return;
        }
        $this->db->prepare('INSERT INTO academy_protocol_links (protocol_code, article_id, relevance_note, created_by_user_id, created_at) VALUES (?, ?, ?, ?, ?)')
            ->execute([$protocolCode, $articleId, $note, $userId, date('Y-m-d H:i:s')]);
    }

    /** @return list<array<string, mixed>> */
    public function getArticlesForProtocol(string $protocolCode, bool $publishedOnly = true): array
    {
        $s = $this->db->prepare('SELECT a.id, a.title, a.authors, a.journal, a.publication_year, a.study_design, a.doi, a.pmid, a.url, a.retraction_status,
            l.relevance_note, l.created_at AS linked_at FROM academy_protocol_links l JOIN academy_articles a ON a.id = l.article_id
            WHERE l.protocol_code = ?' . ($publishedOnly ? " AND a.status = 'published'" : '') . ' ORDER BY a.publication_year DESC, a.id DESC');
        $s->execute([$protocolCode]);
        return $s->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    /** O resumo só aparece quando a licença declarada permite reprodução. */
    public static function abstractVisible(array $a): bool
    {
        return trim((string)($a['abstract'] ?? '')) !== '' && trim((string)($a['license'] ?? '')) !== '';
    }

    /** @return array{abnt: string, vancouver: string, bibtex: string} */
    public function formatCitations(array $article): array
    {
        $authorsRaw = trim((string)($article['authors'] ?? ''));
        $list = array_values(array_filter(array_map('trim', explode(';', $authorsRaw)), static fn($x) => $x !== ''));
        $first = $list[0] ?? 'Autoria não informada';
        $title = rtrim((string)($article['title'] ?? ''), '.');
        $journal = (string)($article['journal'] ?? '');
        $year = (string)($article['publication_year'] ?? '');
        $doi = (string)($article['doi'] ?? '');
        $url = (string)($article['url'] ?? '');
        $abntAuthors = mb_strtoupper($first, 'UTF-8') . (count($list) > 3 ? ' et al.' : (count($list) > 1 ? '; ' . mb_strtoupper(implode('; ', array_slice($list, 1)), 'UTF-8') : ''));
        $where = $doi !== '' ? " DOI: https://doi.org/$doi." : ($url !== '' ? " Disponível em: $url." : '');
        $vanc = count($list) > 6 ? implode(', ', array_slice($list, 0, 6)) . ', et al' : implode(', ', $list);
        $key = (preg_replace('/[^a-z0-9]/', '', strtolower(explode(',', $first)[0])) ?: 'artigo') . $year;
        $bib = "@article{{$key},\n  title = {{$title}},\n  author = {" . implode(' and ', $list) . "},\n  journal = {{$journal}},\n  year = {{$year}}"
            . ($doi !== '' ? ",\n  doi = {{$doi}}" : '') . ($url !== '' ? ",\n  url = {{$url}}" : '') . "\n}";
        return ['abnt' => "$abntAuthors. $title. $journal, $year.$where", 'vancouver' => "$vanc. $title. $journal. $year;" . ($doi !== '' ? " doi:$doi" : ''), 'bibtex' => $bib];
    }

    public static function studyDesignLabel(string $design): string
    {
        return self::DESIGNS[$design] ?? 'Estudo';
    }

    private function validate(array $d): array
    {
        $s = static fn(string $k): string => trim((string)($d[$k] ?? ''));
        $title = $s('title');
        $authors = $s('authors');
        $journal = $s('journal');
        if ($title === '' || $authors === '' || $journal === '') {
            throw new InvalidArgumentException('Título, autores (separados por ";") e periódico são obrigatórios.');
        }
        $year = (int)$s('publication_year');
        if ($year < 1800 || $year > (int)date('Y') + 1) {
            throw new InvalidArgumentException('Ano de publicação inválido.');
        }
        $doi = $s('doi') !== '' ? preg_replace('#^https?://(dx\.)?doi\.org/#i', '', $s('doi')) : null;
        if ($doi !== null && !preg_match('#^10\.\d{4,9}/\S{1,110}$#', $doi)) {
            throw new InvalidArgumentException('DOI inválido (ex.: 10.1016/S0140-6736(18)31809-9).');
        }
        $pmid = $s('pmid') !== '' ? $s('pmid') : null;
        if ($pmid !== null && !preg_match('/^\d{1,9}$/', $pmid)) {
            throw new InvalidArgumentException('PMID deve ter só números.');
        }
        if ($doi === null && $pmid === null) {
            throw new InvalidArgumentException('Informe DOI ou PMID: sem identificador não há como conferir a referência.');
        }
        $url = $s('url');
        if (!preg_match('#^https://[^\s"<>]{4,490}$#', $url)) {
            throw new InvalidArgumentException('URL oficial deve começar com https://.');
        }
        $design = $s('study_design');
        $access = $s('access_type') ?: 'publisher_link';
        $source = $s('source');
        if (!isset(self::DESIGNS[$design]) || !isset(self::ACCESS[$access]) || !isset(self::SOURCES[$source])) {
            throw new InvalidArgumentException('Escolha desenho do estudo, tipo de acesso e fonte da lista.');
        }
        $retrieved = $s('retrieved_at');
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $retrieved) || strtotime($retrieved) === false || $retrieved > date('Y-m-d')) {
            throw new InvalidArgumentException('Informe a data em que os metadados foram conferidos na fonte.');
        }
        $license = $s('license') !== '' ? mb_substr($s('license'), 0, 60, 'UTF-8') : null;
        $abstract = $s('abstract') !== '' ? $s('abstract') : null;
        if ($abstract !== null && $license === null) {
            throw new InvalidArgumentException('O resumo só pode ser guardado com a licença que permite reproduzi-lo (ex.: CC BY 4.0). Sem licença, deixe só o link.');
        }
        $retraction = $s('retraction_status') ?: 'none';
        if (!isset(self::RETRACTION[$retraction])) {
            throw new InvalidArgumentException('Sinalização inválida.');
        }
        $tags = array_values(array_unique(array_filter(array_map([self::class, 'normalizeTag'], explode(',', $s('condition_tags'))))));
        return ['title' => mb_substr($title, 0, 500, 'UTF-8'), 'authors' => $authors, 'journal' => mb_substr($journal, 0, 255, 'UTF-8'), 'year' => $year,
            'doi' => $doi, 'pmid' => $pmid, 'url' => $url, 'design' => $design, 'access' => $access, 'source' => $source, 'retrieved_at' => $retrieved,
            'license' => $license, 'abstract' => $abstract, 'retraction' => $retraction, 'tags' => $tags ? mb_substr(implode(',', $tags), 0, 255, 'UTF-8') : null];
    }

    private static function normalizeTag(string $t): string
    {
        return trim((string)preg_replace('/[^a-z0-9_]+/', '_', strtolower(trim($t))), '_');
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
