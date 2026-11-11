# Clyo Nutri v54.8: NI2-610, NI2-612 e NI2-615

- **Pacote:** `clyonutri-v54-8-hostgator.zip`
- **Data:** 02/11/2026
- **Base:** v54.1, a versão em produção.
- **Conteúdo:** traz tudo da v54.7, que ainda não foi instalada (NI2-602, 607, 609, 613, 614, 616 e 617), e acrescenta NI2-610, NI2-612 e NI2-615. O NI2-613 já estava pronto desde a v54.6.
- **Banco:** sem mudança. **Não há SQL para rodar.**

## NI2-610 (UX-R3-09): painel mostra o trabalho pela metade
A lista "Pacientes e prioridades que exigem atenção" passa a incluir também:
- **Anamnese em rascunho:** com o paciente, a data e o botão **Continuar**, que abre a anamnese.
- **Solicitação de exames em rascunho ou em revisão:** com o número, a data e **Continuar**, que abre a edição ou a emissão.
- **Agendamento online ligado sem horários:** traz **Ativar agendamento online**, ou **Revisar horários** quando não há horário livre em 14 dias. O botão leva direto a Configurações > Agenda > Horários.

Como a lista funciona:
- É uma lista única, ordenada por prioridade: perigo, depois aviso, depois informação.
- Os itens além dos 5 primeiros aparecem em **"Ver os outros N item(ns)"**, sem sair do painel. O contador mostra o total.
- Rascunhos de anamnese e de exames só aparecem para os perfis clínicos (titular, administrador e nutricionista).

## NI2-612 (UX-R3-11): Configurações > Agenda em seções, com um aviso só
- **Seções:** a aba tem quatro seções, uma por vez: **Horários**, **Link online**, **Feriados e bloqueios** e **Regras e lista de espera**. A última aparece só para a gestão.
- **Altura:** a 1280 × 800 px, todas as seções cabem em 2 telas:

  | Seção | Altura |
  |---|---|
  | Horários | 1.510 px (antes, a aba inteira tinha 4.861 px) |
  | Link online | 1.168 px |
  | Feriados e bloqueios | 1.392 px |
  | Regras e lista de espera | 1.190 px |

- **Horários:** a disponibilidade semanal tem uma linha por dia. No celular, a linha vira duas.
- **Um aviso por pendência:** aparece no topo da aba e é o mais específico de três: horários a confirmar, sem dias de atendimento ou sem horário livre em 14 dias. Saíram os avisos repetidos "Ligado, mas os horários ainda não foram confirmados" e "não há nenhum horário livre nos próximos 14 dias". A agenda usa a mesma regra e o mesmo texto.
- **Depois de salvar:** a tela volta para a mesma seção. Os links antigos (`#calendar-...`, e "Feriados e bloqueios" na agenda) abrem a seção certa.

## NI2-615 (UX-R3-14): nome do feriado e importação dos feriados nacionais
- **Nome do feriado:** o cadastro ganhou o campo **Nome do feriado (opcional)**, ao lado do tipo. Fica gravado como "Feriado nacional: Finados" e pode ser editado depois.
- **Na agenda:** as visões diária, semanal e mensal mostram os nomes, como "Feriado nacional: Finados · Reunião", com o número de regras entre parênteses. Antes aparecia só "4 regras nesta data".
- **Importar feriados nacionais (2026/2027):**
  - abre a lista do ano para revisão, e nada é gravado ao abrir;
  - cada feriado tem uma caixa de marcar; os que já estão cadastrados aparecem como "já cadastrado" e não são duplicados;
  - você escolhe a quem se aplica e clica em **Salvar feriados selecionados**.
- **O que é importado:** só os feriados. Pontos facultativos, como Carnaval e Corpus Christi, ficam de fora.
- **Fonte:** Leis nº 662/1949, nº 6.802/1980 e nº 14.759/2023, mais a Sexta-feira da Paixão, calculada pela Páscoa. A lista de 2026 foi conferida com a Portaria MGI nº 11.460/2025.
  - **2026:** confere com a portaria (10 feriados).
  - **2027:** a portaria ainda não saiu. Confira quando for publicada, normalmente em dezembro.
- **Feriados estaduais e municipais:** continuam manuais, agora com nome.

## Arquivos
**Novos:**
- `src/Domain/Booking/BookingSetupState.php`
- `src/Domain/Booking/NationalHolidays.php`
- `src/Domain/Dashboard/PendingWorkSummary.php`

**Alterados:**
- `src/Application/Controllers/DashboardController.php`
- `src/Application/Controllers/SettingsController.php`
- `src/Domain/Booking/BookingCalendarService.php`
- `views/settings/profile.php`
- `views/settings/_calendar.php`
- `views/agenda/_calendar_markers.php`
- `public/assets/css/clinical-journeys.css`
- `public/assets/js/clinical-journeys.js`
- `views/layouts/professional-shell.php`: rodapé v54.8 e versão `v54-8` nos endereços.

## Como foi testado (testes fora do zip)
| Teste | Resultado |
|---|---|
| Domínio (`v548_ni2_610_612_615`) | 24/24 |
| Navegador (`p610.js`), 1280 × 800 | 31/31 |
| Regressão | navegador v54.1 30/30; NI2-607 14/14; NI2-613 8/8; PHP 38/38, 6/6, 15/15, 71/71, 42/42, 87/87; `v47_ni2_537` com a falha que já existia na v53 |

O que os testes conferiram:
- **Painel:**
  - a anamnese aparece com a data e "Continuar", que abre a página (200);
  - o exame aparece com "Continuar", que abre a edição (200);
  - "Ativar agendamento online" aparece e some depois de confirmar os horários.
- **Configurações > Agenda:**
  - as 4 seções têm até 1.510 px;
  - aparece 1 aviso de pendência em cada seção;
  - salvar volta para a mesma seção;
  - o link antigo abre Feriados;
  - a 375 px não há rolagem lateral.
- **Feriados:**
  - um feriado com nome aparece na agenda diária e na semanal;
  - a prévia de 2027 lista 10 feriados, com a Sexta-feira da Paixão em 26/03, e não grava nada ao abrir;
  - foram gravados só os 9 marcados;
  - a nova prévia mostra "já cadastrado";
  - 0 violações de CSP e 0 erros de JavaScript.

## Instalação
Siga o LEIA-ME da v54.3 (`LEIA-ME-HOSTGATOR-v54-3.md`), com estas diferenças:
- o rodapé deve mostrar "v54.8";
- **Painel:** confira a lista de atenção;
- **Configurações > Agenda:** confira as quatro seções e se aparece um aviso só;
- **Feriados e bloqueios > Importar feriados nacionais > Revisar 2026:** confira a lista **sem salvar**, se não quiser gravar agora.

## Rollback
Restaure os arquivos da v54.1. Não há mudança de banco para desfazer. Os feriados importados ficam gravados, e a v54.1 os mostra pelo rótulo.
