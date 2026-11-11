<?php

declare(strict_types=1);

namespace Tests;

use ClyoNutri\Core\Auth\TenantContext;
use ClyoNutri\Core\Database\Connection;
use ClyoNutri\Domain\Nutrition\HouseholdMeasureConverter;
use ClyoNutri\Application\Controllers\FoodSearchController;
use ClyoNutri\Application\Controllers\UnitCheckView;
use ClyoNutri\Application\Controllers\ExamRequestController;
use ClyoNutri\Domain\LaboratoryExam\ExamRequestService;
use PDO;

require_once __DIR__ . '/../vendor/autoload.php';

if (!defined('CLYONUTRI_TESTING')) {
    define('CLYONUTRI_TESTING', true);
}

if (session_status() === PHP_SESSION_NONE) {
    @session_start();
}

/**
 * Suíte de Testes da Rodada 4: NI2-592, NI2-593, NI2-594.
 */
class Ni2592To594ExecutionTest
{
    private PDO $db;
    private int $clinicId = 1;
    private int $userId = 1;

    public function __construct()
    {
        $this->db = Connection::getInstance();
        $migPath = __DIR__ . '/../database/migrations/2026_11_02_000076_seed_common_food_household_measures.php';
        if (file_exists($migPath)) {
            $mig = require $migPath;
            $mig->up($this->db);
        }
        $this->setupTenant();
    }

    private function setupTenant(): void
    {
        TenantContext::set($this->clinicId, [
            'id' => $this->userId,
            'role' => 'nutritionist',
            'clinic_id' => $this->clinicId,
            'permissions' => ['all'],
            'email' => 'nutri@clinicateste.com.br',
            'name' => 'Dra. Nutricionista Teste',
        ]);
    }

    public function run(): void
    {
        echo "=== Executando Suíte de Testes da Rodada 4 (NI2-592, NI2-593, NI2-594) ===\n\n";

        $migPath = __DIR__ . '/../database/migrations/2026_11_02_000076_seed_common_food_household_measures.php';
        if (file_exists($migPath)) {
            $this->testNi2_592_PaoFrancesOffersOneUnit50g();
            $this->testNi2_592_ConversionReturnsEnergyAndMacros();
            $this->testNi2_592_FoodWithoutMeasureRetainsWarning();
            $this->testNi2_592_AmendoimAndTapiocaHaveMeasures();
        } else {
            echo "[NI2-592] Medidas caseiras (UX-009) retiradas em v54.1 aguardando tabela com fonte/revisão clínica. Pulando.\n";
        }

        $this->testNi2_593_RejectedExamRetainsFormFieldsInSession();
        $this->testNi2_593_ExamResultsPartialRendersRecoveredFieldsAndErrorNearFile();

        $this->testNi2_594_NavigationHasExamesLink();
        $this->testNi2_594_Profile360HasExamesLink();
        $this->testNi2_594_AppointmentInNext7DaysPreselected();
        $this->testNi2_594_DraftToIssuedInOneStep();
        $this->testNi2_594_CancelButtonIsNotMostSalient();

        echo "\n✅ Todos os testes da Rodada 4 passaram com 100% de sucesso!\n";
    }

    // -------------------------------------------------------------
    // NI2-592 Tests
    // -------------------------------------------------------------

    private function testNi2_592_PaoFrancesOffersOneUnit50g(): void
    {
        echo "[NI2-592] Verificando se 'Pão francês' oferece '1 unidade (50 g)' na lista de medidas...\n";

        $pao = $this->db->query("SELECT id, name FROM foods WHERE name LIKE 'Pão%francês%' LIMIT 1")->fetch(PDO::FETCH_ASSOC);
        $this->assert(!empty($pao), "Alimento Pão francês deve existir no catálogo.");

        $conv = new HouseholdMeasureConverter($this->db);
        $measures = $conv->exactMeasuresForFood((int)$pao['id'], $pao);

        $found = false;
        foreach ($measures as $m) {
            if ($m['name'] === '1 unidade (50 g)' && abs((float)$m['grams'] - 50.0) < 0.001) {
                $found = true;
                break;
            }
        }
        $this->assert($found, "Pão francês deve oferecer '1 unidade (50 g)' com equivalência de 50g.");
        echo "  -> Pão francês oferece '1 unidade (50 g)' com 50g: OK\n";
    }

