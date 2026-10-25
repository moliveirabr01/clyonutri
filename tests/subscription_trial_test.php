<?php

declare(strict_types=1);

namespace ClyoNutri\Tests;

require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/../bin/cron_scheduler.php';

use ClyoNutri\Core\Database\Connection;
use ClyoNutri\Core\Security\Env;
use ClyoNutri\Domain\Subscription\SubscriptionService;
use ClyoNutri\Infra\HostGatorCronScheduler;
use PDO;

Env::load(__DIR__ . '/../.env');

final class SubscriptionTrialTest
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
        echo "====================================================================\n";
        echo "   CLYONUTRI II — TESTES DO PERÍODO DE AVALIAÇÃO DE 14 DIAS (NI2-450)\n";
        echo "====================================================================\n";

        $this->testNewClinicInitializesInTrialWith14Days();
        $this->testNoFictitiousPaidInvoiceGenerated();
        $this->testSubscriptionViewDataAndHtml();
        $this->testTrialExpirationLifecycle();
        $this->testClinicalDataMutationPermissions();
        $this->testSanitizationOfLegacyFictitiousInvoices();
        $this->testCronSchedulerRunsTrialExpiration();

        echo "\n====================================================================\n";
        echo "   ✓ TODOS OS TESTES DE TRIAL E SANEAMENTO FORAM APROVADOS! (NI2-450)\n";
        echo "====================================================================\n";
    }

    private function assertTrue(bool $condition, string $message): void
    {
        if (!$condition) {
            throw new \RuntimeException("FALHA: {$message}");
        }
        echo "  [PASS] {$message}\n";
    }

    private function assertEquals($expected, $actual, string $message): void
    {
        if ($expected !== $actual) {
            throw new \RuntimeException("FALHA: {$message} (Esperado: " . var_export($expected, true) . ", Obtido: " . var_export($actual, true) . ")");
        }
        echo "  [PASS] {$message}\n";
    }

    private function testNewClinicInitializesInTrialWith14Days(): void
    {
        echo "\n[1] Inicialização de Nova Clínica em Período de Avaliação (Trial)...\n";

        $clinicId = $this->createTestClinic("Clínica Nova Trial");
        $sub = $this->service->getClinicSubscription($clinicId);

        $this->assertEquals('trialing', $sub['status'], "Status da assinatura é 'trialing'");
        $this->assertTrue($sub['is_trial'], "Flag is_trial é true");
        $this->assertEquals('PRO-02', $sub['plan_code'], "Plano padrão de degustação é PRO-02");
        $this->assertEquals(14, (int)$sub['days_left_in_trial'], "Dias restantes de trial é exatamente 14");
        $this->assertTrue(!empty($sub['trial_ends_at']), "trial_ends_at preenchido");

        $trialEnd = new \DateTimeImmutable($sub['trial_ends_at']);
        $start = new \DateTimeImmutable($sub['started_at']);
        $diffDays = (int)$start->diff($trialEnd)->days;
        $this->assertEquals(14, $diffDays, "Diferença entre início e término do trial é 14 dias");
    }

    private function testNoFictitiousPaidInvoiceGenerated(): void
    {
        echo "\n[2] Garantia de Ausência de Faturas Fictícias 'Pagas'...\n";

        $clinicId = $this->createTestClinic("Clínica Sem Fatura Falsa");
        $sub = $this->service->getClinicSubscription($clinicId);

        $this->assertEquals(0, count($sub['invoices']), "Nenhuma fatura associada à assinatura no período de teste");

        $stmt = $this->db->prepare("SELECT COUNT(*) FROM subscription_invoices WHERE clinic_id = :clinic_id");
        $stmt->execute(['clinic_id' => $clinicId]);
        $invoicesCount = (int)$stmt->fetchColumn();

        $this->assertEquals(0, $invoicesCount, "Zero registros em subscription_invoices para a clínica no trial");
    }

    private function testSubscriptionViewDataAndHtml(): void
    {
        echo "\n[3] Renderização Visual e Transparência na Tela /configuracoes/assinatura...\n";

        $clinicId = $this->createTestClinic("Clínica View Trial");
        $subscription = $this->service->getClinicSubscription($clinicId);

        // Renderizar a view capturando buffer
        $flashSuccess = null;
        $flashError = null;
        $reasons = $this->service->getCancellationReasons();
        ob_start();
        include __DIR__ . '/../views/settings/subscription.php';
        $html = ob_get_clean();

        $this->assertTrue(str_contains($html, 'Período de Avaliação Gratuita (14 dias)'), "View exibe banner de 14 dias");
        $this->assertTrue(str_contains($html, '● Período de Avaliação (Trial)'), "View exibe badge âmbar de Período de Avaliação");
        $this->assertTrue(str_contains($html, 'R$ 0,00'), "View exibe valor R$ 0,00 para recorrência no período de teste");
        $this->assertTrue(str_contains($html, 'sem necessidade de cartão de crédito'), "View reforça ausência de cartão de crédito");
        $this->assertTrue(!str_contains($html, '● Assinatura Ativa'), "View NÃO exibe 'Assinatura Ativa' durante o trial");
    }

    private function testTrialExpirationLifecycle(): void
    {
        echo "\n[4] Ciclo de Vida: Expiração do Período de Avaliação...\n";

        $clinicId = $this->createTestClinic("Clínica Expirando");
        $sub = $this->service->getClinicSubscription($clinicId);

        // Forçar data de trial vencida no banco
        $pastDate = (new \DateTimeImmutable('-1 day', new \DateTimeZone('America/Sao_Paulo')))->format('Y-m-d H:i:s');
        $stmt = $this->db->prepare("UPDATE clinic_subscriptions SET trial_ends_at = :past WHERE id = :id");
        $stmt->execute(['past' => $pastDate, 'id' => $sub['id']]);

        // Executar rotina de expiração
        $expiredCount = $this->service->expireTrials();
        $this->assertTrue($expiredCount >= 1, "expireTrials detectou e expirou pelo menos 1 assinatura");

        $updatedSub = $this->service->getClinicSubscription($clinicId);
        $this->assertEquals('expired', $updatedSub['status'], "Status da assinatura passou de 'trialing' para 'expired'");
    }

    private function testClinicalDataMutationPermissions(): void
    {
        echo "\n[5] Permissões de Mutação de Dados Clínicos (canMutateClinicalData)...\n";

        // 1. Trial ativo -> permitido
        $c1 = $this->createTestClinic("Clínica Permissão Trial");
        $this->assertTrue($this->service->canMutateClinicalData($c1), "Clínica em trial ativo pode cadastrar dados clínicos");

        // 2. Trial expirado -> bloqueado
        $c2 = $this->createTestClinic("Clínica Permissão Expirada");
        $sub2 = $this->service->getClinicSubscription($c2);
        $pastDate = (new \DateTimeImmutable('-2 days', new \DateTimeZone('America/Sao_Paulo')))->format('Y-m-d H:i:s');
        $this->db->prepare("UPDATE clinic_subscriptions SET status = 'expired', trial_ends_at = :past WHERE id = :id")
                 ->execute(['past' => $pastDate, 'id' => $sub2['id']]);
        $this->assertTrue(!$this->service->canMutateClinicalData($c2), "Clínica com trial expirado NÃO pode criar novos dados clínicos");

        // 3. Assinatura ativa -> permitido
        $c3 = $this->createTestClinic("Clínica Permissão Ativa");
        $sub3 = $this->service->getClinicSubscription($c3);
        $this->db->prepare("UPDATE clinic_subscriptions SET status = 'active' WHERE id = :id")
                 ->execute(['id' => $sub3['id']]);
        $this->assertTrue($this->service->canMutateClinicalData($c3), "Clínica com plano ativo pode criar novos dados clínicos");
    }

    private function testSanitizationOfLegacyFictitiousInvoices(): void
    {
        echo "\n[6] Saneamento de Faturas Fictícias e Assinaturas Legadas...\n";

        $clinicId = $this->createTestClinic("Clínica Legada Simulação");
        
        // Simular assinatura antiga do modelo anterior (active, sem trial, com fatura fictícia 'paid')
        $stmtPlan = $this->db->query("SELECT id, price_cents FROM saas_plans WHERE code = 'PRO-02' LIMIT 1");
        $plan = $stmtPlan->fetch(PDO::FETCH_ASSOC);

        $now = new \DateTimeImmutable('now', new \DateTimeZone('America/Sao_Paulo'));
        $startedAt = $now->modify('-5 days')->format('Y-m-d H:i:s');
        $periodEnd = $now->modify('+25 days')->format('Y-m-d H:i:s');

        $stmtSub = $this->db->prepare("
            INSERT INTO clinic_subscriptions (clinic_id, plan_id, status, billing_cycle, price_cents, started_at, current_period_start, current_period_end)
            VALUES (:cid, :pid, 'active', 'monthly', :price, :start, :start, :end)
        ");
        $stmtSub->execute([
            'cid' => $clinicId,
            'pid' => $plan['id'],
            'price' => $plan['price_cents'],
            'start' => $startedAt,
            'end' => $periodEnd,
        ]);
        $subId = (int)$this->db->lastInsertId();

        // Inserir fatura fictícia paga
        $invNum = 'FAT-LEGADA-' . bin2hex(random_bytes(6));
        $stmtInv = $this->db->prepare("
            INSERT INTO subscription_invoices (clinic_id, subscription_id, invoice_number, amount_cents, status, due_date, paid_at, payment_method)
            VALUES (:cid, :sub_id, :inv_num, :amount, 'paid', :due, :paid, 'credit_card')
        ");
        $stmtInv->execute([
            'cid' => $clinicId,
            'sub_id' => $subId,
            'inv_num' => $invNum,
            'amount' => $plan['price_cents'],
            'due' => $now->format('Y-m-d'),
            'paid' => $startedAt,
        ]);
        $invId = (int)$this->db->lastInsertId();

        // Executar rotina de saneamento
        $report = $this->service->sanitizeLegacySubscriptions();
        $this->assertTrue($report['voided_invoices'] >= 1, "Saneamento anulou a fatura fictícia");
        $this->assertTrue($report['total_sanitized'] >= 1, "Saneamento ajustou a assinatura sem pagamento real");

        // Conferir se fatura ficou 'void'
        $stmtCheckInv = $this->db->prepare("SELECT status FROM subscription_invoices WHERE id = :id");
        $stmtCheckInv->execute(['id' => $invId]);
        $this->assertEquals('void', $stmtCheckInv->fetchColumn(), "Fatura fictícia marcada como 'void'");

        // Conferir se assinatura virou 'trialing' com trial_ends_at calculado
        $stmtCheckSub = $this->db->prepare("SELECT status, trial_ends_at FROM clinic_subscriptions WHERE id = :id");
        $stmtCheckSub->execute(['id' => $subId]);
        $rowSub = $stmtCheckSub->fetch(PDO::FETCH_ASSOC);
        $this->assertEquals('trialing', $rowSub['status'], "Assinatura recente sem pagamento convertida para 'trialing'");
        $this->assertTrue(!empty($rowSub['trial_ends_at']), "trial_ends_at preenchido pelo saneamento");
    }

    private function testCronSchedulerRunsTrialExpiration(): void
    {
        echo "\n[7] Integração com HostGatorCronScheduler (expireSaaSTrials)...\n";

        $scheduler = new HostGatorCronScheduler();
        $report = $scheduler->run();

        $this->assertTrue(isset($report['trials_expired']), "Scheduler reportou chave trials_expired");
        $this->assertTrue(is_int($report['trials_expired']), "trials_expired é um inteiro");
    }

    private function createTestClinic(string $name): int
    {
        $suffix = bin2hex(random_bytes(6));
        $uuid = bin2hex(random_bytes(16));
        $stmt = $this->db->prepare("INSERT INTO clinics (uuid, name, document, email, phone, status) VALUES (?, ?, ?, ?, ?, 'active')");
        $stmt->execute([
            $uuid,
            $name . ' ' . $suffix,
            '00.' . mt_rand(100,999) . '.' . mt_rand(100,999) . '/0001-' . mt_rand(10,99),
            'clinic_' . $suffix . '@clyonutri.com.br',
            '119' . mt_rand(10000000, 99999999)
        ]);
        return (int)$this->db->lastInsertId();
    }
}

(new SubscriptionTrialTest())->run();
