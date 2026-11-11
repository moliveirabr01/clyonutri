<?php

declare(strict_types=1);

namespace ClyoNutri\Application\Controllers;

use ClyoNutri\Core\Auth\TenantContext;
use ClyoNutri\Core\Database\Connection;
use ClyoNutri\Core\Http\Pagination;
use ClyoNutri\Core\Security\Csrf;
use ClyoNutri\Domain\Helpdesk\HelpdeskAttachmentService;
use ClyoNutri\Domain\Helpdesk\HelpdeskTicketService;
use ClyoNutri\Domain\Helpdesk\KnowledgeBaseService;
use PDO;

class HelpdeskController
{
    private PDO $db;
    private KnowledgeBaseService $kbService;
    private HelpdeskTicketService $ticketService;
    private HelpdeskAttachmentService $attachmentService;

    public function __construct(
        ?PDO $db = null,
        ?KnowledgeBaseService $kbService = null,
        ?HelpdeskTicketService $ticketService = null,
        ?HelpdeskAttachmentService $attachmentService = null
    ) {
        $this->db = $db ?? Connection::getInstance();
        $this->kbService = $kbService ?? new KnowledgeBaseService($this->db);
        $this->ticketService = $ticketService ?? new HelpdeskTicketService($this->db);
        $this->attachmentService = $attachmentService ?? new HelpdeskAttachmentService($this->db);
    }

    private function getAuthInfo(): array
    {
        $auth = \ClyoNutri\Core\Auth\AuthContext::resolveFromSession($this->db);
        if (!$auth) {
            return [
                'userId' => 0,
                'clinicId' => 0,
                'userRole' => '',
                'userName' => 'Desconhecido',
                'clinicName' => 'Desconhecido',
                'isSupport' => false,
            ];
        }

        return [
            'userId' => $auth->getUserId(),
            'clinicId' => $auth->getClinicId(),
            'userRole' => $auth->getUserRole(),
            'userName' => $auth->getName(),
            'clinicName' => $auth->getClinicName(),
            'isSupport' => $auth->isSupport(),
        ];
    }

