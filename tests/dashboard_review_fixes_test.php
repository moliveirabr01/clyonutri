<?php

declare(strict_types=1);

/**
 * Revisão do /dashboard: deduplicação das prioridades por paciente, consistência
 * entre o card "Planos a revisar" e a lista de atenção, filtro /planos?status=draft
 * e logout exigindo POST + CSRF (HTTP real via servidor embutido).
 *
 * Usa o banco isolado de testes (Connection redireciona scripts de tests/ para storage/testing.sqlite).
 */

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/../vendor/autoload.php';

use ClyoNutri\Core\Auth\TenantContext;
use ClyoNutri\Core\Database\Connection;
use ClyoNutri\Domain\Appointment\AppointmentService;
use ClyoNutri\Domain\MealPlan\DraftPlanSummary;

$passed = 0;
$failed = 0;
function check(bool $cond, string $msg): void
{
    global $passed, $failed;
    echo ($cond ? '  [PASS] ' : '  [FAIL] ') . $msg . "\n";
    $cond ? $passed++ : $failed++;
}

$db = Connection::getInstance();
$dbPath = (string)getenv('DB_DATABASE');
check(!str_ends_with(str_replace('\\', '/', $dbPath), 'storage/database.sqlite'), 'Teste não usa o banco de desenvolvimento (' . basename($dbPath) . ')');

// --- Fixture em uma clínica dedicada (não interfere na clínica demo) ---------
$suffix = bin2hex(random_bytes(4));
$db->prepare("INSERT INTO clinics (uuid, name, status) VALUES (:u, :n, 'active')")->execute(['u' => 'dash-review-' . $suffix, 'n' => 'Clínica Revisão Dashboard ' . $suffix]);
$clinicId = (int)$db->lastInsertId();
$otherClinicId = (int)$db->query("SELECT id FROM clinics WHERE id <> {$clinicId} ORDER BY id LIMIT 1")->fetchColumn();
$professionalId = (int)$db->query('SELECT id FROM professionals ORDER BY id LIMIT 1')->fetchColumn();

$insPatient = $db->prepare("INSERT INTO patients (clinic_id, uuid, name, status) VALUES (:c, :u, :n, 'active')");
$insPlan = $db->prepare("INSERT INTO meal_plans (clinic_id, patient_id, professional_id, title, status) VALUES (:c, :p, :pr, :t, :s)");
$mkPatient = function (int $clinic, string $name) use ($insPatient, $db, $suffix): int {
    $insPatient->execute(['c' => $clinic, 'u' => 'dash-' . bin2hex(random_bytes(6)), 'n' => $name . ' ' . $suffix]);
    return (int)$db->lastInsertId();
};
$mkPlan = function (int $clinic, int $patient, string $status) use ($insPlan, $professionalId): void {
    $insPlan->execute(['c' => $clinic, 'p' => $patient, 'pr' => $professionalId, 't' => 'Plano revisão ' . $status, 's' => $status]);
};

$multi = $mkPatient($clinicId, 'Paciente Três Rascunhos');
$single = $mkPatient($clinicId, 'Paciente Um Rascunho');
$published = $mkPatient($clinicId, 'Paciente Publicado');
for ($i = 0; $i < 3; $i++) { $mkPlan($clinicId, $multi, 'draft'); }
$mkPlan($clinicId, $single, 'draft');
$mkPlan($clinicId, $published, 'published');
$foreign = $mkPatient($otherClinicId, 'Paciente Outra Clínica');
$mkPlan($otherClinicId, $foreign, 'draft');

echo "[1] Deduplicação por paciente e definição única de contagem\n";
$summary = new DraftPlanSummary($db);
$totals = $summary->totals($clinicId);
check($totals['patients'] === 2, 'Card conta pacientes com rascunho (2), não linhas de plano');
check($totals['draft_plans'] === 4, 'Total complementar de rascunhos = 4 (sem vazar outra clínica)');

TenantContext::set($clinicId, ['id' => 1, 'role' => 'nutritionist', 'name' => 'Revisor']);
$items = (new AppointmentService($db))->getAttentionRequired();
$draftItems = array_values(array_filter($items, fn($i) => $i['type'] === 'draft_plan'));
check(count($draftItems) === 2, 'Lista de atenção traz um item por paciente (2), não um por plano (4)');
check(count($draftItems) === $totals['patients'], 'Contador do card e itens de rascunho da lista usam a mesma definição');
$multiItem = current(array_filter($draftItems, fn($i) => (int)$i['patient_id'] === $multi)) ?: [];
check(str_contains($multiItem['description'] ?? '', '3 planos em rascunho'), 'Item agrupado informa "3 planos em rascunho"');
check(($multiItem['action_url'] ?? '') === '/planos?status=draft&paciente=' . $multi, 'Paciente com vários rascunhos aponta para /planos filtrado por paciente');
$singleItem = current(array_filter($draftItems, fn($i) => (int)$i['patient_id'] === $single)) ?: [];
check(preg_match('#^/planos/\d+$#', $singleItem['action_url'] ?? '') === 1, 'Paciente com um rascunho aponta para a revisão do plano');
check(!array_filter($items, fn($i) => str_contains((string)$i['title'], 'faltoso')), 'Rótulo "Paciente faltoso" não é mais usado');

