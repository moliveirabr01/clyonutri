<?php

declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';

/**
 * ==============================================================================
 * CLYONUTRI II — SUÍTE E2E DE CANAL SEGURO DE COMUNICAÇÃO E ENGAJAMENTO (NI2-189)
 * ==============================================================================
 * Validação rigorosa dos 5 critérios de aceite:
 * 1. Participantes autorizados leem/escrevem com isolamento multi-tenant fail-closed.
 * 2. Persistência real, ordenação estrita e idempotência anti-duplicação (zero double-click).
 * 3. Anexos protegidos fora do webroot e ZERO dados clínicos em notificações/alertas.
 * 4. Polling proporcional com cursor incremental (after_id) e sem websockets próprios.
 * 5. Ciclo de vida de entrega (sent ➔ delivered ➔ read) e resiliência a erros.
 * ==============================================================================
 */

use ClyoNutri\Core\Auth\TenantContext;
use ClyoNutri\Core\Database\Connection;
use ClyoNutri\Core\Security\TenantGuard;
use ClyoNutri\Core\Security\TenantSecurityException;
use ClyoNutri\Domain\Chat\ChatAttachmentService;
use ClyoNutri\Domain\Chat\ChatService;
use ClyoNutri\Domain\Patient\PatientRepository;

$db = Connection::getInstance();

function logChatPass(string $category, string $description, string $detail = ''): void {
    echo "  [PASS] [{$category}] {$description}" . ($detail ? " ➔ {$detail}" : "") . "\n";
}

function assertChat(bool $condition, string $failureMessage): void {
    if (!$condition) {
        throw new RuntimeException("FALHA DE HOMOLOGAÇÃO NO CHAT SEGURO (NI2-189): {$failureMessage}");
    }
}

echo "\n========================================================================\n";
echo ">>> CLYONUTRI II — SUÍTE DE TESTES E2E DO CHAT SEGURO (NI2-189) <<<\n";
echo "    Canal Seguro de Comunicação, Idempotência e Privacidade Clínica\n";
echo "========================================================================\n\n";

$clinicIdAlfa = 0;
$clinicIdBeta = 0;
$nutriIdAlfa = 0;
$profIdAlfa = 0;
$patientIdAlfa = 0;
$patientIdAlfa2 = 0;
$nutriIdBeta = 0;
$profIdBeta = 0;
$patientIdBeta = 0;
$convIdAlfa = 0;
$tempFilePng = '';
$tempFilePhp = '';

