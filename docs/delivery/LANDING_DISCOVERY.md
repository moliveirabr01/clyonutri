# ClyoNutri II — Documento de Descoberta (Landing Discovery)
**Fase A — Auditoria do Produto, Arquitetura e Estratégia de Conversão**  
**Data:** 08/10/2026 | **Versão:** 1.0.0 | **Status:** Aprovado para Implementação

---

## 1. Auditoria da Estrutura Atual do Projeto

### 1.1. Arquitetura e Stack Real (vs. Planejada)
* **Arquitetura Real em Produção:** Monólito modular em PHP 8.2+ com roteamento HTTP PSR-4 (`src/Core/Http/Router.php`), banco de dados relacional com suporte dual SQLite (desenvolvimento/testes) e MySQL 8 (produção HostGator), views server-rendered em `views/` e API REST autenticada com tokens Bearer padrão Sanctum em `/api/v1/*`.
* **Frontend Existente:** Utiliza o Design System Premium documentado em `CLYONUTRI_DESIGN_SYSTEM_PREMIUM.md` e estilos em `public/assets/css/clinical-botanical.css`.
* **Situação da Rota Raiz (`/`):** Anteriormente, a rota `/` em `public/index.php` continha um redirecionamento forçado para `/login` quando o usuário não estava logado. Não existia uma Landing Page institucional pública, o que provocava uma barreira abrupta para novos visitantes e eliminava a indexação orgânica e o funil de aquisição.

---

## 2. Matriz de Disponibilidade de Funcionalidades (Auditoria 360)

Para cumprir a regra inegociável de **Truth in Advertising (Zero Falsas Promessas)**, o catálogo de funcionalidades do ClyoNutri II foi minuciosamente auditado no código-fonte e classificado em 4 níveis:

| Módulo / Funcionalidade | Status Real | Evidência Técnica no Repositório | Como Será Apresentado na Landing Page |
|:---|:---:|:---|:---|
| **Cadastro & Onboarding de Nutricionista** | **AVAILABLE** | `AuthController.php`, `/cadastro`, validação de CRN, hash de senha | **Destaque Principal:** CTA de criação de conta gratuita imediata |
| **Login & Sessão Segura** | **AVAILABLE** | `AuthController.php`, `/login`, proteção CSRF, isolamento de tenant | Acesso direto ao consultório para usuários existentes |
| **Dashboard Clínico & Agenda** | **AVAILABLE** | `DashboardController.php`, `/dashboard`, `/agenda`, consultas do dia | **Showcase Hero:** Visualização da rotina organizada e ágil |
| **Prontuário & Perfil Clínico 360** | **AVAILABLE** | `PatientRepository.php`, `/pacientes/{id}`, histórico longitudinal | **Showcase Central:** Centralização de anamnese, peso e evolução |
| **Cálculo Nutricional & Base TACO** | **AVAILABLE** | `NutritionCalculator.php`, `/alimentos`, 100% canônico de 100g para porção | **Pilar Prescreva:** Precisão científica e rapidez de cálculo |
| **Prescrição & Planos Alimentares** | **AVAILABLE** | `MealPlanService.php`, `/planos`, refeições, itens e substituições | **Pilar Prescreva:** Prescrição estruturada sem retrabalho em Word/PDF |
| **Integridade de Prontuário SHA-256** | **AVAILABLE** | `ClinicalRecordService.php`, cadeia criptográfica append-only e LGPD | **Diferencial de Segurança:** Prontuário juridicamente auditável |
| **Gestão Financeira Essencial** | **AVAILABLE** | `FinanceService.php`, `/financeiro`, recebíveis e controle de sessões | **Pilar Organize:** Visão clara de pagamentos sem planilhas soltas |
| **Aplicativo do Paciente (API & Mobile)** | **BETA** | `PatientApiController.php`, endpoints `/api/v1/patient/*`, React Native | **Showcase Experiência do Paciente:** Apresentado como aplicativo conectado (plano, água e diário) |
| **Inteligência Artificial Assistiva** | **BETA** | `ConsultationSummaryAssistant.php`, sugestões clínicas supervisionadas | Apresentado com transparência como recurso assistivo supervisionado |
| **Integração com Wearables (Apple Health)** | **PLANNED** | Roadmap 2026–2027 documentado no Blueprint | Não prometido como disponível; listado no roadmap de evolução |
| **Checkout de Cartão Automatizado** | **PLANNED** | Gateway de pagamento em implementação | Substituído por teste gratuito real sem cartão de crédito |

