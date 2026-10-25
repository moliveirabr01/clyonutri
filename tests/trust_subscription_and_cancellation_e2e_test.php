<?php

declare(strict_types=1);

namespace ClyoNutri\Tests;

require_once __DIR__ . '/../vendor/autoload.php';

use ClyoNutri\Core\Database\Connection;
use ClyoNutri\Core\Security\Env;
use ClyoNutri\Core\Tenant\TenantContext;
use ClyoNutri\Domain\Subscription\SubscriptionService;
use PDO;

Env::load(__DIR__ . '/../.env');

final class TrustSubscriptionAndCancellationE2ETest
{
    private PDO $db;
    private SubscriptionService $service;

    public function __construct()
    {
        $this->db = Connection::getInstance();
        $this->service = new SubscriptionService($this->db);
    }

    public function run(): void
    {
        echo "== Início dos Testes E2E: Assinatura SaaS e Cancelamento Autosserviço (NI2-364, NI2-365, NI2-374) ==\n";

        $this->testCatalogIntegrity();
        $this->testDefaultSubscriptionInitializationAndTransparency();
        $this->testRbacOwnerAuthorization();
        $this->testMultiTenantIsolation();
        $this->testSelfServiceCancellationWithReason();
        $this->testCancellationWithOptionalSurveySkipped();
        $this->testCancellationIdempotency();
        $this->testAuditLogsComplianceWithoutPhi();

        echo "\n✓ TODOS OS TESTES DE ASSINATURA E CANCELAMENTO FORAM APROVADOS COM SUCESSO!\n";
    }

    private function assertTrue(bool $condition, string $message): void
    {
        if (!$condition) {
            throw new \RuntimeException("FALHA: {$message}");
        }
        echo "  [OK] {$message}\n";
    }

    private function assertEquals($expected, $actual, string $message): void
    {
        if ($expected !== $actual) {
            throw new \RuntimeException("FALHA: {$message} (Esperado: " . var_export($expected, true) . ", Obtido: " . var_export($actual, true) . ")");
        }
        echo "  [OK] {$message}\n";
    }

    private function testCatalogIntegrity(): void
    {
        echo "\n--- [1] Verificando Integridade do Catálogo de Planos SaaS (saas_plans) ---\n";
        $stmt = $this->db->query("SELECT * FROM saas_plans ORDER BY id ASC");
        $plans = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $this->assertTrue(count($plans) >= 3, "Catálogo contém os planos cadastrados.");
        
        $codes = array_column($plans, 'code');
        $this->assertTrue(in_array('START-01', $codes, true), "Plano START-01 presente.");
        $this->assertTrue(in_array('PRO-02', $codes, true), "Plano PRO-02 presente.");
        $this->assertTrue(in_array('ELITE-03', $codes, true), "Plano ELITE-03 presente.");

        foreach ($plans as $p) {
            if ($p['code'] === 'PRO-02') {
                $this->assertEquals(18900, (int)$p['price_cents'], "Preço do ClyoNutri Profissional é R$ 189,00 (18900 centavos).");
                $this->assertEquals(3, (int)$p['max_professionals'], "Limite de 3 profissionais para PRO-02.");
                $this->assertEquals(0, (int)$p['max_patients'], "Pacientes ilimitados para PRO-02 (0).");
            }
        }
    }

