<?php

declare(strict_types=1);

namespace ClyoNutri\Application\Controllers;

use ClyoNutri\Core\Auth\TenantContext;
use ClyoNutri\Core\Database\Connection;
use ClyoNutri\Core\Security\Csrf;
use ClyoNutri\Core\Security\TenantSecurityException;
use ClyoNutri\Domain\Chat\ChatAttachmentService;
use ClyoNutri\Domain\Chat\ChatService;
use PDO;

class ChatController
{
    private PDO $db;
    private ChatService $chatService;
    private ChatAttachmentService $attachmentService;

    public function __construct(
        ?PDO $db = null,
        ?ChatService $chatService = null,
        ?ChatAttachmentService $attachmentService = null
    ) {
        $this->db = $db ?? Connection::getInstance();
        $this->chatService = $chatService ?? new ChatService($this->db);
        $this->attachmentService = $attachmentService ?? new ChatAttachmentService($this->db);
    }

    private function getAuthInfo(): array
    {
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }

        $userId = (int)($_SESSION['user_id'] ?? 0);
        $clinicId = (int)($_SESSION['clinic_id'] ?? 0);
        $userName = (string)($_SESSION['user_name'] ?? 'Nutricionista');

        if ($userId <= 0 || $clinicId <= 0) {
            header('Location: /login');
            exit;
        }

        // Buscar ID profissional correspondente ao user_id
        $stmt = $this->db->prepare("SELECT id FROM professionals WHERE user_id = :uid LIMIT 1");
        $stmt->execute(['uid' => $userId]);
        $profId = (int)$stmt->fetchColumn();

        // Buscar Nome da Clínica
        $cStmt = $this->db->prepare("SELECT name FROM clinics WHERE id = :cid LIMIT 1");
        $cStmt->execute(['cid' => $clinicId]);
        $clinicName = (string)($cStmt->fetchColumn() ?: ($_SESSION['clinic_name'] ?? 'Clínica Principal'));

        TenantContext::set($clinicId, [
            'id' => $userId,
            'name' => $userName,
            'professional_id' => $profId,
        ]);

