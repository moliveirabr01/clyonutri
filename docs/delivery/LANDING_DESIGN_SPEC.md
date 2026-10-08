# ClyoNutri II — Especificação de Design de Experiência (Landing Design Spec)
**Fase B — Arquitetura de Informação, Composição Visual & Especificação de Interface**  
**Data:** 08/10/2026 | **Versão:** 1.0.0 | **Status:** Aprovado para Implementação

---

## 1. Arquitetura da Informação & Narrativa de Conversão

A landing page foi desenhada em **13 seções orquestradas** para conduzir o nutricionista por uma jornada de descoberta, identificação, encantamento técnico e decisão de cadastro:

```
[1. Header Premium] ➔ [2. Hero de Alto Impacto] ➔ [3. O Problema Real da Rotina]
       │
[4. A Solução Integrada (3 Pilares)] ➔ [5. Showcase Interativo do Software]
       │
[6. A Conexão com o Paciente] ➔ [7. Como Funciona (3 Passos)]
       │
[8. Diferenciais Clínicos & Segurança] ➔ [9. Filosofia Clínica & Compromisso Ético]
       │
[10. Acesso e Teste Transparente] ➔ [11. FAQ Acessível] ➔ [12. CTA Final]
       │
[13. Rodapé Institucional]
```

---

## 2. Detalhamento de Cada Seção

### Seção 1 — Header Premium
* **Layout:** Altura de 76px, container max-width 1200px, alinhamento flex com espaço entre extremidades.
* **Comportamento:** Fixo no topo (`position: sticky; top: 0; z-index: 100;`), fundo translúcido com `backdrop-filter: blur(12px)` e borda inferior sutil `#E5E7EB`.
* **Itens de Navegação:**
  * Logo ClyoNutri com ícone estilizado da marca em Navy `#0F2747` e detalhe em Copper `#E67A3F`.
  * Links: *Visão Geral*, *Funcionalidades*, *Plataforma*, *App do Paciente*, *Diferenciais*, *Dúvidas*.
  * Ações: Link *"Entrar"* (leva a `/login`) e Botão Primário *"Começar Grátis"* (leva a `/cadastro`).
* **Mobile:** Botão hamburguer acessível (`aria-expanded`, `aria-controls`, `aria-label`) abrindo drawer lateral com navegação completa por teclado.

### Seção 2 — Hero de Alto Impacto
* **Composição Editorial:**
  * **Badge Superior:** Pílula neutra com borda sutil: `✦ A plataforma moderna para nutricionistas clínicos`.
  * **Headline (H1):** *"Sua nutrição merece uma plataforma à altura."* em `Playfair Display`, 56px desktop / 36px mobile, Navy `#0F2747`.
  * **Subheadline:** *"Centralize prontuários, prescreva planos alimentares estruturados com base TACO e acompanhe a evolução dos seus pacientes em uma experiência fluida, precisa e sem burocracia."* em `Inter`, 18px, cor `#526071`.
  * **Grupo de CTAs:**
    * Primário: `"Começar Gratuitamente"` (botão Copper de alto contraste com seta direcional).
    * Secundário: `"Explorar a Plataforma"` (botão outline Navy com scroll para `#funcionalidades`).
  * **Micro-garantias:** *"14 dias de teste completo • Sem cartão de crédito • Pronto em 1 minuto"*.
* **Elemento Protagonista:** Demonstração visual de alta resolução da interface real do Dashboard e Perfil 360 do paciente, com dados clínicos verossímeis, gráficos de evolução antropométrica e indicadores de aderência.

### Seção 3 — O Problema Real da Rotina Clínica
* **Título:** *"Seu tempo deveria estar com seus pacientes. Não com a burocracia."*
* **Narrativa:** Dores reais e verificadas do consultório de nutrição:
  * Informações dispersas entre prontuários em papel, planilhas e mensagens de WhatsApp.
  * Planos alimentares complexos que demandam horas de digitação e cálculos manuais propensos a erro.
  * Dificuldade de mensurar se o paciente está realmente aderindo à prescrição após sair do consultório.

