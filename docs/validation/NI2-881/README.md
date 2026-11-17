# NI2-881 — Ocultação de manipulados sem catálogo validado

## Decisão e implementação

Opção A do escopo registrada na issue antes de codificar (comentário 11899). A Opção B não foi implementada; nenhum catálogo clínico ou revisão regulatória foi criado em produção.

A checagem única `PrescriptionAvailability` usa o mesmo filtro do catálogo do editor: clínica, ingrediente habilitado, categoria suportada, regra allow e política habilitada dentro da vigência UTC. Sem fallback para catálogo de outra clínica. Não guarda disponibilidade em cache, portanto revogar regra/política ou desativar ingrediente reflete na próxima leitura.

Quando indisponível:

- Aba Documentos e resumo da ficha não mostram entrada ou promoção de manipulados.
- “Mais ações” do editor de plano não mostra manipulados.
- Seção de prescrições do prontuário é omitida.
- Portal web não mostra o cartão Prescrições.
- A leitura usada pelo GET `/api/v1/patient/prescriptions` devolve lista vazia, inclusive se houver registros históricos. Autenticação, vínculo e clínica são validados antes dessa resposta.
- Controller do acesso direto autorizado `/pacientes/{id}/prescricoes` responde 200 com “Recurso ainda não disponível neste consultório”, link para Documentos e ação de orientação no prontuário. Não carrega editor ou botão “Nova prescrição”. Paciente de outra clínica continua bloqueado.
- Removida a referência a `bin/prescription_governance.php` da view.

Com catálogo validado, editor, links, cartão e lista permanecem disponíveis. A disponibilidade é uma regra de apresentação/listagem; as validações clínicas, assinatura e autorização de downloads existentes não foram substituídas. Nenhum documento histórico foi regravado ou apagado. Sem migração de banco, alteração de .env, pacote ou deploy.

## Validação

- `php tests/ni2_881_prescription_availability_test.php`: **27 PASS, 0 FAIL** com SQLite isolado e renderização dos controllers/views reais. Cobre catálogo vazio/validado, ingrediente sem regra, deny, política expirada/futura/desabilitada, ingrediente desabilitado, isolamento entre clínicas, Documentos, ficha, menu do plano, prontuário, portal, status do controller e leitura usada pela API.
- `./bin/run-tests core`: **20 suítes OK, 0 falhas**, com asserções PHP habilitadas. Nova suíte incluída no gate.
- `php -l` nos 12 arquivos PHP tocados/criados: sem erros.
- `git diff --check`: sem erros.
- `python3 tests/ni2_881_http_test.py`: **BLOCKED**. Fixture sintética foi criada; `./bin/serve-local.sh` não abriu `127.0.0.1:18081` por `Operation not permitted`. Nenhuma requisição HTTP da NI2-881 foi declarada PASS. Script preparado para verificar os dois estados da API e autenticação/isolamento.
- Navegação browser e MySQL real: **NOT TESTED**.

Os metadados regulatórios e registros históricos inseridos nos testes são sintéticos e temporários. Não representam catálogo clínico aprovado nem prova de emissão/assinatura de prescrição. Os testes não foram incluídos em pacote de produção.

Implementação local disponível; aceite HTTP pendente. História não marcada como concluída.
