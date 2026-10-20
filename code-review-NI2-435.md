# Code review — NI2-435 (épico: validação clínica com Rafael)

Data: 20/10/2026. Base: commit `e070208` mais as alterações **não commitadas** do working tree em `merge/v11-teleconsulta`.

## Escopo

O épico tem 8 histórias. Só três têm código:

| História | Status no Jira | Onde está o código |
|---|---|---|
| NI2-437, pré-consulta e anamnese adaptativa | Feito | commit `463216c` + **WIP não commitado** (envio pelo WhatsApp) |
| NI2-439, dobras cutâneas | Feito | commit `9799923` (+ SQL manual em `e070208`) |
| NI2-444, refeições pré-selecionáveis | Feito | **só no working tree** (`DashboardController.php`) |
| NI2-436, 438, 440 a 443 | A fazer | — |

Arquivos revisados no WIP:
- `src/Application/Controllers/DashboardController.php` (+54/−2)
- `src/Application/Controllers/PreConsultationController.php` (+44/−2)
- `views/preconsultation/professional_show.php` (+31/−4)
- `public/assets/js/pre-consulta-profissional.js` (novo)

## Como foi verificado

- `php -l` e `node --check` sem erros.
- Suítes passando com o WIP:
  - `ni2_437_preconsultation_test` (67/67)
  - `ni2_439_skinfold_protocols_test` (62/62)
  - `ni2_9_code_review_regression_test` (42/42)
  - `meal_plan_and_prescription_lifecycle_test`
  - `meal_plan_web_csrf_and_form_test`
- E2E `ni2_437_real_http_test.cjs`: 46 verificações, com Chromium e axe.
- Sonda no navegador (Chromium, CSP ativa):
  - Fluxo de envio pelo WhatsApp, botão "Copiar link" e os chips de refeição.
  - axe WCAG 2.1 AA na tela do link e no modal de refeição: sem violações.
- Normalização de telefone testada com 9 formatos.

## Achados

### 1. Alta (governança): histórias marcadas como Feito contra a regra do épico

O épico diz: "Proibido marcar como Feito com mocks ou sem validação". Os critérios de conclusão exigem aceite clínico e evidências de testes reais.

Mesmo assim, NI2-437, NI2-439 e NI2-444 foram para **Feito** em 20/10, às 09:00. A situação de cada uma:

- **NI2-437:** o banco de perguntas e o termo de consentimento ainda aguardam a revisão do Rafael. Ver o checklist em `docs/clinical/PRE_CONSULTA_ANAMNESE_ADAPTATIVA.md`.
- **NI2-439:** os protocolos e faixas de validade aguardam conferência clínica. Ver `docs/clinical/PROTOCOLOS_DOBRAS_CUTANEAS.md`.
- **NI2-444:** o código **não está commitado**. Não entra em pacote nem em release (o empacotador exige árvore commitada). A descrição no Jira afirma testes, mas nenhum teste cobre os chips.

**Recomendação:** voltar as três para "Em validação" (ou equivalente) até haver aceite clínico e o código de NI2-444 estar commitado.

### 2. Média (privacidade): telefone estrangeiro vira número brasileiro no WhatsApp

`PreConsultationController::whatsappPhone()` põe `55` na frente de qualquer número com 10 ou 11 dígitos, mesmo quando o cadastro começa com `+`.

Exemplo: `+1 415 555 0100` vira `5514155550100`. O WhatsApp abre a conversa com um **desconhecido no Brasil**, já com a mensagem pronta: primeiro nome do paciente, nome do nutricionista e da clínica, e o link pessoal.

O link ainda exige a data de nascimento, mas o nome e o vínculo com a clínica vazam.

**Correção:** quando o número começar com `+` ou `00`, usar os dígitos como estão (DDI informado). Só acrescentar `55` em número sem DDI.

### 3. Média (NI2-444): trocar de chip mantém o horário da refeição anterior

