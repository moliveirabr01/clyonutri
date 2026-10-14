# NI2-323 — Correções do code review

Data: 10/10/2026. Escopo: os seis findings do review; não representa conclusão dos 22 findings do épico.

## Correções

- Versões editadas armazenam ingredientes, alergênicos, preparo e metadados. Leitura histórica não combina ingredientes atuais com nutrientes antigos. Versões legadas incompletas apresentam erro explícito e exigem nova prescrição; o conteúdo perdido não é reconstruído artificialmente.
- O cliente envia `prescription_id` ao consultar a receita. A API verifica receita, paciente e clínica da prescrição, e usa a versão vinculada. Um `version_id` divergente é rejeitado.
- Registro alimentar usa chave por operação e trava síncrona. Retry preserva chave, descrição e horário até confirmação, mesmo mudando de minuto. Após confirmação, um novo consumo recebe nova chave. A operação pendente fica em memória da tela; persistência após kill/reopen não foi implementada neste escopo.
- Migração 29 impõe índice único por paciente/clínica/chave. Colisões de inserção retornam o registro existente. Duplicatas prévias são preservadas: somente as chaves excedentes tornam-se nulas. Não há exclusão de registros alimentares.
- Timeout de 15 segundos cobre cabeçalhos e leitura do corpo. Falha de leitura por cancelamento é identificada como timeout.
- A suíte E2E cria seu próprio SQLite e servidor HTTP em porta dinâmica e remove os recursos ao terminar. Exige assertions habilitadas. A saída limita sua conclusão aos cenários realmente executados.

## Verificação

- `npm --prefix mobile run typecheck` e verificações de sintaxe PHP.
- `php tests/ni2_323_mobile_ux_remediation_e2e_test.php`: sete cenários de API; regressões adicionais v1/v2 após edição v3, seleção da prescrição, rejeição de vínculo inválido e snapshot incompleto, oito processos independentes gravando a mesma operação.
- `node tests/ni2_323_mobile_client_regression_test.cjs`: corpo HTTP pendente, prazo acelerado somente no teste (15s para 100ms), execução do handler de produção com retry após 61s, payload preservado, novo consumo e duplo toque. Teste de lógica, não interação em dispositivo.
- `php tests/ni2_323_diary_migration_test.php`: preservação de registros preexistentes e unicidade por paciente/clínica em SQLite em memória.
- `node tests/patient_recipe_prescription_http_test.cjs`: prescrição, onboarding e isolamento de acesso.
- `node tests/recipe_edit_save_http_test.cjs`: duas edições com persistência e novas versões.

Migrações 28 e 29 aplicadas ao SQLite local após backup em `storage/backups/ni2-323-before-*.sqlite`. Dados de teste foram escritos somente em bancos temporários. A alteração do banco local limitou-se à migração.

## Limites

Android, iOS, TalkBack, MySQL e testes moderados com usuários não foram executados. Não se declara conclusão do épico, aprovação de piloto nem métricas de usabilidade. Alterações anteriores do workspace foram preservadas; nenhum commit, publicação ou atualização do Jira foi realizado.
