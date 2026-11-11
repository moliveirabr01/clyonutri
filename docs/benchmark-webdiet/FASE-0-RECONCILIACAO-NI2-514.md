# NI2-514, Fase 0: reconciliação entre as issues e o código da v40

Data: 28/10/2026 (America/Sao_Paulo). Base examinada: v40 (`feat/plano-perfil-v40`, commit d590602), mais o que a v41 acrescenta.
Método: leitura estática do código, das migrações e do esquema de produção (somente estrutura), e testes locais com dados "TESTE".
O que este relatório **não** fez: não acessou produção, não abriu homologação, não leu nem alterou nada no Jira ou no Confluence (as issues foram só lidas).

## Como ler as classificações

Cada ponto recebe uma destas marcas, para não confundir "não testei" com "não existe":

- **Existe e verificado**: está no código e um teste local o exercita.
- **Incompleto**: há parte do código (tabela, serviço), mas falta rota, tela ou o critério inteiro.
- **Dado**: o mecanismo existe, mas a base está vazia ou sem origem documentada.
- **Feature flag**: depende de configuração; não foi possível ver o valor em produção.
- **Falha de descoberta**: existe no código, mas o profissional não chega até ele pela interface.
- **Não verificado**: não foi possível comprovar nesta rodada. Isto **não** significa ausente.

Limite desta fase: a análise é do código. O comportamento em produção só se confirma lá. Onde a issue está "Feito" no Jira e o código não mostra o critério, isso é uma divergência a conferir, não uma conclusão de que a entrega falhou.

## Quadro geral

| Issue | Jira | O que o código da v40 mostra | Classificação | O que a v41 faz |
|---|---|---|---|---|
| NI2-159 | Feito | Conversor com ordem fixa (massa, medida, porção do alimento, tabela padrão com confirmação, erro para unidade desconhecida). Teste NI2-74 cobre. | Existe e verificado | Preservado. Acrescenta a medida da clínica antes da global. |
| NI2-114 | Feito | Editor de refeições, versionamento e publicação existem. Substituição guarda kcal e macros, mas não há cálculo de equivalência por energia ou macro nem tolerância configurável. Modelos: serviço e tabela, sem rota. | Parte existe, parte **incompleta** (equivalências) e **falha de descoberta** (modelos) | Sem alteração nesta rodada (Fase 2). |
| NI2-120 | Feito | `CompetitorMigrationService` importa pacientes por CSV e tem testes. Não há rota nem tela que o chame na v40. Sem XLSX e sem importação de documentos. | **Falha de descoberta** (serviço sem entrada na interface) e **incompleto** (XLSX, documentos) | Nada nesta rodada (Fase 3). |
| NI2-183 | A fazer | Catálogo TACO de 597 itens. Medidas caseiras: 3 dos 12 alimentos de referência recebem medidas no seed, mais as da migração 41, sem fonte registrada. Tabela global sem coluna de fonte e **vazia** no dump de produção (somente estrutura examinada). | **Dado** (base vazia ou sem origem) | Mecanismo de medidas da clínica com fonte obrigatória e importação (ver Fase 1b). Não carrega valores sem fonte verificável. |
| NI2-440 | A fazer | `TemplateService` e tabela `meal_plan_templates` existem. Nenhuma rota ou tela os usa. | **Incompleto** e **falha de descoberta** | Plano sem paciente (rascunho-modelo) como degrau. Biblioteca de modelos fica para a Fase 2. |
| NI2-228 | A fazer | Anamnese versionada por paciente. Não há tabela nem serviço de modelo de anamnese. | **Incompleto** (não encontrei o recurso no código) | Nada nesta rodada. |
| NI2-230 | A fazer | Tabela `patient_documents` e `PatientPortalService` com validação de tipo e 15 MB. Nenhuma rota de envio ou download no `public/index.php`. | **Incompleto** e **falha de descoberta** | Nada nesta rodada. |
| NI2-439 | Fazendo | `SkinfoldProtocolCatalog` (versão 2026.10-r1) com 5 protocolos (Jackson e Pollock 7 e 3 dobras, Jackson, Pollock e Ward, Durnin e Womersley) e Siri; limites e alertas de medida improvável. Perímetros: 7 (cintura, quadril, pescoço, tórax, braço, coxa, panturrilha). Série temporal e gráficos não verificados. | Existe e verificado (cálculo); gráficos **não verificados** | Nada nesta rodada (Fase 2). |
| NI2-288 | A fazer | Formulários longos de anamnese e antropometria; rascunho de anamnese existe (`isDraft`). Autosave e agrupamento não verificados. | **Não verificado** | Nada nesta rodada. |
| NI2-282 | Fazendo | `OnboardingService` com 5 passos, progresso salvo por usuário e clínica, retomada e `/onboarding`. | Existe e verificado (código); jornada completa **não verificada** | Nada nesta rodada. |
| NI2-487 | A fazer | Confirmação de consulta por e-mail não aparece nos serviços de agenda e de reserva. SMTP não homologado, segundo a issue. | **Não verificado** (depende de envio real) | Fora do escopo (Fase 3). |

