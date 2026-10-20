<?php

declare(strict_types=1);

// NI2-439 — Protocolos antropométricos e dobras cutâneas.
// Casos de referência: (1) tabelas de % de gordura publicadas pelos autores; (2) valores calculados de forma
// independente com aritmética decimal de 40 dígitos (Python Decimal) a partir das equações do catálogo.
session_start();
ob_start();
require_once __DIR__ . '/../vendor/autoload.php';

use ClyoNutri\Core\Auth\TenantContext;
use ClyoNutri\Core\Database\Connection;
use ClyoNutri\Domain\Anthropometry\InvalidAnthropometricDataException;
use ClyoNutri\Domain\Anthropometry\SkinfoldCalculator as Calc;
use ClyoNutri\Domain\Anthropometry\SkinfoldProtocolCatalog as Catalog;
use ClyoNutri\Domain\Anthropometry\SkinfoldService;

$passed = 0;
$failed = 0;
$log = [];
function ok(bool $c, string $m): void
{
    global $passed, $failed, $log;
    $c ? $passed++ : $failed++;
    $log[] = ($c ? '  [PASS] ' : '  [FAIL] ') . $m;
}
function rejects(callable $f, string $needle = ''): bool
{
    try {
        $f();
        return false;
    } catch (InvalidAnthropometricDataException $e) {
        return $needle === '' || str_contains($e->getMessage(), $needle);
    }
}
$finally = null;
register_shutdown_function(function () {
    global $passed, $failed, $log, $finally;
    if (is_callable($finally)) {
        $finally();
    }
    while (ob_get_level() > 0) {
        ob_end_clean();
    }
    echo "NI2-439 PROTOCOLOS DE DOBRAS CUTÂNEAS\n" . implode("\n", $log) . "\n  PASSOU: {$passed}\n  FALHOU: {$failed}\n";
    if ($failed > 0) {
        exit(1);
    }
});

/** Distribui uma soma entre as dobras do protocolo (o resultado depende só da soma). */
function readingsFor(string $code, float $sum): array
{
    $sites = Catalog::PROTOCOLS[$code]['sites'];
    $each = floor($sum / count($sites) * 10) / 10;
    $out = array_fill_keys($sites, $each);
    $out[end($sites)] = round($sum - $each * (count($sites) - 1), 1); // soma exata com valores de 0,1 mm
    return $out;
}

// ---------- 1. Tabela dos autores: Jackson & Pollock, homens, 3 dobras (Pollock, Schmidt & Jackson, 1980) ----------
$jpMen = [27 => [7.6, 8.1, 8.7, 9.2, 9.8, 10.3, 10.9], 51 => [14.8, 15.4, 15.9, 16.5, 17.1, 17.6, 18.2],
    75 => [21.5, 22.0, 22.6, 23.2, 23.8, 24.4, 25.0], 99 => [27.4, 28.0, 28.6, 29.2, 29.8, 30.4, 31.0]];
$ages = [25, 30, 35, 40, 45, 50, 55];
$maxDiff = 0.0;
foreach ($jpMen as $sum => $row) {
    foreach ($row as $i => $expected) {
        $r = Calc::calculate('jp3_male', 'male', $ages[$i], readingsFor('jp3_male', $sum));
        $maxDiff = max($maxDiff, abs($r['body_fat_pct'] - $expected));
    }
}
ok($maxDiff <= 0.1, sprintf('JP 3 dobras (homens) × tabela dos autores, 28 células: diferença máxima %.2f p.p. (≤ 0,1)', $maxDiff));

