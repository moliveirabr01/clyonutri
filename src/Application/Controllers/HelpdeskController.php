<?php

declare(strict_types=1);

namespace ClyoNutri\Application\Controllers;

use ClyoNutri\Core\Auth\TenantContext;
use ClyoNutri\Core\Database\Connection;
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

        $title = 'Central de Ajuda';
        $activeNav = 'ajuda';
        $clinicName = $auth['clinicName'];
        $userName = $auth['userName'];

        ob_start();
        ?>
        <div style="max-width: 1040px; margin: 0 auto; padding-bottom: var(--space-8);">
          <!-- Header Hero da Central de Ajuda -->
          <div style="background: linear-gradient(135deg, #0F3E17 0%, #1B5226 100%); color: #fff; border-radius: 12px; padding: 36px 32px; margin-bottom: 28px; box-shadow: 0 4px 14px rgba(0,0,0,0.08);">
            <div style="max-width: 680px;">
              <span style="display: inline-block; background: rgba(191, 216, 194, 0.2); color: #D3E5D5; font-size: 0.8rem; font-weight: 600; padding: 4px 10px; border-radius: 20px; margin-bottom: 12px;">
                CENTRAL DE AJUDA & SERVICE DESK CLYONUTRI II
              </span>
              <h1 style="color: #fff; font-size: 1.85rem; font-family: 'Playfair Display', serif; margin: 0 0 10px 0;">Como podemos ajudar você hoje?</h1>
              <p style="color: #94A3B8; font-size: 0.95rem; margin: 0 0 20px 0;">
                Encontre guias passo a passo, tutoriais de nutrição clínica, tabela TACO, prescrições e suporte técnico direto com nosso time.
              </p>

              <!-- Formulário de Busca -->
              <form action="/ajuda" method="GET" style="display: flex; gap: 8px;">
                <div style="position: relative; flex: 1;">
                  <input type="text" name="q" value="<?= htmlspecialchars($query) ?>" placeholder="Buscar por anamnese, tabela TACO, Pollock, convite..." 
                         style="width: 100%; padding: 12px 16px; border-radius: 8px; border: 1px solid #334155; background: #fff; color: #0F3E17; font-size: 0.95rem; box-sizing: border-box;" required>
                </div>
                <button type="submit" class="btn btn-primary" style="padding: 0 22px; font-weight: 600;">
                  Buscar
                </button>
              </form>
            </div>
          </div>

          <!-- Ações Rápidas -->
          <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 24px; flex-wrap: wrap; gap: 12px;">
            <div style="display: flex; gap: 10px;">
              <a href="/ajuda/abrir-chamado" class="btn btn-primary" style="display: inline-flex; align-items: center; gap: 6px;">
                <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg>
                <span>Abrir Novo Chamado</span>
              </a>
              <a href="/ajuda/meus-chamados" class="btn btn-secondary" style="display: inline-flex; align-items: center; gap: 6px;">
                <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M4 4h16c1.1 0 2 .9 2 2v12c0 1.1-.9 2-2 2H4c-1.1 0-2-.9-2-2V6c0-1.1.9-2 2-2z"/><polyline points="22,6 12,13 2,6"/></svg>
                <span>Meus Chamados (<?= $myTicketsCount ?> ativos)</span>
              </a>
            </div>

            <?php if ($auth['isSupport']): ?>
              <a href="/suporte/fila" class="btn btn-outline" style="border-color: #8B5CF6; color: #7C3AED; font-weight: 600;">
                🛡️ Fila do Service Desk (Operador)
              </a>
            <?php endif; ?>
          </div>

          <?php if ($query !== ''): ?>
            <!-- Resultados da Busca -->
            <div class="card" style="margin-bottom: 28px;">
              <div class="card__header" style="display: flex; justify-content: space-between; align-items: center;">
                <h3 style="margin: 0;">Resultados para "<?= htmlspecialchars($query) ?>" (<?= count($searchResults) ?>)</h3>
                <a href="/ajuda" style="font-size: 0.85rem; color: #64748B;">Limpar busca</a>
              </div>
              <?php if (empty($searchResults)): ?>
                <div style="padding: 24px; text-align: center; color: #64748B;">
                  <p>Nenhum artigo encontrado para o termo pesquisado.</p>
                  <a href="/ajuda/abrir-chamado" class="btn btn-primary" style="margin-top: 10px;">
                    Abrir Chamado com a Equipe
                  </a>
                </div>
              <?php else: ?>
                <div style="display: flex; flex-direction: column; gap: 12px;">
                  <?php foreach ($searchResults as $art): ?>
                    <a href="/ajuda/artigo/<?= $art['slug'] ?>" style="text-decoration: none; color: inherit; display: block; padding: 14px 18px; border-radius: 8px; border: 1px solid #E2E8F0; background: #F8FAFC; transition: all 0.2s;" data-hover="borderColor:#94A3B8" data-unhover="borderColor:#E2E8F0">
                      <strong style="color: #0F3E17; font-size: 1rem; display: block; margin-bottom: 4px;"><?= htmlspecialchars($art['title']) ?></strong>
                      <p style="color: #475569; font-size: 0.85rem; margin: 0;"><?= htmlspecialchars($art['summary']) ?></p>
                      <span style="font-size: 0.75rem; color: #94A3B8; margin-top: 6px; display: inline-block;">👁️ <?= $art['view_count'] ?> visualizações</span>
                    </a>
                  <?php endforeach; ?>
                </div>
              <?php endif; ?>
            </div>
          <?php endif; ?>

          <!-- Seção de Categorias -->
          <h2 style="font-size: 1.25rem; font-weight: 700; color: #0F3E17; margin: 0 0 16px 0;">Categorias de Ajuda</h2>
          <div style="display: grid; grid-template-columns: repeat(auto-fill, minmax(300px, 1fr)); gap: 16px; margin-bottom: 32px;">
            <?php foreach ($categories as $cat): ?>
              <a href="/ajuda/categoria/<?= $cat['slug'] ?>" style="text-decoration: none; color: inherit;" class="card" data-hover="transform:translateY(-2px)" data-unhover="transform:none">
                <div style="display: flex; align-items: flex-start; gap: 12px;">
                  <div style="background: #E5F0E5; color: #39734A; width: 38px; height: 38px; border-radius: 8px; display: flex; align-items: center; justify-content: center; font-size: 1.2rem; flex-shrink: 0;">
                    📘
                  </div>
                  <div>
                    <h4 style="margin: 0 0 4px 0; color: #0F3E17; font-size: 0.95rem; font-weight: 600;"><?= htmlspecialchars($cat['name']) ?></h4>
                    <p style="margin: 0 0 8px 0; font-size: 0.8rem; color: #64748B;"><?= htmlspecialchars($cat['description']) ?></p>
                    <span style="font-size: 0.75rem; font-weight: 600; color: #39734A;"><?= $cat['count'] ?> <?= $cat['count'] === 1 ? 'artigo' : 'artigos' ?> ➔</span>
                  </div>
                </div>
              </a>
            <?php endforeach; ?>
          </div>

          <!-- FAQs em Destaque -->
          <div class="card">
            <div class="card__header">
              <h3 style="margin: 0;">Perguntas Frequentes & Artigos Mais Acessados</h3>
            </div>
            <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(280px, 1fr)); gap: 14px;">
              <?php foreach ($featuredFaqs as $faq): ?>
                <a href="/ajuda/artigo/<?= $faq['slug'] ?>" style="text-decoration: none; color: inherit; padding: 12px; border-radius: 8px; border: 1px solid #E2E8F0; background: #fff; display: block;" data-hover="borderColor:#39734A" data-unhover="borderColor:#E2E8F0">
                  <div style="font-size: 0.75rem; font-weight: 700; color: #64748B; text-transform: uppercase; margin-bottom: 4px;">
                    <?= htmlspecialchars(KnowledgeBaseService::CATEGORIES[$faq['category']]['name'] ?? $faq['category']) ?>
                  </div>
                  <strong style="color: #0F3E17; font-size: 0.9rem; display: block; margin-bottom: 4px;"><?= htmlspecialchars($faq['title']) ?></strong>
                  <p style="color: #64748B; font-size: 0.8rem; margin: 0;"><?= htmlspecialchars($faq['summary']) ?></p>
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
            <a href="/ajuda" style="font-size: 0.85rem; color: #64748B; text-decoration: none;">← Voltar para Central de Ajuda</a>
          </div>

          <div class="card" style="margin-bottom: 24px;">
            <div style="display: flex; align-items: center; gap: 12px; margin-bottom: 8px;">
              <span style="font-size: 1.5rem;">📘</span>
              <h1 style="margin: 0; font-size: 1.5rem; color: #0F3E17;"><?= htmlspecialchars($catMeta['name']) ?></h1>
            </div>
            <p style="margin: 0; color: #64748B; font-size: 0.95rem;"><?= htmlspecialchars($catMeta['description']) ?></p>
          </div>

          <div style="display: flex; flex-direction: column; gap: 12px;">
            <?php if (empty($articles)): ?>
              <div class="card" style="text-align: center; color: #64748B; padding: 32px;">
                <p>Nenhum artigo publicado nesta categoria ainda.</p>
                <a href="/ajuda/abrir-chamado" class="btn btn-primary" style="margin-top: 10px;">Abrir Chamado</a>
              </div>
            <?php else: ?>
              <?php foreach ($articles as $art): ?>
                <a href="/ajuda/artigo/<?= $art['slug'] ?>" class="card" style="text-decoration: none; color: inherit; transition: border-color 0.2s;" data-hover="borderColor:#39734A" data-unhover="borderColor:#E2E8F0">
                  <div style="display: flex; justify-content: space-between; align-items: baseline;">
                    <h3 style="margin: 0 0 6px 0; color: #0F3E17; font-size: 1.05rem;"><?= htmlspecialchars($art['title']) ?></h3>
                    <span style="font-size: 0.75rem; color: #94A3B8;">👁️ <?= $art['view_count'] ?></span>
                  </div>
                  <p style="margin: 0; color: #475569; font-size: 0.85rem;"><?= htmlspecialchars($art['summary']) ?></p>
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
            <a href="/ajuda/categoria/<?= $article['category'] ?>" style="font-size: 0.85rem; color: #64748B; text-decoration: none;">
              ← Voltar para <?= htmlspecialchars($article['category_meta']['name']) ?>
            </a>
          </div>

          <div class="card" style="margin-bottom: 24px;">
            <div style="border-bottom: 1px solid #E2E8F0; padding-bottom: 16px; margin-bottom: 20px;">
              <span style="font-size: 0.8rem; font-weight: 700; color: #39734A; text-transform: uppercase;">
                <?= htmlspecialchars($article['category_meta']['name']) ?>
              </span>
              <h1 style="margin: 8px 0; font-size: 1.6rem; color: #0F3E17; font-family: 'Playfair Display', serif;">
                <?= htmlspecialchars($article['title']) ?>
              </h1>
              <div style="font-size: 0.8rem; color: #64748B; display: flex; gap: 16px;">
                <span>Atualizado em <?= date('d/m/Y', strtotime($article['updated_at'])) ?></span>
                <span>•</span>
                <span>👁️ <?= $article['view_count'] ?> visualizações</span>
              </div>
            </div>

            <div class="article-content" style="font-size: 0.95rem; line-height: 1.7; color: #334155;">
              <?= $markdownHtml ?>
            </div>
          </div>

          <!-- Box de Ajuda Adicional -->
          <div class="card" style="background: #F8FAFC; border-color: #CBD5E1; display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 16px;">
            <div>
              <strong style="color: #0F3E17; font-size: 1rem; display: block;">Este artigo resolveu sua dúvida?</strong>
              <p style="margin: 0; color: #64748B; font-size: 0.85rem;">Se ainda precisar de suporte especializado, nosso time está pronto para atender.</p>
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

        $title = 'Abrir Chamado — Suporte ClyoNutri II';
        $activeNav = 'ajuda';
        $clinicName = $auth['clinicName'];
        $userName = $auth['userName'];

        ob_start();
        ?>
        <div style="max-width: 780px; margin: 0 auto; padding-bottom: var(--space-8);">
          <div style="margin-bottom: 20px;">
            <a href="/ajuda" style="font-size: 0.85rem; color: #64748B; text-decoration: none;">← Voltar para Central de Ajuda</a>
          </div>

          <div class="card">
            <div class="card__header" style="border-bottom: 1px solid #E2E8F0; padding-bottom: 16px; margin-bottom: 20px;">
              <span style="font-size: 0.8rem; font-weight: 700; color: #39734A; text-transform: uppercase;">SERVICE DESK CLYONUTRI II</span>
              <h1 style="margin: 4px 0 0 0; font-size: 1.5rem; color: #0F3E17;">Abrir Novo Chamado de Suporte</h1>
              <p style="margin: 4px 0 0 0; color: #64748B; font-size: 0.85rem;">
                Descreva sua dúvida, sugestão ou incidente técnico. Um protocolo exclusivo será gerado imediatamente.
              </p>
            </div>

            <form action="/ajuda/abrir-chamado" method="POST" enctype="multipart/form-data" id="form-ticket">
              <input type="hidden" name="_csrf_token" value="<?= $csrfToken ?>">

              <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 16px; margin-bottom: 16px;">
                <div>
                  <label style="display: block; font-weight: 600; font-size: 0.85rem; color: #334155; margin-bottom: 6px;">
                    Categoria do ClyoNutri *
                  </label>
                  <select name="category" required style="width: 100%; padding: 10px; border: 1px solid #CBD5E1; border-radius: 6px; font-size: 0.9rem;">
                    <?php foreach (KnowledgeBaseService::CATEGORIES as $key => $cat): ?>
                      <option value="<?= $key ?>" <?= $selectedCat === $key ? 'selected' : '' ?>>
                        <?= htmlspecialchars($cat['name']) ?>
                      </option>
                    <?php endforeach; ?>
                  </select>
                </div>

                <div>
                  <label style="display: block; font-weight: 600; font-size: 0.85rem; color: #334155; margin-bottom: 6px;">
                    Tipo de Chamado *
                  </label>
                  <select name="type" required style="width: 100%; padding: 10px; border: 1px solid #CBD5E1; border-radius: 6px; font-size: 0.9rem;">
                    <?php foreach (HelpdeskTicketService::TYPES as $key => $label): ?>
                      <option value="<?= $key ?>"><?= htmlspecialchars($label) ?></option>
                    <?php endforeach; ?>
                  </select>
                </div>
              </div>

              <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 16px; margin-bottom: 16px;">
                <div>
                  <label style="display: block; font-weight: 600; font-size: 0.85rem; color: #334155; margin-bottom: 6px;">
                    Impacto / Prioridade Percebida
                  </label>
                  <select name="priority_perceived" style="width: 100%; padding: 10px; border: 1px solid #CBD5E1; border-radius: 6px; font-size: 0.9rem;">
                    <option value="low">Baixa (Dúvida pontual ou melhoria futura)</option>
                    <option value="medium" selected>Média (Impacto parcial, consigo contornar)</option>
                    <option value="high">Alta (Impacto no fluxo de atendimento clínico)</option>
                    <option value="urgent">Urgente (Bloqueio total / Consulta em andamento)</option>
                  </select>
                </div>

                <div>
                  <label style="display: block; font-weight: 600; font-size: 0.85rem; color: #334155; margin-bottom: 6px;">
                    Tela ou Módulo de Origem
                  </label>
                  <input type="text" name="origin_route" placeholder="Ex: /planos, /antropometria, /teleconsulta" 
                         style="width: 100%; padding: 10px; border: 1px solid #CBD5E1; border-radius: 6px; font-size: 0.9rem; box-sizing: border-box;">
                </div>
              </div>

              <div style="margin-bottom: 16px;">
                <label style="display: block; font-weight: 600; font-size: 0.85rem; color: #334155; margin-bottom: 6px;">
                  Assunto Resumido *
                </label>
                <input type="text" name="subject" required minlength="5" placeholder="Ex: Erro ao salvar plano alimentar com alimentos TACO personalizados" 
                       style="width: 100%; padding: 10px; border: 1px solid #CBD5E1; border-radius: 6px; font-size: 0.9rem; box-sizing: border-box;">
              </div>

              <div style="margin-bottom: 16px;">
                <label style="display: block; font-weight: 600; font-size: 0.85rem; color: #334155; margin-bottom: 6px;">
                  Descrição Detalhada do Problema *
                </label>
                <textarea name="description" required minlength="10" rows="6" placeholder="Descreva os passos que realizou, mensagens de erro apresentadas ou comportamento esperado..." 
                          style="width: 100%; padding: 10px; border: 1px solid #CBD5E1; border-radius: 6px; font-size: 0.9rem; box-sizing: border-box;"></textarea>
                <span style="font-size: 0.75rem; color: #64748B;">
                  ⚠️ Por conformidade com a LGPD e sigilo profissional CFN, <strong>não insira dados de identificação pessoal de pacientes</strong> (como CPF ou prontuários completos).
                </span>
              </div>

              <!-- Upload de Anexo Seguro (NI2-200) -->
              <div style="margin-bottom: 24px; padding: 16px; background: #F8FAFC; border: 1px dashed #CBD5E1; border-radius: 8px;">
                <label style="display: block; font-weight: 600; font-size: 0.85rem; color: #334155; margin-bottom: 6px;">
                  Anexo Opcional (Captura de tela ou documento de evidência)
                </label>
                <input type="file" name="attachment" accept=".png,.jpg,.jpeg,.pdf" style="font-size: 0.85rem; color: #475569;">
                <div style="font-size: 0.75rem; color: #64748B; margin-top: 6px;">
                  Formatos aceitos: <strong>PNG, JPG e PDF</strong> (Tamanho máximo: 10 MB). Os arquivos são armazenados com criptografia e fora do diretório público.
                </div>
              </div>

              <div style="display: flex; justify-content: flex-end; gap: 12px;">
                <a href="/ajuda" class="btn btn-secondary">Cancelar</a>
                <button type="submit" class="btn btn-primary" id="btn-submit-ticket" style="padding: 10px 24px; font-weight: 600;">
                  Registrar Chamado
                </button>
              </div>
            </form>
          </div>
        </div>
        <script nonce="<?= \ClyoNutri\Core\Http\SecurityHeaders::nonce() ?>">
          // Prevenção de duplo clique
          document.getElementById('form-ticket').addEventListener('submit', function() {
            var btn = document.getElementById('btn-submit-ticket');
            btn.disabled = true;
            btn.innerText = 'Processando e Gerando Protocolo...';
          });
        </script>
        <?php
        $content = ob_get_clean();
        require __DIR__ . '/../../../views/layouts/professional-shell.php';
    }

    /**
     * Processa a Abertura de Chamado (NI2-199 e NI2-200)
     */
    public function storeTicket(): void
    {
        $auth = $this->getAuthInfo();

        if (!Csrf::validate($_POST['_csrf_token'] ?? '')) {
            header('Location: /ajuda/abrir-chamado?error=csrf');
            exit;
        }

        try {
            $ticket = $this->ticketService->createTicket(
                $auth['clinicId'],
                $auth['userId'],
                (string)($_POST['subject'] ?? ''),
                (string)($_POST['category'] ?? ''),
                (string)($_POST['type'] ?? 'question'),
                (string)($_POST['description'] ?? ''),
                (string)($_POST['priority_perceived'] ?? 'medium'),
                !empty($_POST['origin_route']) ? (string)$_POST['origin_route'] : null
            );

            // Upload de anexo se enviado
            if (!empty($_FILES['attachment']) && ($_FILES['attachment']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE) {
                $this->attachmentService->storeAttachment(
                    (int)$ticket['id'],
                    $auth['userId'],
                    $_FILES['attachment']
                );
            }

            header("Location: /ajuda/chamado/{$ticket['protocol']}?sucesso=1");
            exit;
        } catch (\Throwable $e) {
            header("Location: /ajuda/abrir-chamado?error=" . urlencode($e->getMessage()));
            exit;
        }
    }

    /**
     * Lista de Chamados do Usuário: Meus Chamados (NI2-201)
     */
    public function myTickets(): void
    {
        $auth = $this->getAuthInfo();
        $statusFilter = trim((string)($_GET['status'] ?? ''));
        $tickets = $this->ticketService->listUserTickets($auth['userId'], $auth['clinicId'], $statusFilter !== '' ? $statusFilter : null);

        $title = 'Meus Chamados — Service Desk ClyoNutri II';
        $activeNav = 'ajuda';
        $clinicName = $auth['clinicName'];
        $userName = $auth['userName'];

        ob_start();
        ?>
        <div style="max-width: 1040px; margin: 0 auto; padding-bottom: var(--space-8);">
          <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 24px; flex-wrap: wrap; gap: 12px;">
            <div>
              <a href="/ajuda" style="font-size: 0.85rem; color: #64748B; text-decoration: none;">← Central de Ajuda</a>
              <h1 style="margin: 4px 0 0 0; font-size: 1.5rem; color: #0F3E17;">Meus Chamados de Suporte</h1>
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
            <div class="card" style="text-align: center; padding: 48px; color: #64748B;">
              <p style="font-size: 1.05rem; margin-bottom: 12px;">Nenhum chamado encontrado com o filtro selecionado.</p>
              <a href="/ajuda/abrir-chamado" class="btn btn-primary">Abrir Chamado</a>
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
                      <td style="font-family: monospace; font-weight: 700; color: #39734A;">
                        <?= htmlspecialchars($t['protocol']) ?>
                      </td>
                      <td style="font-weight: 600; color: #0F3E17;">
                        <a href="/ajuda/chamado/<?= $t['protocol'] ?>" style="color: inherit; text-decoration: none;">
                          <?= htmlspecialchars($t['subject']) ?>
                        </a>
                      </td>
                      <td style="font-size: 0.85rem; color: #64748B;">
                        <?= htmlspecialchars($t['category_label']) ?>
                      </td>
                      <td>
                        <span class="badge" style="background: var(--brand-navy-100); color: var(--brand-navy-900); font-size: 0.75rem;">
                          <?= htmlspecialchars($t['status_label']) ?>
                        </span>
                      </td>
                      <td style="font-size: 0.85rem; color: #64748B;">
                        💬 <?= $t['messages_count'] ?> <?= $t['attachments_count'] > 0 ? "• 📎 {$t['attachments_count']}" : '' ?>
                      </td>
                      <td style="font-size: 0.8rem; color: #94A3B8;">
                        <?= date('d/m/Y H:i', strtotime($t['updated_at'])) ?>
                      </td>
                      <td style="text-align: right;">
                        <a href="/ajuda/chamado/<?= $t['protocol'] ?>" class="btn btn-sm btn-secondary">
                          Ver Detalhes ➔
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
            <a href="/ajuda/meus-chamados" style="font-size: 0.85rem; color: #64748B; text-decoration: none;">← Voltar para Meus Chamados</a>
            <?php if ($auth['isSupport']): ?>
              <a href="/suporte/chamado/<?= $ticket['id'] ?>" class="btn btn-sm btn-outline" style="border-color: #8B5CF6; color: #7C3AED;">
                🛡️ Abrir no Painel de Atendimento (Backoffice)
              </a>
            <?php endif; ?>
          </div>

          <!-- Cabeçalho do Chamado -->
          <div class="card" style="margin-bottom: 24px;">
            <div style="display: flex; justify-content: space-between; align-items: flex-start; flex-wrap: wrap; gap: 12px; margin-bottom: 12px;">
              <div>
                <div style="display: flex; align-items: center; gap: 8px; margin-bottom: 6px;">
                  <span style="font-family: monospace; font-size: 1.15rem; font-weight: 700; color: #39734A;">
                    <?= htmlspecialchars($ticket['protocol']) ?>
                  </span>
                  <span class="badge" style="background: #E2E8F0; color: #0F3E17; font-size: 0.75rem;">
                    <?= htmlspecialchars($ticket['status_label']) ?>
                  </span>
                  <span class="badge" style="background: #F1F5F9; color: #475569; font-size: 0.75rem;">
                    <?= htmlspecialchars($ticket['type_label']) ?>
                  </span>
                </div>
                <h1 style="margin: 0 0 6px 0; font-size: 1.4rem; color: #0F3E17;">
                  <?= htmlspecialchars($ticket['subject']) ?>
                </h1>
                <div style="font-size: 0.85rem; color: #64748B;">
                  Categoria: <strong><?= htmlspecialchars($ticket['category_label']) ?></strong> • Aberto em <?= date('d/m/Y H:i', strtotime($ticket['created_at'])) ?>
                </div>
              </div>

              <!-- Ações de Resolução -->
              <?php if (in_array($ticket['status'], [HelpdeskTicketService::STATUS_OPEN, HelpdeskTicketService::STATUS_IN_PROGRESS, HelpdeskTicketService::STATUS_WAITING_CLIENT])): ?>
                <form action="/ajuda/chamado/<?= $ticket['protocol'] ?>/resolver" method="POST" data-confirm="Confirma que o problema foi solucionado e deseja fechar o chamado?">
                  <input type="hidden" name="_csrf_token" value="<?= $csrfToken ?>">
                  <button type="submit" class="btn btn-sm btn-secondary" style="color: #0A7075; border-color: #0A7075;">
                    ✓ Marcar como Resolvido
                  </button>
                </form>
              <?php endif; ?>
            </div>
          </div>

          <!-- Avaliação CSAT (NI2-207) se resolvido ou fechado -->
          <?php if (in_array($ticket['status'], [HelpdeskTicketService::STATUS_RESOLVED, HelpdeskTicketService::STATUS_CLOSED])): ?>
            <div class="card" style="background: #E3F4F2; border: 1px solid #B7DFDC; margin-bottom: 24px;">
              <h3 style="margin: 0 0 8px 0; color: #0A5F63; font-size: 1.1rem;">
                ⭐ Avaliação do Atendimento (CSAT)
              </h3>
              <?php if (!empty($ticket['feedback'])): ?>
                <div style="font-size: 0.95rem; color: #0A5F63;">
                  Você avaliou este atendimento com <strong><?= str_repeat('★', (int)$ticket['feedback']['rating']) ?><?= str_repeat('☆', 5 - (int)$ticket['feedback']['rating']) ?> (<?= $ticket['feedback']['rating'] ?>/5)</strong>.
                  <?php if (!empty($ticket['feedback']['comment'])): ?>
                    <p style="margin: 6px 0 0 0; color: #0A5F63; font-style: italic;">"<?= htmlspecialchars($ticket['feedback']['comment']) ?>"</p>
                  <?php endif; ?>
                </div>
              <?php else: ?>
                <p style="margin: 0 0 12px 0; font-size: 0.9rem; color: #0A5F63;">
                  Este chamado foi solucionado. Como você avalia a agilidade e qualidade do suporte prestado?
                </p>
                <form action="/ajuda/chamado/<?= $ticket['protocol'] ?>/csat" method="POST" style="display: flex; flex-direction: column; gap: 10px;">
                  <input type="hidden" name="_csrf_token" value="<?= $csrfToken ?>">
                  <div style="display: flex; gap: 16px; align-items: center;">
                    <?php for ($i = 5; $i >= 1; $i--): ?>
                      <label style="display: flex; align-items: center; gap: 4px; font-weight: 600; cursor: pointer; color: #0A5F63;">
                        <input type="radio" name="rating" value="<?= $i ?>" <?= $i === 5 ? 'checked' : '' ?>>
                        <?= $i ?> <?= $i === 5 ? '⭐ (Excelente)' : '⭐' ?>
                      </label>
                    <?php endfor; ?>
                  </div>
                  <div>
                    <input type="text" name="comment" placeholder="Comentário opcional sobre o atendimento..." 
                           style="width: 100%; padding: 8px 12px; border: 1px solid #B7DFDC; border-radius: 6px; font-size: 0.85rem; box-sizing: border-box;">
                  </div>
                  <div>
                    <button type="submit" class="btn btn-primary" style="background: #0A7075; border-color: #0A7075;">
                      Enviar Avaliação
                    </button>
                  </div>
                </form>
              <?php endif; ?>
            </div>
          <?php endif; ?>

          <!-- Anexos do Chamado (NI2-200) -->
          <?php if (!empty($ticket['attachments'])): ?>
            <div class="card" style="margin-bottom: 24px;">
              <h4 style="margin: 0 0 12px 0; font-size: 0.95rem; color: #0F3E17;">📎 Anexos Vinculados (<?= count($ticket['attachments']) ?>)</h4>
              <div style="display: flex; flex-wrap: wrap; gap: 10px;">
                <?php foreach ($ticket['attachments'] as $att): ?>
                  <a href="/ajuda/anexo/<?= $att['id'] ?>" target="_blank" style="text-decoration: none; display: inline-flex; align-items: center; gap: 8px; padding: 8px 12px; background: #F8FAFC; border: 1px solid #E2E8F0; border-radius: 6px; font-size: 0.85rem; color: #39734A;">
                    <span>📄</span>
                    <span style="font-weight: 500;"><?= htmlspecialchars($att['file_name']) ?></span>
                    <span style="color: #94A3B8; font-size: 0.75rem;">(<?= round($att['file_size'] / 1024, 1) ?> KB)</span>
                  </a>
                <?php endforeach; ?>
              </div>
            </div>
          <?php endif; ?>

          <!-- Timeline de Mensagens (NI2-202) -->
          <h3 style="margin: 0 0 16px 0; font-size: 1.15rem; color: #0F3E17;">Histórico de Mensagens</h3>
          <div style="display: flex; flex-direction: column; gap: 16px; margin-bottom: 28px;">
            <?php foreach ($ticket['messages'] as $idx => $msg): ?>
              <?php 
                $isMe = ((int)$msg['user_id'] === $auth['userId']);
                $isOperator = \ClyoNutri\Core\Security\RoleModel::isPlatformSupport($msg['author_role'] ?? ''); // u.role (plataforma)
              ?>
              <div style="border-radius: 8px; padding: 16px; border: 1px solid <?= $isOperator ? '#BFD8C2' : '#E2E8F0' ?>; background: <?= $isOperator ? '#E5F0E5' : '#FFFFFF' ?>;">
                <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 8px;">
                  <div>
                    <strong style="color: <?= $isOperator ? '#2F6140' : '#0F3E17' ?>; font-size: 0.95rem;">
                      <?= $isOperator ? '🛡️ Suporte ClyoNutri (' . htmlspecialchars($msg['author_name']) . ')' : htmlspecialchars($msg['author_name']) ?>
                    </strong>
                    <span style="font-size: 0.75rem; color: #64748B; margin-left: 8px;">
                      <?= date('d/m/Y H:i', strtotime($msg['created_at'])) ?>
                    </span>
                  </div>
                  <?php if ($idx === 0): ?>
                    <span style="font-size: 0.75rem; background: #E2E8F0; padding: 2px 6px; border-radius: 4px; color: #475569;">Abertura</span>
                  <?php endif; ?>
                </div>
                <div style="font-size: 0.9rem; color: #334155; line-height: 1.6; white-space: pre-wrap;">
                  <?= htmlspecialchars($msg['message']) ?>
                </div>
              </div>
            <?php endforeach; ?>
          </div>

          <!-- Formulário para Nova Resposta se não estiver fechado -->
          <?php if ($ticket['status'] !== HelpdeskTicketService::STATUS_CLOSED): ?>
            <div class="card">
              <h4 style="margin: 0 0 12px 0; font-size: 1rem; color: #0F3E17;">Adicionar Resposta ao Chamado</h4>
              <form action="/ajuda/chamado/<?= $ticket['protocol'] ?>/responder" method="POST" enctype="multipart/form-data">
                <input type="hidden" name="_csrf_token" value="<?= $csrfToken ?>">
                <div style="margin-bottom: 12px;">
                  <textarea name="message" required rows="4" placeholder="Escreva sua mensagem ou esclarecimento..." 
                            style="width: 100%; padding: 10px; border: 1px solid #CBD5E1; border-radius: 6px; font-size: 0.9rem; box-sizing: border-box;"></textarea>
                </div>
                <div style="margin-bottom: 16px;">
                  <label style="display: block; font-size: 0.8rem; font-weight: 600; color: #64748B; margin-bottom: 4px;">
                    Anexar novo arquivo (PNG, JPG ou PDF - Máx 10MB)
                  </label>
                  <input type="file" name="attachment" accept=".png,.jpg,.jpeg,.pdf" style="font-size: 0.85rem;">
                </div>
                <div style="display: flex; justify-content: flex-end;">
                  <button type="submit" class="btn btn-primary">
                    Enviar Resposta
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
            header('Cache-Control: private, max-age=3600');
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

        $queue = $this->ticketService->listBackofficeQueue($statusFilter, $priorityFilter);
        $metrics = $this->ticketService->getOperationsMetrics();

        $title = 'Fila do Service Desk — Backoffice';
        $activeNav = 'service_desk';
        $clinicName = $auth['clinicName'];
        $userName = $auth['userName'];

        ob_start();
        ?>
        <div style="max-width: 1200px; margin: 0 auto; padding-bottom: var(--space-8);">
          <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 24px;">
            <div>
              <span style="font-size: 0.8rem; font-weight: 700; color: #7C3AED; text-transform: uppercase;">BACKOFFICE DE OPERAÇÃO</span>
              <h1 style="margin: 4px 0 0 0; font-size: 1.6rem; color: #0F3E17;">Fila de Atendimento do Service Desk</h1>
            </div>
            <a href="/ajuda" class="btn btn-secondary">
              Visão da Central de Ajuda ➔
            </a>
          </div>

          <!-- Métricas e Indicadores Operacionais (NI2-206) -->
          <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 16px; margin-bottom: 28px;">
            <div class="card" style="padding: 16px; border-left: 4px solid #39734A;">
              <span style="font-size: 0.8rem; color: #64748B; font-weight: 600;">TOTAL DE CHAMADOS</span>
              <div style="font-size: 1.7rem; font-weight: 700; color: #0F3E17; margin-top: 4px;"><?= $metrics['total_tickets'] ?></div>
            </div>

            <div class="card" style="padding: 16px; border-left: 4px solid #0A7075;">
              <span style="font-size: 0.8rem; color: #64748B; font-weight: 600;">TEMPO 1ª RESPOSTA (SLA)</span>
              <div style="font-size: 1.7rem; font-weight: 700; color: #0F3E17; margin-top: 4px;">
                <?= $metrics['avg_first_response_minutes'] > 0 ? $metrics['avg_first_response_minutes'] . ' min' : 'Imediato' ?>
              </div>
            </div>

            <div class="card" style="padding: 16px; border-left: 4px solid #F59E0B;">
              <span style="font-size: 0.8rem; color: #64748B; font-weight: 600;">CHAMADOS ENVELHECIDOS (>48H)</span>
              <div style="font-size: 1.7rem; font-weight: 700; color: <?= $metrics['aged_tickets_count'] > 0 ? '#DC2626' : '#0A7075' ?>; margin-top: 4px;">
                <?= $metrics['aged_tickets_count'] ?>
              </div>
            </div>

            <div class="card" style="padding: 16px; border-left: 4px solid #8B5CF6;">
              <span style="font-size: 0.8rem; color: #64748B; font-weight: 600;">CSAT MÉDIO (1 A 5)</span>
              <div style="font-size: 1.7rem; font-weight: 700; color: #0F3E17; margin-top: 4px;">
                <?= $metrics['csat_average'] > 0 ? $metrics['csat_average'] . ' / 5.0 ⭐' : 'Sem notas' ?>
              </div>
            </div>
          </div>

          <!-- Filtros de Prioridade e Status -->
          <div style="display: flex; gap: 8px; margin-bottom: 20px; flex-wrap: wrap;">
            <a href="/suporte/fila" class="btn btn-sm <?= ($statusFilter === null && $priorityFilter === null) ? 'btn-primary' : 'btn-secondary' ?>">
              Todos os Chamados
            </a>
            <a href="/suporte/fila?priority=P0" class="btn btn-sm <?= $priorityFilter === 'P0' ? 'btn-danger' : 'btn-secondary' ?>" style="color: #DC2626;">
              P0 - Críticos
            </a>
            <a href="/suporte/fila?priority=P1" class="btn btn-sm <?= $priorityFilter === 'P1' ? 'btn-danger' : 'btn-secondary' ?>" style="color: #EA580C;">
              P1 - Altos
            </a>
            <a href="/suporte/fila?status=open" class="btn btn-sm <?= $statusFilter === 'open' ? 'btn-primary' : 'btn-secondary' ?>">
              Abertos
            </a>
            <a href="/suporte/fila?status=in_progress" class="btn btn-sm <?= $statusFilter === 'in_progress' ? 'btn-primary' : 'btn-secondary' ?>">
              Em Atendimento
            </a>
            <a href="/suporte/fila?status=waiting_internal" class="btn btn-sm <?= $statusFilter === 'waiting_internal' ? 'btn-primary' : 'btn-secondary' ?>">
              Aguardando Time Interno
            </a>
          </div>

          <!-- Tabela de Chamados na Fila -->
          <?php if (empty($queue)): ?>
            <div class="card" style="text-align: center; padding: 48px; color: #64748B;">
              <p>Nenhum chamado pendente na fila operacional com estes filtros.</p>
            </div>
          <?php else: ?>
            <div class="table-responsive">
              <table class="table">
                <thead>
                  <tr>
                    <th>Prioridade</th>
                    <th>Protocolo</th>
                    <th>Clínica / Solicitante</th>
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
                          $pBg = '#F1F5F9'; $pCol = '#334155';
                          if ($item['priority_operational'] === 'P0') { $pBg = '#FEE2E2'; $pCol = '#DC2626'; }
                          elseif ($item['priority_operational'] === 'P1') { $pBg = '#FFEDD5'; $pCol = '#C2410C'; }
                        ?>
                        <span class="badge" style="background: <?= $pBg ?>; color: <?= $pCol ?>; font-weight: 700;">
                          <?= $item['priority_operational'] ?>
                        </span>
                      </td>
                      <td style="font-family: monospace; font-weight: 700;">
                        <a href="/suporte/chamado/<?= $item['id'] ?>" style="color: #39734A; text-decoration: none;">
                          <?= htmlspecialchars($item['protocol']) ?>
                        </a>
                      </td>
                      <td>
                        <strong style="color: #0F3E17; display: block; font-size: 0.9rem;"><?= htmlspecialchars($item['clinic_name']) ?></strong>
                        <span style="font-size: 0.75rem; color: #64748B;"><?= htmlspecialchars($item['author_name']) ?></span>
                      </td>
                      <td style="font-weight: 600; font-size: 0.9rem;">
                        <?= htmlspecialchars($item['subject']) ?>
                      </td>
                      <td style="font-size: 0.85rem; color: #64748B;">
                        <?= htmlspecialchars($item['category_label']) ?>
                      </td>
                      <td>
                        <span class="badge" style="background: #E2E8F0; color: #0F3E17; font-size: 0.75rem;">
                          <?= htmlspecialchars($item['status_label']) ?>
                        </span>
                      </td>
                      <td style="font-size: 0.85rem; color: #64748B;">
                        <?= $item['assigned_operator_name'] ? htmlspecialchars($item['assigned_operator_name']) : '<em>Não atribuído</em>' ?>
                      </td>
                      <td style="text-align: right;">
                        <a href="/suporte/chamado/<?= $item['id'] ?>" class="btn btn-sm btn-primary">
                          Atender ➔
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
            <a href="/suporte/fila" style="font-size: 0.85rem; color: #64748B; text-decoration: none;">← Voltar para Fila de Atendimento</a>
          </div>

          <!-- Card de Controle e Metadados do Chamado -->
          <div class="card" style="margin-bottom: 24px;">
            <div style="display: flex; justify-content: space-between; align-items: flex-start; flex-wrap: wrap; gap: 16px; margin-bottom: 16px;">
              <div>
                <span style="font-family: monospace; font-size: 1.25rem; font-weight: 700; color: #39734A;">
                  <?= htmlspecialchars($ticket['protocol']) ?>
                </span>
                <h1 style="margin: 4px 0 6px 0; font-size: 1.45rem; color: #0F3E17;">
                  <?= htmlspecialchars($ticket['subject']) ?>
                </h1>
                <div style="font-size: 0.85rem; color: #64748B;">
                  Solicitante: <strong><?= htmlspecialchars($ticket['author_name']) ?></strong> (<?= htmlspecialchars($ticket['author_email']) ?>) • Clínica: <strong><?= htmlspecialchars($ticket['clinic_name']) ?></strong>
                </div>
              </div>

              <!-- Formulário de Transição de Status e Prioridade -->
              <div style="display: flex; gap: 10px; align-items: center; flex-wrap: wrap;">
                <form action="/suporte/chamado/<?= $ticket['id'] ?>/status" method="POST" style="display: flex; gap: 6px;">
                  <input type="hidden" name="_csrf_token" value="<?= $csrfToken ?>">
                  <select name="status" style="padding: 6px 10px; border: 1px solid #CBD5E1; border-radius: 6px; font-size: 0.85rem;">
                    <?php foreach (HelpdeskTicketService::STATUS_LABELS as $sKey => $sLabel): ?>
                      <option value="<?= $sKey ?>" <?= $ticket['status'] === $sKey ? 'selected' : '' ?>>
                        <?= $sLabel ?>
                      </option>
                    <?php endforeach; ?>
                  </select>
                  <button type="submit" class="btn btn-sm btn-secondary">Atualizar Status</button>
                </form>
              </div>
            </div>
          </div>

          <!-- Timeline Completa com Notas Internas Destacadas (NI2-205) -->
          <h3 style="margin: 0 0 16px 0; font-size: 1.15rem; color: #0F3E17;">Timeline Completa do Chamado</h3>
          <div style="display: flex; flex-direction: column; gap: 14px; margin-bottom: 28px;">
            <?php foreach ($ticket['messages'] as $msg): ?>
              <?php 
                $isInternal = ((int)$msg['is_internal_note'] === 1);
                $isAuthor = ((int)$msg['user_id'] === (int)$ticket['user_id']);
              ?>
              <div style="border-radius: 8px; padding: 16px; border: 1px solid <?= $isInternal ? '#FDE68A' : ($isAuthor ? '#E2E8F0' : '#BFD8C2') ?>; background: <?= $isInternal ? '#FFFBEB' : ($isAuthor ? '#FFFFFF' : '#E5F0E5') ?>;">
                <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 8px;">
                  <div>
                    <?php if ($isInternal): ?>
                      <span class="badge" style="background: #F59E0B; color: #fff; font-size: 0.75rem; margin-right: 6px;">
                        🔒 NOTA INTERNA (OCULTA DO CLIENTE)
                      </span>
                    <?php endif; ?>
                    <strong style="color: #0F3E17; font-size: 0.95rem;">
                      <?= htmlspecialchars($msg['author_name']) ?> (<?= htmlspecialchars($msg['author_role']) ?>)
                    </strong>
                    <span style="font-size: 0.75rem; color: #64748B; margin-left: 8px;">
                      <?= date('d/m/Y H:i', strtotime($msg['created_at'])) ?>
                    </span>
                  </div>
                </div>
                <div style="font-size: 0.9rem; color: #334155; line-height: 1.6; white-space: pre-wrap;">
                  <?= htmlspecialchars($msg['message']) ?>
                </div>
              </div>
            <?php endforeach; ?>
          </div>

          <!-- Box de Resposta do Backoffice -->
          <div class="card">
            <h4 style="margin: 0 0 12px 0; font-size: 1rem; color: #0F3E17;">Responder ou Adicionar Nota Interna</h4>
            <form action="/suporte/chamado/<?= $ticket['id'] ?>/responder" method="POST">
              <input type="hidden" name="_csrf_token" value="<?= $csrfToken ?>">

              <div style="margin-bottom: 12px;">
                <textarea name="message" required rows="5" placeholder="Escreva a resposta para o cliente ou as anotações técnicas internas de triagem..." 
                          style="width: 100%; padding: 10px; border: 1px solid #CBD5E1; border-radius: 6px; font-size: 0.9rem; box-sizing: border-box;"></textarea>
              </div>

              <div style="display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 12px;">
                <label style="display: flex; align-items: center; gap: 8px; font-size: 0.9rem; font-weight: 600; color: #B45309; cursor: pointer; background: #FEF3C7; padding: 6px 12px; border-radius: 6px;">
                  <input type="checkbox" name="is_internal_note" value="1">
                  <span>🔒 Registrar como Nota Interna (não envia notificação nem exibe ao cliente)</span>
                </label>

                <button type="submit" class="btn btn-primary">
                  Enviar Mensagem
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
                $html .= "<h3 style='margin: 18px 0 8px 0; color: #0F3E17;'>" . htmlspecialchars(substr($trimmed, 4)) . "</h3>\n";
                continue;
            }
            if (str_starts_with($trimmed, '#### ')) {
                if ($inList) { $html .= "</ul>\n"; $inList = false; }
                $html .= "<h4 style='margin: 14px 0 6px 0; color: #1E293B;'>" . htmlspecialchars(substr($trimmed, 5)) . "</h4>\n";
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

    private function formatInlineMarkdown(string $text): string
    {
        // Bold: **text**
        $text = preg_replace('/\*\*(.*?)\*\*/', '<strong>$1</strong>', $text);
        // Italic: *text*
        $text = preg_replace('/(?<!\*)\*(?!\*)(.*?)\*/', '<em>$1</em>', $text);
        // Inline code: `code`
        $text = preg_replace('/`(.*?)`/', '<code style="background: #F1F5F9; padding: 2px 4px; border-radius: 4px; font-size: 0.85em;">$1</code>', $text);

        return $text;
    }
}
