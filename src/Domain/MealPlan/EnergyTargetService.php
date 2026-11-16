<?php

declare(strict_types=1);

namespace ClyoNutri\Domain\MealPlan;

use ClyoNutri\Core\Audit\AuditLogger;
use ClyoNutri\Core\Auth\TenantContext;
use ClyoNutri\Core\Database\Connection;
use ClyoNutri\Core\Security\TenantGuard;
use ClyoNutri\Domain\Anthropometry\SkinfoldService;
use ClyoNutri\Domain\Nutrition\EnergyCalculator;
use InvalidArgumentException;
use PDO;
use RuntimeException;

/**
 * Meta de energia do plano (NI2-514, fase 1): grava TMB, GET, VET, objetivo e metas de macros por plano, com a fórmula
 * e os dados de entrada. Tudo isolado por clinic_id. Só rascunhos são alterados; a versão publicada guarda a cópia
 * dentro do snapshot (getPlanWithDetails inclui 'energy_target').
 */
final class EnergyTargetService
{
    private PDO $db;

    public function __construct(?PDO $db = null)
    {
        $this->db = $db ?? Connection::getInstance();
    }

    /** Meta atual do plano, pronta para tela e para o snapshot; null se ainda não foi calculada. */
    public function forPlan(int $planId): ?array
    {
        $st = $this->db->prepare('SELECT * FROM meal_plan_energy_targets WHERE meal_plan_id = :p AND clinic_id = :c');
        $st->execute(['p' => $planId, 'c' => TenantContext::getClinicId()]);
        $row = $st->fetch(PDO::FETCH_ASSOC);
        if (!$row) {
            return null;
        }
        $meta = EnergyCalculator::FORMULAS[$row['formula']] ?? ['label' => $row['formula'], 'reference' => ''];
        $equation = null;
        try {
            $equation = EnergyCalculator::compute([
                'formula' => $row['formula'], 'sex' => $row['sex'], 'age_years' => $row['age_years'],
                'weight_kg' => $row['weight_kg'], 'height_cm' => $row['height_cm'], 'ffm_kg' => $row['ffm_kg'],
                'activity_factor' => $row['activity_factor'], 'adjust_mode' => $row['adjust_mode'],
                'adjust_value' => $row['adjust_value'], 'goal_type' => $row['goal_type'],
            ])['equation'];
        } catch (\Throwable) {
            $equation = null;
        }
        $num = static fn($v) => $v === null ? null : (float)$v;
        return [
            'formula' => $row['formula'],
            'formula_label' => $meta['label'],
            'formula_reference' => $meta['reference'],
            'formula_version' => $row['formula_version'],
            'equation' => $equation,
            'sex' => $row['sex'],
            'age_years' => $num($row['age_years']),
            'weight_kg' => $num($row['weight_kg']),
            'height_cm' => $num($row['height_cm']),
            'ffm_kg' => $num($row['ffm_kg']),
            'inputs_source' => $row['inputs_source'],
            'activity_factor' => $num($row['activity_factor']),
            'activity_label' => $row['activity_label'],
            'activity_source' => EnergyCalculator::ACTIVITY_SOURCE,
            'goal_type' => $row['goal_type'],
            'goal_label' => EnergyCalculator::GOALS[$row['goal_type']] ?? $row['goal_type'],
            'goal_note' => $row['goal_note'],
            'adjust_mode' => $row['adjust_mode'],
            'adjust_value' => $num($row['adjust_value']),
            'tmb_kcal' => $num($row['tmb_kcal']),
            'get_kcal' => $num($row['get_kcal']),
            'vet_kcal' => $num($row['vet_kcal']),
            'macro_mode' => $row['macro_mode'],
            'protein_g' => $num($row['protein_g']),
            'carbohydrate_g' => $num($row['carbohydrate_g']),
            'lipid_g' => $num($row['lipid_g']),
            'protein_pct' => $num($row['protein_pct']),
            'carbohydrate_pct' => $num($row['carbohydrate_pct']),
            'lipid_pct' => $num($row['lipid_pct']),
            'protein_g_kg' => $num($row['protein_g_kg']),
            'carbohydrate_g_kg' => $num($row['carbohydrate_g_kg']),
            'lipid_g_kg' => $num($row['lipid_g_kg']),
            'warnings' => json_decode((string)($row['warnings_json'] ?? '[]'), true) ?: [],
            'calculated_at' => $row['updated_at'],
        ];
    }

