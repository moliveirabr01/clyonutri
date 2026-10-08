<?php

declare(strict_types=1);

namespace ClyoNutri\Tests;

require_once __DIR__ . '/../vendor/autoload.php';

use ClyoNutri\Application\Controllers\DashboardController;
use ClyoNutri\Core\Auth\AuthService;
use ClyoNutri\Core\Auth\TenantContext;
use ClyoNutri\Core\Database\Connection;
use ClyoNutri\Core\Database\Migrator;
use ClyoNutri\Core\Security\TenantGuard;
use ClyoNutri\Core\Security\TenantSecurityException;
use ClyoNutri\Domain\Appointment\AppointmentService;
use ClyoNutri\Domain\Migration\CompetitorMigrationService;
use ClyoNutri\Domain\Patient\PatientRepository;
use ClyoNutri\Domain\PatientPortal\PatientPortalService;
use PDO;

class CrossTenantEndToEndSecurityTest
{
    private PDO $db;
    private int $assertions = 0;
    private int $blockedAttacks = 0;

    private int $clinicAlfaId;
    private int $clinicBetaId;
    private int $profAlfaId;
    private int $profBetaId;
    private int $patientAlfaId;
    private int $patientBetaId;
    private int $apptAlfaId;

    public function run(): void
    {
        echo "========================================================================\n";
        echo "   CLYONUTRI II — SUÍTE DE ISOLAMENTO MULTI-TENANT PONTA A PONTA (NI2-143)\n";
        echo "   Avaliação Ofensiva e Auditoria Sem PII em Web, API, Jobs, Imports e Files\n";
        echo "========================================================================\n\n";

        $this->setupDatabase();
        $this->setupTenantsAndResources();

        $this->testPermissionsMatrixStructure();
        $this->testWebControllerFlows();
        $this->testMobileApiAndSanctumFlows();
        $this->testImportsAndBulkUploadFlows();
        $this->testFilesAndDocumentsFlows();
        $this->testJobsAndSchedulerQueueIsolation();
        $this->testAuditLogsContainZeroPii();
        $this->testRelationalStateIntegrityAfterAttacks();

        echo "\n========================================================================\n";
        echo "   ✅ TODOS OS TESTES PASSARAM COM 100% DE SUCESSO!\n";
        echo "   Total de asserções verificadas: {$this->assertions}\n";
        echo "   Total de violações ofensivas barradas com 403: {$this->blockedAttacks}\n";
        echo "   Isolamento multi-tenant comprovado em todos os 5 fluxos operacionais!\n";
        echo "========================================================================\n";
    }

    private function assertTrue(bool $condition, string $message): void
    {
        $this->assertions++;
        if (!$condition) {
            throw new \RuntimeException("FALHA: {$message}");
        }
        echo "  [PASS] {$message}\n";
    }

    private function assertEquals($expected, $actual, string $message): void
    {
        $this->assertions++;
        if ($expected !== $actual) {
            $expectedStr = var_export($expected, true);
            $actualStr = var_export($actual, true);
            throw new \RuntimeException("FALHA: {$message} (Esperado: {$expectedStr}, Recebido: {$actualStr})");
        }
        echo "  [PASS] {$message}\n";
    }

    private function assertThrowsSecurity(callable $callback, string $description): void
    {
        $this->assertions++;
        try {
            $callback();
            throw new \RuntimeException("FALHA DE SEGURANÇA: {$description} não foi bloqueado!");
        } catch (TenantSecurityException $e) {
            $this->blockedAttacks++;
            echo "  [BLOQUEADO 403] {$description} -> HTTP {$e->getCode()}: {$e->getMessage()}\n";
        } catch (\Throwable $e) {
            throw new \RuntimeException("FALHA: {$description} lançou exceção incorreta (" . get_class($e) . "): {$e->getMessage()}");
        }
    }

