# ClyoNutri v46 · Pedido de exames laboratoriais (NI2-529): a nutricionista emite, baixa e imprime sem trava (29/10/2026)

**SQL novo na v46: SIM**, só para acrescentar a migração 65. Os três arquivos estão em `database/manual/atualizacao-v46-{preflight,completa,verificacao}.sql`. Eles partem do que está em produção hoje: **a migração 64 já rodou** (pacote u3) e a política de exames está 1,1. **O SQL da v46 não mexe na política.** Não rodei nada em produção.

A v46 substitui a v45, que não foi instalada. **Não suba o `clyonutri-v45.zip`.** A lista de arquivos que mudaram da v45 para a v46 está em `COMPARACAO-V45-V46.txt`. O pacote traz `vendor/` (dompdf) e não traz `.env` nem chaves.

## O que muda em relação à v45

Na v45 a emissão do pedido ficava fechada até alguém registrar uma liberação pela linha de comando. A v46 tira essa trava por completo:

- a nutricionista com CRN e região no perfil faz o caminho inteiro sozinha: rascunho, revisão, "Emitir e gerar PDF", "Baixar PDF" e "Imprimir";
- nenhuma tela mostra aviso de liberação pendente;
- o comando `bin/exam_gate.php` e a classe `ExamReleaseGate` saíram do pacote;
- a migração 65 não fecha mais a política e não cria colunas de aprovação. Ela passou a se chamar `2026_10_29_000065_exam_requests_pdf_and_review` (a v45 nunca rodou em produção, então não há registro antigo com o outro nome);
- as colunas `is_approved_clinical` e `is_approved_legal` da 64 continuam no banco como estão (1,1 em produção), mas o sistema não as lê mais. Com 0,0 também emite.

Todo o resto da v45 continua igual: login em todas as rotas, PDF guardado fora da pasta pública e baixado só por usuário logado da mesma clínica, CRN com região obrigatório, código SHA-256 do arquivo PDF conferido a cada download, trilha de auditoria, nenhum CPF do paciente no documento e botões ligados por arquivo `.js` do site (a política de segurança do navegador continua ativa).

## Como fica para a nutricionista

1. **Rascunho.** Paciente > Exames > Novo pedido. Busca de exames sem diferença de acento ou maiúscula, também por sinônimo ("glicemia", "TSH"). Salva e pode editar.
2. **Revisão.** "Enviar para revisão" confere exames, justificativa e CRN com região. Sem região (CRN-1 a CRN-11) no perfil, a tela pede para completar em Configurações.
3. **Emissão.** Na revisão aparecem o aviso de cobertura, a caixa de ciência e o botão "Emitir e gerar PDF". O PDF é gerado no servidor e só então o pedido fica emitido. Se o PDF falhar, o pedido continua em revisão.
4. **Baixar e imprimir.** Depois de emitido: "Baixar PDF" e "Imprimir" (folha A4 sem menu, com clínica, paciente, nutricionista com CRN, exames, justificativa, data e aviso de cobertura).
5. **Cancelar.** Pedido emitido não muda. Para corrigir, cancele com motivo e faça outro. Cancelado não entrega mais o PDF.

A assinatura digital ICP-Brasil não está nesta versão (NI2-535). O documento sai com linha para assinatura e carimbo.

## Banco de dados

- **Migração 64** (já em produção): sem mudança no banco. No código, o padrão das colunas de aprovação e o registro inicial da política voltam a ser 1,1, como na u3.
- **Migração 65** (nova): no pedido, `reviewed_at`, `issued_by_user_id`, `pdf_path`, `pdf_size` e `pdf_generated_at`; padrão 0 na ciência do aviso de cobertura; índice `idx_exam_audit_entity`.
- Ids `BIGINT(20)`, InnoDB, utf8mb4_unicode_ci, **sem chaves estrangeiras**. Sem UPDATE, DELETE ou DROP. Pedidos que já existem não mudam.
- `database/instalacao_mysql8.sql` (instalação limpa) já vem com a 64 e a 65.
- Os SQL avulsos da u3 (`atualizacao-exames-ni2-529-*.sql`) ficam no pacote só como histórico. **Use os da v46.**