    private function testDefaultSubscriptionInitializationAndTransparency(): void
    {
        echo "\n--- [2] Transparência Contratual e Inicialização da Assinatura (NI2-364 / TRUST-01) ---\n";
        
        // Criar clínica e titular para teste
        $suffix = (string)mt_rand(100000, 999999);
        $clinicId = $this->createTestClinic("Consultório Dra. Teste {$suffix}");
        $ownerId = $this->createTestUser("Titular {$suffix}", "titular_{$suffix}@clyonutri.com.br", 'owner', $clinicId);

        $sub = $this->service->getClinicSubscription($clinicId);

        $this->assertEquals('trialing', $sub['status'], "Assinatura inicializada em período de avaliação ('trialing').");
        $this->assertTrue($sub['is_trial'], "Flag is_trial é true.");
        $this->assertEquals(14, (int)$sub['days_left_in_trial'], "14 dias restantes de avaliação.");
        $this->assertTrue(!empty($sub['trial_ends_at']), "trial_ends_at devidamente preenchido.");
        $this->assertEquals('PRO-02', $sub['plan_code'], "Plano vinculado é PRO-02.");
        $this->assertEquals(18900, (int)$sub['price_cents'], "Preço do plano de R$ 189,00 registrado com precisão.");
        $this->assertEquals('monthly', $sub['billing_cycle'], "Ciclo mensal de faturamento registrado.");
        
        // Vigência calculada
        $this->assertTrue(!empty($sub['current_period_end']), "Data de vigência futura está preenchida.");
        $end = new \DateTimeImmutable($sub['current_period_end']);
        $start = new \DateTimeImmutable($sub['current_period_start']);
        $this->assertTrue($end > $start, "Vigência final é posterior ao início.");

        // Consumo em tempo real
        $this->assertTrue(isset($sub['usage']['active_professionals']), "Métrica de profissionais ativos retornada.");
        $this->assertEquals(1, (int)$sub['usage']['active_professionals'], "1 profissional ativo contabilizado.");

        // Zero faturas fictícias geradas (NI2-450)
        $this->assertEquals(0, count($sub['invoices']), "Zero faturas fictícias geradas durante o período de avaliação.");
    }

    private function testRbacOwnerAuthorization(): void
    {
        echo "\n--- [3] Validação de RBAC Server-Side (Apenas Titular/Admin pode gerenciar) ---\n";
        
        $suffix = (string)mt_rand(100000, 999999);
        $clinicId = $this->createTestClinic("Clínica RBAC {$suffix}");
        $ownerId = $this->createTestUser("Dono {$suffix}", "dono_{$suffix}@clyonutri.com.br", 'owner', $clinicId);
        $nutriId = $this->createTestUser("Nutri {$suffix}", "nutri_{$suffix}@clyonutri.com.br", 'nutritionist', $clinicId);

        // Owner pode gerenciar
        $this->assertTrue($this->service->canManageSubscription($clinicId, $ownerId), "Titular (owner) tem permissão de gestão de assinatura.");

        // Nutricionista associado NÃO pode gerenciar
        $this->assertTrue(!$this->service->canManageSubscription($clinicId, $nutriId), "Nutricionista membro NÃO tem permissão de gestão de assinatura.");

        // Tentativa direta de cancelamento por membro sem permissão deve falhar
        $blocked = false;
        try {
            $this->service->cancelSubscription($clinicId, $nutriId, 'cost', 'Tentativa não autorizada');
        } catch (\DomainException $e) {
            $blocked = true;
        }
        $this->assertTrue($blocked, "Tentativa de cancelamento por não-titular foi estritamente barrada com DomainException.");
    }

    private function testMultiTenantIsolation(): void
    {
        echo "\n--- [4] Isolamento Multi-Tenant (Tenant A não afeta Tenant B) ---\n";
        
        $suffixA = (string)mt_rand(100000, 999999);
        $suffixB = (string)mt_rand(100000, 999999);

        $clinicA = $this->createTestClinic("Clínica A {$suffixA}");
        $ownerA = $this->createTestUser("Titular A", "titular_a_{$suffixA}@clyonutri.com.br", 'owner', $clinicA);

        $clinicB = $this->createTestClinic("Clínica B {$suffixB}");
        $ownerB = $this->createTestUser("Titular B", "titular_b_{$suffixB}@clyonutri.com.br", 'owner', $clinicB);

        // Titular A tenta gerenciar ou cancelar a assinatura da Clínica B
        $this->assertTrue(!$this->service->canManageSubscription($clinicB, $ownerA), "Titular da Clínica A não tem permissão na Clínica B.");

        $blocked = false;
        try {
            $this->service->cancelSubscription($clinicB, $ownerA, 'cost', 'Cross-tenant');
        } catch (\DomainException $e) {
            $blocked = true;
        }
        $this->assertTrue($blocked, "Ataque cross-tenant barrado com isolamento multi-tenant rigoroso.");
    }

