<?php

declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';

use ClyoNutri\Core\Auth\TenantContext;
use ClyoNutri\Core\Database\Connection;
use ClyoNutri\Application\Controllers\DashboardController;
use ClyoNutri\Application\Controllers\SettingsController;
use ClyoNutri\Domain\Patient\PatientRepository;

$db = Connection::getInstance();
$db->exec('PRAGMA foreign_keys = ON');

echo "======================================================================\n";
echo "TEST SUITE: Execucao NI2-588 ate NI2-590 (Rodada 2 UX)\n";
echo "======================================================================\n";

function check(bool $cond, string $msg): void {
    if (!$cond) {
        echo " [FAIL] {$msg}\n";
        exit(1);
    }
    echo " [PASS] {$msg}\n";
}

// Configura contexto profissional para testes
TenantContext::setClinicId(1);
TenantContext::setUserId(1);
$_SESSION['clinic_id'] = 1;
$_SESSION['user_id'] = 1;
$_SESSION['user_name'] = 'Nutricionista Teste';
$_SESSION['clinic_name'] = 'Clínica Escola';

$render = function(callable $fn): string {
    ob_start();
    try {
        $fn();
    } finally {
        $output = ob_get_clean();
    }
    return (string)$output;
};

// -------------------------------------------------------------------------
// 1. NI2-588 [UX-005][P1]: Abas modulares em Configurações & Atalho da Agenda
// -------------------------------------------------------------------------
echo "\n--- NI2-588: Abas modulares em Configurações & Atalho da Agenda ---\n";

// 1.1 Teste da Aba Perfil
$_GET = ['tab' => 'perfil'];
$_POST = [];
$_SERVER['REQUEST_METHOD'] = 'GET';
$htmlPerfil = $render(fn() => (new SettingsController())->show());
check(str_contains($htmlPerfil, 'data-testid="tab-perfil"'), 'Navegacao por abas exibe data-testid="tab-perfil"');
check(str_contains($htmlPerfil, 'id="settings-profile"'), 'Aba perfil renderiza secao de dados cadastrais (#settings-profile)');
check(!str_contains($htmlPerfil, 'id="settings-agenda"'), 'Aba perfil NÃO renderiza secao da agenda (isolamento de escopo)');
check(!str_contains($htmlPerfil, 'id="patient-actions-settings"'), 'Aba perfil NÃO renderiza regras do paciente no app');
check(!str_contains($htmlPerfil, 'id="password-new_password"'), 'Aba perfil NÃO renderiza troca de senha');

// 1.2 Teste da Aba Agenda
$_GET = ['tab' => 'agenda'];
$htmlAgenda = $render(fn() => (new SettingsController())->show());
check(str_contains($htmlAgenda, 'data-testid="tab-agenda"'), 'Navegacao por abas exibe data-testid="tab-agenda"');
check(str_contains($htmlAgenda, 'id="settings-agenda"'), 'Aba agenda renderiza secao de disponibilidade e agendamento');
$_GET = ['tab' => 'agenda', 'secao' => 'link'];
$htmlAgendaLink = $render(fn() => (new SettingsController())->show());
check(str_contains($htmlAgendaLink, 'data-testid="booking-settings"'), 'Aba agenda renderiza formulario de autoagendamento');
check(!str_contains($htmlAgenda, 'id="settings-profile"'), 'Aba agenda NÃO renderiza dados cadastrais');

// 1.3 Teste da Aba Paciente App
$_GET = ['tab' => 'paciente-app'];
$htmlPacienteApp = $render(fn() => (new SettingsController())->show());
check(str_contains($htmlPacienteApp, 'data-testid="tab-paciente-app"'), 'Navegacao por abas exibe data-testid="tab-paciente-app"');
check(str_contains($htmlPacienteApp, 'data-testid="patient-actions-settings"'), 'Aba paciente-app renderiza acoes da paciente no app');
check(str_contains($htmlPacienteApp, 'data-testid="chat-settings"'), 'Aba paciente-app renderiza regras do chat');
check(!str_contains($htmlPacienteApp, 'id="settings-profile"'), 'Aba paciente-app NÃO renderiza dados cadastrais');
check(!str_contains($htmlPacienteApp, 'id="settings-agenda"'), 'Aba paciente-app NÃO renderiza disponibilidade');

// 1.4 Teste da Aba Conta
$_GET = ['tab' => 'conta'];
$htmlConta = $render(fn() => (new SettingsController())->show());
check(str_contains($htmlConta, 'data-testid="tab-conta"'), 'Navegacao por abas exibe data-testid="tab-conta"');
check(str_contains($htmlConta, 'id="settings-account"'), 'Aba conta renderiza secao de conta e seguranca');
check(str_contains($htmlConta, 'id="password-new_password"'), 'Aba conta renderiza campos de alteracao de senha');
check(!str_contains($htmlConta, 'id="settings-profile"'), 'Aba conta NÃO renderiza dados cadastrais');

// 1.5 Teste de Tab Invalida (Fallback seguro para perfil)
$_GET = ['tab' => 'aba_inexistente'];
$htmlFallback = $render(fn() => (new SettingsController())->show());
check(str_contains($htmlFallback, 'id="settings-profile"'), 'Tab inexistente aplica fallback seguro para perfil');

// 1.6 Atalho da Agenda para Configurações de Horários
$_GET = [];
$dashCtrl = new DashboardController();
$htmlAgendaView = $render(fn() => $dashCtrl->agenda());
check(str_contains($htmlAgendaView, 'data-testid="agenda-to-settings-btn"'), 'Agenda exibe botao de atalho 1-clique para configuracoes');
check(str_contains($htmlAgendaView, 'href="/configuracoes?tab=agenda&amp;secao=horarios"'), 'Menu da agenda aponta para a seção de horários');


