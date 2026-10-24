# Tokens do Design System — ClyoNutri

Referência dos tokens visuais da web e do app (NI2-25).

**Onde ficam os valores de verdade**

| Lugar | Arquivo |
|---|---|
| Web (fonte única) | `public/assets/css/clinical-botanical.css`, bloco `:root` |
| App (espelho) | `mobile/src/theme/clinicalBotanical.ts` |
| Fontes | `public/assets/css/fonts.css` + `public/assets/fonts/` |
| Guarda | `tests/ni2_25_design_tokens_test.php` |

O teste compara este documento, o CSS e o arquivo do app. Mudou um valor? Mude nos três, senão o teste quebra.

## Paleta oficial: Clinical Botanical

O Maicon ratificou o Clinical Botanical como paleta oficial da marca. Forest e Clyo Green são a marca, Sage e Cream são superfícies, o cobre é só acento e o verde-azulado (teal) é o sucesso. O DS Premium (Navy/Cobre, PX-21) está **legado/deprecado**: os nomes `--brand-*` continuam existindo só para não quebrar tela antiga, e apontam para os valores Botanical. Código novo usa `--botanical-*`.

Os valores antigos do Premium ficam registrados no fim desta seção, para consulta e para desfazer, se for preciso.

## Cores

### Marca

| Token | Valor | Uso |
|---|---|---|
| `--botanical-forest-950` | `#0A2A10` | fundo mais fundo (rodapé do menu, borda) |
| `--botanical-forest-900` | `#0F3E17` | Forest: menu lateral, títulos, texto forte, seções escuras |
| `--botanical-forest-800` | `#1B5226` | degradê e hover em superfície escura |
| `--botanical-green-700` | `#2F6140` | hover do botão primário |
| `--botanical-green-600` | `#39734A` | Clyo Green: botão primário (texto branco por cima), links, foco de campo |
| `--botanical-sage-300` | `#BFD8C2` | Sage: fundo e enfeite |
| `--botanical-sage-200` | `#D3E5D5` | bordas suaves e texto secundário sobre Forest |
| `--botanical-sage-100` | `#E5F0E5` | fundo de selo, cartão suave |
| `--botanical-cream-50` | `#FAF9F4` | Cream: fundo de página |

Sage `#BFD8C2` sobre branco dá 1,52:1. Serve para fundo e enfeite, **nunca** como borda de campo nem como único sinal de algo (a regra de componentes de interface pede 3:1).

### Cobre (acento restrito)

O cobre não é cor de botão primário. Aparece em detalhes: eyebrow, link de ênfase, selo de destaque, botão de destaque sobre fundo Forest.

| Token | Valor | Uso |
|---|---|---|
| `--botanical-copper-400` | `#F4A06A` | hover do cobre 500 sobre Forest |
| `--botanical-copper-500` | `#EF8F4F` | acento **sobre Forest** (texto, anel de foco, botão com texto Forest) e detalhes decorativos. Sobre branco é só decoração (2,5:1) |
| `--botanical-copper-600` | `#B04A1B` | acento **sobre claro**: texto, link, anel de foco |
| `--botanical-copper-700` | `#8F3D14` | hover do botão de destaque; texto sobre o fundo `copper-100` |
| `--botanical-copper-100` | `#FBEADF` | fundo de selo de destaque |

### Legado do DS Premium (deprecado)

Nomes antigos que ainda aparecem em views e controllers. Cada um é um alias: mudou o `--botanical-*`, muda junto. O valor abaixo é o resolvido.

| Token | Valor | Aponta para |
|---|---|---|
| `--brand-navy-900` | `#0F3E17` | `--botanical-forest-900` |
| `--brand-navy-reference` | `#0F3E17` | `--botanical-forest-900` |
| `--brand-copper-500` | `#EF8F4F` | `--botanical-copper-500` |
| `--brand-copper-600` | `#B04A1B` | `--botanical-copper-600` |
| `--brand-copper-reference` | `#EF8F4F` | `--botanical-copper-500` |
| `--brand-green-500` | `#39734A` | `--botanical-green-600` |
| `--brand-green-200` | `#BFD8C2` | `--botanical-sage-300` |
| `--neutral-warm-50` | `#FAF9F4` | `--botanical-cream-50` |

