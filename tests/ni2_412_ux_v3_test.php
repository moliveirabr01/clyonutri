<?php

declare(strict_types=1);

/**
 * NI2-412 (UX/UI v3): testes automatizados das histórias UX-01..UX-13 que têm comportamento de servidor
 * ou contrato de template. O comportamento de navegador (foco, Tab, descarte, viewports) é verificado
 * no E2E real em tests/ni2_412_real_browser_e2e.py (Apache + MariaDB, dados fictícios).
 *
 * Usa o banco isolado de testes (Connection redireciona scripts de tests/ para storage/testing.sqlite).
 */

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
require_once __DIR__ . '/../vendor/autoload.php';

use ClyoNutri\Core\Auth\TenantContext;
use ClyoNutri\Core\Database\Connection;
use ClyoNutri\Core\Time\AgendaNavigation;
use ClyoNutri\Domain\Appointment\AppointmentService;

$passed = 0; $failed = 0;
function check(bool $cond, string $msg): void
{
    global $passed, $failed;
    echo ($cond ? '  [PASS] ' : '  [FAIL] ') . $msg . "\n";
    $cond ? $passed++ : $failed++;
}
$db = Connection::getInstance();
check(!str_ends_with(str_replace('\\', '/', (string)getenv('DB_DATABASE')), 'storage/database.sqlite'), 'Teste não usa o banco de desenvolvimento');
$root = realpath(__DIR__ . '/..');

echo "[NI2-413] Navegação da agenda por visão\n";
$w = AgendaNavigation::forView('week', '2026-10-14', '2026-10-15');
check($w['start'] === '2026-10-12' && $w['end'] === '2026-10-18', 'Semana normalizada para segunda a domingo');
check($w['next'] === '2026-10-19' && $w['prev'] === '2026-10-05', 'Semanal anda 7 dias a partir da segunda (não 1 dia)');
foreach (['2026-10-12', '2026-10-15', '2026-10-18'] as $anchor) {
    $n = AgendaNavigation::forView('week', AgendaNavigation::forView('week', $anchor, '2026-10-15')['next'], '2026-10-15');
    check($n['start'] === '2026-10-19', "Qualquer âncora da semana ({$anchor}) avança para a semana seguinte em um clique");
}
$y = AgendaNavigation::forView('week', '2026-12-30', '2026-10-15');
check($y['start'] === '2026-12-28' && $y['next'] === '2027-01-04', 'Virada de ano na semanal');
$d = AgendaNavigation::forView('day', '2026-12-31', '2026-10-15');
check($d['next'] === '2027-01-01' && $d['prev'] === '2026-12-30', 'Diária anda 1 dia (virada de ano)');
check(AgendaNavigation::forView('day', '2026-03-01', '2026-10-15')['prev'] === '2026-02-28', 'Diária: virada de mês (fevereiro)');
check(AgendaNavigation::forView('day', '2028-03-01', '2026-10-15')['prev'] === '2028-02-29', 'Diária: ano bissexto');
check(str_starts_with($w['next_label'], 'Próxima semana (19 de outubro de 2026') && str_starts_with($w['prev_label'], 'Semana anterior'), 'Rótulos acessíveis com direção e período (semana)');
check(str_starts_with($d['next_label'], 'Próximo dia (1 de janeiro de 2027'), 'Rótulos acessíveis com direção e data (dia)');
check($w['is_current'] === true && AgendaNavigation::forView('week', '2026-10-19', '2026-10-15')['is_current'] === false, 'Indicador de período vigente (Hoje)');
$threw = false; try { AgendaNavigation::forView('week', '2026-02-30', '2026-10-15'); } catch (\InvalidArgumentException $e) { $threw = true; }
check($threw, 'Data inexistente é rejeitada');