// -------------------------------------------------------------------------
// 2. NI2-589 [UX-006][P2]: Link no Nome do Paciente, Typeahead & Toast
// -------------------------------------------------------------------------
echo "\n--- NI2-589: Link no Nome do Paciente, Typeahead & Toast ---\n";

$repo = new PatientRepository();
$uniqueSuffix = bin2hex(random_bytes(4));
$pName = 'Paciente Teste Link ' . $uniqueSuffix;
$created = $repo->create([
    'name' => $pName,
    'email' => "paciente_{$uniqueSuffix}@teste.com",
    'phone' => '(11) 9' . random_int(1000, 9999) . '-' . random_int(1000, 9999),
    'birth_date' => '1992-05-10',
    'gender' => 'Feminino',
    'allergies' => 'Frutos do mar',
    'allergy_assessment' => 'recorded',
]);
$pId = (int)$created['id'];

// 2.1 Link Direto no Nome do Paciente na Tabela
$_GET = ['q' => $uniqueSuffix];
$htmlPatients = $render(fn() => $dashCtrl->patients());
check(str_contains($htmlPatients, 'data-testid="patient-row-link-' . $pId . '"'), 'Tabela de pacientes possui link com data-testid="patient-row-link-[id]"');
check(str_contains($htmlPatients, 'href="/pacientes/' . $pId . '"'), 'Nome do paciente na listagem e link direto para o perfil /pacientes/[id]');

// 2.2 Toast pós-cadastro com botão "Abrir perfil de [Nome] →"
$_GET = ['success' => '1'];
$_SESSION['patient_created_id'] = $pId;
$_SESSION['patient_created_name'] = $pName;
$htmlPatientsToast = $render(fn() => $dashCtrl->patients());
check(str_contains($htmlPatientsToast, 'data-testid="patient-success-toast"'), 'Exibe toast de sucesso apos cadastro de paciente');
check(str_contains($htmlPatientsToast, 'data-testid="toast-open-patient-btn"'), 'Toast contem botao de atalho direto data-testid="toast-open-patient-btn"');
check(str_contains($htmlPatientsToast, 'href="/pacientes/' . $pId . '"'), 'Botao do toast aponta diretamente para o perfil do novo paciente');
check(str_contains($htmlPatientsToast, 'Abrir perfil de ' . htmlspecialchars($pName) . ' →'), 'Texto do botao no toast especifica o nome do paciente cadastrado');

// 2.3 Busca reativa / typeahead instantaneo
check(str_contains($htmlPatients, 'id="patient-search"'), 'Campo de busca presente na tela de pacientes');
check(str_contains($htmlPatients, 'term.length >= 3'), 'Script de busca reativa client-side filtra com >= 3 caracteres');


// -------------------------------------------------------------------------
// 3. NI2-590 [UX-007][P2]: Perfil 360 - 1ª Dobra e Navegação Unificada
// -------------------------------------------------------------------------
echo "\n--- NI2-590: Perfil 360 - 1ª Dobra e Navegação Unificada ---\n";

$_GET = [];
$htmlProfile360 = $render(fn() => $dashCtrl->profile360(['id' => $pId]));

// 3.1 Identidade essencial e Alergias na 1ª Dobra
check(str_contains($htmlProfile360, 'data-testid="profile-allergy-badge"'), 'Perfil 360 exibe badge de status de alergias na 1ª dobra');
check(str_contains($htmlProfile360, 'Frutos do mar'), 'Badge destaca alergia registrada do paciente');
check(str_contains($htmlProfile360, 'data-testid="care-next-step"'), 'Card de proximo passo clinico (careStep) presente na 1ª dobra');

// 3.2 Paciente com recusa de alergia
$pDenied = $repo->create([
    'name' => 'Paciente Sem Alergia ' . bin2hex(random_bytes(4)),
    'allergy_assessment' => 'denied',
]);
$htmlProfileDenied = $render(fn() => $dashCtrl->profile360(['id' => (int)$pDenied['id']]));
check(str_contains($htmlProfileDenied, 'Nega alergias') || str_contains($htmlProfileDenied, 'Sem alergias relatadas'), 'Badge reflete recusa de alergias com indicador positivo');

// 3.3 Navegação Unificada cn-profile-nav
check(str_contains($htmlProfile360, 'class="cn-profile-nav"'), 'Perfil 360 contem container de navegacao unificada cn-profile-nav');
check(str_contains($htmlProfile360, 'data-testid="profile-meal-plan-btn"'), 'Navegacao unificada contem atalho destacado para Plano Alimentar');
check(str_contains($htmlProfile360, 'data-testid="profile-manipulados-btn">Manipulados →</a>'), 'Navegacao unificada contem atalho para Manipulados');
check(str_contains($htmlProfile360, 'href="#patient-appointments"'), 'Navegacao unificada contem ancoras integradas para secoes do perfil');
check(str_contains($htmlProfile360, 'href="#secao-prontuario"'), 'Navegacao unificada contem ancora para Prontuario');
check(str_contains($htmlProfile360, 'href="#secao-anamnese"'), 'Navegacao unificada contem ancora para Anamnese');

echo "\n======================================================================\n";
echo "TODOS OS TESTES DE NI2-588 ATE NI2-590 PASSARAM COM SUCESSO!\n";
echo "======================================================================\n";