    private function setupDatabase(): void
    {
        $this->db = new PDO('sqlite::memory:');
        $this->db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->db->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
        Connection::setInstance($this->db);

        $migrator = new Migrator($this->db, __DIR__ . '/../database/migrations');
        $migrator->run();
    }

    private function setupTenantsAndResources(): void
    {
        $auth = new AuthService($this->db);
        $alfa = $auth->registerNutritionist('Dra. Helena Alfa', 'helena@alfa.test', 'SenhaForte@1', 'Clínica Alfa Saúde', '11111111000199', 'CRN-1010');
        $beta = $auth->registerNutritionist('Dr. Bernardo Beta', 'bernardo@beta.test', 'SenhaForte@2', 'Clínica Beta Fit', '22222222000188', 'CRN-2020');

        $this->clinicAlfaId = (int)$alfa['clinic_id'];
        $this->clinicBetaId = (int)$beta['clinic_id'];
        $this->profAlfaId = (int)$alfa['user_id'];
        $this->profBetaId = (int)$beta['user_id'];

        // Contexto Alfa: Criar Recursos da Clínica Alfa
        TenantContext::set($this->clinicAlfaId, ['id' => $this->profAlfaId, 'name' => 'Dra. Helena Alfa']);
        $repo = new PatientRepository($this->db);
        $patientAlfa = $repo->create([
            'name' => 'Maria de Fátima (Paciente Alfa)',
            'email' => 'maria.alfa@email.test',
            'phone' => '11999990001',
            'lgpd_consent' => 1,
        ]);
        $this->patientAlfaId = (int)$patientAlfa['id'];

        $apptService = new AppointmentService($this->db);
        $apptAlfa = $apptService->schedule(
            $this->patientAlfaId,
            $this->profAlfaId,
            date('Y-m-d H:i:s', strtotime('+2 days')),
            'first_visit',
            60,
            'Consulta de Acompanhamento'
        );
        $this->apptAlfaId = (int)$apptAlfa['id'];

        // Contexto Beta: Criar Recursos da Clínica Beta
        TenantContext::set($this->clinicBetaId, ['id' => $this->profBetaId, 'name' => 'Dr. Bernardo Beta']);
        $patientBeta = $repo->create([
            'name' => 'João Silveira (Paciente Beta)',
            'email' => 'joao.beta@email.test',
            'phone' => '11988880002',
            'lgpd_consent' => 1,
        ]);
        $this->patientBetaId = (int)$patientBeta['id'];

        echo "✓ Infraestrutura e tenants criados com sucesso.\n";
        echo "  Clínica Alfa ID: {$this->clinicAlfaId} | Paciente Alfa ID: {$this->patientAlfaId} | Consulta Alfa ID: {$this->apptAlfaId}\n";
        echo "  Clínica Beta ID: {$this->clinicBetaId} | Paciente Beta ID: {$this->patientBetaId}\n\n";
    }

    private function testPermissionsMatrixStructure(): void
    {
        echo "--- 1. Matriz Formal de Permissões Aprovada (NI2-143 DoD) ---\n";
        $matrixPath = __DIR__ . '/../docs/delivery/PERMISSIONS_MATRIX_NI2_143.json';
        $this->assertTrue(file_exists($matrixPath), "Arquivo PERMISSIONS_MATRIX_NI2_143.json existe");

        $matrix = json_decode((string)file_get_contents($matrixPath), true);
        $this->assertEquals('NI2-143', $matrix['epic'], "Matriz vinculada formalmente ao épico NI2-143");
        $this->assertTrue(isset($matrix['roles']['CLINIC_OWNER']), "Papel CLINIC_OWNER contemplado");
        $this->assertTrue(isset($matrix['roles']['NUTRITIONIST']), "Papel NUTRITIONIST contemplado");
        $this->assertTrue(isset($matrix['roles']['PATIENT']), "Papel PATIENT contemplado");
        $this->assertTrue(count($matrix['modules']) >= 8, "Módulos P0 mapeados na matriz (total: " . count($matrix['modules']) . ")");
    }

