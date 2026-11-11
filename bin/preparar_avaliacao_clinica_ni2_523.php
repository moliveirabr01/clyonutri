<?php

declare(strict_types=1);

/**
 * NI2-523 — Preparação do ambiente isolado de avaliação clínica e usabilidade ("Clínica TESTE Avaliação").
 *
 * Cria ou atualiza (idempotente):
 *  - Clínica isolada: "Clínica TESTE Avaliação" (uuid: clinica-teste-ni2-523)
 *  - 5 nutricionistas avaliadores (P1 a P5, incluindo perfis ex-WebDiet) + 1 perfil de recepção para o cenário C11
 *  - 5 pacientes TESTE com antropometria e anamnese finalizada:
 *      1. TESTE Adulto Redução de Peso (34 anos, masculino, 88 kg, 176 cm)
 *      2. TESTE Adulto Alergia a Amendoim (29 anos, feminino, 64 kg, 165 cm, alergia a amendoim)
 *      3. TESTE Gestante 2º Trimestre (28 anos, feminino, 68 kg, 163 cm, gestante 2º trimestre)
 *      4. TESTE Idoso 72 Anos (72 anos, masculino, 71 kg, 170 cm)
 *      5. TESTE Adolescente Atleta 15 Anos (15 anos, masculino, 62 kg, 174 cm, % gordura 12,9% -> MLG 54 kg)
 *
 * Uso:
 *   php bin/preparar_avaliacao_clinica_ni2_523.php --dry-run
 *   php bin/preparar_avaliacao_clinica_ni2_523.php --apply
 */

require_once __DIR__ . '/../vendor/autoload.php';

use ClyoNutri\Core\Database\Connection;

final class EvaluationEnvironmentSeeder
{
    public const CLINIC_UUID = 'clinica-teste-ni2-523';
    public const CLINIC_NAME = 'Clínica TESTE Avaliação';

    public const EVALUATORS = [
        ['code' => 'P1', 'uuid' => 'u-ni2-523-p1', 'name' => 'Avaliador P1 (Consultório Individual · Ex-WebDiet)', 'email' => 'p1.teste@avaliacao.clyonutri.local', 'crn' => '52301', 'crn_region' => 'CRN-3', 'specialty' => 'Nutrição Clínica'],
        ['code' => 'P2', 'uuid' => 'u-ni2-523-p2', 'name' => 'Avaliador P2 (Clínica Multiprofissional · Ex-WebDiet)', 'email' => 'p2.teste@avaliacao.clyonutri.local', 'crn' => '52302', 'crn_region' => 'CRN-3', 'specialty' => 'Emagrecimento e Metabologia'],
        ['code' => 'P3', 'uuid' => 'u-ni2-523-p3', 'name' => 'Avaliador P3 (Nutrição Esportiva)', 'email' => 'p3.teste@avaliacao.clyonutri.local', 'crn' => '52303', 'crn_region' => 'CRN-4', 'specialty' => 'Nutrição Esportiva'],
        ['code' => 'P4', 'uuid' => 'u-ni2-523-p4', 'name' => 'Avaliador P4 (Materno-Infantil)', 'email' => 'p4.teste@avaliacao.clyonutri.local', 'crn' => '52304', 'crn_region' => 'CRN-2', 'specialty' => 'Materno-Infantil'],
        ['code' => 'P5', 'uuid' => 'u-ni2-523-p5', 'name' => 'Avaliador P5 (Gerontologia e Clínica)', 'email' => 'p5.teste@avaliacao.clyonutri.local', 'crn' => '52305', 'crn_region' => 'CRN-1', 'specialty' => 'Gerontologia'],
    ];

    public const RECEPTIONIST = [
        'uuid' => 'u-ni2-523-recepcao',
        'name' => 'Recepção TESTE Avaliação',
        'email' => 'recepcao.teste@avaliacao.clyonutri.local',
    ];