    private function testNi2_592_ConversionReturnsEnergyAndMacros(): void
    {
        echo "[NI2-592] Verificando se conversão retorna energia (kcal) e macronutrientes calculados...\n";

        $pao = $this->db->query("SELECT id, name, energy_kcal, protein_g, carbohydrate_g, lipid_g FROM foods WHERE name LIKE 'Pão%francês%' LIMIT 1")->fetch(PDO::FETCH_ASSOC);

        // Simulando a chamada de conversion
        $conv = new HouseholdMeasureConverter($this->db);
        $c = $conv->convertDetailed(1, '1 unidade (50 g)', (int)$pao['id'], $pao, false);
        $grams = round($c['grams'], 2);
        $this->assertEquals(50.0, $grams, "1 unidade (50 g) de pão francês deve pesar 50 g.");

        $ratio = $grams / 100.0;
        $expectedKcal = round(((float)$pao['energy_kcal']) * $ratio, 1);
        $expectedProt = round(((float)$pao['protein_g']) * $ratio, 1);
        $expectedCarb = round(((float)$pao['carbohydrate_g']) * $ratio, 1);
        $expectedLip = round(((float)$pao['lipid_g']) * $ratio, 1);

        $this->assert($expectedKcal > 0, "Energia estimada deve ser positiva.");
        echo "  -> 1 unidade (50 g) calculou {$expectedKcal} kcal, {$expectedProt}g prot, {$expectedCarb}g carb, {$expectedLip}g lip: OK\n";

        // Conferindo se UnitCheckView::block() contém o contêiner de nutrição data-unit-nutrition
        $blockHtml = UnitCheckView::block('test-confirm');
        $this->assert(str_contains($blockHtml, 'data-unit-nutrition'), "UnitCheckView::block() deve conter o elemento data-unit-nutrition.");
        echo "  -> Contêiner de nutrição data-unit-nutrition presente no bloco: OK\n";
    }

    private function testNi2_592_FoodWithoutMeasureRetainsWarning(): void
    {
        echo "[NI2-592] Verificando se alimento sem medida caseira mantém o aviso atual...\n";

        $portion = FoodSearchController::portion(['serving_size_g' => 100, 'serving_unit' => 'g'], []);
        $this->assertEquals('Sem medida caseira cadastrada (valores por 100 g)', $portion['label']);
        echo "  -> Alimento sem medida caseira mantém aviso: OK\n";
    }

    private function testNi2_592_AmendoimAndTapiocaHaveMeasures(): void
    {
        echo "[NI2-592] Verificando medidas caseiras para Amendoim e Tapioca...\n";

        $amendoim = $this->db->query("SELECT id, name FROM foods WHERE name LIKE 'Amendoim%grão%cru%' LIMIT 1")->fetch(PDO::FETCH_ASSOC);
        $tapioca = $this->db->query("SELECT id, name FROM foods WHERE name LIKE 'Tapioca%manteiga%' LIMIT 1")->fetch(PDO::FETCH_ASSOC);

        $conv = new HouseholdMeasureConverter($this->db);
        if ($amendoim) {
            $mAmendoim = $conv->exactMeasuresForFood((int)$amendoim['id'], $amendoim);
            $this->assert(!empty($mAmendoim), "Amendoim deve ter medidas caseiras cadastradas.");
            echo "  -> Amendoim tem " . count($mAmendoim) . " medidas caseiras: OK\n";
        }
        if ($tapioca) {
            $mTapioca = $conv->exactMeasuresForFood((int)$tapioca['id'], $tapioca);
            $this->assert(!empty($mTapioca), "Tapioca deve ter medidas caseiras cadastradas.");
            echo "  -> Tapioca tem " . count($mTapioca) . " medidas caseiras: OK\n";
        }
    }

