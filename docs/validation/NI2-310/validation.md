# NI2-310 — PX-17: edição de itens do plano

Implementado localmente em /Users/moliveira/Nutri_II em 09/10/2026. Alterações preexistentes preservadas. Testes com dados sintéticos em banco temporário.

O rascunho permite editar quantidade, unidade/medida caseira e orientação no próprio item. Preserva identificador, refeição, alimento, ordenação e substituições. Recalcula nutrientes e totais na mesma transação; falhas desfazem a edição. A revisão do formulário bloqueia sobrescrita por outra aba. Confere clínica, plano, estado e papel de nutricionista ativo com vínculo profissional. Mostra estados de gravação, sucesso, conflito, erro e vazio.

Publicado oferece “Criar nova versão para editar”, usando duplicação com parent_plan_id e substituições preservadas. O plano e snapshot publicados permanecem intactos. O novo rascunho exige publicação posterior.

## Validação

- 30/30 verificações HTTP e navegador: [resultados](evidence/http-results.json).
- 47/47 testes existentes: [regressão](evidence/regression.log).
- [Persistência antes/depois e snapshot](evidence/persistence.json).
- Capturas inspecionadas: [desktop](evidence/editor-1440.png), [sucesso](evidence/success-1440.png), [celular](evidence/editor-390.png).

Conexão TCP real, autenticação por senha, rotas de produção, SQLite temporário com migrations oficiais. Falha de persistência provocada por trigger SQL, sem simular respostas HTTP.

## Reprodução

`node tests/ni2_310_real_http_test.cjs`

Requer PHP, Node, Chrome e Playwright (neste ambiente em /tmp/cfit276-browser/node_modules/playwright). Banco temporário criado e removido pelo teste.

Escopo: rota POST /planos/{id}/itens/{itemId}/editar; editor/controlador do plano; transação e revisão de updateItem. Nenhuma migração necessária. Alterações locais, sem publicação remota.