    /**
     * @return array<int, array<string, mixed>>
     */
    public static function patientsSpec(): array
    {
        $year = (int)date('Y');
        return [
            [
                'uuid' => 'p-ni2-523-adulto-perda',
                'name' => 'TESTE Adulto Redução de Peso',
                'email' => 'adulto.perda.teste@avaliacao.clyonutri.local',
                'phone' => '(11) 99000-5231',
                'birth_date' => ($year - 34) . '-03-15',
                'gender' => 'Masculino',
                'notes' => 'Paciente fictício (NI2-523 C1): adulto com objetivo de redução de peso.',
                'weight_kg' => 88.0,
                'height_cm' => 176.0,
                'body_fat_pct' => 26.0,
                'allergies' => '',
                'clinical_history' => 'Sobrepeso sem comorbidades graves. Objetivo: redução ponderal gradual.',
            ],
            [
                'uuid' => 'p-ni2-523-alergia-amendoim',
                'name' => 'TESTE Adulto Alergia a Amendoim',
                'email' => 'alergia.amendoim.teste@avaliacao.clyonutri.local',
                'phone' => '(11) 99000-5232',
                'birth_date' => ($year - 29) . '-06-20',
                'gender' => 'Feminino',
                'notes' => 'Paciente fictício (NI2-523 C3): alergia alimentar grave a amendoim.',
                'weight_kg' => 64.0,
                'height_cm' => 165.0,
                'body_fat_pct' => 23.0,
                'allergies' => 'Alergia confirmada a amendoim e pasta de amendoim.',
                'clinical_history' => 'Reação urticariforme prévia ao consumo de amendoim.',
            ],
            [
                'uuid' => 'p-ni2-523-gestante',
                'name' => 'TESTE Gestante 2º Trimestre',
                'email' => 'gestante.teste@avaliacao.clyonutri.local',
                'phone' => '(11) 99000-5233',
                'birth_date' => ($year - 28) . '-04-10',
                'gender' => 'Feminino',
                'notes' => 'Paciente fictício (NI2-523 C2): gestante no 2º trimestre (20 semanas). Peso pré-gestacional: 63 kg.',
                'weight_kg' => 68.0,
                'height_cm' => 163.0,
                'body_fat_pct' => null,
                'allergies' => '',
                'clinical_history' => 'Gestante no 2º trimestre (20 semanas), gestação única tópica sem intercorrências.',
            ],
            [
                'uuid' => 'p-ni2-523-idoso',
                'name' => 'TESTE Idoso 72 Anos',
                'email' => 'idoso.teste@avaliacao.clyonutri.local',
                'phone' => '(11) 99000-5234',
                'birth_date' => ($year - 72) . '-01-10',
                'gender' => 'Masculino',
                'notes' => 'Paciente fictício (NI2-523 C6): idoso de 72 anos para preservação de massa muscular.',
                'weight_kg' => 71.0,
                'height_cm' => 170.0,
                'body_fat_pct' => 24.0,
                'allergies' => '',
                'clinical_history' => 'Idoso de 72 anos funcionalmente independente; foco em aporte proteico e prevenção de sarcopenia.',
            ],
            [
                'uuid' => 'p-ni2-523-adolescente-atleta',
                'name' => 'TESTE Adolescente Atleta 15 Anos',
                'email' => 'adolescente.atleta.teste@avaliacao.clyonutri.local',
                'phone' => '(11) 99000-5235',
                'birth_date' => ($year - 15) . '-02-10',
                'gender' => 'Masculino',
                'notes' => 'Paciente fictício (NI2-523): adolescente atleta de natação (15 anos).',
                'weight_kg' => 62.0,
                'height_cm' => 174.0,
                'body_fat_pct' => 12.9, // MLG = 62 * (1 - 0.129) = 54.0 kg
                'allergies' => '',
                'clinical_history' => 'Adolescente atleta (15 anos), treino de natação 5x/semana.',
            ],
        ];
    }

