# ClyoNutri — Design System Premium

**Versão:** 1.0.0  
**Data:** 07/10/2026  
**Status:** Proposta de referência visual para implementação  
**Produto:** ClyoNutri — SaaS para nutricionistas

---

## 1. Visão de marca

ClyoNutri é uma plataforma profissional para nutricionistas que combina gestão de consultório, acompanhamento de pacientes e planejamento alimentar. A identidade visual deve transmitir **sofisticação, confiança, cuidado e clareza**.

**Posicionamento:** tecnologia premium para profissionais de nutrição.  
**Personalidade:** confiante, contemporânea, humana, precisa e elegante.  
**Promessa visual:** menos ruído, mais foco no que importa.

### O que queremos evitar

- Interfaces genéricas de dashboards repletas de cartões e indicadores sem hierarquia.
- Verde como cor principal apenas por associação óbvia com alimentação saudável.
- Grandes áreas em tons pastel sem contraste.
- Laranja saturado em todos os elementos.
- Excesso de bordas, sombras, gradientes, ícones decorativos ou fotografias genéricas.
- Misturar a expressividade da landing page com a densidade do software operacional.

## 2. Princípios de design

1. **Elegância funcional:** cada elemento existe para facilitar uma tarefa real.
2. **Hierarquia antes de decoração:** tamanho, contraste e respiro organizam a experiência.
3. **Premium não é ornamental:** refinamento vem de consistência, precisão e qualidade.
4. **Cor com intenção:** o cobre/laranja destaca ações; não domina o produto.
5. **Acessibilidade desde o início:** contraste, foco, teclado, estados e linguagem clara.
6. **Jornadas primeiro:** agenda, pacientes, evolução e plano alimentar guiam o design.

## 3. Paleta de cores

### 3.1 Tokens de marca

| Token | Hex | Aplicação |
|---|---|---|
| `brand.navy.900` | `#0F2747` | Marca, navegação, títulos de destaque |
| `brand.copper.500` | `#E67A3F` | Cor de assinatura, CTA e destaques selecionados |
| `brand.copper.600` | `#B94F1F` | Texto e ações em superfícies claras, sujeito a validação de contraste |
| `neutral.warm.50` | `#FAF7F2` | Fundos editoriais da landing |
| `neutral.white` | `#FFFFFF` | Superfícies do produto |
| `neutral.gray.100` | `#F4F5F6` | Plano de fundo operacional |
| `neutral.gray.200` | `#E5E7EB` | Divisórias e bordas |
| `neutral.gray.500` | `#667085` | Texto secundário |
| `neutral.ink.900` | `#1F2937` | Texto principal |

**Regra:** texto branco sobre `brand.copper.500` não deve ser presumido acessível. Para botões com fundo cobre, usar texto `#0F2747` quando o contraste for validado, ou selecionar um cobre mais escuro com texto branco. Testar todas as combinações efetivas segundo WCAG 2.2 AA.

### 3.2 Cores semânticas (não confundir com a identidade de marca)

| Token | Hex | Significado |
|---|---|---|
| `semantic.success` | `#18794E` | Concluído, confirmado |
| `semantic.warning` | `#946200` | Atenção, pendência |
| `semantic.error` | `#B42318` | Falha, campo inválido |
| `semantic.info` | `#175CD3` | Informação ou ajuda |

Sempre associar cor a texto, ícone ou rótulo: nunca depender exclusivamente da cor.

### 3.3 Proporção indicativa de uso

- **75–85%:** branco e neutros claros.
- **10–20%:** azul-marinho e texto escuro.
- **Até 5%:** cobre nos pontos de ação e expressão da marca.

Proporções orientativas, não limites rígidos.

## 4. Tipografia

### Landing page / comunicação institucional

- **Títulos editoriais:** `Playfair Display`, serif.
- **Texto corrido, botões e navegação:** `Inter`, sans-serif.
- Usar a serifada somente onde há espaço para expressão de marca: hero, chamadas e citações.

### Produto autenticado

- **Fonte principal:** `Inter`.
- **Números e indicadores:** `Inter` com números tabulares (`font-variant-numeric: tabular-nums`).
- Não usar Playfair em tabelas, formulários, menus, números de prontuário ou telas densas.

### Escala tipográfica sugerida

| Papel | Desktop | Mobile | Peso | Line-height |
|---|---:|---:|---:|---:|
| Hero | 64px | 40px | 500 | 1.08 |
| H1 | 40px | 32px | 600 | 1.15 |
| H2 | 30px | 26px | 600 | 1.2 |
| H3 | 24px | 22px | 600 | 1.3 |
| Título de card | 18px | 18px | 600 | 1.4 |
| Body | 16px | 16px | 400 | 1.55 |
| Body compacto | 14px | 14px | 400 | 1.5 |
| Caption | 12px | 12px | 500 | 1.4 |

Para landing, Hero e H1 podem usar Playfair. No sistema, H1/H2/H3 usam Inter.

## 5. Espaçamento, grid e forma

