# NI2-412: remediação UX/UI v3, relatório de evidências

- **Data:** 20/10/2026
- **Base:** commit `5b2a968` (UX-01/02); commit `6924d97` (UX-03 a UX-13)
- **Status no Jira:** sem alteração. O épico e as 13 histórias continuam "A fazer" até a validação independente.

## Escopo verificado nesta rodada

Primeira rodada: os dois P1 que vêm primeiro na ordem do próprio épico (abaixo). Segunda rodada: E2E de navegador das histórias UX-03 a UX-13 (seção própria mais adiante).

- **UX-01 (NI2-413):** navegação semanal da agenda.
- **UX-02 (NI2-414):** alterações pendentes ao cancelar.

O código das 13 histórias já estava no repositório (commit `804e3a0`, 15/10), mas sem evidência reproduzível neste ambiente:
- **E2E de navegador anterior:** `tests/ni2_412_real_browser_e2e.py` depende de outra máquina (Apache, MariaDB acessado por `sudo mysql`, conta demo com senha e alimentos nº 301/9901 que não existem no seed). Por isso não roda aqui.
- **Teste PHP:** `tests/ni2_412_ux_v3_test.php` falhava (1 de 71) por depender da data em que foi escrito.

## Como reproduzir

```bash
# Depois (código atual): para no primeiro FAIL
PLAYWRIGHT_MODULE=… CHROMIUM_PATH=… node tests/ni2_412_ux01_ux02_real_test.cjs

# Antes (árvore de 804e3a0^, anterior à correção): registra as falhas sem parar
BASELINE=1 APP_ROOT=/caminho/da/arvore/804e3a0^ PLAYWRIGHT_MODULE=… CHROMIUM_PATH=… node tests/ni2_412_ux01_ux02_real_test.cjs

# UX-03 a UX-13 (NI2-415 a NI2-425): mesmo ambiente; BASELINE=1 continua após falhas
PLAYWRIGHT_MODULE=… AXE_MODULE=… CHROMIUM_PATH=… node tests/ni2_412_ux03_ux13_real_test.cjs

# Contratos de servidor e template das 13 histórias
php -d zend.assertions=1 -d assert.exception=1 tests/ni2_412_ux_v3_test.php
```

O E2E sobe `public/index.php` em `php -S` (`APP_ENV=production`). Usa:
- banco SQLite isolado com dados fictícios (`tests/support/ni2_412_fixture.php`): uma clínica, uma nutricionista, uma paciente fictícia e consultas em datas de virada de mês e ano;
- catálogo TACO real (`CatalogSeeder`);
- login real;
- Chromium com a CSP ativa.

Nenhum dado real foi usado.

## Resultados

| Execução | Resultado | Arquivos |
|---|---|---|
| E2E **antes** (`804e3a0^`) | 11 de 27 PASS. O defeito foi reproduzido: veja abaixo | `antes-baseline.log`, `antes-resultados.json`, `*-antes.png` |
| E2E **depois** (código atual) | **36 de 36 PASS** | `depois.log`, `depois-resultados.json`, `*-depois.png` |
| `tests/ni2_412_ux_v3_test.php` | **71 de 71 PASS** (antes: 70 de 71, falha só de data) | — |
| 59 suítes PHP antes x depois | 45 → 46 PASS. Única mudança: `ni2_412_ux_v3_test` passou a passar. Nenhuma falha nova; as 13 falhas antigas continuam | `regressao-suites.txt` |
| Navegador NI2-444 (editor de plano) | 16 de 16 PASS | — |
| **2ª rodada:** E2E UX-03 a UX-13, depois | **124 PASS, 0 FAIL, 1 BLOCKED** (NI2-422, decisão de produto) | `ux03-ux13/depois.log` |
| **2ª rodada:** 59 suítes PHP, `6924d97` x esta entrega | 47 = 47 PASS; mesmas 12 falhas antigas; resultado idêntico | `ux03-ux13/regressao-suites.txt` |
| **2ª rodada:** navegador UX-01/02, NI2-433, NI2-444, NI2-437; `ni2_412_ux_v3_test.php` | 36/36, 69, 16, 41 PASS; 71/71 | — |

**Defeito reproduzido no antes (UX-01):** na visão semanal de 12–18/10, clicar em → a partir de 12/10 ou de 15/10 **continuava na mesma semana**. Só a partir do domingo (18/10) ia para a seguinte. A virada de ano ficou presa em 28/12–03/01. Faltavam também:
- nome acessível nas setas;
- `aria-current` no Hoje;
- anúncio do período para leitor de tela.

**Defeito novo encontrado e corrigido (afeta UX-03/NI2-415 e UX-02):**
- **Sintoma:** na busca de alimento, **seta para baixo + Enter não selecionava nada**.
- **Causa:** desde o agrupamento de equivalentes (v14), o primeiro filho dos resultados é um `div` de grupo, que não recebe foco.
- **Correção:** `public/assets/js/food-picker.js`. A seta para baixo vai ao primeiro botão de resultado; setas percorrem os resultados; seta para cima no primeiro volta ao campo.
- **Teste:** coberto no E2E.

