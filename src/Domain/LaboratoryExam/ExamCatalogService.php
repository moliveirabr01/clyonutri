<?php

declare(strict_types=1);

namespace ClyoNutri\Domain\LaboratoryExam;

use DomainException;
use InvalidArgumentException;
use PDO;

/**
 * Catálogo Curado de Exames Laboratoriais com Sinônimos e Grupos (NI2-532).
 *
 * Fornece:
 *  - Busca tolerante por texto com correspondência de sinônimos clínicos e nomes populares.
 *  - Indicação explícita quando um item foi localizado via sinônimo (ex: "B12" -> "Vitamina B12").
 *  - Categorias biológicas e tipo de amostra recomendada.
 *  - Ocultação de exames inativos em novos pedidos, preservando-os em históricos.
 *  - Bloqueio estrito de procedimentos fora do escopo aprovado.
 */
class ExamCatalogService
{
    private PDO $db;

    public function __construct(PDO $db)
    {
        $this->db = $db;
    }

    /**
     * Busca exames no catálogo com suporte a acentos, maiúsculas, sinônimos e categorias.
     */
    /**
     * v47: só aparecem os exames do catálogo geral (clinic_id nulo) e os personalizados da própria clínica que
     * já foram validados. Sem $clinicId, só o catálogo geral.
     */
    public function search(string $query, ?string $category = null, bool $onlyActive = true, int $limit = 30, ?int $clinicId = null): array
    {
        $normalizedQuery = self::normalizeSearchText($query);
        $tokens = array_filter(explode(' ', $normalizedQuery));

        $sql = "SELECT id, code, canonical_name, short_name, category, sample_type, fasting_hours,
                       nutritional_indication, synonyms, is_laboratorial, is_nutritional_scope, is_active, version,
                       clinic_id, origin, validation_status, standard_code_system, standard_code, clinical_review_status
                FROM exam_catalog
                WHERE validation_status = 'approved'";
        $params = [];
        if ($clinicId !== null) {
            $sql .= " AND (clinic_id IS NULL OR clinic_id = :clinic_id)";
            $params['clinic_id'] = $clinicId;
        } else {
            $sql .= " AND clinic_id IS NULL";
        }

        if ($onlyActive) {
            $sql .= " AND is_active = 1";
        }

        if ($category !== null && trim($category) !== '') {
            $sql .= " AND category = :category";
            $params['category'] = trim($category);
        }

        if (!empty($tokens)) {
            $tokenClauses = [];
            foreach ($tokens as $idx => $token) {
                $paramKey = ":token_{$idx}";
                $tokenClauses[] = "search_text LIKE {$paramKey} ESCAPE '!'";
                $params[$paramKey] = '%' . strtr($token, ['!' => '!!', '%' => '!%', '_' => '!_']) . '%';
            }
            $sql .= " AND (" . implode(' AND ', $tokenClauses) . ")";
        }

        $sql .= " ORDER BY canonical_name ASC LIMIT " . max(1, min(100, $limit));

        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // Pós-processamento para identificar match por sinônimo
        $results = [];
        foreach ($rows as $row) {
            $matchedSynonym = null;
            if (!empty($query)) {
                $matchedSynonym = $this->detectMatchedSynonym($query, $row['canonical_name'], (string)($row['synonyms'] ?? ''));
            }

            $row['id'] = (int)$row['id'];
            $row['fasting_hours'] = $row['fasting_hours'] !== null ? (int)$row['fasting_hours'] : null;
            $row['is_active'] = (bool)$row['is_active'];
            $row['matched_synonym'] = $matchedSynonym;
            $results[] = $row;
        }

        return $results;
    }

