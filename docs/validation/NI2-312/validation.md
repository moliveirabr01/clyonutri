# NI2-312 — PX-19: avaliação explícita de alergias

Implementado localmente em /Users/moliveira/Nutri_II em 09/10/2026. Alterações preexistentes preservadas.

## Comportamento

A coleta de anamnese oferece Não avaliado / Nega alergias / Alergia registrada. A negativa exige seleção explícita e anamnese finalizada. Campo vazio, ausência de anamnese e negativa em rascunho permanecem Não avaliado. Alergia descrita permanece visível, inclusive em rascunho, que também exibe pendência de revisão. Negativa com descrição de alergia e estado positivo sem descrição são recusados.

O editor e a revisão de publicação mostram o estado e a origem/data da anamnese. Pendências oferecem acesso à coleta. A revisão não infere diagnóstico nem impõe novo bloqueio de publicação por informação desconhecida. O detector existente de conflitos e sua autorização clínica permanecem ativos.

O plano publicado guarda clinical_safety_summary no snapshot, incluindo estado e fonte consultada. Anamneses posteriores atualizam o resumo atual sem reescrever a versão publicada. Histórico anterior preservado. Textos de alergias existentes continuam visíveis; campos vazios legados não são convertidos em negativa. Falha de leitura retorna 503 com tentativa de recuperação, distinta de Não avaliado. Leitura e coleta recusam acesso de outras clínicas e papéis sem autorização.

## Banco

Migration 2026_10_09_000023_add_allergy_assessment_to_anamneses acrescenta allergy_assessment com padrão not_assessed, sem reescrever conteúdo clínico. Aplicada com sucesso no banco local.

## Evidências

- 19/19 verificações HTTP/navegador aprovadas: [resultados](evidence/http-results.json).
- [Persistência e snapshot antes/depois](evidence/persistence.json).
- 47/47 testes existentes de planos: [regressão](evidence/meal-plan-regression.log).
- Suíte de prontuário e anamnese aprovada: [regressão](evidence/anamnesis-regression.log).
- Edição contextual NI2-310 aprovada novamente: [regressão](evidence/item-edit-regression.log).
- Capturas inspecionadas: [não avaliado](evidence/unassessed-1440.png), [revisão pendente](evidence/publication-pending.png), [negativa explícita](evidence/negative-1440.png), [celular](evidence/negative-390.png), [alergia registrada](evidence/positive-390.png).

Testes usam dados sintéticos, autenticação real por senha, requisições TCP, navegador Chrome, rotas de produção e banco SQLite temporário com migrations oficiais. Nenhum paciente real foi alterado nos testes.

## Reprodução

`node tests/ni2_312_real_http_test.cjs`

Requer PHP, Node, Chrome e Playwright (disponível neste ambiente em /tmp/cfit276-browser/node_modules/playwright). Banco de teste criado e removido automaticamente.

Alterações locais, sem publicação remota.