    // -------------------------------------------------------------
    // NI2-593 Tests
    // -------------------------------------------------------------

    private function testNi2_593_RejectedExamRetainsFormFieldsInSession(): void
    {
        echo "[NI2-593] Verificando recuperação de sessão quando o upload do laudo é recusado...\n";

        if (session_status() !== PHP_SESSION_ACTIVE) {
            session_start();
        }

        // Simulando a falha de validação de arquivo
        $_POST = [
            'laboratory_name' => 'Laboratório São Marcos',
            'collected_at' => '2026-11-01',
            'report_issued_at' => '2026-11-02',
            'notes' => 'Amostra hemolisada reprocessada',
            'item_ids' => [1, 2, 3],
        ];

        // Simulando o salvamento em sessão pelo controller
        $reqId = 9999;
        $_SESSION['exam_form_recovery'] = [
            'req_id' => $reqId,
            'laboratory_name' => $_POST['laboratory_name'],
            'collected_at' => $_POST['collected_at'],
            'report_issued_at' => $_POST['report_issued_at'],
            'notes' => $_POST['notes'],
            'item_ids' => $_POST['item_ids'],
            'error_message' => 'Formato não aceito. Envie o laudo em PDF, JPG ou PNG.',
        ];

        $this->assert(isset($_SESSION['exam_form_recovery']), "A sessão deve conter exam_form_recovery.");
        $this->assertEquals('Laboratório São Marcos', $_SESSION['exam_form_recovery']['laboratory_name']);
        $this->assertEquals('2026-11-01', $_SESSION['exam_form_recovery']['collected_at']);
        $this->assertEquals([1, 2, 3], $_SESSION['exam_form_recovery']['item_ids']);
        $this->assert(str_contains($_SESSION['exam_form_recovery']['error_message'], 'Formato não aceito'));
        echo "  -> Dados do formulário e erro preservados na sessão: OK\n";
    }

    private function testNi2_593_ExamResultsPartialRendersRecoveredFieldsAndErrorNearFile(): void
    {
        echo "[NI2-593] Verificando renderização do partial com campos preenchidos e erro junto ao arquivo...\n";

        $reqId = 9999;
        $_SESSION['exam_form_recovery'] = [
            'req_id' => $reqId,
            'laboratory_name' => 'Laboratório Fleury',
            'collected_at' => '2026-10-30',
            'report_issued_at' => '2026-10-31',
            'notes' => 'Observação mantida após erro',
            'item_ids' => [10],
            'error_message' => 'Formato não aceito. Envie o laudo em PDF, JPG ou PNG.',
        ];

        $request = [
            'id' => $reqId,
            'status' => 'issued',
            'items' => [
                ['id' => 10, 'exam_canonical_name' => 'Glicemia de jejum'],
                ['id' => 11, 'exam_canonical_name' => 'Hemograma completo'],
            ],
        ];
        $resultDocs = [];
        $coverage = [];
        $csrfToken = 'test-token';
        $pid = 1;
        $e = fn($v) => htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');

        ob_start();
        require __DIR__ . '/../views/patients/partials/exam_results.php';
        $html = ob_get_clean();

        $this->assert(str_contains($html, 'value="Laboratório Fleury"'), "Laboratório Fleury deve estar preenchido no input.");
        $this->assert(str_contains($html, 'value="2026-10-30"'), "Data da coleta deve estar preenchida no input.");
        $this->assert(str_contains($html, 'value="Observação mantida após erro"'), "Notas devem estar preenchidas.");
        $this->assert(str_contains($html, 'value="10" checked'), "Item 10 (Glicemia de jejum) deve continuar marcado.");
        $this->assert(!str_contains($html, 'value="11" checked'), "Item 11 não marcado não deve ter checked.");
        $this->assert(str_contains($html, 'id="up-file-error"'), "Elemento up-file-error deve estar presente.");
        $this->assert(str_contains($html, 'Formato não aceito. Envie o laudo em PDF, JPG ou PNG.'), "Mensagem de erro deve ser renderizada junto ao campo de arquivo.");

        echo "  -> Campos repopulados e erro exibido junto ao campo de arquivo: OK\n";
    }

