<?php

declare(strict_types=1);

/**
 * Testes unitários do domínio da Equipe (NI2-606 - TeamService)
 */

require_once __DIR__ . '/../vendor/autoload.php';

use ClyoNutri\Core\Auth\TenantContext;
use ClyoNutri\Core\Database\Connection;
use ClyoNutri\Domain\Team\TeamService;

$db = Connection::getInstance();

function check(bool $cond, string $desc): void
{
    if ($cond) {
        echo "  [PASS] {$desc}\n";
    } else {
        echo "  [FAIL] {$desc}\n";
        throw new RuntimeException("Falha na asserção: {$desc}");
    }
}

echo "=== Suíte de Testes NI2-606: Equipe (TeamService) ===\n\n";

$clinicId = 1;
$ownerId = 1;
TenantContext::set($clinicId, ['id' => $ownerId, 'role' => 'owner', 'name' => 'Titular da Clínica']);

// Limpar membros de testes anteriores
$db->exec("DELETE FROM clinic_members WHERE clinic_id = 1 AND user_id > 800");

$teamSvc = new TeamService($db);

// 1. canManage
check($teamSvc->canManage($clinicId, $ownerId), "Titular ativo tem permissão de gerenciar a equipe");

// 2. roleLabel
check(TeamService::roleLabel('owner') === 'Titular', "Rótulo owner é Titular");
check(TeamService::roleLabel('admin') === 'Nutricionista gestor(a)', "Rótulo admin é Nutricionista gestor(a)");
check(TeamService::roleLabel('nutritionist') === 'Nutricionista', "Rótulo nutritionist é Nutricionista");
check(TeamService::roleLabel('receptionist') === 'Recepção', "Rótulo receptionist é Recepção");

// 3. members list
$members = $teamSvc->members($clinicId);
check(is_array($members) && count($members) > 0, "members() retorna lista não-vazia de membros");
check(isset($members[0]['state_label']), "Cada membro tem state_label formatado");

// 4. limit
$limit = $teamSvc->limit($clinicId);
check(isset($limit['used'], $limit['max'], $limit['available']), "limit() retorna estrutura esperada");

// 5. Convite sem CRN para nutricionista deve falhar
$failedCrn = false;
try {
    $teamSvc->invite($clinicId, $ownerId, [
        'name' => 'Nutri Sem CRN',
        'email' => 'nutri_sem_crn@teste.invalid',
        'role' => 'nutritionist',
    ]);
} catch (\InvalidArgumentException $e) {
    if (str_contains($e->getMessage(), 'CRN')) {
        $failedCrn = true;
    }
}
check($failedCrn, "Convite para nutricionista sem CRN é recusado");

// 6. Convite válido para nutricionista
$testEmailNutri = 'nutri_convidada_' . bin2hex(random_bytes(4)) . '@teste.invalid';
$invNutri = $teamSvc->invite($clinicId, $ownerId, [
    'name' => 'Nutricionista Convidada',
    'email' => $testEmailNutri,
    'role' => 'nutritionist',
    'crn' => '998877',
    'crn_region' => 'CRN-3',
]);
check($invNutri['user_id'] > 0, "Nutricionista convidada criada com sucesso");
check(!empty($invNutri['expires_at']), "Convite possui prazo de expiração");
$nutriUserId = (int)$invNutri['user_id'];

// 7. Reenviar convite
$reinv = $teamSvc->resendInvite($clinicId, $ownerId, $nutriUserId);
check(isset($reinv['delivery'], $reinv['expires_at']), "Reenvio de convite gera nova entrega");

// 8. Trocar papel entre nutricionista e admin
$teamSvc->changeRole($clinicId, $ownerId, $nutriUserId, 'admin');
$st = $db->prepare("SELECT role FROM clinic_members WHERE clinic_id = ? AND user_id = ?");
$st->execute([$clinicId, $nutriUserId]);
check($st->fetchColumn() === 'admin', "Papel alterado para admin com sucesso");

// 9. Recepção não exige CRN
$testEmailRecep = 'recepcao_' . bin2hex(random_bytes(4)) . '@teste.invalid';
$invRecep = $teamSvc->invite($clinicId, $ownerId, [
    'name' => 'Recepcionista Convidada',
    'email' => $testEmailRecep,
    'role' => 'receptionist',
]);
check($invRecep['user_id'] > 0, "Recepção cadastrada sem CRN");
$recepUserId = (int)$invRecep['user_id'];

// 10. Desativar membro
$teamSvc->deactivate($clinicId, $ownerId, $nutriUserId);
$stStatus = $db->prepare("SELECT status FROM clinic_members WHERE clinic_id = ? AND user_id = ?");
$stStatus->execute([$clinicId, $nutriUserId]);
check($stStatus->fetchColumn() === 'revoked', "Membro desativado tem vínculo alterado para revoked");

// 11. Reativar membro
$teamSvc->reactivate($clinicId, $ownerId, $nutriUserId);
$stStatus->execute([$clinicId, $nutriUserId]);
check($stStatus->fetchColumn() === 'active', "Membro reativado tem vínculo restaurado");

// 12. Ninguém altera o titular
$failedOwner = false;
try {
    $teamSvc->deactivate($clinicId, $nutriUserId, $ownerId);
} catch (\DomainException $e) {
    if (str_contains($e->getMessage(), 'titular')) {
        $failedOwner = true;
    }
}
check($failedOwner, "Tentativa de desativar o titular é bloqueada");

// 13. Ninguém altera a si mesmo
$failedSelf = false;
try {
    $teamSvc->deactivate($clinicId, $ownerId, $ownerId);
} catch (\DomainException $e) {
    if (str_contains($e->getMessage(), 'próprio acesso')) {
        $failedSelf = true;
    }
}
check($failedSelf, "Tentativa de alterar o próprio acesso é bloqueada");

// 14. Histórico da equipe
$hist = $teamSvc->history($clinicId);
check(is_array($hist) && count($hist) > 0, "history() registra eventos da equipe na auditoria");

// Limpar membros de teste
$teamSvc->deactivate($clinicId, $ownerId, $nutriUserId);
$teamSvc->deactivate($clinicId, $ownerId, $recepUserId);

// 15. TeamController::show renderiza aba de equipe
$_SESSION['clinic_id'] = $clinicId;
$_SESSION['user_id'] = $ownerId;
$_SESSION['user_name'] = 'Titular';
$_SESSION['clinic_name'] = 'Clínica Demo';
ob_start();
(new \ClyoNutri\Application\Controllers\TeamController())->show();
$teamHtml = ob_get_clean();

check(str_contains($teamHtml, 'Pessoas na equipe:'), "Página de equipe exibe contador 'Pessoas na equipe:'");
check(str_contains($teamHtml, 'data-testid="team-member"'), "Página lista membros da equipe com data-testid='team-member'");
check(str_contains($teamHtml, 'data-testid="team-invite-form"'), "Formulário de convite presente");

echo "\n✅ Todos os testes de TeamService e TeamController passaram com 100% de sucesso!\n";