// --- Fixture: duas clínicas fictícias -----------------------------------------
$sfx = bin2hex(random_bytes(4));
$mkClinic = function (string $n) use ($db, $sfx): int { $db->prepare("INSERT INTO clinics (uuid, name, status) VALUES (:u, :n, 'active')")->execute(['u' => 'ux412-' . $n . $sfx, 'n' => 'Clínica UX412 ' . $n . ' ' . $sfx]); return (int)$db->lastInsertId(); };
$cA = $mkClinic('A'); $cB = $mkClinic('B');
$prof = (int)$db->query('SELECT id FROM professionals ORDER BY id LIMIT 1')->fetchColumn();
$mkPatient = function (int $c, string $n) use ($db, $sfx): int { $db->prepare("INSERT INTO patients (clinic_id, uuid, name, status) VALUES (:c, :u, :n, 'active')")->execute(['c' => $c, 'u' => 'ux412-' . bin2hex(random_bytes(6)), 'n' => $n . ' ' . $sfx]); return (int)$db->lastInsertId(); };
$pA = $mkPatient($cA, 'Paciente Fictício A'); $pB = $mkPatient($cB, 'Paciente Fictício B');
$insApp = $db->prepare("INSERT INTO appointments (clinic_id, patient_id, professional_id, scheduled_at, duration_minutes, type, status) VALUES (:c, :p, :pr, :s, 60, 'follow_up', :st)");

echo "[NI2-418] Próximo passo do atendimento (somente leitura, escopo de clínica)\n";
$now = new DateTimeImmutable('2026-10-15 10:00:00', new DateTimeZone('America/Sao_Paulo'));
TenantContext::set($cA, ['id' => 1, 'role' => 'nutritionist', 'name' => 'Teste']);
$svc = new AppointmentService($db, $now);
check($svc->careStepForPatient($pA)['state'] === 'none', 'Sem consulta: estado none (Agendar atendimento)');
$insApp->execute(['c' => $cB, 'p' => $pB, 'pr' => $prof, 's' => '2026-10-15 11:00:00', 'st' => 'in_progress']);
check($svc->careStepForPatient($pB)['state'] === 'none', 'Consulta de outra clínica não aparece (paciente B consultado na clínica A)');
$insApp->execute(['c' => $cA, 'p' => $pA, 'pr' => $prof, 's' => '2026-10-20 09:00:00', 'st' => 'scheduled']);
check($svc->careStepForPatient($pA)['state'] === 'upcoming', 'Consulta futura: estado upcoming');
$insApp->execute(['c' => $cA, 'p' => $pA, 'pr' => $prof, 's' => '2026-10-15 15:00:00', 'st' => 'confirmed']);
$st = $svc->careStepForPatient($pA);
check($st['state'] === 'start_today' && substr($st['appointment']['scheduled_at'], 0, 10) === '2026-10-15', 'Consulta de hoje confirmada: estado start_today');
$todayId = (int)$st['appointment']['id'];
$countBefore = (int)$db->query("SELECT COUNT(*) FROM appointments WHERE patient_id = {$pA}")->fetchColumn();
$statusBefore = $db->query("SELECT status FROM appointments WHERE id = {$todayId}")->fetchColumn();
$svc->careStepForPatient($pA);
check((int)$db->query("SELECT COUNT(*) FROM appointments WHERE patient_id = {$pA}")->fetchColumn() === $countBefore && $db->query("SELECT status FROM appointments WHERE id = {$todayId}")->fetchColumn() === $statusBefore, 'Consultar o próximo passo não cria nem altera consulta');
$db->exec("UPDATE appointments SET status = 'in_progress' WHERE id = {$todayId}");
$st = $svc->careStepForPatient($pA);
check($st['state'] === 'continue' && (int)$st['appointment']['id'] === $todayId, 'Em andamento: continue aponta para a mesma consulta');

