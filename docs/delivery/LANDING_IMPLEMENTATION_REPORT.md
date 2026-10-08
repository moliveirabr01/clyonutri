# CLYONUTRI II — LANDING PAGE IMPLEMENTATION REPORT
## Relatório Técnico de Engenharia · Produção · Versão 1.0.0

---

### 1. Resumo Executivo

Este documento detalha a implementação em nível de produção da nova **Landing Page Premium** do **ClyoNutri II**, concebida sob o direcionamento criativo **"Precision Meets Care"** e alinhada com as melhores referências globais de produtos SaaS de alta sofisticação (Linear, Stripe, Notion, Framer e Raycast).

A solução foi desenvolvida diretamente na stack nativa do projeto (PHP 8.2+ MVC com roteamento canônico em `public/index.php`, templates Blade/PHP sem dependências externas desnecessárias e folha de estilos dedicada baseada nos tokens oficiais do Design System Premium).

---

### 2. Arquitetura de Arquivos Implementada

| Arquivo | Papel na Arquitetura | Responsabilidade Técnica |
| :--- | :--- | :--- |
| `src/Application/Controllers/LandingController.php` | Controller Web Público | Renderiza a landing page (`/`), termos de uso (`/termos`) e política de privacidade (`/privacidade`), gerenciando estado de sessão de usuário logado. |
| `public/index.php` | Front Controller & Router | Mapeamento das rotas canônicas públicas `GET /`, `GET /termos` e `GET /privacidade`, garantindo acesso irrestrito a visitantes e link de acesso rápido ao Dashboard para autenticados. |
| `public/assets/css/landing-premium.css` | Folha de Estilos Premium (1.116 linhas) | Implementação completa dos tokens visuais, tipografia editorial e sans-serif, grid responsivo, microinterações, estados de foco WCAG 2.2 e suporte a `prefers-reduced-motion`. |
| `views/landing/index.php` | Template Principal da Landing Page | Estrutura semântica HTML5 cobrindo as 13 seções obrigatórias com componentes acessíveis (`role="tablist"`, `aria-expanded`, skip link) e UI autêntica do sistema. |
| `views/legal/terms.php` | Visualização Legal de Termos de Uso | Termos completos com referência ética ao CFN/CRN, período de avaliação de 14 dias sem cartão, e definição de titularidade de dados. |
| `views/legal/privacy.php` | Visualização de Governança LGPD | Política de proteção de dados sensíveis de saúde, especificação de isolamento multitenant, hash SHA-256, Bcrypt, TLS 1.3 e contato do DPO. |
| `tests/landing_page_verification_test.php` | Suíte de Testes Automatizados | 46 asserções de integridade cobrindo renderização, presença das 13 seções, resolução de âncoras, rotas legais, conformidade CSS e proibição de padrões antiéticos. |

---

### 3. Mapeamento das 13 Seções Implementadas

#### Seção 1 — Header Premium
* **Componentes:** Logo vetorial com marcação Clyo em tom Copper (`#E67A3F`) e Nutri em Navy (`#0F2747`), navegação com links rápidos para seções da página, botões de ação ("Entrar" e "Começar Agora") e menu móvel acessível (gaveta off-canvas acionada por botão hamburguer com `aria-expanded`).
* **Comportamento Sticky:** Fundo com `backdrop-filter: blur(14px)` e opacidade 88% sobre o neutro quente `#FAF7F2`, mantendo a leitura fluida e sofisticada.

#### Seção 2 — Hero de Alto Impacto
* **Badge:** *"Plataforma Clínica de Alta Precisão · Versão 1.0"* com indicador em tom Copper.
* **Headline:** *"Sua nutrição merece uma plataforma à altura."* (Playfair Display, clamp responsivo).
* **Subheadline:** Narrativa equilibrada unindo agilidade de atendimento, cálculo canônico e cuidado humano.
* **CTAs Duplos:** Botão principal Copper para `/cadastro` e botão secundário para âncora `#recursos`.
* **Garantias em Microcopy:** Ícones SVG de checkmark para 14 dias de teste, ausência de cartão de crédito no cadastro e conformidade legal com LGPD & CFN.
* **Protagonista Visual:** Janela com controles de janela do macOS (`window-dots`), barra de endereço simulando `app.clyonutri.com.br/dashboard`, e interface do painel com métricas reais (48 pacientes ativos, 6 consultas hoje, 94% taxa de adesão, faturamento mensal e agenda do dia).

#### Seção 3 — Problema do Nutricionista
* **Título:** *"Seu tempo deveria estar com seus pacientes. Não com a burocracia."*
* **3 Dores Reais Catalogadas:**
  1. *Dados dispersos e retrabalho:* Perda de histórico entre papéis, planilhas e mensagens.
  2. *Cálculos nutricionais exaustivos:* Desgaste com cálculos manuais propensos a erro.
  3. *Abandono e desengajamento:* Pacientes que perdem o foco pós-consulta com PDFs frios.