        return [
            'userId' => $userId,
            'clinicId' => $clinicId,
            'userName' => $userName,
            'clinicName' => $clinicName,
            'profId' => $profId,
        ];
    }

    /**
     * Tela inicial de conversas do profissional.
     */
    public function index(array $params = []): void
    {
        $auth = $this->getAuthInfo();
        $patientId = isset($_GET['patient_id']) ? (int)$_GET['patient_id'] : 0;
        if ($patientId > 0 && $auth['profId'] > 0) {
            try {
                $activeConvId = $this->chatService->getOrCreateConversation($patientId, $auth['profId']);
            } catch (TenantSecurityException $e) {
                http_response_code(404); echo 'Paciente não encontrado.'; return;
            } catch (\ClyoNutri\Domain\Chat\ChatException $e) {
                $_SESSION['chat_flash'] = $e->getMessage();
                header('Location: /chat'); exit;
            }
            $conversations = $this->chatService->listConversationsForProfessional($auth['profId']);
        } else {
            $conversations = $this->chatService->listConversationsForProfessional($auth['profId']);
            $activeConvId = (int)($_GET['conversa'] ?? ($conversations[0]['id'] ?? 0));
        }

        $this->renderChatView($auth, $conversations, $activeConvId);
    }

    /**
     * NI2-424 (UX-12): busca paginada de pacientes ativos da clínica para "Nova conversa" (JSON, somente leitura).
     */
    public function patientSearch(array $params = []): void
    {
        $auth = $this->getAuthInfo();
        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: no-store');
        $term = $_GET['q'] ?? '';
        $page = $_GET['page'] ?? '1';
        if (!is_string($term) || mb_strlen($term) > 80 || !is_string($page) || !ctype_digit($page) || (int)$page < 1 || (int)$page > 200) {
            http_response_code(422); echo json_encode(['error' => 'Busca inválida.']); return;
        }
        $like = '%' . str_replace(['!', '%', '_'], ['!!', '!%', '!_'], mb_strtolower(trim($term))) . '%';
        $offset = ((int)$page - 1) * 20;
        // NI2-138 (A1): o chat só abre com vínculo ativo entre a paciente e este profissional.
        $hasLinks = \ClyoNutri\Core\Database\SchemaInfo::hasTable($this->db, 'patient_professional_links');
        $linkSql = $hasLinks ? "(SELECT l.id FROM patient_professional_links l WHERE l.clinic_id = p.clinic_id AND l.patient_id = p.id AND l.professional_id = :prof2 AND l.status = 'active' LIMIT 1)" : '1';
        $stmt = $this->db->prepare("SELECT p.id, p.name, (SELECT c.id FROM chat_conversations c WHERE c.clinic_id = p.clinic_id AND c.patient_id = p.id AND c.professional_id = :prof LIMIT 1) AS conversation_id,
            {$linkSql} AS link_id
            FROM patients p WHERE p.clinic_id = :cid AND p.status = 'active' AND LOWER(p.name) LIKE :q ESCAPE '!' ORDER BY p.name, p.id LIMIT 21 OFFSET {$offset}");
        $stmt->execute(['cid' => $auth['clinicId'], 'prof' => $auth['profId'], 'q' => $like] + ($hasLinks ? ['prof2' => $auth['profId']] : []));
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        $more = count($rows) > 20;
        echo json_encode(['data' => array_map(static fn($r) => ['id' => (int)$r['id'], 'name' => $r['name'], 'has_conversation' => $r['conversation_id'] !== null, 'has_link' => $r['link_id'] !== null], array_slice($rows, 0, 20)), 'has_more' => $more, 'page' => (int)$page], JSON_UNESCAPED_UNICODE);
    }

    /**
     * NI2-424 (UX-12): abre (ou reaproveita) a conversa com o paciente escolhido explicitamente. Não envia mensagem.
     */
    public function startConversation(array $params = []): void
    {
        $auth = $this->getAuthInfo();
        if (!Csrf::validate((string)($_POST['_csrf_token'] ?? ''))) {
            http_response_code(419); echo 'Sessão expirada. Recarregue a página.'; return;
        }
        $patientId = ctype_digit((string)($_POST['patient_id'] ?? '')) ? (int)$_POST['patient_id'] : 0;
        if ($patientId <= 0 || $auth['profId'] <= 0) {
            header('Location: /chat?erro=paciente'); exit;
        }
        try {
            $convId = $this->chatService->getOrCreateConversation($patientId, $auth['profId']);
        } catch (TenantSecurityException $e) {
            http_response_code(404); echo 'Paciente não encontrado.'; return;
        } catch (\ClyoNutri\Domain\Chat\ChatException $e) {
            $_SESSION['chat_flash'] = $e->getMessage();
            header('Location: /chat'); exit;
        }
        header('Location: /chat/' . $convId);
        exit;
    }

    /**
     * Exibe uma conversa específica.
     */
    public function conversationView(array $params = []): void
    {
        $auth = $this->getAuthInfo();
        $activeConvId = (int)($params['id'] ?? 0);
        $conversations = $this->chatService->listConversationsForProfessional($auth['profId']);

        $this->renderChatView($auth, $conversations, $activeConvId);
    }

    /**
     * Envia mensagem segura via POST (nutricionista).
     */
    public function sendMessage(array $params = []): void
    {
        $auth = $this->getAuthInfo();
        $convId = (int)($params['id'] ?? ($_POST['conversation_id'] ?? 0));

        // Validação estrita de CSRF
        $csrfToken = (string)($_POST['csrf_token'] ?? ($_SERVER['HTTP_X_CSRF_TOKEN'] ?? ''));
        if (!Csrf::validateToken($csrfToken)) {
            $this->respondError("Token de segurança CSRF inválido ou expirado.", 403);
            return;
        }

        $message = trim((string)($_POST['message'] ?? ''));
        $idempotencyKey = trim((string)($_POST['idempotency_key'] ?? ''));
        if ($idempotencyKey === '') {
            $idempotencyKey = bin2hex(random_bytes(16));
        }

        $file = null;
        if (!empty($_FILES['attachment']) && is_uploaded_file($_FILES['attachment']['tmp_name'])) {
            $file = $_FILES['attachment'];
        }

        try {
            $msg = $this->chatService->sendMessage(
                $convId,
                'professional',
                $auth['profId'],
                $message,
                null,
                $idempotencyKey,
                $file
            );

            if ($this->wantsJson()) {
                header('Content-Type: application/json; charset=utf-8');
                echo json_encode(['success' => true, 'message' => $msg], JSON_UNESCAPED_UNICODE);
                return;
            }

            header("Location: /chat/{$convId}");
            exit;
        } catch (\ClyoNutri\Domain\Chat\ChatException $e) {
            if ($e->errorCode === 'RATE_LIMITED') {
                header('Retry-After: ' . (int)($e->extra['retry_after'] ?? 60));
            }
            $this->respondError($e->getMessage(), $e->httpStatus, $e->errorCode);
        } catch (TenantSecurityException | \ClyoNutri\Core\Security\AuthorizationException $e) {
            $this->respondError('Conversa não encontrada.', 404, 'CONVERSATION_NOT_FOUND');
        }
    }

    /**
     * Polling otimizado com cursor incremental.
     */
    public function pollMessages(array $params = []): void
    {
        $auth = $this->getAuthInfo();
        $convId = (int)($params['id'] ?? 0);
        $afterId = isset($_GET['after_id']) ? (int)$_GET['after_id'] : null;

        header('Content-Type: application/json; charset=utf-8');
        try {
            $data = $this->chatService->getMessagesWithCursor(
                $convId,
                $afterId,
                50,
                'professional',
                $auth['profId']
            );

            // Resumo atualizado de todas as conversas do profissional para a lista lateral
            $allConvs = $this->chatService->listConversationsForProfessional($auth['profId']);
            $data['conversations_summary'] = array_map(function($c) {
                return [
                    'id' => (int)$c['id'],
                    'patient_name' => $c['patient_name'],
                    'last_message' => $c['last_message'] ?? 'Conversa iniciada...',
                    'last_message_at' => !empty($c['last_message_at']) ? date('H:i', strtotime($c['last_message_at'])) : '',
                    'unread_count' => (int)($c['unread_count'] ?? 0),
                ];
            }, $allConvs);

            echo json_encode($data, JSON_UNESCAPED_UNICODE);
        } catch (\ClyoNutri\Domain\Chat\ChatException $e) {
            http_response_code($e->httpStatus);
            echo json_encode($e->toArray(), JSON_UNESCAPED_UNICODE);
        } catch (TenantSecurityException | \ClyoNutri\Core\Security\AuthorizationException $e) {
            http_response_code(404);
            echo json_encode(['error' => 'Conversa não encontrada.', 'code' => 'CONVERSATION_NOT_FOUND'], JSON_UNESCAPED_UNICODE);
        }
    }

    /**
     * Download autenticado de anexo privado.
     */
    public function downloadAttachment(array $params = []): void
    {
        $auth = $this->getAuthInfo();
        $attachmentId = (int)($params['id'] ?? 0);

        try {
            $fileData = $this->attachmentService->getAttachmentForDownload(
                $attachmentId,
                'professional',
                $auth['profId']
            );

            ChatAttachmentService::sendDownloadHeaders($fileData);
            readfile($fileData['file_path']);
            exit;
        } catch (\ClyoNutri\Domain\Chat\ChatException | TenantSecurityException $e) {
            http_response_code(404);
            echo '<h1>Anexo não encontrado</h1><p>O anexo não foi encontrado nas suas conversas.</p>';
            exit;
        }
    }

    private function renderChatView(array $auth, array $conversations, int $activeConvId): void
    {
        $activeConv = null;
        $messages = [];
        $csrfToken = Csrf::generateToken();
        $clientMessageId = 'msg_' . time() . '_' . bin2hex(random_bytes(4));

        if ($activeConvId > 0) {
            try {
                $activeConv = $this->chatService->getConversation($activeConvId);
                // Carregar mensagens e marcar como lidas para o profissional
                $pollResult = $this->chatService->getMessagesWithCursor(
                    $activeConvId,
                    null,
                    100,
                    'professional',
                    $auth['profId']
                );
                $messages = $pollResult['messages'];
                $access = $pollResult['access'] ?? ['can_send' => true, 'reason' => null];
                $this->chatService->markAsRead($activeConvId, 'professional', $auth['profId']);
            } catch (\Throwable $e) {
                $activeConv = null;
            }
        }

        $access ??= ['can_send' => false, 'reason' => null];
        $chatNotice = (new \ClyoNutri\Domain\Chat\ChatSettingsService($this->db))->notice($auth['clinicId']);
        $chatFlash = $_SESSION['chat_flash'] ?? null; unset($_SESSION['chat_flash']);
        $statusLabel = static fn(string $st) => ['sent' => 'Enviada', 'delivered' => 'Entregue', 'read' => 'Lida'][$st] ?? 'Enviada';
        $hhmm = static fn(?string $iso) => $iso ? substr($iso, 11, 5) : '';
        $totalUnread = array_reduce($conversations, fn($acc, $c) => $acc + (int)($c['unread_count'] ?? 0), 0);

        $title = 'Mensagens';
        $activeNav = 'chat';
        $clinicName = $auth['clinicName'];
        $userName = $auth['userName'];

        ob_start();
        ?>
    <style>
        :root {
            --patient-bubble: var(--neutral-gray-100, #F4F5F6);
            --nutri-bubble: var(--botanical-green-600, #39734A); /* NI2-138: contraste AA com texto branco */
            --nutri-bubble-text: #ffffff;
        }

        /* Container Principal do Chat Integrado ao Shell */
        .chat-layout {
            display: flex;
            height: calc(100vh - 120px);
            min-height: 560px;
            background: #ffffff;
            border: 1px solid var(--neutral-gray-200, #e2e8f0);
            border-radius: var(--radius-card, 12px);
            overflow: hidden;
            box-shadow: 0 1px 3px rgba(0, 0, 0, 0.05);
        }

        /* Coluna Lista de Conversas */
        .conv-list-panel {
            width: 320px;
            border-right: 1px solid var(--neutral-gray-200, #e2e8f0);
            display: flex;
            flex-direction: column;
            background: #ffffff;
            flex-shrink: 0;
        }
        .conv-list-header {
            padding: 18px 20px;
            border-bottom: 1px solid var(--neutral-gray-200, #e2e8f0);
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        .conv-list-header h2 {
            font-size: 1.1rem;
            font-weight: 600;
            color: var(--brand-navy-900, #0F3E17);
            margin: 0;
        }
        .conv-list-items {
            flex: 1;
            overflow-y: auto;
            list-style: none;
            margin: 0;
            padding: 0;
        }
        .conv-card {
            padding: 14px 18px;
            border-bottom: 1px solid var(--neutral-gray-200, #e2e8f0);
            cursor: pointer;
            text-decoration: none;
            color: inherit;
            display: block;
            transition: background 0.15s ease;
        }
        .conv-card:hover { background: var(--neutral-gray-50, #F9FAFB); }
        .conv-card.active {
            background: var(--botanical-sage-100, #E5F0E5);
            border-left: 4px solid var(--botanical-green-600, #39734A);
        }
        .conv-card-title {
            display: flex;
            justify-content: space-between;
            margin-bottom: 4px;
            font-weight: 600;
            font-size: 0.95rem;
            color: var(--brand-navy-900, #0F3E17);
        }
        .conv-card-time {
            font-size: 0.75rem;
            color: var(--neutral-gray-600, #475467);
            font-weight: normal;
        }
        .conv-card-preview {
            font-size: 0.85rem;
            color: var(--neutral-gray-600, #475467);
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        .badge-unread {
            background: var(--botanical-green-600, #39734A);
            color: white;
            font-size: 0.7rem;
            font-weight: 700;
            border-radius: 999px;
            padding: 2px 7px;
        }

        /* Coluna Mensagens e Conversa Ativa */
        .conv-main-panel {
            flex: 1;
            display: flex;
            flex-direction: column;
            height: 100%;
            background: var(--neutral-gray-50, #F9FAFB);
            min-width: 0;
        }
        .chat-topbar {
            padding: 14px 24px;
            background: #ffffff;
            border-bottom: 1px solid var(--neutral-gray-200, #e2e8f0);
            display: flex;
            align-items: center;
            justify-content: space-between;
        }
        .chat-topbar-info h3 {
            font-size: 1.05rem;
            font-weight: 600;
            margin: 0;
            color: var(--brand-navy-900, #0F3E17);
        }
        .chat-topbar-info p {
            font-size: 0.8rem;
            color: var(--neutral-gray-500, #667085);
            margin: 0;
        }
        .chat-security-tag {
            background: var(--botanical-sage-100, #E5F0E5);
            color: var(--botanical-green-600, #39734A);
            padding: 4px 10px;
            border-radius: 6px;
            font-size: 0.75rem;
            font-weight: 600;
            display: flex;
            align-items: center;
            gap: 6px;
        }

        .chat-messages-container {
            flex: 1;
            overflow-y: auto;
            padding: 24px;
            display: flex;
            flex-direction: column;
            gap: 12px;
        }
        .chat-bubble {
            max-width: 68%;
            padding: 12px 16px;
            border-radius: 12px;
            font-size: 0.92rem;
            line-height: 1.45;
            position: relative;
            word-wrap: break-word;
        }
        .chat-bubble.patient {
            align-self: flex-start;
            background: var(--patient-bubble);
            color: var(--botanical-forest-900, #0F3E17);
            border-bottom-left-radius: 4px;
        }
        .chat-bubble.professional {
            align-self: flex-end;
            background: var(--nutri-bubble);
            color: var(--nutri-bubble-text);
            border-bottom-right-radius: 4px;
        }
        .bubble-meta {
            font-size: 0.75rem;
            margin-top: 6px;
            display: flex;
            gap: 8px;
            justify-content: flex-end;
        }
        .chat-attachment-box {
            margin-top: 8px;
            padding: 8px 12px;
            background: rgba(0,0,0,0.06);
            border-radius: 6px;
            font-size: 0.85rem;
            display: flex;
            align-items: center;
            gap: 8px;
        }
        .chat-attachment-box a {
            color: inherit;
            font-weight: 600;
            text-decoration: underline;
        }

        /* Rodapé de Envio */
        .chat-input-bar {
            padding: 16px 24px;
            background: #ffffff;
            border-top: 1px solid var(--neutral-gray-200, #e2e8f0);
        }
        .chat-input-form {
            display: flex;
            gap: 10px;
            align-items: center;
        }
        .chat-textarea {
            flex: 1;
            padding: 12px 16px;
            border: 1px solid var(--border-input, #828B9B);
            border-radius: 8px;
            font-family: inherit;
            font-size: 0.95rem;
            outline: none;
            resize: none;
            height: 48px;
            transition: border-color 0.15s ease;
        }
        .chat-textarea:focus { border-color: var(--botanical-green-600, #39734A); }
        .btn-send {
            background: var(--botanical-green-600, #39734A);
            color: white;
            border: none;
            padding: 0 20px;
            height: 48px;
            border-radius: 8px;
            font-weight: 600;
            cursor: pointer;
            transition: background 0.15s ease;
        }
        .btn-send:hover { background: var(--botanical-green-700, #2F6140); }
        .file-label {
            cursor: pointer;
            padding: 10px;
            border-radius: 8px;
            border: 1px solid var(--neutral-gray-300, #D1D5DB);
            color: var(--neutral-gray-500, #667085);
            display: flex;
            align-items: center;
            justify-content: center;
        }
        .file-label:hover { background: var(--neutral-gray-50, #F9FAFB); color: var(--botanical-forest-900, #0F3E17); }
        .file-input { display: none; }

        /* Empty State */
        .empty-chat {
            flex: 1;
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            color: var(--neutral-gray-500, #667085);
            padding: 40px;
            text-align: center;
        }
        .empty-chat h3 { font-size: 1.2rem; color: var(--botanical-forest-900, #0F3E17); margin-bottom: 8px; }

        /* Responsividade */
        @media (max-width: 1024px) {
            .conv-list-panel { width: 280px; }
        }
        @media (max-width: 768px) {
            .chat-layout { flex-direction: column; height: auto; min-height: calc(100vh - 160px); }
            .conv-list-panel { width: 100%; border-right: none; border-bottom: 1px solid var(--neutral-gray-200, #E5E7EB); }
            .conv-main-panel {
                display: <?= ($activeConv !== null) ? 'flex' : 'none' ?>;
            }
        }
        @media (max-width: 390px) {
            .chat-topbar { padding: 10px 14px; }
            .chat-input-bar { padding: 10px 14px; }
            .chat-bubble { max-width: 85%; font-size: 0.88rem; }
        }
    </style>

    <?php if ($chatFlash): ?><p role="alert" class="card" data-testid="chat-flash" style="border-left: 4px solid var(--semantic-warning, #946200);"><?= htmlspecialchars($chatFlash) ?></p><?php endif; ?>
    <!-- Layout de Comunicação Segura -->
    <div class="chat-layout">
        <!-- Lista de Conversas -->
        <aside class="conv-list-panel">
            <div class="conv-list-header">
                <h2>Mensagens</h2>
                <?php if ($totalUnread > 0): ?>
                    <span class="badge-unread"><?= $totalUnread ?> não lidas</span>
                <?php endif; ?>
                <button type="button" class="btn btn-primary" data-testid="new-conversation" data-dialog-open="modal-nova-conversa" style="min-height: 36px; font-size: 0.85rem;">Nova conversa</button>
            </div>
            <ul class="conv-list-items">
                <?php if (empty($conversations)): ?>
                    <li style="padding: 24px; text-align: center; color: var(--neutral-gray-500, #667085); font-size: 0.9rem;">
                        Nenhuma conversa ativa no momento.<br>Use <strong>Nova conversa</strong> para escolher o paciente (o atalho no perfil do paciente continua disponível).
                    </li>
                <?php else: ?>
                    <?php foreach ($conversations as $c): ?>
                        <li>
                            <a href="/chat/<?= $c['id'] ?>" class="conv-card <?= ($activeConvId === (int)$c['id']) ? 'active' : '' ?>">
                                <div class="conv-card-title">
                                    <span><?= htmlspecialchars($c['patient_name']) ?></span>
                                    <span class="conv-card-time">
                                        <?= !empty($c['last_message_at']) ? date('H:i', strtotime($c['last_message_at'])) : '' ?>
                                    </span>
                                </div>
                                <div class="conv-card-preview">
                                    <span><?= htmlspecialchars($c['last_message'] ?? 'Conversa iniciada...') ?></span>
                                    <?php if ((int)($c['unread_count'] ?? 0) > 0): ?>
                                        <span class="badge-unread"><?= $c['unread_count'] ?></span>
                                    <?php endif; ?>
                                </div>
                            </a>
                        </li>
                    <?php endforeach; ?>
                <?php endif; ?>
            </ul>
        </aside>

        <!-- Área Principal de Mensagens -->
        <section class="conv-main-panel">
            <?php if ($activeConv !== null): ?>
                <header class="chat-topbar">
                    <div class="chat-topbar-info">
                        <h3><?= htmlspecialchars($activeConv['patient_name']) ?></h3>
                        <p><?= $access['can_send'] ? 'Mensagens assíncronas com a paciente. Não é canal de urgência.' : 'Só leitura' ?></p>
                    </div>
                    <div style="display: flex; align-items: center; gap: 10px;">
                        <div class="chat-security-tag" title="Mensagens visíveis só para você e a paciente, dentro do consultório">
                            <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><rect width="18" height="11" x="3" y="11" rx="2" ry="2"/><path d="M7 11V7a5 5 0 0 1 10 0v4"/></svg>
                            Acesso restrito ao consultório
                        </div>
                    </div>
                </header>

                <div class="chat-messages-container" id="chatMessagesContainer">
                    <?php if (empty($messages)): ?>
                        <div class="empty-chat" id="emptyStateNotice">
                            <h3>Inicie a conversa</h3>
                            <p>Envie orientações, tire dúvidas e acompanhe a adesão nutricional do seu paciente com segurança.</p>
                        </div>
                    <?php else: ?>
                        <?php foreach ($messages as $m): ?>
                            <div class="chat-bubble <?= $m['sender_type'] === 'professional' ? 'professional' : 'patient' ?>" data-msg-id="<?= (int)$m['id'] ?>">
                                <div><?= nl2br(htmlspecialchars($m['message'])) ?></div>
                                <?php if (!empty($m['attachment'])): ?>
                                    <div class="chat-attachment-box">
                                        <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="m21.44 11.05-9.19 9.19a6 6 0 0 1-8.49-8.49l8.57-8.57A4 4 0 1 1 18 8.84l-8.59 8.57a2 2 0 0 1-2.83-2.83l8.49-8.48"/></svg>
                                        <a href="/chat/anexo/<?= (int)$m['attachment']['id'] ?>" target="_blank" rel="noopener"><?= htmlspecialchars($m['attachment']['name']) ?></a>
                                    </div>
                                <?php endif; ?>
                                <div class="bubble-meta">
                                    <span><?= htmlspecialchars($hhmm($m['created_at'])) ?></span>
                                    <?php if ($m['sender_type'] === 'professional'): ?>
                                        <span><?= $statusLabel($m['status']) ?></span>
                                    <?php endif; ?>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>

                <?php if (!$access['can_send']): ?>
                <div class="chat-input-bar" role="note" data-testid="chat-read-only"><p style="margin: 0; color: var(--semantic-warning-strong, #7A4A00);"><?= htmlspecialchars((string)$access['reason']) ?></p></div>
                <?php else: ?>
                <p class="chat-notice" data-testid="chat-notice" style="margin: 0; padding: 8px 16px; font-size: 0.8rem; color: var(--neutral-gray-600, #475467); border-top: 1px solid var(--neutral-gray-200, #E5E7EB);">
                    A paciente vê: “<?= htmlspecialchars($chatNotice['response_time_text']) ?>” e “<?= htmlspecialchars($chatNotice['emergency_text']) ?>” <a href="/configuracoes?tab=paciente-app#chat-settings">Alterar o tempo de resposta</a>
                </p>
                <p id="chatSendError" role="alert" data-testid="chat-send-error" style="margin: 0 16px; color: var(--semantic-error, #B42318); font-size: 0.85rem;"></p>
                <div class="chat-input-bar">
                    <form method="POST" action="/chat/<?= $activeConvId ?>/enviar" enctype="multipart/form-data" class="chat-input-form" id="chatSendForm">
                        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken) ?>">
                        <input type="hidden" name="idempotency_key" id="idempotencyKeyInput" value="<?= htmlspecialchars($clientMessageId) ?>">

                        <label class="file-label" title="Anexar arquivo seguro (PDF, JPG, PNG até 10MB)">
                            <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="m21.44 11.05-9.19 9.19a6 6 0 0 1-8.49-8.49l8.57-8.57A4 4 0 1 1 18 8.84l-8.59 8.57a2 2 0 0 1-2.83-2.83l8.49-8.48"/></svg>
                            <input type="file" name="attachment" class="file-input" id="attachmentInput" accept=".pdf,.png,.jpg,.jpeg">
                        </label>
                        <span id="selectedFileName" style="font-size: 0.8rem; color: var(--neutral-gray-500, #667085); display: none;"></span>

                        <textarea name="message" class="chat-textarea" id="chatMessageTextarea" maxlength="<?= \ClyoNutri\Domain\Chat\ChatService::MAX_MESSAGE_CHARS ?>" aria-label="Mensagem para <?= htmlspecialchars($activeConv['patient_name']) ?>" placeholder="Escreva uma mensagem para a paciente"></textarea>
                        <button type="submit" class="btn-send" id="btnSend">Enviar</button>
                    </form>
                </div>
                <?php endif; ?>

                <?php /* Polling com cursor e envio assíncrono. NI2-138 (A4): todo texto vindo da API entra por textContent. */ ?>
                <script nonce="<?= \ClyoNutri\Core\Http\SecurityHeaders::nonce() ?>">
                    (function() {
                        const convId = <?= (int)$activeConvId ?>;
                        let lastId = <?= !empty($messages) ? (int)end($messages)['id'] : 0 ?>;
                        let isPolling = false;
                        let pollIntervalMs = 5000;
                        const container = document.getElementById('chatMessagesContainer');
                        const fileInput = document.getElementById('attachmentInput');
                        const fileNameSpan = document.getElementById('selectedFileName');
                        const sendForm = document.getElementById('chatSendForm');
                        const textarea = document.getElementById('chatMessageTextarea');
                        const btnSend = document.getElementById('btnSend');
                        const idempotencyInput = document.getElementById('idempotencyKeyInput');
                        const sendError = document.getElementById('chatSendError');
                        const STATUS = { sent: 'Enviada', delivered: 'Entregue', read: 'Lida' };
                        const newKey = () => 'msg_' + Date.now() + '_' + Math.random().toString(36).substring(2, 10);

                        if (container) container.scrollTop = container.scrollHeight;

                        // Balão montado só com createElement/textContent: HTML enviado pela paciente aparece como texto.
                        function renderBubble(m) {
                            if (!container || document.querySelector('[data-msg-id="' + Number(m.id) + '"]')) return;
                            const notice = document.getElementById('emptyStateNotice');
                            if (notice) notice.remove();
                            const bubble = document.createElement('div');
                            bubble.className = 'chat-bubble ' + (m.sender_type === 'professional' ? 'professional' : 'patient');
                            bubble.setAttribute('data-msg-id', String(Number(m.id)));
                            const body = document.createElement('div');
                            body.style.whiteSpace = 'pre-wrap';
                            body.textContent = m.message || '';
                            bubble.appendChild(body);
                            if (m.attachment && m.attachment.id) {
                                const box = document.createElement('div');
                                box.className = 'chat-attachment-box';
                                const link = document.createElement('a');
                                link.href = '/chat/anexo/' + Number(m.attachment.id);
                                link.target = '_blank';
                                link.rel = 'noopener';
                                link.textContent = m.attachment.name || 'Anexo';
                                box.appendChild(link);
                                bubble.appendChild(box);
                            }
                            const meta = document.createElement('div');
                            meta.className = 'bubble-meta';
                            const time = document.createElement('span');
                            time.textContent = m.created_at ? String(m.created_at).substring(11, 16) : '';
                            meta.appendChild(time);
                            if (m.sender_type === 'professional') {
                                const st = document.createElement('span');
                                st.textContent = STATUS[m.status] || 'Enviada';
                                meta.appendChild(st);
                            }
                            bubble.appendChild(meta);
                            container.appendChild(bubble);
                            lastId = Math.max(lastId, Number(m.id) || 0);
                        }

                        if (fileInput && fileNameSpan) {
                            fileInput.addEventListener('change', function() {
                                if (this.files && this.files[0]) {
                                    fileNameSpan.textContent = this.files[0].name;
                                    fileNameSpan.style.display = 'inline';
                                } else {
                                    fileNameSpan.style.display = 'none';
                                }
                            });
                        }

                        if (sendForm) {
                            sendForm.addEventListener('submit', function(e) {
                                e.preventDefault();
                                const text = textarea.value.trim();
                                if (!text && (!fileInput || !fileInput.files.length)) return;
                                if (sendError) sendError.textContent = '';
                                btnSend.disabled = true;
                                btnSend.textContent = 'Enviando…';
                                // A chave só muda depois de um envio confirmado: tentar de novo não duplica.
                                fetch(sendForm.action, { method: 'POST', headers: { 'Accept': 'application/json' }, body: new FormData(sendForm) })
                                    .then(res => res.json().then(data => ({ ok: res.ok, data })))
                                    .then(({ ok, data }) => {
                                        if (ok && data && data.success && data.message) {
                                            renderBubble(data.message);
                                            container.scrollTop = container.scrollHeight;
                                            textarea.value = '';
                                            if (fileInput) fileInput.value = '';
                                            if (fileNameSpan) fileNameSpan.style.display = 'none';
                                            if (idempotencyInput) idempotencyInput.value = newKey();
                                        } else if (sendError) {
                                            sendError.textContent = (data && data.error) ? data.error : 'Não foi possível enviar. Tente de novo.';
                                        }
                                    })
                                    .catch(() => { if (sendError) sendError.textContent = 'Sem conexão. A mensagem não foi enviada; tente de novo (não duplica).'; })
                                    .finally(() => { btnSend.disabled = false; btnSend.textContent = 'Enviar'; });
                            });
                        }

                        function pollDelta() {
                            if (isPolling) return;
                            isPolling = true;
                            fetch('/chat/' + convId + '/poll?after_id=' + lastId, { headers: { 'Accept': 'application/json' } })
                                .then(res => res.json())
                                .then(data => {
                                    if (data && Array.isArray(data.messages) && data.messages.length > 0) {
                                        data.messages.forEach(renderBubble);
                                        container.scrollTop = container.scrollHeight;
                                    }
                                    if (data && Array.isArray(data.receipts)) {
                                        data.receipts.forEach(rc => {
                                            const el = document.querySelector('[data-msg-id="' + Number(rc.id) + '"] .bubble-meta span:last-child');
                                            if (el && STATUS[rc.status]) el.textContent = STATUS[rc.status];
                                        });
                                    }
                                    if (data && Array.isArray(data.conversations_summary)) {
                                        data.conversations_summary.forEach(cs => {
                                            const cardLink = document.querySelector('a[href="/chat/' + Number(cs.id) + '"]');
                                            if (!cardLink) return;
                                            const timeEl = cardLink.querySelector('.conv-card-time');
                                            if (timeEl && cs.last_message_at) timeEl.textContent = cs.last_message_at;
                                            const prevSpan = cardLink.querySelector('.conv-card-preview span:first-child');
                                            if (prevSpan && cs.last_message) prevSpan.textContent = cs.last_message;
                                            let badge = cardLink.querySelector('.badge-unread');
                                            if (cs.unread_count > 0) {
                                                if (!badge) {
                                                    badge = document.createElement('span');
                                                    badge.className = 'badge-unread';
                                                    cardLink.querySelector('.conv-card-preview').appendChild(badge);
                                                }
                                                badge.textContent = String(Number(cs.unread_count));
                                            } else if (badge && cs.id === convId) {
                                                badge.remove();
                                            }
                                        });
                                    }
                                    if (data && data.polling_policy) {
                                        pollIntervalMs = document.hidden ? data.polling_policy.background_window_ms : data.polling_policy.active_window_ms;
                                    }
                                })
                                .catch(() => {})
                                .finally(() => { isPolling = false; setTimeout(pollDelta, pollIntervalMs); });
                        }
                        setTimeout(pollDelta, pollIntervalMs);
                    })();
                </script>

            <?php else: ?>
                <div class="empty-chat">
                    <h3>Nenhuma conversa selecionada</h3>
                    <p>Selecione uma conversa na lista ou comece uma nova.</p>
                    <button type="button" class="btn btn-primary" data-dialog-open="modal-nova-conversa">Nova conversa</button>
                </div>
            <?php endif; ?>
        </section>
    </div>

    <?php /* NI2-424 (UX-12): Nova conversa — busca paginada, destinatário explícito, nenhuma mensagem enviada aqui */ ?>
    <div id="modal-nova-conversa" data-dialog="modal" style="display: none; position: fixed; inset: 0; background: rgba(0,0,0,0.5); z-index: 1000; align-items: center; justify-content: center;">
      <div class="cn-dialog__panel" style="background: #fff; border-radius: 12px; width: 100%; max-width: 480px; padding: 24px;">
        <h2 style="font-size: 1.15rem; margin: 0 0 12px;">Nova conversa</h2>
        <form method="POST" action="/chat/nova" id="new-conv-form">
          <input type="hidden" name="_csrf_token" value="<?= htmlspecialchars($csrfToken) ?>">
          <input type="hidden" name="patient_id" id="new-conv-patient" value="">
          <label class="form-label" for="new-conv-search">Buscar paciente da clínica</label>
          <input class="form-control" type="search" id="new-conv-search" maxlength="80" autocomplete="off" placeholder="Digite parte do nome" aria-describedby="new-conv-status">
          <p id="new-conv-status" role="status" aria-live="polite" style="font-size: 0.8rem; color: var(--neutral-gray-600, #475467); margin: 6px 0;"></p>
          <div id="new-conv-results" role="group" aria-label="Pacientes encontrados" style="display: flex; flex-direction: column; gap: 6px; max-height: 40vh; overflow-y: auto;"></div>
          <button type="button" class="btn btn-secondary" id="new-conv-more" hidden style="margin-top: 6px;">Mais pacientes</button>
          <p id="new-conv-chosen" style="margin: 12px 0 0; font-weight: 600;" aria-live="polite">Nenhum paciente selecionado.</p>
          <div style="display: flex; justify-content: flex-end; gap: 8px; margin-top: 16px;">
            <button type="button" class="btn btn-outline" data-dialog-close>Cancelar</button>
            <button type="submit" class="btn btn-primary" id="new-conv-submit" disabled>Abrir conversa</button>
          </div>
        </form>
      </div>
    </div>
    <script nonce="<?= \ClyoNutri\Core\Http\SecurityHeaders::nonce() ?>">
    (() => {
      const q = document.getElementById('new-conv-search'), box = document.getElementById('new-conv-results'), st = document.getElementById('new-conv-status');
      const more = document.getElementById('new-conv-more'), hid = document.getElementById('new-conv-patient'), chosen = document.getElementById('new-conv-chosen'), submit = document.getElementById('new-conv-submit');
      const form = document.getElementById('new-conv-form');
      let page = 1, timer, seq = 0;
      const clear = () => { hid.value = ''; submit.disabled = true; submit.textContent = 'Abrir conversa'; chosen.textContent = 'Nenhum paciente selecionado.'; };
      async function load(append) {
        const my = ++seq; st.textContent = 'Buscando pacientes…';
        try {
          const r = await fetch('/chat/pacientes?q=' + encodeURIComponent(q.value) + '&page=' + page, {headers: {Accept: 'application/json'}});
          if (!r.ok) throw Error(); const data = await r.json(); if (my !== seq) return;
          if (!append) box.replaceChildren();
          data.data.forEach(p => {
            const b = document.createElement('button'); b.type = 'button'; b.className = 'btn btn-secondary'; b.style.justifyContent = 'space-between';
            b.textContent = p.name + (p.has_conversation ? ' — já tem conversa' : '') + (p.has_link === false && !p.has_conversation ? ' — chat não liberado' : '');
            b.setAttribute('aria-pressed', 'false');
            if (p.has_link === false && !p.has_conversation) { b.disabled = true; b.title = 'Libere o chat no perfil da paciente (vínculo de acompanhamento).'; }
            b.addEventListener('click', () => {
              box.querySelectorAll('[aria-pressed]').forEach(x => x.setAttribute('aria-pressed', 'false')); b.setAttribute('aria-pressed', 'true');
              hid.value = String(p.id); submit.disabled = false;
              submit.textContent = (p.has_conversation ? 'Abrir conversa existente com ' : 'Abrir conversa com ') + p.name;
              chosen.textContent = 'Destinatário: ' + p.name + (p.has_conversation ? ' (a conversa existente será aberta, sem duplicar)' : '') + '. Nenhuma mensagem é enviada nesta etapa.';
            });
            box.append(b);
          });
          more.hidden = !data.has_more;
          st.textContent = box.children.length ? box.children.length + ' paciente(s) exibido(s).' : 'Nenhum paciente ativo encontrado.';
        } catch (e) { if (my === seq) st.textContent = 'Não foi possível buscar pacientes. Tente novamente.'; }
      }
      q.addEventListener('input', () => { clearTimeout(timer); page = 1; clear(); timer = setTimeout(() => load(false), 250); });
      q.addEventListener('focus', () => { if (!box.children.length) load(false); });
      more.addEventListener('click', () => { page++; load(true); });
      form.addEventListener('clyo:discard', () => { box.replaceChildren(); st.textContent = ''; more.hidden = true; clear(); });
      form.addEventListener('submit', e => { if (!hid.value) { e.preventDefault(); st.textContent = 'Selecione um paciente na lista.'; q.focus(); } });
    })();
    </script>
        <?php
        $content = ob_get_clean();
        require __DIR__ . '/../../../views/layouts/professional-shell.php';
    }

    private function wantsJson(): bool
    {
        $accept = $_SERVER['HTTP_ACCEPT'] ?? '';
        $xReq = $_SERVER['HTTP_X_REQUESTED_WITH'] ?? '';
        return str_contains($accept, 'application/json') || strtolower($xReq) === 'xmlhttprequest';
    }

    private function respondError(string $message, int $code = 400, ?string $errorCode = null): void
    {
        http_response_code($code);
        if ($this->wantsJson()) {
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode(['error' => $message] + ($errorCode !== null ? ['code' => $errorCode] : []), JSON_UNESCAPED_UNICODE);
            return;
        }

        echo "<h1>Erro</h1><p>" . htmlspecialchars($message) . "</p>";
        echo "<a href='#' data-history-back>← Voltar</a>";
        exit;
    }
}
