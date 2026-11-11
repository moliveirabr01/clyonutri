<?php
/** NI2-606 (v54.11): equipe da clínica. Dados TESTE; e-mail desligado (link mostrado uma vez). Fora do zip. */
declare(strict_types=1);
putenv('V54_DB=' . dirname(__DIR__) . '/storage/testing-v5411.sqlite');
putenv('MAIL_DRIVER=log');
require __DIR__ . '/_v54_bootstrap.php';

use ClyoNutri\Domain\Team\TeamService;
use ClyoNutri\Core\Auth\PasswordResetService;
use ClyoNutri\Core\Auth\AuthService;

$c = Fx::clinic($pdo, 'Clínica TESTE'); $o = Fx::user($pdo, $c, 'Titular TESTE', 'owner'); Fx::act($o);
$c2 = Fx::clinic($pdo, 'Clínica TESTE B'); $o2 = Fx::user($pdo, $c2, 'Titular B', 'owner');
$pdo->prepare("INSERT INTO clinic_subscriptions (clinic_id, plan_id, status, price_cents, current_period_start, current_period_end) VALUES (?, 2, 'active', 19900, datetime('now'), datetime('now', '+30 days'))")->execute([$c]);
$t = new TeamService($pdo);

echo "== limite e convite\n";
$l = $t->limit($c);
T::ok($l['used'] === 1 && $l['max'] === 3 && $l['available'], 'plano Profissional: 1 de 3');
T::throws(fn() => $t->invite($c, $o['user_id'], ['name' => 'Nutri TESTE 2', 'email' => 'n2@teste.invalid', 'role' => 'nutritionist']), 'nutricionista sem CRN é recusado', 'CRN');
$r = $t->invite($c, $o['user_id'], ['name' => 'Nutri TESTE 2', 'email' => 'N2@teste.invalid', 'role' => 'nutritionist', 'crn' => '54321', 'crn_region' => 'CRN-3']);
$u2 = $r['user_id'];
T::ok($r['delivery'] === 'manual' && str_contains((string)$r['link'], '/recuperar-senha/'), 'sem servidor de e-mail: link mostrado uma vez');
$row = $pdo->query("SELECT u.status, u.role, u.email, cm.role AS mr, cm.status AS ms, p.crn FROM users u JOIN clinic_members cm ON cm.user_id = u.id LEFT JOIN professionals p ON p.user_id = u.id WHERE u.id = $u2")->fetch();
T::ok($row['status'] === 'invited' && $row['role'] === 'nutritionist' && $row['mr'] === 'nutritionist' && $row['ms'] === 'active' && $row['crn'] === '54321' && $row['email'] === 'n2@teste.invalid', 'convidado: conta "convite pendente", papel e CRN gravados');
T::ok($t->limit($c)['used'] === 2, 'convidado já conta no limite (2 de 3)');
T::throws(fn() => $t->invite($c, $o['user_id'], ['name' => 'Outra', 'email' => 'n2@teste.invalid', 'role' => 'receptionist']), 'e-mail repetido é recusado', 'Já existe');
// cria a senha pelo link e entra
$token = basename(parse_url((string)$r['link'], PHP_URL_PATH));
(new PasswordResetService($pdo))->resetPassword($token, 'Senha-TESTE-123');
$auth = (new AuthService($pdo))->attempt('n2@teste.invalid', 'Senha-TESTE-123');
T::ok($auth && (int)$auth['clinic_id'] === $c && $auth['member_role'] === 'nutritionist', 'depois de criar a senha, entra na clínica com o papel escolhido');

echo "== recepção, limite atingido\n";
$t->invite($c, $o['user_id'], ['name' => 'Recepção TESTE', 'email' => 'rec@teste.invalid', 'role' => 'receptionist']);
T::ok($t->limit($c)['used'] === 3 && !$t->limit($c)['available'], 'limite atingido: 3 de 3');
T::throws(fn() => $t->invite($c, $o['user_id'], ['name' => 'Quarta', 'email' => 'q@teste.invalid', 'role' => 'nutritionist', 'crn' => '1', 'crn_region' => 'CRN-3']), 'com o limite atingido, o convite mostra o motivo', 'Seu plano permite 3');

echo "== papel e desativação\n";
$t->changeRole($c, $o['user_id'], $u2, 'admin');
T::ok($pdo->query("SELECT role FROM clinic_members WHERE user_id = $u2")->fetchColumn() === 'admin', 'nutricionista vira gestor(a)');
$rec = (int)$pdo->query("SELECT id FROM users WHERE email = 'rec@teste.invalid'")->fetchColumn();
T::throws(fn() => $t->changeRole($c, $o['user_id'], $rec, 'nutritionist'), 'recepção não vira nutricionista por troca de papel', 'Recepção');
T::throws(fn() => $t->deactivate($c, $o['user_id'], $o['user_id']), 'ninguém desativa a si mesmo', 'próprio');
T::throws(fn() => $t->deactivate($c, $u2, $o['user_id']), 'gestor não mexe no titular', 'titular');
$pdo->prepare("INSERT INTO anamneses (clinic_id, patient_id, professional_id, version, is_draft, created_at) VALUES (?, ?, (SELECT id FROM professionals WHERE user_id = ?), 1, 0, datetime('now'))")->execute([$c, Fx::patient($pdo, $c, 'Paciente TESTE'), $u2]);
$t->deactivate($c, $o['user_id'], $u2);
T::ok((new AuthService($pdo))->attempt('n2@teste.invalid', 'Senha-TESTE-123')['clinic_id'] === null, 'desativado não tem mais vínculo ativo para entrar');
T::ok((int)$pdo->query("SELECT COUNT(*) FROM anamneses WHERE professional_id = (SELECT id FROM professionals WHERE user_id = $u2)")->fetchColumn() === 1, 'histórico dele continua preservado');
T::ok($t->limit($c)['used'] === 2 && $t->limit($c)['available'], 'desativar libera a vaga (2 de 3)');
$t->reactivate($c, $o['user_id'], $u2);
T::ok($t->limit($c)['used'] === 3, 'reativar volta a contar');

echo "== permissões e isolamento\n";
T::throws(fn() => $t->invite($c, $rec, ['name' => 'X', 'email' => 'x@teste.invalid', 'role' => 'receptionist']), 'recepção não gerencia a equipe', 'Somente o titular');
T::throws(fn() => $t->deactivate($c2, $o2['user_id'], $u2), 'titular de outra clínica não alcança membro desta', 'não encontrada');
$acts = $pdo->query("SELECT action FROM audit_logs WHERE action LIKE 'TEAM_%'")->fetchAll(PDO::FETCH_COLUMN);
T::ok(in_array('TEAM_MEMBER_INVITED', $acts, true) && in_array('TEAM_MEMBER_DEACTIVATED', $acts, true) && in_array('TEAM_MEMBER_ROLE_CHANGED', $acts, true) && in_array('TEAM_MEMBER_REACTIVATED', $acts, true), 'auditoria: convite, papel, desativação e reativação');
$det = implode(' ', $pdo->query("SELECT details FROM audit_logs WHERE action LIKE 'TEAM_%'")->fetchAll(PDO::FETCH_COLUMN));
T::ok(!str_contains($det, $token) && !str_contains($det, 'recuperar-senha'), 'auditoria sem token nem link');
T::done();
