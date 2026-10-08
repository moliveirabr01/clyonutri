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

        TenantContext::set($clinicId, [
            'id' => $userId,
            'name' => $userName,
            'professional_id' => $profId,
        ]);

        return [
            'userId' => $userId,
            'clinicId' => $clinicId,
            'userName' => $userName,
            'profId' => $profId,
        ];
    }

    /**
     * Tela inicial de conversas do profissional.
     */
    public function index(array $params = []): void
    {
        $auth = $this->getAuthInfo();
        $conversations = $this->chatService->listConversationsForProfessional($auth['profId']);

        $activeConvId = (int)($_GET['conversa'] ?? ($conversations[0]['id'] ?? 0));
        $this->renderChatView($auth, $conversations, $activeConvId);
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
                echo json_encode(['success' => true, 'message' => $msg]);
                return;
            }

            header("Location: /chat/{$convId}");
            exit;
        } catch (\Throwable $e) {
            $status = ($e instanceof TenantSecurityException) ? 403 : 400;
            $this->respondError($e->getMessage(), $status);
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
            echo json_encode($data);
        } catch (\Throwable $e) {
            $status = ($e instanceof TenantSecurityException) ? 403 : 400;
            http_response_code($status);
            echo json_encode(['error' => $e->getMessage()]);
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

            header('Content-Type: ' . $fileData['mime_type']);
            header('Content-Length: ' . $fileData['size_bytes']);
            header('Content-Disposition: inline; filename="' . addslashes($fileData['file_name']) . '"');
            header('X-Content-Type-Options: nosniff');
            header('Cache-Control: private, no-cache, must-revalidate');

            readfile($fileData['file_path']);
            exit;
        } catch (\Throwable $e) {
            $status = ($e instanceof TenantSecurityException) ? 403 : 404;
            http_response_code($status);
            echo "<h1>Acesso negado ou anexo não encontrado</h1><p>" . htmlspecialchars($e->getMessage()) . "</p>";
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
                $this->chatService->markAsRead($activeConvId, 'professional');
            } catch (\Throwable $e) {
                $activeConv = null;
            }
        }

        $totalUnread = array_reduce($conversations, fn($acc, $c) => $acc + (int)($c['unread_count'] ?? 0), 0);

        header('Content-Type: text/html; charset=utf-8');
        ?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Canal Seguro de Comunicação — ClyoNutri II</title>
    <style>
        :root {
            --primary: #059669;
            --primary-hover: #047857;
            --primary-light: #ecfdf5;
            --surface: #ffffff;
            --background: #f8fafc;
            --border: #e2e8f0;
            --text: #0f172a;
            --text-muted: #64748b;
            --patient-bubble: #f1f5f9;
            --nutri-bubble: #10b981;
            --nutri-bubble-text: #ffffff;
            --danger: #ef4444;
            --font: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Helvetica, Arial, sans-serif;
        }
        * { box-sizing: border-box; margin: 0; padding: 0; }
        body {
            font-family: var(--font);
            background: var(--background);
            color: var(--text);
            display: flex;
            height: 100vh;
            overflow: hidden;
        }

        /* Sidebar Navegação Global */
        .sidebar-nav {
            width: 240px;
            background: #0f172a;
            color: #f8fafc;
            display: flex;
            flex-direction: column;
            flex-shrink: 0;
        }
        .nav-header { padding: 20px; font-weight: 700; font-size: 1.15rem; border-bottom: 1px solid #1e293b; }
        .nav-items { list-style: none; padding: 16px 0; }
        .nav-items a {
            display: flex; align-items: center; gap: 10px;
            padding: 12px 20px; color: #94a3b8; text-decoration: none; font-size: 0.95rem;
            transition: all 0.15s ease;
        }
        .nav-items a:hover, .nav-items a.active { background: #1e293b; color: #ffffff; }

        /* Container Principal do Chat */
        .chat-layout {
            flex: 1;
            display: flex;
            height: 100vh;
            overflow: hidden;
            background: var(--surface);
        }

        /* Coluna Lista de Conversas */
        .conv-list-panel {
            width: 340px;
            border-right: 1px solid var(--border);
            display: flex;
            flex-direction: column;
            background: #ffffff;
            flex-shrink: 0;
        }
        .conv-list-header {
            padding: 18px 20px;
            border-bottom: 1px solid var(--border);
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        .conv-list-header h2 { font-size: 1.1rem; font-weight: 600; }
        .conv-list-items {
            flex: 1;
            overflow-y: auto;
            list-style: none;
        }
        .conv-card {
            padding: 14px 18px;
            border-bottom: 1px solid var(--border);
            cursor: pointer;
            text-decoration: none;
            color: inherit;
            display: block;
            transition: background 0.15s ease;
        }
        .conv-card:hover { background: #f8fafc; }
        .conv-card.active { background: var(--primary-light); border-left: 4px solid var(--primary); }
        .conv-card-title { display: flex; justify-content: space-between; margin-bottom: 4px; font-weight: 600; font-size: 0.95rem; }
        .conv-card-time { font-size: 0.75rem; color: var(--text-muted); font-weight: normal; }
        .conv-card-preview {
            font-size: 0.85rem; color: var(--text-muted);
            white-space: nowrap; overflow: hidden; text-overflow: ellipsis;
            display: flex; justify-content: space-between; align-items: center;
        }
        .badge-unread {
            background: var(--primary); color: white;
            font-size: 0.7rem; font-weight: 700;
            border-radius: 999px; padding: 2px 7px;
        }

        /* Coluna Mensagens e Conversa Ativa */
        .conv-main-panel {
            flex: 1;
            display: flex;
            flex-direction: column;
            height: 100%;
            background: #fafafa;
        }
        .chat-topbar {
            padding: 14px 24px;
            background: #ffffff;
            border-bottom: 1px solid var(--border);
            display: flex;
            align-items: center;
            justify-content: space-between;
        }
        .chat-topbar-info h3 { font-size: 1.05rem; font-weight: 600; }
        .chat-topbar-info p { font-size: 0.8rem; color: var(--text-muted); }
        .chat-security-tag {
            background: var(--primary-light);
            color: var(--primary);
            padding: 4px 10px;
            border-radius: 6px;
            font-size: 0.75rem;
            font-weight: 600;
            display: flex; align-items: center; gap: 6px;
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
            color: var(--text);
            border-bottom-left-radius: 4px;
        }
        .chat-bubble.professional {
            align-self: flex-end;
            background: var(--nutri-bubble);
            color: var(--nutri-bubble-text);
            border-bottom-right-radius: 4px;
        }
        .bubble-meta {
            font-size: 0.7rem;
            margin-top: 6px;
            opacity: 0.85;
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
            border-top: 1px solid var(--border);
        }
        .chat-input-form {
            display: flex;
            gap: 10px;
            align-items: center;
        }
        .chat-textarea {
            flex: 1;
            padding: 12px 16px;
            border: 1px solid var(--border);
            border-radius: 8px;
            font-family: inherit;
            font-size: 0.95rem;
            outline: none;
            resize: none;
            height: 48px;
            transition: border-color 0.15s ease;
        }
        .chat-textarea:focus { border-color: var(--primary); }
        .btn-send {
            background: var(--primary);
            color: white;
            border: none;
            padding: 0 20px;
            height: 48px;
            border-radius: 8px;
            font-weight: 600;
            cursor: pointer;
            transition: background 0.15s ease;
        }
        .btn-send:hover { background: var(--primary-hover); }
        .file-label {
            cursor: pointer;
            padding: 10px;
            border-radius: 8px;
            border: 1px solid var(--border);
            color: var(--text-muted);
            display: flex; align-items: center; justify-content: center;
        }
        .file-label:hover { background: #f8fafc; color: var(--text); }
        .file-input { display: none; }

        /* Empty State */
        .empty-chat {
            flex: 1;
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            color: var(--text-muted);
            padding: 40px;
            text-align: center;
        }
        .empty-chat h3 { font-size: 1.2rem; color: var(--text); margin-bottom: 8px; }

        /* Responsividade (1440, 1280, 768, 390px) */
        @media (max-width: 1024px) {
            .conv-list-panel { width: 280px; }
        }
        @media (max-width: 768px) {
            .sidebar-nav { display: none; }
            .conv-list-panel { width: 100%; border-right: none; }
            .chat-layout { flex-direction: column; }
            .conv-main-panel {
                display: <?= ($activeConv !== null) ? 'flex' : 'none' ?>;
                position: fixed; inset: 0; z-index: 50;
            }
        }
        @media (max-width: 390px) {
            .chat-topbar { padding: 10px 14px; }
            .chat-input-bar { padding: 10px 14px; }
            .chat-bubble { max-width: 85%; font-size: 0.88rem; }
        }
    </style>
</head>
<body>
    <!-- Sidebar Navegação -->
    <nav class="sidebar-nav">
        <div class="nav-header">🌱 ClyoNutri II</div>
        <ul class="nav-items">
            <li><a href="/dashboard">📊 Dashboard</a></li>
            <li><a href="/agenda">📅 Agenda</a></li>
            <li><a href="/pacientes">👥 Pacientes</a></li>
            <li><a href="/planos">🥗 Planos Alimentares</a></li>
            <li><a href="/chat" class="active">💬 Chat & Mensagens</a></li>
            <li><a href="/ajuda">❓ Central de Ajuda</a></li>
            <li><a href="/logout">🚪 Sair</a></li>
        </ul>
    </nav>

    <!-- Layout de Comunicação Segura -->
    <main class="chat-layout">
        <!-- Lista de Conversas -->
        <aside class="conv-list-panel">
            <div class="conv-list-header">
                <h2>Mensagens</h2>
                <?php if ($totalUnread > 0): ?>
                    <span class="badge-unread"><?= $totalUnread ?> não lidas</span>
                <?php endif; ?>
            </div>
            <ul class="conv-list-items">
                <?php if (empty($conversations)): ?>
                    <li style="padding: 24px; text-align: center; color: var(--text-muted); font-size: 0.9rem;">
                        Nenhuma conversa ativa no momento.<br>Inicie o contato a partir da ficha do paciente.
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
                        <p>Paciente vinculado • Consulta e acompanhamento</p>
                    </div>
                    <div class="chat-security-tag">
                        🔒 Canal Criptografado & Isolado
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
                            <div class="chat-bubble <?= htmlspecialchars($m['sender_type']) ?>" data-msg-id="<?= $m['id'] ?>">
                                <div><?= nl2br(htmlspecialchars($m['message'])) ?></div>
                                <?php if (!empty($m['attachment_path'])): ?>
                                    <div class="chat-attachment-box">
                                        📎 <a href="/chat/anexo/<?= $m['id'] ?>" target="_blank">
                                            <?= htmlspecialchars($m['attachment_name'] ?? 'Anexo Privado') ?>
                                        </a>
                                    </div>
                                <?php endif; ?>
                                <div class="bubble-meta">
                                    <span><?= date('H:i', strtotime($m['created_at'])) ?></span>
                                    <?php if ($m['sender_type'] === 'professional'): ?>
                                        <span><?= ($m['status'] === 'read') ? '✓✓' : '✓' ?></span>
                                    <?php endif; ?>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>

                <div class="chat-input-bar">
                    <form method="POST" action="/chat/<?= $activeConvId ?>/enviar" enctype="multipart/form-data" class="chat-input-form" id="chatSendForm">
                        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken) ?>">
                        <input type="hidden" name="idempotency_key" id="idempotencyKeyInput" value="<?= htmlspecialchars($clientMessageId) ?>">

                        <label class="file-label" title="Anexar arquivo seguro (PDF, JPG, PNG até 10MB)">
                            📎
                            <input type="file" name="attachment" class="file-input" id="attachmentInput" accept=".pdf,.png,.jpg,.jpeg">
                        </label>
                        <span id="selectedFileName" style="font-size: 0.8rem; color: var(--text-muted); display: none;"></span>

                        <textarea name="message" class="chat-textarea" id="chatMessageTextarea" placeholder="Digite uma mensagem segura para seu paciente..." required></textarea>
                        <button type="submit" class="btn-send" id="btnSend">Enviar</button>
                    </form>
                </div>

                <!-- Script de Polling com Cursor Delta e Prevenção de Conflito -->
                <script>
                    (function() {
                        const convId = <?= $activeConvId ?>;
                        let lastId = <?= !empty($messages) ? (int)end($messages)['id'] : 0 ?>;
                        let isPolling = false;
                        let pollIntervalMs = 3000;
                        const container = document.getElementById('chatMessagesContainer');
                        const fileInput = document.getElementById('attachmentInput');
                        const fileNameSpan = document.getElementById('selectedFileName');

                        if (container) {
                            container.scrollTop = container.scrollHeight;
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

                        // Polling com cursor proporcional ao estado da tela
                        function pollDelta() {
                            if (isPolling) return;
                            isPolling = true;

                            fetch('/chat/' + convId + '/poll?after_id=' + lastId, {
                                headers: { 'Accept': 'application/json' }
                            })
                            .then(res => res.json())
                            .then(data => {
                                if (data && data.messages && data.messages.length > 0) {
                                    const notice = document.getElementById('emptyStateNotice');
                                    if (notice) notice.remove();

                                    data.messages.forEach(m => {
                                        if (document.querySelector('[data-msg-id="' + m.id + '"]')) return;

                                        const bubble = document.createElement('div');
                                        bubble.className = 'chat-bubble ' + m.sender_type;
                                        bubble.setAttribute('data-msg-id', m.id);

                                        let content = '<div>' + (m.message || '').replace(/\n/g, '<br>') + '</div>';
                                        if (m.has_attachment || m.attachment_path) {
                                            content += '<div class="chat-attachment-box">📎 <a href="/chat/anexo/' + m.id + '" target="_blank">' + (m.attachment_name || 'Anexo Privado') + '</a></div>';
                                        }
                                        const timeStr = m.created_at ? m.created_at.substring(11, 16) : '';
                                        const checkmark = (m.sender_type === 'professional') ? ((m.status === 'read') ? '✓✓' : '✓') : '';
                                        content += '<div class="bubble-meta"><span>' + timeStr + '</span><span>' + checkmark + '</span></div>';

                                        bubble.innerHTML = content;
                                        container.appendChild(bubble);
                                        lastId = Math.max(lastId, parseInt(m.id, 10));
                                    });
                                    container.scrollTop = container.scrollHeight;
                                }

                                if (data && data.polling_policy) {
                                    // Ajustar dinamicamente se a janela estiver visível ou minimizada
                                    pollIntervalMs = document.hidden ? 
                                        data.polling_policy.background_window_ms : 
                                        data.polling_policy.active_window_ms;
                                }
                            })
                            .catch(() => {})
                            .finally(() => {
                                isPolling = false;
                                setTimeout(pollDelta, pollIntervalMs);
                            });
                        }

                        // Disparar polling contínuo
                        setTimeout(pollDelta, pollIntervalMs);
                    })();
                </script>

            <?php else: ?>
                <div class="empty-chat">
                    <h3>Nenhuma conversa selecionada</h3>
                    <p>Selecione um paciente na lista à esquerda para visualizar e enviar mensagens seguras.</p>
                </div>
            <?php endif; ?>
        </section>
    </main>
</body>
</html>
        <?php
    }

    private function wantsJson(): bool
    {
        $accept = $_SERVER['HTTP_ACCEPT'] ?? '';
        $xReq = $_SERVER['HTTP_X_REQUESTED_WITH'] ?? '';
        return str_contains($accept, 'application/json') || strtolower($xReq) === 'xmlhttprequest';
    }

    private function respondError(string $message, int $code = 400): void
    {
        http_response_code($code);
        if ($this->wantsJson()) {
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode(['error' => $message]);
            return;
        }

        echo "<h1>Erro</h1><p>" . htmlspecialchars($message) . "</p>";
        echo "<a href='javascript:history.back()'>← Voltar</a>";
        exit;
    }
}
