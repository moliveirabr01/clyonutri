<?php

declare(strict_types=1);

namespace ClyoNutri\Tests;

require_once __DIR__ . '/../vendor/autoload.php';

if (session_status() !== PHP_SESSION_ACTIVE && !headers_sent()) {
    session_start();
}

use ClyoNutri\Core\Auth\TenantContext;
use ClyoNutri\Core\Database\Connection;
use ClyoNutri\Core\Database\Migrator;
use ClyoNutri\Core\Database\CatalogSeeder;
use ClyoNutri\Core\Security\TenantSecurityException;
use ClyoNutri\Domain\Settings\ProfessionalProfile;
use ClyoNutri\Domain\Finance\FinanceService;
use ClyoNutri\Domain\Helpdesk\HelpdeskTicketService;
use PDO;

class Onda4OperationAndPromiseE2eTest
{
    private PDO $db;
    private array $results = [];

    public function __construct()
    {
        $this->db = new PDO('sqlite::memory:');
        $this->db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->db->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);

        $migrator = new Migrator($this->db, __DIR__ . '/../database/migrations');
        $migrator->run();

        $seeder = new CatalogSeeder($this->db);
        $seeder->run();
    }

    private function record(string $code, string $name, bool $pass, string $evidence): void
    {
        $this->results[] = [
            'code' => $code,
            'name' => $name,
            'status' => $pass ? 'PASS' : 'FAIL',
            'evidence' => $evidence,
        ];
        echo sprintf("[%s] %s: %s - %s\n", $pass ? 'PASS' : 'FAIL', $code, $name, $evidence);
        if (!$pass) {
            throw new \RuntimeException("Falha de conformidade no teste {$code}: {$evidence}");
        }
    }

    public function runAll(): void
    {
        echo "\n====================================================================\n";
        echo "   SUÍTE DE OPERAÇÃO E PROMESSA DE VALOR - ONDA 4 (PX-11, PX-12, PX-28)\n";
        echo "====================================================================\n";

        $this->testPx11ProfessionalSettingsAndSecurity();
        $this->testPx12FinancialOperationsAndReceipts();
        $this->testPx28HelpdeskTicketsAndCsat();

        echo "\n====================================================================\n";
        echo "   RESULTADO: TODOS OS GATES DA ONDA 4 APROVADOS (3/3 PASS)\n";
        echo "====================================================================\n\n";
    }

    /**
     * PX-11 / NI2-304: Configurações do Profissional, Identidade e Concorrência Otimista
     */
    private function testPx11ProfessionalSettingsAndSecurity(): void
    {
        // 1. Provisionar usuário profissional e clínica
        $this->db->prepare("INSERT INTO clinics (id, uuid, name, status) VALUES (501, 'clinic-501', 'Clínica Saúde Plena', 'active')")->execute();
        $hash = password_hash('SenhaAntiga@2026', PASSWORD_ARGON2ID);
        $this->db->prepare("INSERT INTO users (id, uuid, name, email, phone, password_hash, role, status) VALUES (501, 'user-501', 'Dra. Beatriz Santos', 'beatriz@saudeplena.test', '11988880001', ?, 'nutritionist', 'active')")->execute([$hash]);
        $this->db->prepare("INSERT INTO clinic_members (clinic_id, user_id, role, status) VALUES (501, 501, 'owner', 'active')")->execute();
        $this->db->prepare("INSERT INTO professionals (id, user_id, crn, crn_region, specialty, bio) VALUES (501, 501, '12345', 'CRN-3', 'Nutrição Clínica', 'Atendimento focado em saúde integral')")->execute();

        TenantContext::set(501, ['id' => 501, 'name' => 'Dra. Beatriz Santos', 'role' => 'nutritionist']);

        $service = new ProfessionalProfile($this->db);
        $profile = $service->read();

        $this->record('PX-11.1', 'Leitura Autenticada de Configurações Profissionais', $profile['name'] === 'Dra. Beatriz Santos' && $profile['crn'] === '12345', "Dados de perfil carregados com integridade");

        // 2. Trava de concorrência por hash de revisão
        $validRevision = ProfessionalProfile::revision($profile);
        $staleRevision = hash('sha256', 'stale_profile_payload');

        try {
            $service->save([
                'revision' => $staleRevision,
                'name' => 'Dra. Beatriz Concorrente',
                'clinic_name' => 'Clínica Atualizada',
                'specialty' => 'Clínica',
                'bio' => 'Bio',
                'email' => 'beatriz@saudeplena.test',
                'phone' => '11988880001',
                'crn' => '12345',
                'crn_region' => 'CRN-3',
            ]);
            $concurrencyBlocked = false;
        } catch (\DomainException $e) {
            $concurrencyBlocked = true;
        }

        $this->record('PX-11.2', 'Detecção e Bloqueio de Concorrência Otimista (409)', $concurrencyBlocked, "Tentativa de escrita com revisão defasada bloqueada com sucesso");

        // 3. Atualização bem sucedida com revisão válida
        $service->save([
            'revision' => $validRevision,
            'name' => 'Dra. Beatriz Santos Silva',
            'clinic_name' => 'Instituto Saúde Plena',
            'specialty' => 'Nutrição Clínica Funcional',
            'bio' => 'Especialista em modulação intestinal e longevidade.',
            'email' => 'beatriz@saudeplena.test',
            'phone' => '11988880001',
            'crn' => '12345',
            'crn_region' => 'CRN-3',
        ]);

        $updatedProfile = $service->read();
        $this->record('PX-11.3', 'Persistência de Identidade e Dados Cadastrais', $updatedProfile['name'] === 'Dra. Beatriz Santos Silva' && $updatedProfile['clinic_name'] === 'Instituto Saúde Plena', "Perfil e nome do consultório atualizados no banco");

        // 4. Alteração de senha
        $service->changePassword([
            'current_password' => 'SenhaAntiga@2026',
            'new_password' => 'NovaSenhaForte@2026',
            'password_confirmation' => 'NovaSenhaForte@2026',
        ]);

        $uStmt = $this->db->prepare("SELECT password_hash FROM users WHERE id = 501");
        $uStmt->execute();
        $newHash = $uStmt->fetchColumn();
        $validNewAuth = password_verify('NovaSenhaForte@2026', $newHash);
        $this->record('PX-11.4', 'Fluxo Seguro de Troca de Senha com Reautenticação', $validNewAuth, "Senha atualizada e verificada via Argon2id");
    }

    /**
     * PX-12 / NI2-305: Operações Financeiras, Liquidação e Comprovantes
     */
    private function testPx12FinancialOperationsAndReceipts(): void
    {
        // 1. Provisionar paciente
        $this->db->prepare("INSERT INTO patients (id, clinic_id, uuid, name, email, phone, status) VALUES (601, 501, 'pat-601', 'Lucas Mendes', 'lucas@saudeplena.test', '11977770002', 'active')")->execute();

        TenantContext::set(501, ['id' => 501, 'name' => 'Dra. Beatriz Santos Silva']);
        $financeService = new FinanceService($this->db);

        // 2. Criar recebível
        $receivable = $financeService->createReceivable(601, 'Consulta Inicial e Planejamento', 35000, '2026-10-25');
        $recId = (int)$receivable['id'];
        $this->record('PX-12.1', 'Criação de Lançamento Financeiro com Persistência', $recId > 0 && (int)$receivable['amount_cents'] === 35000, "Recebível #{$recId} criado com valor R$ 350,00");

        // 3. Pagamento Parcial
        $partial = $financeService->recordPayment($recId, 15000, 'pix');
        $this->record('PX-12.2', 'Registro de Pagamento Parcial', $partial['status'] === 'partially_paid' && (int)$partial['total_paid_cents'] === 15000, "Saldo restante de R$ 200,00 registrado com sucesso");

        // 4. Quitação total com código de recibo comprobatório
        $finalPay = $financeService->recordPayment($recId, 20000, 'credit_card');
        $this->record('PX-12.3', 'Quitação Total e Emissão de Recibo REC-...', $finalPay['status'] === 'paid' && str_starts_with($finalPay['receipt_code'], 'REC-'), "Recibo comprobatório emitido: {$finalPay['receipt_code']}");

        // 5. Isolamento Multi-Tenant estrito
        try {
            TenantContext::set(999, ['id' => 999, 'name' => 'Hacker Clinic']);
            $hackerService = new FinanceService($this->db);
            $hackerService->recordPayment($recId, 1000, 'pix');
            $tenantBlocked = false;
        } catch (TenantSecurityException $e) {
            $tenantBlocked = true;
        }
        $this->record('PX-12.4', 'Proteção Multi-Tenant contra Acesso Financeiro Indevido', $tenantBlocked, "Acesso financeiro cruzado bloqueado com 403 / TenantSecurityException");
    }

    /**
     * PX-28 / NI2-321: Central de Ajuda, Fila de Suporte e CSAT
     */
    private function testPx28HelpdeskTicketsAndCsat(): void
    {
        TenantContext::set(501, ['id' => 501, 'name' => 'Dra. Beatriz Santos Silva']);
        $helpService = new HelpdeskTicketService($this->db);

        // 1. Criar chamado de suporte
        $ticket = $helpService->createTicket(
            501,
            501,
            'Dúvida sobre integração do Diário Alimentar',
            'diario_alimentar',
            'question',
            'Gostaria de saber como liberar o envio de fotos de refeições para pacientes específicos.',
            'medium'
        );
        $ticketId = (int)$ticket['id'];
        $protocol = $ticket['protocol'];

        $this->record('PX-28.1', 'Abertura de Chamado com Protocolo Rastreável', $ticketId > 0 && str_starts_with($protocol, 'SUP-'), "Chamado #{$ticketId} registrado com protocolo {$protocol}");

        // 2. Adicionar mensagem na timeline do chamado
        $msg = $helpService->addMessage($ticketId, 501, 'Gostaria de confirmação se a liberação é imediata.', false);
        $this->record('PX-28.2', 'Interação e Timeline do Chamado', (int)$msg['ticket_id'] === $ticketId, "Mensagem registrada na timeline do chamado");

        // 3. Atualizar status para 'resolved'
        $helpService->updateStatus($ticketId, 501, 'resolved', 'Funcionalidade esclarecida: as fotos são ativadas na aba de permissões do paciente.', true);
        $updatedTicket = $helpService->getTicketById($ticketId, 501, 501);
        $this->record('PX-28.3', 'Resolução de Chamado com Sumário de Resolução', $updatedTicket['status'] === 'resolved' && !empty($updatedTicket['resolved_at']), "Chamado resolvido com data/hora e justificativa registradas");

        // 4. Submissão de avaliação CSAT
        $feedback = $helpService->submitFeedback($ticketId, 501, 5, 'Excelente e rápido atendimento!');
        $this->record('PX-28.4', 'Avaliação CSAT Pós-Resolução', (int)$feedback['rating'] === 5 && $feedback['comment'] === 'Excelente e rápido atendimento!', "Feedback registrado com 5 estrelas e persistido no banco");
    }
}

$test = new Onda4OperationAndPromiseE2eTest();
$test->runAll();
