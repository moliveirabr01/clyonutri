> **Substituído pela v46.** A v45 não foi instalada e não deve ser. O comando `bin/exam_gate.php` citado abaixo não existe mais; na v46 a nutricionista emite sem liberação. Veja `LEIA-ME-HOSTGATOR-v46.md`.

# ClyoNutri v45 · Pedido de exames laboratoriais (NI2-529): impressão, PDF no servidor e liberação fechada (29/10/2026)

**SQL novo na v45: SIM.** A v45 traz a migração 65 e completa a 64. Os três arquivos estão em `database/manual/atualizacao-v45-{preflight,completa,verificacao}.sql`. Eles partem do que já está em produção: **a migração 64 já foi rodada** (pelo pacote u3) e a liberação da emissão pode estar aberta (1,1). **Não rodei nada em produção.**

A v45 é a **v44** (sha256 `d94bdc0aef49cdcec88c6ca95b29f7637d35cd6ec39d73d59d4e68163b8e0521`) com o pacote u3 (sha256 `7904d0dfe2da194f44ee63d239c8a1e4aea5ee9af50f56bfabd77e926635829d`) e as correções abaixo. A lista completa de arquivos está em `COMPARACAO-V44-V45.txt`. O pacote não traz `.env` nem chaves.

## Por que o pedido não imprimia

O site usa uma política de segurança de conteúdo (CSP) que só deixa rodar JavaScript de arquivo do próprio site, e nunca JavaScript escrito dentro do HTML. O botão "Imprimir" da u3 era `onclick="window.print()"`; o navegador bloqueava o clique sem mostrar erro. O mesmo bloqueio atingia o botão do modal de cancelamento e o script da busca de exames na tela de novo pedido.

**Correção.** A impressão virou uma página própria (`/pacientes/{id}/exames/{pedido}/imprimir`), em folha A4, sem menu nem barra lateral, com o botão ligado por arquivo `.js` do site. A folha traz: cabeçalho da clínica, paciente (nome e data de nascimento, sem CPF), nutricionista com CRN e região, exames com orientações, justificativa clínica, data da emissão e o aviso de cobertura. A mesma folha é gerada como **PDF no servidor** na hora da emissão.

## O que muda para a nutricionista

1. **Rascunho → revisão → emissão.** O pedido nasce rascunho e pode ser editado. "Enviar para revisão" confere tudo (exames, justificativa, CRN). Na revisão, "Emitir e gerar PDF" grava o PDF e só então o pedido fica emitido. Se o PDF falhar, o pedido continua em revisão e nada fica pela metade.
2. **PDF e impressão.** Depois de emitido: "Baixar PDF" e "Imprimir". O PDF fica fora da pasta pública (`storage/app/exam_requests/{clínica}/`), com hash SHA-256 conferido a cada download. Só quem está logado na mesma clínica baixa.
3. **CRN com região.** Sem região (CRN-1 a CRN-11) no perfil profissional, o pedido não vai para revisão, e a tela diz para preencher em Configurações.
4. **Busca de exames** sem diferença de acento, maiúscula ou pontuação, e por sinônimo ("glicemia", "TSH"...). O sinônimo usado fica gravado no item.
5. **Aviso de cobertura** precisa ser marcado na emissão; o valor marcado é o que fica gravado.
6. **Assinatura digital (ICP-Brasil)** não está nesta versão (NI2-535). O documento sai com linha para assinatura e carimbo. O sistema não promete assinatura eletrônica.
7. Tudo fica na trilha do módulo e no registro de auditoria geral, isolado por clínica.

## Liberação da emissão (fica FECHADA)

A emissão só funciona quando a política de exames está liberada **e** tem registro de quem liberou, quando e com qual parecer. Depois do SQL da v45:

- liberação aberta sem esse registro (é o caso de produção, se a u3 deixou 1,1) **é fechada**;
- nada abre a liberação sozinho, nem o SQL, nem a migração;
- com a liberação fechada, dá para criar rascunhos e enviar para revisão, mas "Emitir e gerar PDF" mostra: "A emissão de pedidos de exames ainda não foi liberada para esta instalação. Você pode montar e salvar o rascunho; a emissão do PDF fica disponível assim que a liberação for registrada pela administração do ClyoNutri." **Portanto, sem a liberação, não há PDF nem impressão de pedidos novos.**

Para liberar, **só depois do parecer clínico e regulatório por escrito**, pelo Terminal do cPanel, como superadmin:

```
cd /home3/maic6824/clyonutri.com
php bin/exam_gate.php status
php bin/exam_gate.php approve --user=<id do seu usuário superadmin> --reference="Parecer nº ..., de dd/mm/aaaa" --confirm
php bin/exam_gate.php close --user=<id> --reason="..."   (para fechar de novo)
```

