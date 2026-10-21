# Code review: NI2-135, Evolução, metas e autorrelatos com proveniência (paciente)

- **Data:** 21/10/2026
- **Base revisada:** `~/Nutri_II`, branch `merge/v11-teleconsulta`, commit `aa8bb1d`.
- **Status no Jira:** "Feito" (épico NI2-11). Não alterado.

**Escopo:**
- `PatientPortalService::getEvolutionTimeline` e `recordSelfReportedMetric`.
- Rotas `GET /api/v1/patient/evolution` e `POST /api/v1/patient/self-metrics` (`public/index.php` 970–1010).
- Aba "Evolução & Metas" do app (`mobile/src/screens/PatientHomeScreen.tsx` 902–932) e cliente (`mobile/src/services/api.ts`).
- Compartilhamento de avaliações (`AnthropometryService`, `is_shared`).

## Status das correções

**A1 corrigido em 21/10/2026.** As anotações internas do profissional não chegam mais à paciente. O mesmo problema apareceu na observação da agenda (queixas, encaminhamentos), que ia junto com a "próxima consulta" na home do app; também foi corrigido. Os demais achados seguem abertos.

**O que mudou:**
- `PatientPortalService::getEvolutionTimeline` deixa de selecionar `notes`.
- `getNextAppointment` remove `notes` da consulta antes de devolver.
- `AnthropometryService::getSharedAssessmentsForPatient` remove `notes`. O método monta a visão da paciente, mas hoje não é usado por nenhuma rota.
- Tipos do app (`EvolutionData`, `Appointment`) sem `notes`.
- Nada é apagado: o profissional continua vendo as anotações.

**Teste:** `tests/ni2_135_internal_notes_real_test.cjs` (HTTP real).

| Execução | Resultado |
|---|---|
| Antes | 6 PASS, **3 FAIL** (evolução, próxima consulta e o método de serviço vazavam) |
| Depois | **9 PASS, 0 FAIL** |

**Regressão:**
- NI2-89: 50/50.
- NI2-91: 34/34.
- Cliente do app contra o servidor real: 34/34.
- 97 suítes PHP: nenhuma falha causada pela correção. Duas mudam de resultado conforme a hora em que rodam: `ni2_292…` cria uma consulta para hoje às 14:30, que às 16h já é passado, e `dashboard_review_fixes` falha no logout só dentro do próprio teste; o fluxo real de logout foi conferido por HTTP em 20/10.
- Tipagem do app: sem erros.
- `review_ni2_135_provas.cjs`: P7 agora dá "ok".

Evidências em `docs/evidencias/ni2-135/`.

## Critérios da história

> Exibir medidas disponibilizadas pelo nutricionista e gráficos acessíveis; indicar data, unidade, fonte e tipo (clínico vs autorrelato); filtros temporais; registrar autorrelato somente nos campos habilitados; preservar histórico; linguagem neutra e inclusiva; não inferir diagnósticos nem recomendar alterações automáticas de tratamento.
> QA: visibilidade por vínculo, valores inválidos, leituras de tela, ausência de medidas e autorização por recurso. Integração NI2-7.

## Resumo

A base está certa:
- **Fonte separada:** medições clínicas e autorrelatos vêm de tabelas e campos distintos (`source`).
- **Liberação pelo profissional:** a paciente só vê a avaliação que o profissional liberou (`is_shared`).
- **Isolamento:** cada paciente só vê os próprios dados.
- **Sem diagnóstico:** o app mostra o IMC sem classificação nem recomendação.

O que a história pede para a paciente, porém, quase não existe:
- não há gráfico nem filtro de período;
- não há como registrar autorrelato pelo app;
- não há metas.

A API aceita autorrelato de qualquer tipo, unidade e valor (peso de 4.000 kg, tipo inventado). Ao liberar uma avaliação, ela entrega à paciente a **observação interna do profissional**.

**Veredito: pedir alterações.** A NI2-135 não atende o aceite e não deveria estar em "Feito". Recomendo reabrir.

Os achados **[prova]** foram reproduzidos por HTTP real com `tests/review_ni2_135_provas.cjs`:
- `php -S` em modo produção;
- SQLite isolado com dados fictícios (fixture da NI2-89);
- login real da paciente.

Cada linha "ACHADO" do script é um defeito confirmado.

## Situação por critério