Valores antigos do Premium (não use em código novo): Navy 900 #0F2747 (referência #0F2A44 na landing e no admin), cobre 500 #E67A3F (#E67E3A em alguns pontos), cobre 600 #B94F1F, verde 500 #2E7D58, verde 200 #A7C7A1, fundo quente #FAF7F2, sucesso #18794E sobre #E8F5E9.

### Neutros

| Token | Valor |
|---|---|
| `--neutral-white` | `#FFFFFF` |
| `--neutral-gray-100` | `#F4F5F6` |
| `--neutral-gray-200` | `#E5E7EB` |
| `--neutral-gray-300` | `#D1D5DB` |
| `--neutral-gray-500` | `#667085` |
| `--neutral-ink-900` | `#1F2937` |

### Semânticos (independentes da marca)

Valores próprios, nunca `var(--brand-*)` nem `var(--botanical-*)`. Sucesso é **teal**, de propósito: o Clyo Green da marca e um "deu certo" em verde ficariam quase iguais e a confirmação pareceria só cor de marca.

| Token | Valor | Fundo claro | Valor |
|---|---|---|---|
| `--semantic-success` | `#0A7075` | `--semantic-success-light` | `#E3F4F2` |
| `--semantic-warning` | `#946200` | `--semantic-warning-light` | `#FEF3C7` |
| `--semantic-error` | `#B42318` | `--semantic-error-light` | `#FEE2E2` |
| `--semantic-info` | `#175CD3` | `--semantic-info-light` | `#EFF6FF` |

Variantes do sucesso:

| Token | Valor | Uso |
|---|---|---|
| `--semantic-success-text` | `#0A5F63` | texto de selo e alerta sobre o fundo claro |
| `--semantic-success-on-dark` | `#4FC3B8` | sucesso sobre Forest (ponto de status do menu). O teal 700 some ali: 2,08:1 |

Regras:

- Status nunca vai só na cor: sempre com texto ou ícone ("Pago", "Atrasado", ícone de alerta).
- O painel admin usa os mesmos valores (`--admin-success` etc. em `views/layouts/admin-shell.php`).
- Prioridade P1 do Service Desk continua laranja (escala de severidade vermelho > laranja > âmbar), não é cobre de marca.

### Contraste conferido (WCAG 2.2)

| Texto | Fundo | Razão |
|---|---|---|
| Forest | branco | 12,20 |
| Forest | Cream | 11,57 |
| tinta 900 | branco | 14,68 |
| cinza 500 | branco | 4,97 |
| branco | Clyo Green (botão) | 5,64 |
| branco | Green 700 (hover) | 7,23 |
| Clyo Green | Cream | 5,35 |
| Clyo Green | Sage claro | 4,81 |
| Forest | Sage | 8,02 |
| cobre 600 | branco | 5,47 |
| cobre 600 | Cream | 5,19 |
| cobre 600 | cobre 100 | 4,67 |
| cobre 700 | cobre 100 | 6,30 |
| Forest | cobre 500 (botão sobre fundo Forest) | 5,06 |
| cobre 500 | Forest | 5,06 |
| cobre 400 | Forest | 5,86 |
| Sage 200 | Forest | 9,26 |
| sucesso | branco / fundo claro | 5,85 / 5,15 |
| sucesso (texto) | fundo claro | 6,53 |
| sucesso sobre escuro | Forest | 5,71 |
| alerta | branco / fundo claro | 5,24 / 4,71 |
| erro | branco / fundo claro | 6,57 / 5,38 |
| info | branco / fundo claro | 5,99 / 5,50 |

Anel de foco: cobre 600 sobre claro (5,47:1 no branco). No menu lateral, que é Forest, o anel usa o cobre 500 (5,06:1); o 600 ficaria em 2,23:1.

Atenção com o laranja de antes: o cobre 500 `#EF8F4F` **não** serve como texto sobre branco nem como fundo de botão com texto branco. Em fundo claro use o cobre 600; em fundo Forest, o 500.

### Logo (pendente)

O logo é imagem e não foi redesenhado. Os SVGs `clyonutri-logo-light.svg`, `clyonutri-logo-dark.svg` e `clyonutri-icon.svg` ganharam só um recolor **temporário** (azul para Forest, cobre para `#EF8F4F`/`#B04A1B`). Os PNGs (`clyonutri-logo*.png`, ícones de app e favicon) continuam com o azul antigo. Falta o Maicon entregar a versão verde oficial do logo (SVG e PNG, claro e escuro, ícone quadrado, favicon).

