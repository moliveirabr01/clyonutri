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

## Decisão pendente: qual paleta é a marca

Hoje existem duas paletas no projeto, e elas não combinam:

- **Navy/Cobre (DS Premium, PX-21).** É a que a interface usa de fato, em todas as telas. Está em `CLYONUTRI_DESIGN_SYSTEM_PREMIUM.md`, que ainda está marcado como "Proposta" e pede para evitar verde como cor principal.
- **Clinical Botanical (blueprint, NI2-25).** É a que o card pede: Forest, Clyo Green, Sage e Cream. Até esta correção ela não existia em nenhum arquivo de código.

A NI2-25 deixa a paleta Botanical **documentada e disponível como token** (`--botanical-*`), sem repintar nada. Trocar a cor da marca no produto inteiro é decisão de produto, não de código, e mexe em todas as telas.

Falta o Maicon ratificar uma das duas. Depois disso:

- se ficar **Navy/Cobre**, o card NI2-25 (e o NI2-45, que é duplicado) deve ter o critério de cores atualizado, e os tokens `--botanical-*` ficam só como referência;
- se ficar **Botanical**, os `--brand-*` passam a apontar para os `--botanical-*` e o tom de sucesso precisa mudar (ver abaixo).

## Cores

### Marca ativa (Navy/Cobre)

| Token | Valor | Uso |
|---|---|---|
| `--brand-navy-900` | `#0F2747` | títulos, menu lateral, texto forte |
| `--brand-copper-500` | `#E67A3F` | fundo de botão primário (texto navy por cima), detalhes |
| `--brand-copper-600` | `#B94F1F` | texto e links em cobre, anel de foco |
| `--brand-green-500` | `#2E7D58` | reservado (hoje sem uso) |
| `--brand-green-200` | `#A7C7A1` | reservado (hoje sem uso) |

O cobre 500 **não serve como texto** sobre branco (2,91:1). Para texto em cobre use o 600.

### Paleta Clinical Botanical

| Token | Nome no blueprint | Valor |
|---|---|---|
| `--botanical-forest-900` | Forest | `#0F3E17` |
| `--botanical-green-600` | Clyo Green | `#39734A` |
| `--botanical-sage-300` | Sage | `#BFD8C2` |
| `--botanical-sage-100` | Sage claro | `#E5F0E5` |
| `--botanical-cream-50` | Cream | `#FAF9F4` |

Sage `#BFD8C2` sobre branco dá 1,52:1. Serve para fundo e enfeite, **nunca** como borda de campo nem como único sinal de algo (a regra de componentes de interface pede 3:1).

### Neutros

| Token | Valor |
|---|---|
| `--neutral-warm-50` | `#FAF7F2` |
| `--neutral-white` | `#FFFFFF` |
| `--neutral-gray-100` | `#F4F5F6` |
| `--neutral-gray-200` | `#E5E7EB` |
| `--neutral-gray-300` | `#D1D5DB` |
| `--neutral-gray-500` | `#667085` |
| `--neutral-ink-900` | `#1F2937` |

### Semânticos (independentes da marca)

Valores próprios, nunca `var(--brand-*)`. Assim, se a marca mudar, erro continua vermelho e sucesso continua verde.

| Token | Valor | Fundo claro | Valor |
|---|---|---|---|
| `--semantic-success` | `#18794E` | `--semantic-success-light` | `#E8F5E9` |
| `--semantic-warning` | `#946200` | `--semantic-warning-light` | `#FEF3C7` |
| `--semantic-error` | `#B42318` | `--semantic-error-light` | `#FEE2E2` |
| `--semantic-info` | `#175CD3` | `--semantic-info-light` | `#EFF6FF` |

Regras:

- Status nunca vai só na cor: sempre com texto ou ícone ("Pago", "Atrasado", ícone de alerta).
- O painel admin usa os mesmos valores (`--admin-success` etc. em `views/layouts/admin-shell.php`). Os tons Tailwind 600 que estavam lá ficavam abaixo de 4,5:1 como texto.
- **Se a marca virar Botanical:** o sucesso `#18794E` e o Clyo Green `#39734A` ficam praticamente iguais (1,04:1 entre eles). Nesse caso o sucesso precisa de outro tom, ou a confirmação vai parecer só "cor da marca".

### Contraste conferido (WCAG 2.2)

| Texto | Fundo | Razão |
|---|---|---|
| navy 900 | branco | 14,98 |
| navy 900 | warm 50 | 14,02 |
| tinta 900 | branco | 14,68 |
| cinza 500 | branco | 4,97 |
| cinza 500 | cinza 100 | 4,56 |
| cobre 600 | branco | 5,00 |
| navy 900 | cobre 500 (botão) | 5,15 |
| Forest | branco | 12,20 |
| Forest | Cream | 11,57 |
| Clyo Green | branco | 5,64 |
| Clyo Green | Cream | 5,35 |
| Clyo Green | Sage claro | 4,81 |
| Forest | Sage | 8,02 |
| sucesso | branco / fundo claro | 5,41 / 4,81 |
| alerta | branco / fundo claro | 5,24 / 4,71 |
| erro | branco / fundo claro | 6,57 / 5,38 |
| info | branco / fundo claro | 5,99 / 5,50 |

Anel de foco: cobre 600 (5,00:1 sobre branco). No menu lateral, que é navy, o anel usa o cobre 500 (5,15:1); o 600 ficaria em 3,00:1, no limite.

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

Isto não foi mexido na NI2-25. Fica registrado para não se perder:

- Há muita cor escrita direto no código: perto de 345 ocorrências em `views/`, 582 em `src/Application`, 143 nos CSS e 170 no app (só `PatientHomeScreen` tem 72). Vale trocar aos poucos, tela por tela.
- `views/patient/app.php` tem um `:root` próprio com cores Tailwind e `mobile/src/theme/tokens.ts` está com valores antigos. Os dois estão sendo tratados na NI2-140.
- `teleconsulta.css`, `pre-consulta.css` e `prontuario-impressao.css` repetem um `:root` com os mesmos valores. Não divergem hoje, mas podem divergir.
- O painel admin tem paleta própria (navy e laranja Tailwind). Só os semânticos foram alinhados.