    private function testSelfServiceCancellationWithReason(): void
    {
        echo "\n--- [5] Cancelamento Autosserviço sem Barreiras com Motivo (NI2-365 / TRUST-02) ---\n";
        
        $suffix = (string)mt_rand(100000, 999999);
        $clinicId = $this->createTestClinic("Clínica Cancelamento {$suffix}");
        $ownerId = $this->createTestUser("Titular C", "titular_c_{$suffix}@clyonutri.com.br", 'owner', $clinicId);

        $result = $this->service->cancelSubscription(
            $clinicId,
            $ownerId,
            'cost',
            'Preciso reduzir custos momentaneamente.',
            '192.168.1.50',
            'Mozilla/5.0 TestBrowser'
        );

        $this->assertTrue($result['success'], "Cancelamento efetuado com sucesso.");
        $this->assertEquals('canceled', $result['status'], "Status retornado é 'canceled'.");
        $this->assertTrue(str_starts_with($result['protocol'], 'CAN-'), "Protocolo inequívoco gerado com prefixo CAN-.");
        
        // Conferir persistência no banco
        $sub = $this->service->getClinicSubscription($clinicId);
        $this->assertEquals('canceled', $sub['status'], "Assinatura no banco atualizada para 'canceled'.");
        $this->assertEquals($result['protocol'], $sub['cancellation_protocol'], "Protocolo conferido no banco de dados.");
        $this->assertEquals('cost', $sub['cancellation_reason_category'], "Categoria do motivo persistida.");

        // Conferir persistência na tabela de pesquisas
        $stmtSurvey = $this->db->prepare("SELECT * FROM cancellation_surveys WHERE protocol = :p");
        $stmtSurvey->execute(['p' => $result['protocol']]);
        $survey = $stmtSurvey->fetch(PDO::FETCH_ASSOC);

        $this->assertTrue(!empty($survey), "Registro persistido em cancellation_surveys.");
        $this->assertEquals('cost', $survey['reason_category'], "Motivo conferido na pesquisa.");
        $this->assertEquals($ownerId, (int)$survey['user_id'], "ID do autor conferido na pesquisa.");
    }

    private function testCancellationWithOptionalSurveySkipped(): void
    {
        echo "\n--- [6] Motivos de Cancelamento 100% Opcionais (NI2-374 / TRUST-11) ---\n";
        
        $suffix = (string)mt_rand(100000, 999999);
        $clinicId = $this->createTestClinic("Clínica Pular Pesquisa {$suffix}");
        $ownerId = $this->createTestUser("Titular D", "titular_d_{$suffix}@clyonutri.com.br", 'owner', $clinicId);

        // Cancelar com motivo nulo e feedback nulo (usuário optou por não responder)
        $result = $this->service->cancelSubscription(
            $clinicId,
            $ownerId,
            null,
            null,
            '127.0.0.1',
            'Mozilla/5.0 TestBrowser'
        );

        $this->assertTrue($result['success'], "Cancelamento efetuado com sucesso mesmo pulando toda a pesquisa.");
        $this->assertEquals('canceled', $result['status'], "Status atualizado para 'canceled'.");
        $this->assertTrue(!empty($result['protocol']), "Protocolo de cancelamento emitido sem bloqueio.");

        $sub = $this->service->getClinicSubscription($clinicId);
        $this->assertEquals(null, $sub['cancellation_reason_category'], "Motivo nulo persistido sem travar fluxo.");
    }