## Tipografia

| Token | Valor |
|---|---|
| `--font-body` | Inter, com fontes do sistema como reserva |
| `--font-editorial` | Playfair Display, Georgia como reserva |

As duas são servidas pelo próprio domínio (`/assets/fonts/`, licença OFL 1.1). Não carregamos mais nada do Google Fonts: o navegador de quem abre login, cadastro ou qualquer tela logada não fala com servidor de terceiro só para baixar fonte.

No app, Inter só aparece depois de embutida com `expo-font` e de um build novo (pendente). Até lá o app usa a fonte do sistema.

### Escala

| Token | Valor | Uso |
|---|---|---|
| `--font-size-caption` | 0,75rem (12px) | legendas, rótulos pequenos |
| `--font-size-compact` | 0,875rem (14px) | tabelas, texto de apoio |
| `--font-size-body` | 1rem (16px) | texto corrido |
| `--font-size-card-title` | 1,125rem (18px) | título de card |
| `--font-size-h3` | 1,25rem (20px) | h3 |
| `--font-size-h2` | 1,5rem (24px) | h2 |
| `--font-size-h1` | 2rem (32px) | h1 das telas |
| `--font-size-display` | 2,5rem (40px) | título da landing no celular |
| `--font-size-hero` | 4rem (64px) | hero da landing no desktop |

| Token | Valor |
|---|---|
| `--line-height-tight` | 1.25 (títulos) |
| `--line-height-body` | 1.55 (texto) |
| `--font-weight-regular` | 400 |
| `--font-weight-medium` | 500 |
| `--font-weight-semibold` | 600 |
| `--font-weight-bold` | 700 |

Os valores são os que as telas já usavam; agora eles têm nome. Não houve mudança visual.

## Espaçamento

Base de 4px.

| Token | Valor |
|---|---|
| `--space-1` | 4px |
| `--space-2` | 8px |
| `--space-3` | 12px |
| `--space-4` | 16px |
| `--space-5` | 20px |
| `--space-6` | 24px |
| `--space-8` | 32px |
| `--space-10` | 40px |
| `--space-12` | 48px |
| `--space-16` | 64px |
| `--space-20` | 80px |
| `--space-24` | 96px |

Área mínima de toque: `--touch-target-min` = 44px.

## Raios

| Token | Valor |
|---|---|
| `--radius-input` | 8px |
| `--radius-button` | 10px |
| `--radius-card` | 12px |
| `--radius-modal` | 16px |
| `--radius-pill` | 9999px |

## Sombras

Discretas, para separar camadas.

| Token | Uso |
|---|---|
| `--shadow-sm` | cards e campos |
| `--shadow-md` | cards em destaque, menus |
| `--shadow-lg` | painéis flutuantes |
| `--shadow-modal` | modais e diálogos |

## O que ainda está fora dos tokens

Registrado para não se perder:

- **`mobile/src/theme/tokens.ts`** ainda tem os valores Premium antigos. As telas do app importam dele, então a migração para `clinicalBotanical.ts` está pendente e fica no NI2-140. Até lá o app mistura a paleta antiga (telas) com a nova (arquivo `clinicalBotanical.ts`).
- **Logo e ícones em PNG** (ver "Logo (pendente)" acima).
- **Cores escritas direto no código.** Esta correção remapeou o que é da marca (azul, cobre e verde de sucesso) nas 17 views, nos CSS das telas e nos controllers e e-mails com HTML. Slate neutro, vermelho, âmbar, roxo (gráficos), azul de "info" e o verde do WhatsApp ficaram como estavam. O Service Desk (`HelpdeskController`) manteve o laranja da prioridade P1. Vale trocar o restante aos poucos, tela por tela, por `var(--botanical-*)`.
- **Telas com `:root` próprio**, que não carregam `clinical-botanical.css`: landing (`landing-premium.css`), admin (`admin-shell.php`), `/app` (`views/patient/app.php`), `teleconsulta.css`, `pre-consulta.css` e `prontuario-impressao.css`. Os valores já são Botanical, mas continuam em arquivos separados e podem divergir. O teste confere a landing e o admin contra este documento.
- **Telas do app (`mobile/src/screens`)** não foram tocadas (NI2-140).
- Impressão: o prontuário continua em fundo branco, com Forest só em títulos e filetes, para sair bem em impressora comum.