echo "[NI2-413/416/417/418/419/420/421/422] Contratos de template renderizados\n";
$_SESSION['user_id'] = 0; $_SESSION['clinic_id'] = $cA; $_SERVER['REQUEST_METHOD'] = 'GET';
$render = function (callable $fn): string { ob_start(); try { $fn(); } finally { $h = (string)ob_get_clean(); } return $h; };
$_GET = ['view' => 'week', 'date' => '2026-10-14'];
$agenda = $render(fn() => (new \ClyoNutri\Application\Controllers\DashboardController())->agenda());
check(str_contains($agenda, 'date=2026-10-19" class="btn btn-secondary" aria-label="Próxima semana'), 'Agenda semanal: link Próxima semana aponta para +7 dias com nome acessível');
check(str_contains($agenda, 'date=2026-10-05" class="btn btn-secondary" aria-label="Semana anterior'), 'Agenda semanal: link Semana anterior aponta para -7 dias');
$_GET = ['view' => '"><script>x</script>', 'date' => '2026-10-14'];
$agendaX = $render(fn() => (new \ClyoNutri\Application\Controllers\DashboardController())->agenda());
check(!str_contains($agendaX, '<script>x</script>') && str_contains($agendaX, 'view=day&date=2026-10-15'), 'Parâmetro view fora da lista branca vira "day" (sem refletir HTML)');
$_GET = ['novo' => '1', 'paciente' => (string)$pA, 'date' => '2026-10-15'];
$agendaN = $render(fn() => (new \ClyoNutri\Application\Controllers\DashboardController())->agenda());
check(str_contains($agendaN, 'id="card-novo-agendamento" data-dialog="inline" class="card" style="display: block;') && preg_match('#<option value="' . $pA . '" selected>#', $agendaN) === 1, 'Agendar a partir do perfil abre o formulário com o paciente explícito selecionado');
$_GET = ['novo' => '1', 'paciente' => (string)$pB, 'date' => '2026-10-15'];
$agendaF = $render(fn() => (new \ClyoNutri\Application\Controllers\DashboardController())->agenda());
check(!str_contains($agendaF, 'value="' . $pB . '"'), 'Paciente de outra clínica não aparece nem é pré-selecionado');
$_GET = [];
$plans = $render(fn() => (new \ClyoNutri\Application\Controllers\DashboardController())->mealPlans());
preg_match('#<div id="modal-new-plan".*?</form>#s', $plans, $mm); $modal = $mm[0] ?? '';
preg_match_all('#<(select|input|textarea)\b(?![^>]*type="hidden")[^>]*\bid="([^"]+)"#', $modal, $ctrls);
$allLabelled = count($ctrls[2]) === 3; foreach ($ctrls[2] as $cid) { $allLabelled = $allLabelled && str_contains($modal, 'for="' . $cid . '"'); }
check($allLabelled, 'Novo plano: os 3 campos têm id e label for correspondente');
check(str_contains($modal, 'data-dialog="modal"') && str_contains($modal, 'data-dialog-close'), 'Novo plano usa o componente de diálogo (data-dialog/data-dialog-close)');
$allSrc = file_get_contents($root . '/src/Application/Controllers/DashboardController.php');
preg_match_all('#<label(?![^>]*\bfor=)[^>]*>(?:(?!</label>).)*?</label>#s', $allSrc, $orph);
$orph = array_filter($orph[0], fn($l) => !preg_match('#<(input|select|textarea)#', $l));
check(count($orph) === 0, 'DashboardController: nenhum label solto sem for (encontrados: ' . count($orph) . ')');
$pat = $render(fn() => (new \ClyoNutri\Application\Controllers\DashboardController())->patients());
check(str_contains($pat, 'id="patientModal" data-dialog="modal"') && !str_contains($pat, "if (e.key === 'Escape') closePatientModal()"), 'Cadastro de paciente usa o componente compartilhado (sem Escape próprio que descartava sem regra)');
check(str_contains($pat, 'aria-describedby="p_gender_help"') && str_contains($pat, 'relação cintura-quadril'), 'Gênero/sexo: ajuda associada descreve o uso real; opções e valores inalterados');
check(str_contains($pat, '<option value="Outro">Outro / Não informado</option>'), 'UX-10: nenhum valor/legado alterado (decisão de produto pendente)');
$_SESSION['user_id'] = 0;
$prof360 = $render(fn() => (new \ClyoNutri\Application\Controllers\DashboardController())->profile360(['id' => $pA]));
check(str_contains($prof360, 'data-testid="care-continue"') && str_contains($prof360, 'href="/consulta/' . $todayId . '"'), 'Perfil: Continuar atendimento da mesma consulta');
check(str_contains($prof360, 'href="#patient-appointments"') && !str_contains($prof360, 'Perfil Clínico 360'), 'Perfil: navegação por seções e sem jargão "Perfil Clínico 360"');
// A renderização usa o relógio real: move a consulta para hoje (o restante do teste usa 15/10/2026 fixo).
$db->prepare("UPDATE appointments SET status = 'confirmed', scheduled_at = :s WHERE id = :id")->execute(['s' => (new DateTimeImmutable('now', new DateTimeZone('America/Sao_Paulo')))->format('Y-m-d') . ' 00:01:00', 'id' => $todayId]);
$prof360b = $render(fn() => (new \ClyoNutri\Application\Controllers\DashboardController())->profile360(['id' => $pA]));
check(preg_match('#<form method="POST" action="/agenda/' . $todayId . '/status"[^>]*data-confirm="Iniciar agora#', $prof360b) === 1 && str_contains($prof360b, 'name="status" value="in_progress"'), 'Perfil: Iniciar atendimento é POST com confirmação (não GET)');
$demoUser = $db->query("SELECT u.id, cm.clinic_id FROM users u JOIN clinic_members cm ON cm.user_id = u.id WHERE u.email = 'demo.local@clyonutri.local' LIMIT 1")->fetch(PDO::FETCH_ASSOC);
$pDemo = $mkPatient((int)$demoUser['clinic_id'], 'Paciente Fictício Anamnese');
TenantContext::set((int)$demoUser['clinic_id'], ['id' => (int)$demoUser['id'], 'role' => 'nutritionist', 'name' => 'Demo']);
$_SESSION['clinic_id'] = (int)$demoUser['clinic_id'];
$anam = $render(fn() => (new \ClyoNutri\Application\Controllers\DashboardController())->patientAnamnesis(['id' => $pDemo]));
TenantContext::set($cA, ['id' => 1, 'role' => 'nutritionist', 'name' => 'Teste']); $_SESSION['clinic_id'] = $cA;
$db->exec("DELETE FROM patients WHERE id = {$pDemo}");
check(substr_count($anam, 'data-sec="') === 8 && str_contains($anam, 'href="#sec-notas"') && str_contains($anam, 'id="sec-notas"'), 'Anamnese: índice com 8 seções e âncoras existentes');
check(str_contains($anam, '>' . "\n                Salvar rascunho") || str_contains($anam, 'Salvar rascunho'), 'Anamnese: botão Salvar rascunho');
check(str_contains($anam, 'Concluir anamnese') && !str_contains($anam, 'Imutável'), 'Anamnese: Concluir anamnese sem jargão');
check(str_contains($anam, 'class="cn-sticky-actions"') && str_contains($anam, 'Nada salvo ainda'), 'Anamnese: barra de ações persistente com estado de salvamento');
check(str_contains($anam, 'name="save_mode" value="draft"') && str_contains($anam, 'name="save_mode" value="final"'), 'Anamnese: contrato de envio (save_mode) preservado');
$_SESSION['user_id'] = 0;
$dash = $render(fn() => (new \ClyoNutri\Application\Controllers\DashboardController())->index());
check(strpos($dash, 'data-testid="attention-panel"') < strpos($dash, 'dashboard-metrics cn-metrics-5'), 'Painel: prioridades antes das métricas');
check(substr_count($dash, 'class="card metric-card"') === 5, 'Painel: as 5 métricas continuam presentes');
foreach (['painel' => $dash, 'perfil' => $prof360, 'anamnese' => $anam, 'pacientes' => $pat, 'agenda' => $agenda, 'planos' => $plans] as $nome => $h) { check(!preg_match('/NI2-\d+|decisão de produto/u', $h), "HTML de $nome não expõe código de ticket nem jargão interno ao usuário"); }
$css = file_get_contents($root . '/public/assets/css/clinical-journeys.css');
check(str_contains($css, '.dashboard-metrics.cn-metrics-5 { grid-template-columns: repeat(5, minmax(0, 1fr))'), 'CSS: 5 colunas deliberadas (sem auto-fit que gera linha órfã)');
check(str_contains($css, '.cn-dialog__panel { box-sizing: border-box; max-width: min(100%') && str_contains($css, 'max-height: calc(100dvh - 24px)'), 'CSS: painel de diálogo com altura máxima e rolagem interna');