`approve` recusa usuário que não seja superadmin, referência curta e falta de `--confirm`. Liberar e fechar ficam na trilha.

## Banco de dados

- **Migração 64** (já em produção): tabelas `exam_regulatory_policies`, `exam_catalog` (22 exames), `exam_requests`, `exam_request_items` e `exam_audit_logs`. A v45 muda o padrão das aprovações para 0 (fechado).
- **Migração 65** (nova): na política, `approved_by_user_id`, `approved_at` e `approval_reference`; no pedido, `reviewed_at`, `issued_by_user_id`, `pdf_path`, `pdf_size` e `pdf_generated_at`; índice `idx_exam_audit_entity`; fecha a liberação aberta sem registro.
- Ids `BIGINT(20)`, InnoDB, utf8mb4_unicode_ci, **sem chaves estrangeiras**. Nada é apagado; o único UPDATE é o que fecha a liberação. Pedidos já existentes não mudam.
- `database/instalacao_mysql8.sql` (instalação limpa) já vem com a 64 e a 65.
- Os SQL avulsos da u3 (`atualizacao-exames-ni2-529-*.sql`) ficam no pacote só como histórico e não abrem mais a liberação. **Use os da v45.**

## Como subir (ordem)

1. **Backup** do banco e dos arquivos.
2. **Arquivos:** envie `clyonutri-v45.zip` para `/home3/maic6824/clyonutri.com`, **Extract** (sobrescrever), apague o zip do servidor. Não mexa em `.env` nem em `storage/`.
3. **Pasta do PDF:** confira que `storage/app` aceita gravação pelo PHP (o sistema cria `storage/app/exam_requests` com permissão 750 e os arquivos com 640). Opcional: `EXAM_PDF_STORAGE_DIR` no `.env` aponta outra pasta fora da pública.
4. **SQL no phpMyAdmin**, logo depois dos arquivos:
   1. `atualizacao-v45-preflight.sql` (só lê). Esperado em produção: "OK: migração 64 já aplicada (como em produção); a completa acrescenta a 65" e a liberação "ABERTA SEM REGISTRO (a completa vai fechar)" ou "FECHADA".
   2. `atualizacao-v45-completa.sql`. Pode rodar de novo sem efeito.
   3. `atualizacao-v45-verificacao.sql`: todas as linhas OK.
   Em vez dos três, `php bin/migrate.php` dá o mesmo resultado (roda só a 65 onde a 64 já está).
5. Limpe o cache e abra com Ctrl+F5.
6. **Liberação:** só quando tiver o parecer, com `php bin/exam_gate.php approve ...` (acima).

## Conferência depois de subir (clínica TESTE)

- `https://clyonutri.com/up` responde `{"status":"ok"}`.
- Paciente TESTE > Exames > Novo pedido: busque "glicemia", marque, escreva a justificativa e salve o rascunho. Envie para revisão.
- Com a liberação fechada, "Emitir e gerar PDF" mostra a mensagem de bloqueio e o pedido continua em revisão.
- Com a liberação aberta (depois do parecer): emita, baixe o PDF e use "Imprimir" (a janela de impressão abre em A4).

## Itens que vieram na u3 e não foram ativados

- `src/Core/Security/ClinicalEncryptionService.php` e `bin/encrypt_clinical_records.php` (criptografia de prontuário): no pacote, sem uso em nenhuma tela e sem rodar. Não rode o script sem decisão própria e backup.
- `database/scripts/saneamento_assinaturas_trial_ni2_450.sql` (assinaturas de teste): não roda sozinho e não faz parte da ordem acima. Ele altera dados; não rode sem decidir.
- `bin/release.log` e `docs/validation/` vieram da u3 e ficam como vieram.

## Validação

- Testes do módulo (`tests/ni2_529_exam_requests_test.php`, 113 verificações) e do SQL manual (`tests/ni2_529_v45_sql_manual_test.php`, 43), passando; não vão no zip.
- SQL testado em MySQL 5.7.44 como em produção (60 a 64 aplicadas, liberação 1,1, pedido emitido antigo): fecha a liberação, segunda execução sem efeito, pedido antigo intacto. Também banco parcial, v43 sem a 64 e instalação limpa. Saídas em `PACOTE-V45-2026-10-29.md` e na pasta `v45-sql`, que vão ao lado do zip.
- **Nada foi rodado no banco de produção.** Sem homologação em produção.

## Voltar atrás

Extraia por cima o `clyonutri-v44.zip`. As tabelas e colunas novas podem ficar no banco; a v44 não usa o módulo de exames.