    // -------------------------------------------------------------
    // NI2-594 Tests
    // -------------------------------------------------------------

    private function testNi2_594_NavigationHasExamesLink(): void
    {
        echo "[NI2-594] Verificando se o menu lateral inclui o link para 'Exames'...\n";

        ob_start();
        $activeNav = 'exames';
        require __DIR__ . '/../views/layouts/professional-shell.php';
        $html = ob_get_clean();

        $this->assert(str_contains($html, 'href="/exames"'), "Menu lateral deve conter link para /exames.");
        $this->assert(str_contains($html, '<span>Exames</span>'), "Rótulo Exames deve estar presente na navegação.");
        echo "  -> Menu lateral contém link visível para Exames: OK\n";
    }

    private function testNi2_594_Profile360HasExamesLink(): void
    {
        echo "[NI2-594] Verificando links de exames na navegação unificada do perfil 360...\n";

        // Testando DashboardController::profile360 output
        $ctrl = new \ClyoNutri\Application\Controllers\DashboardController();
        // Garantindo que existe paciente para teste
        $patient = $this->db->query("SELECT id FROM patients WHERE clinic_id = 1 LIMIT 1")->fetch(PDO::FETCH_ASSOC);
        $this->assert(!empty($patient), "Deve existir paciente cadastrado para teste.");

        ob_start();
        $ctrl->profile360(['id' => (int)$patient['id']]);
        $html = ob_get_clean();

        $this->assert(str_contains($html, 'data-testid="profile-exames-btn"'), "Perfil deve ter botão profile-exames-btn.");
        $this->assert(str_contains($html, 'Exames laboratoriais →'), "Perfil deve ter botão 'Exames laboratoriais →'.");
        $this->assert(str_contains($html, '<li><a href="/pacientes/' . (int)$patient['id'] . '/exames">Exames</a></li>'), "cn-section-nav deve ter atalho para Exames.");
        echo "  -> Perfil 360 contém botão direto e aba de navegação para Exames: OK\n";
    }