    /**
     * Dados que podem pré-preencher o formulário, cada um com a origem. Nada é gravado aqui.
     *
     * @return array{sex:?string,age_years:?int,weight_kg:?float,height_cm:?float,ffm_kg:?float,sources:array<string,string>,source_text:string}
     */
    public function suggestInputs(int $planId): array
    {
        $out = ['sex' => null, 'age_years' => null, 'weight_kg' => null, 'height_cm' => null, 'ffm_kg' => null, 'sources' => [], 'source_text' => ''];
        $clinicId = TenantContext::getClinicId();
        $plan = TenantGuard::assertMealPlanBelongsToClinic($planId, $clinicId, $this->db);
        if (empty($plan['patient_id'])) {
            return $out;
        }
        $patientId = (int)$plan['patient_id'];
        $p = $this->db->prepare('SELECT gender, birth_date FROM patients WHERE id = :id AND clinic_id = :c');
        $p->execute(['id' => $patientId, 'c' => $clinicId]);
        $patient = $p->fetch(PDO::FETCH_ASSOC) ?: [];
        $sex = SkinfoldService::sexFromPatient($patient['gender'] ?? null);
        if ($sex !== null) {
            $out['sex'] = $sex;
            $out['sources']['sex'] = 'cadastro do paciente';
        }
        $a = $this->db->prepare('SELECT weight_kg, height_cm, body_fat_pct, assessed_at FROM anthropometric_assessments
            WHERE clinic_id = :c AND patient_id = :p ORDER BY assessed_at DESC, id DESC LIMIT 1');
        $a->execute(['c' => $clinicId, 'p' => $patientId]);
        $assess = $a->fetch(PDO::FETCH_ASSOC);
        $when = date('Y-m-d');
        if ($assess) {
            $when = (string)$assess['assessed_at'];
            $label = 'avaliação antropométrica de ' . date('d/m/Y', (int)strtotime($when));
            $out['weight_kg'] = (float)$assess['weight_kg'];
            $out['height_cm'] = (float)$assess['height_cm'];
            $out['sources']['weight_kg'] = $out['sources']['height_cm'] = $label;
            if ($assess['body_fat_pct'] !== null && (float)$assess['body_fat_pct'] > 0 && (float)$assess['body_fat_pct'] < 70) {
                $out['ffm_kg'] = round((float)$assess['weight_kg'] * (1 - (float)$assess['body_fat_pct'] / 100.0), 1);
                $out['sources']['ffm_kg'] = $label . ' (peso x (1 - % de gordura))';
            }
        }
        $age = SkinfoldService::ageAt($patient['birth_date'] ?? null, $when);
        if ($age !== null) {
            $out['age_years'] = $age;
            $out['sources']['age_years'] = 'data de nascimento do cadastro';
        }
        $out['source_text'] = $out['sources'] ? implode('; ', array_unique($out['sources'])) : '';
        return $out;
    }

    /**
     * Calcula, valida e grava a meta do plano (apenas rascunho). Lança InvalidArgumentException com texto para a tela.
     *
     * @param array<string,mixed> $in campos do formulário
     */
    public function save(int $planId, array $in): array
    {
        $clinicId = TenantContext::getClinicId();
        $plan = TenantGuard::assertMealPlanBelongsToClinic($planId, $clinicId, $this->db);
        if (($plan['status'] ?? '') !== 'draft') {
            throw new RuntimeException('Só é possível alterar a meta de um rascunho. Duplique o plano para recalcular.');
        }

        $formula = (string)($in['formula'] ?? '');
        $needsFfm = EnergyCalculator::FORMULAS[$formula]['needs_ffm'] ?? false;
        $sex = (string)($in['sex'] ?? '');
        $sexVal = in_array($sex, ['male', 'female'], true) ? $sex : null;
        $goal = (string)($in['goal_type'] ?? 'maintenance');
        if (!isset(EnergyCalculator::GOALS[$goal])) {
            throw new InvalidArgumentException('Escolha o objetivo do plano.');
        }
        $factorRaw = ($in['activity_factor'] ?? '') !== '' ? $in['activity_factor'] : ($in['activity_preset'] ?? '');
        $adjMode = (string)($in['adjust_mode'] ?? 'kcal');
        // Reteste UX v54.62 (R-006): "Redução de peso" ou "Ganho" com ajuste 0 gravava VET = GET com o rótulo do objetivo.
        // O sistema não inventa o déficit/superávit; pede que o nutricionista informe o ajuste no sentido do objetivo.
        $adjRaw = trim(str_replace(',', '.', (string)($in['adjust_value'] ?? '0')));
        $adjNum = is_numeric($adjRaw) ? (float)$adjRaw : 0.0;
        if ($goal === 'loss' && $adjNum >= 0) {
            throw new InvalidArgumentException('Para redução de peso, informe o ajuste do GET com valor negativo (em kcal ou % do GET). Com ajuste 0 o VET fica igual ao GET.');
        }
        if ($goal === 'gain' && $adjNum <= 0) {
            throw new InvalidArgumentException('Para ganho de peso ou de massa, informe o ajuste do GET com valor positivo (em kcal ou % do GET). Com ajuste 0 o VET fica igual ao GET.');
        }

        $suggest = $this->suggestInputs($planId);
        $ageYears = ($in['age_years'] ?? '') !== '' ? $in['age_years'] : ($suggest['age_years'] ?? null);

        $core = [
            'formula' => $formula, 'sex' => $sexVal, 'age_years' => $ageYears,
            'weight_kg' => $in['weight_kg'] ?? null, 'height_cm' => $in['height_cm'] ?? null,
            'ffm_kg' => $in['ffm_kg'] ?? null, 'activity_factor' => $factorRaw,
            'adjust_mode' => $adjMode, 'adjust_value' => $in['adjust_value'] ?? 0, 'goal_type' => $goal,
            'goal_note' => $in['goal_note'] ?? null,
        ];
        $calc = EnergyCalculator::compute($core);
        $weight = (float)str_replace(',', '.', (string)$core['weight_kg']);
        $macroMode = (string)($in['macro_mode'] ?? '');
        $macros = EnergyCalculator::macros($calc['vet_kcal'], $macroMode, [
            'protein' => $in['protein'] ?? null, 'carbohydrate' => $in['carbohydrate'] ?? null, 'lipid' => $in['lipid'] ?? null,
        ], $weight);

        $dec = static fn($v) => $v === null || $v === '' ? null : (float)str_replace(',', '.', (string)$v);
        $normHeight = $dec($core['height_cm']);
        if ($normHeight !== null && $normHeight > 0.0 && $normHeight <= 3.0) {
            $normHeight = round($normHeight * 100.0, 1);
        }
        $factor = (float)str_replace(',', '.', (string)$factorRaw);
        $presetKey = number_format($factor, 2, '.', '');
        $activityLabel = EnergyCalculator::ACTIVITY_PRESETS[$presetKey] ?? ('Fator informado pelo profissional (' . number_format($factor, 2, ',', '') . ')');
        $same = $suggest['source_text'] !== ''
            && ($suggest['weight_kg'] === null || abs((float)$suggest['weight_kg'] - $weight) < 0.005)
            && ($suggest['height_cm'] === null || $needsFfm || abs((float)$suggest['height_cm'] - (float)$normHeight) < 0.05)
            && ($suggest['age_years'] === null || $needsFfm || (int)$suggest['age_years'] === (int)$dec($core['age_years']))
            && ($suggest['sex'] === null || $needsFfm || $suggest['sex'] === $sexVal);
        $source = $same
            ? 'Pré-preenchido de: ' . $suggest['source_text']
            : 'Valores informados ou ajustados pelo profissional' . ($suggest['source_text'] !== '' ? ' (havia sugestão de: ' . $suggest['source_text'] . ')' : '');

        $goalNote = trim((string)($in['goal_note'] ?? ''));
        if (mb_strlen($goalNote) > 255) {
            throw new InvalidArgumentException('A observação do objetivo pode ter até 255 caracteres.');
        }
        $warnings = array_values(array_unique(array_merge($calc['warnings'], $macros['warnings'])));
        $userId = TenantContext::getUserId();

        $params = [
            'formula' => $formula, 'fv' => EnergyCalculator::FORMULA_VERSION, 'sex' => $sexVal,
            'age' => $needsFfm ? null : $dec($core['age_years']), 'w' => $weight,
            'h' => $needsFfm ? null : $normHeight, 'ffm' => $needsFfm ? $dec($core['ffm_kg']) : null,
            'src' => mb_substr($source, 0, 255), 'af' => $factor, 'al' => mb_substr($activityLabel, 0, 80),
            'goal' => $goal, 'gn' => $goalNote !== '' ? $goalNote : null, 'am' => $adjMode,
            'av' => $dec($core['adjust_value']) ?? 0.0,
            'tmb' => $calc['tmb_kcal'], 'get' => $calc['get_kcal'], 'vet' => $calc['vet_kcal'], 'mm' => $macroMode,
            'pg' => $macros['protein_g'], 'cg' => $macros['carbohydrate_g'], 'lg' => $macros['lipid_g'],
            'pp' => $macros['protein_pct'], 'cp' => $macros['carbohydrate_pct'], 'lp' => $macros['lipid_pct'],
            'pk' => $macros['protein_g_kg'], 'ck' => $macros['carbohydrate_g_kg'], 'lk' => $macros['lipid_g_kg'],
            'warn' => json_encode($warnings, JSON_UNESCAPED_UNICODE), 'uid' => $userId,
        ];

        $exists = $this->db->prepare('SELECT id FROM meal_plan_energy_targets WHERE meal_plan_id = :p AND clinic_id = :c');
        $exists->execute(['p' => $planId, 'c' => $clinicId]);
        if ($exists->fetchColumn()) {
            $sql = 'UPDATE meal_plan_energy_targets SET patient_id = :pat, formula = :formula, formula_version = :fv, sex = :sex,
                age_years = :age, weight_kg = :w, height_cm = :h, ffm_kg = :ffm, inputs_source = :src, activity_factor = :af,
                activity_label = :al, goal_type = :goal, goal_note = :gn, adjust_mode = :am, adjust_value = :av,
                tmb_kcal = :tmb, get_kcal = :get, vet_kcal = :vet, macro_mode = :mm, protein_g = :pg, carbohydrate_g = :cg,
                lipid_g = :lg, protein_pct = :pp, carbohydrate_pct = :cp, lipid_pct = :lp, protein_g_kg = :pk,
                carbohydrate_g_kg = :ck, lipid_g_kg = :lk, warnings_json = :warn, calculated_by_user_id = :uid,
                updated_at = CURRENT_TIMESTAMP WHERE meal_plan_id = :p AND clinic_id = :c';
        } else {
            $sql = 'INSERT INTO meal_plan_energy_targets (clinic_id, meal_plan_id, patient_id, formula, formula_version, sex,
                age_years, weight_kg, height_cm, ffm_kg, inputs_source, activity_factor, activity_label, goal_type, goal_note,
                adjust_mode, adjust_value, tmb_kcal, get_kcal, vet_kcal, macro_mode, protein_g, carbohydrate_g, lipid_g,
                protein_pct, carbohydrate_pct, lipid_pct, protein_g_kg, carbohydrate_g_kg, lipid_g_kg, warnings_json,
                calculated_by_user_id) VALUES (:c, :p, :pat, :formula, :fv, :sex, :age, :w, :h, :ffm, :src, :af, :al, :goal,
                :gn, :am, :av, :tmb, :get, :vet, :mm, :pg, :cg, :lg, :pp, :cp, :lp, :pk, :ck, :lk, :warn, :uid)';
        }
        $params['c'] = $clinicId;
        $params['p'] = $planId;
        $params['pat'] = !empty($plan['patient_id']) ? (int)$plan['patient_id'] : null;
        $this->db->prepare($sql)->execute($params);

        (new AuditLogger($this->db))->log('MEAL_PLAN_ENERGY_TARGET_SAVED', 'meal_plans', (string)$planId, [
            'formula' => $formula, 'formula_version' => EnergyCalculator::FORMULA_VERSION,
            'activity_factor' => $factor, 'goal_type' => $goal, 'macro_mode' => $macroMode,
            'tmb_kcal' => $calc['tmb_kcal'], 'get_kcal' => $calc['get_kcal'], 'vet_kcal' => $calc['vet_kcal'],
            'inputs_prefilled' => $same, 'warnings' => count($warnings),
        ]);

        return $this->forPlan($planId) ?? [];
    }