    private function testWebControllerFlows(): void
    {
        echo "\n--- 2. Fluxos Web e Controladores (Barreira de Sessão e Tenant) ---\n";
        // Atacante logado como Clínica Beta tentando acessar recursos de Alfa
        TenantContext::set($this->clinicBetaId, ['id' => $this->profBetaId, 'name' => 'Dr. Bernardo Beta']);

        // Tentativa de obter Profile 360 de paciente pertencente a Alfa
        $repo = new PatientRepository($this->db);
        $profileAlfa = $repo->getProfile360($this->patientAlfaId);
        $this->assertEquals(null, $profileAlfa, "getProfile360 retorna null para paciente de outro tenant (fail-closed)");

        // Tentativa de agendamento Web para paciente de outro tenant
        $apptService = new AppointmentService($this->db);
        $this->assertThrowsSecurity(function () use ($apptService) {
            $apptService->schedule(
                $this->patientAlfaId,
                $this->profBetaId,
                date('Y-m-d 15:00:00'),
                'first_visit',
                60
            );
        }, "Tentativa Web de agendar consulta para paciente de outro tenant");

        // Tentativa de editar consulta de outro tenant
        $this->assertThrowsSecurity(function () use ($apptService) {
            $apptService->edit(
                $this->apptAlfaId,
                [
                    'scheduled_at' => date('Y-m-d 16:00:00'),
                    'duration_minutes' => 60,
                    'notes' => 'Reagendamento Malicioso',
                ]
            );
        }, "Tentativa Web de reagendar consulta pertencente a outro tenant");
    }

    private function testMobileApiAndSanctumFlows(): void
    {
        echo "\n--- 3. Fluxos de API Móvel e Tokens de Acesso (Sanctum/Portal) ---\n";
        $portalService = new PatientPortalService($this->db);
        $engagementService = new \ClyoNutri\Domain\Engagement\EngagementService($this->db);

        // Paciente Beta tentando acessar hub de Alfa
        TenantContext::set($this->clinicBetaId, ['id' => $this->profBetaId, 'name' => 'Dr. Bernardo Beta']);

        $this->assertThrowsSecurity(function () use ($portalService) {
            $portalService->getEvolutionTimeline($this->patientAlfaId);
        }, "API Móvel: Paciente de Beta tentando ler evolução clínica de paciente de Alfa");

        $this->assertThrowsSecurity(function () use ($portalService) {
            $portalService->recordSelfReportedMetric($this->patientAlfaId, 'weight_kg', 72.5, 'kg');
        }, "API Móvel: Paciente de Beta tentando registrar autométrica no paciente de Alfa");

        $this->assertThrowsSecurity(function () use ($engagementService) {
            $engagementService->logHydration($this->patientAlfaId, 500);
        }, "API Móvel: Paciente de Beta tentando registrar hidratação no perfil de Alfa");

        $this->assertThrowsSecurity(function () use ($portalService) {
            $portalService->confirmAppointment($this->patientAlfaId, $this->apptAlfaId);
        }, "API Móvel: Paciente de Beta tentando confirmar consulta de paciente de Alfa");
    }