| Critério | Situação | Evidência |
|---|---|---|
| Medidas liberadas pelo nutricionista | **Atende** | Avaliação não liberada não aparece [prova P8, ok] |
| Gráficos acessíveis | **Não atende** | A aba é uma lista de cartões, sem gráfico [P14] |
| Data, unidade, fonte e tipo | **Parcial** | A fonte é indicada (clínico x autorrelato). Mas a data aparece crua, num fuso diferente para cada fonte; o tipo aparece como código técnico ("weight_kg"); a unidade da medição clínica está fixa no texto [P6, P16] |
| Filtros temporais | **Não atende** | Nem a API nem a tela filtram por período [P9, P15] |
| Autorrelato só nos campos habilitados | **Não atende** | O app não tem entrada [P13]; a API aceita qualquer tipo e unidade [P1–P5]; não existe cadastro de campos habilitados |
| Preservar histórico | **Atende** | Não há rota de edição nem de exclusão de autorrelato |
| Linguagem neutra e inclusiva | **Parcial** | Termos técnicos expostos; "Aferido presencialmente" afirma algo que o sistema não sabe (B1) |
| Não inferir diagnóstico | **Atende** | IMC sem classificação e sem recomendação |
| Visibilidade por vínculo / autorização por recurso | **Atende** | Bruno não vê dados da Ana [P10, ok]. Autenticação conferida no teste da NI2-89 |
| Valores inválidos | **Não atende** | 4.000 kg, unidade incoerente e texto de 100 mil caracteres aceitos [P2–P4] |
| Ausência de medidas | **Atende** | Estado vazio "Sem avaliações clínicas cadastradas" |
| Integração NI2-7 | **Não atende** | O profissional não vê os autorrelatos em lugar nenhum [P17] |

## Problemas altos

| # | Arquivo | Linha | Problema |
|---|---|---|---|
| A1 | `PatientPortalService.php` | 247–265 | **[prova P7] Observação interna do profissional chega à paciente.** `getEvolutionTimeline` devolve `anthropometric_assessments.notes` de toda avaliação liberada. Na prova, a paciente recebeu "Obs. interna: suspeita de compulsão alimentar, discutir com psicóloga". Liberar a avaliação (`is_shared`) não deveria liberar as anotações clínicas, que podem conter hipóteses sensíveis; isso contraria também "não inferir diagnósticos". **Correção:** não enviar `notes` na API da paciente; se houver um texto para a paciente, criar um campo próprio ("orientação para a paciente") preenchido de propósito. |
| A2 | `public/index.php` / `PatientPortalService.php` | 982–1010 / 190–235 | **[prova P1–P5] Autorrelato sem "campos habilitados".** A API aceita: tipo inventado; unidade "xyz"; peso com unidade "lb"; 4.000 kg; observação de 100 mil caracteres. Sem tipo nem unidade, assume peso em kg. **Correção:** (1) lista fechada de métricas por clínica ou paciente, cada uma com unidade fixa e faixa plausível (ex.: peso 20–400 kg, cintura 30–250 cm); (2) habilitação pelo profissional (NI2-7); (3) 422 para tipo não habilitado, unidade divergente ou valor fora da faixa; (4) observação com limite (ex.: 500). |
| A3 | `PatientHomeScreen.tsx` | 902–932 | **[prova P13–P15] O app não entrega a jornada da história.** Não há formulário de autorrelato (`logSelfMetric` existe no cliente, mas nenhuma tela o chama), nem gráfico, nem filtro de período. **Correção:** gráfico de linha por métrica, com marcadores distintos para clínico e autorrelato e alternativa em tabela para leitor de tela; filtro (30 dias, 3 meses, 12 meses, tudo); formulário só com as métricas habilitadas; `?from=&to=` na API. |

## Sugestões (médias e baixas)

