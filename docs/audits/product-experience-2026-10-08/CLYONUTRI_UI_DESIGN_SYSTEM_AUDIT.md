# ClyoNutri II — Auditoria UI e Design System

Data de avaliação: **08/10/2026 (America/Sao_Paulo)**. Fontes: árvore de trabalho existente, navegação real em `127.0.0.1:8080` e instância isolada `localhost:8081`, banco SQLite novo com as 21 migrações. Nenhuma alteração de código da aplicação ou de registros no Jira.

## Referência de marca e governança

O documento `CLYONUTRI_DESIGN_SYSTEM_PREMIUM.md` versão 1.0.0 está marcado como **Proposta de referência visual para implementação**, não como aprovação definitiva. É a referência disponível; aprovação de marca não foi presumida. A direção navy/cobre, Inter operacional e superfícies claras é coerente com a finalidade descrita. Não há justificativa para trocar a identidade por verde ou pastel.

| Fundação | Referência | Implementação | Decisão |
|---|---|---|---|
| Navy | #0F2747 | CSS #0F2A44; mobile #0F2747 | Ratificar um valor; preservar família e comparar renderização antes de migrar |
| Cobre | #E67A3F / escuro #B94F1F | CSS #E67E3A / escuro correto; mobile #E67A3F | Semântica de ação no navy; cobre como assinatura e foco |
| Success | #18794E | CSS alias #2E7D58 | Separar marca de estado; testar contraste real |
| Fonte | Inter no produto | Inter no shell; chat usa fonte de sistema própria | Unificar fonte operacional; serifada restrita a conteúdo editorial |
| Raios | input 8, botão 10, card 12, modal 16 | Base coincide; estilos locais com 4/6/8/14 | Consolidar por componente, sem arredondar tudo |
| Espaços | escala de 4 a 96 | base razoável; composições e overrides arbitrários | Templates de página e seções com escala única |
| Largura | sidebar 240–256; formulário 640–760 | shell 256; vários grids inline; celular transborda | Preservar desktop e redesenhar comportamento compacto |

Não se recomenda mudar tokens silenciosamente durante esta auditoria. Fazer comparação antes/depois, ratificação e migração visual controlada.

## Componentes e estados

| Área | Evidência / problema | Classificação | Padrão proposto |
|---|---|---|---|
| Sidebar desktop | Reconhecível e regular; Chat ausente | Manter + combinar | Um shell; grupos clínico, biblioteca e operação |
| Header | Nome de clínica/usuário repetido; altura fixa móvel | Simplificar | Contexto de consultório e conta; título/ação no conteúdo |
| Dashboard | Quatro métricas antes do trabalho; quebra 3+1 no viewport observado | Reposicionar | Agenda e pendências primeiro; indicadores secundários contextualizados |
| Cards | Borda/sombra em todos; subcontainers de prioridade dentro de card | Combinar | Uma superfície para seção, linhas e divisórias para itens |
| Buttons | Primary e accent competem; overrides 28/32/36; btn-outline ausente | Redesenhar variantes | Primary, secondary, tertiary, destructive; estados completos |
| Inputs | Base 44px; vários rótulos sem associação | Manter base + reconstruir Field | label, ajuda, requisito, erro, readonly, disabled |
| Selects | Catálogo até 250 em select e ingredientes sem busca | Redesenhar | Combobox acessível de alimentos e pacientes; paginação |
| Tabelas | Boa leitura no pequeno conjunto; sem busca de pacientes; base nutricional ambígua | Simplificar + redesenhar | Toolbar, legenda de base/unidade e detalhes progressivos |
| Modal | Implementações distintas; foco permanece fora | Reconstruir | Dialog compartilhado; estado alterado e foco seguro |
| Badges | Enums crus; badge-danger indefinido na base | Redesenhar | StatusBadge semântico com rótulo português |
| Breadcrumb/contexto | Retornos variáveis; plano volta à lista global | Reposicionar | Pacientes → pessoa → plano, preservando origem |
| Feedback | Mensagens úteis após POST; conclusão pode ser genérica; sem live region clínica | Simplificar | Resultado específico com próximo passo e anúncio acessível |
| Erro | Falha API pode virar HTML 200; cliente ignora evolução | Reconstruir | InlineError + retry por recurso; correlação no suporte |
| Loading | Mobile tem indicador; SSR não precisa skeleton em toda página | Manter onde útil | Pending em submit; prevenir duplo envio; skeleton só em carga assíncrona |
| Empty states | Receitas oferece criar; financeiro só informa ausência | Redesenhar seletivamente | Explicação, origem e ação realmente implementada |
| Ícones | SVG lineares no shell versus emojis no chat/help/clinical | Combinar | Conjunto existente de ícones sem adicionar biblioteca automaticamente |
| Gráficos | Antropometria tem resumo textual e seletor; série preenchida não exercida | Manter intenção / validar | Eixo/unidade/período, tabela equivalente, origem clínica explícita |
| Tooltips | Uso pontual de title; cobertura de teclado não demonstrada | Padronizar | Ajuda que funcione por foco; informação essencial visível |
| Movimento | Transitions e modalFadeIn; CSS operacional sem reduced-motion | Simplificar | 120–200ms apenas feedback; respeitar preferência de movimento |