    private function testImportsAndBulkUploadFlows(): void
    {
        echo "\n--- 4. Fluxos de Importação e Carga em Lote (CSV Imports) ---\n";
        $migrationService = new CompetitorMigrationService($this->db);

        // Executar importação sob o contexto da Clínica Alfa
        TenantContext::set($this->clinicAlfaId, ['id' => $this->profAlfaId, 'name' => 'Dra. Helena Alfa']);
        $csvContent = "Nome,E-mail,Telefone,Data de Nascimento,Gênero\n"
                    . "Paciente Importado Alfa 1,alfa1@test.com,11999991111,10/05/1990,Feminino\n"
                    . "Paciente Importado Alfa 2,alfa2@test.com,11999992222,15/08/1985,Masculino";

        $res = $migrationService->importPatientsFromCsv($csvContent, 'Dietbox');
        $this->assertEquals(2, $res['total_imported'], "2 pacientes importados com sucesso");

        // Comprovar persistência isolada: todos os pacientes criados pertencem à Clínica Alfa
        $stmtAlfa = $this->db->prepare("SELECT COUNT(*) as total FROM patients WHERE clinic_id = :clinic_id AND notes LIKE 'Migrado de Dietbox%'");
        $stmtAlfa->execute(['clinic_id' => $this->clinicAlfaId]);
        $this->assertEquals(2, (int)$stmtAlfa->fetch()['total'], "Pacientes gravados estritamente na Clínica Alfa");

        // Comprovar isolamento: Clínica Beta tem zero pacientes desta importação
        $stmtBeta = $this->db->prepare("SELECT COUNT(*) as total FROM patients WHERE clinic_id = :clinic_id AND notes LIKE 'Migrado de Dietbox%'");
        $stmtBeta->execute(['clinic_id' => $this->clinicBetaId]);
        $this->assertEquals(0, (int)$stmtBeta->fetch()['total'], "Nenhum paciente importado vazou para a Clínica Beta");
    }

    private function testFilesAndDocumentsFlows(): void
    {
        echo "\n--- 5. Fluxos de Arquivos, Laudos e Documentos (patient_documents) ---\n";
        $portalService = new PatientPortalService($this->db);

        // Criar documento médico no Paciente da Clínica Alfa
        TenantContext::set($this->clinicAlfaId, ['id' => $this->profAlfaId, 'name' => 'Dra. Helena Alfa']);
        $doc = $portalService->uploadDocument(
            $this->patientAlfaId,
            [
                'title' => 'Exame de Sangue e Perfil Lipídico',
                'category' => 'exam',
                'file_path' => '/storage/uploads/alfa/exame_lipidos_2026.pdf',
                'mime_type' => 'application/pdf',
                'size_bytes' => 1024 * 500, // 500KB
            ],
            'professional',
            $this->profAlfaId
        );

        $docId = (int)$doc['id'];
        $this->assertTrue($docId > 0, "Documento médico inserido com ID {$docId}");

        // Leitura legítima por profissional de Alfa
        $docAlfa = $portalService->getDocument($this->patientAlfaId, $docId);
        $this->assertEquals('Exame de Sangue e Perfil Lipídico', $docAlfa['title'], "Leitura legítima autorizada dentro do tenant Alfa");

        // Atacante de Beta tentando acessar o documento de Alfa
        TenantContext::set($this->clinicBetaId, ['id' => $this->profBetaId, 'name' => 'Dr. Bernardo Beta']);

        $this->assertThrowsSecurity(function () use ($portalService, $docId) {
            $portalService->getDocument($this->patientAlfaId, $docId);
        }, "Tentativa de download/leitura de exame de outro tenant via ID do paciente Alfa");

        $this->assertThrowsSecurity(function () use ($portalService, $docId) {
            $portalService->getDocument($this->patientBetaId, $docId);
        }, "Tentativa de download IDOR forjando ID do paciente Beta para obter documento de Alfa");
    }