#### Seção 4 — Solução Integrada (3 Pilares)
* **Pilar 1 — Organize (Gestão):** Prontuário 360, agenda sincronizada, isolamento por clínica conforme LGPD.
* **Pilar 2 — Prescreva (Precisão):** Base TACO canônica 100g, cálculo em tempo real de macros e substitutos inteligentes.
* **Pilar 3 — Acompanhe (Continuidade):** Aplicativo móvel para o paciente, acompanhamento de água e aumento da adesão clínica.

#### Seção 5 — Showcase do Produto (Abas Interativas Acessíveis)
* **Arquitetura ARIA:** `role="tablist"` com navegação por teclado (`ArrowLeft`, `ArrowRight`, `Home`, `End`).
* **Aba 1 (Perfil 360 do Paciente):** Linha do tempo clínica, evolução de peso (-2.1kg), IMC 22.4, sparkline antropométrico e selo de integridade criptográfica SHA-256.
* **Aba 2 (Prescrição Canônica TACO):** Detalhamento de refeição (Almoço: arroz integral, feijão preto, filé de frango, azeite) com totalizador instantâneo de 568 kcal, 38.4g proteína, 44.2g carbo e 14.6g lipídeos.
* **Aba 3 (Agenda & Consultório):** Linha do tempo diária com status visuais (Concluída, Em Atendimento, Confirmada) e previsão de faturamento.
* **Aba 4 (App do Paciente - Beta):** Cartão de rastreamento de hidratação (1.750 / 2.500 ml com barra de progresso) e próxima refeição.

#### Seção 6 — Experiência do Paciente
* **Narrativa:** *"O plano nutricional que o seu paciente realmente consegue seguir."*
* **Composição:** Duas colunas com lista de benefícios e frame de smartphone de alta fidelidade com notch, cabeçalho de atendimento e botão de registro rápido de copo de água (+250 ml).

#### Seção 7 — Como Funciona (3 Passos)
* **Passo 1:** *Configure seu consultório* em 2 minutos.
* **Passo 2:** *Atenda e prescreva* com rapidez e exatidão da base TACO.
* **Passo 3:** *Acompanhe a evolução* contínua e fortaleça o vínculo terapêutico.

#### Seção 8 — Diferenciais Técnicos e de Segurança
* **Base Canônica TACO / UNICAMP:** Valores nutricionais oficiais por 100g proporcionais.
* **Integridade com Hash SHA-256:** Trilha imutável de auditoria no prontuário.
* **Isolamento Multitenant & LGPD:** Proteção de dados com criptografia Bcrypt e TLS 1.3.

#### Seção 9 — Filosofia & Manifesto Clínico
* **Box Editorial Navy (`#0F2747`):** Declaração de princípio do design ClyoNutri:
  > *«Acreditamos que a tecnologia deve ser invisível na consulta: ela deve trabalhar em silêncio para que a empatia, a escuta ativa e o julgamento clínico do nutricionista ocupem o centro do palco.»*

#### Seção 10 — Preços / Teste Transparente
* **Cartão de Oferta:** 14 Dias de Acesso Irrestrito sem custo durante o período de avaliação, sem exigência de cartão de crédito, com suporte completo e garantia de exportação e portabilidade de dados.

#### Seção 11 — FAQ Accordion Acessível
* **6 Perguntas e Respostas Frequentes:** Implementado com botões acessíveis (`aria-expanded`, `aria-controls`), cobrindo cartão de crédito, metodologia de cálculo TACO, LGPD, gratuidade do app para o paciente, exportação de dados e compatibilidade responsiva.

#### Seção 12 — CTA Final de Alta Conversão
* **Box de Fechamento:** Fundo Navy com botão Copper de alto contraste: *"Mais tempo para cuidar. Mais clareza para evoluir."* com links de criação de conta gratuita e login.

#### Seção 13 — Rodapé Institucional
* **Quatro Colunas Estruturadas:** Marca & Manifesto LGPD, Links de Produto (incluindo rota de healthcheck `/up`), Governança & Legal (`/termos`, `/privacidade`) e Acesso Direto (`/cadastro`, `/login`, `/dashboard`).

---

### 4. Padrões de Código e Acessibilidade

1. **Acessibilidade WCAG 2.2 AA:**
   * Skip Link no topo do documento apontando diretamente para `#main-content`.
   * Contraste tipográfico superior a 12:1 no texto principal e superior a 5.2:1 nos botões.
   * Total navegabilidade via teclado: Abas respondem a setas direcionais, Accordion responde a `Enter` e `Espaço`.
   * Área de clique mínima de 44x44px em todos os botões e links interativos.
2. **JavaScript Leve & Sem Frameworks:**
   * Menos de 80 linhas de Vanilla JS nativo incluídas diretamente antes de `</body>`, garantindo execução instantânea e zero dependência de pacotes pesados como React ou Vue para a camada pública de aquisição.
3. **Respeito às Restrições Éticas:**
   * Zero banco de imagens falso com nutricionistas sorrindo e tigelas de salada.
   * Zero emojis usados como elementos de interface.
   * Zero alegações de funcionalidades inexistentes (funcionalidades rotuladas fidedignamente como Disponível ou Beta).