### Seção 4 — Solução Integrada (Os Três Pilares Clínicos)
* **Pilar 1 — Organize:** Gestão centralizada de pacientes, agenda de atendimentos, histórico de consultas e dados cadastrais protegidos pela LGPD.
* **Pilar 2 — Prescreva:** Editor inteligente de planos alimentares, base de alimentos canônica TACO/IBGE, equivalentes calóricos e opções de substituições autorizadas.
* **Pilar 3 — Acompanhe:** Histórico antropométrico (peso, circunferências, IMC), adesão ao plano alimentar e registros de diário alimentar e hidratação.

### Seção 5 — Showcase do Produto (Demonstração da Interface Real)
* **Apresentação em Abas Interativas Acessíveis (`role="tablist"`):**
  1. *Perfil 360 do Paciente:* Linha do tempo clínica, dados antropométricos e histórico consolidado.
  2. *Prescrição Nutricional TACO:* Editor de refeições por horários com cálculo automático instantâneo de kcal e macronutrientes.
  3. *Agenda & Consultório:* Visão diária de compromissos com confirmações de presença e recebíveis.
  4. *Aplicativo Conectado:* Visão do paciente com plano do dia e registro de água.

### Seção 6 — Experiência do Paciente
* **Conceito:** *"O cuidado continua depois da consulta."*
* **Destaques:** Acesso ao plano pelo celular, visualização clara das refeições, substituições aprovadas pelo profissional, registro de hidratação e histórico alimentar sem planilhas confusas.

### Seção 7 — Como Funciona (Jornada em 3 Etapas)
1. *Prepare seu Consultório:* Cadastre seu perfil e personalize suas preferências de atendimento.
2. *Atenda e Prescreva:* Realize a anamnese, avalie medidas e publique o plano alimentar em minutos.
3. *Acompanhe a Evolução:* Receba dados reais de adesão e observe o progresso longitudinal do paciente.

### Seção 8 — Diferenciais Técnicos e de Segurança
* **Precisão Científica Canônica:** Conversão proporcional exata a partir da base oficial de 100g.
* **Prontuário Auditável com Hash SHA-256:** Registros clínicos com trilha de integridade imutável.
* **Segurança e Privacidade por Concepção:** Arquitetura multitenant com isolamento criptográfico e conformidade estrita com a LGPD.

### Seção 9 — Filosofia de Produto & Prática Ética
* Declaração editorial autêntica sobre o compromisso com a ciência da nutrição, sem depoimentos forjados nem métricas artificiais de marketing.

### Seção 10 — Acesso Transparente & Condições Claras
* Período de teste completo de 14 dias sem necessidade de dados de pagamento.
* Informações honestas sobre planos futuros com foco na autonomia do nutricionista.

### Seção 11 — FAQ Acessível (Perguntas Frequentes)
* Accordion com `aria-expanded` e controle total por teclado respondendo às dúvidas mais comuns de novos usuários.

### Seção 12 — CTA Final de Alta Conversão
* Box editorial com fundo Navy `#0F2747`, tipografia clara e botão Copper: *"Mais tempo para cuidar. Mais clareza para evoluir."*

### Seção 13 — Rodapé Institucional
* Links funcionais, política de privacidade, termos de uso, indicação de conformidade e direitos autorais.

---

## 3. Especificações Técnicas de Estilo & Acessibilidade

### 3.1. Variáveis de Cor & Contraste WCAG 2.2 AA
* Texto escuro sobre fundo `#FAF7F2`: Contraste de **12.4:1** (superior ao mínimo de 4.5:1 exigido).
* Botão Copper `#E67A3F` com texto escuro `#0F2747`: Contraste de **5.2:1** (Aprovado AA).
* Botão Navy `#0F2747` com texto branco `#FFFFFF`: Contraste de **15.8:1** (Aprovado AAA).

### 3.2. Breakpoints Responsivos Obrigatórios
* **Desktop Amplo:** 1440px / 1280px (container centralizado em 1200px com margem automática).
* **Tablet / Laptop:** 1024px / 768px (grid colapsando para 2 colunas, navegação adaptada).
* **Mobile:** 390px / 375px (fluxo vertical fluído, alvos de toque mínimos de 44x44px).
