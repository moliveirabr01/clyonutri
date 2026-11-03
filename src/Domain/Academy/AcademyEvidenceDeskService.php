<?php

declare(strict_types=1);

namespace ClyoNutri\Domain\Academy;

use PDO;

final class AcademyEvidenceDeskService
{
    private PDO $db;

    public function __construct(PDO $db)
    {
        $this->db = $db;
    }

    /**
     * Retorna a ficha crítica de evidência vinculada a um artigo.
     */
    public function getByArticleId(int $articleId): ?array
    {
        $stmt = $this->db->prepare('SELECT * FROM academy_evidence_sheets WHERE article_id = ?');
        $stmt->execute([$articleId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    /**
     * Salva ou atualiza uma ficha crítica de evidência.
     */
    public function saveSheet(array $data): int
    {
        $articleId = (int)($data['article_id'] ?? 0);
        if ($articleId <= 0) {
            throw new \InvalidArgumentException('ID do artigo é obrigatório para a ficha de evidência.');
        }

        $curatorUserId = !empty($data['curator_user_id']) ? (int)$data['curator_user_id'] : null;
        $curatorName = trim((string)($data['curator_name'] ?? 'Curador Clyo Academy'));
        $curatorCrn = !empty($data['curator_crn']) ? trim((string)$data['curator_crn']) : null;
        $population = trim((string)($data['population'] ?? ''));
        $intervention = trim((string)($data['intervention'] ?? ''));
        $comparator = trim((string)($data['comparator'] ?? ''));
        $outcomes = trim((string)($data['outcomes'] ?? ''));
        $limitations = trim((string)($data['methodological_limitations'] ?? ''));
        $certainty = trim((string)($data['evidence_certainty'] ?? 'moderate'));
        $applicability = trim((string)($data['clinical_applicability'] ?? ''));
        $conflict = isset($data['conflict_of_interest']) ? trim((string)$data['conflict_of_interest']) : null;
        $status = trim((string)($data['status'] ?? 'published'));
        $now = date('Y-m-d H:i:s');

        $existing = $this->getByArticleId($articleId);
        if ($existing) {
            $stmt = $this->db->prepare("
                UPDATE academy_evidence_sheets SET
                    curator_user_id = ?, curator_name = ?, curator_crn = ?,
                    population = ?, intervention = ?, comparator = ?, outcomes = ?,
                    methodological_limitations = ?, evidence_certainty = ?,
                    clinical_applicability = ?, conflict_of_interest = ?,
                    status = ?, reviewed_at = ?
                WHERE id = ?
            ");
            $stmt->execute([
                $curatorUserId, $curatorName, $curatorCrn,
                $population, $intervention, $comparator, $outcomes,
                $limitations, $certainty, $applicability, $conflict,
                $status, $now, (int)$existing['id']
            ]);
            $sheetId = (int)$existing['id'];
        } else {
            $stmt = $this->db->prepare("
                INSERT INTO academy_evidence_sheets (
                    article_id, curator_user_id, curator_name, curator_crn,
                    population, intervention, comparator, outcomes,
                    methodological_limitations, evidence_certainty,
                    clinical_applicability, conflict_of_interest, status,
                    reviewed_at, created_at
                ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
            ");
            $stmt->execute([
                $articleId, $curatorUserId, $curatorName, $curatorCrn,
                $population, $intervention, $comparator, $outcomes,
                $limitations, $certainty, $applicability, $conflict,
                $status, $now, $now
            ]);
            $sheetId = (int)$this->db->lastInsertId();
        }

        // Marca que o artigo possui ficha de evidência
        $up = $this->db->prepare("UPDATE academy_articles SET has_evidence_sheet = 1 WHERE id = ?");
        $up->execute([$articleId]);

        return $sheetId;
    }

    /**
     * Retorna o rótulo descritivo do grau de certeza da evidência (metodologia GRADE).
     */
    public static function certaintyBadge(string $certainty): array
    {
        return match ($certainty) {
            'high' => [
                'label' => 'Certeza Alta',
                'description' => 'Alta confiança de que o efeito real da intervenção é próximo ao estimado.',
                'color' => '#027A48',
                'bg' => '#ECFDF3'
            ],
            'moderate' => [
                'label' => 'Certeza Moderada',
                'description' => 'Confiança moderada no efeito; o efeito real provavelmente é próximo, mas pode diferir.',
                'color' => '#B54708',
                'bg' => '#FFFAEB'
            ],
            'low' => [
                'label' => 'Certeza Baixa',
                'description' => 'Confiança limitada no efeito; o efeito real pode ser substancialmente diferente do estimado.',
                'color' => '#C4320A',
                'bg' => '#FFF6ED'
            ],
            'very_low' => [
                'label' => 'Certeza Muito Baixa',
                'description' => 'Mínima confiança no efeito estimado; grande incerteza metodológica ou alto risco de viés.',
                'color' => '#B42318',
                'bg' => '#FEF3F2'
            ],
            default => [
                'label' => 'Em Curadoria',
                'description' => 'Ficha técnica em elaboração pelo corpo editorial científico.',
                'color' => '#344054',
                'bg' => '#F2F4F7'
            ]
        };
    }

    /**
     * Popula fichas de evidência para os artigos semente.
     */
    public function seedIfEmpty(): void
    {
        $count = (int)$this->db->query("SELECT COUNT(*) FROM academy_evidence_sheets")->fetchColumn();
        if ($count > 0) {
            return;
        }

        // Buscar artigos sementes criados pelo AcademyArticleService
        $stmt = $this->db->query("SELECT id, doi FROM academy_articles");
        $articles = $stmt->fetchAll(PDO::FETCH_KEY_PAIR) ?: [];

        foreach ($articles as $id => $doi) {
            if ($doi === '10.1016/S0140-6736(18)31808-7') {
                $this->saveSheet([
                    'article_id' => $id,
                    'curator_name' => 'Dra. Ana Silveira',
                    'curator_crn' => 'CRN-3 12490',
                    'population' => 'Adultos de 18 a 75 anos sem diagnóstico prévio de doenças cardiovasculares ou diabetes descompensado em coortes prospectivas e ECRs (total N > 135.000 pessoas-ano).',
                    'intervention' => 'Consumo diário elevado de fibras alimentares totais (> 25 g a 35 g/dia), predominantemente oriundas de cereais integrais, leguminosas e vegetais.',
                    'comparator' => 'Ingestão habitual reduzida de fibras alimentares (< 15 g/dia).',
                    'outcomes' => 'Redução de 15% a 30% na mortalidade cardiovascular e por todas as causas; redução relativa de 22% na incidência de diabetes mellitus tipo 2.',
                    'methodological_limitations' => 'Heterogeneidade nos métodos de recordatório alimentar entre coortes; variabilidade na solubilidade das fibras.',
                    'evidence_certainty' => 'high',
                    'clinical_applicability' => 'Recomenda-se meta primária mínima de 25 g/dia de fibras totais para planos alimentares de prevenção metabólica, priorizando fontes integrais intactas.',
                    'conflict_of_interest' => 'Financiamento por órgãos de pesquisa pública (Health Research Council of NZ / OMS); autores declaram ausência de conflitos com indústrias alimentícias.',
                ]);
            } elseif ($doi === '10.1056/NEJMoa1800389') {
                $this->saveSheet([
                    'article_id' => $id,
                    'curator_name' => 'Dr. Marcelo Fonseca',
                    'curator_crn' => 'CRN-3 8742',
                    'population' => '7.447 homens (55 a 80 anos) e mulheres (60 a 80 anos) com alto risco cardiovascular mas sem doença cardiovascular manifesta no recrutamento.',
                    'intervention' => 'Dieta Mediterrânea suplementada com Azeite de Oliva Extravirgem (~1 L/semana) OU Dieta Mediterrânea suplementada com Mix de Castanhas (30 g/dia).',
                    'comparator' => 'Dieta controle com aconselhamento para redução do teor total de lipídios na dieta (dieta low-fat).',
                    'outcomes' => 'Redução de 30% na incidência composta de infarto agudo do miocárdio, AVC e morte cardiovascular (HR 0,69 e 0,72 respectivamente).',
                    'methodological_limitations' => 'Retratado e republicado em 2018 para corrigir inconsistências pontuais de randomização em pequenos centros específicos; as conclusões permaneceram robustas.',
                    'evidence_certainty' => 'high',
                    'clinical_applicability' => 'Sólida evidência para prescrever gorduras mono e poli-insaturadas de alta qualidade (azeite e oleaginosas) sem fobia de gorduras benéficas.',
                    'conflict_of_interest' => 'Azeite e castanhas fornecidos por cooperativas agrícolas produtoras; desenho e análise controlados independentemente pelo comitê acadêmico.',
                ]);
            }
        }
    }
}
