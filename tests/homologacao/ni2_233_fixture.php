<?php
/**
 * NI2-233: contas e dados TESTE da homologação de anamnese e prontuário.
 * SQL portável (SQLite e MySQL 5.7). Reaproveita o que já existe (seed base do portão) e cria o que falta.
 * Nunca roda contra banco cujo nome não contenha "test" (MySQL) — quem chama garante isso.
 * Todos os e-mails terminam em @teste.invalid e todos os nomes contêm "TESTE".
 */
declare(strict_types=1);

const NI2_233_PASSWORD = 'Teste-Senha-123';

function ni2_233_fixture(PDO $pdo): array
{
    $one = static function (string $sql, array $args = []) use ($pdo) {
        $st = $pdo->prepare($sql);
        $st->execute($args);
        return $st->fetchColumn();
    };
    $now = date('Y-m-d H:i:s');
    $hash = password_hash(NI2_233_PASSWORD, PASSWORD_DEFAULT);

    $clinic = static function (string $name) use ($pdo, $one): int {
        $id = $one('SELECT id FROM clinics WHERE name = ? ORDER BY id LIMIT 1', [$name]);
        if ($id) return (int)$id;
        $pdo->prepare("INSERT INTO clinics (uuid, name, status) VALUES (?, ?, 'active')")->execute([bin2hex(random_bytes(8)), $name]);
        return (int)$pdo->lastInsertId();
    };
    /** Garante usuário (por e-mail, ou o dono já existente da clínica), vínculo e perfil profissional. */
    $user = static function (int $clinicId, string $name, string $email, string $userRole, string $memberRole, ?string $crn, ?int $existingUserId = null) use ($pdo, $one, $hash): array {
        $uid = $existingUserId ?: (int)$one('SELECT id FROM users WHERE email = ?', [$email]);
        if ($uid) {
            $pdo->prepare("UPDATE users SET name = ?, email = ?, password_hash = ?, role = ?, status = 'active', is_verified = 1 WHERE id = ?")
                ->execute([$name, $email, $hash, $userRole, $uid]);
        } else {
            $pdo->prepare("INSERT INTO users (uuid, name, email, password_hash, role, is_verified, status) VALUES (?, ?, ?, ?, ?, 1, 'active')")
                ->execute([bin2hex(random_bytes(8)), $name, $email, $hash, $userRole]);
            $uid = (int)$pdo->lastInsertId();
        }
        if (!$one('SELECT id FROM clinic_members WHERE clinic_id = ? AND user_id = ?', [$clinicId, $uid])) {
            $pdo->prepare("INSERT INTO clinic_members (clinic_id, user_id, role, status) VALUES (?, ?, ?, 'active')")->execute([$clinicId, $uid, $memberRole]);
        }
        $pid = $one('SELECT id FROM professionals WHERE user_id = ?', [$uid]);
        if (!$pid && $crn !== null) {
            $pdo->prepare('INSERT INTO professionals (user_id, crn, crn_region) VALUES (?, ?, ?)')->execute([$uid, $crn, 'CRN-3']);
            $pid = $pdo->lastInsertId();
        }
        return ['user_id' => $uid, 'professional_id' => $pid ? (int)$pid : null, 'clinic_id' => $clinicId, 'email' => $email];
    };
    $patient = static function (int $clinicId, string $name) use ($pdo, $one): int {
        $id = $one('SELECT id FROM patients WHERE clinic_id = ? AND name = ?', [$clinicId, $name]);
        if ($id) return (int)$id;
        $pdo->prepare("INSERT INTO patients (clinic_id, uuid, name, birth_date, gender, status) VALUES (?, ?, ?, '1990-05-10', 'F', 'active')")
            ->execute([$clinicId, bin2hex(random_bytes(8)), $name]);
        return (int)$pdo->lastInsertId();
    };

    $cA = $clinic('Clínica TESTE A');
    $cB = $clinic('Clínica TESTE B');
    $ownerA = (int)$one("SELECT user_id FROM clinic_members WHERE clinic_id = ? AND role = 'owner' ORDER BY id LIMIT 1", [$cA]) ?: null;
    $ownerB = (int)$one("SELECT user_id FROM clinic_members WHERE clinic_id = ? AND role = 'owner' ORDER BY id LIMIT 1", [$cB]) ?: null;
    $a = $user($cA, 'Nutri TESTE A', 'nutri.homolog.a@teste.invalid', 'nutritionist', 'owner', '23301', $ownerA);
    $a2 = $user($cA, 'Nutri TESTE A2', 'nutri.homolog.a2@teste.invalid', 'nutritionist', 'nutritionist', '23302');
    $rec = $user($cA, 'Recepção TESTE A', 'recepcao.homolog.a@teste.invalid', 'receptionist', 'receptionist', null);
    $adm = $user($cA, 'Gestora TESTE A sem CRN', 'gestora.semcrn.a@teste.invalid', 'nutritionist', 'admin', null);
    $b = $user($cB, 'Nutri TESTE B', 'nutri.homolog.b@teste.invalid', 'nutritionist', 'owner', '23303', $ownerB);
    $patientB = $patient($cB, 'Paciente TESTE Clinica B');

    foreach ([[$cA, $a], [$cA, $a2], [$cB, $b]] as [$cid, $u]) {
        if (!$one('SELECT id FROM onboarding_progress WHERE clinic_id = ? AND user_id = ?', [$cid, $u['user_id']])) {
            $pdo->prepare('INSERT INTO onboarding_progress (clinic_id, user_id, current_step, is_completed, practice_preferences, created_at, updated_at) VALUES (?, ?, 5, 1, ?, ?, ?)')
                ->execute([$cid, $u['user_id'], json_encode(['working_days' => ['seg', 'ter', 'qua', 'qui', 'sex'], 'default_duration_minutes' => 60]), $now, $now]);
        }
    }
    foreach ([$cA, $cB] as $cid) {
        if (!$one('SELECT id FROM booking_settings WHERE clinic_id = ?', [$cid])) {
            $pdo->prepare('INSERT INTO booking_settings (clinic_id, is_enabled, allow_presential, allow_telehealth) VALUES (?, 1, 1, 1)')->execute([$cid]);
        }
    }
    $food = static function (string $name, float $kcal, float $p, float $c, float $l) use ($pdo, $one): int {
        $id = $one('SELECT id FROM foods WHERE name = ? AND clinic_id IS NULL', [$name]);
        if ($id) return (int)$id;
        $pdo->prepare("INSERT INTO foods (name, category, source, energy_kcal, protein_g, carbohydrate_g, lipid_g, fiber_g, serving_size_g, serving_unit, is_custom, is_current) VALUES (?, 'TESTE', 'TACO', ?, ?, ?, ?, 1, 100, 'g', 0, 1)")
            ->execute([$name, $kcal, $p, $c, $l]);
        return (int)$pdo->lastInsertId();
    };
    $fPeanut = $food('Amendoim, grão, cru', 544, 27.2, 20.3, 43.9);
    $fBread = $food('Pão, trigo, francês', 300, 8, 58.6, 3.1);

    return [
        'clinicA' => $cA, 'clinicB' => $cB, 'a' => $a, 'a2' => $a2, 'reception' => $rec, 'adminNoCrn' => $adm, 'b' => $b,
        'patientB' => $patientB, 'foodPeanut' => $fPeanut, 'foodBread' => $fBread, 'password' => NI2_233_PASSWORD,
    ];
}
