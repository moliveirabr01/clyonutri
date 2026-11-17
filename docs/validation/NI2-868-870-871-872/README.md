# NI2-868, NI2-870, NI2-871 e NI2-872 — Correções

Alterações locais anteriores preservadas. As correções parciais já presentes na pasta foram revisadas, complementadas e testadas. Sem deploy, pacote, migração ou alteração do .env.

## NI2-868 — Contexto da agenda

A preservação de view/date no sucesso já existia parcialmente. Centralizada em AgendaReturnLocation, com validação de data civil (incluindo rejeição de arrays, datas inexistentes e byte nulo), lista fechada de visões e destino interno fixo. Sucesso, erro de transição, erro genérico e CSRF preservam contexto válido. Sem data válida, o sucesso usa a data do agendamento; as demais respostas usam a data atual da aplicação.

Formulários de confirmação/cancelamento/falta já carregavam contexto; os formulários de início agora também carregam data e visão para retorno em caso de erro. O início bem-sucedido continua levando à sala.

Validação pelo front controller real, via PHP-CGI: login com senha, sessão persistente, autenticação e CSRF; confirmar/cancelar/marcar falta para dia, semana e mês. Nove respostas com Location esperado e status persistido no SQLite. Não houve bypass de login/tenant. Somente o armazenamento das sessões foi isolado em diretório temporário antes do front controller. Isso valida o protocolo CGI e as respostas da aplicação, não TCP, Apache ou navegação no navegador.

## NI2-870 — Reimportação

A exclusão de lotes desfeitos já estava parcialmente implementada. Regressão comprova preview → confirmação → rollback integral → reenvio do CSV idêntico com novo batch_id, reused=false e status=preview. Refinada a condição: somente discarded/rolled_back são descartáveis. Lotes preview/confirmed/confirming/rolling_back não são removidos pelo reenvio da planilha. Lotes e pacientes continuam escopados à clínica.

## NI2-871 — Telemetria sem texto clínico livre

O filtro por nomes de campos conhecido já existia. Reforçado com normalização de nomes/camelCase, proteção também na construção do ProductionEvent e modo restritivo para categorias/recursos/tipos de eventos clínicos.

Nesse modo, strings desconhecidas viram indicadores de presença/tamanho; somente campos operacionais tipados de estado, ID numérico e hash conservam strings conforme seus formatos restritos. Arrays são processados recursivamente. Permanecem números, booleanos e sinais de execução; não há cópia de justificativa, nota ou texto desconhecido para metadata. Eventos operacionais não clínicos mantêm o comportamento existente, além do filtro de campos já conhecido.

Teste de persistência para prontuário, anamnese, antropometria, planos, prescrições, exames e protocolos. Publicação real de plano comprova justificativa original em meal_plan_versions e audit_logs, enquanto production_events contém somente indicadores. Eventos históricos existentes não foram regravados nem excluídos.

## NI2-872 — Rótulos, intervalo e alérgenos

- WorkflowState já traduzia superseded, mas a aba Plano usa LabelMap, que ainda não o traduzia. Corrigido esse mapa e completados draft/archived no WorkflowState. Testados os quatro estados canônicos de plano nas duas apresentações.
- Intervalo usa upsert na política efetiva, evitando o padrão UPDATE + rowCount()==0 + INSERT que falha no MySQL quando o valor já é o mesmo. Apenas um intervalo explicitamente enviado altera a política; campo ausente preserva o intervalo atual. Limites 0–120, somente gestão ativa da clínica; os demais campos da política são preservados. A UI atual do onboarding não possui campo de intervalo; clientes que enviam esse parâmetro passam a gravar a política real com essas validações.
- Confirmado em execução real que 09:00–09:45 impede outra consulta às 09:45 com intervalo de 15 minutos e permite às 10:00.
- Aviso de publicação separado em partial: lista conflitos e alimentos sem informação independentemente, inclusive quando coexistem. Corrigida a chave do grupo de alérgeno. Falta de informação é apresentada como incerteza; não como conflito detectado. Confirmação e justificativa continuam obrigatórias quando exigidas pelo validador.
- A execução encontrou PatientAllergyRecord lendo a alergia cifrada sem decifrar. Corrigida a leitura, após filtro por clínica/paciente, usando ClinicalEncryptionService. O armazenamento permanece cifrado e a alergia a amendoim passa a ser reconhecida para detectar o conflito real.

## Evidências executadas

| Comando | Resultado |
| --- | --- |
| `php tests/ni2_868_870_871_872_regression_test.php` | 42 PASS, 0 FAIL |
| `python3 tests/ni2_868_cgi_test.py` | Login real + 9 transições/retornos aprovados via CGI |
| `php tests/ni2_514_v42_phase2_http_test.php` | 12 PASS, 0 FAIL; apesar do nome, esta suíte usa serviços/SQLite e inspeção de HTML/JS, sem requisição HTTP |
| `php tests/ni2_767_production_events_center_test.php` | 32 PASS, 0 FAIL |
| `php tests/meal_plan_and_prescription_lifecycle_test.php` | 48 PASS, 0 FAIL |
| `php -d zend.assertions=1 -d assert.exception=1 tests/onboarding_guided_and_activation_e2e_test.php` | 9 gates aprovados. Fixtures dos dois donos de consultório ajustadas para role owner, pois alteram configuração global; teste negativo de membro sem gestão na suíte nova. |
| `./bin/run-tests core` | 21 suítes OK, 1 falha |

**Falha restante do gate:** `tests/v5469_ni2_879_and_cr360_fixes_test.php` tenta criar atendimento imediato de 50 minutos perto de 23h; o validador de jornada rejeita o término após meia-noite. O stack trace aponta createTodayWalkInKeepingFuture → schedule → assertAvailability, em código não alterado por este conjunto de correções. A suíte passava mais cedo nesta mesma sessão. A lógica e o teste não foram afrouxados para tornar o gate verde; requer tratamento específico da NI2-879 ou fixture com relógio controlado.

Sintaxe PHP dos 11 arquivos principais e git diff --check: sem erros. A suíte conjunta foi incluída no perfil core.

Navegação browser/TCP/Apache e MySQL real: **NOT TESTED**. A alternativa CGI evita a restrição de porta local e exercita o front controller, mas não substitui esses ambientes. O upsert MySQL precisa ser validado em MySQL real antes de publicar. Não foram declarados deploy ou conclusão das issues no Jira.
