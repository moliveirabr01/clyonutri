<?php

declare(strict_types=1);

// Papel admin — users.role=admin é administrador da PLATAFORMA; clinic_members.role=admin não (auditoria 14/10/2026).
require_once __DIR__ . '/support/auditoria_2026_10_14_fixture.php';

use ClyoNutri\Core\Auth\AuthService;
use ClyoNutri\Core\Auth\AuthUser;
use ClyoNutri\Core\Security\AuthorizationException;
use ClyoNutri\Core\Security\ClinicalAuthorization;
use ClyoNutri\Core\Security\RoleModel;
use ClyoNutri\Core\Security\TenantSecurityException;

echo "Papel admin — plataforma x clínica\n";
$fx = aud_fixture();
$db = $fx['db'];

aud_check(RoleModel::isPlatformSupport('admin'), "users.role 'admin' (plataforma) mantém suporte global");
aud_check(!RoleModel::isPlatformSupport('nutritionist') && !RoleModel::isPlatformSupport('owner'), 'nutritionist/owner não são suporte');

// Membro com clinic_members.role='admin' mas users.role='nutritionist'
$db->exec("UPDATE clinic_members SET role = 'admin' WHERE user_id = {$fx['userA']}");
$actor = ['user_role' => 'nutritionist', 'member_role' => 'admin', 'id' => $fx['userA'], 'user_id' => $fx['userA'], 'clinic_id' => $fx['clinicA']];
foreach (['/suporte/fila', '/admin', '/admin/clinicas'] as $path) {
    $denied = false;
    try {
        ClinicalAuthorization::assertWebAccess($actor, $path, 'GET', $db);
    } catch (TenantSecurityException | AuthorizationException) {
        $denied = true;
    }
    aud_check($denied, "admin de clínica (member_role=admin) bloqueado em {$path}");
}
$okClinic = true;
try {
    ClinicalAuthorization::assertWebAccess($actor, '/pacientes', 'GET', $db);
} catch (\Throwable) {
    $okClinic = false;
}
aud_check($okClinic, 'admin de clínica continua operando a própria clínica (/pacientes)');

$rp = new ReflectionClass(AuthUser::class);
$u = $rp->newInstanceWithoutConstructor();
foreach (['userRole' => 'nutritionist', 'memberRole' => 'admin'] as $k => $v) {
    $p = $rp->getProperty($k);
    $p->setValue($u, $v);
}
aud_check(!$u->isSupport() && !$u->isPlatformAdmin(), 'AuthUser(member_role=admin) não é suporte nem admin de plataforma');
$rp->getProperty('userRole')->setValue($u, 'admin');
aud_check($u->isSupport() && $u->isPlatformAdmin(), 'AuthUser(users.role=admin) é suporte e admin de plataforma');

// Anexo de chamado de outra clínica: só papel de plataforma
$svcSrc = (string)file_get_contents(__DIR__ . '/../src/Domain/Helpdesk/HelpdeskAttachmentService.php');
aud_check(str_contains($svcSrc, 'RoleModel::isPlatformSupport($userRole)'), 'HelpdeskAttachmentService decide suporte global por users.role via RoleModel');

// Provisionamento: membro de clínica não concede users.role=admin
$auth = new AuthService($db);
$denied = false;
try {
    $auth->updateUserRole($fx['userB'], 'admin', $fx['userA']); // userA é owner/admin de clínica, não da plataforma
} catch (AuthorizationException) {
    $denied = true;
}
$roleB = (string)$db->query("SELECT role FROM users WHERE id = {$fx['userB']}")->fetchColumn();
aud_check($denied && $roleB === 'nutritionist', 'admin de clínica NÃO consegue promover ninguém a users.role=admin');
$denied = false;
try {
    $auth->updateUserRole($fx['userA'], 'admin', $fx['userA']);
} catch (AuthorizationException) {
    $denied = true;
}
aud_check($denied, 'autopromoção a users.role=admin bloqueada');

$db->prepare("INSERT INTO users (uuid, name, email, password_hash, role, status) VALUES ('plat-1', 'Admin Plataforma', 'plat@aud.test', 'x', 'superadmin', 'active')")->execute();
$plat = (int)$db->lastInsertId();
$auth->updateUserRole($fx['userB'], 'admin', $plat);
aud_check((string)$db->query("SELECT role FROM users WHERE id = {$fx['userB']}")->fetchColumn() === 'admin', 'superadmin da plataforma consegue provisionar users.role=admin');
$auth->updateUserRole($fx['userB'], 'nutritionist', $plat);

aud_check(is_file(__DIR__ . '/../docs/delivery/MODELO_PAPEIS.md'), 'docs/delivery/MODELO_PAPEIS.md documenta o modelo');

echo "Papel admin: OK\n";