    /** Copia a meta de um plano para a cópia (duplicar). Sem meta de origem, não faz nada. */
    public function copyTarget(int $fromPlanId, int $toPlanId): void
    {
        $clinicId = TenantContext::getClinicId();
        TenantGuard::assertMealPlanBelongsToClinic($fromPlanId, $clinicId, $this->db);
        $to = TenantGuard::assertMealPlanBelongsToClinic($toPlanId, $clinicId, $this->db);
        $st = $this->db->prepare('SELECT * FROM meal_plan_energy_targets WHERE meal_plan_id = :p AND clinic_id = :c');
        $st->execute(['p' => $fromPlanId, 'c' => $clinicId]);
        $row = $st->fetch(PDO::FETCH_ASSOC);
        if (!$row) {
            return;
        }
        unset($row['id']);
        $row['meal_plan_id'] = $toPlanId;
        $row['patient_id'] = !empty($to['patient_id']) ? (int)$to['patient_id'] : null;
        $row['inputs_source'] = mb_substr('Copiado do plano #' . $fromPlanId . ' (' . ($row['inputs_source'] ?? '') . ')', 0, 255);
        $row['created_at'] = $row['updated_at'] = date('Y-m-d H:i:s');
        $cols = array_keys($row);
        $this->db->prepare('INSERT INTO meal_plan_energy_targets (' . implode(',', $cols) . ') VALUES (:' . implode(', :', $cols) . ')')->execute($row);
    }

    /** Vincula o paciente recém-atribuído à meta já calculada. */
    public function attachPatient(int $planId, int $patientId): void
    {
        $this->db->prepare('UPDATE meal_plan_energy_targets SET patient_id = :pat WHERE meal_plan_id = :p AND clinic_id = :c AND patient_id IS NULL')
            ->execute(['pat' => $patientId, 'p' => $planId, 'c' => TenantContext::getClinicId()]);
    }

    /**
     * Compara os totais do plano com a meta. Null quando não há meta.
     *
     * @param array<string,mixed> $plan resultado de getPlanWithDetails
     */
    public function compare(array $plan): ?array
    {
        $t = $plan['energy_target'] ?? null;
        if (!$t) {
            return null;
        }
        return EnergyCalculator::comparePlan($t, [
            'energy_kcal' => $plan['daily_energy_kcal'] ?? 0, 'protein_g' => $plan['protein_g'] ?? 0,
            'carbohydrate_g' => $plan['carbohydrate_g'] ?? 0, 'lipid_g' => $plan['lipid_g'] ?? 0,
        ]);
    }
}
