<?php
// v54.57: correções da auditoria UX/UI 360° (v54.56). Testes positivos, negativos e de regressão por achado,
// pela camada HTTP real (php -S + roteador de teste), em bancos SQLite descartáveis com dados TESTE.
declare(strict_types=1);
date_default_timezone_set('America/Sao_Paulo'); // o app grava horários de consulta no fuso do consultório
$root = dirname(__DIR__); $fail = 0; $n = 0;
$ok = function ($c, $m) use (&$fail, &$n) { $n++; echo ($c ? '  PASS  ' : '  FAIL  ') . $m . "\n"; if (!$c) $fail++; };
putenv('CLINICAL_ENCRYPTION_KEY=test-clinical-key-v5445-not-for-prod');
$_ENV['CLINICAL_ENCRYPTION_KEY'] = $_SERVER['CLINICAL_ENCRYPTION_KEY'] = 'test-clinical-key-v5445-not-for-prod';
require_once $root . '/vendor/autoload.php';
use ClyoNutri\Core\Security\ClinicalEncryptionService as Enc;

$procs = []; $files = [];
/** Sobe um servidor local para um cenário de seed; devolve [$pdo, $req]. */
$server = function (string $scenario) use ($root, $ok, &$procs, &$files): array {
    $db = sys_get_temp_dir() . '/v5457-' . $scenario . '-' . getmypid() . '.sqlite';
    exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg("$root/tests/e2e/lib/seed.php") . ' ' . escapeshellarg($db) . ' ' . $scenario . ' 2>&1', $o, $rc);
    $ok($rc === 0, "seed TESTE ($scenario)");
    $pdo = new PDO('sqlite:' . $db); $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION); $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
    $s = stream_socket_server('tcp://127.0.0.1:0'); $port = (int)substr(strrchr(stream_socket_get_name($s, false), ':'), 1); fclose($s);
    $env = "DB_CONNECTION=sqlite DB_DATABASE=" . escapeshellarg($db) . " APP_ENV=local APP_URL=http://127.0.0.1:$port APP_KEY=base64:dGVzdGVrZXl0ZXN0ZWtleXRlc3Rla2V5dGVzdGVrZXk= MAIL_DRIVER=log APP_TIMEZONE=America/Sao_Paulo CLINICAL_ENCRYPTION_KEY=test-clinical-key-v5445-not-for-prod";
    $procs[] = proc_open("cd " . escapeshellarg($root) . " && exec env $env " . escapeshellarg(PHP_BINARY) . " -d opcache.enable=0 -S 127.0.0.1:$port -t public tests/_v54_router.php", [1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']], $pp);
    for ($i = 0; $i < 50 && !@fsockopen('127.0.0.1', $port); $i++) usleep(100000);
    $jar = tempnam(sys_get_temp_dir(), 'jar'); $files[] = $db; $files[] = $jar;
    $req = function (string $path, ?array $post = null, array $headers = []) use ($port, &$jar) {
        $c = curl_init("http://127.0.0.1:$port$path");
        curl_setopt_array($c, [CURLOPT_RETURNTRANSFER => true, CURLOPT_HEADER => true, CURLOPT_COOKIEJAR => $jar, CURLOPT_COOKIEFILE => $jar, CURLOPT_HTTPHEADER => $headers]);
        if ($post !== null) { curl_setopt($c, CURLOPT_POST, true); curl_setopt($c, CURLOPT_POSTFIELDS, http_build_query($post)); }
        $r = (string)curl_exec($c); $code = curl_getinfo($c, CURLINFO_HTTP_CODE); $hs = curl_getinfo($c, CURLINFO_HEADER_SIZE); curl_close($c);
        return [$code, substr($r, $hs), substr($r, 0, $hs)];
    };
    return [$pdo, $req];
};
$csrf = fn(string $b) => preg_match('/name="_csrf_token" value="([^"]+)"/', $b, $m) ? html_entity_decode($m[1]) : '';
$loc = fn(string $h) => preg_match('/^Location:\s*(\S+)/mi', $h, $m) ? $m[1] : '';
$hidden = fn(string $b, string $name) => preg_match('/name="' . preg_quote($name, '/') . '" value="([^"]*)"/', $b, $m) ? html_entity_decode($m[1]) : '';

try {
    // =====================================================================================================
    echo "=== UX-103: rascunho cifrado corrompido não trava a anamnese ===\n";
    [$pdo, $req] = $server('jornadas');
    $req('/__teste_login?u=1&c=1&v=0&to=/dashboard');
    // rascunho v2 com um campo legível e um campo corrompido (payload enc:v1 inválido)
    $pdo->prepare("UPDATE anamneses SET routine = ?, objectives = 'enc:v1:AAAAcorrompidoTESTE' WHERE patient_id = 2 AND is_draft = 1")->execute([Enc::encrypt('ROTINA LEGIVEL TESTE')]);
    $before = $pdo->query("SELECT objectives FROM anamneses WHERE patient_id = 2 AND is_draft = 1")->fetchColumn();
    [$c, $b] = $req('/pacientes/2/anamnese');
    $ok($c === 200, "anamnese abre (HTTP $c) com o rascunho corrompido");
    $ok(str_contains($b, 'data-testid="anamnese-rascunho-ilegivel"') && str_contains($b, 'Objetivos'), 'aviso claro nomeia o campo ilegível');
    $ok(str_contains($b, 'ROTINA LEGIVEL TESTE'), 'o campo legível do rascunho continua na tela');
    $ok(str_contains($b, 'href="/agenda"') || str_contains($b, 'href="/pacientes"'), 'tela dentro do layout, com navegação');
    $ok(Enc::unreadableAnamnesisFields(['objectives' => 'enc:v1:AAAA', 'routine' => Enc::encrypt('x')]) === ['objectives'], 'detector aponta só o campo corrompido');
    $row = Enc::decryptAnamnesisRow(['id' => 9, 'objectives' => 'enc:v1:AAAA', 'routine' => Enc::encrypt('ok')]);
    $ok($row['objectives'] === null && $row['routine'] === 'ok' && $row['_unreadable_fields'] === ['objectives'], 'decryptAnamnesisRow não lança: campo ilegível vira vazio e é marcado');
    $threw = false; try { Enc::decrypt('enc:v1:AAAA'); } catch (\RuntimeException $e) { $threw = true; }
    $ok($threw, 'regressão: decrypt() de um valor isolado continua recusando payload corrompido');
    // negativo: salvar por cima do rascunho ilegível não grava nada
    [$c, $b2, $h] = $req('/pacientes/2/anamnese', ['_csrf_token' => $csrf($b), 'save_mode' => 'draft', 'objectives' => 'sobrescrita TESTE',
        '_workflow_revision' => $hidden($b, '_workflow_revision'), '_final_request_key' => bin2hex(random_bytes(16))]);
    $ok(str_contains($loc($h), 'error=rascunho_ilegivel'), 'salvar por cima do rascunho ilegível é recusado com mensagem (' . substr($loc($h), 0, 60) . ')');
    $ok($pdo->query("SELECT objectives FROM anamneses WHERE patient_id = 2 AND is_draft = 1")->fetchColumn() === $before, 'conteúdo cifrado original preservado (nada sobrescrito)');
    // negativo: sem CSRF
    [$c, $b2, $h] = $req('/pacientes/2/anamnese/recuperar-rascunho', ['_csrf_token' => 'x']);
    $ok($c === 403 && $pdo->query("SELECT objectives FROM anamneses WHERE patient_id = 2 AND is_draft = 1")->fetchColumn() === $before, "recuperar sem CSRF: recusado (HTTP $c), nada alterado");
    // positivo
    [$c, $b2, $h] = $req('/pacientes/2/anamnese/recuperar-rascunho', ['_csrf_token' => $csrf($b)]);
    $ok(str_contains($loc($h), 'success=rascunho_recuperado'), 'recuperar com CSRF: sucesso');
    $d = $pdo->query("SELECT objectives, routine FROM anamneses WHERE patient_id = 2 AND is_draft = 1")->fetch();
    $ok($d['objectives'] === null && Enc::decrypt($d['routine']) === 'ROTINA LEGIVEL TESTE', 'só o campo corrompido foi esvaziado; o legível foi mantido');
    $v1 = $pdo->query("SELECT allergies FROM anamneses WHERE patient_id = 2 AND is_draft = 0")->fetchColumn();
    $ok(Enc::decrypt($v1) === 'amendoim', 'versão concluída v1 intacta');
    $aud = $pdo->query("SELECT details FROM audit_logs WHERE action = 'ANAMNESIS_DRAFT_UNREADABLE_DISCARDED' ORDER BY id DESC LIMIT 1")->fetchColumn();
    $aj = json_decode((string)$aud, true);
    $ok(($aj['quarantine']['objectives']['ciphertext'] ?? '') === $before && ($aj['fields'] ?? []) === ['objectives'], 'auditoria guarda o conteúdo cifrado descartado e os campos');
    [$c, $b] = $req('/pacientes/2/anamnese');
    $ok($c === 200 && !str_contains($b, 'data-testid="anamnese-rascunho-ilegivel"'), 'depois da recuperação o aviso some');
    [$c, $b2, $h] = $req('/pacientes/2/anamnese/recuperar-rascunho', ['_csrf_token' => $csrf($b)]);
    $ok($pdo->query("SELECT COUNT(*) FROM audit_logs WHERE action = 'ANAMNESIS_DRAFT_UNREADABLE_DISCARDED'")->fetchColumn() == 1, 'recuperar de novo (rascunho íntegro) não descarta nada');
    // negativo: paciente de outra clínica
    $pdo->prepare("INSERT INTO patients (clinic_id, uuid, name, birth_date, gender, status) VALUES (2, ?, 'Paciente TESTE B 103', '1991-01-01', 'F', 'active')")->execute([bin2hex(random_bytes(8))]);
    $pB = (int)$pdo->lastInsertId();
    [$c] = $req("/pacientes/$pB/anamnese/recuperar-rascunho", ['_csrf_token' => $csrf($b)]);
    $ok($c === 404, "recuperar rascunho de paciente de outra clínica: 404 (HTTP $c)");

    // =====================================================================================================
    echo "=== UX-102: portal web do paciente com sessão real, plano visível e consultas sem JSON 401 ===\n";
    $H = ['Accept: text/html,application/xhtml+xml'];
    [$c, $b] = $req('/paciente/app', null, $H);
    [$c, $b2, $h] = $req('/paciente/app/login', ['_csrf_token' => $csrf($b), 'login' => 'paciente.teste@teste.invalid', 'password' => 'Teste-Paciente-123'], $H);
    $ok($c === 303 && $loc($h) === '/paciente/app', "login web do portal: PRG para /paciente/app (HTTP $c)");
    $ok(str_contains($h, 'clyonutri_portal=') && str_contains($h, 'HttpOnly'), 'login web grava o cookie HttpOnly do portal');
    [$c, $b] = $req('/paciente/app', null, $H);
    $ok($c === 200 && str_contains($b, 'data-testid="portal-card-plano"') && str_contains($b, 'href="/paciente/plano"'), 'área logada: cartão "Plano alimentar" é link real');
    $ok(str_contains($b, 'href="/paciente/consultas"') && str_contains($b, 'data-testid="portal-agua"'), 'área logada: consultas e registro de água');
    $ok(!str_contains($b, 'Chat com o nutricionista'), 'sem cartão falso (chat) no portal web');
    [$c, $bp] = $req('/paciente/plano', null, $H);
    $ok($c === 200 && str_contains($bp, 'data-testid="portal-plano"') && !str_contains($bp, 'ainda não publicou'), "plano publicado aparece em leitura (HTTP $c)");
    [$c, $bc, $hc] = $req('/paciente/consultas', null, $H);
    $ok($c === 200 && str_contains($bc, 'Minhas consultas') && !str_contains($bc, 'Token Bearer'), "Minhas consultas abre a página, sem JSON 401 (HTTP $c)");
    [$c, $bj] = $req('/api/v1/patient/appointments', null, ['Accept: application/json']);
    $ok($c === 200 && is_array(json_decode($bj, true)), 'a página consegue a lista pela API com o cookie da sessão');
    $w0 = (int)$pdo->query("SELECT COALESCE(SUM(amount_ml),0) FROM hydration_logs WHERE patient_id = 2 AND deleted_at IS NULL")->fetchColumn();
    [$c, $x, $h] = $req('/paciente/hidratacao', ['_csrf_token' => $csrf($b), 'amount_ml' => '300', 'idempotency_key' => str_repeat('a', 32)], $H);
    $w1 = (int)$pdo->query("SELECT COALESCE(SUM(amount_ml),0) FROM hydration_logs WHERE patient_id = 2 AND deleted_at IS NULL")->fetchColumn();
    $ok(str_contains($loc($h), 'agua=ok') && $w1 === $w0 + 300, "registrar 300 ml pelo portal grava de verdade ($w0 → $w1)");
    $req('/paciente/hidratacao', ['_csrf_token' => $csrf($b), 'amount_ml' => '300', 'idempotency_key' => str_repeat('a', 32)], $H);
    $ok((int)$pdo->query("SELECT COALESCE(SUM(amount_ml),0) FROM hydration_logs WHERE patient_id = 2 AND deleted_at IS NULL")->fetchColumn() === $w1, 'reenvio com a mesma chave não duplica (idempotente)');
    [$c, $x, $h] = $req('/paciente/hidratacao', ['_csrf_token' => $csrf($b), 'amount_ml' => '-5'], $H);
    $ok(str_contains($loc($h), 'agua=erro'), 'quantidade inválida: recusada com mensagem');
    [$c] = $req('/paciente/hidratacao', ['_csrf_token' => 'x', 'amount_ml' => '200'], $H);
    $ok($c === 403, "registrar água sem CSRF: 403 (HTTP $c)");
    $cid = (int)$pdo->query("SELECT clinic_id FROM hydration_logs WHERE patient_id = 2 ORDER BY id DESC LIMIT 1")->fetchColumn();
    $ok($cid === (int)$pdo->query("SELECT clinic_id FROM patients WHERE id = 2")->fetchColumn(), 'registro fica na clínica da paciente');
    [$c, $x, $h] = $req('/paciente/app/sair', ['_csrf_token' => $csrf($b)], $H);
    $ok($c === 303 && $pdo->query("SELECT COUNT(*) FROM personal_access_tokens WHERE tokenable_type='patient' AND tokenable_id=2 AND name='mobile_web'")->fetchColumn() == 0, 'sair revoga o token do portal');
    [$c, $x, $h] = $req('/paciente/consultas', null, $H);
    $ok($c === 303 && str_contains($loc($h), '/paciente/app?sessao=1'), 'sem sessão, a página do portal volta ao login com aviso (não JSON)');
    [$c, $bj] = $req('/paciente/consultas', null, ['Accept: application/json']);
    $ok($c === 401 && is_array(json_decode($bj, true)), 'regressão: cliente JSON continua recebendo 401 JSON');
    [$c, $bj] = $req('/api/v1/patient/home', null, ['Accept: text/html']);
    $ok($c === 401 && is_array(json_decode($bj, true)), 'regressão: a API /api/v1 continua JSON mesmo com Accept text/html');
    [$c, $b] = $req('/paciente/app?sessao=1', null, $H);
    $ok(str_contains($b, 'Entre no portal para ver esta página'), 'login do portal explica por que voltou');
    [$c] = $req('/paciente/plano', null, $H);
    $ok($c === 303, 'plano sem sessão: volta ao login');

    // =====================================================================================================
    echo "=== UX-111 / R55-01 / UX-104: páginas de erro HTML para navegação; JSON para API/XHR ===\n";
    $req('/__teste_login?u=1&c=1&v=0&to=/dashboard');
    [$c, $b] = $req('/rota-que-nao-existe-v5457', null, $H);
    $ok($c === 404 && str_contains($b, 'data-testid="error-page"') && str_contains($b, '<html lang="pt-BR"') && str_contains($b, '<title>'), "404 no navegador: página com idioma e título (HTTP $c)");
    $ok(str_contains($b, 'clinical-navigation') && str_contains($b, 'href="/dashboard"'), '404 logado: dentro do layout do consultório, com caminho de volta');
    [$c, $b] = $req('/rota-que-nao-existe-v5457', null, ['Accept: application/json']);
    $ok($c === 404 && (json_decode($b, true)['error'] ?? '') === 'Rota não encontrada', 'regressão: 404 para JSON continua JSON');
    [$c, $b] = $req('/rota-que-nao-existe-v5457', null, ['Accept: text/html', 'X-Requested-With: XMLHttpRequest']);
    $ok($c === 404 && is_array(json_decode($b, true)), 'regressão: 404 para XHR continua JSON');
    [$c, $b] = $req('/pacientes/2/pre-consulta?atendimento=999999', null, $H);
    $ok($c === 403 && str_contains($b, 'data-testid="error-page"') && str_contains($b, 'Atendimento não disponível') && str_contains($b, 'href="/agenda"'), "R55-01: atendimento inválido → 403 com layout e saída (HTTP $c)");
    [$c, $b] = $req('/pacientes/2/pre-consulta?atendimento=999999', null, ['Accept: application/json']);
    $ok($c === 403 && is_array(json_decode($b, true)), 'R55-01 regressão: cliente JSON recebe JSON');
    $req('/__teste_logout_inexistente');
    [$pdo2, $req2] = $server('homologacao');
    $rec = $pdo2->query("SELECT u.id AS uid, m.clinic_id AS cid, u.auth_version AS av FROM users u JOIN clinic_members m ON m.user_id = u.id WHERE u.email = 'recepcao.homolog.a@teste.invalid'")->fetch();
    $pat = (int)$pdo2->query("SELECT id FROM patients WHERE clinic_id = " . (int)$rec['cid'] . " ORDER BY id LIMIT 1")->fetchColumn();
    $req2('/__teste_login?u=' . (int)$rec['uid'] . '&c=' . (int)$rec['cid'] . '&v=' . (int)$rec['av'] . '&to=/agenda');
    [$c, $b] = $req2('/agenda', null, $H);
    $ok($c === 200, "recepção abre a agenda (HTTP $c)");
    preg_match('~<aside class="app-sidebar">.*?</aside>~s', $b, $m); $aside = $m[0] ?? '';
    $ok($aside !== '' && !str_contains($aside, 'href="/planos"') && !str_contains($aside, 'href="/dashboard"') && !str_contains($aside, 'href="/alimentos"'), 'menu da recepção sem links para rotas proibidas (/planos, /dashboard, /alimentos)');
    $ok(str_contains($aside, 'href="/agenda"') && str_contains($aside, 'href="/pacientes"') && str_contains($aside, 'href="/financeiro"'), 'menu da recepção mantém agenda, pacientes e financeiro');
    [$c, $b] = $req2('/pacientes', null, $H);
    $pname = (string)$pdo2->query("SELECT name FROM patients WHERE id = $pat")->fetchColumn();
    $ok($c === 200 && str_contains($b, htmlspecialchars($pname)) && !preg_match('~href="/pacientes/' . $pat . '(?:[/?"])~', $b), 'lista de pacientes da recepção mostra o nome e não leva ao perfil clínico (proibido)');
    [$c, $b] = $req2("/pacientes/$pat/anamnese", null, $H);
    $ok($c === 403 && str_contains($b, 'data-testid="error-page"') && str_contains($b, 'app-sidebar') && str_contains($b, 'href="/agenda"'), "recepção na anamnese: 403 em HTML com layout e \"Ir para a agenda\" (HTTP $c)");
    $ok(!str_contains($b, '"error"') && !str_contains($b, 'href="/dashboard"'), 'página 403 da recepção não oferece destino proibido');
    [$c, $b] = $req2("/pacientes/$pat/anamnese", null, ['Accept: application/json']);
    $ok($c === 403 && (json_decode($b, true)['error'] ?? '') === 'Acesso não autorizado para este perfil.', 'regressão: 403 JSON para cliente JSON');
    $ok($pdo2->query("SELECT COUNT(*) FROM audit_logs WHERE action = 'SECURITY_AUTHORIZATION_BLOCKED'")->fetchColumn() >= 2, 'bloqueio continua auditado');
    [$c, $b] = $req2('/planos', null, $H);
    $ok($c === 403, "recepção em /planos continua bloqueada (HTTP $c)");
    $ok(\ClyoNutri\Core\Security\ClinicalAuthorization::allowsWeb(['user_role' => 'nutritionist', 'member_role' => 'owner'], '/planos', 'GET')
        && !\ClyoNutri\Core\Security\ClinicalAuthorization::allowsWeb(['user_role' => 'receptionist', 'member_role' => 'receptionist'], '/planos', 'GET')
        && \ClyoNutri\Core\Security\ClinicalAuthorization::allowsWeb(['user_role' => 'receptionist', 'member_role' => 'receptionist'], '/agenda', 'GET')
        && !\ClyoNutri\Core\Security\ClinicalAuthorization::allowsWeb(['user_role' => 'nutritionist', 'member_role' => 'owner'], '/admin', 'GET'), 'allowsWeb reproduz a regra do servidor (clínico, recepção, /admin)');
    // =====================================================================================================
    echo "=== UX-101 / UX-119 / UX-105: registro feito no atendimento conta como etapa; faixa com data/hora e etapas ===\n";
    [$pdo, $req] = $server('jornadas');
    $req('/__teste_login?u=1&c=1&v=0&to=/dashboard');
    $when = date('Y-m-d H:i:00', time() - 600);
    $pdo->prepare("INSERT INTO appointments (clinic_id, patient_id, professional_id, scheduled_at, duration_minutes, status, type) VALUES (1, 2, 1, ?, 60, 'in_progress', 'follow_up')")->execute([$when]);
    $aid = (int)$pdo->lastInsertId();
    [$c, $b] = $req("/pacientes/2/antropometria?atendimento=$aid");
    $label = "Consulta #$aid · " . \ClyoNutri\Core\I18n\LabelMap::appointmentType('follow_up') . ' · ' . date('d/m/Y', strtotime($when)) . ' às ' . date('H:i', strtotime($when));
    $ok($c === 200 && str_contains($b, $label), "UX-119: contexto mostra tipo, data e hora da consulta ($label)");
    $ok(str_contains($b, 'data-testid="attendance-steps"') && preg_match('~data-step="anamnese"~', $b) && preg_match('~data-step="plano"~', $b), 'UX-105: etapas do atendimento navegáveis na tela da avaliação');
    $ok((bool)preg_match('~href="[^"]*/antropometria\?atendimento=' . $aid . '"[^>]*data-step="avaliacao"[^>]*aria-current="step"~', $b), 'UX-105: etapa atual marcada (aria-current) e links preservam ?atendimento=');
    $ok(!str_contains($b, 'contexto selecionado (agendada para'), 'regressão UX-119: texto antigo sem data/hora não aparece');
    $evt = fn() => (int)$pdo->query("SELECT COUNT(*) FROM care_progress_events WHERE appointment_id = $aid AND step_key = 'avaliacao' AND decision = 'confirm'")->fetchColumn();
    $ok($evt() === 0, 'antes de salvar: nenhuma etapa confirmada');
    preg_match('~<form[^>]*action="([^"]*antropometria[^"]*)"[^>]*method="post"|<form[^>]*method="post"[^>]*action="([^"]*antropometria[^"]*)"~i', $b, $fm);
    $action = html_entity_decode(($fm[1] ?? '') ?: ($fm[2] ?? "/pacientes/2/antropometria?atendimento=$aid"));
    $ok(str_contains($action, "atendimento=$aid"), "formulário da avaliação envia o contexto da consulta ($action)");
    [$c, $b2, $h] = $req($action, ['_csrf_token' => $csrf($b), 'weight_kg' => '70.5', 'height_cm' => '164', 'atendimento' => (string)$aid]);
    $ok($evt() === 1, 'UX-101: avaliação salva no atendimento em andamento vira etapa concluída (evento confirm)');
    [$c, $b] = $req("/consulta/$aid");
    $ok(str_contains($b, 'data-testid="care-flow-summary"') && (bool)preg_match('~Avaliação ✓~u', $b), 'UX-101: sala do atendimento mostra Avaliação ✓ sem "revisar pendências"');
    // negativo: salvar sem contexto de consulta não confirma nada de outra consulta
    $pdo->prepare("INSERT INTO appointments (clinic_id, patient_id, professional_id, scheduled_at, duration_minutes, status, type) VALUES (1, 2, 1, ?, 60, 'scheduled', 'follow_up')")->execute([date('Y-m-d H:i:00', time() + 86400 * 3)]);
    $fut = (int)$pdo->lastInsertId();
    [$c, $b] = $req("/pacientes/2/antropometria?atendimento=$fut");
    [$c, $b2] = $req("/pacientes/2/antropometria?atendimento=$fut", ['_csrf_token' => $csrf($b), 'weight_kg' => '70.1', 'height_cm' => '164', 'atendimento' => (string)$fut]);
    $ok((int)$pdo->query("SELECT COUNT(*) FROM care_progress_events WHERE appointment_id = $fut")->fetchColumn() === 0, 'negativo UX-101: consulta apenas agendada (não iniciada) não recebe confirmação automática');
    $ok($evt() === 1, 'negativo UX-101: salvar fora do atendimento em andamento não duplica a confirmação');
    // negativo: tenant — clínica B não confirma etapa no atendimento da A
    $cp = new \ClyoNutri\Domain\Appointment\CareProgress($pdo);
    $threw = false; try { $cp->confirmFromAttendance(2, 2, $aid, ['anamnese']); } catch (\Throwable $e) { $threw = true; }
    $ok($threw && (int)$pdo->query("SELECT COUNT(*) FROM care_progress_events WHERE appointment_id = $aid AND step_key='anamnese'")->fetchColumn() === 0, 'negativo UX-101: outra clínica não confirma etapas desta consulta');
    // regressão: consulta concluída não ganha etapas
    $pdo->exec("UPDATE appointments SET status = 'completed' WHERE id = $aid");
    \ClyoNutri\Core\Auth\TenantContext::set(1, ['id' => 1, 'role' => 'nutritionist']);
    $ok($cp->confirmFromAttendance(1, 2, $aid, ['anamnese', 'plano']) === [], 'regressão UX-101: consulta concluída não recebe confirmações');

    // =====================================================================================================
    echo "=== UX-106: datas e horas no fuso do consultório ===\n";
    $L = \ClyoNutri\Core\I18n\LocalTime::class;
    $ok($L::fromUtc('2026-11-11 01:33:45 UTC') === '10/11/2026 às 22:33', 'UTC gravado (gmdate) aparece em horário de Brasília (01:33 UTC → 22:33)');
    $ok($L::local('2026-11-10 22:33:45') === '10/11/2026 às 22:33', 'horário local gravado só muda de formato');
    $ok($L::local('10/11/2026 às 22:33') === '10/11/2026 às 22:33', 'negativo: valor já formatado não é reinterpretado (sem dd/mm virar mm/dd)');
    $ok($L::local('') === '' && $L::fromUtc(null) === '', 'vazio continua vazio');
    $ok(str_contains(\ClyoNutri\Application\View\OperationalUI::state('saved', '2026-11-10 22:33:45'), '10/11/2026 às 22:33') && !str_contains(\ClyoNutri\Application\View\OperationalUI::state('saved', '2026-11-10 22:33:45'), '2026-11-10'), 'estado "Salvo como rascunho" mostra dd/mm/aaaa hh:mm, não o valor cru do banco');
    $now = time();
    $pdo->prepare("INSERT INTO patients (clinic_id, uuid, name, birth_date, gender, status) VALUES (1, ?, 'Paciente TESTE convite 106', '1990-01-01', 'F', 'active')")->execute([bin2hex(random_bytes(8))]);
    $pI = (int)$pdo->lastInsertId();
    [$c, $b] = $req("/pacientes/$pI");
    [$c, $b, $h] = $req("/pacientes/$pI/convite-app", ['_csrf_token' => $csrf($b)]);
    $exp = $pdo->query("SELECT expires_at FROM patient_app_invitations WHERE patient_id = $pI ORDER BY id DESC LIMIT 1")->fetchColumn();
    $ok($exp !== false, 'convite gerado pela tela (gravado em UTC, regra de expiração inalterada)');
    $want = (new DateTimeImmutable((string)$exp, new DateTimeZone('UTC')))->setTimezone(new DateTimeZone('America/Sao_Paulo'))->format('d/m/Y \à\s H:i');
    $ok(str_contains($b, "Válido até $want (horário de Brasília)"), "convite mostra a validade no horário de Brasília ($want)");
    $ok(!preg_match('~\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2} UTC~', $b), 'regressão: nenhum "AAAA-MM-DD hh:mm:ss UTC" cru na tela do convite');

    // =====================================================================================================
    echo "=== UX-108: alergia com uma fonte de verdade (anamnese concluída) ===\n";
    $pdo->exec("UPDATE patients SET allergies = 'cadastro antigo TESTE', allergy_assessment = 'recorded' WHERE id = 2");
    [$c, $b] = $req('/pacientes/2/editar');
    $ok($c === 200 && str_contains($b, 'data-testid="edit-allergy-source"') && str_contains($b, 'amendoim'), 'cadastro mostra a alergia da anamnese em leitura');
    $ok(str_contains($b, 'Origem: anamnese v1') && str_contains($b, 'href="/pacientes/2/anamnese"'), 'mostra a origem (versão) e o link "Editar na anamnese"');
    $ok(!str_contains($b, 'name="allergies"') && !str_contains($b, 'cadastro antigo TESTE'), 'sem campo paralelo de alergia e sem o valor divergente do cadastro');
    [$c, $b2, $h] = $req('/pacientes/2/editar', ['_csrf_token' => $csrf($b), 'name' => 'Paciente TESTE 108', 'allergies' => 'camarão (forjado)', 'allergy_denied' => '1']);
    $r = $pdo->query("SELECT name, allergies, allergy_assessment FROM patients WHERE id = 2")->fetch();
    $ok($r['name'] === 'Paciente TESTE 108', 'o resto do cadastro continua salvando');
    $ok(Enc::decrypt($r['allergies']) === 'cadastro antigo TESTE' && $r['allergy_assessment'] === 'recorded', 'negativo: POST forjado não grava alergia paralela nem "nega alergias" por cima da anamnese');
    [$c, $b] = $req('/pacientes/2');
    $ok(str_contains($b, 'amendoim'), 'selo/resumo do perfil continua vindo da anamnese');
    // sem anamnese concluída: o cadastro continua sendo onde se informa
    $pdo->prepare("INSERT INTO patients (clinic_id, uuid, name, birth_date, gender, status) VALUES (1, ?, 'Paciente TESTE sem anamnese', '1990-01-01', 'F', 'active')")->execute([bin2hex(random_bytes(8))]);
    $pN = (int)$pdo->lastInsertId();
    [$c, $b] = $req("/pacientes/$pN/editar");
    $ok(str_contains($b, 'name="allergies"') && !str_contains($b, 'data-testid="edit-allergy-source"'), 'regressão: sem anamnese o campo de alergia do cadastro continua editável');
    [$c, $b2] = $req("/pacientes/$pN/editar", ['_csrf_token' => $csrf($b), 'name' => 'Paciente TESTE sem anamnese', 'allergies' => 'glúten TESTE']);
    $ok(Enc::decrypt((string)$pdo->query("SELECT allergies FROM patients WHERE id = $pN")->fetchColumn()) === 'glúten TESTE', 'regressão: sem anamnese a alergia do cadastro é gravada');
    // tenant: outra clínica não abre
    $pdo->prepare("INSERT INTO patients (clinic_id, uuid, name, birth_date, gender, status) VALUES (2, ?, 'Paciente TESTE B 108', '1991-01-01', 'F', 'active')")->execute([bin2hex(random_bytes(8))]);
    $pB8 = (int)$pdo->lastInsertId();
    [$c] = $req("/pacientes/$pB8/editar");
    $ok(in_array($c, [403, 404], true), "negativo: cadastro de paciente de outra clínica (#$pB8) não abre (HTTP $c)");

    // =====================================================================================================
    echo "=== UX-114: altura da última avaliação pré-preenchida no retorno ===\n";
    $pdo->exec("DELETE FROM anthropometric_assessments WHERE patient_id IN (2, $pN)");
    $pdo->exec("INSERT INTO anthropometric_assessments (clinic_id, patient_id, professional_id, weight_kg, height_cm, bmi, assessed_at, created_at) VALUES (1, 2, 1, 70, 164.0, 26.0, '2026-10-01 10:00:00', '2026-10-01 10:00:00')");
    $pdo->exec("INSERT INTO anthropometric_assessments (clinic_id, patient_id, professional_id, weight_kg, height_cm, bmi, assessed_at, created_at) VALUES (1, 2, 1, 69, 165.5, 25.2, '2026-10-20 10:00:00', '2026-10-20 10:00:00')");
    [$c, $b] = $req('/pacientes/2/antropometria');
    $ok((bool)preg_match('~name="height_cm"[^>]*value="165.5"~', $b), '2ª avaliação: altura da última avaliação (165,5) vem preenchida');
    $ok(str_contains($b, 'data-testid="height-prefill-source"') && str_contains($b, 'Da avaliação de 20/10/2026'), 'campo rotulado com a avaliação de origem (20/10/2026), editável');
    $ok((bool)preg_match('~name="height_cm"[^>]*required~', $b) && !preg_match('~name="height_cm"[^>]*readonly~', $b), 'regressão: altura continua obrigatória e editável');
    [$c, $b] = $req('/pacientes/2');
    $ok((bool)preg_match('~name="height_cm"[^>]*value="165.5"~', $b), 'modal de avaliação do perfil também pré-preenche');
    $pdo->exec("INSERT INTO anthropometric_assessments (clinic_id, patient_id, professional_id, weight_kg, height_cm, bmi, assessed_at, created_at) VALUES (2, $pN, 2, 80, 199, 20, '2026-11-01 10:00:00', '2026-11-01 10:00:00')");
    [$c, $b] = $req("/pacientes/$pN/antropometria");
    $ok(!preg_match('~name="height_cm"[^>]*value=~', $b) && !str_contains($b, 'height-prefill-source'), 'negativo: sem avaliação na clínica, nada pré-preenchido (registro de outra clínica não vaza)');

    // =====================================================================================================
    echo "=== UX-124: último atendimento aparece depois de concluir ===\n";
    $pdo->exec("DELETE FROM appointments WHERE patient_id = $pN");
    [$c, $b] = $req("/pacientes/$pN");
    $ok((bool)preg_match('~data-testid="op-strip-last">—<~u', $b), 'negativo: sem consulta concluída, "—"');
    $pdo->prepare("INSERT INTO appointments (clinic_id, patient_id, professional_id, scheduled_at, duration_minutes, status, type) VALUES (1, ?, 1, '2026-11-05 14:00:00', 60, 'completed', 'follow_up'), (1, ?, 1, '2026-11-01 09:00:00', 60, 'completed', 'first_visit'), (1, ?, 1, '2026-11-08 10:00:00', 60, 'cancelled', 'follow_up')")->execute([$pN, $pN, $pN]);
    $pdo->prepare("INSERT INTO appointments (clinic_id, patient_id, professional_id, scheduled_at, duration_minutes, status, type) VALUES (2, ?, 2, '2026-11-09 10:00:00', 60, 'completed', 'follow_up')")->execute([$pN]);
    [$c, $b] = $req("/pacientes/$pN");
    $ok(str_contains($b, 'data-testid="op-strip-last">05/11/2026 14:00<'), 'UX-124: perfil mostra a última consulta concluída (05/11/2026 14:00), ignorando cancelada e registro de outra clínica');

    // =====================================================================================================
    echo "=== UX-115: comparar versões do plano a 1 clique na aba Plano ===\n";
    [$c, $b] = $req("/pacientes/$pN/plano");
    $ok($c === 200 && !str_contains($b, 'data-testid="plans-compare-link"'), 'negativo: sem 2 versões publicadas, sem o link');
    $pdo->prepare("INSERT INTO meal_plans (clinic_id, patient_id, professional_id, title, status, version) VALUES (1, ?, 1, 'Plano TESTE 115', 'active', 2)")->execute([$pN]);
    $mp = (int)$pdo->lastInsertId();
    $iv = $pdo->prepare("INSERT INTO meal_plan_versions (clinic_id, meal_plan_id, patient_id, professional_id, version_number, title, payload_json, status, published_at) VALUES (1, ?, ?, 1, ?, 'Plano TESTE 115', '{}', ?, ?)");
    $iv->execute([$mp, $pN, 1, 'superseded', '2026-10-01 10:00:00']);
    [$c, $b] = $req("/pacientes/$pN/plano");
    $ok(!str_contains($b, 'data-testid="plans-compare-link"'), 'negativo: com 1 versão publicada, sem o link');
    $iv->execute([$mp, $pN, 2, 'published', '2026-11-01 10:00:00']);
    [$c, $b] = $req("/pacientes/$pN/plano");
    $ok(str_contains($b, 'data-testid="plans-compare-link"') && str_contains($b, 'href="/pacientes/' . $pN . '/planos/comparar"'), 'UX-115: com 2 versões publicadas, "Comparar com a versão anterior" a 1 clique');
    [$c] = $req("/pacientes/$pN/planos/comparar");
    $ok($c === 200, "o destino do link abre (HTTP $c)");

    echo "=== UX-126 / UX-112: cabeçalho sem recurso desconhecido; erro de rede em pt-BR ===\n";
    [$c, $b, $h] = $req('/dashboard');
    $ok(preg_match('~^Permissions-Policy:\s*(.+)$~mi', $h, $pp) && !str_contains($pp[1], 'bluetooth') && str_contains($pp[1], 'geolocation=()') && str_contains($pp[1], 'usb=()'), 'UX-126: Permissions-Policy sem "bluetooth" e com as restrições mantidas');
    $js = (string)file_get_contents("$root/public/assets/js/operational-ui.js");
    $ok(str_contains($js, 'function netMessage') && str_contains($js, 'Sem conexão com a internet'), 'UX-112: tradução da falha de rede no helper único');
    foreach (['workflow-drafts.js', 'plan-live.js', 'quick-plan.js'] as $f) $ok(str_contains((string)file_get_contents("$root/public/assets/js/$f"), 'netMessage'), "UX-112: $f usa a mensagem em pt-BR");

    // =====================================================================================================
    echo "=== UX-113: sessão expirada ao salvar → entrar de novo volta à mesma tela ===\n";
    $js = (string)file_get_contents("$root/public/assets/js/workflow-drafts.js");
    $ok(str_contains($js, "'/login?error=session_expired&voltar='+encodeURIComponent(location.pathname+location.search)") && str_contains($js, 'relogin-link'), 'aviso de sessão expirada oferece "Entrar novamente" com a tela atual como retorno');
    [$pdo2, $req2] = $server('jornadas');
    [$c, $b] = $req2('/login?error=session_expired&voltar=' . rawurlencode('/pacientes/2/anamnese?atendimento=1'));
    $ok($c === 200 && str_contains($b, 'Sua sessão terminou'), 'login explica que a sessão terminou');
    [$c, $b2, $h] = $req2('/login', ['_csrf_token' => $csrf($b), 'email' => 'nutri.teste.a@teste.invalid', 'password' => 'Teste-Senha-123']);
    $ok($loc($h) === '/pacientes/2/anamnese?atendimento=1', 'UX-113: depois de entrar, volta para a anamnese com a consulta (' . $loc($h) . ')');
    [$pdo3, $req3] = $server('jornadas');
    [$c, $b] = $req3('/login?voltar=' . rawurlencode('//evil.example/x'));
    [$c, $b2, $h] = $req3('/login', ['_csrf_token' => $csrf($b), 'email' => 'nutri.teste.a@teste.invalid', 'password' => 'Teste-Senha-123']);
    $ok($loc($h) === '/dashboard', 'negativo: retorno para outro domínio é ignorado (' . $loc($h) . ')');

    // =====================================================================================================
    echo "=== UX-110 / UX-109: painel com ações de rotina no topo e sem indicadores contraditórios ===\n";
    [$pdo, $req] = $server('painel');
    $req('/__teste_login?u=1&c=1&v=0&to=/dashboard');
    [$c, $b] = $req('/dashboard');
    $top = strpos($b, 'data-testid="dash-top-actions"'); $metrics = strpos($b, 'data-testid="dash-metrics"');
    $ok($c === 200 && $top !== false && $metrics !== false && $top < $metrics, 'UX-110: ações no cabeçalho do painel, antes dos indicadores');
    $ok((bool)preg_match('~<form[^>]*method="GET"[^>]*action="/pacientes"[^>]*role="search"~', $b) && str_contains($b, 'name="q"') && str_contains($b, 'for="dash-patient-search"'), 'UX-110: busca de paciente (GET /pacientes?q=) com rótulo acessível');
    $ok(str_contains($b, 'href="/agenda?novo=1"') && str_contains($b, 'data-testid="dash-new-appointment"'), 'UX-110: botão primário "+ Nova consulta" abre o agendamento');
    $ok(str_contains($b, 'data-testid="quick-new-patient"'), 'regressão: ações rápidas continuam disponíveis');
    $pdo->prepare("INSERT INTO patients (clinic_id, uuid, name, birth_date, gender, status) VALUES (2, ?, 'Ana TESTE Outra Clínica', '1990-01-01', 'F', 'active')")->execute([bin2hex(random_bytes(8))]);
    [$c, $b2] = $req('/pacientes?q=Ana');
    $ok($c === 200 && str_contains($b2, 'Ana TESTE Ribeiro') && !str_contains($b2, 'Ana TESTE Outra Clínica'), 'UX-110: a busca acha a paciente pelo nome e não mostra paciente de outra clínica');
    // UX-109: onboarding de preferências incompleto + prontidão completa
    $pdo->exec("UPDATE onboarding_progress SET is_completed = 0 WHERE user_id = 1");
    $pdo->exec("UPDATE professionals SET crn = '' WHERE user_id = 1");
    [$c, $b] = $req('/dashboard');
    $ok(str_contains($b, 'data-ready="0"') && str_contains($b, 'Há requisitos aplicáveis pendentes'), 'UX-109 (negativo): prontidão pendente continua destacada');
    $pdo->exec("UPDATE professionals SET crn = '12345', crn_region = 'CRN-3' WHERE user_id = 1");
    $pdo->exec("UPDATE booking_settings SET is_enabled = 0 WHERE clinic_id = 1"); // agendamento online desligado: horários não se aplicam
    $ready = (new \ClyoNutri\Domain\Onboarding\ActivationChecklist($pdo))->forUser(1, 1);
    [$c, $b] = $req('/dashboard');
    $ok($ready['ready'] && str_contains($b, 'data-testid="practice-readiness" data-ready="1"'), 'cenário: prontidão completa (checklist real ' . $ready['done'] . ' de ' . $ready['applicable'] . ')');
    $ok(str_contains($b, 'data-testid="onboarding-banner" data-variant="optional"') && str_contains($b, 'Personalize suas preferências (opcional)'), 'UX-109: com a prontidão completa, preferências viram linha opcional');
    $ok(!str_contains($b, 'Continuar onboarding') && !str_contains($b, '% concluído'), 'UX-109: sem CTA laranja e sem "0% concluído" contradizendo o checklist');
    foreach (['onboarding-banner', 'onboarding-steps', 'onboarding-pct'] as $tid) $ok(!str_contains($b, 'data-testid="onboarding-banner"') || str_contains($b, 'data-testid="' . $tid . '"'), "regressão DS: data-testid $tid preservado");

    $css = file_get_contents($root . '/public/assets/css/ux-v54-57.css');
    $ok(!preg_match('/\.dash-(today|grid|metrics|attention|quick)\s*[,{][^}]*order\s*:/', $css), 'regressão v54.56 (p5456): CSS do v54.57 não reordena saudação → indicadores → agenda → lista no mobile');
    $ok(str_contains($css, '.dash .dash-greeting__icon{display:none}'), 'UX-110: mobile compacta a saudação (ícone oculto) para ações na 1ª dobra');

    echo "=== UX-120: KPI vazio neutro, cor de atenção só com valor > 0, nomes da agenda sem corte em 1 linha ===\n";
    $drafts = (int)$pdo->query("SELECT COUNT(DISTINCT patient_id) FROM meal_plans WHERE clinic_id = 1 AND status = 'draft'")->fetchColumn();
    [$c, $b] = $req('/dashboard');
    $ok($drafts > 0 && (bool)preg_match('~class="dash-kpi dash-kpi--accent" data-testid="dash-kpi-drafts"~', $b), "UX-120: com $drafts paciente(s) com rascunho, o card \"Planos a revisar\" fica em destaque");
    $pdo->exec("UPDATE meal_plans SET status = 'archived' WHERE clinic_id = 1 AND status = 'draft'");
    [$c, $b] = $req('/dashboard');
    $ok((bool)preg_match('~class="dash-kpi" data-testid="dash-kpi-drafts"~', $b) && str_contains($b, 'todos publicados'), 'UX-120 (negativo): com 0 planos a revisar, o card não usa a cor de atenção');
    $ok(str_contains($b, 'class="dash-appt__name" title="'), 'UX-120: nome completo da agenda disponível no title');
    $req('/__teste_login?u=2&c=2&v=0&to=/dashboard');
    [$c, $bB] = $req('/dashboard');
    $ok((bool)preg_match('~dash-kpi__value tabular-nums dash-kpi__value--empty"><span data-testid="average-adherence-value">Sem dados~', $bB), 'UX-120: "Sem dados" da adesão em estilo de estado vazio (clínica sem check-in)');
    $req('/__teste_login?u=1&c=1&v=0&to=/dashboard');
    $pid = (int)$pdo->query("SELECT id FROM patients WHERE clinic_id = 1 ORDER BY id LIMIT 1")->fetchColumn();
    $pdo->prepare("INSERT INTO checkins (clinic_id, patient_id, checkin_date, meal_adherence_pct) VALUES (1, ?, ?, 80)")->execute([$pid, date('Y-m-d')]);
    [$c, $b] = $req('/dashboard');
    $adhTxt = preg_match('~data-testid="average-adherence-value">([^<]*)<~', $b, $mm) ? $mm[1] : '';
    $ok(preg_match('/^\d/', $adhTxt) && !str_contains($b, 'dash-kpi__value--empty"><span data-testid="average-adherence-value">'), "UX-120 (negativo): com check-in real, a adesão continua como número de KPI ($adhTxt)");
    $ok(str_contains($css, '.dash .dash-kpi__value.dash-kpi__value--empty{') && str_contains($css, '.dash .dash-appt__name{white-space:normal'), 'UX-120: CSS do estado vazio e do nome em 2 linhas');

    echo "=== UX-121 / UX-122: alvos ≥ 24 px em links isolados; foco visível em data/hora ===\n";
    $ok(str_contains($css, '.card__header > a,.op-breadcrumb > a,a[data-resume],.u-flex > a.u-text-compact{min-height:24px'), 'UX-121: links isolados com min-height 24 px');
    $ok(str_contains(file_get_contents($root . '/views/auth/login.php'), 'href="/recuperar-senha" style="display: inline-flex; align-items: center; min-height: 24px;'), 'UX-121: "Esqueci minha senha" com 24 px');
    $ok((bool)preg_match('/\.nutri-dir-banner__link \{[^}]*min-height: 24px/', file_get_contents($root . '/public/assets/css/ds-pages.css')), 'UX-121: atalhos do diretório público com 24 px');
    $ok(str_contains($css, 'input[type="date"]:focus-visible,input[type="time"]:focus-visible') && str_contains($css, 'outline:2px solid'), 'UX-122: foco visível (contorno 2 px) em data e hora');

    $ok(str_contains($css, 'input[type="date"]:focus-within,input[type="time"]:focus-within'), 'UX-122: foco também quando o botão do calendário (shadow DOM do Chromium) está focado');
    $reg = (string)file_get_contents($root . '/views/auth/register.php');
    $ok(str_contains($reg, 'name="terms_accepted" value="1" required style="width: 24px; height: 24px;'), 'UX-121: aceite dos termos no cadastro com 24 px');
    $ok(str_contains($reg, 'tabindex="-1"') && str_contains($reg, 'cn_campo_extra'), 'regressão: campo-armadilha anti-robô do cadastro mantido (fora da ordem de Tab)');

    //@@NEXT@@
} finally {
    foreach ($procs as $p) { proc_terminate($p); }
    foreach ($files as $f) { @unlink($f); }
}
echo "\nv54.57 UX360 fixes: " . ($n - $fail) . " PASS, $fail FAIL\n";
exit($fail ? 1 : 0);