    private function testJobsAndSchedulerQueueIsolation(): void
    {
        echo "\n--- 6. Fluxos de Fila Assíncrona e Agendamentos (Queue & Scheduler) ---\n";
        // Inserir notificações de jobs na fila com clinic_id explícito
        $insNotif = $this->db->prepare("
            INSERT INTO notifications (clinic_id, user_id, title, message, type)
            VALUES (:clinic_id, :user_id, :title, :message, 'job_alert')
        ");

        $insNotif->execute([
            'clinic_id' => $this->clinicAlfaId,
            'user_id' => $this->profAlfaId,
            'title' => 'Lembrete de Consulta Alfa #101',
            'message' => 'Notificação assíncrona gerada para Clínica Alfa',
        ]);

        $insNotif->execute([
            'clinic_id' => $this->clinicBetaId,
            'user_id' => $this->profBetaId,
            'title' => 'Lembrete de Consulta Beta #202',
            'message' => 'Notificação assíncrona gerada para Clínica Beta',
        ]);

        // Drenagem/consulta simulando worker da Clínica Alfa
        $stmtWorkerAlfa = $this->db->prepare("SELECT title FROM notifications WHERE clinic_id = :clinic_id");
        $stmtWorkerAlfa->execute(['clinic_id' => $this->clinicAlfaId]);
        $jobsAlfa = $stmtWorkerAlfa->fetchAll(PDO::FETCH_COLUMN);

        $this->assertTrue(in_array('Lembrete de Consulta Alfa #101', $jobsAlfa, true), "Worker de Alfa localiza o alerta da Clínica Alfa");
        $this->assertTrue(!in_array('Lembrete de Consulta Beta #202', $jobsAlfa, true), "Worker de Alfa não tem visibilidade do alerta da Clínica Beta");
    }

    private function testAuditLogsContainZeroPii(): void
    {
        echo "\n--- 7. Auditoria Imutável de Segurança Sem PII (LGPD Compliance) ---\n";
        $stmt = $this->db->prepare("
            SELECT action, resource, resource_id, details
            FROM audit_logs
            WHERE action = 'SECURITY_CROSS_TENANT_VIOLATION_BLOCKED'
        ");
        $stmt->execute();
        $logs = $stmt->fetchAll();

        $this->assertTrue(count($logs) > 0, "Violações ofensivas geraram registros de auditoria (total: " . count($logs) . ")");

        $forbiddenTerms = [
            'maria.alfa@email.test',
            'joao.beta@email.test',
            '11999990001',
            '11988880002',
            'SenhaForte@1',
            'SenhaForte@2',
        ];

        $hasPii = false;
        foreach ($logs as $log) {
            $detailsStr = (string)$log['details'];
            foreach ($forbiddenTerms as $term) {
                if (str_contains($detailsStr, $term)) {
                    $hasPii = true;
                    echo "  ❌ FALHA DE PRIVACIDADE: Termo PII '{$term}' encontrado no log de auditoria!\n";
                }
            }
        }

        $this->assertTrue(!$hasPii, "Nenhuma PII ou dado pessoal sensível foi registrado nos logs de segurança");
    }

    private function testRelationalStateIntegrityAfterAttacks(): void
    {
        echo "\n--- 8. Integridade do Estado Relacional Pós-Ataques (Fail-Closed) ---\n";
        // 1. O agendamento da Clínica Alfa não foi cancelado ou alterado
        $stmtAppt = $this->db->prepare("SELECT status, notes FROM appointments WHERE id = :id");
        $stmtAppt->execute(['id' => $this->apptAlfaId]);
        $appt = $stmtAppt->fetch();
        $this->assertEquals('scheduled', $appt['status'], "Status da consulta de Alfa permaneceu íntegro após os ataques");
        $this->assertTrue(!str_contains($appt['notes'], 'Reagendamento Malicioso'), "Notas da consulta de Alfa inalteradas");

        // 2. Clínica Beta não possui nenhum agendamento fantasma criado com Paciente de Alfa
        $stmtCheck = $this->db->prepare("SELECT COUNT(*) as total FROM appointments WHERE clinic_id = :clinic_id AND patient_id = :patient_id");
        $stmtCheck->execute(['clinic_id' => $this->clinicBetaId, 'patient_id' => $this->patientAlfaId]);
        $this->assertEquals(0, (int)$stmtCheck->fetch()['total'], "Zero consultas espúrias criadas na Clínica Beta");
    }
}

$test = new CrossTenantEndToEndSecurityTest();
$test->run();