O script só preenche o horário se o campo estiver vazio. Confirmado no navegador: clicar em "Café da Manhã" e depois em "Jantar" deixa **Jantar às 07:30**.

**Correção:** guardar se o horário veio de um chip (por exemplo `data-auto="1"`) e substituir nesse caso. Um horário digitado à mão continua preservado.

### 4. Baixa/média: link relativo quando `APP_URL` não está configurada

Sem `APP_URL`, a mensagem pronta do WhatsApp (e a do e-mail) leva `/pre-consulta/<token>`, um link que o paciente não consegue abrir. Confirmado na sonda.

Na HostGator a `APP_URL` está configurada, mas o canal padrão passou a ser o WhatsApp. Com isso, uma configuração esquecida resulta em link quebrado enviado em silêncio.

**Correção:** se `APP_URL` não for uma URL absoluta, recusar os canais WhatsApp e e-mail com uma mensagem clara e deixar só "copiar link".

### 5. Baixa (acessibilidade e texto, NI2-444)

- **Emojis no nome acessível:** ficam dentro do texto dos botões, e o leitor de tela lê "prato com talheres Almoço". Envolver em `<span aria-hidden="true">`.
- **Rótulo dos chips:** é um `<label>` sem campo associado. Usar `<div role="group" aria-labelledby="…">`.
- **Lista de sugestões diferente dos chips:** o datalist tem opções que os chips não têm ("Lanche da Tarde 1/2") e um termo em inglês ("Snack Noturno"). Usar uma lista só e "Lanche noturno".

### 6. Informativo

- **Canal padrão:** passou de e-mail para WhatsApp. Por padrão, o e-mail deixa de sair. É uma decisão de produto e convém confirmar.
- **Sem testes novos:** não há testes para o canal WhatsApp (URL, telefone, auditoria `PRECONSULTATION_WHATSAPP_PREPARED`) nem para os chips.
- **Link perdido:** o link aparece só uma vez. Se a aba do WhatsApp for fechada sem enviar, é preciso gerar outro link, o que invalida o anterior. O comportamento é coerente com a segurança do token.

## Pontos positivos

- Nenhum script inline novo sem nonce. O JS do "Copiar link" está em arquivo, e a CSP está íntegra.
- O link do WhatsApp usa `rel="noopener noreferrer"` e `rawurlencode`, e o `href` é escapado.
- A auditoria do envio pelo WhatsApp não grava o token nem dados do paciente.
- O fallback de cópia tem mensagem de status acessível (`role="status"`).

## Correções aplicadas (20/10/2026)

| Achado | Correção | Teste |
|---|---|---|
| 2, telefone estrangeiro | Com `+` ou `00` no início, usa o DDI informado. Sem DDI, aceita só número brasileiro com DDD. | `tests/ni2_437_whatsapp_test.php` (24 verificações) |
| 3, horário do chip | O horário vindo de um chip é trocado ao clicar em outro chip. O horário digitado à mão é preservado. | `tests/ni2_444_meal_chips_browser_test.cjs` |
| 4, link relativo | Sem `APP_URL` absoluta, o canal WhatsApp é recusado com aviso e auditoria `PRECONSULTATION_WHATSAPP_SKIPPED`. O botão só aparece com link absoluto. | `ni2_437_real_http_test.cjs` (+2) |
| 5, acessibilidade e texto | Emoji com `aria-hidden`, grupo com `role="group"` e uma lista só para chips e datalist (sem "Snack Noturno"). | `ni2_444_meal_chips_browser_test.cjs` (axe e nome acessível) |

O achado 1 (status no Jira) depende de decisão do produto e não foi alterado.

**Testes finais:**
- PHP: `ni2_437_preconsultation_test` 67/67, `ni2_437_whatsapp_test` 24/24, `ni2_439` 62/62 e `ni2_9` 42/42, além das suítes de planos e da jornada canônica.
- E2E da NI2-437: 48 verificações.
- Navegador NI2-444 + WhatsApp: 16 verificações, com a CSP ativa e axe sem violações.