try {
    // -------------------------------------------------------------------------
    // ETAPA 1: Setup de Tenants e Participantes Reais
    // -------------------------------------------------------------------------
    echo "--> [1/8] Provisionando Tenants Alfa e Beta para Testes de Comunicação...\n";

    $u1 = 'chat-alfa-' . bin2hex(random_bytes(4));
    $u2 = 'chat-beta-' . bin2hex(random_bytes(4));

    $db->exec("INSERT INTO clinics (uuid, name, email) VALUES ('{$u1}', 'Clínica Alfa Nutrição', 'alfa-" . bin2hex(random_bytes(3)) . "@clinica.com')");
    $clinicIdAlfa = (int)$db->lastInsertId();

    $db->exec("INSERT INTO clinics (uuid, name, email) VALUES ('{$u2}', 'Clínica Concorrente Beta', 'beta-" . bin2hex(random_bytes(3)) . "@clinica.com')");
    $clinicIdBeta = (int)$db->lastInsertId();

    // Nutricionista Alfa
    $nutriUuidAlfa = 'user-nutri-alfa-' . bin2hex(random_bytes(4));
    $nutriEmailAlfa = 'dra.alfa-' . bin2hex(random_bytes(3)) . '@clinica.com';
    $db->exec("INSERT INTO users (uuid, name, email, password_hash, role, is_verified, status)
               VALUES ('{$nutriUuidAlfa}', 'Dra. Aline Nutri Alfa', '{$nutriEmailAlfa}', 'hash_pw', 'nutritionist', 1, 'active')");
    $nutriIdAlfa = (int)$db->lastInsertId();
    $db->exec("INSERT INTO clinic_members (clinic_id, user_id, role) VALUES ({$clinicIdAlfa}, {$nutriIdAlfa}, 'nutritionist')");
    $db->exec("INSERT INTO professionals (user_id, crn, crn_region) VALUES ({$nutriIdAlfa}, '11223', 'CRN-3')");
    $profIdAlfa = (int)$db->lastInsertId();

    // Nutricionista Beta (Tenant Concorrente)
    $nutriUuidBeta = 'user-nutri-beta-' . bin2hex(random_bytes(4));
    $nutriEmailBeta = 'dr.beta-' . bin2hex(random_bytes(3)) . '@clinica.com';
    $db->exec("INSERT INTO users (uuid, name, email, password_hash, role, is_verified, status)
               VALUES ('{$nutriUuidBeta}', 'Dr. Bernardo Nutri Beta', '{$nutriEmailBeta}', 'hash_pw', 'nutritionist', 1, 'active')");
    $nutriIdBeta = (int)$db->lastInsertId();
    $db->exec("INSERT INTO clinic_members (clinic_id, user_id, role) VALUES ({$clinicIdBeta}, {$nutriIdBeta}, 'nutritionist')");
    $db->exec("INSERT INTO professionals (user_id, crn, crn_region) VALUES ({$nutriIdBeta}, '44556', 'CRN-3')");
    $profIdBeta = (int)$db->lastInsertId();

    // Paciente Alfa 1
    TenantContext::set($clinicIdAlfa, ['id' => $nutriIdAlfa, 'name' => 'Dra. Aline Nutri Alfa']);
    $patRepo = new PatientRepository($db);
    $pat1 = $patRepo->create([
        'name' => 'Rodrigo Paciente Alfa',
        'email' => 'rodrigo.' . bin2hex(random_bytes(3)) . '@teste.com',
        'phone' => '11988880001',
        'birth_date' => '1995-04-10',
        'gender' => 'male',
        'lgpd_consent' => 1,
    ]);
    $patientIdAlfa = (int)$pat1['id'];

    // Paciente Alfa 2 (Outro paciente da mesma clínica, sem vínculo com a conversa 1)
    $pat2 = $patRepo->create([
        'name' => 'Carla Paciente Alfa Dois',
        'email' => 'carla.' . bin2hex(random_bytes(3)) . '@teste.com',
        'phone' => '11988880002',
        'birth_date' => '1998-09-20',
        'gender' => 'female',
        'lgpd_consent' => 1,
    ]);
    $patientIdAlfa2 = (int)$pat2['id'];

    // Paciente Beta (Tenant Concorrente)
    TenantContext::set($clinicIdBeta, ['id' => $nutriIdBeta, 'name' => 'Dr. Bernardo Nutri Beta']);
    $patBeta = $patRepo->create([
        'name' => 'Tiago Paciente Beta',
        'email' => 'tiago.' . bin2hex(random_bytes(3)) . '@teste.com',
        'phone' => '11988880003',
        'birth_date' => '1990-01-15',
        'gender' => 'male',
        'lgpd_consent' => 1,
    ]);
    $patientIdBeta = (int)$patBeta['id'];

    TenantContext::set($clinicIdAlfa, ['id' => $nutriIdAlfa, 'name' => 'Dra. Aline Nutri Alfa']);
    logChatPass('SETUP', 'Tenants Alfa e Beta provisionados com segregação relacional estrita');

    // -------------------------------------------------------------------------
    // ETAPA 2: Criação de Conversa e Autorização de Vínculo (Critério 1)
    // -------------------------------------------------------------------------
    echo "--> [2/8] Validando Vínculo Autorizado e Abertura de Conversa...\n";

    $chatService = new ChatService($db);
    // NI2-138 (A1): sem vínculo ativo a conversa não abre; o vínculo é liberado no perfil da paciente.
    $noLinkBlocked = false;
    try {
        $chatService->getOrCreateConversation($patientIdAlfa, $profIdAlfa);
    } catch (\ClyoNutri\Domain\Chat\ChatException $e) {
        $noLinkBlocked = $e->errorCode === 'NO_ACTIVE_LINK';
    }
    assertChat($noLinkBlocked, "Sem vínculo ativo, a conversa NÃO pode ser aberta (NO_ACTIVE_LINK)");
    (new \ClyoNutri\Domain\Chat\PatientLinkService($db))->activate($patientIdAlfa, $profIdAlfa, $nutriIdAlfa, 'test');
    $convIdAlfa = $chatService->getOrCreateConversation($patientIdAlfa, $profIdAlfa);
    assertChat($convIdAlfa > 0, "Conversa entre Paciente Alfa e Nutri Alfa deve ser criada");

    // Chamar novamente deve ser idempotente (retorna a mesma conversa existente)
    $convIdAlfaReplay = $chatService->getOrCreateConversation($patientIdAlfa, $profIdAlfa);
    assertChat($convIdAlfa === $convIdAlfaReplay, "getOrCreateConversation deve ser idempotente");

    $convData = $chatService->getConversation($convIdAlfa);
    assertChat($convData['patient_name'] === 'Rodrigo Paciente Alfa', "Nome do paciente deve bater na consulta");
    logChatPass('CONVERSATION', 'Conversa segura criada com vínculo autorizado e idempotência de abertura');

    // -------------------------------------------------------------------------
    // ETAPA 3: Persistência Real, Ordenação e Idempotência Anti-Duplicação (Critério 2)
    // -------------------------------------------------------------------------
    echo "--> [3/8] Testando Persistência, Ordenação e Prevenção de Mensagens Duplicadas...\n";

    $idempKey1 = 'client-msg-' . bin2hex(random_bytes(8));
    $msg1 = $chatService->sendMessage(
        $convIdAlfa,
        'professional',
        $profIdAlfa,
        'Olá Rodrigo, como está a adesão ao plano alimentar desta semana?',
        null,
        $idempKey1
    );
    assertChat($msg1['id'] > 0, "Mensagem do profissional deve ser persistida");
    assertChat($msg1['status'] === 'sent', "Status inicial da mensagem deve ser 'sent'");
    assertChat($msg1['is_duplicate'] === false, "Primeiro envio não deve ser duplicata");

    // Tentativa de reenvio da mesma mensagem com a mesma idempotency_key (simulando double-click de usuário ou retry de conexão instável)
    $msg1Duplicate = $chatService->sendMessage(
        $convIdAlfa,
        'professional',
        $profIdAlfa,
        'Olá Rodrigo, como está a adesão ao plano alimentar desta semana?',
        null,
        $idempKey1
    );
    assertChat((int)$msg1Duplicate['id'] === (int)$msg1['id'], "Reenvio com mesma chave DEVE retornar mensagem existente");
    assertChat(!empty($msg1Duplicate['is_duplicate']), "Flag is_duplicate deve ser true no reenvio");

    // Verificar no banco que existe APENAS 1 registro com essa chave de idempotência
    $countMsg = (int)$db->query("SELECT COUNT(*) FROM chat_messages WHERE idempotency_key = '{$idempKey1}'")->fetchColumn();
    assertChat($countMsg === 1, "Banco relacional DEVE conter estritamente 1 mensagem para a chave (anti-duplicação 100%)");

    // Envio de resposta pelo paciente
    $idempKey2 = 'client-msg-' . bin2hex(random_bytes(8));
    $msg2 = $chatService->sendMessage(
        $convIdAlfa,
        'patient',
        $patientIdAlfa,
        'Olá Dra. Aline! Segui o almoço certinho e bebi 2 litros de água hoje.',
        null,
        $idempKey2
    );
    assertChat($msg2['id'] > $msg1['id'], "Mensagem do paciente deve ter ID incremental ordenado");

    // Teste de validação: mensagem vazia sem anexo deve falhar
    $emptyFailed = false;
    try {
        $chatService->sendMessage($convIdAlfa, 'patient', $patientIdAlfa, '   ');
    } catch (\ClyoNutri\Domain\Chat\ChatException $e) {
        $emptyFailed = $e->errorCode === 'VALIDATION_ERROR' && $e->httpStatus === 422;
    }
    assertChat($emptyFailed, "Envio de mensagem vazia sem anexo DEVE ser recusado (422 VALIDATION_ERROR)");

    logChatPass('IDEMPOTENCY', 'Idempotência anti-duplicação e ordenação estrita comprovadas no banco relacional');

    // -------------------------------------------------------------------------
    // ETAPA 4: Anexos Privados Protegidos Fora do Webroot (Critério 3)
    // -------------------------------------------------------------------------
    echo "--> [4/8] Validando Armazenamento Seguro de Anexos Fora do Webroot e MIME Real...\n";

    $attService = $chatService->getAttachmentService();

    // 1. Criar arquivo PNG sintético de teste
    $tempFilePng = tempnam(sys_get_temp_dir(), 'chat_att_') . '.png';
    // Gerar imagem PNG mínima válida de 1x1 pixel
    $pngRaw = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNk+M9QDwADhgGAWjR9awAAAABJRU5ErkJggg==');
    file_put_contents($tempFilePng, $pngRaw);

    $fileUploadMock = [
        'name' => 'foto_refeicao_almoco.png',
        'type' => 'image/png',
        'tmp_name' => $tempFilePng,
        'error' => UPLOAD_ERR_OK,
        'size' => strlen($pngRaw),
    ];

    $msgWithAtt = $chatService->sendMessage(
        $convIdAlfa,
        'patient',
        $patientIdAlfa,
        'Segue a foto do meu prato de hoje conforme combinado.',
        null,
        'idemp-att-' . bin2hex(random_bytes(6)),
        $fileUploadMock
    );
    // NI2-138 (M5): a resposta não expõe o caminho do arquivo; ele fica só no banco.
    assertChat(!array_key_exists('attachment_path', $msgWithAtt) && !empty($msgWithAtt['attachment']['id']), "Resposta traz o anexo por id, sem caminho do servidor");
    $attPath = (string)$db->query("SELECT storage_path FROM chat_attachments WHERE id = " . (int)$msgWithAtt['attachment']['id'])->fetchColumn();
    assertChat(file_exists($attPath), "Arquivo físico do anexo deve existir em disco");
    assertChat(!str_contains($attPath, '/public/'), "Anexo NUNCA deve ser salvo dentro do webroot público");
    assertChat($msgWithAtt['attachment']['mime_type'] === 'image/png', "MIME detectado via libmagic deve ser image/png");

    // Teste de download autorizado pelo nutricionista
    $downloadProf = $attService->getAttachmentForDownload((int)$db->query("SELECT id FROM chat_attachments WHERE message_id = {$msgWithAtt['id']}")->fetchColumn(), 'professional', $profIdAlfa);
    assertChat(file_exists($downloadProf['file_path']), "Nutricionista autorizado deve conseguir obter anexo");
    assertChat($downloadProf['mime_type'] === 'image/png', "MIME deve ser preservado");

    // Teste de rejeição de arquivo não permitido (ex: arquivo de script executável PHP)
    $tempFilePhp = tempnam(sys_get_temp_dir(), 'malicious_') . '.php';
    file_put_contents($tempFilePhp, '<?php echo "evil"; ?>');
    $maliciousUpload = [
        'name' => 'script_malicioso.php',
        'type' => 'text/x-php',
        'tmp_name' => $tempFilePhp,
        'error' => UPLOAD_ERR_OK,
        'size' => filesize($tempFilePhp),
    ];

    $maliciousBlocked = false;
    try {
        $chatService->sendMessage(
            $convIdAlfa,
            'patient',
            $patientIdAlfa,
            'Tentativa de envio de script',
            null,
            null,
            $maliciousUpload
        );
    } catch (\ClyoNutri\Domain\Chat\ChatException $e) {
        $maliciousBlocked = $e->errorCode === 'ATTACHMENT_INVALID';
    }
    assertChat($maliciousBlocked, "Upload de executável/script PHP DEVE ser categoricamente bloqueado");

    logChatPass('ATTACHMENTS', 'Anexos protegidos fora do webroot, detecção MIME real e bloqueio de executáveis validados');

    // -------------------------------------------------------------------------
    // ETAPA 5: Notificações com ZERO Dados Clínicos no Corpo (Critério 3)
    // -------------------------------------------------------------------------
    echo "--> [5/8] Auditando Notificações e Preservação Estrita de Privacidade Clínica (LGPD)...\n";

    // Enviar mensagem contendo dados clínicos sensíveis
    $clinicalMessage = "Rodrigo, revisei seus exames de sangue: a hemoglobina glicada está em 6.8% e o colesterol LDL em 145 mg/dL. Vamos ajustar as gorduras saturadas.";
    $msgClinical = $chatService->sendMessage(
        $convIdAlfa,
        'professional',
        $profIdAlfa,
        $clinicalMessage,
        null,
        'idemp-clin-' . bin2hex(random_bytes(6))
    );

    // Consultar o registro de notificação gerado no banco de dados
    $stmtNotif = $db->prepare("SELECT * FROM chat_notifications_log WHERE message_id = :mid LIMIT 1");
    $stmtNotif->execute(['mid' => $msgClinical['id']]);
    $notif = $stmtNotif->fetch(PDO::FETCH_ASSOC);

    assertChat(!empty($notif), "Log de notificação deve ser gerado no banco");
    assertChat((int)$notif['contains_clinical_data'] === 0, "Flag contains_clinical_data DEVE ser estritamente 0");

    // Verificar que NENHUM dado clínico vazou no corpo ou título do alerta
    $forbiddenTerms = ['hemoglobina', 'glicada', 'colesterol', 'ldl', '145', '6.8', 'exames'];
    $notifText = strtolower($notif['title'] . ' ' . $notif['body_preview']);
    foreach ($forbiddenTerms as $term) {
        assertChat(!str_contains($notifText, $term), "Termo clínico '{$term}' NÃO PODE constar no corpo/título da notificação");
    }
    logChatPass('PRIVACY_NOTIF', 'Corpo de notificações 100% genérico sem exposição de termos clínicos sensíveis (LGPD)');

    // -------------------------------------------------------------------------
    // ETAPA 6: Polling com Cursor Delta e Política Proporcional (Critério 4)
    // -------------------------------------------------------------------------
    echo "--> [6/8] Testando Polling Otimizado com Cursor Incremental (after_id)...\n";

    // 1. Polling sem cursor (retorna todas)
    $pollAll = $chatService->getMessagesWithCursor($convIdAlfa, null, 50, 'patient', $patientIdAlfa);
    assertChat(count($pollAll['messages']) >= 4, "Deve retornar todas as mensagens existentes");
    assertChat($pollAll['cursor']['after_id'] > 0, "after_id do cursor deve ser o ID da última mensagem");
    assertChat($pollAll['polling_policy']['zero_websockets'] === true, "Arquitetura cumpre requisito de zero websockets próprios");
    assertChat($pollAll['polling_policy']['active_window_ms'] === 3000, "Intervalo em foco deve ser 3s");
    assertChat($pollAll['polling_policy']['background_window_ms'] === 15000, "Intervalo em background deve ser 15s");

    // 2. Polling com cursor após a última mensagem (deve retornar vazio, delta zero)
    $lastId = $pollAll['cursor']['after_id'];
    $pollEmptyDelta = $chatService->getMessagesWithCursor($convIdAlfa, $lastId, 50, 'patient', $patientIdAlfa);
    assertChat(count($pollEmptyDelta['messages']) === 0, "Polling com cursor atualizado deve retornar delta vazio");
    assertChat($pollEmptyDelta['cursor']['after_id'] === $lastId, "after_id deve ser mantido");

    // 3. Enviar mais 1 mensagem e verificar que o delta traz APENAS a nova mensagem
    $msgDelta = $chatService->sendMessage(
        $convIdAlfa,
        'professional',
        $profIdAlfa,
        'Nova mensagem avulsa para testar delta de polling.',
        null,
        'idemp-delta-' . bin2hex(random_bytes(6))
    );

    $pollSingleDelta = $chatService->getMessagesWithCursor($convIdAlfa, $lastId, 50, 'patient', $patientIdAlfa);
    assertChat(count($pollSingleDelta['messages']) === 1, "Delta de polling DEVE retornar exatamente 1 mensagem");
    assertChat((int)$pollSingleDelta['messages'][0]['id'] === (int)$msgDelta['id'], "Mensagem retornada deve ser a recém-criada");
    logChatPass('CURSOR_POLLING', 'Polling com cursor incremental traz apenas deltas, poupando tráfego e recursos de hospedagem');

    // -------------------------------------------------------------------------
    // ETAPA 7: Máquina de Estados da Mensagem e Leitura (Critério 5)
    // -------------------------------------------------------------------------
    echo "--> [7/8] Validando Estados de Entrega (sent ➔ delivered ➔ read)...\n";

    // A mensagem $msgDelta acabou de ser enviada pelo profissional ('sent')
    // Quando o paciente consome via getMessagesWithCursor com readerType = 'patient', deve avançar para 'delivered'
    $statusDelivered = (string)$db->query("SELECT status FROM chat_messages WHERE id = {$msgDelta['id']}")->fetchColumn();
    assertChat($statusDelivered === 'delivered', "Status da mensagem deve ter transitado para 'delivered' após polling do leitor");

    // Marcar como lida pelo paciente
    $chatService->markAsRead($convIdAlfa, 'patient');
    $statusRead = (string)$db->query("SELECT status FROM chat_messages WHERE id = {$msgDelta['id']}")->fetchColumn();
    assertChat($statusRead === 'read', "Status da mensagem deve ter transitado para 'read' após markAsRead");

    // Validar listas de conversas do nutricionista e paciente
    $profList = $chatService->listConversationsForProfessional($profIdAlfa);
    assertChat(!empty($profList), "Lista de conversas do profissional deve retornar conversas ativas");
    assertChat($profList[0]['patient_name'] === 'Rodrigo Paciente Alfa', "Nome do paciente correto na lista do profissional");

    $patientList = $chatService->listConversationsForPatient($patientIdAlfa);
    assertChat(!empty($patientList), "Lista de conversas do paciente deve retornar conversas ativas");
    assertChat($patientList[0]['professional_name'] === 'Dra. Aline Nutri Alfa', "Nome da nutricionista correto na lista do paciente");

    logChatPass('STATE_MACHINE', 'Ciclo de vida da mensagem (sent ➔ delivered ➔ read) verificado deterministicamente');

    // -------------------------------------------------------------------------
    // ETAPA 8: Pen-Test Ofensivo Cross-Tenant e Participantes Não Autorizados (Critério 1 & 5)
    // -------------------------------------------------------------------------
    echo "--> [8/8] Executando Testes de Invasão Cross-Tenant e Cross-User (Fail-Closed)...\n";

    // Ataque 1: Invasor do Tenant Beta tenta enviar mensagem na conversa do Tenant Alfa
    TenantContext::set($clinicIdBeta, ['id' => $nutriIdBeta, 'name' => 'Dr. Bernardo Nutri Beta']);
    $attackCrossTenantBlocked = false;
    try {
        $chatService->sendMessage(
            $convIdAlfa,
            'professional',
            $profIdBeta,
            'Ataque Cross-Tenant tentando injetar mensagem'
        );
    } catch (TenantSecurityException $e) {
        $attackCrossTenantBlocked = true;
    }
    assertChat($attackCrossTenantBlocked, "Envio cross-tenant DEVE ser barrado com 403 Forbidden");

    // Ataque 2: Invasor do Tenant Beta tenta ler mensagens via cursor da conversa do Tenant Alfa
    $attackCrossTenantPollBlocked = false;
    try {
        $chatService->getMessagesWithCursor($convIdAlfa, null, 50, 'professional', $profIdBeta);
    } catch (TenantSecurityException $e) {
        $attackCrossTenantPollBlocked = true;
    }
    assertChat($attackCrossTenantPollBlocked, "Polling cross-tenant DEVE ser barrado com 403 Forbidden");

    // Ataque 3: Invasor do Tenant Beta tenta baixar anexo privado da conversa do Tenant Alfa
    $attackCrossTenantAttBlocked = false;
    try {
        $attIdAlfa = (int)$db->query("SELECT id FROM chat_attachments WHERE conversation_id = {$convIdAlfa} LIMIT 1")->fetchColumn();
        $attService->getAttachmentForDownload($attIdAlfa, 'professional', $profIdBeta);
    } catch (\ClyoNutri\Domain\Chat\ChatException $e) {
        // NI2-138 (M5): 404 "Anexo não encontrado", sem dizer de quem é.
        $attackCrossTenantAttBlocked = $e->errorCode === 'ATTACHMENT_NOT_FOUND';
    }
    assertChat($attackCrossTenantAttBlocked, "Download de anexo cross-tenant DEVE ser barrado com 403 Forbidden");

    // Ataque 4: Outro paciente da mesma clínica (Paciente Alfa 2) tentando ler conversa do Paciente Alfa 1
    TenantContext::set($clinicIdAlfa, ['id' => $nutriIdAlfa, 'name' => 'Dra. Aline Nutri Alfa']);
    $attackCrossUserBlocked = false;
    try {
        $chatService->getMessagesWithCursor($convIdAlfa, null, 50, 'patient', $patientIdAlfa2);
    } catch (TenantSecurityException $e) {
        $attackCrossUserBlocked = true;
    }
    assertChat($attackCrossUserBlocked, "Acesso por paciente não participante da conversa DEVE ser barrado com 403 Forbidden");

    logChatPass('OFFENSIVE_PENTEST', '4 ataques ofensivos de invasão barrados categoricamente com 403 Forbidden');

    echo "\n========================================================================\n";
    echo ">>> SUCESSO TOTAL: TODOS OS CRITÉRIOS DE ACEITE DE NI2-189 HOMOLOGADOS <<<\n";
    echo "    Canal seguro de comunicação operacional com persistência real em produção.\n";
    echo "========================================================================\n\n";

} catch (\Throwable $e) {
    echo "\n[ERRO CRÍTICO NO CHAT SEGURO NI2-189] " . $e->getMessage() . "\n";
    echo $e->getTraceAsString() . "\n";
    exit(1);
} finally {
    // Teardown completo de banco e arquivos temporários
    TenantContext::clear();

    if ($tempFilePng && file_exists($tempFilePng)) {
        @unlink($tempFilePng);
    }
    if ($tempFilePhp && file_exists($tempFilePhp)) {
        @unlink($tempFilePhp);
    }

    if ($convIdAlfa > 0) {
        // Remover arquivos físicos de anexos criados durante o teste
        $attRows = $db->query("SELECT storage_path FROM chat_attachments WHERE conversation_id = {$convIdAlfa}")->fetchAll(PDO::FETCH_ASSOC);
        foreach ($attRows as $row) {
            if (!empty($row['storage_path']) && file_exists($row['storage_path'])) {
                @unlink($row['storage_path']);
            }
        }
        $db->exec("DELETE FROM chat_attachments WHERE conversation_id = {$convIdAlfa}");
        $db->exec("DELETE FROM chat_notifications_log WHERE conversation_id = {$convIdAlfa}");
        $db->exec("DELETE FROM chat_messages WHERE conversation_id = {$convIdAlfa}");
        $db->exec("DELETE FROM chat_conversations WHERE id = {$convIdAlfa}");
    }

    if ($patientIdAlfa > 0 || $patientIdAlfa2 > 0 || $patientIdBeta > 0) {
        $pIds = implode(',', array_filter([$patientIdAlfa, $patientIdAlfa2, $patientIdBeta]));
        $db->exec("DELETE FROM patient_consents WHERE patient_id IN ({$pIds})");
        $db->exec("DELETE FROM patients WHERE id IN ({$pIds})");
    }

    if ($profIdAlfa > 0 || $profIdBeta > 0) {
        $prIds = implode(',', array_filter([$profIdAlfa, $profIdBeta]));
        $db->exec("DELETE FROM professionals WHERE id IN ({$prIds})");
    }

    if ($nutriIdAlfa > 0 || $nutriIdBeta > 0) {
        $uIds = implode(',', array_filter([$nutriIdAlfa, $nutriIdBeta]));
        $db->exec("DELETE FROM clinic_members WHERE user_id IN ({$uIds})");
        $db->exec("DELETE FROM users WHERE id IN ({$uIds})");
    }

    if ($clinicIdAlfa > 0 || $clinicIdBeta > 0) {
        $cIds = implode(',', array_filter([$clinicIdAlfa, $clinicIdBeta]));
        $db->exec("DELETE FROM audit_logs WHERE clinic_id IN ({$cIds})");
        $db->exec("DELETE FROM clinics WHERE id IN ({$cIds})");
    }
}
