# NI2-6 — Correções de revisão (2026-10-09)

## Entrega local

1. Anamnese e auditoria são gravadas na mesma transação. Em transações de chamadores, savepoint impede que a falha de auditoria deixe uma versão persistida. Auditoria passa a registrar autor. Lock por paciente no caminho MySQL serializa criação de versões.
2. Retificações rejeitam anotação vazia e campos acima de 10.000 caracteres, antes de modificar a retração do registro original. Mantida compatibilidade com registros estruturados. Justificativa tem limite de 10.000 caracteres; título deve ter até 255 caracteres.
3. Novos registros usam hash_version=2, com serialização canônica incluindo título e justificativa. A migration 000027 adiciona a versão do formato sem reescrever hashes antigos. Cadeias mistas preservam os registros v1; a verificação informa que a cobertura dos títulos/justificativas legados é limitada. Mudanças de formato desconhecidas ou regressão para v1 após v2 são rejeitadas. is_retracted continua estado administrativo mutável fora do hash.
4. Campos da anamnese acima de 5.000 caracteres são rejeitados com mensagem por campo, sem corte silencioso ou nova versão. Recuperação de formulário em sessão aceita textos Unicode maiores que o limite e não os trunca (até 200 KB por campo, duração de uma hora).

Migration aplicada ao SQLite local após backup protegido. Não há commit/publicação remota. Registros históricos não ganham retroativamente cobertura de título/justificativa; a aplicação sinaliza essa limitação.

## Verificação

PASS:
- tests/ni2_6_review_regression_test.php: rollback standalone/nested, Unicode/limites/recuperação, retificação vazia/excessiva sem mutação, adulteração de título/justificativa e exportação bloqueada, hashes legados e cadeia mista.
- tests/ni2_6_review_http_test.cjs: servidor PHP com SQLite/sessões temporários; recuperação de Unicode no formulário, erro de auditoria com rollback, gravação válida, retificação inválida sem alteração do original e exportação adulterada bloqueada.
- tests/ni2_6_prontuario_e_anamnese_nutricional_test.php e tests/clinical_safety_and_precision_test.php com zend.assertions=1/assert.exception=1.
- tests/ni2_5_patient_management_http_test.cjs: regressão da gestão de pacientes.
- Sintaxe dos arquivos PHP e git diff --check.

Não executada homologação em MySQL ou revisão visual. Testes apenas em memória ou banco temporário.

## Receitas de exemplo

Seis receitas criadas no consultório MAICON FERREIRA DE OLIVEIRA (clinic_id=4), autor local user_id=3, IDs 246–251. Visibilidade clinic, títulos com prefixo Exemplo, duas porções cada, ingredientes existentes no catálogo, instruções de preparo e nutrientes calculados. Nenhuma prescrição para pacientes criada. Alergênicos explicitamente não conferidos pelo profissional.

Seed repetível: bin/seed_fit_example_recipes.php exige clinic_id/user_id e valida vínculo ativo; evita duplicar títulos da mesma clínica. Definições em database/seeds/fit_example_recipes.php. Corrigida propagação do cálculo nutricional para o snapshot dos ingredientes e permitido indicar alergênicos não verificados.

PASS tests/fit_example_recipes_seed_test.cjs: seis receitas, segunda execução sem duplicação, snapshots coerentes com nutrientes calculados, isolamento e ausência de falsa conferência profissional. Estes testes usam SQLite temporário; o cadastro das receitas solicitadas foi feito no SQLite local da aplicação.