**Testes corrigidos:**
- `tests/ni2_412_ux_v3_test.php`: o trecho que renderiza o perfil usa o relógio real e agora cria a consulta "de hoje" na data real.

## Situação por critério

Legenda:
- **PASS:** executado com evidência.
- **NOT TESTED:** não executado.
- **BLOCKED:** depende de algo indisponível.

### NI2-413 (UX-01): navegação da agenda

| Critério | Situação | Evidência |
|---|---|---|
| 1. Semana: um avanço muda início e fim em exatamente 7 dias | **PASS** | 12–18/10 → 19–25/10, partindo de segunda, quinta e domingo; o conteúdo da semana muda junto (sai 14/10 09:00, entra 21/10 10:00) |
| 2. Dia: ±1 dia, inclusive viradas de mês e ano | **PASS** | 31/12/2026 → 01/01/2027; 01/03 → 28/02/2026; 01/03/2028 → 29/02/2028 |
| 2b. Semana na virada de ano | **PASS** | 28/12–03/01 → 04/01–10/01/2027 |
| 3. Teclado e leitor de tela: links anunciam período e direção; Hoje volta ao intervalo vigente | **PASS** | Tab até "Próxima semana (19 de outubro de 2026 a 25 de outubro de 2026)" + Enter; `aria-current="date"`; `role=status` com o período; Hoje volta à semana atual |
| Viewports 360/390/768/1366 px | **PASS** | setas e Hoje visíveis, alvo ≥ 24 px, sem rolagem horizontal |
| Zoom 200% | **PASS (emulado)**: 1366 px a 200% ≈ 683 px de largura CSS. O zoom nativo do navegador não foi acionado. | `ux01-agenda-683-depois.png` |
| Teclado virtual (celular real) | **NOT TESTED** | — |
| Revisão de UX e engenharia | **BLOCKED**: precisa de revisão humana | — |

### NI2-414 (UX-02): alterações pendentes

| Critério | Situação | Evidência |
|---|---|---|
| 1. Buscar alimento sem incluir, cancelar e sair: sem aviso | **PASS** | sem diálogo ao cancelar; `dirtyForms = 0`; sair para o painel sem `beforeunload` |
| 2. Edição clínica pendente: avisa ao sair e permite permanecer sem perda | **PASS** | anamnese alterada → `beforeunload`; permanecer mantém o texto |
| 3a. Descartar um formulário não limpa os demais | **PASS** | descartar o alimento mantém a refeição pendente (valor e estado) |
| 3b. Enviar um formulário com outro pendente | **PASS**: o navegador avisa antes de sair; confirmando, o envio grava (1 item no banco) | — |
| Persistência | **PASS** | só o envio confirmado gravou item; os cancelamentos não gravaram nada |
| Viewports e zoom nas telas de anamnese e editor | **NOT TESTED** nesta rodada | — |
| Revisão de UX e engenharia | **BLOCKED** | — |

### Demais histórias do épico (NI2-415 a NI2-425): E2E de navegador

Teste: `tests/ni2_412_ux03_ux13_real_test.cjs`. Mesmo ambiente do UX-01/02 (php -S em modo produção, SQLite isolado, login real, CSP ativa, Chromium, axe-core WCAG 2 A/AA). A fixture ganhou dados fictícios para isolamento e busca:
- segunda clínica com o paciente "Lucas Aluno" (nº 901);
- alimento personalizado próprio e um da clínica vizinha;
- duas duplicatas reais e um homônimo com composição diferente;
- modo `attention`: uma falta recente sem remarcação, para o painel ter pendência real.

Evidências em `ux03-ux13/`: `depois.log`, `depois-resultados.json`, `antes-baseline.log`, `antes-resultados.json` e 11 capturas.

| Execução | Resultado |
|---|---|
| **Depois** (código atual) | **124 PASS, 0 FAIL, 1 BLOCKED** |
| **Antes** (`804e3a0^`, `BASELINE=1`) | 30 PASS, 35 FAIL. A maior parte das falhas é ausência dos elementos novos (diálogo, próximo passo, índice da anamnese, "Nova conversa"), o que confirma que a funcionalidade não existia; não é falha de seletor no código atual |

**Defeitos encontrados nesta rodada e corrigidos (axe, contraste WCAG AA):**
- Perfil do paciente, rótulos "OBJETIVOS": `#0284C7` sobre `#F8FAFC` (3,91:1) → `#0369A1`.
- Editor de plano, "Nenhum alimento cadastrado nesta refeição": `#94A3B8` sobre branco (2,56:1) → `#64748B`.
- Conversas, botão "Enviar": branco sobre `#059669` (3,76:1) → `#047857` (hover `#065F46`).

Depois da correção, o axe não acusa violações em 8 telas: painel, pacientes, perfil, anamnese, planos, editor de plano, conversas e agenda semanal.

