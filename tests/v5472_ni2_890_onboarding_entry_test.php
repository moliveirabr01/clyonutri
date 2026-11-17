<?php

declare(strict_types=1);

/**
 * Suíte NI2-890:
 *  - Portal do paciente abre em "Entrar" por padrão e tem link para "Primeiro acesso"
 *  - Convite de equipe gera link /convite/{token} e isola escopo de token (invite vs reset)
 *  - Tela de convite não exibe "Redefinir senha" e exige aceite dos termos
 *  - Pós-agendamento online não exibe botão sem saída para Portal do Paciente
 */
require_once __DIR__ . '/_v54_bootstrap.php';

use ClyoNutri\Core\Auth\PasswordResetService;
use ClyoNutri\Core\Mail\ArrayMailer;
use ClyoNutri\Core\Security\Csrf;
use ClyoNutri\Domain\Team\TeamService;

/** @var PDO $pdo */
$db = $pdo;

// 1) Portal do paciente abre em "Entrar"
$step = 'landing';
$codeVal = '';
$preview = null;
$error = null;
ob_start();
require __DIR__ . '/../views/patient/app.php';
$portalHtml = ob_get_clean();
assert(str_contains($portalHtml, 'id="tab-btn-login" aria-controls="tab-login" aria-selected="true"'), 'Portal deve abrir na aba Entrar por padrão');
assert(str_contains($portalHtml, 'É seu primeiro acesso? Crie sua senha'), 'Aba Entrar deve ter link visível para Primeiro acesso');

// 2) Pós-agendamento online sem botão sem saída
$bookingHtml = (string)file_get_contents(__DIR__ . '/../views/booking/public_booking.php');
assert(!str_contains($bookingHtml, 'Acessar Portal do Paciente'), 'Pós-agendamento não deve exibir botão Acessar Portal do Paciente para visitante sem senha');

// 3) Convite da equipe: link /convite/{token}, isolamento de escopo e tela própria sem "Redefinir senha"
$db->exec("INSERT INTO clinics (id, uuid, name, email, status) VALUES (1, 'c1', 'Clínica 890', 'c890@teste.local', 'active')");
$pwHash = password_hash('SenhaForte@2026', PASSWORD_BCRYPT, ['cost' => 10]);
$db->prepare("INSERT INTO users (id, uuid, name, email, password_hash, role, is_verified, status) VALUES (1, 'u1', 'Titular', 'owner890@teste.local', ?, 'nutritionist', 1, 'active')")->execute([$pwHash]);
$db->exec("INSERT INTO clinic_members (clinic_id, user_id, role, status) VALUES (1, 1, 'owner', 'active')");

$mailer = new ArrayMailer();
$teamSvc = new TeamService($db, $mailer, 'https://clyonutri.test');
$invited = $teamSvc->invite(1, 1, ['name' => 'Convidada', 'email' => 'conv890@teste.local', 'role' => 'nutritionist', 'crn' => '54321', 'crn_region' => 'CRN-3']);
$mailBody = (string)($mailer->sent[0]->textBody ?? '') . (string)($mailer->sent[0]->htmlBody ?? '');
assert(str_contains($mailBody, '/convite/'), 'E-mail de convite deve apontar para /convite/{token}');

preg_match('#/convite/([a-f0-9]{64})#', $mailBody, $m);
$inviteToken = $m[1] ?? '';
assert(strlen($inviteToken) === 64, 'Token de convite de 64 chars deve estar no e-mail');

$resetSvc = new PasswordResetService($db, $mailer, 'https://clyonutri.test');
assert($resetSvc->validateToken($inviteToken, 'reset') === null, 'Token de convite NÃO pode servir na rota de recuperação de senha');
$tokenData = $resetSvc->validateToken($inviteToken, 'invite');
assert($tokenData !== null && $tokenData['clinic_name'] === 'Clínica 890', 'Token de convite deve validar no escopo invite com nome da clínica');

$token = $inviteToken;
$csrfToken = Csrf::token();
$isValidToken = true;
ob_start();
require __DIR__ . '/../views/auth/team-invite.php';
$inviteHtml = ob_get_clean();
assert(!str_contains($inviteHtml, 'Redefinir senha'), 'Tela de convite não pode exibir Redefinir senha');
assert(str_contains($inviteHtml, 'Clínica 890') && str_contains($inviteHtml, 'terms_accepted'), 'Tela de convite deve mostrar clínica e exigir aceite de termos');

// Token de reset normal não pode servir em /convite/{token}
$resetIssued = $resetSvc->issueAdminToken('owner890@teste.local', 3600);
assert($resetSvc->validateToken($resetIssued['token'], 'invite') === null, 'Token de usuário ativo NÃO pode servir na rota de convite');
assert($resetSvc->validateToken($resetIssued['token'], 'reset') !== null, 'Token de usuário ativo deve validar no escopo reset');

echo "NI2-890 OK: portal em Entrar, convite /convite/{token} isolado e pós-agendamento sem beco validados.\n";
