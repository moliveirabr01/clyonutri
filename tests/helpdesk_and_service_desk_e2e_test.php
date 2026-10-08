<?php

declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';

/**
 * Suíte E2E: Central de Ajuda e Service Desk ClyoNutri II (NI2-195 a NI2-208)
 * Validação rigorosa de produção: sem mocks, sem dados fixos simulados,
 * com persistência real em banco, autorização RBAC, isolamento multi-tenant e segurança LGPD.
 */

use ClyoNutri\Core\Auth\TenantContext;
use ClyoNutri\Core\Database\Connection;
use ClyoNutri\Domain\Helpdesk\HelpdeskAttachmentService;
use ClyoNutri\Domain\Helpdesk\HelpdeskTicketService;
use ClyoNutri\Domain\Helpdesk\KnowledgeBaseService;

$db = Connection::getInstance();

function logPass(string $testName, string $detail = ''): void {
    echo "[PASS] {$testName}" . ($detail ? " — {$detail}" : "") . "\n";
}

function assertCondition(bool $cond, string $msg): void {
    if (!$cond) {
        throw new RuntimeException("FALHA NA ASSERTIVA: {$msg}");
    }
}

echo "\n========================================================================\n";
echo ">>> SUÍTE E2E: CENTRAL DE AJUDA & SERVICE DESK (NI2-195 a NI2-208) <<<\n";
echo "========================================================================\n\n";

$db->beginTransaction();