## Como subir (ordem)

1. **Backup** do banco e dos arquivos.
2. **Arquivos:** envie `clyonutri-v46.zip` para `/home3/maic6824/clyonutri.com`, **Extract** (sobrescrever) e apague o zip do servidor. Não mexa em `.env` nem em `storage/`.
3. **Pasta do PDF:** confira que `storage/app` aceita gravação pelo PHP. O sistema cria `storage/app/exam_requests` (750) e grava os arquivos com 640. Se quiser outra pasta fora da pública, use `EXAM_PDF_STORAGE_DIR` no `.env`.
4. **SQL no phpMyAdmin**, logo depois dos arquivos:
   1. `atualizacao-v46-preflight.sql` (só lê). Esperado em produção: "OK: migração 64 já aplicada (como em produção); a completa acrescenta a 65". A política aparece com a observação "não usado pela v46".
   2. `atualizacao-v46-completa.sql`. Pode rodar de novo sem efeito.
   3. `atualizacao-v46-verificacao.sql`: dez linhas OK.
   Em vez dos três, `php bin/migrate.php` dá o mesmo resultado (roda só a 65 onde a 64 já está).
5. Limpe o cache e abra com Ctrl+F5.

## Conferência depois de subir (clínica TESTE)

- `https://clyonutri.com/up` responde `{"status":"ok"}`.
- Paciente TESTE > Exames > Novo pedido: busque "glicemia", marque, escreva a justificativa e salve. Envie para revisão.
- Marque a ciência do aviso e clique em "Emitir e gerar PDF".
- Clique em "Baixar PDF" e depois em "Imprimir": a janela de impressão abre em A4.

Pedidos emitidos pela u3 antes desta versão não têm PDF guardado no servidor. Eles continuam abrindo na tela e no "Imprimir"; o "Baixar PDF" vale para os emitidos a partir da v46. Para ter o PDF de um pedido antigo, cancele e emita de novo.

## Itens que vieram na u3 e não foram ativados

- `src/Core/Security/ClinicalEncryptionService.php` e `bin/encrypt_clinical_records.php` (criptografia de prontuário): no pacote, sem uso em nenhuma tela. Não rode o script sem decisão própria e backup.
- `database/scripts/saneamento_assinaturas_trial_ni2_450.sql` altera dados de assinaturas e não faz parte da ordem acima. Não rode sem decidir.
- `bin/release.log` e `docs/validation/` ficam como vieram.

## Validação

- Testes do módulo: `tests/ni2_529_exam_requests_test.php` com 113 verificações e `tests/ni2_529_v46_sql_manual_test.php` com 41, todos passando. Os testes não vão no zip.
- SQL em MySQL 5.7.44 num banco como o de produção (64 aplicada, política 1,1, pedido emitido antigo): a completa acrescenta a 65, a segunda execução não muda nada, a política e o pedido antigo ficam idênticos. Também testado em banco novo com a 64, com política 0,0, v43 sem a 64 e instalação limpa, pelo phpMyAdmin (cliente mysql), por PDO e por `php bin/migrate.php`.
- Teste de fumaça no zip, em MySQL 5.7.44: 77 de 77. A nutricionista emitiu, baixou e imprimiu um pedido TESTE sem aprovação de ninguém, com a política em 1,1 e depois em 0,0. Saída em `docs/evidencias/ni2-529-v46/SMOKE-V46-mysql57.txt`.
- **Nada foi rodado no banco de produção.**

## Voltar atrás

Extraia por cima o `u3.zip` (o código que está hoje no servidor). As colunas da 65 podem ficar no banco; a u3 não as usa.
