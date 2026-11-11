# Clyo Nutri v54.12: NI2-625 (ativação), NI2-632 (portão de release) e NI2-635 (motor de protocolos, desligado)

- **Pacote:** `clyonutri-v54-12-hostgator.zip`
- **Data:** 02/11/2026
- **Base:** v54.1, a versão em produção.
- **Conteúdo:** traz tudo da v54.11, que ainda não foi instalada, e acrescenta as três histórias abaixo.
- **Banco:** **há SQL nesta versão.** É a migração 78, que só cria 7 tabelas novas e vazias. Ver "Instalação".

## NI2-625: ativação do consultório conferida no banco
O passo 5 do onboarding virou um **checklist conferido no sistema**, não pelos cliques. Cada item mostra a situação e o botão que resolve:

| Item | Obrigatório? |
|---|---|
| Perfil profissional completo (nome, CRN e região) | Sim |
| Horários de atendimento confirmados | Sim, se o agendamento online estiver ligado |
| Link público oferecendo horários | Sim, se o agendamento online estiver ligado |
| Primeiro paciente | Não ("primeiro passo") |
| Primeira consulta marcada (cancelada não conta) | Não |
| Primeiro plano alimentar | Não |

**Regras de exibição:**
- "Consultório pronto para atender" só aparece quando todos os obrigatórios estão feitos.
- O percentual da barra é calculado (feitos ÷ aplicáveis). Sai o "90%" fixo da v54.10.
- **Agendamento online desligado:** horários e link aparecem como "não se aplica" e não contam.
- **Agenda configurada mas sem vaga livre em 14 dias** (cheia ou bloqueada de propósito): conta como feita, com aviso. Não é falta de configuração.
- O link pendente por causa dos horários não tem botão próprio, para não haver dois botões iguais.

**No painel:**
- Aparece "Ativação do consultório incompleta (X de Y)" enquanto faltar item obrigatório.
- Se faltarem só primeiros passos, o aviso aparece nos 30 dias seguintes ao onboarding.
- O botão leva ao checklist.
- "Consultório ativado" não aparece mais quando ainda falta item obrigatório.

**Também nesta versão:**
- A API `/api/v1/onboarding/status` passa a trazer `activation`.
- Corrigido o rodapé do onboarding, que passava da tela em 375 px (era assim em todos os passos).

## NI2-632: portão de release no repositório (fora do zip)
**O que é:** um comando, `bash tests/run_release_gate.sh`, roda os testes de domínio (PHP) e 9 jornadas no navegador em bancos SQLite descartáveis com dados TESTE, e decide **GO / NO-GO**.

**Resultados possíveis:**

| Resultado | Código | Quando |
|---|---|---|
| GO | 0 | Tudo passou |
| NO-GO | 1 | Qualquer falha nova |
| INCOMPLETO | 2 | Falta Node, Playwright ou Chromium; a parte sai "NÃO TESTADO", nunca aprovada |

**Relatório:** `report.md` e `report.json`, com versão e commit, mais capturas, em `storage/e2e-report/`. O build remove essa pasta do zip.

**Cenário novo (`tenant`):**
- A clínica B tenta abrir paciente, anamnese, prontuário, impressão, plano, exames, consulta e API da clínica A.
- A clínica A tenta o paciente da B.
- Uma sessão forjada tenta uma clínica sem vínculo.
- Resultado: nada vaza e nenhuma ação é aceita.

**Falhas conhecidas:** `tests/e2e/known_failures.txt` lista com a contagem exata. Hoje só a do `v47_ni2_537`, da v53.

## NI2-635: motor da anamnese por protocolos clínicos (DESLIGADO)
**Fase 0 (desenho) e motor.** O documento completo é `NI2-635-fase0-e-motor.md`, entregue junto com o pacote e guardado no repositório em `docs/clinical-protocols/`; o build não leva essa pasta para o zip.

**Nada muda para o usuário nesta versão:**
- não há tela nova;
- não há pergunta clínica cadastrada;
- a chave `feature_flag_clinical_protocols` nasce em 0.

