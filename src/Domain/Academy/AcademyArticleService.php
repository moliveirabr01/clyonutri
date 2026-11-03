<?php

declare(strict_types=1);

namespace ClyoNutri\Domain\Academy;

use PDO;

final class AcademyArticleService
{
    private PDO $db;

    public function __construct(PDO $db)
    {
        $this->db = $db;
    }

    /**
     * Busca artigos com filtros multifacetados.
     *
     * @param array{
     *   q?: string,
     *   study_design?: string,
     *   access_type?: string,
     *   condition_tag?: string,
     *   year_min?: int,
     *   year_max?: int,
     *   has_evidence_sheet?: bool,
     *   limit?: int,
     *   offset?: int
     * } $filters
     * @return array{total: int, items: array<int, array<string, mixed>>}
     */
    public function search(array $filters = []): array
    {
        $where = ['1=1'];
        $params = [];

        $q = trim((string)($filters['q'] ?? ''));
        if ($q !== '') {
            $where[] = '(title LIKE ? OR authors LIKE ? OR journal LIKE ? OR abstract LIKE ? OR doi LIKE ? OR pmid LIKE ?)';
            $like = '%' . $q . '%';
            $params = array_merge($params, [$like, $like, $like, $like, $like, $like]);
        }

        $studyDesign = trim((string)($filters['study_design'] ?? ''));
        if ($studyDesign !== '' && $studyDesign !== 'all') {
            $where[] = 'study_design = ?';
            $params[] = $studyDesign;
        }

        $accessType = trim((string)($filters['access_type'] ?? ''));
        if ($accessType !== '' && $accessType !== 'all') {
            $where[] = 'access_type = ?';
            $params[] = $accessType;
        }

        $conditionTag = trim((string)($filters['condition_tag'] ?? ''));
        if ($conditionTag !== '' && $conditionTag !== 'all') {
            $where[] = 'condition_tags LIKE ?';
            $params[] = '%' . $conditionTag . '%';
        }

        if (!empty($filters['year_min']) && is_numeric($filters['year_min'])) {
            $where[] = 'publication_year >= ?';
            $params[] = (int)$filters['year_min'];
        }

        if (!empty($filters['year_max']) && is_numeric($filters['year_max'])) {
            $where[] = 'publication_year <= ?';
            $params[] = (int)$filters['year_max'];
        }

        if (!empty($filters['has_evidence_sheet'])) {
            $where[] = 'has_evidence_sheet = 1';
        }

        $sqlWhere = implode(' AND ', $where);

        // Contagem total
        $countStmt = $this->db->prepare("SELECT COUNT(*) FROM academy_articles WHERE $sqlWhere");
        $countStmt->execute($params);
        $total = (int)$countStmt->fetchColumn();

        // Paginação
        $limit = max(1, min(100, (int)($filters['limit'] ?? 20)));
        $offset = max(0, (int)($filters['offset'] ?? 0));

        $selectStmt = $this->db->prepare(
            "SELECT * FROM academy_articles WHERE $sqlWhere ORDER BY publication_year DESC, id DESC LIMIT $limit OFFSET $offset"
        );
        $selectStmt->execute($params);
        $items = $selectStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

        return [
            'total' => $total,
            'items' => $items,
        ];
    }