    /**
     * Retorna um exame por ID.
     */
    public function getById(int $id, bool $onlyActive = false): ?array
    {
        $sql = "SELECT * FROM exam_catalog WHERE id = ?";
        if ($onlyActive) {
            $sql .= " AND is_active = 1";
        }
        $stmt = $this->db->prepare($sql);
        $stmt->execute([$id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    /**
     * Retorna um exame por código único (ex: 'HEMOGRAMA_COMPLETO').
     */
    public function getByCode(string $code, bool $onlyActive = false): ?array
    {
        $sql = "SELECT * FROM exam_catalog WHERE code = ?";
        if ($onlyActive) {
            $sql .= " AND is_active = 1";
        }
        $stmt = $this->db->prepare($sql);
        $stmt->execute([trim($code)]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    /**
     * Valida se o exame é elegível para inclusão em uma solicitação ativa.
     *
     * @throws DomainException Se o exame for inexistente, inativo, não laboratorial ou fora de escopo.
     */
    public function assertExamEligible(int $examId, ?int $clinicId = null): array
    {
        $exam = $this->getById($examId, false);
        // Exame personalizado de outra clínica: responde como inexistente (não revela que existe).
        if (!$exam || ($exam['clinic_id'] !== null && $exam['clinic_id'] !== '' && (int)$exam['clinic_id'] !== (int)$clinicId)) {
            throw new InvalidArgumentException("Exame laboratorial ID {$examId} não encontrado no catálogo.");
        }

        if ((string)($exam['validation_status'] ?? 'approved') !== 'approved') {
            throw new DomainException("O exame '{$exam['canonical_name']}' ainda não foi validado pela responsável da clínica e não pode entrar em solicitações.");
        }

        if ((int)$exam['is_active'] !== 1) {
            throw new DomainException("O exame '{$exam['canonical_name']}' está inativo e não pode ser incluído em novas solicitações.");
        }

        if ((int)$exam['is_laboratorial'] !== 1) {
            throw new DomainException("O procedimento '{$exam['canonical_name']}' não é de natureza laboratorial.");
        }

        if ((int)$exam['is_nutritional_scope'] !== 1) {
            throw new DomainException("O exame '{$exam['canonical_name']}' extrapola o escopo de atuação do nutricionista.");
        }

        // Checagem de segurança regulatória dupla
        ExamRegulatoryPolicy::assertLaboratorialScope($exam['canonical_name']);

        return $exam;
    }

    /**
     * Lista todas as categorias disponíveis no catálogo.
     */
    public function listCategories(?int $clinicId = null): array
    {
        if ($clinicId === null) {
            $stmt = $this->db->query("SELECT DISTINCT category FROM exam_catalog WHERE is_active = 1 AND validation_status = 'approved' AND clinic_id IS NULL ORDER BY category ASC");
        } else {
            $stmt = $this->db->prepare("SELECT DISTINCT category FROM exam_catalog WHERE is_active = 1 AND validation_status = 'approved' AND (clinic_id IS NULL OR clinic_id = ?) ORDER BY category ASC");
            $stmt->execute([$clinicId]);
        }
        return $stmt->fetchAll(PDO::FETCH_COLUMN);
    }

    // ------------------------------------------------------------ favoritos (v47, NI2-532)

    /** @return int[] ids dos exames favoritos do usuário nesta clínica (só os que ele ainda pode usar). */
    public function favoriteIds(int $clinicId, int $userId): array
    {
        $st = $this->db->prepare("SELECT f.exam_id FROM exam_favorites f
            JOIN exam_catalog c ON c.id = f.exam_id AND (c.clinic_id IS NULL OR c.clinic_id = f.clinic_id)
            WHERE f.clinic_id = ? AND f.user_id = ? AND c.is_active = 1 AND c.validation_status = 'approved'
            ORDER BY f.id ASC");
        $st->execute([$clinicId, $userId]);
        return array_map('intval', $st->fetchAll(PDO::FETCH_COLUMN));
    }

    /** Marca ou desmarca. Devolve true se ficou favorito. Favorito é só atalho: não pré-seleciona nada no pedido. */
    public function toggleFavorite(int $clinicId, int $userId, int $examId): bool
    {
        $exam = $this->assertExamEligible($examId, $clinicId);
        $st = $this->db->prepare('SELECT id FROM exam_favorites WHERE clinic_id = ? AND user_id = ? AND exam_id = ?');
        $st->execute([$clinicId, $userId, (int)$exam['id']]);
        $id = $st->fetchColumn();
        if ($id) {
            $this->db->prepare('DELETE FROM exam_favorites WHERE id = ? AND clinic_id = ? AND user_id = ?')->execute([(int)$id, $clinicId, $userId]);
            return false;
        }
        $this->db->prepare('INSERT INTO exam_favorites (clinic_id, user_id, exam_id, created_at) VALUES (?, ?, ?, ?)')
            ->execute([$clinicId, $userId, (int)$exam['id'], date('Y-m-d H:i:s')]);
        return true;
    }

    // ------------------------------------------------- exames personalizados (v47, NI2-532)

    /**
     * Nutricionista da clínica propõe um exame que não está no catálogo. Fica "aguardando validação" e não entra
     * em solicitação até a responsável (owner/admin) validar. Imagem, endoscopia, biópsia etc. são recusados já aqui.
     *
     * $input: canonical_name, category, sample_type, fasting_hours, nutritional_indication, synonyms,
     *         standard_code_system, standard_code, declaration (bool: é laboratorial e pertinente ao acompanhamento)
     */
    public function submitCustomExam(int $clinicId, int $userId, array $input): array
    {
        $name = self::clean((string)($input['canonical_name'] ?? ''), 255);
        $category = self::clean((string)($input['category'] ?? ''), 100);
        $sample = self::clean((string)($input['sample_type'] ?? ''), 100);
        $indication = self::clean((string)($input['nutritional_indication'] ?? ''), 2000);
        $synonyms = self::clean((string)($input['synonyms'] ?? ''), 1000);
        $codeSystem = strtoupper(self::clean((string)($input['standard_code_system'] ?? ''), 20));
        $code = self::clean((string)($input['standard_code'] ?? ''), 40);
        $fasting = trim((string)($input['fasting_hours'] ?? ''));

        if (mb_strlen($name, 'UTF-8') < 3) {
            throw new InvalidArgumentException('Informe o nome do exame (mínimo de 3 caracteres).');
        }
        if ($category === '' || $sample === '') {
            throw new InvalidArgumentException('Informe a categoria e o tipo de amostra do exame.');
        }
        if (mb_strlen($indication, 'UTF-8') < 15) {
            throw new InvalidArgumentException('Explique a pertinência para o acompanhamento nutricional (mínimo de 15 caracteres).');
        }
        if ($fasting !== '' && (!ctype_digit($fasting) || (int)$fasting > 24)) {
            throw new InvalidArgumentException('Jejum deve ser um número inteiro de horas, de 0 a 24.');
        }
        if ($codeSystem !== '' && !in_array($codeSystem, ['TUSS', 'LOINC', 'OUTRO'], true)) {
            throw new InvalidArgumentException('Sistema de código: use TUSS, LOINC ou OUTRO (ou deixe em branco).');
        }
        if (($codeSystem === '') !== ($code === '')) {
            throw new InvalidArgumentException('Informe o sistema e o código juntos, ou deixe os dois em branco.');
        }
        if (empty($input['declaration'])) {
            throw new InvalidArgumentException('Confirme que é um exame laboratorial pertinente ao acompanhamento nutricional.');
        }
        ExamRegulatoryPolicy::assertLaboratorialScope($name);
        if ($synonyms !== '') {
            ExamRegulatoryPolicy::assertLaboratorialScope($synonyms);
        }

        $dup = $this->db->prepare("SELECT canonical_name FROM exam_catalog
            WHERE (clinic_id IS NULL OR clinic_id = ?) AND validation_status <> 'rejected'
              AND (search_text LIKE ? ESCAPE '!' OR canonical_name = ?) LIMIT 1");
        $norm = ExamText::normalize($name);
        $dup->execute([$clinicId, '%' . strtr($norm, ['!' => '!!', '%' => '!%', '_' => '!_']) . '%', $name]);
        if ($existing = $dup->fetchColumn()) {
            throw new DomainException("Já existe no catálogo um exame com esse nome: {$existing}. Use a busca da solicitação.");
        }

        $now = date('Y-m-d H:i:s');
        $codeKey = 'CLIN' . $clinicId . '_' . strtoupper(bin2hex(random_bytes(4)));
        $this->db->prepare("INSERT INTO exam_catalog (code, canonical_name, short_name, category, sample_type, fasting_hours,
                nutritional_indication, synonyms, search_text, is_laboratorial, is_nutritional_scope, is_active, version,
                clinic_id, origin, validation_status, submitted_by_user_id, standard_code_system, standard_code,
                clinical_review_status, created_at, updated_at)
            VALUES (?, ?, NULL, ?, ?, ?, ?, ?, ?, 1, 1, 1, 1, ?, 'clinic_custom', 'pending', ?, ?, ?, 'pending', ?, ?)")
            ->execute([$codeKey, $name, $category, $sample, $fasting === '' ? null : (int)$fasting, $indication,
                $synonyms === '' ? null : $synonyms, self::buildSearchText($name, null, $category, $synonyms),
                $clinicId, $userId, $codeSystem === '' ? null : $codeSystem, $code === '' ? null : $code, $now, $now]);
        return $this->getById((int)$this->db->lastInsertId()) ?? [];
    }

    /**
     * Owner/admin da clínica valida (aprova ou recusa) um exame personalizado da própria clínica. Recusa exige motivo.
     * Quem não é owner/admin não valida, nem o próprio pedido.
     */
    public function validateCustomExam(int $clinicId, int $userId, int $examId, bool $approve, string $note): array
    {
        $exam = $this->getById($examId);
        if (!$exam || (int)($exam['clinic_id'] ?? 0) !== $clinicId || ($exam['origin'] ?? '') !== 'clinic_custom') {
            throw new InvalidArgumentException('Exame personalizado não encontrado nesta clínica.');
        }
        if (!self::isClinicManager($this->db, $userId, $clinicId)) {
            throw new DomainException('Só a responsável pela clínica (proprietária ou administradora) valida exames personalizados.');
        }
        if (($exam['validation_status'] ?? '') !== 'pending') {
            throw new DomainException('Este exame já foi validado ou recusado.');
        }
        $note = self::clean($note, 500);
        if (!$approve && mb_strlen($note, 'UTF-8') < 5) {
            throw new InvalidArgumentException('Informe o motivo da recusa (mínimo de 5 caracteres).');
        }
        if ($approve) {
            ExamRegulatoryPolicy::assertLaboratorialScope((string)$exam['canonical_name']);
        }
        $st = $this->db->prepare("UPDATE exam_catalog SET validation_status = ?, validated_by_user_id = ?, validated_at = ?,
                validation_note = ?, updated_at = ? WHERE id = ? AND clinic_id = ? AND validation_status = 'pending'");
        $now = date('Y-m-d H:i:s');
        $st->execute([$approve ? 'approved' : 'rejected', $userId, $now, $note === '' ? null : $note, $now, $examId, $clinicId]);
        if ($st->rowCount() !== 1) {
            throw new DomainException('Este exame já foi validado ou recusado.');
        }
        return $this->getById($examId) ?? [];
    }

    /** Exames personalizados da clínica (todas as situações), mais recentes primeiro. */
    public function listCustomExams(int $clinicId): array
    {
        $st = $this->db->prepare("SELECT c.*, su.name AS submitted_by_name, vu.name AS validated_by_name
            FROM exam_catalog c
            LEFT JOIN users su ON su.id = c.submitted_by_user_id
            LEFT JOIN users vu ON vu.id = c.validated_by_user_id
            WHERE c.clinic_id = ? AND c.origin = 'clinic_custom' ORDER BY c.id DESC");
        $st->execute([$clinicId]);
        return $st->fetchAll(PDO::FETCH_ASSOC);
    }

    public static function isClinicManager(PDO $db, int $userId, int $clinicId): bool
    {
        $st = $db->prepare("SELECT COUNT(*) FROM clinic_members WHERE clinic_id = ? AND user_id = ? AND status = 'active' AND role IN ('owner', 'admin')");
        $st->execute([$clinicId, $userId]);
        return (int)$st->fetchColumn() > 0;
    }

    private static function clean(string $v, int $max): string
    {
        $v = trim(preg_replace('/\s+/u', ' ', $v) ?? '');
        return mb_substr($v, 0, $max, 'UTF-8');
    }

    /**
     * Identifica se a busca coincidiu com um sinônimo e não com o nome canônico principal.
     */
    private function detectMatchedSynonym(string $query, string $canonicalName, string $synonymsStr): ?string
    {
        $qNorm = self::normalizeSearchText($query);
        $nameNorm = self::normalizeSearchText($canonicalName);

        // Se o nome principal já contém o termo pesquisado, não é considerado match secundário por sinônimo
        if (str_contains($nameNorm, $qNorm)) {
            return null;
        }

        // Verifica na lista de sinônimos - 1. Prioriza correspondência exata
        $synList = array_map('trim', explode(',', $synonymsStr));
        foreach ($synList as $syn) {
            if ($syn === '') {
                continue;
            }
            $synNorm = self::normalizeSearchText($syn);
            if ($synNorm === $qNorm) {
                return $syn;
            }
        }

        // 2. Correspondência parcial
        foreach ($synList as $syn) {
            if ($syn === '') {
                continue;
            }
            $synNorm = self::normalizeSearchText($syn);
            if (str_contains($synNorm, $qNorm) || str_contains($qNorm, $synNorm)) {
                return $syn;
            }
        }

        return null;
    }

    /**
     * Sinônimo pelo qual o exame foi achado, vindo do formulário (matched_synonym[examId]). Só é aceito se for,
     * depois da mesma normalização dos dois lados, um dos sinônimos cadastrados do exame; devolve o texto do
     * catálogo (nunca o texto digitado). Qualquer outra coisa vira null.
     */
    public static function resolveMatchedSynonym(array $exam, ?string $claimed): ?string
    {
        $claimedNorm = ExamText::normalize((string)$claimed);
        if ($claimedNorm === '') {
            return null;
        }
        foreach (array_map('trim', explode(',', (string)($exam['synonyms'] ?? ''))) as $syn) {
            if ($syn !== '' && ExamText::normalize($syn) === $claimedNorm) {
                return mb_substr($syn, 0, 255, 'UTF-8');
            }
        }
        return null;
    }

    /** Mesma normalização do resto do módulo (ExamText::normalize), dos dois lados da comparação. */
    public static function normalizeSearchText(string $text): string
    {
        return ExamText::normalize($text);
    }

    /** Texto de busca de um item do catálogo (igual ao gravado em exam_catalog.search_text pela migração 64). */
    public static function buildSearchText(string $name, ?string $short, string $category, ?string $synonyms): string
    {
        return ExamText::normalize("{$name} {$short} {$category} {$synonyms}");
    }
}