// ---------- 2. Tabela dos autores: Durnin & Womersley (1974), 30 a 72 anos ----------
$dw = [
    ['male', 35, [20 => 12.2, 30 => 16.2, 35 => 17.7, 40 => 19.2, 50 => 21.5, 60 => 23.5, 70 => 25.1]],
    ['male', 45, [20 => 12.2, 30 => 17.7, 35 => 19.6, 40 => 21.4, 50 => 24.6, 60 => 27.1, 70 => 29.3]],
    ['male', 60, [20 => 12.6, 30 => 18.6, 35 => 20.8, 40 => 22.9, 50 => 26.5, 60 => 29.2, 70 => 31.6]],
    ['female', 35, [20 => 17.0, 30 => 21.8, 35 => 23.7, 40 => 25.5, 50 => 28.2, 60 => 30.6, 70 => 32.5]],
    ['female', 45, [20 => 19.8, 30 => 24.5, 35 => 26.4, 40 => 28.2, 50 => 31.0, 60 => 33.2, 70 => 35.0]],
    ['female', 60, [20 => 21.4, 30 => 26.6, 35 => 28.5, 40 => 30.3, 50 => 33.4, 60 => 35.7, 70 => 37.7]],
];
$maxDiff = 0.0;
$cells = 0;
foreach ($dw as [$sex, $age, $row]) {
    foreach ($row as $sum => $expected) {
        $r = Calc::calculate('dw4', $sex, $age, readingsFor('dw4', $sum));
        $maxDiff = max($maxDiff, abs($r['body_fat_pct'] - $expected));
        $cells++;
    }
}
ok($maxDiff <= 0.25, sprintf('Durnin & Womersley × tabela dos autores, %d células: diferença máxima %.2f p.p. (≤ 0,25)', $cells, $maxDiff));

// ---------- 3. Casos calculados de forma independente (Decimal, 40 dígitos) ----------
$cases = [
    ['jp7_male', 'male', 30, 120, 1.05907, 17.39],
    ['jp3_male', 'male', 25, 60, 1.05910, 17.38],
    ['jp7_female', 'female', 35, 140, 1.03773, 27.00],
    ['jp3_female', 'female', 40, 75, 1.03239, 29.47],
    ['dw4', 'male', 22, 40, 1.06185, 16.17],
    ['dw4', 'female', 45, 60, 1.02448, 33.17],
    ['dw4', 'female', 16, 35, 1.05021, 21.33],
    ['dw4', 'male', 55, 70, 1.02777, 31.63],
];
foreach ($cases as [$code, $sex, $age, $sum, $density, $fat]) {
    $r = Calc::calculate($code, $sex, $age, readingsFor($code, (float)$sum));
    ok(abs($r['body_density'] - $density) < 0.000006 && abs($r['body_fat_pct'] - $fat) < 0.006,
        "{$code} {$sex} {$age} anos, soma {$sum} mm → D {$r['body_density']} / {$r['body_fat_pct']}% (referência {$density} / {$fat}%)");
}

// ---------- 4. Determinismo e proveniência ----------
$a = Calc::calculate('jp7_male', 'male', 30, readingsFor('jp7_male', 120), 80);
$b = Calc::calculate('jp7_male', 'male', 30, readingsFor('jp7_male', 120), 80);
ok($a === $b, 'Mesmas entradas → resultado idêntico (determinístico)');
ok($a['fat_mass_kg'] === 13.91 && $a['lean_mass_kg'] === 66.09, 'Massa gorda e magra a partir do peso (80 kg × 17,39%)');
ok(str_contains($a['provenance']['reference'], 'doi:10.1079/BJN19780152') && $a['provenance']['catalog_version'] === Catalog::CATALOG_VERSION
    && str_contains($a['provenance']['conversion'], 'Siri'), 'Proveniência inclui referência (DOI), versão do catálogo e conversão de Siri');
$dwr = Calc::calculate('dw4', 'female', 45, readingsFor('dw4', 60));
ok(($dwr['provenance']['coefficients_used']['age_band'] ?? '') === '40-49', 'Durnin & Womersley registra a faixa etária e os coeficientes usados');