echo "[NI2-414/417] Componente JS (contrato estático)\n";
$js = file_get_contents($root . '/public/assets/js/clinical-journeys.js');
check(!str_contains($js, 'dirtyForms.clear()'), 'Submit não limpa o estado de outros formulários');
check(str_contains($js, "input[type=\"search\"]") && str_contains($js, '[data-food-query]'), 'Busca transitória excluída do estado de alteração');
check(str_contains($js, "'[data-dialog],div[id^=\"modal-\"],.modal-overlay'") && str_contains($js, "setAttribute('inert'"), 'Diálogo por atributo, inclui .modal-overlay (patientModal) e torna o fundo inerte');
check(str_contains($js, 'window.confirm(DISCARD_MSG)'), 'Fechar com alteração exige confirmação explícita de descarte');
$fp = file_get_contents($root . '/public/assets/js/food-picker.js');
check(!str_contains($fp, "modal.addEventListener('keydown'") && str_contains($fp, 'window.ClyoDialog'), 'Diálogo de alimento usa o componente compartilhado');

echo "[NI2-425] Login e cadastro\n";
$login = file_get_contents($root . '/views/auth/login.php'); $reg = file_get_contents($root . '/views/auth/register.php');
check(str_contains($login, 'autocomplete="username"') && str_contains($login, 'autocomplete="current-password"') && str_contains($login, 'data-password-toggle'), 'Login: autocomplete e toggle');
check(str_contains($reg, 'mínimo de 8 caracteres') && str_contains($reg, 'autocomplete="new-password"') && !str_contains($reg, '8 dígitos'), 'Cadastro: "8 caracteres" e new-password');
$auth = file_get_contents($root . '/src/Application/Controllers/AuthController.php');
check(substr_count($auth, "mb_strlen(\$password, 'UTF-8') < 8") === 2 && !str_contains($auth, '8 dígitos'), 'Servidor conta caracteres (igual ao minlength)');
check(str_contains($auth, "'Muitas tentativas de acesso.") && !preg_match('/Muitas tentativas[^\']*conta existe/i', $auth), 'Mensagem de bloqueio mantém prefixo e não revela existência de conta');
$pt = file_get_contents($root . '/public/assets/js/password-toggle.js');
check(str_contains($pt, "btn.type = 'button'") && str_contains($pt, "aria-pressed"), 'Toggle é type=button e anuncia estado');