    /**
     * Home da Central de Ajuda (NI2-196)
     */
    public function index(): void
    {
        $auth = $this->getAuthInfo();
        $query = trim((string)($_GET['q'] ?? ''));
        $categories = $this->kbService->getCategoriesWithCount();
        $searchResults = [];

        if ($query !== '') {
            $searchResults = $this->kbService->search($query);
        }

        $featuredFaqs = $this->kbService->getFeaturedFaqs(6);
        $myTicketsCount = count($this->ticketService->listUserTickets($auth['userId'], $auth['clinicId'], HelpdeskTicketService::STATUS_OPEN));

        $title = 'Central de ajuda';
        $activeNav = 'ajuda';
        $clinicName = $auth['clinicName'];
        $userName = $auth['userName'];

        ob_start();
        ?>
        <div style="max-width: 1040px; margin: 0 auto; padding-bottom: var(--space-8);">
          <!-- Header Hero da Central de Ajuda -->
          <div style="background: linear-gradient(135deg, var(--botanical-forest-900, #0F3E17) 0%, var(--botanical-forest-800, #1B5226) 100%); color: #fff; border-radius: 12px; padding: 36px 32px; margin-bottom: 28px; box-shadow: 0 4px 14px rgba(0,0,0,0.08);">
            <div style="max-width: 680px;">
              <span style="display: inline-block; background: rgba(191, 216, 194, 0.2); color: var(--botanical-sage-200, #D3E5D5); font-size: 0.8rem; font-weight: 600; padding: 4px 10px; border-radius: 20px; margin-bottom: 12px;">
                CENTRAL DE AJUDA & SERVICE DESK CLYONUTRI II
              </span>
              <h1 style="color: #fff; font-size: 1.85rem; font-family: 'Playfair Display', serif; margin: 0 0 10px 0;">Como podemos ajudar você hoje?</h1>
              <p style="color: var(--neutral-gray-400, #98A2B3); font-size: 0.95rem; margin: 0 0 20px 0;">
                Encontre guias passo a passo, tutoriais de nutrição clínica, tabela TACO, prescrições e suporte técnico direto com nosso time.
              </p>

              <!-- Formulário de Busca -->
              <form action="/ajuda" method="GET" style="display: flex; gap: 8px;">
                <div style="position: relative; flex: 1;">
                  <label for="kb-search-query" class="sr-only">Buscar na Central de Ajuda</label>
                  <input type="text" id="kb-search-query" name="q" value="<?= htmlspecialchars($query) ?>" placeholder="Buscar por anamnese, tabela TACO, Pollock, convite..." 
                         style="width: 100%; padding: 12px 16px; border-radius: 8px; border: 1px solid var(--neutral-gray-700, #344054); background: #fff; color: var(--botanical-forest-900, #0F3E17); font-size: 0.95rem; box-sizing: border-box;" required>
                </div>
                <button type="submit" class="btn btn-primary" style="padding: 0 22px; font-weight: 600;">
                  Buscar
                </button>
              </form>
            </div>
          </div>

          <!-- Ações Rápidas -->
          <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 24px; flex-wrap: wrap; gap: 12px;">
            <div style="display: flex; gap: 10px; flex-wrap: wrap;">
              <a href="/onboarding" class="btn btn-outline" data-testid="onboarding-guide-link" style="display: inline-flex; align-items: center; gap: 6px;">
                <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M2 3h6a4 4 0 0 1 4 4v14a3 3 0 0 0-3-3H2z"/><path d="M22 3h-6a4 4 0 0 0-4 4v14a3 3 0 0 1 3-3h7z"/></svg>
                <span>Guia de primeiros passos</span>
              </a>
              <a href="/ajuda/abrir-chamado" class="btn btn-primary" style="display: inline-flex; align-items: center; gap: 6px;">
                <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg>
                <span>Abrir novo chamado</span>
              </a>
              <a href="/ajuda/meus-chamados" class="btn btn-secondary" style="display: inline-flex; align-items: center; gap: 6px;">
                <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M4 4h16c1.1 0 2 .9 2 2v12c0 1.1-.9 2-2 2H4c-1.1 0-2-.9-2-2V6c0-1.1.9-2 2-2z"/><polyline points="22,6 12,13 2,6"/></svg>
                <span>Meus chamados (<?= $myTicketsCount ?> ativos)</span>
              </a>
            </div>

            <?php if ($auth['isSupport']): ?>
              <a href="/suporte/fila" class="btn btn-outline" style="border-color: var(--semantic-info, #175CD3); color: var(--semantic-info, #175CD3); font-weight: 600;">
                Fila do service desk (operador)
              </a>
            <?php endif; ?>
          </div>

          <?php if ($query !== ''): ?>
            <!-- Resultados da Busca -->
            <div class="card" style="margin-bottom: 28px;">
              <div class="card__header" style="display: flex; justify-content: space-between; align-items: center;">
                <h3 style="margin: 0;">Resultados para "<?= htmlspecialchars($query) ?>" (<?= count($searchResults) ?>)</h3>
                <a href="/ajuda" style="font-size: 0.85rem; color: var(--neutral-gray-500, #667085);">Limpar busca</a>
              </div>
              <?php if (empty($searchResults)): ?>
                <div style="padding: 24px; text-align: center; color: var(--neutral-gray-500, #667085);">
                  <p>Nenhum artigo encontrado para o termo pesquisado.</p>
                  <a href="/ajuda/abrir-chamado" class="btn btn-primary" style="margin-top: 10px;">
                    Abrir chamado com a equipe
                  </a>
                </div>
              <?php else: ?>
                <div style="display: flex; flex-direction: column; gap: 12px;">
                  <?php foreach ($searchResults as $art): ?>
                    <a href="/ajuda/artigo/<?= $art['slug'] ?>" style="text-decoration: none; color: inherit; display: block; padding: 14px 18px; border-radius: 8px; border: 1px solid var(--neutral-gray-200, #E5E7EB); background: var(--neutral-gray-50, #F9FAFB); transition: all 0.2s;" data-hover="borderColor:var(--neutral-gray-400, #98A2B3)" data-unhover="borderColor:var(--neutral-gray-200, #E5E7EB)">
                      <strong style="color: var(--botanical-forest-900, #0F3E17); font-size: 1rem; display: block; margin-bottom: 4px;"><?= htmlspecialchars($art['title']) ?></strong>
                      <p style="color: var(--neutral-gray-600, #475467); font-size: 0.85rem; margin: 0;"><?= htmlspecialchars($art['summary']) ?></p>
                      <span style="font-size: 0.75rem; color: var(--neutral-gray-400, #98A2B3); margin-top: 6px; display: inline-block;"><?= $art['view_count'] ?> visualizações</span>
                    </a>
                  <?php endforeach; ?>
                </div>
              <?php endif; ?>
            </div>
          <?php endif; ?>

          <!-- Seção de Categorias -->
          <h2 style="font-size: 1.25rem; font-weight: 700; color: var(--botanical-forest-900, #0F3E17); margin: 0 0 16px 0;">Categorias de Ajuda</h2>
          <div style="display: grid; grid-template-columns: repeat(auto-fill, minmax(300px, 1fr)); gap: 16px; margin-bottom: 32px;">
            <?php foreach ($categories as $cat): ?>
              <a href="/ajuda/categoria/<?= $cat['slug'] ?>" style="text-decoration: none; color: inherit;" class="card" data-hover="transform:translateY(-2px)" data-unhover="transform:none">
                <div style="display: flex; align-items: flex-start; gap: 12px;">
                  <div style="background: var(--botanical-sage-100, #E5F0E5); color: var(--botanical-green-600, #39734A); width: 44px; height: 44px; border-radius: 12px; display: flex; align-items: center; justify-content: center; flex-shrink: 0;">
                    <?= self::categoryIcon((string)($cat['icon'] ?? 'book-open')) ?>
                  </div>
                  <div>
                    <h4 style="margin: 0 0 4px 0; color: var(--botanical-forest-900, #0F3E17); font-size: 0.95rem; font-weight: 600;"><?= htmlspecialchars($cat['name']) ?></h4>
                    <p style="margin: 0 0 8px 0; font-size: 0.8rem; color: var(--neutral-gray-500, #667085);"><?= htmlspecialchars($cat['description']) ?></p>
                    <span style="font-size: 0.75rem; font-weight: 600; color: var(--botanical-green-600, #39734A);"><?= $cat['count'] ?> <?= $cat['count'] === 1 ? 'artigo' : 'artigos' ?> →</span>
                  </div>
                </div>
              </a>
            <?php endforeach; ?>
          </div>

          <!-- FAQs em Destaque -->
          <div class="card">
            <div class="card__header">
              <h3 style="margin: 0;">Perguntas frequentes e artigos mais acessados</h3>
            </div>
            <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(280px, 1fr)); gap: 14px;">
              <?php foreach ($featuredFaqs as $faq): ?>
                <a href="/ajuda/artigo/<?= $faq['slug'] ?>" style="text-decoration: none; color: inherit; padding: 12px; border-radius: 8px; border: 1px solid var(--neutral-gray-200, #E5E7EB); background: #fff; display: block;" data-hover="borderColor:var(--botanical-green-600, #39734A)" data-unhover="borderColor:var(--neutral-gray-200, #E5E7EB)">
                  <div style="font-size: 0.75rem; font-weight: 700; color: var(--neutral-gray-500, #667085); text-transform: uppercase; margin-bottom: 4px;">
                    <?= htmlspecialchars(KnowledgeBaseService::CATEGORIES[$faq['category']]['name'] ?? $faq['category']) ?>
                  </div>
                  <strong style="color: var(--botanical-forest-900, #0F3E17); font-size: 0.9rem; display: block; margin-bottom: 4px;"><?= htmlspecialchars($faq['title']) ?></strong>
                  <p style="color: var(--neutral-gray-500, #667085); font-size: 0.8rem; margin: 0;"><?= htmlspecialchars($faq['summary']) ?></p>
                </a>
              <?php endforeach; ?>
            </div>
          </div>
        </div>
        <?php
        $content = ob_get_clean();
        require __DIR__ . '/../../../views/layouts/professional-shell.php';
    }

    /**
     * Lista de Artigos de uma Categoria (NI2-197)
     */
    public function categoryArticles(array $params): void
    {
        $auth = $this->getAuthInfo();
        $slug = (string)($params['slug'] ?? '');
        $catMeta = KnowledgeBaseService::CATEGORIES[$slug] ?? null;

        if (!$catMeta) {
            header('Location: /ajuda');
            exit;
        }

        $articles = $this->kbService->getArticlesByCategory($slug);

        $title = $catMeta['name'] . ' — Central de Ajuda';
        $activeNav = 'ajuda';
        $clinicName = $auth['clinicName'];
        $userName = $auth['userName'];

        ob_start();
        ?>
        <div style="max-width: 900px; margin: 0 auto; padding-bottom: var(--space-8);">
          <div style="margin-bottom: 20px;">
            <a href="/ajuda" style="font-size: 0.85rem; color: var(--neutral-gray-500, #667085); text-decoration: none;">← Voltar para central de ajuda</a>
          </div>

          <div class="card" style="margin-bottom: 24px;">
            <div style="display: flex; align-items: center; gap: 12px; margin-bottom: 8px;">
              <span style="background: var(--botanical-sage-100, #E5F0E5); color: var(--botanical-green-600, #39734A); width: 44px; height: 44px; border-radius: 12px; display: inline-flex; align-items: center; justify-content: center; flex-shrink: 0;"><?= self::categoryIcon((string)($catMeta['icon'] ?? 'book-open')) ?></span>
              <h1 style="margin: 0; font-size: 1.5rem; color: var(--botanical-forest-900, #0F3E17);"><?= htmlspecialchars($catMeta['name']) ?></h1>
            </div>
            <p style="margin: 0; color: var(--neutral-gray-500, #667085); font-size: 0.95rem;"><?= htmlspecialchars($catMeta['description']) ?></p>
          </div>

          <div style="display: flex; flex-direction: column; gap: 12px;">
            <?php if (empty($articles)): ?>
              <div class="card" style="text-align: center; color: var(--neutral-gray-500, #667085); padding: 32px;">
                <p>Nenhum artigo publicado nesta categoria ainda.</p>
                <a href="/ajuda/abrir-chamado" class="btn btn-primary" style="margin-top: 10px;">Abrir chamado</a>
              </div>
            <?php else: ?>
              <?php foreach ($articles as $art): ?>
                <a href="/ajuda/artigo/<?= $art['slug'] ?>" class="card" style="text-decoration: none; color: inherit; transition: border-color 0.2s;" data-hover="borderColor:var(--botanical-green-600, #39734A)" data-unhover="borderColor:var(--neutral-gray-200, #E5E7EB)">
                  <div style="display: flex; justify-content: space-between; align-items: baseline;">
                    <h3 style="margin: 0 0 6px 0; color: var(--botanical-forest-900, #0F3E17); font-size: 1.05rem;"><?= htmlspecialchars($art['title']) ?></h3>
                    <span style="font-size: 0.75rem; color: var(--neutral-gray-400, #98A2B3);"><?= $art['view_count'] ?></span>
                  </div>
                  <p style="margin: 0; color: var(--neutral-gray-600, #475467); font-size: 0.85rem;"><?= htmlspecialchars($art['summary']) ?></p>
                </a>
              <?php endforeach; ?>
            <?php endif; ?>
          </div>
        </div>
        <?php
        $content = ob_get_clean();
        require __DIR__ . '/../../../views/layouts/professional-shell.php';
    }

    /**
     * Visualização de Artigo Individual (NI2-197)
     */
    public function showArticle(array $params): void
    {
        $auth = $this->getAuthInfo();
        $slug = (string)($params['slug'] ?? '');
        $article = $this->kbService->getArticleBySlug($slug);

        if (!$article) {
            header('Location: /ajuda');
            exit;
        }

        $relatedArticles = $this->kbService->getArticlesByCategory($article['category']);

        $title = $article['title'] . ' — Central de Ajuda';
        $activeNav = 'ajuda';
        $clinicName = $auth['clinicName'];
        $userName = $auth['userName'];

        // Renderizador simples de Markdown para HTML seguro
        $markdownHtml = $this->renderMarkdown($article['content_markdown']);

        ob_start();
        ?>
        <div style="max-width: 900px; margin: 0 auto; padding-bottom: var(--space-8);">
          <div style="margin-bottom: 20px;">
            <a href="/ajuda/categoria/<?= $article['category'] ?>" style="font-size: 0.85rem; color: var(--neutral-gray-500, #667085); text-decoration: none;">
              ← Voltar para <?= htmlspecialchars($article['category_meta']['name']) ?>
            </a>
          </div>

          <div class="card" style="margin-bottom: 24px;">
            <div style="border-bottom: 1px solid var(--neutral-gray-200, #E5E7EB); padding-bottom: 16px; margin-bottom: 20px;">
              <span style="font-size: 0.8rem; font-weight: 700; color: var(--botanical-green-600, #39734A); text-transform: uppercase;">
                <?= htmlspecialchars($article['category_meta']['name']) ?>
              </span>
              <h1 style="margin: 8px 0; font-size: 1.6rem; color: var(--botanical-forest-900, #0F3E17); font-family: 'Playfair Display', serif;">
                <?= htmlspecialchars($article['title']) ?>
              </h1>
              <div style="font-size: 0.8rem; color: var(--neutral-gray-500, #667085); display: flex; gap: 16px;">
                <span>Atualizado em <?= date('d/m/Y', strtotime($article['updated_at'])) ?></span>
                <span>•</span>
                <span><?= $article['view_count'] ?> visualizações</span>
              </div>
            </div>

            <div class="article-content" style="font-size: 0.95rem; line-height: 1.7; color: var(--neutral-gray-700, #344054);">
              <?= $markdownHtml ?>
            </div>
          </div>

          <!-- Box de Ajuda Adicional -->
          <div class="card" style="background: var(--neutral-gray-50, #F9FAFB); border-color: var(--neutral-gray-300, #D1D5DB); display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 16px;">
            <div>
              <strong style="color: var(--botanical-forest-900, #0F3E17); font-size: 1rem; display: block;">Este artigo resolveu sua dúvida?</strong>
              <p style="margin: 0; color: var(--neutral-gray-500, #667085); font-size: 0.85rem;">Se ainda precisar de suporte especializado, nosso time está pronto para atender.</p>
            </div>
            <a href="/ajuda/abrir-chamado?categoria=<?= urlencode($article['category']) ?>" class="btn btn-primary">
              Abrir um Chamado
            </a>
          </div>
        </div>
        <?php
        $content = ob_get_clean();
        require __DIR__ . '/../../../views/layouts/professional-shell.php';
    }

    /**
     * Tela de Abertura de Chamado (NI2-199)
     */
    public function showCreateTicket(): void
    {
        $auth = $this->getAuthInfo();
        $selectedCat = (string)($_GET['categoria'] ?? 'question');
        $csrfToken = Csrf::token();

        // NI2-459: erro e valores digitados chegam pela sessão (nunca pela URL),
        // para não exibir mensagem de exceção nem texto arbitrário vindo de um link.
        $formState = $_SESSION['helpdesk_ticket_form'] ?? [];
        unset($_SESSION['helpdesk_ticket_form']);
        $errorMessage = isset($formState['error']) ? (string)$formState['error'] : null;
        $errorField = isset($formState['field']) ? (string)$formState['field'] : null;
        $old = is_array($formState['old'] ?? null) ? $formState['old'] : [];

        $title = 'Abrir chamado';
        $activeNav = 'ajuda';
        $clinicName = $auth['clinicName'];
        $userName = $auth['userName'];

        ob_start();
        require __DIR__ . '/../../../views/helpdesk/new-ticket.php';
        $content = ob_get_clean();
        require __DIR__ . '/../../../views/layouts/professional-shell.php';
    }

    /**
     * Processa a Abertura de Chamado (NI2-199 e NI2-200)
     */
    public function storeTicket(): void
    {
        $auth = $this->getAuthInfo();
        $old = [
            'subject' => (string)($_POST['subject'] ?? ''),
            'category' => (string)($_POST['category'] ?? ''),
            'type' => (string)($_POST['type'] ?? 'question'),
            'description' => (string)($_POST['description'] ?? ''),
            'priority_perceived' => (string)($_POST['priority_perceived'] ?? 'medium'),
            'origin_route' => (string)($_POST['origin_route'] ?? ''),
        ];

        if (!Csrf::validate($_POST['_csrf_token'] ?? '')) {
            $this->redirectToTicketForm(
                'Sua sessão expirou. Confira os dados e envie o chamado novamente.',
                null,
                $old
            );
        }

        try {
            $ticket = $this->ticketService->createTicket(
                $auth['clinicId'],
                $auth['userId'],
                $old['subject'],
                $old['category'],
                $old['type'],
                $old['description'],
                $old['priority_perceived'],
                $old['origin_route'] !== '' ? $old['origin_route'] : null
            );
        } catch (\InvalidArgumentException $e) {
            // Mensagens de validação do domínio são textos fixos escritos para o usuário.
            $this->redirectToTicketForm($e->getMessage(), $this->fieldForValidationError($e->getMessage()), $old);
        } catch (\Throwable $e) {
            error_log('[helpdesk] falha ao abrir chamado: ' . get_class($e) . ': ' . $e->getMessage());
            $this->redirectToTicketForm(
                'Não foi possível registrar o chamado agora. Tente novamente em alguns minutos.',
                null,
                $old
            );
        }

        // Upload de anexo se enviado. O chamado já existe: uma falha aqui não volta ao
        // formulário (o reenvio criaria um chamado duplicado); o anexo pode ir numa resposta.
        if (!empty($_FILES['attachment']) && ($_FILES['attachment']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE) {
            try {
                $this->attachmentService->storeAttachment(
                    (int)$ticket['id'],
                    $auth['userId'],
                    $_FILES['attachment']
                );
            } catch (\Throwable $e) {
                error_log('[helpdesk] chamado ' . $ticket['protocol'] . ' criado, anexo recusado: ' . $e->getMessage());
            }
        }

        header("Location: /ajuda/chamado/{$ticket['protocol']}?sucesso=1");
        exit;
    }

    /**
     * NI2-459: guarda erro e valores na sessão e volta ao formulário sem parâmetros na URL.
     */
    private function redirectToTicketForm(string $message, ?string $field, array $old): never
    {
        $_SESSION['helpdesk_ticket_form'] = ['error' => $message, 'field' => $field, 'old' => $old];
        header('Location: /ajuda/abrir-chamado');
        exit;
    }

    private function fieldForValidationError(string $message): ?string
    {
        $m = mb_strtolower($message);
        return match (true) {
            str_contains($m, 'assunto') => 'subject',
            str_contains($m, 'descrição') => 'description',
            str_contains($m, 'categoria') => 'category',
            str_contains($m, 'tipo de chamado') => 'type',
            default => null,
        };
    }

    /**
     * Lista de Chamados do Usuário: Meus Chamados (NI2-201)
     */
    public function myTickets(): void
    {
        $auth = $this->getAuthInfo();
        $statusFilter = trim((string)($_GET['status'] ?? ''));
        $tickets = $this->ticketService->listUserTickets($auth['userId'], $auth['clinicId'], $statusFilter !== '' ? $statusFilter : null);

        $title = 'Meus chamados';
        $activeNav = 'ajuda';
        $clinicName = $auth['clinicName'];
        $userName = $auth['userName'];

        ob_start();
        ?>
        <div style="max-width: 1040px; margin: 0 auto; padding-bottom: var(--space-8);">
          <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 24px; flex-wrap: wrap; gap: 12px;">
            <div>
              <a href="/ajuda" style="font-size: 0.85rem; color: var(--neutral-gray-500, #667085); text-decoration: none;">← Central de Ajuda</a>
              <h1 style="margin: 4px 0 0 0; font-size: 1.5rem; color: var(--botanical-forest-900, #0F3E17);">Meus chamados de suporte</h1>
            </div>
            <a href="/ajuda/abrir-chamado" class="btn btn-primary">
              + Abrir Novo Chamado
            </a>
          </div>

          <!-- Filtros de Status -->
          <div style="display: flex; gap: 8px; margin-bottom: 20px; flex-wrap: wrap;">
            <a href="/ajuda/meus-chamados" class="btn btn-sm <?= $statusFilter === '' ? 'btn-primary' : 'btn-secondary' ?>">
              Todos (<?= count($this->ticketService->listUserTickets($auth['userId'], $auth['clinicId'])) ?>)
            </a>
            <?php foreach (HelpdeskTicketService::STATUS_LABELS as $sKey => $sLabel): ?>
              <a href="/ajuda/meus-chamados?status=<?= $sKey ?>" class="btn btn-sm <?= $statusFilter === $sKey ? 'btn-primary' : 'btn-secondary' ?>">
                <?= $sLabel ?>
              </a>
            <?php endforeach; ?>
          </div>

          <?php if (empty($tickets)): ?>
            <div class="card" style="text-align: center; padding: 48px; color: var(--neutral-gray-500, #667085);">
              <p style="font-size: 1.05rem; margin-bottom: 12px;">Nenhum chamado encontrado com o filtro selecionado.</p>
              <a href="/ajuda/abrir-chamado" class="btn btn-primary">Abrir chamado</a>
            </div>
          <?php else: ?>
            <div class="table-responsive">
              <table class="table">
                <thead>
                  <tr>
                    <th>Protocolo</th>
                    <th>Assunto</th>
                    <th>Categoria</th>
                    <th>Status</th>
                    <th>Mensagens</th>
                    <th>Atualizado</th>
                    <th style="text-align: right;">Ação</th>
                  </tr>
                </thead>
                <tbody>
                  <?php foreach ($tickets as $t): ?>
                    <tr>
                      <td style="font-family: monospace; font-weight: 700; color: var(--botanical-green-600, #39734A);">
                        <?= htmlspecialchars($t['protocol']) ?>
                      </td>
                      <td style="font-weight: 600; color: var(--botanical-forest-900, #0F3E17);">
                        <a href="/ajuda/chamado/<?= $t['protocol'] ?>" style="color: inherit; text-decoration: none;">
                          <?= htmlspecialchars($t['subject']) ?>
                        </a>
                      </td>
                      <td style="font-size: 0.85rem; color: var(--neutral-gray-500, #667085);">
                        <?= htmlspecialchars($t['category_label']) ?>
                      </td>
                      <td>
                        <span class="badge" style="background: var(--brand-navy-100); color: var(--brand-navy-900); font-size: 0.75rem;">
                          <?= htmlspecialchars($t['status_label']) ?>
                        </span>
                      </td>
                      <td style="font-size: 0.85rem; color: var(--neutral-gray-500, #667085);">
                        <?= $t['messages_count'] ?> <?= $t['attachments_count'] > 0 ? "• {$t['attachments_count']}" : '' ?>
                      </td>
                      <td style="font-size: 0.8rem; color: var(--neutral-gray-400, #98A2B3);">
                        <?= date('d/m/Y H:i', strtotime($t['updated_at'])) ?>
                      </td>
                      <td style="text-align: right;">
                        <a href="/ajuda/chamado/<?= $t['protocol'] ?>" class="btn btn-sm btn-secondary">
                          Ver detalhes →
                        </a>
                      </td>
                    </tr>
                  <?php endforeach; ?>
                </tbody>
              </table>
            </div>
          <?php endif; ?>
        </div>
        <?php
        $content = ob_get_clean();
        require __DIR__ . '/../../../views/layouts/professional-shell.php';
    }

    /**
     * Detalhes e Timeline do Chamado (NI2-202 a NI2-207)
     */
    public function ticketDetail(array $params): void
    {
        $auth = $this->getAuthInfo();
        $protocol = (string)($params['protocol'] ?? '');

        try {
            $ticket = $this->ticketService->getTicketByProtocol($protocol, $auth['userId'], $auth['clinicId'], $auth['isSupport']);
        } catch (\Throwable $e) {
            header('Location: /ajuda/meus-chamados');
            exit;
        }

        $csrfToken = Csrf::token();
        $title = "Chamado {$ticket['protocol']} — Detalhes";
        $activeNav = 'ajuda';
        $clinicName = $auth['clinicName'];
        $userName = $auth['userName'];

        ob_start();
        ?>
        <div style="max-width: 960px; margin: 0 auto; padding-bottom: var(--space-8);">
          <div style="margin-bottom: 20px; display: flex; justify-content: space-between; align-items: center;">
            <a href="/ajuda/meus-chamados" style="font-size: 0.85rem; color: var(--neutral-gray-500, #667085); text-decoration: none;">← Voltar para meus chamados</a>
            <?php if ($auth['isSupport']): ?>
              <a href="/suporte/chamado/<?= $ticket['id'] ?>" class="btn btn-sm btn-outline" style="border-color: var(--semantic-info, #175CD3); color: var(--semantic-info, #175CD3);">
                Abrir no painel de atendimento (backoffice)
              </a>
            <?php endif; ?>
          </div>

          <!-- Cabeçalho do Chamado -->
          <div class="card" style="margin-bottom: 24px;">
            <div style="display: flex; justify-content: space-between; align-items: flex-start; flex-wrap: wrap; gap: 12px; margin-bottom: 12px;">
              <div>
                <div style="display: flex; align-items: center; gap: 8px; margin-bottom: 6px;">
                  <span style="font-family: monospace; font-size: 1.15rem; font-weight: 700; color: var(--botanical-green-600, #39734A);">
                    <?= htmlspecialchars($ticket['protocol']) ?>
                  </span>
                  <span class="badge" style="background: var(--neutral-gray-200, #E5E7EB); color: var(--botanical-forest-900, #0F3E17); font-size: 0.75rem;">
                    <?= htmlspecialchars($ticket['status_label']) ?>
                  </span>
                  <span class="badge" style="background: var(--neutral-gray-100, #F4F5F6); color: var(--neutral-gray-600, #475467); font-size: 0.75rem;">
                    <?= htmlspecialchars($ticket['type_label']) ?>
                  </span>
                </div>
                <h1 style="margin: 0 0 6px 0; font-size: 1.4rem; color: var(--botanical-forest-900, #0F3E17);">
                  <?= htmlspecialchars($ticket['subject']) ?>
                </h1>
                <div style="font-size: 0.85rem; color: var(--neutral-gray-500, #667085);">
                  Categoria: <strong><?= htmlspecialchars($ticket['category_label']) ?></strong> • Aberto em <?= date('d/m/Y H:i', strtotime($ticket['created_at'])) ?>
                </div>
              </div>

              <!-- Ações de Resolução -->
              <?php if (in_array($ticket['status'], [HelpdeskTicketService::STATUS_OPEN, HelpdeskTicketService::STATUS_IN_PROGRESS, HelpdeskTicketService::STATUS_WAITING_CLIENT])): ?>
                <form action="/ajuda/chamado/<?= $ticket['protocol'] ?>/resolver" method="POST" data-confirm="Confirma que o problema foi solucionado e deseja fechar o chamado?">
                  <input type="hidden" name="_csrf_token" value="<?= $csrfToken ?>">
                  <button type="submit" class="btn btn-sm btn-secondary" style="color: var(--semantic-success, #0A7075); border-color: var(--semantic-success, #0A7075);">
                    ✓ Marcar como Resolvido
                  </button>
                </form>
              <?php endif; ?>
            </div>
          </div>

          <?php /* Avaliação CSAT (NI2-207) se resolvido ou fechado */ ?>
          <?php if (in_array($ticket['status'], [HelpdeskTicketService::STATUS_RESOLVED, HelpdeskTicketService::STATUS_CLOSED])): ?>
            <div class="card" style="background: var(--semantic-success-light, #E3F4F2); border: 1px solid var(--semantic-success-border, #B7DFDC); margin-bottom: 24px;">
              <h3 style="margin: 0 0 8px 0; color: var(--semantic-success-text, #0A5F63); font-size: 1.1rem;">
                Avaliação do Atendimento (CSAT)
              </h3>
              <?php if (!empty($ticket['feedback'])): ?>
                <div style="font-size: 0.95rem; color: var(--semantic-success-text, #0A5F63);">
                  Você avaliou este atendimento com <strong><?= str_repeat('★', (int)$ticket['feedback']['rating']) ?><?= str_repeat('☆', 5 - (int)$ticket['feedback']['rating']) ?> (<?= $ticket['feedback']['rating'] ?>/5)</strong>.
                  <?php if (!empty($ticket['feedback']['comment'])): ?>
                    <p style="margin: 6px 0 0 0; color: var(--semantic-success-text, #0A5F63); font-style: italic;">"<?= htmlspecialchars($ticket['feedback']['comment']) ?>"</p>
                  <?php endif; ?>
                </div>
              <?php else: ?>
                <p style="margin: 0 0 12px 0; font-size: 0.9rem; color: var(--semantic-success-text, #0A5F63);">
                  Este chamado foi solucionado. Como você avalia a agilidade e qualidade do suporte prestado?
                </p>
                <form action="/ajuda/chamado/<?= $ticket['protocol'] ?>/csat" method="POST" style="display: flex; flex-direction: column; gap: 10px;">
                  <input type="hidden" name="_csrf_token" value="<?= $csrfToken ?>">
                  <div style="display: flex; gap: 16px; align-items: center;">
                    <?php for ($i = 5; $i >= 1; $i--): ?>
                      <label style="display: flex; align-items: center; gap: 4px; font-weight: 600; cursor: pointer; color: var(--semantic-success-text, #0A5F63);">
                        <input type="radio" name="rating" value="<?= $i ?>" <?= $i === 5 ? 'checked' : '' ?>>
                        <?= $i ?> <?= $i === 5 ? '(Excelente)' : '' ?>
                      </label>
                    <?php endfor; ?>
                  </div>
                  <div>
                    <input type="text" name="comment" placeholder="Comentário opcional sobre o atendimento..." 
                           style="width: 100%; padding: 8px 12px; border: 1px solid var(--semantic-success-border, #B7DFDC); border-radius: 6px; font-size: 0.85rem; box-sizing: border-box;">
                  </div>
                  <div>
                    <button type="submit" class="btn btn-primary" style="background: var(--semantic-success, #0A7075); border-color: var(--semantic-success, #0A7075);">
                      Enviar avaliação
                    </button>
                  </div>
                </form>
              <?php endif; ?>
            </div>
          <?php endif; ?>

          <?php /* Anexos do Chamado (NI2-200) */ ?>
          <?php if (!empty($ticket['attachments'])): ?>
            <div class="card" style="margin-bottom: 24px;">
              <h4 style="margin: 0 0 12px 0; font-size: 0.95rem; color: var(--botanical-forest-900, #0F3E17);">Anexos vinculados (<?= count($ticket['attachments']) ?>)</h4>
              <div style="display: flex; flex-wrap: wrap; gap: 10px;">
                <?php foreach ($ticket['attachments'] as $att): ?>
                  <a href="/ajuda/anexo/<?= $att['id'] ?>" target="_blank" style="text-decoration: none; display: inline-flex; align-items: center; gap: 8px; padding: 8px 12px; background: var(--neutral-gray-50, #F9FAFB); border: 1px solid var(--neutral-gray-200, #E5E7EB); border-radius: 6px; font-size: 0.85rem; color: var(--botanical-green-600, #39734A);">
                    <span></span>
                    <span style="font-weight: 500;"><?= htmlspecialchars($att['file_name']) ?></span>
                    <span style="color: var(--neutral-gray-400, #98A2B3); font-size: 0.75rem;">(<?= round($att['file_size'] / 1024, 1) ?> KB)</span>
                  </a>
                <?php endforeach; ?>
              </div>
            </div>
          <?php endif; ?>

          <?php /* Timeline de Mensagens (NI2-202) */ ?>
          <h3 style="margin: 0 0 16px 0; font-size: 1.15rem; color: var(--botanical-forest-900, #0F3E17);">Histórico de Mensagens</h3>
          <div style="display: flex; flex-direction: column; gap: 16px; margin-bottom: 28px;">
            <?php foreach ($ticket['messages'] as $idx => $msg): ?>
              <?php 
                $isMe = ((int)$msg['user_id'] === $auth['userId']);
                $isOperator = \ClyoNutri\Core\Security\RoleModel::isPlatformSupport($msg['author_role'] ?? ''); // u.role (plataforma)
              ?>
              <div style="border-radius: 8px; padding: 16px; border: 1px solid <?= $isOperator ? 'var(--botanical-sage-300, #BFD8C2)' : 'var(--neutral-gray-200, #E5E7EB)' ?>; background: <?= $isOperator ? 'var(--botanical-sage-100, #E5F0E5)' : '#FFFFFF' ?>;">
                <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 8px;">
                  <div>
                    <strong style="color: <?= $isOperator ? 'var(--botanical-green-700, #2F6140)' : 'var(--botanical-forest-900, #0F3E17)' ?>; font-size: 0.95rem;">
                      <?= $isOperator ? 'Suporte Clyo Nutri (' . htmlspecialchars($msg['author_name']) . ')' : htmlspecialchars($msg['author_name']) ?>
                    </strong>
                    <span style="font-size: 0.75rem; color: var(--neutral-gray-500, #667085); margin-left: 8px;">
                      <?= date('d/m/Y H:i', strtotime($msg['created_at'])) ?>
                    </span>
                  </div>
                  <?php if ($idx === 0): ?>
                    <span style="font-size: 0.75rem; background: var(--neutral-gray-200, #E5E7EB); padding: 2px 6px; border-radius: 4px; color: var(--neutral-gray-600, #475467);">Abertura</span>
                  <?php endif; ?>
                </div>
                <div style="font-size: 0.9rem; color: var(--neutral-gray-700, #344054); line-height: 1.6; white-space: pre-wrap;">
                  <?= htmlspecialchars($msg['message']) ?>
                </div>
              </div>
            <?php endforeach; ?>
          </div>

          <!-- Formulário para Nova Resposta se não estiver fechado -->
          <?php if ($ticket['status'] !== HelpdeskTicketService::STATUS_CLOSED): ?>
            <div class="card">
              <h4 style="margin: 0 0 12px 0; font-size: 1rem; color: var(--botanical-forest-900, #0F3E17);">Adicionar resposta ao chamado</h4>
              <form action="/ajuda/chamado/<?= $ticket['protocol'] ?>/responder" method="POST" enctype="multipart/form-data">
                <input type="hidden" name="_csrf_token" value="<?= $csrfToken ?>">
                <div style="margin-bottom: 12px;">
                  <label for="reply-message" style="display: block; font-size: 0.85rem; font-weight: 600; color: var(--neutral-gray-700, #344054); margin-bottom: 6px;">Mensagem de Resposta *</label>
                  <textarea id="reply-message" name="message" required rows="4" placeholder="Escreva sua mensagem ou esclarecimento..." 
                            style="width: 100%; padding: 10px; border: 1px solid var(--border-input, #828B9B); border-radius: 6px; font-size: 0.9rem; box-sizing: border-box;"></textarea>
                </div>
                <div style="margin-bottom: 16px;">
                  <label for="reply-attachment" style="display: block; font-size: 0.8rem; font-weight: 600; color: var(--neutral-gray-500, #667085); margin-bottom: 4px;">
                    Anexar novo arquivo (PNG, JPG ou PDF - Máx 10MB)
                  </label>
                  <input type="file" id="reply-attachment" name="attachment" accept=".png,.jpg,.jpeg,.pdf" style="font-size: 0.85rem;">
                </div>
                <div style="display: flex; justify-content: flex-end;">
                  <button type="submit" class="btn btn-primary">
                    Enviar resposta
                  </button>
                </div>
              </form>
            </div>
          <?php endif; ?>
        </div>
        <?php
        $content = ob_get_clean();
        require __DIR__ . '/../../../views/layouts/professional-shell.php';
    }

    /**
     * Resposta do Cliente na Timeline (NI2-202)
     */
    public function replyTicket(array $params): void
    {
        $auth = $this->getAuthInfo();
        $protocol = (string)($params['protocol'] ?? '');

        if (!Csrf::validate($_POST['_csrf_token'] ?? '')) {
            header("Location: /ajuda/chamado/{$protocol}?error=csrf");
            exit;
        }

        try {
            $ticket = $this->ticketService->getTicketByProtocol($protocol, $auth['userId'], $auth['clinicId'], false);
            $messageText = (string)($_POST['message'] ?? '');

            $msg = $this->ticketService->addMessage((int)$ticket['id'], $auth['userId'], $messageText, false, false);

            if (!empty($_FILES['attachment']) && ($_FILES['attachment']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE) {
                $this->attachmentService->storeAttachment(
                    (int)$ticket['id'],
                    $auth['userId'],
                    $_FILES['attachment'],
                    (int)$msg['id']
                );
            }

            header("Location: /ajuda/chamado/{$protocol}#respostas");
            exit;
        } catch (\Throwable $e) {
            header("Location: /ajuda/chamado/{$protocol}?error=" . urlencode($e->getMessage()));
            exit;
        }
    }

    /**
     * Confirmação de Resolução pelo Cliente (NI2-203)
     */
    public function resolveTicket(array $params): void
    {
        $auth = $this->getAuthInfo();
        $protocol = (string)($params['protocol'] ?? '');

        if (!Csrf::validate($_POST['_csrf_token'] ?? '')) {
            header("Location: /ajuda/chamado/{$protocol}?error=csrf");
            exit;
        }

        try {
            $ticket = $this->ticketService->getTicketByProtocol($protocol, $auth['userId'], $auth['clinicId'], false);
            $this->ticketService->updateStatus((int)$ticket['id'], $auth['userId'], HelpdeskTicketService::STATUS_RESOLVED, 'Confirmado resolvido pelo cliente.', false);
            header("Location: /ajuda/chamado/{$protocol}?sucesso=resolvido");
            exit;
        } catch (\Throwable $e) {
            header("Location: /ajuda/chamado/{$protocol}?error=" . urlencode($e->getMessage()));
            exit;
        }
    }

    /**
     * Submissão de Feedback CSAT (NI2-207)
     */
    public function submitCsat(array $params): void
    {
        $auth = $this->getAuthInfo();
        $protocol = (string)($params['protocol'] ?? '');

        if (!Csrf::validate($_POST['_csrf_token'] ?? '')) {
            header("Location: /ajuda/chamado/{$protocol}?error=csrf");
            exit;
        }

        try {
            $ticket = $this->ticketService->getTicketByProtocol($protocol, $auth['userId'], $auth['clinicId'], false);
            $rating = (int)($_POST['rating'] ?? 5);
            $comment = !empty($_POST['comment']) ? (string)$_POST['comment'] : null;

            $this->ticketService->submitFeedback((int)$ticket['id'], $auth['userId'], $rating, $comment);
            header("Location: /ajuda/chamado/{$protocol}?sucesso=csat_enviado");
            exit;
        } catch (\Throwable $e) {
            header("Location: /ajuda/chamado/{$protocol}?error=" . urlencode($e->getMessage()));
            exit;
        }
    }

    /**
     * Download Seguro de Anexo (NI2-200)
     */
    public function downloadAttachment(array $params): void
    {
        $auth = $this->getAuthInfo();
        $attachmentId = (int)($params['id'] ?? 0);

        try {
            $attachment = $this->attachmentService->getAttachmentForDownload(
                $attachmentId,
                $auth['userId'],
                $auth['clinicId'],
                $auth['userRole']
            );

            $path = $attachment['stored_path'];
            if (!file_exists($path)) {
                http_response_code(404);
                echo "Arquivo não encontrado.";
                exit;
            }

            header('Content-Description: File Transfer');
            header('Content-Type: ' . $attachment['mime_type']);
            header('Content-Disposition: inline; filename="' . addslashes($attachment['file_name']) . '"');
            header('Content-Length: ' . filesize($path));
            header('Cache-Control: private, no-store');
            readfile($path);
            exit;
        } catch (\Throwable $e) {
            http_response_code(403);
            echo "Acesso negado ou anexo indisponível: " . htmlspecialchars($e->getMessage());
            exit;
        }
    }

    /**
     * Fila Operacional do Service Desk / Backoffice (NI2-205 & NI2-206)
     */
    public function backofficeQueue(): void
    {
        $auth = $this->getAuthInfo();
        if (!$auth['isSupport']) {
            header('Location: /ajuda');
            exit;
        }

        $statusFilter = !empty($_GET['status']) ? (string)$_GET['status'] : null;
        $priorityFilter = !empty($_GET['priority']) ? (string)$_GET['priority'] : null;

        $pagination = Pagination::build(
            $this->ticketService->countBackofficeQueue($statusFilter, $priorityFilter),
            $_GET['page'] ?? null,
            $_GET['per_page'] ?? null
        );
        $queue = $this->ticketService->listBackofficeQueue(
            $statusFilter,
            $priorityFilter,
            null,
            $pagination['per_page'],
            $pagination['offset']
        );
        $paginationBase = '/suporte/fila';
        $paginationQuery = array_filter(['status' => $statusFilter, 'priority' => $priorityFilter], static fn($v) => $v !== null && $v !== '');
        $paginationNoun = ['chamado', 'chamados'];
        $metrics = $this->ticketService->getOperationsMetrics();

        $title = 'Fila de atendimento do suporte';
        $activeNav = 'service_desk';
        $clinicName = $auth['clinicName'];
        $userName = $auth['userName'];

        ob_start();
        ?>
        <div style="max-width: 1200px; margin: 0 auto; padding-bottom: var(--space-8);">
          <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 24px;">
            <div>
              <span style="font-size: 0.8rem; font-weight: 700; color: var(--semantic-info, #175CD3); text-transform: uppercase;">BACKOFFICE DE OPERAÇÃO</span>
              <h1 style="margin: 4px 0 0 0; font-size: 1.6rem; color: var(--botanical-forest-900, #0F3E17);">Fila de atendimento do service desk</h1>
            </div>
            <a href="/ajuda" class="btn btn-secondary">
              Visão da central de ajuda →
            </a>
          </div>

          <?php /* Métricas e Indicadores Operacionais (NI2-206) */ ?>
          <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 16px; margin-bottom: 28px;">
            <div class="card" style="padding: 16px; border-left: 4px solid var(--botanical-green-600, #39734A);">
              <span style="font-size: 0.8rem; color: var(--neutral-gray-500, #667085); font-weight: 600;">TOTAL DE CHAMADOS</span>
              <div style="font-size: 1.7rem; font-weight: 700; color: var(--botanical-forest-900, #0F3E17); margin-top: 4px;"><?= $metrics['total_tickets'] ?></div>
            </div>

            <div class="card" style="padding: 16px; border-left: 4px solid var(--semantic-success, #0A7075);">
              <span style="font-size: 0.8rem; color: var(--neutral-gray-500, #667085); font-weight: 600;">TEMPO 1ª RESPOSTA (SLA)</span>
              <div style="font-size: 1.7rem; font-weight: 700; color: var(--botanical-forest-900, #0F3E17); margin-top: 4px;">
                <?= $metrics['avg_first_response_minutes'] > 0 ? $metrics['avg_first_response_minutes'] . ' min' : 'Imediato' ?>
              </div>
            </div>

            <div class="card" style="padding: 16px; border-left: 4px solid var(--semantic-warning, #946200);">
              <span style="font-size: 0.8rem; color: var(--neutral-gray-500, #667085); font-weight: 600;">CHAMADOS ENVELHECIDOS (>48H)</span>
              <div style="font-size: 1.7rem; font-weight: 700; color: <?= $metrics['aged_tickets_count'] > 0 ? 'var(--semantic-error, #B42318)' : 'var(--semantic-success, #0A7075)' ?>; margin-top: 4px;">
                <?= $metrics['aged_tickets_count'] ?>
              </div>
            </div>

            <div class="card" style="padding: 16px; border-left: 4px solid var(--semantic-info, #175CD3);">
              <span style="font-size: 0.8rem; color: var(--neutral-gray-500, #667085); font-weight: 600;">CSAT MÉDIO (1 A 5)</span>
              <div style="font-size: 1.7rem; font-weight: 700; color: var(--botanical-forest-900, #0F3E17); margin-top: 4px;">
                <?= $metrics['csat_average'] > 0 ? $metrics['csat_average'] . ' / 5.0 ' : 'Sem notas' ?>
              </div>
            </div>
          </div>

          <!-- Filtros de Prioridade e Status -->
          <div style="display: flex; gap: 8px; margin-bottom: 20px; flex-wrap: wrap;">
            <a href="/suporte/fila" class="btn btn-sm <?= ($statusFilter === null && $priorityFilter === null) ? 'btn-primary' : 'btn-secondary' ?>">
              Todos os Chamados
            </a>
            <a href="/suporte/fila?priority=P0" class="btn btn-sm <?= $priorityFilter === 'P0' ? 'btn-danger' : 'btn-secondary' ?>" style="color: var(--semantic-error, #B42318);">
              P0 - críticos
            </a>
            <a href="/suporte/fila?priority=P1" class="btn btn-sm <?= $priorityFilter === 'P1' ? 'btn-danger' : 'btn-secondary' ?>" style="color: var(--semantic-warning, #946200);">
              P1 - altos
            </a>
            <a href="/suporte/fila?status=open" class="btn btn-sm <?= $statusFilter === 'open' ? 'btn-primary' : 'btn-secondary' ?>">
              Abertos
            </a>
            <a href="/suporte/fila?status=in_progress" class="btn btn-sm <?= $statusFilter === 'in_progress' ? 'btn-primary' : 'btn-secondary' ?>">
              Em Atendimento
            </a>
            <a href="/suporte/fila?status=waiting_internal" class="btn btn-sm <?= $statusFilter === 'waiting_internal' ? 'btn-primary' : 'btn-secondary' ?>">
              Aguardando time interno
            </a>
          </div>

          <!-- Tabela de Chamados na Fila -->
          <?php if (empty($queue)): ?>
            <div class="card" style="text-align: center; padding: 48px; color: var(--neutral-gray-500, #667085);">
              <p>Nenhum chamado pendente na fila operacional com estes filtros.</p>
            </div>
          <?php else: ?>
            <div class="table-responsive">
              <table class="table">
                <thead>
                  <tr>
                    <th>Prioridade</th>
                    <th>Protocolo</th>
                    <th>Clínica / solicitante</th>
                    <th>Assunto</th>
                    <th>Categoria</th>
                    <th>Status</th>
                    <th>Operador</th>
                    <th style="text-align: right;">Ação</th>
                  </tr>
                </thead>
                <tbody>
                  <?php foreach ($queue as $item): ?>
                    <tr>
                      <td>
                        <?php 
                          $pBg = 'var(--neutral-gray-100, #F4F5F6)'; $pCol = 'var(--neutral-gray-700, #344054)';
                          if ($item['priority_operational'] === 'P0') { $pBg = 'var(--semantic-error-light, #FEE2E2)'; $pCol = 'var(--semantic-error, #B42318)'; }
                          elseif ($item['priority_operational'] === 'P1') { $pBg = '#FFEDD5'; $pCol = 'var(--semantic-warning, #946200)'; }
                        ?>
                        <span class="badge" style="background: <?= $pBg ?>; color: <?= $pCol ?>; font-weight: 700;">
                          <?= $item['priority_operational'] ?>
                        </span>
                      </td>
                      <td style="font-family: monospace; font-weight: 700;">
                        <a href="/suporte/chamado/<?= $item['id'] ?>" style="color: var(--botanical-green-600, #39734A); text-decoration: none;">
                          <?= htmlspecialchars($item['protocol']) ?>
                        </a>
                      </td>
                      <td>
                        <strong style="color: var(--botanical-forest-900, #0F3E17); display: block; font-size: 0.9rem;"><?= htmlspecialchars($item['clinic_name']) ?></strong>
                        <span style="font-size: 0.75rem; color: var(--neutral-gray-500, #667085);"><?= htmlspecialchars($item['author_name']) ?></span>
                      </td>
                      <td style="font-weight: 600; font-size: 0.9rem;">
                        <?= htmlspecialchars($item['subject']) ?>
                      </td>
                      <td style="font-size: 0.85rem; color: var(--neutral-gray-500, #667085);">
                        <?= htmlspecialchars($item['category_label']) ?>
                      </td>
                      <td>
                        <span class="badge" style="background: var(--neutral-gray-200, #E5E7EB); color: var(--botanical-forest-900, #0F3E17); font-size: 0.75rem;">
                          <?= htmlspecialchars($item['status_label']) ?>
                        </span>
                      </td>
                      <td style="font-size: 0.85rem; color: var(--neutral-gray-500, #667085);">
                        <?= $item['assigned_operator_name'] ? htmlspecialchars($item['assigned_operator_name']) : '<em>Não atribuído</em>' ?>
                      </td>
                      <td style="text-align: right;">
                        <a href="/suporte/chamado/<?= $item['id'] ?>" class="btn btn-sm btn-primary">
                          Atender →
                        </a>
                      </td>
                    </tr>
                  <?php endforeach; ?>
                </tbody>
              </table>
            </div>
            <?php require __DIR__ . '/../../../views/admin/_pagination.php'; ?>
          <?php endif; ?>
        </div>
        <?php
        $content = ob_get_clean();
        require __DIR__ . '/../../../views/layouts/professional-shell.php';
    }

    /**
     * Tela de Atendimento do Operador / Backoffice (NI2-205)
     */
    public function backofficeTicket(array $params): void
    {
        $auth = $this->getAuthInfo();
        if (!$auth['isSupport']) {
            header('Location: /ajuda');
            exit;
        }

        $ticketId = (int)($params['id'] ?? 0);

        try {
            $ticket = $this->ticketService->getTicketById($ticketId, $auth['userId'], $auth['clinicId'], true);
        } catch (\Throwable $e) {
            header('Location: /suporte/fila');
            exit;
        }

        $csrfToken = Csrf::token();
        $title = "Atendimento {$ticket['protocol']} — Backoffice";
        $activeNav = 'service_desk';
        $clinicName = $auth['clinicName'];
        $userName = $auth['userName'];

        ob_start();
        ?>
        <div style="max-width: 1040px; margin: 0 auto; padding-bottom: var(--space-8);">
          <div style="margin-bottom: 20px;">
            <a href="/suporte/fila" style="font-size: 0.85rem; color: var(--neutral-gray-500, #667085); text-decoration: none;">← Voltar para fila de atendimento</a>
          </div>

          <!-- Card de Controle e Metadados do Chamado -->
          <div class="card" style="margin-bottom: 24px;">
            <div style="display: flex; justify-content: space-between; align-items: flex-start; flex-wrap: wrap; gap: 16px; margin-bottom: 16px;">
              <div>
                <span style="font-family: monospace; font-size: 1.25rem; font-weight: 700; color: var(--botanical-green-600, #39734A);">
                  <?= htmlspecialchars($ticket['protocol']) ?>
                </span>
                <h1 style="margin: 4px 0 6px 0; font-size: 1.45rem; color: var(--botanical-forest-900, #0F3E17);">
                  <?= htmlspecialchars($ticket['subject']) ?>
                </h1>
                <div style="font-size: 0.85rem; color: var(--neutral-gray-500, #667085);">
                  Solicitante: <strong><?= htmlspecialchars($ticket['author_name']) ?></strong> (<?= htmlspecialchars($ticket['author_email']) ?>) • Clínica: <strong><?= htmlspecialchars($ticket['clinic_name']) ?></strong>
                </div>
              </div>

              <!-- Formulário de Transição de Status e Prioridade -->
              <div style="display: flex; gap: 10px; align-items: center; flex-wrap: wrap;">
                <form action="/suporte/chamado/<?= $ticket['id'] ?>/status" method="POST" style="display: flex; gap: 6px;">
                  <input type="hidden" name="_csrf_token" value="<?= $csrfToken ?>">
                  <label for="ticket-admin-status" class="sr-only">Status do Chamado</label>
                  <select id="ticket-admin-status" name="status" style="padding: 6px 10px; border: 1px solid var(--border-input, #828B9B); border-radius: 6px; font-size: 0.85rem;">
                    <?php foreach (HelpdeskTicketService::STATUS_LABELS as $sKey => $sLabel): ?>
                      <option value="<?= $sKey ?>" <?= $ticket['status'] === $sKey ? 'selected' : '' ?>>
                        <?= $sLabel ?>
                      </option>
                    <?php endforeach; ?>
                  </select>
                  <button type="submit" class="btn btn-sm btn-secondary">Atualizar status</button>
                </form>
              </div>
            </div>
          </div>

          <?php /* Timeline Completa com Notas Internas Destacadas (NI2-205) */ ?>
          <h3 style="margin: 0 0 16px 0; font-size: 1.15rem; color: var(--botanical-forest-900, #0F3E17);">Timeline completa do chamado</h3>
          <div style="display: flex; flex-direction: column; gap: 14px; margin-bottom: 28px;">
            <?php foreach ($ticket['messages'] as $msg): ?>
              <?php 
                $isInternal = ((int)$msg['is_internal_note'] === 1);
                $isAuthor = ((int)$msg['user_id'] === (int)$ticket['user_id']);
              ?>
              <div style="border-radius: 8px; padding: 16px; border: 1px solid <?= $isInternal ? 'var(--semantic-warning-border, #FEDF89)' : ($isAuthor ? 'var(--neutral-gray-200, #E5E7EB)' : 'var(--botanical-sage-300, #BFD8C2)') ?>; background: <?= $isInternal ? 'var(--semantic-warning-light, #FEF3C7)' : ($isAuthor ? '#FFFFFF' : 'var(--botanical-sage-100, #E5F0E5)') ?>;">
                <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 8px;">
                  <div>
                    <?php if ($isInternal): ?>
                      <span class="badge" style="background: var(--semantic-warning, #946200); color: #fff; font-size: 0.75rem; margin-right: 6px;">
                        NOTA INTERNA (OCULTA DO CLIENTE)
                      </span>
                    <?php endif; ?>
                    <strong style="color: var(--botanical-forest-900, #0F3E17); font-size: 0.95rem;">
                      <?= htmlspecialchars($msg['author_name']) ?> (<?= htmlspecialchars($msg['author_role']) ?>)
                    </strong>
                    <span style="font-size: 0.75rem; color: var(--neutral-gray-500, #667085); margin-left: 8px;">
                      <?= date('d/m/Y H:i', strtotime($msg['created_at'])) ?>
                    </span>
                  </div>
                </div>
                <div style="font-size: 0.9rem; color: var(--neutral-gray-700, #344054); line-height: 1.6; white-space: pre-wrap;">
                  <?= htmlspecialchars($msg['message']) ?>
                </div>
              </div>
            <?php endforeach; ?>
          </div>

          <!-- Box de Resposta do Backoffice -->
          <div class="card">
            <h4 style="margin: 0 0 12px 0; font-size: 1rem; color: var(--botanical-forest-900, #0F3E17);">Responder ou adicionar nota interna</h4>
            <form action="/suporte/chamado/<?= $ticket['id'] ?>/responder" method="POST">
              <input type="hidden" name="_csrf_token" value="<?= $csrfToken ?>">

              <div style="margin-bottom: 12px;">
                <textarea name="message" required rows="5" placeholder="Escreva a resposta para o cliente ou as anotações técnicas internas de triagem..." 
                          style="width: 100%; padding: 10px; border: 1px solid var(--border-input, #828B9B); border-radius: 6px; font-size: 0.9rem; box-sizing: border-box;"></textarea>
              </div>

              <div style="display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 12px;">
                <label style="display: flex; align-items: center; gap: 8px; font-size: 0.9rem; font-weight: 600; color: var(--semantic-warning, #946200); cursor: pointer; background: var(--semantic-warning-light, #FEF3C7); padding: 6px 12px; border-radius: 6px;">
                  <input type="checkbox" name="is_internal_note" value="1">
                  <span>Registrar como nota interna (não envia notificação nem exibe ao cliente)</span>
                </label>

                <button type="submit" class="btn btn-primary">
                  Enviar mensagem
                </button>
              </div>
            </form>
          </div>
        </div>
        <?php
        $content = ob_get_clean();
        require __DIR__ . '/../../../views/layouts/professional-shell.php';
    }

    /**
     * Resposta do Backoffice (Mensagem pública ou Nota Interna - NI2-205)
     */
    public function backofficeReply(array $params): void
    {
        $auth = $this->getAuthInfo();
        if (!$auth['isSupport']) {
            header('Location: /ajuda');
            exit;
        }

        $ticketId = (int)($params['id'] ?? 0);

        if (!Csrf::validate($_POST['_csrf_token'] ?? '')) {
            header("Location: /suporte/chamado/{$ticketId}?error=csrf");
            exit;
        }

        try {
            $isInternal = (!empty($_POST['is_internal_note']) && $_POST['is_internal_note'] === '1');
            $messageText = (string)($_POST['message'] ?? '');

            $this->ticketService->addMessage($ticketId, $auth['userId'], $messageText, $isInternal, true);

            header("Location: /suporte/chamado/{$ticketId}#timeline");
            exit;
        } catch (\Throwable $e) {
            header("Location: /suporte/chamado/{$ticketId}?error=" . urlencode($e->getMessage()));
            exit;
        }
    }

    /**
     * Atualização de Status pelo Backoffice (NI2-205)
     */
    public function backofficeUpdateStatus(array $params): void
    {
        $auth = $this->getAuthInfo();
        if (!$auth['isSupport']) {
            header('Location: /ajuda');
            exit;
        }

        $ticketId = (int)($params['id'] ?? 0);

        if (!Csrf::validate($_POST['_csrf_token'] ?? '')) {
            header("Location: /suporte/chamado/{$ticketId}?error=csrf");
            exit;
        }

        try {
            $newStatus = (string)($_POST['status'] ?? '');
            $this->ticketService->updateStatus($ticketId, $auth['userId'], $newStatus, null, true);
            header("Location: /suporte/chamado/{$ticketId}?sucesso=status_atualizado");
            exit;
        } catch (\Throwable $e) {
            header("Location: /suporte/chamado/{$ticketId}?error=" . urlencode($e->getMessage()));
            exit;
        }
    }

    /**
     * Renderizador seguro de Markdown para artigos da Central de Ajuda.
     */
    private function renderMarkdown(string $text): string
    {
        $lines = explode("\n", $text);
        $html = '';
        $inList = false;

        foreach ($lines as $line) {
            $trimmed = trim($line);

            if ($trimmed === '') {
                if ($inList) {
                    $html .= "</ul>\n";
                    $inList = false;
                }
                continue;
            }

            // Headings
            if (str_starts_with($trimmed, '### ')) {
                if ($inList) { $html .= "</ul>\n"; $inList = false; }
                $html .= "<h3 style='margin: 18px 0 8px 0; color: var(--botanical-forest-900, #0F3E17);'>" . htmlspecialchars(substr($trimmed, 4)) . "</h3>\n";
                continue;
            }
            if (str_starts_with($trimmed, '#### ')) {
                if ($inList) { $html .= "</ul>\n"; $inList = false; }
                $html .= "<h4 style='margin: 14px 0 6px 0; color: var(--neutral-ink-900, #1F2937);'>" . htmlspecialchars(substr($trimmed, 5)) . "</h4>\n";
                continue;
            }

            // Unordered list
            if (str_starts_with($trimmed, '- ') || str_starts_with($trimmed, '* ')) {
                if (!$inList) {
                    $html .= "<ul style='margin: 8px 0; padding-left: 20px;'>\n";
                    $inList = true;
                }
                $itemText = substr($trimmed, 2);
                $html .= "<li>" . $this->formatInlineMarkdown($itemText) . "</li>\n";
                continue;
            }

            // Ordered list
            if (preg_match('/^\d+\.\s+(.*)$/', $trimmed, $m)) {
                if (!$inList) {
                    $html .= "<ol style='margin: 8px 0; padding-left: 20px;'>\n";
                    $inList = true;
                }
                $html .= "<li>" . $this->formatInlineMarkdown($m[1]) . "</li>\n";
                continue;
            }

            if ($inList) {
                $html .= "</ul>\n";
                $inList = false;
            }

            $html .= "<p style='margin: 8px 0;'>" . $this->formatInlineMarkdown($trimmed) . "</p>\n";
        }

        if ($inList) {
            $html .= "</ul>\n";
        }

        return $html;
    }

    /** SVGs locais de uma lista fechada; nenhuma marcação vem do banco ou do cliente. */
    private static function categoryIcon(string $name): string
    {
        $icons = [
            'key' => '<circle cx="8" cy="8" r="5"/><path d="m11.5 11.5 9 9M16 16l3-3m-1 5 3-3"/>',
            'user-check' => '<circle cx="9" cy="7" r="4"/><path d="M2 21v-2a7 7 0 0 1 14 0v2m0-11 2 2 4-4"/>',
            'users' => '<circle cx="9" cy="7" r="4"/><path d="M2 21v-2a7 7 0 0 1 14 0v2m1-18a4 4 0 0 1 0 8m3 10v-2a7 7 0 0 0-3-5.7"/>',
            'mail' => '<rect x="3" y="5" width="18" height="14" rx="2"/><path d="m3 7 9 6 9-6"/>',
            'clipboard' => '<rect x="5" y="4" width="14" height="17" rx="2"/><rect x="9" y="2" width="6" height="4" rx="1"/><path d="M9 11h6m-6 5h6"/>',
            'activity' => '<path d="M2 12h4l3-8 6 16 3-8h4"/>',
            'sliders' => '<path d="M4 3v6m0 4v8m8-18v12m0 4v2m8-18v2m0 4v12M1 9h6m2 6h6m2-10h6"/>',
            'book-open' => '<path d="M12 5v16M3 3h4a5 5 0 0 1 5 2 5 5 0 0 1 5-2h4v16h-4a5 5 0 0 0-5 2 5 5 0 0 0-5-2H3z"/>',
            'file-text' => '<path d="M14 2H5a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2V9zM14 2v7h7M7 13h10M7 17h7"/>',
            'coffee' => '<path d="M3 8h14v8a4 4 0 0 1-4 4H7a4 4 0 0 1-4-4zm14 1h2a3 3 0 1 1 0 6h-2M6 2v3m4-3v3m4-3v3M2 22h18"/>',
            'calendar' => '<rect x="3" y="5" width="18" height="16" rx="2"/><path d="M16 3v4M8 3v4M3 11h18M8 15h2m4 0h2m-8 3h2"/>',
            'trending-up' => '<path d="m3 17 6-6 4 4 8-10m-6 0h6v6"/>',
            'smartphone' => '<rect x="6" y="2" width="12" height="20" rx="2"/><path d="M10 5h4M11 19h2"/>',
            'credit-card' => '<rect x="2" y="4" width="20" height="16" rx="2"/><path d="M2 9h20M6 15h4m4 0h2"/>',
            'settings' => '<circle cx="12" cy="12" r="3"/><path d="m9 3 1-1h4l1 3 3 1 3-1 2 4-2 2v3l2 2-2 4-3-1-3 1-1 3h-4l-1-3-3-1-3 1-2-4 2-2v-3L1 9l2-4 3 1 3-1z" transform="translate(1 1) scale(.9)"/>',
            'shield' => '<path d="m12 2 9 4v6c0 5-9 10-9 10S3 17 3 12V6zM8 12l3 3 5-6"/>',
            'message-square' => '<path d="M21 15a3 3 0 0 1-3 3H8l-5 4V5a3 3 0 0 1 3-3h12a3 3 0 0 1 3 3zM7 8h10M7 12h7"/>',
        ];

        return '<svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false">'
            . ($icons[$name] ?? $icons['book-open']) . '</svg>';
    }

    private function formatInlineMarkdown(string $text): string
    {
        // Bold: **text**
        $text = preg_replace('/\*\*(.*?)\*\*/', '<strong>$1</strong>', $text);
        // Italic: *text*
        $text = preg_replace('/(?<!\*)\*(?!\*)(.*?)\*/', '<em>$1</em>', $text);
        // Inline code: `code`
        $text = preg_replace('/`(.*?)`/', '<code style="background: var(--neutral-gray-100, #F4F5F6); padding: 2px 4px; border-radius: 4px; font-size: 0.85em;">$1</code>', $text);

        return $text;
    }
}
