<?php

declare(strict_types=1);

/**
 * ClyoNutri II — Contrato do release gate FAIL CLOSED (NI2-409 / AUD-07, substitui NI2-178).
 *
 * Prova positiva e negativa da regra de decisão do gate de homologação:
 *  - APPROVED somente com todos os checks P0 em PASS;
 *  - P0 em FAIL, SKIPPED, BLOCKED, NOT_TESTED, inválido ou ausente => BLOCKED;
 *  - P1/INFO entram como achados, sem bloquear;
 *  - build_release.sh não contém resultados fixos e empacota só com relatório APPROVED do mesmo commit.
 */

require_once __DIR__ . '/../bin/homologation/GateReport.php';

$failures = 0;
function check(string $desc, bool $cond): void
{
    global $failures;
    echo ($cond ? "  [PASS] " : "  [FAIL] ") . $desc . "\n";
    if (!$cond) {
        $failures++;
    }
}

function p0All(array $override = []): array
{
    $checks = [];
    foreach (GateReport::REQUIRED_P0 as $id) {
        $checks[$id] = ['id' => $id, 'priority' => 'P0', 'status' => 'PASS', 'detail' => 'ok'];
    }
    foreach ($override as $id => $status) {
        if ($status === null) {
            unset($checks[$id]);
        } else {
            $checks[$id]['status'] = $status;
        }
    }
    return array_values($checks);
}

echo "==============================================\n";
echo "  RELEASE GATE FAIL CLOSED — NI2-409\n";
echo "==============================================\n\n";

echo "[1] Regra de decisão\n";
check('Todos os P0 em PASS => APPROVED', GateReport::decide(p0All())['decision'] === 'APPROVED');
foreach (['FAIL', 'SKIPPED', 'BLOCKED', 'NOT_TESTED'] as $status) {
    $d = GateReport::decide(p0All(['web_journeys' => $status]));
    check("P0 em {$status} => BLOCKED (e listado como bloqueio)", $d['decision'] === 'BLOCKED' && $d['blocking'][0]['id'] === 'web_journeys');
}
$missing = GateReport::decide(p0All(['mysql_schema' => null]));
check('P0 obrigatório ausente (mysql_schema) => BLOCKED/MISSING', $missing['decision'] === 'BLOCKED' && $missing['blocking'][0]['status'] === 'MISSING');
$native = GateReport::decide(p0All(['mobile_native_android' => 'BLOCKED']));
check('Perfil mobile: app nativo sem evidência de dispositivo bloqueia a release', $native['decision'] === 'BLOCKED');
$webChecks = p0All(['mobile_native_android' => null]);
$webChecks[] = ['id' => 'mobile_native_android', 'priority' => 'INFO', 'status' => 'BLOCKED', 'detail' => 'sem evidência'];
check('Perfil web (HostGator): Android nativo sem evidência não bloqueia', GateReport::decide($webChecks, 'web')['decision'] === 'APPROVED');
check('Perfil mobile: rebaixar o Android para INFO não burla a exigência', GateReport::decide($webChecks, 'mobile')['decision'] === 'BLOCKED');
check('Perfil web continua exigindo MySQL, API, web e ensaio de deploy', GateReport::decide(p0All(['deploy_rollback_rehearsal' => 'SKIPPED']), 'web')['decision'] === 'BLOCKED');
check('Perfil desconhecido bloqueia', GateReport::decide(p0All(), 'producao')['decision'] === 'BLOCKED');
$withP1 = array_merge(p0All(), [['id' => 'accessibility', 'priority' => 'P1', 'status' => 'FAIL', 'detail' => 'x']]);
check('P1 em FAIL não bloqueia quando todos os P0 passam', GateReport::decide($withP1)['decision'] === 'APPROVED');
$invalid = array_merge(p0All(), [['id' => 'x', 'priority' => 'P0', 'status' => 'OK']]);
check('Status desconhecido ("OK") é tratado como bloqueio', GateReport::decide($invalid)['decision'] === 'BLOCKED');
$mock = array_merge(p0All(), [['id' => 'teleconsulta_external_provider', 'priority' => 'INFO', 'status' => 'BLOCKED', 'detail' => 'sem credenciais']]);
check('Teleconsulta externa sem credenciais aparece como NÃO homologada (INFO), nunca PASS', GateReport::decide($mock)['decision'] === 'APPROVED');

echo "\n[2] Relatório a partir de evidências reais em disco\n";
$dir = sys_get_temp_dir() . '/gate_report_test_' . getmypid();
@mkdir($dir . '/screenshots', 0700, true);
$checks = array_merge(p0All(['mobile_native_android' => 'BLOCKED']), [['id' => 'teleconsulta_external_provider', 'priority' => 'INFO', 'status' => 'BLOCKED', 'detail' => 'sem credenciais']]);
file_put_contents($dir . '/checks.jsonl', implode("\n", array_map('json_encode', $checks)) . "\n");
file_put_contents($dir . '/environment.json', json_encode(['source' => ['commit' => str_repeat('a', 40), 'dirty' => false, 'dirty_files' => 0, 'content_sha256' => str_repeat('b', 64)]]));
file_put_contents($dir . '/screenshots/web__01.png', 'png-sintetico');
$report = GateReport::build($dir);
check('Relatório bloqueado com is_production_approved = false', $report['decision'] === 'BLOCKED' && $report['is_production_approved'] === false);
check('Relatório declara fail_closed_enforced = true', $report['fail_closed_enforced'] === true);
$shot = array_values(array_filter($report['evidence_files'], fn($f) => $f['path'] === 'screenshots/web__01.png'));
check('Evidências listadas com SHA-256 real', $shot && $shot[0]['sha256'] === hash('sha256', 'png-sintetico'));
check('Teleconsulta listada em features_not_homologated', (bool)array_filter($report['features_not_homologated'], fn($f) => str_starts_with($f, 'teleconsulta_external_provider')));
check('Markdown mostra a decisão e o bloqueio', str_contains(GateReport::markdown($report), 'BLOCKED') && str_contains(GateReport::markdown($report), 'mobile_native_android'));
array_map('unlink', glob($dir . '/screenshots/*') ?: []);
array_map('unlink', glob($dir . '/*.*') ?: []);
@rmdir($dir . '/screenshots');
@rmdir($dir);

echo "\n[3] build_release.sh sem resultados fixos\n";
$script = (string)file_get_contents(__DIR__ . '/../bin/build_release.sh');
check('Executa bin/homologation_gate.sh', str_contains($script, 'bin/homologation_gate.sh'));
check('Exige decisão APPROVED do relatório', str_contains($script, '"APPROVED"'));
check('Recusa relatório de outro commit ou de árvore não commitada', str_contains($script, 'git rev-parse HEAD') && str_contains($script, '["dirty"]'));
check('Não contém camadas "PASSED" escritas à mão', !preg_match('/"(syntax_static|http_web|mobile_api|e2e_journeys)"\s*:\s*"PASSED/', $script));
check('Exige que o perfil do relatório seja o da release pedida', str_contains($script, 'GATE_RELEASE_PROFILE') && str_contains($script, 'release_profile'));
check('Recusa empacotar se o código mudou depois do relatório (fingerprint do conteúdo)', str_contains($script, 'source_fingerprint.sh') && str_contains($script, 'content_sha256'));
check('Não descarta a saída das suítes (> /dev/null)', !preg_match('/php "\$test_file" > \/dev\/null/', $script));

echo "\n" . ($failures === 0 ? "✓ CONTRATO FAIL CLOSED VERIFICADO" : "✗ {$failures} verificação(ões) falharam") . "\n";
exit($failures === 0 ? 0 : 1);