    private function testCancellationIdempotency(): void
    {
        echo "\n--- [7] Idempotência do Cancelamento (Requisições Duplicadas) ---\n";
        
        $suffix = (string)mt_rand(100000, 999999);
        $clinicId = $this->createTestClinic("Clínica Idempotência {$suffix}");
        $ownerId = $this->createTestUser("Titular E", "titular_e_{$suffix}@clyonutri.com.br", 'owner', $clinicId);

        // Primeiro cancelamento
        $first = $this->service->cancelSubscription($clinicId, $ownerId, 'other', 'Primeira chamada');
        $this->assertTrue($first['success'], "Primeiro cancelamento executado.");
        $protocol1 = $first['protocol'];

        // Segundo cancelamento (duplicata)
        $second = $this->service->cancelSubscription($clinicId, $ownerId, 'cost', 'Segunda chamada');
        $this->assertTrue($second['success'], "Segundo cancelamento retornou sucesso com idempotência.");
        $this->assertTrue($second['already_canceled'], "Indicador already_canceled = true retornado.");
        $this->assertEquals($protocol1, $second['protocol'], "Mesmo protocolo original mantido, sem recriar ou duplicar efeitos.");
    }

    private function testAuditLogsComplianceWithoutPhi(): void
    {
        echo "\n--- [8] Conformidade de Auditoria sem PHI (Zero dados de saúde em audit_logs) ---\n";
        
        $stmt = $this->db->query("
            SELECT details FROM audit_logs 
            WHERE action = 'subscription.canceled' 
            ORDER BY id DESC LIMIT 5
        ");
        $logs = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $this->assertTrue(count($logs) > 0, "Registros de auditoria encontrados para cancelamento.");

        $forbiddenKeywords = ['patient', 'paciente', 'prontuario', 'diagnostico', 'refeicao', 'calorias', 'antropometria'];
        foreach ($logs as $log) {
            $details = mb_strtolower($log['details'] ?? '');
            foreach ($forbiddenKeywords as $word) {
                $this->assertTrue(!str_contains($details, $word), "Audit log NÃO contém termo clínico confidencial ('{$word}').");
            }
            $this->assertTrue(str_contains($details, 'protocol'), "Audit log contém chave 'protocol' com rastreabilidade operacional.");
        }
    }

    // Helpers
    private function createTestClinic(string $name): int
    {
        $uuid = bin2hex(random_bytes(16));
        $stmt = $this->db->prepare("INSERT INTO clinics (uuid, name, document, email, phone, status) VALUES (?, ?, ?, ?, ?, 'active')");
        $stmt->execute([
            $uuid,
            $name,
            '00.' . mt_rand(100,999) . '.' . mt_rand(100,999) . '/0001-' . mt_rand(10,99),
            'clinic_' . mt_rand(10000, 99999) . '@clyonutri.com.br',
            '119' . mt_rand(10000000, 99999999)
        ]);
        return (int)$this->db->lastInsertId();
    }

    private function createTestUser(string $name, string $email, string $role, int $clinicId): int
    {
        $uuid = bin2hex(random_bytes(16));
        $stmtUser = $this->db->prepare("
            INSERT INTO users (uuid, name, email, password_hash, role, status, is_verified) 
            VALUES (?, ?, ?, ?, ?, 'active', 1)
        ");
        $stmtUser->execute([
            $uuid,
            $name,
            $email,
            password_hash('SenhaSegura123!', PASSWORD_BCRYPT),
            $role === 'owner' ? 'admin' : $role
        ]);
        $userId = (int)$this->db->lastInsertId();

        $stmtMember = $this->db->prepare("
            INSERT INTO clinic_members (clinic_id, user_id, role, status)
            VALUES (?, ?, ?, 'active')
        ");
        $stmtMember->execute([$clinicId, $userId, $role]);

        return $userId;
    }
}

// Execução
(new TrustSubscriptionAndCancellationE2ETest())->run();