| # | Arquivo | Linha | Sugestão | Categoria |
|---|---|---|---|---|
| M1 | `PatientPortalService.php` / `PatientHomeScreen.tsx` | 212–215 / 910, 925 | **[prova P6, P16] Datas e tipos.** O autorrelato é gravado com `CURRENT_TIMESTAMP` (UTC no SQLite; no MySQL depende da sessão), mas a criação devolve `date()` no fuso da aplicação: 18:38 gravado e 15:38 devolvido. A avaliação clínica usa o fuso local. A tela mostra o texto cru do banco e o tipo técnico ("weight_kg: 63.4 kg"). Gravar em um fuso definido, devolver em ISO 8601 com fuso, formatar com `displayDateTime` e traduzir o tipo ("Peso"). | Correção / clareza |
| M2 | API / app | — | **[prova P11] Não há metas.** O título da história e da aba fala em metas, mas não existe modelo, rota nem tela. Definir com produto (meta definida pelo profissional, com prazo e sem cobrança automática) ou tirar "Metas" do título da aba. | Escopo |
| M3 | — | — | **[prova P17] O profissional não vê os autorrelatos.** Nenhuma tela ou rota do lado profissional lê `patient_self_metrics`, então o que a paciente registra não chega à nutricionista (integração NI2-7). Mostrar na evolução do paciente, marcado como autorrelato e separado das medições clínicas. | Integração |
| M4 | `PatientPortalService.php` | 247–290 | **[prova P12]** Sem limite nem paginação: com uma observação longa, a resposta passou de 100 KB. Limitar o período padrão (ex.: 12 meses) ou paginar, além do limite de texto de A2. | Desempenho |
| B1 | `PatientHomeScreen.tsx` | 910–913, 925 | Os títulos com emoji ("📊", "📝") são lidos pelo leitor de tela; preferir ícone com `accessibilityElementsHidden` e texto. "Aferido presencialmente pelo profissional" pode ser falso, já que a avaliação pode vir de teleconsulta; usar "Registrado pelo seu nutricionista". A unidade "kg" do peso clínico está fixa no texto. | Acessibilidade / texto |
| B2 | testes | — | Nenhuma suíte cobre os negativos acima pela API. `review_ni2_135_provas.cjs` serve de base: inverter as asserções depois das correções. | Testes |

## O que está bom

- **Proveniência na API:** `clinical_official` x `patient_self_report`, com listas separadas e resumo de contagem.
- **Só o que foi liberado:** a paciente vê apenas avaliações com `is_shared = 1`, e liberar ou revogar registra quem fez e quando (`shared_at`, `shared_by_user_id`).
- **Isolamento:** tudo por paciente e clínica (`TenantGuard`), com token obrigatório.
- **Sem inferência clínica:** IMC sem classificação, sem alerta e sem sugestão de mudança de dieta.
- **Validações básicas:** valor positivo e finito no autorrelato; histórico não editável.
- **Estados de tela:** carregamento, erro com nova tentativa e vazio (`ResourceNotice`).

## Provas (`tests/review_ni2_135_provas.cjs`, commit `aa8bb1d`)

```
ACHADO P1: autorrelato com tipo e unidade arbitrários ("pressao_arterial_inventada", "xyz") → HTTP 201
ACHADO P2: peso autorrelatado de 4000 kg → HTTP 201
ACHADO P3: tipo weight_kg com unidade "lb" (incoerente) → HTTP 201
ACHADO P4: observação de 100.000 caracteres → HTTP 201
ACHADO P5: sem metric_type nem unit, o servidor assume peso em kg → HTTP 201, tipo "weight_kg"
ACHADO P6: horário devolvido na criação "2026-10-21 15:38:52" x gravado "2026-10-21 18:38:52" (UTC); a avaliação clínica usa o fuso da aplicação
ACHADO P7: GET /evolution devolve à paciente a observação interna (notes) da avaliação liberada
ok     P8: avaliação NÃO liberada (is_shared = 0) não aparece para a paciente
ACHADO P9: filtro temporal: sem filtro 6 registros; com from/to em 2030 6 registros (parâmetros ignorados; não há filtro na API)
ok     P10: Bruno não vê autorrelatos nem avaliações da Ana
ACHADO P11: resposta da evolução não tem metas (chaves: patient_id, clinical_records, self_reports, summary)
ACHADO P12: resposta de /evolution sem paginação nem limite: 101032 bytes (inclui a observação de 100 mil caracteres)
ACHADO P13: tela do app não oferece registrar autorrelato (ApiService.logSelfMetric existe, mas a tela não usa)
ACHADO P14: aba Evolução não tem gráfico (só lista de cartões)
ACHADO P15: aba Evolução não tem filtro de período
ACHADO P16: tela mostra o código técnico do tipo (ex.: "weight_kg") e a data crua do banco, sem formatar nem converter o fuso
ACHADO P17: nenhuma tela do profissional mostra os autorrelatos da paciente
```

## Ordem sugerida de correção

1. **A1:** parar de enviar `notes` à paciente. É pequeno e urgente.
2. **A2 + M1:** lista de métricas com unidade e faixa, 422 para o resto, datas com fuso definido. Precisa de decisão de produto sobre quais métricas a paciente pode registrar.
3. **A3:** formulário de autorrelato, gráfico acessível e filtro de período no app; `from`/`to` na API.
4. **M3:** autorrelatos visíveis para o profissional.
5. **M2, M4, B1, B2:** metas (ou ajustar o título), paginação, textos e testes.