    /**
     * @return array{clinic_id:int, evaluators:array<string,array{user_id:int,professional_id:int}>, receptionist_user_id:int, patients:array<string,int>}
     */
    public static function seed(PDO $db, string $passwordPlain = 'Teste@NI2523!'): array
    {
        $own = !$db->inTransaction();
        if ($own) {
            $db->beginTransaction();
        }
        try {
            $stClinic = $db->prepare('SELECT id FROM clinics WHERE uuid = ?');
            $stClinic->execute([self::CLINIC_UUID]);
            $clinicId = (int)($stClinic->fetchColumn() ?: 0);
            if ($clinicId === 0) {
                $db->prepare("INSERT INTO clinics (uuid, name, status) VALUES (?, ?, 'active')")
                    ->execute([self::CLINIC_UUID, self::CLINIC_NAME]);
                $clinicId = (int)$db->lastInsertId();
            }

            $hash = password_hash($passwordPlain, PASSWORD_BCRYPT);
            $evaluators = [];
            foreach (self::EVALUATORS as $i => $ev) {
                $stU = $db->prepare('SELECT id FROM users WHERE email = ?');
                $stU->execute([$ev['email']]);
                $userId = (int)($stU->fetchColumn() ?: 0);
                if ($userId === 0) {
                    $db->prepare("INSERT INTO users (uuid, name, email, password_hash, role, is_verified, status) VALUES (?, ?, ?, ?, 'nutritionist', 1, 'active')")
                        ->execute([$ev['uuid'], $ev['name'], $ev['email'], $hash]);
                    $userId = (int)$db->lastInsertId();
                }

                $stMem = $db->prepare('SELECT id FROM clinic_members WHERE clinic_id = ? AND user_id = ?');
                $stMem->execute([$clinicId, $userId]);
                if (!$stMem->fetchColumn()) {
                    $memberRole = $i === 0 ? 'owner' : 'nutritionist';
                    $db->prepare("INSERT INTO clinic_members (clinic_id, user_id, role, status) VALUES (?, ?, ?, 'active')")
                        ->execute([$clinicId, $userId, $memberRole]);
                }

                $stProf = $db->prepare('SELECT id FROM professionals WHERE user_id = ?');
                $stProf->execute([$userId]);
                $profId = (int)($stProf->fetchColumn() ?: 0);
                if ($profId === 0) {
                    $db->prepare('INSERT INTO professionals (user_id, crn, crn_region, specialty) VALUES (?, ?, ?, ?)')
                        ->execute([$userId, $ev['crn'], $ev['crn_region'], $ev['specialty']]);
                    $profId = (int)$db->lastInsertId();
                }
                $evaluators[$ev['code']] = ['user_id' => $userId, 'professional_id' => $profId];
            }

            // Recepcionista (para o cenário C11 de bloqueio de acesso a planos)
            $stRec = $db->prepare('SELECT id FROM users WHERE email = ?');
            $stRec->execute([self::RECEPTIONIST['email']]);
            $recUserId = (int)($stRec->fetchColumn() ?: 0);
            if ($recUserId === 0) {
                $db->prepare("INSERT INTO users (uuid, name, email, password_hash, role, is_verified, status) VALUES (?, ?, ?, ?, 'receptionist', 1, 'active')")
                    ->execute([self::RECEPTIONIST['uuid'], self::RECEPTIONIST['name'], self::RECEPTIONIST['email'], $hash]);
                $recUserId = (int)$db->lastInsertId();
            }
            $stRecMem = $db->prepare('SELECT id FROM clinic_members WHERE clinic_id = ? AND user_id = ?');
            $stRecMem->execute([$clinicId, $recUserId]);
            if (!$stRecMem->fetchColumn()) {
                $db->prepare("INSERT INTO clinic_members (clinic_id, user_id, role, status) VALUES (?, ?, 'receptionist', 'active')")
                    ->execute([$clinicId, $recUserId]);
            }

            $leadProfId = $evaluators['P1']['professional_id'];
            $patients = [];
            foreach (self::patientsSpec() as $p) {
                $stP = $db->prepare('SELECT id FROM patients WHERE uuid = ?');
                $stP->execute([$p['uuid']]);
                $pid = (int)($stP->fetchColumn() ?: 0);
                if ($pid === 0) {
                    $db->prepare("INSERT INTO patients (clinic_id, uuid, name, email, phone, birth_date, gender, status, notes)
                        VALUES (?, ?, ?, ?, ?, ?, ?, 'active', ?)")
                        ->execute([$clinicId, $p['uuid'], $p['name'], $p['email'], $p['phone'], $p['birth_date'], $p['gender'], $p['notes']]);
                    $pid = (int)$db->lastInsertId();
                }

                $stA = $db->prepare('SELECT id FROM anthropometric_assessments WHERE clinic_id = ? AND patient_id = ?');
                $stA->execute([$clinicId, $pid]);
                if (!$stA->fetchColumn()) {
                    $hMeters = $p['height_cm'] / 100.0;
                    $bmi = round($p['weight_kg'] / ($hMeters * $hMeters), 2);
                    $db->prepare('INSERT INTO anthropometric_assessments (clinic_id, patient_id, professional_id, weight_kg, height_cm, bmi, body_fat_pct, assessed_at)
                        VALUES (?, ?, ?, ?, ?, ?, ?, ?)')
                        ->execute([$clinicId, $pid, $leadProfId, $p['weight_kg'], $p['height_cm'], $bmi, $p['body_fat_pct'], date('Y-m-d 09:00:00')]);
                }

                $stAn = $db->prepare('SELECT id FROM anamneses WHERE clinic_id = ? AND patient_id = ?');
                $stAn->execute([$clinicId, $pid]);
                if (!$stAn->fetchColumn()) {
                    $db->prepare('INSERT INTO anamneses (clinic_id, patient_id, professional_id, version, allergies, clinical_history)
                        VALUES (?, ?, ?, 1, ?, ?)')
                        ->execute([$clinicId, $pid, $leadProfId, $p['allergies'], $p['clinical_history']]);
                }

                $patients[$p['uuid']] = $pid;
            }

            if ($own) {
                $db->commit();
            }
            return [
                'clinic_id' => $clinicId,
                'evaluators' => $evaluators,
                'receptionist_user_id' => $recUserId,
                'patients' => $patients,
            ];
        } catch (\Throwable $e) {
            if ($own && $db->inTransaction()) {
                $db->rollBack();
            }
            throw $e;
        }
    }
}

if (PHP_SAPI === 'cli' && realpath($_SERVER['SCRIPT_FILENAME'] ?? '') === __FILE__) {
    $apply = in_array('--apply', $argv, true);
    if (!$apply) {
        echo "NI2-523 — Modo simulação (--dry-run).\n";
        echo "Serão provisionados na clínica '" . EvaluationEnvironmentSeeder::CLINIC_NAME . "':\n";
        echo "  - 5 nutricionistas avaliadores (P1..P5) + 1 recepcionista\n";
        echo "  - 5 pacientes TESTE (" . implode(', ', array_column(EvaluationEnvironmentSeeder::patientsSpec(), 'name')) . ")\n";
        echo "Para aplicar no banco configurado, execute com --apply.\n";
        exit(0);
    }
    $db = Connection::getInstance();
    $res = EvaluationEnvironmentSeeder::seed($db);
    echo "Ambiente NI2-523 provisionado com sucesso (clinic_id={$res['clinic_id']}, " . count($res['patients']) . " pacientes TESTE).\n";
}
