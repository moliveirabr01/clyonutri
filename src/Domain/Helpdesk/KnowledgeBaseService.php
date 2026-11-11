<?php

declare(strict_types=1);

namespace ClyoNutri\Domain\Helpdesk;

use ClyoNutri\Core\Database\Connection;
use PDO;

class KnowledgeBaseService
{
    private PDO $db;

    public const CATEGORIES = [
        'acesso_login' => [
            'name' => 'Acesso e senha',
            'icon' => 'key',
            'description' => 'Entrar na conta e criar uma nova senha.',
        ],
        'cadastro_conta' => [
            'name' => 'Cadastro e conta',
            'icon' => 'user-check',
            'description' => 'Seus dados profissionais (CRN) e do consultório.',
        ],
        'pacientes' => [
            'name' => 'Pacientes',
            'icon' => 'users',
            'description' => 'Cadastro, perfil e histórico do paciente.',
        ],
        'convites' => [
            'name' => 'Convite e acesso do paciente',
            'icon' => 'mail',
            'description' => 'Link, código e QR code para o paciente entrar no app.',
        ],
        'anamnese' => [
            'name' => 'Anamnese',
            'icon' => 'clipboard',
            'description' => 'Alergias, restrições, rotina, histórico clínico e versões.',
        ],
        'avaliacao_nutricional' => [
            'name' => 'Avaliação nutricional',
            'icon' => 'activity',
            'description' => 'Metas e necessidades nutricionais. O cálculo por fórmulas ainda não está disponível.',
        ],
        'antropometria' => [
            'name' => 'Antropometria',
            'icon' => 'sliders',
            'description' => 'Peso, altura, circunferências, percentual de gordura, IMC e evolução.',
        ],
        'planos_alimentares' => [
            'name' => 'Planos alimentares',
            'icon' => 'book-open',
            'description' => 'Refeições, alimentos da TACO, medidas caseiras, substituições e publicação.',
        ],
        'prescricoes_orientacoes' => [
            'name' => 'Prescrições e orientações',
            'icon' => 'file-text',
            'description' => 'Receitas prescritas e impressão do plano.',
        ],
        'diario_alimentar' => [
            'name' => 'Diário alimentar',
            'icon' => 'coffee',
            'description' => 'Refeições que o paciente registra no app, com foto opcional.',
        ],
        'agenda' => [
            'name' => 'Agenda e consultas',
            'icon' => 'calendar',
            'description' => 'Agendar, reagendar, iniciar e concluir consultas.',
        ],
        'evolucao' => [
            'name' => 'Evolução',
            'icon' => 'trending-up',
            'description' => 'Comparação entre avaliações e histórico do paciente.',
        ],
        'app_paciente' => [
            'name' => 'App do paciente',
            'icon' => 'smartphone',
            'description' => 'Plano publicado, registro de água e diário, pelo navegador.',
        ],
        'assinaturas_cobranca' => [
            'name' => 'Assinatura e cobrança',
            'icon' => 'credit-card',
            'description' => 'Seu plano do Clyo Nutri, faturas e cancelamento.',
        ],
        'configuracoes' => [
            'name' => 'Configurações do consultório',
            'icon' => 'settings',
            'description' => 'Dados do consultório, horários de atendimento e senha.',
        ],
        'seguranca_privacidade' => [
            'name' => 'Segurança e privacidade (LGPD)',
            'icon' => 'shield',
            'description' => 'Consentimentos, sigilo e direitos do titular dos dados.',
        ],
        'teleconsulta' => [
            'name' => 'Teleconsulta',
            'icon' => 'video',
            'description' => 'Convite, sala de espera, câmera, microfone e entrada só com áudio.',
        ],
        'sugestoes_outros' => [
            'name' => 'Sugestões e outros',
            'icon' => 'message-square',
            'description' => 'Sugestões de melhoria e dúvidas gerais.',
        ],
    ];

    public function __construct(?PDO $db = null)
    {
        $this->db = $db ?? Connection::getInstance();
        $this->ensureDefaultArticlesSeeded();
        $this->ensureRescheduleArticle();
    }