// ---------- 5. Elegibilidade (sem extrapolação) ----------
$jp3m = readingsFor('jp3_male', 60);
ok(rejects(fn() => Calc::calculate('jp3_male', 'male', 62, $jp3m), '18 a 61'), 'JP homens: 62 anos recusado');
ok(rejects(fn() => Calc::calculate('jp3_male', 'male', 17, $jp3m), '18 a 61'), 'JP homens: 17 anos recusado');
ok(!rejects(fn() => Calc::calculate('jp3_male', 'male', 61, $jp3m)), 'JP homens: 61 anos aceito');
ok(rejects(fn() => Calc::calculate('jp3_female', 'female', 56, readingsFor('jp3_female', 60)), '18 a 55'), 'JP mulheres: 56 anos recusado');
ok(rejects(fn() => Calc::calculate('jp3_male', 'female', 30, $jp3m), 'apenas para homens'), 'Protocolo masculino recusa sexo feminino');
ok(rejects(fn() => Calc::calculate('jp3_male', null, 30, $jp3m), 'sexo'), 'Sem sexo para a equação: recusado');
ok(rejects(fn() => Calc::calculate('jp3_male', 'male', null, $jp3m), 'data de nascimento'), 'Sem idade: recusado');
ok(rejects(fn() => Calc::calculate('dw4', 'male', 16, readingsFor('dw4', 40)), '17 a 72'), 'Durnin & Womersley homens: 16 anos recusado');
ok(rejects(fn() => Calc::calculate('dw4', 'female', 69, readingsFor('dw4', 40)), '16 a 68'), 'Durnin & Womersley mulheres: 69 anos recusado');
ok(rejects(fn() => Calc::calculate('inexistente', 'male', 30, [])), 'Protocolo desconhecido recusado');

// ---------- 6. Limites e alertas de medição improvável ----------
ok(rejects(fn() => Calc::calculate('jp3_male', 'male', 30, ['chest' => 0.5, 'abdominal' => 20, 'thigh' => 20]), 'plicômetro'), 'Dobra de 0,5 mm recusada');
ok(rejects(fn() => Calc::calculate('jp3_male', 'male', 30, ['chest' => 85, 'abdominal' => 20, 'thigh' => 20]), 'plicômetro'), 'Dobra de 85 mm recusada');
ok(rejects(fn() => Calc::calculate('jp3_male', 'male', 30, ['chest' => 'abc', 'abdominal' => 20, 'thigh' => 20]), 'números'), 'Valor não numérico recusado');
ok(rejects(fn() => Calc::calculate('jp3_male', 'male', 30, ['chest' => 20, 'abdominal' => 20]), 'Coxa'), 'Dobra faltando recusada com o nome da dobra');
ok(rejects(fn() => Calc::calculate('jp3_male', 'male', 30, ['chest' => [10, 10, 10, 10], 'abdominal' => 20, 'thigh' => 20]), 'no máximo 3'), 'Mais de 3 leituras recusado');
$r = Calc::calculate('jp3_male', 'male', 30, ['chest' => '12,5', 'abdominal' => 20, 'thigh' => 20]);
ok($r['sites']['chest']['value_mm'] === 12.5, 'Vírgula decimal aceita (12,5 mm)');
$r = Calc::calculate('jp3_male', 'male', 30, ['chest' => [10, 12, 30], 'abdominal' => [20, 21], 'thigh' => 20]);
ok($r['sites']['chest']['value_mm'] === 12.0 && $r['sites']['abdominal']['value_mm'] === 20.5, 'Mediana de 3 leituras e média de 2');
ok(in_array('reading_spread', array_column($r['warnings'], 'code'), true), 'Leituras discrepantes (>5%) geram alerta para repetir');
$r = Calc::calculate('jp3_male', 'male', 30, ['chest' => 55, 'abdominal' => 20, 'thigh' => 20]);
ok(in_array('site_unusual', array_column($r['warnings'], 'code'), true), 'Dobra de 55 mm aceita com alerta de valor incomum');
$r = Calc::calculate('jp3_male', 'male', 20, ['chest' => 2, 'abdominal' => 3, 'thigh' => 3]);
ok(in_array('fat_below_essential', array_column($r['warnings'], 'code'), true), 'Resultado abaixo da gordura essencial gera alerta');
ok(rejects(fn() => Calc::calculate('jp3_male', 'male', 18, ['chest' => 1, 'abdominal' => 1, 'thigh' => 1]), 'fisiologicamente'), 'Resultado fisiologicamente impossível (gordura ≤ 0%) é recusado');

