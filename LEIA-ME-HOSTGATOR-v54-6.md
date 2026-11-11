# Clyo Nutri v54.6: NI2-613 (agenda diária sem rolagem lateral)

- **Pacote:** `clyonutri-v54-6-hostgator.zip`
- **Data:** 02/11/2026
- **Base:** v54.1, a versão em produção.
- **Conteúdo:** traz tudo da v54.5, que ainda não foi instalada (NI2-602, NI2-614, NI2-616, NI2-617 e NI2-609), e acrescenta o NI2-613.
- **Banco:** sem mudança.

## Novo nesta versão: NI2-613 (UX-R3-12)
**Antes:** a tabela da visão diária tem 737 px de conteúdo. Com o menu lateral aberto, entre cerca de 770 e 1.200 px de janela, as colunas Status e "Ações da consulta" (Confirmar, Iniciar, Mais ações) ficavam fora da vista. Elas só apareciam rolando a tabela para o lado.

**Agora:** a visão diária vira cartões quando a **área da agenda** tem menos de 900 px. A regra olha para essa área, e não para a janela, por isso também vale com o menu lateral aberto.

| Área da agenda | Como aparece |
|---|---|
| A partir de 400 px | Cartão compacto: horário, paciente, contato, tipo e status lado a lado, e as ações logo abaixo |
| Abaixo de 400 px (celular) | O cartão empilhado de antes (NI2-595) |
| A partir de 900 px (janela de cerca de 1.270 px ou mais) | A tabela continua igual |

**Correção junto (NI2-607, primeira parte):** o menu "Mais ações" esticava pela largura da tela, porque o estilo `right:0` ficava ativo junto com a posição fixa. Agora ele abre com a largura certa, ao lado do botão.

**Navegador antigo:** em navegadores sem suporte a container queries (Chrome anterior ao 105, Safari anterior ao 16), a tabela continua como antes, com rolagem lateral.

### Arquivos alterados
- `public/assets/css/clinical-journeys.css`: regras de cartão pela largura da área.
- `src/Application/Controllers/DashboardController.php`: classe `agenda-day-wrap` e versão `v54-6` no endereço de `agenda-form.js`.
- `public/assets/js/agenda-form.js`: posição do menu "Mais ações".
- `views/layouts/professional-shell.php`: rodapé v54.6 e versão `v54-6` nos endereços.

## Como foi testado (testes fora do zip)
| Teste | Antes | Depois |
|---|---|---|
| Visão diária, larguras de 375 a 1.440 px | 800, 900 e 1.024 px com rolagem lateral de 388, 288 e 164 px, e as ações fora da vista | 0 de rolagem em todas as larguras; Confirmar, Iniciar e "Mais ações" visíveis (também em 769, 1.100, 1.150, 1.200 e 1.240 px) |
| Menu "Mais ações" a 800 px | ocupa de −8 a 800 px | 140 px de largura, ao lado do botão |
| Regressão | | navegador v54.1 30/30; PHP v54.1 38/38 e 6/6; NI2-609 15/15; 0 violações de CSP |

## Instalação
Siga o LEIA-ME da v54.3 (`LEIA-ME-HOSTGATOR-v54-3.md`), com estas diferenças:
- o rodapé deve mostrar "v54.6";
- abra a Agenda na visão diária com a janela em cerca de 1.000 px e confira os cartões com Confirmar, Iniciar e "Mais ações" visíveis.

Para as notas do NI2-609, veja `LEIA-ME-HOSTGATOR-v54-5.md`.

## Rollback
Restaure os arquivos da v54.1. Não há mudança de banco para desfazer.