    /**
     * Retorna a lista de categorias disponíveis com contagem de artigos publicados.
     */
    public function getCategoriesWithCount(): array
    {
        $stmt = $this->db->query("
            SELECT category, COUNT(*) as total_articles
            FROM helpdesk_articles
            WHERE is_published = 1
            GROUP BY category
        ");
        $counts = $stmt->fetchAll(PDO::FETCH_KEY_PAIR) ?: [];

        $result = [];
        foreach (self::CATEGORIES as $key => $meta) {
            $result[$key] = array_merge($meta, [
                'slug' => $key,
                'count' => (int)($counts[$key] ?? 0),
            ]);
        }
        return $result;
    }

    /**
     * Busca artigos na base de conhecimento por termo e opcionalmente por categoria.
     */
    public function search(string $query, ?string $category = null): array
    {
        $query = trim($query);
        $sql = "SELECT id, category, slug, title, summary, view_count, updated_at
                FROM helpdesk_articles
                WHERE is_published = 1";
        $params = [];

        if (!empty($category) && isset(self::CATEGORIES[$category])) {
            $sql .= " AND category = :category";
            $params['category'] = $category;
        }

        if (!empty($query)) {
            $sql .= " AND (title LIKE :q OR summary LIKE :q OR content_markdown LIKE :q)";
            $params['q'] = '%' . $query . '%';
        }

        $sql .= " ORDER BY view_count DESC, id ASC LIMIT 30";
        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    /**
     * Retorna artigos de uma categoria específica.
     */
    public function getArticlesByCategory(string $category): array
    {
        $stmt = $this->db->prepare("
            SELECT id, category, slug, title, summary, view_count, updated_at
            FROM helpdesk_articles
            WHERE category = :category AND is_published = 1
            ORDER BY view_count DESC, id ASC
        ");
        $stmt->execute(['category' => $category]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    /**
     * Retorna um artigo pelo seu slug amigável e incrementa visualização.
     */
    public function getArticleBySlug(string $slug): ?array
    {
        $stmt = $this->db->prepare("
            SELECT * FROM helpdesk_articles
            WHERE slug = :slug AND is_published = 1
            LIMIT 1
        ");
        $stmt->execute(['slug' => $slug]);
        $article = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($article) {
            $this->incrementViews((int)$article['id']);
            $article['category_meta'] = self::CATEGORIES[$article['category']] ?? [
                'name' => ucfirst($article['category']),
                'icon' => 'help-circle'
            ];
            return $article;
        }

        return null;
    }

    /**
     * Incrementa o contador de visualizações do artigo.
     */
    public function incrementViews(int $articleId): void
    {
        $stmt = $this->db->prepare("
            UPDATE helpdesk_articles
            SET view_count = view_count + 1
            WHERE id = :id
        ");
        $stmt->execute(['id' => $articleId]);
    }

    /**
     * Retorna artigos em destaque (FAQs e tópicos mais frequentes).
     */
    public function getFeaturedFaqs(int $limit = 6): array
    {
        $stmt = $this->db->prepare("
            SELECT id, category, slug, title, summary, view_count
            FROM helpdesk_articles
            WHERE is_published = 1
            ORDER BY view_count DESC, id ASC
            LIMIT :lim
        ");
        $stmt->bindValue(':lim', $limit, PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    /** UX-04 (v14): artigo "Como reagendar uma consulta" (o mesmo texto vai no SQL v14). */
    public const RESCHEDULE_ARTICLE = [
        'category' => 'agenda',
        'slug' => 'como-reagendar-uma-consulta',
        'title' => 'Como reagendar uma consulta?',
        'summary' => 'Mude o dia ou horário de uma consulta agendada sem perder o histórico nem os vínculos com o paciente.',
        'content_markdown' => "### Reagendar uma consulta

1. Na **Agenda**, na linha da consulta (agendada ou confirmada), clique em **Reagendar**. No perfil do paciente, use **Reagendar** no quadro *Próximo passo*.
2. O formulário já vem com o horário e a duração atuais. Escolha o novo dia e horário e, se quiser, informe o motivo.
3. Clique em **Confirmar reagendamento**.

#### O que acontece
- A consulta continua a mesma: paciente, tipo, observações, anamnese, avaliações e planos vinculados não mudam.
- A trilha da consulta registra o horário anterior, o novo horário, o motivo e quem reagendou.
- Se o novo horário conflitar com outra consulta, o formulário continua aberto e preenchido, com o horário destacado. Escolha outro horário ou marque **Permitir sobreposição (encaixe)**.
- Não é possível reagendar para um horário que já passou, nem reagendar consultas concluídas, canceladas ou com falta.
- Teleconsulta: o convite anterior deixa de valer; envie um novo convite ao paciente.",
    ];

    public function ensureRescheduleArticle(): void
    {
        try {
            $check = $this->db->prepare('SELECT COUNT(*) FROM helpdesk_articles WHERE slug = :slug');
            $check->execute(['slug' => self::RESCHEDULE_ARTICLE['slug']]);
            if ((int)$check->fetchColumn() > 0) {
                return;
            }
            $this->db->prepare("INSERT INTO helpdesk_articles (category, slug, title, summary, content_markdown, is_published, view_count, created_at, updated_at)
                VALUES (:category, :slug, :title, :summary, :content_markdown, 1, 0, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP)")
                ->execute(array_merge(self::RESCHEDULE_ARTICLE, HelpArticlesV21::all()[self::RESCHEDULE_ARTICLE['slug']] ?? []));
        } catch (\PDOException $e) {
            // Artigo de ajuda é acessório: não derruba a central de ajuda (tabela antiga/sem permissão).
        }
    }

    /**
     * Garante que os artigos canônicos do ClyoNutri estejam pré-cadastrados.
     * Confere artigo por artigo (slug): outras fontes, como a migração 41 com os artigos
     * de teleconsulta, podem ter populado a tabela antes, e os canônicos não podem faltar.
     */
    public function ensureDefaultArticlesSeeded(): void
    {
        $existing = array_flip($this->db->query('SELECT slug FROM helpdesk_articles')->fetchAll(PDO::FETCH_COLUMN) ?: []);

        $articles = [
            [
                'category' => 'acesso_login',
                'slug' => 'como-redefinir-minha-senha',
                'title' => 'Como redefinir minha senha de acesso?',
                'summary' => 'Passo a passo para recuperar seu acesso ao Clyo Nutri caso tenha esquecido sua credencial.',
                'content_markdown' => "### Redefinição de Senha no Clyo Nutri\n\n1. Na tela de login (`/login`), clique no link **Esqueceu sua senha?**.\n2. Informe o endereço de e-mail cadastrado na sua conta profissional.\n3. Você receberá um e-mail com instruções seguras e um link único de validação criptografada.\n4. Defina sua nova senha com no mínimo 8 caracteres, contendo letras e números.\n5. Em caso de dúvidas ou se não receber o e-mail, verifique sua caixa de spam ou abra um chamado pelo nosso Service Desk.",
                'view_count' => 124,
            ],
            [
                'category' => 'planos_alimentares',
                'slug' => 'como-elaborar-um-plano-alimentar-com-taco',
                'title' => 'Como elaborar um plano alimentar utilizando a Tabela TACO?',
                'summary' => 'Aprenda a criar cardápios balanceados calculando macronutrientes e micronutrientes automaticamente pela tabela TACO.',
                'content_markdown' => "### Elaboração de Plano Alimentar com Tabela TACO\n\nO Clyo Nutri integra nativamente a Tabela Brasileira de Composição de Alimentos (TACO 4ª edição).\n\n#### Passos para montagem:\n1. Acesse o menu **Planos Alimentares** e selecione o paciente desejado.\n2. Clique em **Criar Novo Plano** e dê um título identificador (ex: *Hipertrofia Fase 1*).\n3. Adicione as refeições do dia (Café da Manhã, Almoço, Lanche da Tarde, Jantar, Ceia).\n4. Em cada refeição, pesquise o alimento pelo nome na barra de busca (ex: *Arroz integral cozido*, *Peito de frango grelhado*).\n5. Ajuste a quantidade em gramas ou medidas caseiras.\n6. O gráfico superior recalcula em tempo real o VET (Valor Energético Total), Carboidratos, Proteínas e Lipídios.\n7. Clique em **Publicar Plano** para disponibilizá-lo instantaneamente no app do paciente.",
                'view_count' => 312,
            ],
            [
                'category' => 'antropometria',
                'slug' => 'como-registrar-avaliacao-antropometrica-pollock-7-dobras',
                'title' => 'Como registrar avaliação antropométrica com protocolo Pollock 7 dobras?',
                'summary' => 'Orientações para medição e inserção de dobras cutâneas com cálculo de densidade e percentual de gordura.',
                'content_markdown' => "### Antropometria: Protocolo Pollock 7 Dobras\n\nO protocolo de Jackson & Pollock 7 dobras cutâneas é ideal para estimativa precisa da composição corporal.\n\n#### Procedimento no Clyo Nutri:\n1. Acesse o prontuário do paciente e clique na aba **Antropometria**.\n2. Clique em **Nova Avaliação** e selecione o protocolo **Pollock 7 Dobras**.\n3. Insira as medidas aferidas pelo adipômetro em milímetros (mm):\n   - Subescapular\n   - Tricipital\n   - Peitoral (ou axilar média)\n   - Axilar média\n   - Suprailíaca\n   - Abdominal\n   - Coxa\n4. O sistema calcula automaticamente a densidade corporal (\(D_c\)), percentual de gordura corporal (%G via fórmula de Siri) e massa magra em kg.\n5. Você pode gerar o gráfico evolutivo e liberar o relatório ao paciente.",
                'view_count' => 245,
            ],
            [
                'category' => 'convites',
                'slug' => 'como-convidar-paciente-para-o-aplicativo',
                'title' => 'Como enviar o convite de acesso para o aplicativo do paciente?',
                'summary' => 'Veja como enviar o link de primeiro acesso por e-mail ou WhatsApp para o paciente acessar o app.',
                'content_markdown' => "### Envio de Convites para o Aplicativo do Paciente\n\n1. No perfil 360 do paciente, localize o botão **Convidar para o App**.\n2. Escolha o canal de envio: **E-mail** ou link direto via **WhatsApp**.\n3. O paciente recebe um link com token exclusivo para definir sua senha de primeiro acesso.\n4. Após a ativação, o paciente pode baixar o app ou utilizá-lo diretamente pelo navegador do celular com suporte offline.",
                'view_count' => 198,
            ],
            [
                'category' => 'anamnese',
                'slug' => 'como-preencher-anamnese-nutricional-estruturada',
                'title' => 'Como preencher e personalizar a anamnese nutricional?',
                'summary' => 'Estrutura completa de histórico alimentar, patologias, aversões e estilo de vida.',
                'content_markdown' => "### Anamnese Nutricional Estruturada\n\nO módulo de anamnese do Clyo Nutri é projetado para rapidez e profundidade clínica:\n\n- **Histórico Clínico:** Alergias, intolerâncias alimentares e histórico familiar.\n- **Hábito Intestinal e Hidratação:** Escala de Bristol e consumo hídrico diário.\n- **Recordatório Alimentar:** Registro de 24h com cálculo aproximado de calorias.\n- **Aversões e Preferências:** O sistema sinaliza restrições ao montar o plano alimentar para evitar erros prescritivos.",
                'view_count' => 180,
            ],
            [
                'category' => 'app_paciente',
                'slug' => 'como-o-paciente-registra-agua-e-refeicoes-no-app',
                'title' => 'Como o paciente registra hidratação e refeições no aplicativo?',
                'summary' => 'Oriente seus pacientes a utilizarem o diário alimentar e o monitor de hidratação diário.',
                'content_markdown' => "### Uso do Aplicativo pelo Paciente\n\nNo app Clyo Nutri, o paciente tem acesso a:\n\n1. **Meta de Água:** Registro com um toque de 250ml ou 500ml, com acompanhamento da barra de meta diária.\n2. **Plano Alimentar:** Lista das refeições programadas com substituições autorizadas pelo nutricionista.\n3. **Diário com Fotos:** O paciente pode fotografar pratos e relatar saciedade e humor, sincronizando com o consultório.",
                'view_count' => 210,
            ],
            [
                'category' => 'seguranca_privacidade',
                'slug' => 'como-o-clyonutri-garante-a-privacidade-lgpd',
                'title' => 'Como o Clyo Nutri protege os dados dos pacientes conforme a LGPD?',
                'summary' => 'Entenda como cumprimos as diretrizes da LGPD e do Conselho Federal de Nutricionistas (CFN).',
                'content_markdown' => "### Conformidade com a LGPD e Segurança dos Dados\n\n- **Isolamento de Dados (Multi-tenancy):** Os dados da sua clínica são isolados logicamente por meio do TenantGuard.\n- **Criptografia:** As senhas são guardadas com hash bcrypt e as comunicações trafegam por conexão criptografada (HTTPS/TLS).\n- **Termo de Consentimento:** O paciente assina digitalmente o consentimento para tratamento de dados sensíveis de saúde.\n- **Privacidade no Suporte:** Os operadores do Service Desk têm acesso estritamente restrito aos chamados, sem visibilidade de prontuários médicos ou dados clínicos confidenciais.",
                'view_count' => 155,
            ],
        ];

        $stmt = $this->db->prepare("
            INSERT INTO helpdesk_articles (category, slug, title, summary, content_markdown, is_published, view_count, created_at, updated_at)
            VALUES (:category, :slug, :title, :summary, :content_markdown, 1, :view_count, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP)
        ");

        foreach ($articles as $art) {
            if (isset($existing[$art['slug']])) {
                continue;
            }
            $art = array_merge($art, HelpArticlesV21::all()[$art['slug']] ?? []); // v21: texto revisado
            $stmt->execute([
                'category' => $art['category'],
                'slug' => $art['slug'],
                'title' => $art['title'],
                'summary' => $art['summary'],
                'content_markdown' => $art['content_markdown'],
                'view_count' => $art['view_count'],
            ]);
        }
    }
}