### Escala de espaçamento

`4 / 8 / 12 / 16 / 20 / 24 / 32 / 40 / 48 / 64 / 80 / 96px`

| Token | Valor |
|---|---:|
| `space.1` | 4px |
| `space.2` | 8px |
| `space.3` | 12px |
| `space.4` | 16px |
| `space.6` | 24px |
| `space.8` | 32px |
| `space.12` | 48px |
| `space.16` | 64px |

### Layout

- Landing: largura máxima de conteúdo de **1200px**, margens laterais responsivas; hero assimétrico com texto editorial e fotografia autoral.
- Aplicação web: sidebar **240–256px** (expandida), área de conteúdo fluida, até **1440px** quando útil.
- Formulários: largura de leitura recomendada de **640–760px**; evitar campos muito longos.
- Breakpoints iniciais: `sm=640px`, `md=768px`, `lg=1024px`, `xl=1280px` (ajustar com testes reais).

### Raios e bordas

- Campo: `8px`.
- Botão: `10px`.
- Card: `12px`.
- Modal: `16px`.
- Pílula/badge: `999px`.
- Bordas: `1px solid #E5E7EB`.
- Sombras discretas e raras; priorizar borda e espaçamento.

## 6. Iconografia e imagens

- Ícones lineares, consistentes, preferencialmente **Lucide**.
- Traço recomendado: **1.75–2px**; tamanhos usuais **16, 20 e 24px**.
- Ícones representam significado ou ação; evitar ilustrações decorativas nos fluxos clínicos.
- Fotografia institucional: iluminação natural, ambientes reais, materiais e alimentos em composições editoriais, profissionais diversos.
- Evitar fotos de bancos de imagem excessivamente posadas, pratos hiper-saturados e objetos esticados.
- Nunca distorcer imagens: utilizar proporção definida e `object-fit: cover`, com recorte intencional e texto alternativo.

## 7. Componentes fundamentais

### Botões

| Variante | Uso | Estilo |
|---|---|---|
| Primary | Ação principal da tela | Fundo navy, texto branco; alternativa cobre escuro validada |
| Secondary | Ação complementar | Fundo branco, borda cinza, texto navy |
| Tertiary | Ação de baixa ênfase | Texto navy, sem superfície |
| Destructive | Remoção e ações de risco | Vermelho semântico e confirmação adequada |

- Altura: **44px** padrão; **40px** compacto; área mínima confortável para toque.
- Estados: default, hover, focus-visible, active, disabled, loading.
- Apenas uma ação visualmente primária por contexto de decisão.

### Campos e formulários

- Rótulo visível acima do campo; placeholder apenas como exemplo.
- Altura de input: **44px**.
- Texto de ajuda quando necessário; erros específicos junto ao campo.
- Validação não depende só de cor; expor erros com texto e semântica acessível.
- Para cadastro e prontuário, salvar progresso quando apropriado e explicar requisitos obrigatórios.

### Cards

- Superfícies brancas com borda sutil.
- Padding habitual: **20–24px**.
- Título e conteúdo com hierarquia; evitar métricas sem contexto.
- Não transformar toda informação em card: tabelas e listas são melhores em muitos fluxos.

### Tabelas

- Cabeçalho explícito; ordenação e filtros claros.
- Densidade confortável; cabeçalho fixo quando necessário.
- Linha clicável somente com affordance e acessibilidade adequadas.
- Em mobile, priorizar conteúdo essencial e detalhes progressivos; não esmagar colunas.

### Navegação

- Sidebar para módulos principais no desktop.
- Navegação simplificada no mobile, priorizando agenda, pacientes e plano alimentar.
- Estado ativo combina cor, peso e indicador visual não cromático.
- Breadcrumb em telas profundas; títulos de tela claros.

### Feedback

- Toast para sucesso discreto; mensagem persistente em erros que exigem ação.
- Skeleton apenas quando melhora compreensão do carregamento.
- Empty states com explicação e próximo passo.
- Modal apenas para decisões que requerem foco.

## 8. Diretrizes por contexto

### 8.1 Landing page — linguagem editorial premium

**Objetivo:** transmitir confiança, valor e diferenciação.

Estrutura sugerida:

1. Cabeçalho leve com logotipo, funcionalidades, planos e acesso.
2. Hero assimétrico, headline forte e fotografia real de uma nutricionista.
3. Prova visual do produto com screenshot autêntico, sem distorções.
4. Benefícios centrados na rotina do profissional.
5. Demonstração de jornadas: agendar, avaliar, prescrever e acompanhar.
6. Segurança, privacidade e informações regulatórias apresentadas sem promessas infundadas.
7. Planos transparentes, FAQ e CTA final.

**Exemplo de headline:** “Mais tempo para o que realmente importa.”  
**Texto de apoio:** “Organize sua prática, acompanhe a evolução dos pacientes e entregue um cuidado mais próximo, em uma só plataforma.”

### 8.2 Dashboard — precisão operacional