echo "[2] Dashboard renderizado: card, lista limitada e links\n";
$_SESSION['user_id'] = 0; // sem onboarding nesta clínica de teste
$_SESSION['clinic_id'] = $clinicId;
$_SERVER['REQUEST_METHOD'] = 'GET';
ob_start();
(new \ClyoNutri\Application\Controllers\DashboardController())->index();
$html = (string)ob_get_clean();
preg_match('#data-testid="draft-patients-count">(\d+)<#', $html, $m);
check((int)($m[1] ?? -1) === 2, 'Card "Planos a revisar" exibe 2 pacientes');
check(substr_count($html, 'data-type="draft_plan"') === 2, 'HTML exibe 2 itens de rascunho');
check(substr_count($html, 'data-testid="attention-item"') <= 5, 'Lista de atenção limitada a no máximo 5 itens');
check(str_contains($html, 'href="/planos?status=draft"'), 'Há link para /planos?status=draft');
check(str_contains($html, 'href="/pacientes?novo=1"'), '"Novo Paciente" aponta para o fluxo de criação');
check(!str_contains($html, 'Carteira regular') && !str_contains($html, 'em tempo real'), 'Badge fixo e "em tempo real" removidos');
check(!str_contains($html, '➔') && !str_contains($html, '<h3>'), 'Sem seta decorativa textual e títulos de seção em h2');

echo "[3] Filtro /planos?status=draft&paciente=N\n";
$_GET = ['status' => 'draft', 'paciente' => (string)$multi];
ob_start();
(new \ClyoNutri\Application\Controllers\DashboardController())->mealPlans();
$plansHtml = (string)ob_get_clean();
$_GET = [];
check(substr_count($plansHtml, 'Plano revisão draft') === 3 && !str_contains($plansHtml, 'Plano revisão published'), 'Filtro retorna apenas os 3 rascunhos do paciente');
$_GET = ['status' => "draft' OR 1=1 --"];
ob_start();
(new \ClyoNutri\Application\Controllers\DashboardController())->mealPlans();
$plansAll = (string)ob_get_clean();
$_GET = [];
check(substr_count($plansAll, 'Plano revisão') === 5, 'Sem filtro, /planos lista todos os 5 planos da clínica (inclusive sem filtro de paciente)');
check(str_contains($plansAll, "Plano revisão published"), 'Status fora da lista branca é ignorado (sem filtro)');

// --- Logout via HTTP real ------------------------------------------------------
echo "[4] Logout exige POST + CSRF\n";
$port = random_int(20000, 40000);
$root = realpath(__DIR__ . '/..');
$proc = proc_open(['php', '-S', "127.0.0.1:{$port}", '-t', $root . '/public', $root . '/public/index.php'], [1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']], $pipes, $root);
$base = "http://127.0.0.1:{$port}";
for ($i = 0; $i < 50 && !@fsockopen('127.0.0.1', $port); $i++) { usleep(100000); }
$cookie = '';
$http = function (string $method, string $path, array $data = []) use (&$cookie, $base): array {
    $headers = "Content-Type: application/x-www-form-urlencoded\r\n" . ($cookie ? "Cookie: {$cookie}\r\n" : '');
    $ctx = stream_context_create(['http' => ['method' => $method, 'header' => $headers, 'content' => http_build_query($data), 'follow_location' => 0, 'ignore_errors' => true]]);
    $body = (string)@file_get_contents($base . $path, false, $ctx);
    $status = 0; $location = null;
    foreach ($http_response_header ?? [] as $h) {
        if (preg_match('#^HTTP/\S+ (\d+)#', $h, $mm)) { $status = (int)$mm[1]; }
        if (stripos($h, 'Location:') === 0) { $location = trim(substr($h, 9)); }
        if (stripos($h, 'Set-Cookie:') === 0) { $cookie = explode(';', trim(substr($h, 11)))[0]; }
    }
    return ['status' => $status, 'location' => $location, 'body' => $body];
};
$token = fn(string $html) => preg_match('#name="_csrf_token" value="([^"]+)"#', $html, $t) ? html_entity_decode($t[1]) : '';
$login = $http('GET', '/login');
$res = $http('POST', '/login', ['_csrf_token' => $token($login['body']), 'email' => 'demo.local@clyonutri.local', 'password' => 'DevLocalPass@2026']);
check($res['status'] === 302, 'Login demo no banco de testes (302)');
$dash = $http('GET', '/dashboard');
check($dash['status'] === 200 && str_contains($dash['body'], 'data-testid="logout-form"') && str_contains($dash['body'], 'method="POST" action="/logout"'), 'Shell renderiza logout como formulário POST com CSRF');
$get = $http('GET', '/logout');
check($get['status'] === 405, 'GET /logout responde 405 (não encerra sessão)');
check($http('GET', '/dashboard')['status'] === 200, 'Sessão continua ativa após GET /logout');
$noCsrf = $http('POST', '/logout');
check($noCsrf['status'] === 403, 'POST /logout sem CSRF é bloqueado (403)');
check($http('GET', '/dashboard')['status'] === 200, 'Sessão continua ativa após POST sem CSRF');
$ok = $http('POST', '/logout', ['_csrf_token' => $token($get['body'])]);
check($ok['status'] === 302 && $ok['location'] === '/login', 'POST /logout com CSRF encerra sessão e redireciona para /login');
$after = $http('GET', '/dashboard');
check($after['status'] === 302 && $after['location'] === '/login', 'Após logout o dashboard exige login');
proc_terminate($proc);
proc_close($proc);

// --- Limpeza da fixture (somente banco de testes) ------------------------------
$db->prepare('DELETE FROM meal_plans WHERE patient_id IN (:a, :b, :c, :d)')->execute(['a' => $multi, 'b' => $single, 'c' => $published, 'd' => $foreign]);
$db->prepare('DELETE FROM patients WHERE id IN (:a, :b, :c, :d)')->execute(['a' => $multi, 'b' => $single, 'c' => $published, 'd' => $foreign]);
$db->prepare('DELETE FROM clinics WHERE id = :id')->execute(['id' => $clinicId]);

echo "\nResultado: {$passed} passaram, {$failed} falharam\n";
exit($failed === 0 ? 0 : 1);