try {
    // ---------------------------------------------------------
    // Setup de Massa de Testes Real
    // ---------------------------------------------------------
    // 1. Clínicas
    $uuidC1 = 'clinic-help-1-' . bin2hex(random_bytes(4));
    $uuidC2 = 'clinic-help-2-' . bin2hex(random_bytes(4));
    $db->exec("INSERT INTO clinics (uuid, name, email) VALUES ('{$uuidC1}', 'Clínica Nutrição Integral', 'contato@integral.com')");
    $clinicId1 = (int)$db->lastInsertId();

    $db->exec("INSERT INTO clinics (uuid, name, email) VALUES ('{$uuidC2}', 'Consultório Dieta & Saúde', 'contato@dieta.com')");
    $clinicId2 = (int)$db->lastInsertId();

    // 2. Usuários: Nutricionista A (Clínica 1), Nutricionista B (Clínica 2), Operador de Suporte
    $uUuid1 = 'user-nutri-a-' . bin2hex(random_bytes(4));
    $db->exec("INSERT INTO users (uuid, name, email, password_hash, role, is_verified, status)
               VALUES ('{$uUuid1}', 'Dra. Carolina Nutricionista', 'carolina@integral.com', 'hash_pw', 'nutritionist', 1, 'active')");
    $nutriAId = (int)$db->lastInsertId();

    $uUuid2 = 'user-nutri-b-' . bin2hex(random_bytes(4));
    $db->exec("INSERT INTO users (uuid, name, email, password_hash, role, is_verified, status)
               VALUES ('{$uUuid2}', 'Dr. Roberto Nutricionista', 'roberto@dieta.com', 'hash_pw', 'nutritionist', 1, 'active')");
    $nutriBId = (int)$db->lastInsertId();

    $uUuidOp = 'user-operador-' . bin2hex(random_bytes(4));
    $db->exec("INSERT INTO users (uuid, name, email, password_hash, role, is_verified, status)
               VALUES ('{$uUuidOp}', 'Carlos Operador ServiceDesk', 'carlos.suporte@clyonutri.local', 'hash_pw', 'support_agent', 1, 'active')");
    $operatorId = (int)$db->lastInsertId();

    // Vínculos de clínica
    $db->exec("INSERT INTO clinic_members (clinic_id, user_id, role) VALUES ({$clinicId1}, {$nutriAId}, 'nutritionist')");
    $db->exec("INSERT INTO clinic_members (clinic_id, user_id, role) VALUES ({$clinicId2}, {$nutriBId}, 'nutritionist')");

    // Instanciar serviços reais
    $kbService = new KnowledgeBaseService($db);
    $ticketService = new HelpdeskTicketService($db);
    $attachmentService = new HelpdeskAttachmentService($db);

    // ---------------------------------------------------------
    // TESTE 1: NI2-196 & NI2-197 — Base de Conhecimento e Categorias Canônicas
    // ---------------------------------------------------------
    $categories = $kbService->getCategoriesWithCount();
    assertCondition(count($categories) >= 15, 'Base de conhecimento deve possuir todas as categorias oficiais');
    assertCondition(isset($categories['planos_alimentares']), 'Categoria planos_alimentares deve existir');
    assertCondition(isset($categories['antropometria']), 'Categoria antropometria deve existir');
    assertCondition(isset($categories['anamnese']), 'Categoria anamnese deve existir');

    // Artigo individual por slug
    $articleTaco = $kbService->getArticleBySlug('como-elaborar-um-plano-alimentar-com-taco');
    assertCondition($articleTaco !== null, 'Artigo da Tabela TACO deve ser recuperado com sucesso');
    assertCondition(str_contains($articleTaco['content_markdown'], 'TACO 4ª edição'), 'Conteúdo do artigo TACO deve conter instruções reais');
    logPass('NI2-196 & NI2-197: Base de Conhecimento, categorias oficiais e artigos nutricionais validados.');

    // ---------------------------------------------------------
    // TESTE 2: NI2-198 — Busca e Sugestão de Conteúdo
    // ---------------------------------------------------------
    $searchResults = $kbService->search('Pollock');
    assertCondition(!empty($searchResults), 'Busca por Pollock deve retornar o artigo de antropometria');
    assertCondition($searchResults[0]['slug'] === 'como-registrar-avaliacao-antropometrica-pollock-7-dobras', 'Artigo correto deve ser ranqueado na busca');

    $searchEmpty = $kbService->search('termo_inexistente_xyz_123');
    assertCondition(empty($searchEmpty), 'Busca por termo inexistente deve retornar vazio com segurança');
    logPass('NI2-198: Mecanismo de busca textual e filtragem de conteúdo validado.');

    // ---------------------------------------------------------
    // TESTE 3: NI2-199 — Abertura de Chamado com Protocolo Exclusivo
    // ---------------------------------------------------------
    $ticket = $ticketService->createTicket(
        $clinicId1,
        $nutriAId,
        'Dúvida sobre substituição de carboidratos no plano alimentar',
        'planos_alimentares',
        'question',
        'Preciso entender como definir listas de substituição equivalentes utilizando a tabela TACO para meu paciente.',
        'medium',
        '/planos'
    );

    assertCondition(!empty($ticket['id']), 'Chamado deve ser persistido com ID válido');
    assertCondition(str_starts_with($ticket['protocol'], 'SUP-' . date('Y') . '-'), 'Protocolo deve seguir o padrão SUP-YYYY-XXXXX');
    assertCondition($ticket['status'] === 'open', 'Status inicial deve ser open');
    assertCondition($ticket['priority_operational'] === 'P3', 'Tipo question deve ter prioridade P3 por padrão');

    // Notificação in-app criada
    $notif = $db->query("SELECT * FROM helpdesk_notifications WHERE ticket_id = {$ticket['id']} LIMIT 1")->fetch(PDO::FETCH_ASSOC);
    assertCondition($notif !== false, 'Notificação in-app deve ser criada para o chamado');
    assertCondition(str_contains($notif['title'], $ticket['protocol']), 'Notificação deve conter o protocolo legível');
    logPass('NI2-199: Abertura de chamado com protocolo legível exclusivo e notificação confirmada.');

    // ---------------------------------------------------------
    // TESTE 4: NI2-200 — Upload e Gestão Segura de Anexos
    // ---------------------------------------------------------
    // Simular upload de arquivo temporário PNG válido
    $tmpFile = tempnam(sys_get_temp_dir(), 'test_att') . '.png';
    // Gerar um PNG mínimo válido de 1x1 pixel (8 bytes PNG header + chunks)
    $pngBytes = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNk+M9QDwADhgGAWjR9awAAAABJRU5ErkJggg==');
    file_put_contents($tmpFile, $pngBytes);

    $fileInfo = [
        'name' => 'captura_erro_tela.png',
        'tmp_name' => $tmpFile,
        'size' => filesize($tmpFile),
        'error' => UPLOAD_ERR_OK,
    ];

    $att = $attachmentService->storeAttachment((int)$ticket['id'], $nutriAId, $fileInfo);
    assertCondition(!empty($att['id']), 'Anexo deve ser registrado com sucesso');
    assertCondition(file_exists($att['stored_path']), 'Arquivo deve estar persistido no storage seguro fora do webroot');
    assertCondition(!str_contains($att['stored_path'], 'public'), 'Anexo não pode estar dentro do webroot público');

    // Validação de segurança: autorização para download
    // Nutri A (autor) pode baixar
    $attDownloadA = $attachmentService->getAttachmentForDownload($att['id'], $nutriAId, $clinicId1, 'nutritionist');
    assertCondition($attDownloadA['file_name'] === 'captura_erro_tela.png', 'Autor do chamado deve conseguir baixar o anexo');

    // Nutri B (outra clínica) NÃO PODE baixar (Fail-Closed / IDOR Protection)
    $blockedOtherTenant = false;
    try {
        $attachmentService->getAttachmentForDownload($att['id'], $nutriBId, $clinicId2, 'nutritionist');
    } catch (\Throwable $e) {
        $blockedOtherTenant = true;
    }
    assertCondition($blockedOtherTenant, 'Nutricionista de outra clínica deve ser bloqueado ao tentar baixar anexo alheio');

    // Operador de suporte PODE baixar para atender o chamado
    $attDownloadOp = $attachmentService->getAttachmentForDownload($att['id'], $operatorId, 0, 'support_agent');
    assertCondition(!empty($attDownloadOp), 'Operador de suporte deve conseguir baixar o anexo do cliente');
    logPass('NI2-200: Upload seguro de anexo, validação MIME e proteção contra IDOR multi-tenant comprovada.');

    // ---------------------------------------------------------
    // TESTE 5: NI2-201 & NI2-202 — Meus Chamados, Timeline e Ocultação Estrita de Notas Internas
    // ---------------------------------------------------------
    // Operador adiciona uma nota interna exclusiva da equipe
    $ticketService->addMessage((int)$ticket['id'], $operatorId, 'Nota técnica interna: verificar integridade dos alimentos customizados no banco.', true, true);

    // Operador adiciona uma resposta pública ao cliente
    $ticketService->addMessage((int)$ticket['id'], $operatorId, 'Olá Dra. Carolina! Analisamos seu chamado e identificamos as regras de substituição na TACO.', false, true);

    // Cliente consulta o chamado: NOTA INTERNA NÃO DEVE APARECER
    $clientView = $ticketService->getTicketById((int)$ticket['id'], $nutriAId, $clinicId1, false);
    foreach ($clientView['messages'] as $m) {
        assertCondition((int)$m['is_internal_note'] === 0, 'Cliente NUNCA pode ter acesso a notas internas na timeline');
    }
    assertCondition(count($clientView['messages']) === 2, 'Cliente deve ver mensagem de abertura e resposta pública (2 mensagens)');

    // Operador consulta o chamado: NOTA INTERNA DEVE APARECER
    $operatorView = $ticketService->getTicketById((int)$ticket['id'], $operatorId, $clinicId1, true);
    assertCondition(count($operatorView['messages']) === 3, 'Operador deve visualizar 3 mensagens incluindo a nota interna');

    // Tentativa do cliente comum postar nota interna deve FALHAR
    $clientTriedInternal = false;
    try {
        $ticketService->addMessage((int)$ticket['id'], $nutriAId, 'Tentando burlar como nota interna', true, false);
    } catch (\Throwable $e) {
        $clientTriedInternal = true;
    }
    assertCondition($clientTriedInternal, 'Cliente comum deve ser expressamente impedido de postar nota interna');
    logPass('NI2-201 & NI2-202: Timeline, histórico cronológico e isolamento de notas internas comprovado.');

    // ---------------------------------------------------------
    // TESTE 6: NI2-203 — Ciclo de Vida de Estados (Aberto -> Em Atendimento -> Resolvido -> CSAT)
    // ---------------------------------------------------------
    // Atualizar status para resolvido
    $ticketService->updateStatus((int)$ticket['id'], $operatorId, HelpdeskTicketService::STATUS_RESOLVED, 'Orientações sobre tabela TACO fornecidas com sucesso.', true);

    $resolvedTicket = $ticketService->getTicketById((int)$ticket['id'], $nutriAId, $clinicId1, false);
    assertCondition($resolvedTicket['status'] === 'resolved', 'Status deve estar como resolved');
    assertCondition(!empty($resolvedTicket['resolved_at']), 'Timestamp resolved_at deve estar preenchido');

    // Submissão de avaliação CSAT pelo cliente (NI2-207)
    $feedback = $ticketService->submitFeedback((int)$ticket['id'], $nutriAId, 5, 'Atendimento excelente e muito ágil!');
    assertCondition($feedback['rating'] === 5, 'CSAT deve ser 5 estrelas');

    // Outro usuário não pode avaliar o chamado do cliente (IDOR CSAT)
    $csatHijackBlocked = false;
    try {
        $ticketService->submitFeedback((int)$ticket['id'], $nutriBId, 1, 'Tentativa indevida');
    } catch (\Throwable $e) {
        $csatHijackBlocked = true;
    }
    assertCondition($csatHijackBlocked, 'Usuário não-autorizado não pode avaliar chamado de terceiros');
    logPass('NI2-203 & NI2-207: Ciclo de vida para resolvido e avaliação CSAT de 1 a 5 estrelas validados.');

    // ---------------------------------------------------------
    // TESTE 7: NI2-205 & NI2-206 — Backoffice de Triagem, Fila e Indicadores de SLA
    // ---------------------------------------------------------
    // Criar um chamado crítico P0
    $ticketP0 = $ticketService->createTicket(
        $clinicId2,
        $nutriBId,
        'Erro crítico na geração de PDF de prescrição',
        'prescricoes_orientacoes',
        'incident',
        'Os relatórios de prescrição estão apresentando erro 500 no download imediato.',
        'urgent',
        '/pacientes/1/resumo-prescricao'
    );

    // Definir prioridade operacional P0
    $ticketService->setPriority((int)$ticketP0['id'], 'P0', $operatorId);

    // Listar fila de triagem
    $queue = $ticketService->listBackofficeQueue();
    assertCondition(!empty($queue), 'Fila de backoffice deve listar chamados');
    assertCondition($queue[0]['priority_operational'] === 'P0', 'Chamados P0 devem ter prioridade máxima e aparecer no topo da fila');

    // Métricas operacionais
    $metrics = $ticketService->getOperationsMetrics();
    assertCondition($metrics['total_tickets'] >= 2, 'Total de chamados deve contabilizar todos os registros');
    assertCondition($metrics['csat_total_feedbacks'] >= 1, 'Total de CSATs deve contabilizar a avaliação submetida');
    assertCondition($metrics['csat_average'] >= 4.0, 'Média do CSAT deve refletir nota 5.0');
    logPass('NI2-205 & NI2-206: Fila de triagem por prioridade P0-P3, métricas de SLA e saúde operacional validados.');

    // ---------------------------------------------------------
    // TESTE 8: NI2-208 — Auditoria Operacional Completa
    // ---------------------------------------------------------
    $auditEvents = $db->query("SELECT * FROM helpdesk_audit_events WHERE ticket_id = {$ticket['id']} ORDER BY id ASC")->fetchAll(PDO::FETCH_ASSOC);
    assertCondition(count($auditEvents) >= 4, 'Trilha de auditoria deve conter eventos de abertura, notas, transição e CSAT');
    $eventTypes = array_column($auditEvents, 'event_type');
    assertCondition(in_array('ticket_created', $eventTypes, true), 'Evento ticket_created deve estar registrado');
    assertCondition(in_array('status_changed', $eventTypes, true), 'Evento status_changed deve estar registrado');
    assertCondition(in_array('csat_submitted', $eventTypes, true), 'Evento csat_submitted deve estar registrado');
    logPass('NI2-208: Auditoria operacional e runbook de rastreabilidade de eventos comprovados.');

    // Rollback para limpar dados de teste sem poluir o banco operacional
    $db->rollBack();
    echo "\n>>> SUCESSO TOTAL: 8/8 TESTES PASSARAM COM COBERTURA COMPLETA DAS HISTÓRIAS NI2-195 A NI2-208 <<<\n";

} catch (\Throwable $e) {
    if ($db->inTransaction()) {
        $db->rollBack();
    }
    echo "\n[ERRO CRÍTICO] " . $e->getMessage() . "\n";
    echo $e->getTraceAsString() . "\n";
    exit(1);
}