    /**
     * Obtém um artigo pelo ID.
     */
    public function getById(int $id): ?array
    {
        $stmt = $this->db->prepare('SELECT * FROM academy_articles WHERE id = ?');
        $stmt->execute([$id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    /**
     * Cadastra ou atualiza um artigo no acervo da Academy.
     */
    public function upsert(array $data): int
    {
        $title = trim((string)($data['title'] ?? ''));
        if ($title === '') {
            throw new \InvalidArgumentException('Título do artigo é obrigatório.');
        }

        $authors = trim((string)($data['authors'] ?? ''));
        $journal = trim((string)($data['journal'] ?? ''));
        $year = (int)($data['publication_year'] ?? date('Y'));
        $studyDesign = trim((string)($data['study_design'] ?? 'rct'));
        $accessType = trim((string)($data['access_type'] ?? 'publisher_link'));
        $abstract = isset($data['abstract']) ? trim((string)$data['abstract']) : null;
        $url = trim((string)($data['url'] ?? ''));
        $pmid = !empty($data['pmid']) ? trim((string)$data['pmid']) : null;
        $doi = !empty($data['doi']) ? trim((string)$data['doi']) : null;
        $source = trim((string)($data['source'] ?? 'pubmed'));
        $conditionTags = !empty($data['condition_tags']) ? trim((string)$data['condition_tags']) : null;
        $hasEvidenceSheet = !empty($data['has_evidence_sheet']) ? 1 : 0;
        $retractionStatus = trim((string)($data['retraction_status'] ?? 'none'));
        $now = date('Y-m-d H:i:s');

        // Se já existe por DOI ou PMID, atualiza
        $existingId = null;
        if ($doi !== null) {
            $stmt = $this->db->prepare('SELECT id FROM academy_articles WHERE doi = ?');
            $stmt->execute([$doi]);
            $existingId = $stmt->fetchColumn() ?: null;
        }
        if (!$existingId && $pmid !== null) {
            $stmt = $this->db->prepare('SELECT id FROM academy_articles WHERE pmid = ?');
            $stmt->execute([$pmid]);
            $existingId = $stmt->fetchColumn() ?: null;
        }

        if ($existingId) {
            $update = $this->db->prepare("
                UPDATE academy_articles SET
                    title = ?, authors = ?, journal = ?, publication_year = ?,
                    study_design = ?, access_type = ?, abstract = ?, url = ?,
                    source = ?, condition_tags = ?, has_evidence_sheet = ?,
                    retraction_status = ?, updated_at = ?
                WHERE id = ?
            ");
            $update->execute([
                $title, $authors, $journal, $year, $studyDesign, $accessType,
                $abstract, $url, $source, $conditionTags, $hasEvidenceSheet,
                $retractionStatus, $now, (int)$existingId
            ]);
            return (int)$existingId;
        }

        $insert = $this->db->prepare("
            INSERT INTO academy_articles (
                pmid, doi, title, authors, journal, publication_year,
                study_design, access_type, abstract, url, source,
                condition_tags, has_evidence_sheet, retraction_status,
                created_at, updated_at
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
        ");
        $insert->execute([
            $pmid, $doi, $title, $authors, $journal, $year,
            $studyDesign, $accessType, $abstract, $url, $source,
            $conditionTags, $hasEvidenceSheet, $retractionStatus,
            $now, $now
        ]);

        return (int)$this->db->lastInsertId();
    }

    /**
     * Vincula um artigo a um protocolo clínico (NI2-635).
     */
    public function linkToProtocol(string $protocolCode, int $articleId, ?string $relevanceNote = null): bool
    {
        $stmt = $this->db->prepare("
            INSERT OR REPLACE INTO academy_protocol_links (protocol_code, article_id, relevance_note, created_at)
            VALUES (?, ?, ?, ?)
        ");
        return $stmt->execute([
            $protocolCode,
            $articleId,
            $relevanceNote,
            date('Y-m-d H:i:s')
        ]);
    }

    /**
     * Retorna os artigos vinculados a um protocolo clínico específico.
     *
     * @return array<int, array<string, mixed>>
     */
    public function getArticlesForProtocol(string $protocolCode): array
    {
        $stmt = $this->db->prepare("
            SELECT a.*, l.relevance_note, l.created_at as linked_at
            FROM academy_protocol_links l
            JOIN academy_articles a ON a.id = l.article_id
            WHERE l.protocol_code = ?
            ORDER BY a.publication_year DESC, a.id DESC
        ");
        $stmt->execute([$protocolCode]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    /**
     * Gera citação do artigo em formatos acadêmicos padronizados (ABNT, Vancouver, BibTeX).
     *
     * @return array{abnt: string, vancouver: string, bibtex: string}
     */
    public function formatCitations(array $article): array
    {
        $authors = (string)($article['authors'] ?? 'Autor Desconhecido');
        $title = rtrim((string)($article['title'] ?? ''), '.');
        $journal = (string)($article['journal'] ?? '');
        $year = (string)($article['publication_year'] ?? '');
        $doi = (string)($article['doi'] ?? '');
        $url = (string)($article['url'] ?? '');

        // Formatação ABNT
        $firstAuthor = explode(',', $authors)[0] ?? $authors;
        $abntAuthors = mb_strtoupper(trim($firstAuthor));
        if (str_contains($authors, ';') || str_contains($authors, ',')) {
            $abntAuthors .= ' et al.';
        }
        $abntDoi = $doi !== '' ? " Disponível em: <https://doi.org/$doi>." : ($url !== '' ? " Disponível em: <$url>." : '');
        $abnt = "{$abntAuthors} {$title}. {$journal}, {$year}.{$abntDoi}";

        // Formatação Vancouver
        $vancouverDoi = $doi !== '' ? " doi: $doi." : '';
        $vancouver = "{$authors}. {$title}. {$journal}. {$year};{$vancouverDoi}";

        // BibTeX
        $cleanKey = preg_replace('/[^a-zA-Z0-9]/', '', strtolower(explode(' ', $firstAuthor)[0] ?? 'artigo')) . $year;
        $bibtex = "@article{{$cleanKey},\n" .
            "  title = {{$title}},\n" .
            "  author = {{$authors}},\n" .
            "  journal = {{$journal}},\n" .
            "  year = {{$year}}" .
            ($doi !== '' ? ",\n  doi = {{$doi}}" : "") .
            ($url !== '' ? ",\n  url = {{$url}}" : "") .
            "\n}";

        return [
            'abnt' => $abnt,
            'vancouver' => $vancouver,
            'bibtex' => $bibtex,
        ];
    }

    /**
     * Rótulo em português para cada desenho de estudo.
     */
    public static function studyDesignLabel(string $design): string
    {
        return match ($design) {
            'systematic_review' => 'Revisão Sistemática',
            'meta_analysis' => 'Metanálise',
            'rct' => 'Ensaio Clínico Randomizado (ECR)',
            'clinical_guideline' => 'Diretriz Clínica Baseada em Evidências',
            'cohort' => 'Estudo de Coorte Observacional',
            'case_control' => 'Caso-Controle',
            'cross_sectional' => 'Transversal',
            'preprint' => 'Preprint (Não Revisado por Pares)',
            'editorial' => 'Comentário Editorial / Opinião',
            default => 'Estudo Científico'
        };
    }

    /**
     * Semente de artigos essenciais para a Academy se o banco estiver vazio.
     */
    public function seedIfEmpty(): void
    {
        $count = (int)$this->db->query("SELECT COUNT(*) FROM academy_articles")->fetchColumn();
        if ($count > 0) {
            return;
        }

        $seeds = [
            [
                'pmid' => '30487102',
                'doi' => '10.1016/S0140-6736(18)31808-7',
                'title' => 'Carbohydrate quality and human health: a series of systematic reviews and meta-analyses',
                'authors' => 'Reynolds, Andrew; Mann, Jim; Cummings, John; Winter, Nicola; Mete, Evelyn; Te Morenga, Lisa',
                'journal' => 'The Lancet',
                'publication_year' => 2019,
                'study_design' => 'meta_analysis',
                'access_type' => 'open_access',
                'abstract' => 'Evidências de estudos prospectivos e ensaios clínicos demonstraram que a ingestão elevada de fibras alimentares (25-29 g/dia) está associada a reduções significativas na mortalidade por todas as causas e risco de diabetes tipo 2, doenças cardiovasculares e câncer colorretal.',
                'url' => 'https://doi.org/10.1016/S0140-6736(18)31808-7',
                'source' => 'pubmed',
                'condition_tags' => 'diabetes,cardiovascular,obesidade',
                'has_evidence_sheet' => 1,
            ],
            [
                'pmid' => '29801004',
                'doi' => '10.1056/NEJMoa1800389',
                'title' => 'Primary Prevention of Cardiovascular Disease with a Mediterranean Diet Supplemented with Extra-Virgin Olive Oil or Nuts',
                'authors' => 'Estruch, Ramón; Ros, Emilio; Salas-Salvadó, Jordi; Covas, Maria-Isabel; Corella, Dolores; Arós, Fernando; Gómez-Gracia, Enrique; Ruiz-Gutiérrez, Valentina; Fiol, Miquel; Lapetra, José; et al.',
                'journal' => 'New England Journal of Medicine (NEJM)',
                'publication_year' => 2018,
                'study_design' => 'rct',
                'access_type' => 'open_access',
                'abstract' => 'Ensaio clínico randomizado multicêntrico avaliando a dieta mediterrânea suplementada com azeite de oliva extravirgem ou castanhas na prevenção primária de eventos cardiovasculares maiores em participantes com alto risco cardiovascular.',
                'url' => 'https://doi.org/10.1056/NEJMoa1800389',
                'source' => 'pubmed',
                'condition_tags' => 'hipertensao,cardiovascular,dislipidemia',
                'has_evidence_sheet' => 1,
            ],
            [
                'pmid' => '33358055',
                'doi' => '10.1111/jhn.12845',
                'title' => 'Celiac disease and gluten-free diet: Nutritional adequacy, gut microbiota and long-term implications',
                'authors' => 'Staudacher, Heidi M.; Gibson, Peter R.; Whelan, Kevin',
                'journal' => 'Journal of Human Nutrition and Dietetics',
                'publication_year' => 2021,
                'study_design' => 'systematic_review',
                'access_type' => 'publisher_link',
                'abstract' => 'Revisão sistemática sobre o impacto nutricional e na microbiota intestinal da dieta isenta de glúten em pacientes com doença celíaca confirmada, com ênfase na ingestão de micronutrientes (ferro, folato, zinco, cálcio) e fibras.',
                'url' => 'https://doi.org/10.1111/jhn.12845',
                'source' => 'pubmed',
                'condition_tags' => 'celiaca,gastroenterologia',
                'has_evidence_sheet' => 1,
            ],
            [
                'pmid' => '32732152',
                'doi' => '10.1053/j.ajkd.2020.05.006',
                'title' => 'KDOQI Clinical Practice Guideline for Nutrition in CKD: 2020 Update',
                'authors' => 'Ikizler, T. Alp; Burrowes, Jerrilynn D.; Byham-Gray, Laura D.; Campbell, Katrina L.; Carrero, Juan-Jesus; Chan, Wai; Fouque, Denis; Friedman, Allon N.; Ghaddar, Sahar; Goldstein-Fuchs, Debra J.; et al.',
                'journal' => 'American Journal of Kidney Diseases (AJKD)',
                'publication_year' => 2020,
                'study_design' => 'clinical_guideline',
                'access_type' => 'open_access',
                'abstract' => 'Diretrizes clínicas baseadas em evidências para terapia nutricional na Doença Renal Crônica (DRC estágios 1 a 5), incluindo recomendações de aporte proteico, calórico, fósforo, potássio e sódio.',
                'url' => 'https://doi.org/10.1053/j.ajkd.2020.05.006',
                'source' => 'pubmed',
                'condition_tags' => 'renal,hipertensao',
                'has_evidence_sheet' => 1,
            ],
            [
                'pmid' => '31974140',
                'doi' => '10.2337/dc20-S005',
                'title' => 'Facilitating Behavior Change and Well-being to Improve Health Outcomes: Standards of Medical Care in Diabetes—2020',
                'authors' => 'American Diabetes Association (ADA)',
                'journal' => 'Diabetes Care',
                'publication_year' => 2020,
                'study_design' => 'clinical_guideline',
                'access_type' => 'open_access',
                'abstract' => 'Padrões de cuidados médicos e conduta nutricional individualizada para pacientes com diabetes mellitus tipo 1 e tipo 2, contagem de carboidratos, metas glicêmicas e prevenção de hipoglicemia.',
                'url' => 'https://doi.org/10.2337/dc20-S005',
                'source' => 'pubmed',
                'condition_tags' => 'diabetes,obesidade',
                'has_evidence_sheet' => 1,
            ],
        ];

        foreach ($seeds as $seed) {
            $id = $this->upsert($seed);
            // Vincular às condições dos protocolos clínicos
            if (str_contains($seed['condition_tags'], 'diabetes')) {
                $this->linkToProtocol('diabetes', $id, 'Evidência de referência para manejo glicêmico e qualidade de carboidratos.');
            }
            if (str_contains($seed['condition_tags'], 'hipertensao')) {
                $this->linkToProtocol('hipertensao', $id, 'Evidência classe A para prevenção cardiovascular e controle pressórico.');
            }
            if (str_contains($seed['condition_tags'], 'celiaca')) {
                $this->linkToProtocol('celiaca', $id, 'Diretriz de adequação nutricional na dieta sem glúten estrita.');
            }
            if (str_contains($seed['condition_tags'], 'renal')) {
                $this->linkToProtocol('renal', $id, 'KDOQI guideline: padrão-ouro de prescrição proteica na DRC.');
            }
        }
    }
}