**O que o motor já faz:**
- **Catálogo versionado:** rascunho → revisão → aprovada → aposentada.
  - Para aprovar: referências, aprovador diferente do autor, nome e CRN, e revisão em até 24 meses.
  - Na fase C: também parecer especializado.
  - Versão aprovada é imutável e conferida por hash.
- **Perguntas tipadas** com ramificação, **deduplicação entre módulos** (a mesma pergunta aparece uma vez) e recusa de combinação incompatível.
- **Sinais de alerta** que só encaminham para avaliação; o texto não pode trazer conduta.
- **Condições do paciente com origem** (paciente, autorrelato, profissional, laudo), validade e histórico.
  - Texto livre gera só **sugestão a confirmar**.
  - O módulo só aparece com a condição confirmada por profissional.
- **Respostas** sempre em linha nova. A concluída vale; um rascunho mais novo aparece como alteração em andamento (regra da NI2-605).
- **Quatro travas** para um módulo aparecer: chave global, clínica ligou, versão aprovada íntegra, condição confirmada.

**Próximo passo:** curadoria clínica dos 10 protocolos da fase A, depois as telas. Sem curadoria, nada deve ser ligado.

## Instalação
1. Siga o LEIA-ME da v54.3 (`LEIA-ME-HOSTGATOR-v54-3.md`) e os passos da v54.10 (`storage/sessions/` com escrita).
2. **SQL:** no phpMyAdmin, selecione o banco `maic6824_Clyonutri2` e rode `database/manual/atualizacao-v54-12-ni2-635.sql`.
   - Ele cria as 7 tabelas, grava a chave em 0 e registra a migração 78.
   - Pode rodar duas vezes.
   - Foi testado em MariaDB 10.11 sobre o `instalacao_mysql8.sql`.
3. Confira o rodapé "v54.12".
4. Abra `/onboarding?passo=5` com a sua conta. O checklist deve refletir o que está de fato configurado.
5. **Não ligue** `feature_flag_clinical_protocols`.

## Arquivos
**Novos:**
- `src/Domain/Onboarding/ActivationChecklist.php`
- `src/Domain/ClinicalProtocol/` (7 classes)
- `database/migrations/2026_11_03_000078_create_clinical_protocols.php`
- `database/manual/atualizacao-v54-12-ni2-635.sql`
- `docs/clinical-protocols/NI2-635-fase0-e-motor.md` (no repositório; fora do zip)

**Alterados:**
- `OnboardingController.php`, `DashboardController.php`, `Icon.php` (`circle`, `minus`)
- `clinical-journeys.css`
- `scripts/build_hostgator_zip.sh`
- `views/layouts/professional-shell.php` (rodapé v54.12)

**Fora do zip:**
- `tests/run_release_gate.sh`
- `tests/e2e/` (9 cenários, seeds, README)
- `tests/v5412_ni2_625_activation_test.php`
- `tests/v5413_ni2_635_protocols_test.php`
- `tests/mysql/ni2_635_mysql_smoke.php`

## Como foi testado
- **Portão de release:** **GO**.
  - PHP: 13 suítes, com a falha conhecida da v53.
  - Navegador: v541 30/30, p607 14/14, p609 9/9, p610 31/31, p613c 8/8, p5410 25/25, p5411 32/32, p5412 15/15, tenant 15/15.
  - 0 violações de CSP e 0 erros de JavaScript.
- **NI2-625:** 13 testes de domínio e 15 no navegador. O navegador cobre: antes e depois de confirmar horários, painel, "Consultório ativado" escondido, 375 px.
- **NI2-635:** 53 testes de domínio, mais a prova em MariaDB real: SQL manual duas vezes, migração PHP idempotente e o fluxo catálogo → aprovação → condição → módulo → resposta.
  - A prova em MariaDB achou e corrigiu um defeito que o SQLite escondia: ligar duas vezes o mesmo protocolo dava chave duplicada.

## Rollback
- **Arquivos:** restaure os da v54.1.
- **Banco:** as 7 tabelas novas podem ficar; a v54.1 não as usa.
  - Para removê-las, use o bloco comentado no fim do SQL, só se estiverem vazias.