// ---------- 7. Serviço: registro versionado, auditoria, vínculo NI2-7, tenant ----------
$db = Connection::getInstance();
// Banco de testes já existente não recebe migrações novas automaticamente: aplica as pendentes (idempotente).
(new ClyoNutri\Core\Database\Migrator($db, __DIR__ . '/../database/migrations'))->run();
$db->exec("INSERT OR IGNORE INTO clinics (id, uuid, name, document) VALUES (971,'c971','Clínica Dobras','971'),(972,'c972','Outra','972')");
$db->exec("INSERT OR IGNORE INTO users (id, uuid, name, email, password_hash, role, status) VALUES (971,'u971','Dra. Lia','lia971@x','x','nutritionist','active'),(972,'u972','Rec','rec972@x','x','nutritionist','active')");
$db->exec("INSERT OR IGNORE INTO clinic_members (clinic_id, user_id, role, status) VALUES (971,971,'nutritionist','active'),(971,972,'admin','active')");
$db->exec("INSERT OR IGNORE INTO professionals (id, user_id, crn, crn_region) VALUES (971,971,'CRN-971','CRN-3')");
$db->exec("DELETE FROM patients WHERE uuid IN ('p971','p972','p973')");
$db->exec("INSERT INTO patients (clinic_id, uuid, name, status, gender, birth_date) VALUES (971,'p971','Ana Dobras','active','Feminino','1990-03-10')");
$pid = (int)$db->lastInsertId();
$db->exec("INSERT INTO patients (clinic_id, uuid, name, status, gender, birth_date) VALUES (972,'p972','Outra Clínica','active','Feminino','1990-03-10')");
$foreignPid = (int)$db->lastInsertId();
$db->exec("INSERT INTO patients (clinic_id, uuid, name, status, gender, birth_date) VALUES (971,'p973','Sem Sexo','active','Outro','1985-01-01')");
$otherPid = (int)$db->lastInsertId();
$db->exec("INSERT INTO anthropometric_assessments (clinic_id, patient_id, professional_id, weight_kg, height_cm, bmi, assessed_at) VALUES (971,$pid,971,64.0,165,23.5,'2026-09-01 09:00:00')");
$assessmentId = (int)$db->lastInsertId();
TenantContext::set(971, ['id' => 971, 'role' => 'nutritionist', 'name' => 'Dra. Lia']);
$_SESSION['user_id'] = 971;
$_SESSION['clinic_id'] = 971;
$svc = new SkinfoldService($db);

ok(SkinfoldService::ageAt('1990-03-10', '2026-03-09') === 35 && SkinfoldService::ageAt('1990-03-10', '2026-03-10') === 36, 'Idade em anos completos na data da avaliação');
$jp3f = fn(float $tri, float $sup, float $thi) => ['triceps' => [$tri], 'suprailiac' => [$sup], 'thigh' => [$thi]];
$m1 = $svc->record($pid, 971, ['protocol' => 'jp3_female', 'assessed_at' => '2026-09-01', 'assessment_id' => $assessmentId, 'readings' => $jp3f(20, 15, 25)], 971);
ok($m1['equation_sex'] ?? $m1['sex'] === 'female', 'Sexo da equação vem do cadastro (Feminino)');
$row = $db->query("SELECT * FROM skinfold_measurements WHERE id = {$m1['id']}")->fetch(PDO::FETCH_ASSOC);
ok($row['status'] === 'active' && (int)$row['version'] === 1 && (int)$row['record_group_id'] === $m1['id'] && $row['catalog_version'] === Catalog::CATALOG_VERSION, 'Registro v1 ativo, com grupo e versão do catálogo');
ok((int)$row['age_years'] === 36 && (float)$row['weight_kg'] === 64.0 && $row['fat_mass_kg'] !== null, 'Idade e peso da avaliação NI2-7 vinculada entram no cálculo');
ok(str_contains((string)$row['provenance_json'], 'Jackson'), 'Proveniência gravada com a medição');
$audit = $db->query("SELECT user_id, details FROM audit_logs WHERE action = 'SKINFOLD_RECORDED' AND resource_id = '{$m1['id']}'")->fetch(PDO::FETCH_ASSOC);
ok((int)($audit['user_id'] ?? 0) === 971, 'Trilha de auditoria SKINFOLD_RECORDED com o usuário');