## Detalhe por tema

### Energia e meta (base da NI2-514, não está nas issues acima)
- v40: não há TMB, GET, VET nem objetivo por paciente. O plano mostra só os totais das refeições. Classificação: **ausente no código** (confirmado por busca).
- v41: `EnergyCalculator` (Mifflin-St Jeor, Harris-Benedict revisada, Cunningham com massa magra) e fatores de atividade FAO/OMS/UNU 2001. A fórmula, as entradas e a versão do cálculo ficam registradas no plano e na versão publicada. Macros em g/kg ou em %, conferidas contra o VET.
- **Não é validação clínica.** As fórmulas e os padrões precisam ser conferidos pela nutricionista responsável (gate 5 da épica).

### Medidas caseiras (NI2-183, NI2-159)
- Mecanismo: tabela global `food_household_measures` (sem fonte), conversor, exibição no resultado da busca ("Porção: ..."). Existe e funciona.
- Dados: 3 dos 12 alimentos de referência do seed; a migração 41 insere medidas por padrão de nome, sem fonte; a tabela está vazia no dump de produção. Daí a mensagem "Sem medida caseira cadastrada" que o benchmark viu: é **dado**, não falha do mecanismo.
- v41: tabela `clinic_household_measures` (por clínica, com fonte obrigatória, quem criou e quando), tela `/medidas-caseiras`, importação CSV tudo ou nada com fonte obrigatória e relatório de erros por linha, auditoria. A medida da clínica vale antes da global.
- **Nenhum valor foi inventado ou carregado.** A base de medidas que acompanha a v41 está vazia de propósito.

#### Fonte candidata verificável (decisão pendente)
- "Pesquisa de Orçamentos Familiares 2008-2009: Tabela de Medidas Referidas para os Alimentos Consumidos no Brasil", IBGE, 2011 (ISBN 978-85-240-4196-9). Baixada para análise: `liv50000.pdf`, sha256 `317ace59e53e25f3fc4913c5f552adf181bf001e6272edb5ae7a46804aa360b0`, 3.032.908 bytes.
- É uma fonte oficial, mas **a licença de reutilização não está confirmada** (a publicação traz "© IBGE 2011"). Antes de carregar qualquer valor, é preciso confirmar a licença e mapear cada medida ao alimento da TACO sem adivinhar. Por isso a v41 não importa nada dela.

### Modelos e plano sem paciente (NI2-440, NI2-114)
- v40: o plano exigia paciente (`patient_id` obrigatório). Sem rota para a biblioteca de modelos.
- v41: `patient_id` aceita vazio; o plano sem paciente é um rascunho que se atribui depois. Publicar exige paciente atribuído, e a conferência de alergia e o consentimento rodam na publicação como antes. Versionamento preservado.

### Substituições e equivalências (NI2-114)
- v40: substituição manual com kcal e macros guardados; sem cálculo de equivalência automática nem tolerância. Fica para a Fase 2.

## Riscos e lacunas registradas

1. A meta de energia entra na exportação LGPD do titular (v41, testada). Ela acompanha o plano, que fica sob guarda legal junto do prontuário; a decisão de anonimizar ou excluir a meta quando o plano for excluído continua pendente (precisa de OK).
2. O resumo de rascunhos do painel ignora planos sem paciente (junção interna); eles aparecem na lista de planos, não no resumo.
3. As medidas semeadas pela migração 41 continuam sem fonte. A tela diz "origem não documentada" para a base global.
4. Divergências Jira x código (NI2-114, NI2-120) pedem conferência em homologação antes de qualquer conclusão.
5. Lacunas de verificação da épica (mobile, app do paciente, teleconsulta ponta a ponta, evolução longitudinal) seguem **não verificadas**.