## Acessibilidade

A inspeção da anamnese encontrou **12 controles sem label associado nem aria-label** na tela com comparador. É evidência concreta de deficiência semântica, não inferência por aparência. Novo Plano e Adicionar Alimento mantêm foco no acionador; falta contrato de diálogo e fundo inerte.

O mobile define texto branco em fundo #E67A3F. Cálculo sRGB da combinação: aproximadamente **2,91:1**; falha tanto para texto normal (4,5:1) quanto grande (3:1). A sugestão é navy ou cobre escuro validado, sem alterar a cor de marca indiscriminadamente. As cores não foram medidas por amostragem de pixels em app nativo; a evidência é o StyleSheet entregue.

44px é alvo confortável de produto e corresponde ao critério de tamanho aprimorado, não é o mínimo universal de WCAG 2.2 AA. O critério 2.5.8 AA admite 24×24 CSS px e exceções de espaçamento; botões de 28px não são automaticamente reprovação. Validar geometria e contexto. [W3C: tamanho mínimo](https://www.w3.org/WAI/WCAG22/Understanding/target-size-minimum.html), [W3C: contraste](https://www.w3.org/WAI/WCAG22/Understanding/contrast-minimum.html).

Pendentes: leitor de tela, todos os estados de validação, 200% de zoom, reflow 320px, orientação, autenticação acessível e navegação nativa. Não declarar conformidade AA com base nesta amostra.

## Responsividade e desempenho

Em 390px o documento mede 461px, logo há overflow global. Navegação ocupa aproximadamente 257px no topo e métricas postergam o trabalho clínico. O grid 2fr/1fr do dashboard não ganha adaptação móvel na implementação local. Não confundir tabela com overflow local necessário e página inteira transbordando.

Fontes Google são carregadas externamente no shell; custo e fallback precisam ser medidos. `PatientApiController::getDailyMealPlan` busca refeições, itens e substituições por laços, padrão N+1 a medir com planos reais. Não houve carga, throttling ou Core Web Vitals de campo; não há números de LCP/INP/CLS confiáveis nesta auditoria.

## Arquitetura de componentes

DashboardController tem **3.642 linhas** com HTML, consultas, validação e scripts. Essa mistura não prova defeito por si só, mas explica duplicação de modais, regras responsivas locais e nomenclatura divergente. Extrair primeiro shell, cabeçalhos, Field, Dialog, StatusBadge, tabelas, resumo clínico e feedback; depois mover consultas para serviços/read models. Preservar rotas e contratos antes de qualquer migração de framework.

Evidências principais: PX-07/08/09/10/13/15/16/21/26 na [matriz](CLYONUTRI_UX_JOURNEY_FINDINGS.md). A aplicação atual usa router PHP próprio; os arquivos `routes/*.php` não são o inventário executado pelo entrypoint. Não aplicar padrões Laravel presumindo um kernel que este runtime não utiliza.
