# Code Review & Auditoria Técnica de Qualidade Clínica: NI2-523

- **História Jira:** [`NI2-523`](https://caplybr.atlassian.net/browse/NI2-523) — *[BW26][P1] Criar avaliação de qualidade clínica de liberação da prescrição*
- **Épico Relacionado:** [`NI2-514`](https://caplybr.atlassian.net/browse/NI2-514) — *[BW26][P0] Paridade competitiva central da prescrição dietética*
- **Data da Auditoria:** 01/11/2026
- **Auditor:** Antigravity (Pair Programming / Quality Assurance)
- **Branch:** `fix/assinatura-trial-real`
- **Ambiente de Homologação:** Local / macOS (`Clínica TESTE Avaliação`, `uuid: clinica-teste-ni2-523`)

---

## 1. Resumo Executivo e Veredito

| Item | Situação no Jira / Documentação | Situação Real no Código / Execução | Veredito |
| :--- | :--- | :--- | :--- |
| **Status da Issue** | `Feito` (Resolução: *Itens concluídos*) | Código técnico implementado, mas **Gates 4 e 5 humanos incompletos** | **NÃO LIBERADO** |
| **Suíte de Cenários Clínicos** | Documentado como `25/25 PASS` em `RELATORIO-AVALIACAO-CLINICA-NI2-523.md` | Execução real inicial: **22 PASS, 3 FAIL** (corrigido para 25/25) | **CORRIGIDO** |
| **Homologação com 5 Nutricionistas** | Declarado no escopo da issue como obrigatório | Documento oficial `ROTEIRO-AVALIACAO-CLINICA-V42.md` declara: *"sessões não realizadas"* | **BLOQUEADO** |
| **Gate de Liberação Assinado** | Critério de aceite vinculante da issue | Seção 7 de `ROTEIRO-AVALIACAO-CLINICA-V42.md` em branco (sem nome, sem CRN, sem assinaturas) | **BLOQUEADO** |

> [!CAUTION]
> **VEREDITO DA AUDITORIA: REPROVADO PARA RELEASE (RELEASE BLOCKED)**
>
> A issue `NI2-523` foi marcada como **"Feito"** no Jira em violação direta à sua própria regra de negócio e aos critérios de aceite estritos:
> 1. O documento canônico [`ROTEIRO-AVALIACAO-CLINICA-V42.md`](file:///Users/moliveira/Nutri_II/docs/benchmark-webdiet/ROTEIRO-AVALIACAO-CLINICA-V42.md) avisa explicitamente em sua linha 3:  
>    *“Situação: roteiro pronto, sessões não realizadas. Este documento é o instrumento da NI2-523. Ele não substitui a avaliação: a liberação só acontece depois das sessões com nutricionistas reais e da assinatura no fim deste arquivo.”*
> 2. As **5 sessões com nutricionistas reais** (incluindo pelo menos 2 ex-usuários do WebDiet) não foram executadas e a folha de decisão com número de CRN do responsável técnico permanece sem preenchimento.
> 3. Foi identificado um **False Pass** de documentação: o arquivo [`RELATORIO-AVALIACAO-CLINICA-NI2-523.md`](file:///Users/moliveira/Nutri_II/docs/benchmark-webdiet/RELATORIO-AVALIACAO-CLINICA-NI2-523.md) reportava `25/25 PASS` para a suíte [`tests/ni2_514_v42_clinical_scenarios_test.php`](file:///Users/moliveira/Nutri_II/tests/ni2_514_v42_clinical_scenarios_test.php), porém a execução real acusou **3 falhas** em produção nos alertas clínicos populacionais (gestantes, adolescentes e idosos). Essa divergência foi devidamente corrigida nesta auditoria no [`EnergyCalculator.php`](file:///Users/moliveira/Nutri_II/src/Domain/Nutrition/EnergyCalculator.php) e [`EnergyTargetService.php`](file:///Users/moliveira/Nutri_II/src/Domain/MealPlan/EnergyTargetService.php).

---

## 2. Matriz de Critérios de Aceite (Jira NI2-523)

| # | Critério de Aceite Oficial (Jira) | Evidência Técnica Auditada | Status Real |
| :--- | :--- | :--- | :--- |
| **CA1** | *“Quando executo suíte clínica, então nenhuma divergência de cálculo acima de tolerâncias aprovadas fica sem triagem.”* | Suíte [`tests/ni2_514_v42_clinical_scenarios_test.php`](file:///Users/moliveira/Nutri_II/tests/ni2_514_v42_clinical_scenarios_test.php) executada via PHP CLI. Valida TMB, GET, VET, Atwater (4/4/9 kcal/g), tolerância de 5% de energia diária e alertas de desvio. | **APROVADO (25/25 PASS pós-fix)** |
| **CA2** | *“Quando publico plano, então versão, referências e alertas são rastreáveis.”* | Snapshot imutável gerado na tabela `meal_plan_versions` (v1 e v2 preservadas). Alerta de conflito de alergia bloqueia publicação e exige override com justificativa mínima de 10 caracteres registrada em `audit_logs`. | **APROVADO** |
| **CA3** | *“Quando cinco nutricionistas executam roteiros comparáveis, então relatório contém medidas reais.”* | O provisionador [`bin/preparar_avaliacao_clinica_ni2_523.php`](file:///Users/moliveira/Nutri_II/bin/preparar_avaliacao_clinica_ni2_523.php) provisionou as 5 contas e pacientes teste, mas nenhuma folha de tempo/clique/satisfação humana foi preenchida. | **NOT TESTED / BLOCKED** |
| **CA4** | *“Gate de liberação documentado e decisão de release assinada.”* | Seção 6 e 7 de [`ROTEIRO-AVALIACAO-CLINICA-V42.md`](file:///Users/moliveira/Nutri_II/docs/benchmark-webdiet/ROTEIRO-AVALIACAO-CLINICA-V42.md) permanecem com checkboxes desmarcados e campos de assinatura vazios. | **BLOCKED** |

---

## 3. Auditoria Forense dos 11 Cenários Clínicos (`C1` a `C11`)

A suíte [`tests/ni2_514_v42_clinical_scenarios_test.php`](file:///Users/moliveira/Nutri_II/tests/ni2_514_v42_clinical_scenarios_test.php) foi executada e inspecionada detalhadamente:

```
=== NI2-523 — Homologação Clínica dos 11 Cenários (C1 a C11) e 5 Populações Elegíveis ===
  [PASS] Preparação NI2-523: Clínica TESTE Avaliação provisionada com 5 nutricionistas (P1..P5), 1 recepção e 5 pacientes TESTE
  [PASS] C1: Pré-preenche peso (88 kg), altura (176 cm), idade (34 a) e sexo da avaliação/cadastro
  [PASS] C1: Calcula TMB (1815 kcal), GET (2541 kcal) e VET (2041 kcal) com fórmula e versão rastreadas
  [PASS] C1: Plano nasce com as 6 refeições padrão pré-carregadas
  [PASS] C1: Barra de totais confirma energia diária (2010 kcal) dentro da tolerância de 5% da meta (2041 kcal)
  [PASS] C2: Gestante 2º trimestre recebe alerta clínico específico sobre peso pré-gestacional e adicional do trimestre
  [PASS] C2b: Pré-calcula massa livre de gordura (54,0 kg) para o adolescente atleta
  [PASS] C2b: Adolescente (15 anos) exibe aviso de fórmula desenvolvida em adultos mesmo em Cunningham
  [PASS] C3: Alerta de conflito alergênico (amendoim) é detectado na prévia do plano
  [PASS] C3: Publicação sem justificativa clínica é bloqueada quando há conflito de alergia
  [PASS] C3b: Publicação com override expresso e justificativa clínica (>=10 chars) é aceita e auditada
  [PASS] C4: Medida caseira da clínica converte 2 colheres de servir cheias em 90 g exatos com procedência da clínica
  [PASS] C5: Busca por 'aipim' encontra 'Mandioca, cozida' em 1º lugar com preparo 'Cozido' visível
  [PASS] C5: Busca por 'macaxeira' encontra 'Mandioca, cozida' em 1º lugar com preparo 'Cozido' visível
  [PASS] C6: Novo plano criado do modelo para o Idoso traz as 6 refeições e NÃO copia a meta individual do paciente anterior
  [PASS] C6: Cálculo energético para o idoso (72 anos) emite alerta clínico para >= 65 anos
  [PASS] C7: Substituição apresenta diferença exata de kcal/macros e avisa que não há equivalência automática entre grupos sem regra
  [PASS] C8: Publica versão 1 do plano C1
  [PASS] C8: Publica segunda versão preservando integralmente a versão 1 e seus snapshots em meal_plan_versions
  [PASS] C9: Impressão do plano renderiza título, refeições, alimentos e orientações sem erro
  [PASS] C10: Prévia da planilha CSV TESTE de 10 linhas identifica 8 válidos e 2 com erro (sem nome e data 31/02/1990)
  [PASS] C10: Confirmação cadastra exatamente os 8 pacientes válidos sem duplicação
  [PASS] C10: Desfazer importação remove os 8 pacientes TESTE recém-importados
  [PASS] C11a: Modelo salvo não armazena nome, peso, altura nem ID do paciente de origem
  [PASS] C11b: Perfil de recepção não possui registro de prescritor (professionals) e é bloqueado nas rotas de prescrição/modelos/importação

Resultado NI2-523 Clinical Scenarios (C1..C11): 25 PASS, 0 FAIL
```

### Análise Específica por Cenário:

1. **C1 — Adulto em Redução de Peso (34a, M, 88 kg, 176 cm):**
   - TMB Mifflin-St Jeor: $10 \times 88 + 6{,}25 \times 176 - 5 \times 34 + 5 = 1.815{,}0\text{ kcal}$.
   - NAF `1,40`: $\text{GET} = 1.815 \times 1{,}40 = 2.541{,}0\text{ kcal}$.
   - Déficit `-500 kcal`: $\text{VET} = 2.041{,}0\text{ kcal}$.
   - Plano alimentar com 6 refeições somando `2.010,1 kcal` fica com desvio inferior a 5%, ativando o status visual verde (`ok`) na barra de totais.
2. **C2 & C2b — Populações Especiais (Gestante e Adolescente Atleta):**
   - *Falha identificada:* O cálculo de gestante (`+340 kcal/dia`) não disparava o aviso clínico sobre peso pré-gestacional, e a fórmula de Cunningham não emitia o aviso de idade para menores de 18 anos.
   - *Resolução:* Implementadas as validações populacionais no [`EnergyCalculator::compute`](file:///Users/moliveira/Nutri_II/src/Domain/Nutrition/EnergyCalculator.php#L125-L135) e propagação de `goal_note` e idade sugerida no [`EnergyTargetService::save`](file:///Users/moliveira/Nutri_II/src/Domain/MealPlan/EnergyTargetService.php#L162-L170).
3. **C3 — Bloqueio de Alergias e Override Auditado:**
   - Detecção do grupo `amendoim` pelo `AllergenSafetyValidator`.
   - Tentativa de publicação bloqueia com `InvalidArgumentException` se `override = false`.
   - Publicação com `override = true` e justificativa $\ge 10$ caracteres persiste a justificativa e gera log rastreável em `audit_logs`.
4. **C4 — Medidas Caseiras Customizadas da Clínica:**
   - `HouseholdMeasureConverter` localiza a medida da clínica (`colher de servir cheia = 45 g`), multiplicando para $90{,}0\text{ g}$ e marcando `origin = clinic`, sobrepondo pesos genéricos.
5. **C5 — Busca Regional com Sinônimos e Formas de Preparo:**
   - Termos `"aipim"` e `"macaxeira"` mapeados para `"Mandioca, cozida"`, priorizando preparo cozido sobre cru via `FoodSearchIndex`.
6. **C6 — Modelos e População Idosa ($\ge 65$ anos):**
   - Criação de plano derivado de modelo importa estrutura e alimentos, mas mantém `energy_target = null` (não copia a meta calórica individual do paciente anterior).
   - Cálculo para 72 anos emite alerta geriátrico para avaliação de sarcopenia e adequação do aporte proteico.
7. **C7 — Comparação de Substituições:**
   - `SubstitutionComparison::sameEnergyGrams` calcula equivalência calórica exata e alerta ausência de substituição automática entre grupos distintos sem regra expressa da clínica.
8. **C8 — Versionamento e Snapshots Imutáveis:**
   - Duplicação e reedição preservam a versão 1 intacta na tabela `meal_plan_versions`.
9. **C9 — Impressão A4:**
   - Renderização limpa em HTML/CSS para impressão sem dependência de geradores pesados e sem quebras de layout.
10. **C10 — Importação CSV e Desfazimento Atômico:**
    - Arquivo [`docs/benchmark-webdiet/pacientes_teste_ni2_523.csv`](file:///Users/moliveira/Nutri_II/docs/benchmark-webdiet/pacientes_teste_ni2_523.csv) com 10 linhas: 8 válidos cadastrados, 2 com erro (sem nome e data inexistente `31/02/1990`) rejeitados com mensagem descritiva. Rollback total limpa os 8 registros sem deixar orfãos.
11. **C11 — Privacidade, RBAC e Multi-Tenancy:**
    - Modelos de planos alimentares não salvam PII (nome, documento, peso, idade do paciente).
    - Perfil `receptionist` não possui permissão de prescrição (HTTP 403).

---

## 4. Diagnóstico do "Erro 79" no `clyonutri-v49-native.zip`

O erro apresentado no Utilitário de Compressão do macOS:
`Não foi possível expandir "clyonutri-v49-native.zip" em "Downloads". (Erro 79 - Tipo ou formato de arquivo inadequado.)`

### Causa Raiz:
1. **Corrupção de Stream no Download via Chat:** Quando um arquivo binário `.zip` é baixado através do navegador na interface do chat web em conexões que tratam o payload como texto, ocorre uma decodificação incorreta dos bytes $> 127$ para caracteres UTF-8. O arquivo infla (passando de ~10 MB para ~19.5 MB) e corrompe a assinatura de cabeçalho ZIP (`PK\x03\x04`).
2. **Arquivo Original Válido no Disco Local:** No ambiente local da máquina do usuário (`mac`), o arquivo original gerado em [`/Users/moliveira/Nutri_II/release/clyonutri-v49-native.zip`](file:///Users/moliveira/Nutri_II/release/clyonutri-v49-native.zip) possui exatamente **10.8 MB** e passa com sucesso nos testes de integridade (`zip -T` e `ditto`).

### Como Usar Sem Passar pelo Chat:
Para copiar o pacote diretamente para a pasta Downloads sem risco de corrupção de rede/navegador:
```bash
cp /Users/moliveira/Nutri_II/release/clyonutri-v49-native.zip ~/Downloads/
```

---

## 5. Pendências para Liberação Real da NI2-523

Para que a issue `NI2-523` possa ser legitimamente marcada como concluída e aprovada para release, os seguintes passos são estritamente necessários:

1. **Reverter o Status no Jira:** Alterar o status de `Feito` para `Em Homologação / Bloqueado` até o cumprimento dos gates humanos.
2. **Executar as 5 Sessões Clínicas:**
   - Conduzir o roteiro com 5 nutricionistas reais (com pelo menos 2 ex-usuários do WebDiet).
   - Registrar tempos, contagem de cliques e notas de satisfação nas tabelas da Seção 5 de [`ROTEIRO-AVALIACAO-CLINICA-V42.md`](file:///Users/moliveira/Nutri_II/docs/benchmark-webdiet/ROTEIRO-AVALIACAO-CLINICA-V42.md).
3. **Assinatura do Responsável Técnico e do Produto:**
   - Preencher o nome, número do CRN e decisão formal na Seção 7 do roteiro.
4. **Atualizar o Relatório Técnico:**
   - Anexar as evidências das sessões e o veredito assinado ao [`RELATORIO-AVALIACAO-CLINICA-NI2-523.md`](file:///Users/moliveira/Nutri_II/docs/benchmark-webdiet/RELATORIO-AVALIACAO-CLINICA-NI2-523.md).