ok(rejects(fn() => $svc->record($otherPid, 971, ['protocol' => 'jp3_female', 'assessed_at' => '2026-09-01', 'readings' => $jp3f(20, 15, 25)])), 'Paciente com sexo "Outro" exige escolha explícita do sexo da equação');
$m0 = $svc->record($otherPid, 971, ['protocol' => 'jp3_female', 'equation_sex' => 'female', 'assessed_at' => '2026-09-01', 'readings' => $jp3f(20, 15, 25)]);
ok($m0['sex'] === 'female', 'Com a escolha explícita, a medição é registrada e o sexo usado fica gravado');
ok(rejects(fn() => $svc->record($pid, 971, ['protocol' => 'jp3_female', 'assessed_at' => '2099-01-01', 'readings' => $jp3f(20, 15, 25)]), 'futuro'), 'Data no futuro recusada');
$db->exec("INSERT INTO anthropometric_assessments (clinic_id, patient_id, professional_id, weight_kg, height_cm, bmi, assessed_at) VALUES (971,$otherPid,971,70,170,24,'2026-09-01 09:00:00')");
$otherAssessment = (int)$db->lastInsertId();
ok(rejects(fn() => $svc->record($pid, 971, ['protocol' => 'jp3_female', 'assessment_id' => $otherAssessment, 'readings' => $jp3f(20, 15, 25)]), 'não pertence'), 'Avaliação de outro paciente não pode ser vinculada');
ok((function () use ($svc, $foreignPid, $jp3f) {
    try {
        $svc->record($foreignPid, 971, ['protocol' => 'jp3_female', 'readings' => $jp3f(20, 15, 25)]);
        return false;
    } catch (Throwable $e) {
        return !($e instanceof InvalidAnthropometricDataException);
    }
})(), 'Paciente de outra clínica bloqueado pelo TenantGuard');

// Tendência: segunda medição 20 dias depois com salto
$m2 = $svc->record($pid, 971, ['protocol' => 'jp3_female', 'assessed_at' => '2026-09-21', 'readings' => $jp3f(20, 15, 40)]);
$codes = array_column($m2['warnings'], 'code');
ok(in_array('site_jump', $codes, true), 'Salto da coxa (25→40 mm) em 20 dias gera alerta: ' . implode(',', $codes));
$mJump = $svc->record($otherPid, 971, ['protocol' => 'jp3_female', 'equation_sex' => 'female', 'assessed_at' => '2026-09-10', 'readings' => $jp3f(30, 25, 35)]);
ok(in_array('fat_jump', array_column($mJump['warnings'], 'code'), true), 'Variação ≥ 5 p.p. de gordura em 9 dias gera alerta');

