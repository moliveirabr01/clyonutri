<?php

declare(strict_types=1);

namespace ClyoNutri\Domain\Anamnesis;

use ClyoNutri\Core\Database\SchemaInfo;
use ClyoNutri\Core\Security\ClinicalEncryptionService;
use PDO;

/**
 * NI2-605 (UX-R3-04): qual versão da anamnese vale.
 *
 *  - "concluída": a versão mais recente com is_draft = 0. É ela que alimenta o resumo clínico, o selo do perfil,
 *    a origem do plano e a revisão de publicação.
 *  - "em andamento": um rascunho MAIS NOVO que a concluída. Aparece como "Alteração em andamento (vN, salva em …)"
 *    e não rebaixa a concluída.
 *  - Sem nenhuma concluída, o rascunho é a única fonte (estado "pendente", como antes).
 *
 * Segurança (trava de alérgenos): o que o rascunho acrescentar de alergia/restrição SOMA ao da concluída
 * (mergeForSafety). Um rascunho nunca tira uma alergia já registrada, e uma alergia digitada no rascunho já
 * protege antes da conclusão.
 *
 * Sem a coluna is_draft (migração 16 pendente), toda versão conta como concluída.
 */
final class AnamnesisVersions
{
    /**
     * @param string $select colunas (ex.: "a.*, u.name AS professional_name"); a tabela tem alias "a"
     * @param string $join   JOINs opcionais
     * @return array{concluded: ?array, draft: ?array}
     */
    public static function pick(PDO $db, int $clinicId, int $patientId, string $select = 'a.*', string $join = ''): array
    {
        $hasDraft = SchemaInfo::hasColumn($db, 'anamneses', 'is_draft');
        $base = "SELECT {$select} FROM anamneses a {$join} WHERE a.clinic_id = :c AND a.patient_id = :p";
        if (!$hasDraft) {
            $st = $db->prepare($base . ' ORDER BY a.version DESC, a.id DESC LIMIT 1');
            $st->execute(['c' => $clinicId, 'p' => $patientId]);
            $row = $st->fetch(PDO::FETCH_ASSOC) ?: null;
            return ['concluded' => ClinicalEncryptionService::decryptAnamnesisRow($row), 'draft' => null];
        }
        $st = $db->prepare($base . ' AND (a.is_draft = 0 OR a.is_draft IS NULL) ORDER BY a.version DESC, a.id DESC LIMIT 1');
        $st->execute(['c' => $clinicId, 'p' => $patientId]);
        $concluded = ClinicalEncryptionService::decryptAnamnesisRow($st->fetch(PDO::FETCH_ASSOC) ?: null);
        $st = $db->prepare($base . ' AND a.is_draft = 1 ORDER BY a.version DESC, a.id DESC LIMIT 1');
        $st->execute(['c' => $clinicId, 'p' => $patientId]);
        $draft = ClinicalEncryptionService::decryptAnamnesisRow($st->fetch(PDO::FETCH_ASSOC) ?: null);
        if ($draft !== null && $concluded !== null && (int)$draft['version'] < (int)$concluded['version']) {
            $draft = null; // rascunho antigo, já superado por uma versão concluída
        }
        return ['concluded' => $concluded, 'draft' => $draft];
    }

    /**
     * Versão que vale para exibição: a concluída; sem ela, o rascunho.
     * @param array{concluded: ?array, draft: ?array} $v
     */
    public static function effective(array $v): ?array
    {
        return $v['concluded'] ?? $v['draft'];
    }

    /**
     * Para a trava de alérgenos: a concluída + o que o rascunho mais novo acrescentar (nunca menos).
     * @param array{concluded: ?array, draft: ?array} $v
     * @return array{row: ?array, draft_added: bool} row com allergies/restrictions/dietary_restrictions/allergy_assessment
     */
    public static function mergeForSafety(array $v): array
    {
        $c = $v['concluded'];
        $d = $v['draft'];
        if ($c === null) {
            return ['row' => $d, 'draft_added' => false];
        }
        if ($d === null) {
            return ['row' => $c, 'draft_added' => false];
        }
        $row = $c;
        $added = false;
        foreach (['allergies', 'restrictions', 'dietary_restrictions'] as $f) {
            $cv = trim((string)($c[$f] ?? ''));
            $dv = trim((string)($d[$f] ?? ''));
            if ($dv === '' || self::isDenial($dv) || mb_strtolower($dv, 'UTF-8') === mb_strtolower($cv, 'UTF-8')) {
                continue;
            }
            $row[$f] = $cv === '' || self::isDenial($cv) ? $dv : $cv . '; ' . $dv;
            $added = true;
            if ($f === 'allergies' && ($row['allergy_assessment'] ?? '') === 'denied') {
                $row['allergy_assessment'] = 'recorded'; // o rascunho registrou alergia: a negativa anterior não vale mais
            }
        }
        return ['row' => $row, 'draft_added' => $added];
    }

    /** "Alteração em andamento (v2, salva em 02/11 14:30)". */
    public static function draftLabel(array $draft): string
    {
        $t = strtotime((string)($draft['created_at'] ?? ''));
        return 'Alteração em andamento (v' . (int)$draft['version'] . ($t ? ', salva em ' . date('d/m H:i', $t) : '') . ')';
    }

    private static function isDenial(string $text): bool
    {
        $t = mb_strtolower(trim($text), 'UTF-8');
        foreach (\ClyoNutri\Domain\Clinical\AllergenSafetyValidator::DENIED_TERMS as $dt) {
            if ($t === $dt || str_starts_with($t, $dt . ' ') || str_starts_with($t, $dt . '.') || str_starts_with($t, $dt . ',')) {
                return true;
            }
        }
        return false;
    }
}