---

## 3. Direção Criativa & Identidade Visual: "Precision Meets Care"

A landing page deve traduzir a intersecção exata entre a **precisão clínica e científica** (necessária para a conduta nutricional baseada em evidências) e o **cuidado humano caloroso** (essência do relacionamento profissional-paciente).

### 3.1. Tokens Oficiais Aplicados
* **Navy Profundo (`#0F2747`):** Transmite autoridade, robustez técnica, estabilidade e solidez institucional.
* **Cobre Assinatura (`#E67A3F` / `#B94F1F`):** Ponto de calor humano, dinamismo e conversão. Utilizado com estrito cumprimento de contraste WCAG 2.2 AA.
* **Warm Neutral (`#FAF7F2`):** Fundo editorial acolhedor, eliminando a frieza hospitalar de fundos brancos puros estéreis.
* **Tipografia Editorial Dual:**
  * **Headlines Editoriais:** `Playfair Display` (serifada, refinada, proporções clássicas, peso 500/600).
  * **Interface, Navegação e Corpo:** `Inter` (sans-serif geométrica, legibilidade cirúrgica, números tabulares).

---

## 4. Análise de Referências Globais SaaS

| Referência | Princípios Extraídos | O que NÃO copiar |
|:---|:---|:---|
| **Linear.app** | Foco obsessivo no produto como protagonista, contraste tipográfico afiado, microinterações sutis, teclado visível | Não usar paleta escura (dark mode exclusivo) como padrão; nosso público valoriza a clareza e o acolhimento diurno |
| **Stripe.com** | Hierarquia de títulos impecável, diagramas funcionais explicativos, espaçamento generoso, confiança imediata | Evitar gradientes complexos excessivos de malha e linguagem corporativa bancária |
| **Notion.com** | Simplicidade na apresentação de fluxos, clareza modular, conexão intuitiva de funcionalidades | Não usar ilustrações cartum pretensiosas ou layouts excessivamente desestruturados |
| **Framer.com** | Transições de exibição de software limpas, sensação tátil do produto, sofisticação visual | Evitar efeitos visuais pesados que degradem o Largest Contentful Paint (LCP) |
| **Raycast.com** | Sensação de velocidade e precisão de ferramenta profissional (ferramenta de alto rendimento) | Evitar foco puramente em atalhos para desenvolvedores; focar no fluxo de trabalho clínico |

---

## 5. Estratégia de Conversão & Jornada do Usuário

### 5.1. Ponto de Conversão Primário
* **CTA Principal:** `"Começar Gratuitamente"` direcionando para `/cadastro`.
* **Proposta:** 14 dias de teste completo e irrestrito com acesso imediato ao dashboard, sem exigência de cartão de crédito.
* **Redução de Atrito:** Cadastro simplificado em 1 minuto solicitando apenas nome, e-mail, senha e CRN.

### 5.2. Ponto de Conversão Secundário
* **CTA Secundário:** `"Ver Demonstração"` (scroll suave com âncora para a seção interativa de demonstração do produto `#demonstracao`).
* **CTA para Clínicas:** `"Falar com Consultor Clínico"` (abertura de modal acessível ou link direto para agendamento de onboarding institucional).

---

## 6. Mapeamento de Rotas Canônicas e Links
* `/` ➔ Landing Page Oficial ClyoNutri II (Pública)
* `/cadastro` ➔ Fluxo oficial de Onboarding e Criação de Conta
* `/login` ➔ Acesso ao consultório para profissionais cadastrados
* `/termos` ➔ Termos de Uso e Condições Gerais de Contratação
* `/privacidade` ➔ Política de Privacidade e Governança LGPD
