# Clyo Nutri v54.7: NI2-607 (menu "Mais ações" junto do botão)

- **Pacote:** `clyonutri-v54-7-hostgator.zip`
- **Data:** 02/11/2026
- **Base:** v54.1, a versão em produção.
- **Conteúdo:** traz tudo da v54.6, que ainda não foi instalada (NI2-602, 614, 616, 617, 609 e 613), e acrescenta o NI2-607.
- **Banco:** sem mudança. **Não há SQL para rodar.**

## Novo nesta versão: NI2-607 (UX-R3-06)
**Antes (v54.1):** na agenda, o menu "Mais ações" abria esticado na largura da tela, por cima do menu lateral, e longe do botão.

**Agora:** a função `place()` de `public/assets/js/agenda-form.js` foi ajustada:
- **Largura:** o menu fica com a largura do próprio conteúdo (`width:max-content`, `right:auto`), limitada à área visível.
- **Posição:** o menu abre alinhado à direita do botão, 4 px abaixo dele. Quando não cabe embaixo, abre 4 px acima.
- **Menu lateral:** o menu nunca começa antes da área de conteúdo, então não cobre o menu lateral.
- **Deslocamento:** a margem em linha (`margin-top`), que deslocava o menu mais 4 px, foi zerada.

A primeira parte da correção (`right:auto`) já estava na v54.6. Esta versão completa a história e cria o teste de navegador.

Arquivos alterados:
- `public/assets/js/agenda-form.js`;
- `src/Application/Controllers/DashboardController.php` (versão `v54-7` no endereço do JS);
- `views/layouts/professional-shell.php` (rodapé v54.7).

## Como foi testado (testes fora do zip)
| Teste | Resultado |
|---|---|
| NI2-607 (`p607.js`): a 800, 1024 e 1280 px, primeira e última linha de um dia com 9 consultas | 14/14 |

O que o `p607.js` conferiu:
- o menu tem 140 px de largura, a borda direita igual à do botão e fica 4 px abaixo ou acima dele;
- o menu começa depois do menu lateral (que termina em 256 px);
- Reagendar, Não compareceu e Cancelar recebem clique (conferido pelo elemento sob o ponteiro);
- o clique real em Reagendar, na última linha, abre `/agenda/9/reagendar`;
- 0 violações de CSP.

**Regressão:**
- navegador v54.1: 30/30;
- NI2-613: 8/8.

## Instalação
Siga o LEIA-ME da v54.3 (`LEIA-ME-HOSTGATOR-v54-3.md`), com estas diferenças:
- o rodapé deve mostrar "v54.7";
- na agenda (visão diária), abra "Mais ações" e confira que o menu é pequeno e fica junto do botão.

Para as notas das versões anteriores, veja os LEIA-ME v54-5 e v54-6.

## Rollback
Restaure os arquivos da v54.1. Não há mudança de banco para desfazer.