| História | Situação | Evidência (navegador, salvo indicação) |
|---|---|---|
| NI2-415 (UX-03) busca de alimentos | **PASS** | fonte e nº TACO no resultado; personalizado marcado "da sua clínica"; personalizado da outra clínica **não aparece**; macros por 100 g visíveis; seta + Enter seleciona; duplicata real agrupada ("1 cadastro equivalente") sem mesclar no banco (3 registros continuam); homônimo com composição diferente avisado |
| NI2-416 (UX-04) novo plano | **PASS** | 3 campos com label associado; `required`; clicar no label foca o campo; envio inválido foca o primeiro inválido com `aria-invalid` e erro em `aria-describedby`; mantém o preenchido e **não cria registro**; envio válido cria e abre o plano; axe sem campo/botão/link sem nome em 8 telas |
| NI2-417 (UX-05) cadastro em diálogo | **PASS** | `role=dialog`, `aria-modal`, título por `aria-labelledby`; foco inicial no Nome; 40 Tabs/Shift+Tabs ficam no diálogo; fundo `inert`; Escape sem alteração fecha e devolve o foco; com alteração pede descarte e recusar mantém o valor; envio persiste o paciente |
| NI2-418 (UX-06) próximo passo no perfil | **PASS** | sem consulta → "Agendar" abre o formulário com o paciente pré-selecionado e não cria nada pelo GET; consulta de hoje → "Iniciar" pede confirmação com o nome do paciente; início antecipado exige confirmação explícita e não muda o status antes dela; depois grava `in_progress` na **mesma** consulta; "Continuar" aponta para ela; 1 registro só |
| NI2-419 (UX-07) linguagem da anamnese | **PASS** | "Concluir anamnese" no lugar do jargão de versão imutável; ajuda diz que o histórico anterior é preservado |
| NI2-420 (UX-08) anamnese longa | **PASS** | índice de 8 seções leva à seção sem perder valores; resumo distingue vazio de negação; "Alterações não salvas"; "Salvar rascunho" visível na primeira dobra a 1366×900; rascunho parcial gravado no banco (`is_draft=1`) e retomado |
| NI2-421 (UX-09) painel | **PASS** | sem pendências, orientação no lugar da lista; com falta real, prioridade e ação na primeira dobra a 1366×768 e 1366×900; prioridades antes das métricas no DOM; 5 métricas numa linha a 1366 px e 3+2 a 1024 px; links do painel abrem sem 4xx/5xx |
| NI2-422 (UX-10) gênero e sexo | Ajuda associada ao campo explicando o uso (relação cintura-quadril) e que o dado não é deduzido de outro: **PASS**. "Não informado" distinto de resposta registrada: **BLOCKED** | a opção continua "Outro / Não informado" (valor `Outro`). O guia da história manda validar finalidade e modelo com produto e profissional habilitado e **não converter legado sem evidência**: registros antigos com `Outro` podem significar qualquer das duas coisas. Precisa de decisão de produto antes de mudar |
| NI2-423 (UX-11) responsividade | **PASS (emulado)** | 7 telas (login, painel, pacientes + diálogo, perfil, anamnese, planos + diálogo, conversas) × 360/390/768/1366 px e 683 px (1366 a 200%): sem rolagem horizontal e ação essencial dentro da tela; diálogos rolam por dentro. Zoom nativo do navegador e teclado virtual de celular real: **NOT TESTED** |
| NI2-424 (UX-12) nova conversa | **PASS** | "Nova conversa" no módulo; destinatário explícito antes de abrir; cancelar não cria; segunda abertura indica conversa existente e reabre a mesma; nenhuma mensagem enviada; paciente de outra clínica → 404 no POST e no atalho GET, e não aparece na busca; POST sem CSRF recusado; atalho no perfil mantido |
| NI2-425 (UX-13) login | **PASS** | `autocomplete` username/current-password e new-password; colar senha (Ctrl+V) funciona e o login com ela entra; mostrar/ocultar não envia, muda `type` e anuncia estado (`aria-pressed`, rótulo); senha errada e conta inexistente recebem a mesma mensagem; recuperação disponível; "8 caracteres" na tela e servidor recusa 7 sem criar conta |
| Geral | **PASS** | nenhum erro de script nem bloqueio de CSP nos fluxos; axe WCAG 2 A/AA sem violações nas 8 telas |

Limites desta rodada:
- Zoom foi emulado pela largura CSS equivalente.
- Leitor de tela real não foi usado: foram verificados nomes, papéis e estados no DOM acessível.
- Revisão de UX e engenharia: **BLOCKED** (humana) em todas as histórias.

### Definição de pronto do épico

| Item | Situação |
|---|---|
| Rodada exploratória com 5–8 nutricionistas (NI2-289) | **BLOCKED**: precisa de participantes. Não foi simulada. |
| Percursos por teclado, viewports e zoom em todos os fluxos afetados | **PASS (emulado)** nas telas de UX-01 e UX-03 a UX-13; UX-02 só em 1366 px. Zoom nativo e teclado virtual: **NOT TESTED** |
| Evidências antes/depois anonimizadas | **PASS** (só dados fictícios) |
| Rollback | Sem mudança de banco nem de contrato: o rollback é voltar os arquivos |

## Release

**Não aprovada.** Faltam:
- revisão de UX e engenharia;
- decisão de produto sobre gênero e sexo (NI2-422, critério 2);
- rodada com participantes;
- gate de homologação.
