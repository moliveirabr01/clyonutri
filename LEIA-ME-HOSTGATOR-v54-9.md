# Clyo Nutri v54.9: consistência do design system (Clinical Botanical v2)

- **Pacote:** `clyonutri-v54-9-hostgator.zip`
- **Data:** 02/11/2026
- **Base:** v54.1, a versão em produção.
- **Conteúdo:** traz tudo da v54.8, que ainda não foi instalada, e acrescenta os ajustes de design system pedidos em 02/11.
- **Banco:** sem mudança. **Não há SQL para rodar.**

## O que mudou
| Item | Antes | Depois |
|---|---|---|
| Título (H1) | 5 tamanhos e pesos diferentes (24 a 32 px; 600 ou 700) | 32 px, peso 600, em todas as 21 telas medidas; 24 px no celular |
| Fonte serifada (Playfair) no produto | em Exames, Receitas, Financeiro, Ajuda e Onboarding | Inter em todas as telas logadas; a Playfair fica só na landing |
| Fonte de botões e campos | Arial (padrão do navegador) | herdam a Inter |
| Emojis como ícone | abas de Configurações, "Horários & Regras", refeições, horários, lista de compras, página pública de agendamento e outros | ícones lineares Lucide (`Icon::svg`), sem dependência externa e sem script |
| Botões baixos | de 26 a 38 px | no mínimo 40 px (compacto); os demais seguem os 44 px |
| Cores fixas no código das telas | 1.455 ocorrências: vários cinzas, 5 vermelhos, 4 âmbares, verdes e azuis fora da paleta | 1.120 trocas por tokens (`var(--token, #valor)`); restam 10 ocorrências intencionais, listadas abaixo |
| Tokens que faltavam | `--neutral-gray-600` e `-700` já eram usados (em Exames, por exemplo), mas não estavam definidos | definidos, junto com `-400` e `-50`, as bordas de erro, aviso e sucesso, e os textos fortes de aviso e erro |
| Links de texto | azul padrão do navegador | cobre 600 da paleta |
| Botão "Confirmar cancelamento" (Assinatura) | canto de 6 px | `--radius-button` (10 px) |

O que mudou no visual:
- **Cinzas:** passam ao tom da paleta (#667085, #475467, #344054).
- **Erro:** um só vermelho (#B42318).
- **Aviso:** um só âmbar (#946200), com texto #7A4A00 sobre o fundo âmbar.
- **Contraste:** conferido nas combinações novas. Por exemplo, aviso sobre o fundo âmbar fica em 6,7:1 e erro sobre o fundo vermelho claro em 7,1:1.

As 10 cores fixas que ficaram, de propósito:
- o verde do WhatsApp;
- as cores dentro de atributos de SVG, que não aceitam `var()`, e que já usam os valores da paleta.

## Fora deste pacote
- **Telas que não mudaram:** documentos impressos e em PDF (prontuário, plano, exames), e-mails, o painel administrativo (`/admin`), a landing e as páginas legais. Em PDF e e-mail, `var()` não funciona, e o painel administrativo e a landing têm folha própria.
- **Estilos escritos direto no HTML:** cerca de 2.700 trechos `style="..."` continuam nas telas. Agora usam os tokens, mas ainda não viraram classes. Essa mudança é estrutural e deve ser feita aos poucos.

## Arquivos
- **Novo:** `src/Application/View/Icon.php`, com 28 ícones Lucide (licença ISC).
- **Tokens e regras globais:** `public/assets/css/clinical-botanical.css` (tokens novos, `.cn-icon`, fonte dos campos) e `public/assets/css/clinical-journeys.css` (títulos, botões, links e tokens no próprio CSS).
- **Cores trocadas por tokens:** cerca de 45 telas em `src/Application/Controllers/` e `views/`.
- **Outras folhas de estilo com tokens:** `pre-consulta.css`, `teleconsulta.css` e `patient-self-service.css`.
- **Versão nos endereços:** `botanical-v54-9` e `v54-9` nos endereços de CSS e JS, para o navegador não usar o arquivo antigo do cache.
- **Rodapé:** v54.9.

## Como foi testado (testes fora do zip)
- **Medição em 21 telas a 1280 px:**
  - H1 com 32 px/600 em todas;
  - nenhuma fonte serifada e nenhum emoji pictográfico;
  - botões com 40 px ou mais;
  - 0 erros de JavaScript e 0 violações de CSP.
- **Regressão:**
  - navegador: v54.1 30/30, NI2-609 9/9, NI2-607 14/14, NI2-613 8/8 e NI2-610/612/615 31/31;
  - PHP: 38/38, 6/6, 15/15, 24/24, 71/71, 42/42 e 87/87;
  - `v47_ni2_537` tem a mesma falha da v53.

## Instalação
Siga o LEIA-ME da v54.3 (`LEIA-ME-HOSTGATOR-v54-3.md`), com estas diferenças:
- o rodapé deve mostrar "v54.9";
- confira se os títulos estão do mesmo tamanho em Painel, Planos, Exames e Receitas;
- confira os ícones nas abas de Configurações.

Para as notas das histórias, veja os LEIA-ME da v54.5 à v54.8.

## Rollback
Restaure os arquivos da v54.1. Não há mudança de banco para desfazer.