// Correção versionada
ok(rejects(fn() => $svc->correct($m2['id'], 971, ['protocol' => 'jp3_female', 'assessed_at' => '2026-09-21', 'readings' => $jp3f(20, 15, 26)], 'curto'), 'motivo'), 'Correção sem motivo suficiente recusada');
$m2b = $svc->correct($m2['id'], 971, ['protocol' => 'jp3_female', 'assessed_at' => '2026-09-21', 'readings' => $jp3f(20, 15, 26)], 'Coxa digitada errado: 40 em vez de 26 mm.', 971);
ok($m2b['version'] === 2 && $m2b['record_group_id'] === $m2['id'], 'Correção vira versão 2 do mesmo grupo');
ok($db->query("SELECT status FROM skinfold_measurements WHERE id = {$m2['id']}")->fetchColumn() === 'superseded', 'Versão 1 mantida como superseded (nada apagado)');
ok(!in_array('fat_jump', array_column($m2b['warnings'], 'code'), true), 'Após corrigir, o alerta de salto desaparece');
$hist = $svc->history($m2b['id']);
ok(count($hist) === 2 && $hist[0]['correction_reason'] !== null, 'Histórico traz as 2 versões e o motivo');
ok(rejects(fn() => $svc->correct($m2['id'], 971, ['protocol' => 'jp3_female', 'readings' => $jp3f(20, 15, 26)], 'Tentativa sobre versão antiga.'), 'já foi corrigida'), 'Versão substituída não pode ser corrigida de novo');
$auditC = $db->query("SELECT details FROM audit_logs WHERE action = 'SKINFOLD_CORRECTED' AND resource_id = '{$m2b['id']}'")->fetchColumn();
ok(str_contains((string)$auditC, 'Coxa digitada errado') && str_contains((string)$auditC, 'previous_body_fat_pct'), 'Auditoria da correção com motivo e valor anterior');

// Série temporal: só o protocolo pedido, só versões vigentes
$svc->record($pid, 971, ['protocol' => 'dw4', 'assessed_at' => '2026-09-21', 'readings' => ['biceps' => 8, 'triceps' => 20, 'subscapular' => 15, 'suprailiac' => 15]]);
$evo = $svc->evolution($pid, 'jp3_female');
ok(count($evo['points']) === 2 && $evo['points'][1]['version'] === 2, 'Evolução: 2 pontos JP3 (versão corrigida), sem misturar Durnin & Womersley');
ok(str_contains($evo['accessible_summary'], '2 medições') && str_contains($evo['provenance']['reference'], 'Jackson'), 'Resumo acessível e proveniência da série');

// LGPD: portabilidade inclui as dobras
$export = (new ClyoNutri\Domain\Privacy\LgpdService($db))->exportPatientData($pid);
ok(count($export['skinfold_measurements'] ?? []) === 4, 'Exportação LGPD inclui as medições de dobras (todas as versões)');

// ---------- 8. Controller: autorização e tela com gráfico e proveniência ----------
$_SESSION['user_id'] = 972;
TenantContext::set(971, ['id' => 972, 'role' => 'nutritionist']);
$_POST = ['protocol' => 'jp3_female', 'readings' => ['triceps' => ['20'], 'suprailiac' => ['15'], 'thigh' => ['25']]];
http_response_code(200);
ob_start();
(new ClyoNutri\Application\Controllers\SkinfoldController($db))->store(['id' => (string)$pid]);
ob_end_clean();
ok(http_response_code() === 403, 'Usuário sem CRN não registra dobras (403)');
$_SESSION['user_id'] = 971;
TenantContext::set(971, ['id' => 971, 'role' => 'nutritionist', 'name' => 'Dra. Lia']);
$_GET = ['grafico' => 'jp3_female'];
ob_start();
(new ClyoNutri\Application\Controllers\SkinfoldController($db))->show(['id' => (string)$pid]);
$html = (string)ob_get_clean();
ok(str_contains($html, '<svg') && str_contains($html, 'data-testid="chart-provenance"') && str_contains($html, 'Jackson'), 'Tela mostra gráfico SVG com proveniência');
ok(str_contains($html, 'data-testid="protocol-provenance"') && str_contains($html, 'name="readings[triceps][]"'), 'Formulário guiado pelo protocolo com localização anatômica');
ok(!str_contains((string)file_get_contents(__DIR__ . '/../views/anthropometry/skinfolds.php'), '<script'), 'Tela de dobras sem JavaScript próprio (CSP)');