echo "[NI2-415/424] HTTP real (servidor embutido, banco de testes)\n";
$port = random_int(20000, 40000);
$proc = proc_open(['php', '-S', "127.0.0.1:{$port}", '-t', $root . '/public', $root . '/public/index.php'], [1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']], $pipes, $root);
for ($i = 0; $i < 50 && !@fsockopen('127.0.0.1', $port); $i++) { usleep(100000); }
$cookie = ''; $base = "http://127.0.0.1:{$port}";
$http = function (string $method, string $path, array $data = []) use (&$cookie, $base): array {
    $headers = "Content-Type: application/x-www-form-urlencoded\r\nAccept: application/json, text/html\r\n" . ($cookie ? "Cookie: {$cookie}\r\n" : '');
    $ctx = stream_context_create(['http' => ['method' => $method, 'header' => $headers, 'content' => http_build_query($data), 'follow_location' => 0, 'ignore_errors' => true]]);
    $body = (string)@file_get_contents($base . $path, false, $ctx); $status = 0; $location = null;
    foreach ($http_response_header ?? [] as $h) {
        if (preg_match('#^HTTP/\S+ (\d+)#', $h, $mm)) { $status = (int)$mm[1]; }
        if (stripos($h, 'Location:') === 0) { $location = trim(substr($h, 9)); }
        if (stripos($h, 'Set-Cookie:') === 0) { $cookie = explode(';', trim(substr($h, 11)))[0]; }
    }
    return ['status' => $status, 'location' => $location, 'body' => $body];
};
$token = fn(string $html) => preg_match('#name="_csrf_token" value="([^"]+)"#', $html, $t) ? html_entity_decode($t[1]) : '';
$lp = $http('GET', '/login');
$res = $http('POST', '/login', ['_csrf_token' => $token($lp['body']), 'email' => 'demo.local@clyonutri.local', 'password' => 'DevLocalPass@2026']);
check($res['status'] === 302, 'Login demo no banco de testes');
$demoClinic = (int)$db->query("SELECT cm.clinic_id FROM users u JOIN clinic_members cm ON cm.user_id = u.id WHERE u.email = 'demo.local@clyonutri.local' LIMIT 1")->fetchColumn();
$foods = json_decode($http('GET', '/catalogo/alimentos/busca?q=arroz')['body'], true);
$f0 = $foods['data'][0] ?? [];
check(isset($f0['source'], $f0['is_custom'], $f0['protein_g'], $f0['carbohydrate_g'], $f0['lipid_g']) && array_key_exists('category', $f0), 'Busca de alimentos devolve fonte, tipo, categoria e macros');
$db->prepare("INSERT INTO foods (clinic_id, name, source, energy_kcal, is_custom) VALUES (:c, :n, 'Personalizado', 100, 1)")->execute(['c' => $cB, 'n' => 'Arroz Fictício Estrangeiro ' . $sfx]);
$foreignFood = (int)$db->lastInsertId();
$foods2 = json_decode($http('GET', '/catalogo/alimentos/busca?q=' . urlencode('Arroz Fictício Estrangeiro'))['body'], true);
check(($foods2['data'] ?? null) === [], 'Alimento personalizado de outra clínica não aparece');
$chat = $http('GET', '/chat');
if ($chat['status'] === 200) {
    check(str_contains($chat['body'], 'data-testid="new-conversation"') && str_contains($chat['body'], 'id="modal-nova-conversa" data-dialog="modal"'), 'Conversas: botão e diálogo Nova conversa');
    $own = $mkPatient($demoClinic, 'Paciente Fictício Chat'); $ownName = 'Paciente Fictício Chat ' . $sfx;
    $sr = json_decode($http('GET', '/chat/pacientes?q=' . urlencode($ownName))['body'], true);
    check(count($sr['data'] ?? []) === 1 && $sr['data'][0]['id'] === $own && $sr['data'][0]['has_conversation'] === false, 'Busca paginada encontra paciente da clínica');
    $srB = json_decode($http('GET', '/chat/pacientes?q=' . urlencode('Paciente Fictício B ' . $sfx))['body'], true);
    check(($srB['data'] ?? null) === [], 'Busca não lista paciente de outra clínica');
    check($http('GET', '/chat/pacientes?page=0')['status'] === 422, 'Página inválida → 422');
    $tk = $token($chat['body']);
    // NI2-138: o chat só abre com vínculo ativo entre a paciente e a profissional logada.
    $demoProf = (int)$db->query("SELECT p.id FROM professionals p JOIN users u ON u.id = p.user_id WHERE u.email = 'demo.local@clyonutri.local' LIMIT 1")->fetchColumn();
    $db->prepare("INSERT INTO patient_professional_links (clinic_id, patient_id, professional_id, status, source, started_at, created_at, updated_at) VALUES (?, ?, ?, 'active', 'test', ?, ?, ?)")
        ->execute([$demoClinic, $own, $demoProf, date('Y-m-d H:i:s'), date('Y-m-d H:i:s'), date('Y-m-d H:i:s')]);
    $r1 = $http('POST', '/chat/nova', ['_csrf_token' => $tk, 'patient_id' => (string)$own]);
    $r2 = $http('POST', '/chat/nova', ['_csrf_token' => $tk, 'patient_id' => (string)$own]);
    $nConv = (int)$db->query("SELECT COUNT(*) FROM chat_conversations WHERE patient_id = {$own}")->fetchColumn();
    check($r1['status'] === 302 && $r1['location'] === $r2['location'] && $nConv === 1, 'Nova conversa abre e reabre a mesma conversa (sem duplicar)');
    check((int)$db->query("SELECT COUNT(*) FROM chat_messages m JOIN chat_conversations c ON c.id = m.conversation_id WHERE c.patient_id = {$own}")->fetchColumn() === 0, 'Nenhuma mensagem enviada ao abrir conversa');
    $rf = $http('POST', '/chat/nova', ['_csrf_token' => $tk, 'patient_id' => (string)$pB]);
    check($rf['status'] === 404 && (int)$db->query("SELECT COUNT(*) FROM chat_conversations WHERE patient_id = {$pB}")->fetchColumn() === 0, 'Paciente de outra clínica → 404, nada criado');
    check($http('POST', '/chat/nova', ['patient_id' => (string)$own])['status'] === 403, 'Sem CSRF → 403');
    check($http('GET', '/chat?patient_id=' . $pB)['status'] === 404, 'Atalho GET /chat?patient_id estrangeiro → 404 (antes: exceção)');
    $db->exec("DELETE FROM patient_professional_links WHERE patient_id = {$own}"); $db->exec("DELETE FROM chat_conversations WHERE patient_id = {$own}"); $db->exec("DELETE FROM patients WHERE id = {$own}");
} else {
    check(false, 'Usuário demo sem acesso ao chat no banco de testes (status ' . $chat['status'] . ')');
}
proc_terminate($proc); proc_close($proc);

// --- limpeza (somente banco de testes) ---
$db->exec("DELETE FROM foods WHERE id = {$foreignFood}");
$db->exec("DELETE FROM appointments WHERE clinic_id IN ({$cA}, {$cB})");
$db->exec("DELETE FROM anamneses WHERE clinic_id IN ({$cA}, {$cB})");
$db->exec("DELETE FROM patients WHERE clinic_id IN ({$cA}, {$cB})");
$db->exec("DELETE FROM clinics WHERE id IN ({$cA}, {$cB})");
echo "\nResultado: {$passed} passaram, {$failed} falharam\n";
exit($failed === 0 ? 0 : 1);
