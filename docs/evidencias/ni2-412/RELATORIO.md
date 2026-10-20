# NI2-412: remediação UX/UI v3, relatório de evidências

- **Data:** 20/10/2026
- **Base:** commit `5b2a968`
- **Status no Jira:** sem alteração. O épico e as 13 histórias continuam "A fazer" até a validação independente.

## Escopo verificado nesta rodada

Foram priorizados os dois P1 que vêm primeiro na ordem do próprio épico:

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

### Demais histórias do épico (NI2-415 a NI2-425)

| História | Situação |
|---|---|
| NI2-415 (UX-03) | Contrato da busca (fonte, tipo, categoria, macros; isolamento entre clínicas): **PASS** no teste PHP com HTTP real. Escolha por teclado: **PASS** depois da correção acima. Outros critérios de navegador: **NOT TESTED** |
| NI2-416 a NI2-425 (UX-04 a UX-13) | Contratos de servidor e template: **PASS** (`ni2_412_ux_v3_test.php`, 71/71). Critérios de navegador, viewports e zoom: **NOT TESTED** neste ambiente (o E2E anterior não é reproduzível aqui) |

### Definição de pronto do épico

| Item | Situação |
|---|---|
| Rodada exploratória com 5–8 nutricionistas (NI2-289) | **BLOCKED**: precisa de participantes. Não foi simulada. |
| Percursos por teclado, viewports e zoom em todos os fluxos afetados | Parcial: UX-01 completo; UX-02 em 1366 px; demais **NOT TESTED** |
| Evidências antes/depois anonimizadas | **PASS** para UX-01/UX-02 (só dados fictícios) |
| Rollback | Sem mudança de banco nem de contrato: o rollback é voltar os arquivos |

## Release

**Não aprovada.** Faltam:
- revisão de UX e engenharia;
- E2E de navegador das histórias UX-03 a UX-13 neste ambiente;
- rodada com participantes;
- gate de homologação.
