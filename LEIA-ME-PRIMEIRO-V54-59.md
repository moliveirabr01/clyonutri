# ClyoNutri II — v54.59 candidata (NI2-815)

Base: `/Users/moliveira/Nutri_II`, branch `feat/v54-59-ni2-815`, a partir do snapshot da v54.58 local (commit c039de5). Produção continua na v54.51. Nenhum deploy foi feito.

## O que mudou

- **Correções que faltavam no Nutri_II.** A v54.58 local tinha só o painel e o menu da v54.58 enxertados sobre uma base v54.51. Ficaram de fora as correções das versões v54.52 a v54.57, entre elas:
  - recuperar rascunho ilegível da anamnese;
  - portal web do paciente (plano, água e sair);
  - páginas 403/404 com layout;
  - contexto de atendimento A/B (UX-002);
  - confirmação ao liberar ou revogar avaliação (UX-018);
  - perfil público que só aparece depois de confirmado;
  - etapas do atendimento.

  Todas foram reintegradas com mesclagem de três vias (base v54.51, Nutri_II e pacote v54.58). O painel e o menu da v54.58 e o trabalho local do NI2-767 (Central de Eventos) continuam como estavam. A lista arquivo por arquivo está em `reintegracao/arquivos-reintegrados.csv` na pasta de evidências.
- **Diretório público.** Juntei os temas fixos do Nutri_II (lista permitida em `BookingController::DIRECTORY_TOPICS`) com a regra da v54.52: especialidade, temas e biografia só aparecem depois que o perfil é confirmado. Não há mais texto genérico inventado.
- **Testes.** Novo cenário de navegador `tests/e2e/scenarios/ni2815.js` (seed `ni2815`) para os quatro filhos do NI2-815 e a preservação do épico. Os testes p5456, p5411 e v5421 foram ajustados ao menu da v54.58, sem perder nenhuma verificação.

## Migrations

Esta versão não traz migration nova. Em relação à produção v54.51, o Nutri_II já tinha a migration 000091 (`production_events` e `production_alerts`, NI2-767), com SQL manual em `database/manual/atualizacao-v54-52-ni2-767-production-events.sql`. Ela não foi testada em MySQL 5.7.

## Situação

CANDIDATA EM VALIDAÇÃO LOCAL. Testei com SQLite, Chromium e Firefox. Continuam pendentes: MySQL 5.7/HostGator, Safari/WebKit, aparelhos físicos, leitor de tela real e revisão humana. O gate de produção não foi aprovado.