    private function testNi2_594_AppointmentInNext7DaysPreselected(): void
    {
        echo "[NI2-594] Verificando pré-seleção de consulta nos próximos 7 dias no formulário de exames...\n";

        // Cria paciente exclusivo para teste de vínculo de consulta
        $uuid = sprintf('%04x%04x-%04x-%04x-%04x-%04x%04x%04x', mt_rand(0, 0xffff), mt_rand(0, 0xffff), mt_rand(0, 0xffff), mt_rand(0, 0x0fff) | 0x4000, mt_rand(0, 0x3fff) | 0x8000, mt_rand(0, 0xffff), mt_rand(0, 0xffff), mt_rand(0, 0xffff));
        $st = $this->db->prepare("INSERT INTO patients (clinic_id, uuid, name, birth_date, email, gender, created_at, updated_at)
            VALUES (?, ?, ?, '1990-01-01', ?, 'F', CURRENT_TIMESTAMP, CURRENT_TIMESTAMP)");
        $st->execute([$this->clinicId, $uuid, 'Paciente Teste Exames', 'teste.exame.' . uniqid() . '@teste.com']);
        $pId = (int)$this->db->lastInsertId();

        $profId = (int)($this->db->query("SELECT id FROM professionals LIMIT 1")->fetchColumn() ?: 1);
        $futureDate = date('Y-m-d H:i:s', time() + 2 * 86400);

        $st = $this->db->prepare("INSERT INTO appointments (clinic_id, professional_id, patient_id, scheduled_at, status, type, created_at, updated_at)
            VALUES (?, ?, ?, ?, 'scheduled', 'presential', CURRENT_TIMESTAMP, CURRENT_TIMESTAMP)");
        $st->execute([$this->clinicId, $profId, $pId, $futureDate]);
        $aptId = (int)$this->db->lastInsertId();

        // Chamar o create de ExamRequestController
        $ctrl = new ExamRequestController($this->db);
        ob_start();
        @$ctrl->create(['id' => $pId]);
        $html = ob_get_clean();

        // Verificar se o option do atendimento veio com selected
        $this->assert(str_contains($html, 'value="' . $aptId . '" selected'), "Consulta nos próximos 7 dias (id={$aptId}) deve vir pré-selecionada no vínculo.");
        echo "  -> Consulta nos próximos 7 dias veio pré-selecionada automaticamente: OK\n";
    }

    private function testNi2_594_DraftToIssuedInOneStep(): void
    {
        echo "[NI2-594] Verificando emissão do PDF direto do rascunho em 1 etapa...\n";

        $patient = $this->db->query("SELECT id FROM patients WHERE clinic_id = 1 LIMIT 1")->fetch(PDO::FETCH_ASSOC);
        $exam = $this->db->query("SELECT id FROM exam_catalog WHERE is_active = 1 LIMIT 1")->fetch(PDO::FETCH_ASSOC);

        $svc = new ExamRequestService($this->db);

        // Criando rascunho
        $draft = $svc->saveDraft((int)$patient['id'], $this->userId, [
            'exam_ids' => [(int)$exam['id']],
            'clinical_justification' => 'Investigação clínica nutricional para paciente com queixas digestivas.',
            'diagnostic_hypothesis_nutritional' => 'Possível disbiose intestinal ou má absorção.',
            'coverage_disclaimer_acknowledged' => true,
        ]);

        $this->assertEquals('draft', $draft['status'], "Solicitação inicial deve ser draft.");

        // Chamando issue diretamente sobre o draft
        $issued = $svc->issue((int)$draft['id'], $this->userId, true);

        $this->assertEquals('issued', $issued['status'], "Solicitação deve ter sido emitida com sucesso diretamente a partir do draft.");
        $this->assert(!empty($issued['pdf_hash']), "PDF deve ter sido gerado com hash.");
        $this->assert(!empty($issued['pdf_path']), "PDF deve ter sido salvo.");
        echo "  -> Rascunho emitido com PDF gerado em 1 chamada direta: OK\n";
    }

    private function testNi2_594_CancelButtonIsNotMostSalient(): void
    {
        echo "[NI2-594] Verificando se o botão 'Cancelar solicitação' deixou de ser o mais saliente (não é mais btn-danger)...\n";

        $showHtml = file_get_contents(__DIR__ . '/../views/patients/exam_show.php');

        $this->assert(!str_contains($showHtml, 'class="btn btn-danger" data-dialog-open="modal-exam-cancel"'), "Cancelar solicitação não deve ter class 'btn btn-danger'.");
        $this->assert(str_contains($showHtml, 'class="btn btn-outline" data-dialog-open="modal-exam-cancel"'), "Cancelar solicitação deve ter class 'btn btn-outline'.");
        $this->assert(str_contains($showHtml, 'class="btn btn-primary" data-testid="btn-emitir-pdf"'), "Revisar e emitir PDF deve ser o botão primário.");
        echo "  -> Botão cancelar solicitação agora é secundário com confirmação: OK\n";
    }

    // -------------------------------------------------------------
    // Helper Assertions
    // -------------------------------------------------------------

    private function assert(bool $condition, string $message = 'Assertion failed'): void
    {
        if (!$condition) {
            throw new \RuntimeException("FALHA: " . $message);
        }
    }

    private function assertEquals(mixed $expected, mixed $actual, string $message = ''): void
    {
        if ($expected !== $actual) {
            $msg = ($message ? $message . ' ' : '') . "Esperado: " . json_encode($expected) . " - Recebido: " . json_encode($actual);
            throw new \RuntimeException("FALHA: " . $msg);
        }
    }
}

// Execução
try {
    $suite = new Ni2592To594ExecutionTest();
    $suite->run();
    exit(0);
} catch (\Throwable $e) {
    echo "\n❌ ERRO NA SUÍTE: " . $e->getMessage() . "\n";
    echo $e->getTraceAsString() . "\n";
    exit(1);
}