**Objetivo:** mostrar o que precisa de atenção e permitir agir rapidamente.

Ordem recomendada:

1. Próximas consultas e atividades do dia.
2. Pendências reais: planos a revisar, mensagens, retornos.
3. Acesso rápido a pacientes e planos alimentares.
4. Métricas úteis contextualizadas (apenas quando os dados existirem e forem confiáveis).

Evitar números ilustrativos em produção. Gráficos devem ter eixos, período, legendas e estados vazios adequados.

### 8.3 Jornada clínica

Fluxo de referência: **Paciente → Avaliação → Plano alimentar → Acompanhamento → Evolução**.

- Dar destaque a dados essenciais e contexto longitudinal.
- Definir claramente o estado de cada avaliação/plano: rascunho, pendente, finalizado etc.
- Tornar ações de edição e revisão visíveis.
- Diferenciar ações clínicas de tarefas administrativas.
- Priorizar precisão, rastreabilidade e privacidade de dados sensíveis.

## 9. Motion e microinterações

- Transições discretas de **120–200ms** em ações comuns.
- Preferir opacidade e pequenos deslocamentos a animações longas.
- Respeitar `prefers-reduced-motion`.
- Não animar indicadores clínicos de modo que gere leitura enganosa.

## 10. Acessibilidade e responsividade

- Alvo: **WCAG 2.2 AA**.
- Contraste mínimo: **4.5:1** para texto normal e **3:1** para texto grande, quando aplicável; componentes e indicadores também requerem contraste apropriado.
- Foco visível, navegação por teclado, rótulos semânticos e mensagens de erro descritivas.
- Zoom de 200% e reflow sem perda de funcionalidade.
- Validar interações com leitor de tela e dispositivos móveis reais.
- Nunca comunicar estado clínico apenas por cor.

## 11. Tokens CSS de partida

```css
:root {
  --color-navy: #0F2747;
  --color-copper: #E67A3F;
  --color-copper-dark: #B94F1F;
  --color-warm-bg: #FAF7F2;
  --color-surface: #FFFFFF;
  --color-app-bg: #F4F5F6;
  --color-border: #E5E7EB;
  --color-text: #1F2937;
  --color-text-muted: #667085;
  --color-success: #18794E;
  --color-warning: #946200;
  --color-error: #B42318;
  --color-info: #175CD3;

  --font-body: 'Inter', system-ui, sans-serif;
  --font-editorial: 'Playfair Display', Georgia, serif;

  --radius-input: 8px;
  --radius-button: 10px;
  --radius-card: 12px;
  --radius-modal: 16px;
  --border-default: 1px solid var(--color-border);

  --space-1: 4px;
  --space-2: 8px;
  --space-3: 12px;
  --space-4: 16px;
  --space-6: 24px;
  --space-8: 32px;
  --space-12: 48px;
  --space-16: 64px;
}

body {
  font-family: var(--font-body);
  color: var(--color-text);
  background: var(--color-app-bg);
}

.marketing-hero__title {
  font-family: var(--font-editorial);
  color: var(--color-navy);
}

.button-primary {
  background: var(--color-navy);
  color: #fff;
  border-radius: var(--radius-button);
  min-height: 44px;
}

:focus-visible {
  outline: 3px solid var(--color-copper-dark);
  outline-offset: 3px;
}

@media (prefers-reduced-motion: reduce) {
  *, *::before, *::after {
    animation-duration: 0.01ms !important;
    transition-duration: 0.01ms !important;
  }
}
```

## 12. Governança e critérios de aceite

Antes de considerar uma tela aderente ao design system:

- [ ] Usa tokens de cor, espaço, tipo e raio; não introduz cores arbitrárias.
- [ ] Possui hierarquia clara de título, conteúdo e ação primária.
- [ ] Tem estados completos de interação, carregamento, erro, vazio e sucesso.
- [ ] Funciona em desktop e mobile sem conteúdo cortado ou imagens deformadas.
- [ ] Cumpre critérios aplicáveis de WCAG 2.2 AA com validação real.
- [ ] Mantém dados clínicos legíveis e não usa cor isoladamente para significado.
- [ ] Respeita consistência entre agenda, pacientes, avaliações e planos alimentares.
- [ ] Usa textos coerentes com tom profissional, sem promessas clínicas não fundamentadas.
- [ ] Foi revisada em comparação com componentes oficiais e fluxos reais.

## 13. Próximos artefatos recomendados

1. Biblioteca de componentes e variantes em Figma.
2. Tokens versionados para web e mobile.
3. Templates de landing, login, onboarding, dashboard, paciente, avaliação, plano alimentar e evolução.
4. Matriz de estados, responsividade e acessibilidade por componente.
5. Guia de conteúdo e padrões de UX writing.

---

**Decisão de design:** ClyoNutri adota uma direção **premium editorial + software clínico contemporâneo**: azul-marinho como base de confiança, cobre como assinatura de marca, superfícies claras e tipografia criteriosa. A landing expressa personalidade; a aplicação prioriza foco e eficiência.
