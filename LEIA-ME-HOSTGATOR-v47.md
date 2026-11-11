# ClyoNutri v47 · Solicitação de exames: completa as histórias NI2-530, 532, 533, 537 e 542 (29/10/2026)

**SQL novo na v47: SIM** (migração 66). E **todos os SQL manuais da v41 à v47 foram corrigidos** para o phpMyAdmin. Não rodei nada em produção.

## Antes de tudo: por que os SQL anteriores davam erro #1109

Quando um script consulta o `information_schema`, o phpMyAdmin troca sozinho o banco ativo para `information_schema`. A partir daí `DATABASE()` aponta para o banco errado e os comandos procuram `migrations`, `meal_plans` e as outras tabelas lá dentro (erro #1109). Na v47, **todos os arquivos de `database/manual/atualizacao-v41-*` a `atualizacao-v47-*`** têm o banco fixo (`SET @db = 'maic6824_Clyonutri2'`) e o nome do banco antes de cada tabela. Se um dia o banco tiver outro nome, troque `maic6824_Clyonutri2` em todo o arquivo.

## O que muda para a nutricionista

**Nome na tela.** Tudo passa a se chamar "Solicitação de exames laboratoriais", como pede o épico NI2-529.

**NI2-533: editor**
- **Atendimento de origem:** a solicitação pode ser vinculada a uma consulta do paciente. Pela tela da consulta, "Nova solicitação nesta consulta" já abre com o atendimento escolhido. Atendimento de outro paciente ou de outra clínica é recusado.
- **Ordem dos exames:** quadro "Ordem dos exames na solicitação" com Subir e Descer. O documento e o PDF saem nessa ordem.
- **Alterações não salvas:** o editor já avisava ao sair da página, pelo mecanismo geral do sistema. Agora o aviso também vale quando a tela volta com erro, e a mudança de ordem conta como alteração.

**NI2-532: catálogo** (menu: Solicitações de exames > "Catálogo e favoritos", ou `/exames/catalogo`)
- **Favoritos por nutricionista:** aparecem primeiro no editor e têm o filtro "★ Meus favoritos". **Nunca vêm marcados**: cada solicitação continua exigindo escolha e justificativa caso a caso.
- **Exames da clínica:** a nutricionista propõe um exame laboratorial que não está no catálogo. Ele fica "Aguardando validação" e só entra em solicitações depois que a proprietária ou administradora da clínica aprova. Recusa exige motivo. Imagem, endoscopia, biópsia e afins são recusados já na proposta, e nome duplicado também. Outra clínica não vê nem usa o exame.
- **Código TUSS/LOINC:** há campo para o código, mas ele fica vazio nos 22 exames enquanto não houver licença de uso.
- **Revisão clínica:** cada exame registra a situação da revisão clínica. Os 22 exames estão como "pendente", e a tela do catálogo mostra esse aviso.

**NI2-537: prontuário, consulta e versões**
- **Prontuário:** novo bloco "Solicitações de exames laboratoriais" com número, situação, versão, data, nutricionista, consulta de origem e exames, com link para cada uma.
- **Tela da consulta:** mostra as solicitações daquela consulta e o botão para criar outra nela.
- **Corrigir uma solicitação emitida:** novo botão "Corrigir (nova versão)". A emitida **não muda**: fica cancelada como "substituída pela versão 2", com o PDF guardado. Abre um rascunho da versão 2 com os mesmos exames, justificativa e atendimento. O documento da versão 2 diz qual solicitação substitui. A ciência do aviso de cobertura tem de ser dada de novo. Só a autora faz isso.

**NI2-530: política**
- A política vigente passa a ser lida do banco pela data de vigência, e não mais de um valor fixo no código. Uma versão nova entra por SQL, com outro número e outra data, e as anteriores não mudam.
- **Correção:** a tela e a impressão de uma solicitação emitida passam a mostrar a política **da versão com que ela saiu**, e não a vigente.
- Nova página "Política de solicitação de exames" (`/exames/politica`), só de leitura: versões, vigência, quantas solicitações da clínica usam cada uma e o que mudou.
- **A emissão continua sem trava de aprovação**, conforme sua decisão da v46.

**NI2-542: privacidade e auditoria**
- A trilha passa a registrar também a abertura da solicitação, a nova versão, os favoritos e a proposta e validação de exames da clínica, sempre com autor e horário e sem texto clínico.
- As telas de solicitação, catálogo e política não ficam em cache do navegador.
- **Conferência dos PDFs** depois de backup, restauração ou mudança de servidor: `php bin/exam_pdf_integrity.php` (ou `--clinic=ID`). Só lê. Aponta PDF faltando ou com código de integridade diferente e lista arquivos sobrando sem apagar nada. Sai com código 1 se houver problema.

## O que continua pendente (fora do código)

- **Criptografia dos dados armazenados (NI2-542):** o serviço que veio na u3 (`ClinicalEncryptionService`) segue sem uso. Ligar exige decisão sobre a chave e backup antes.
- **Retenção:** solicitações, PDFs e trilha não são apagados pelo sistema. A política de retenção (prazo e descarte) precisa ser definida com o jurídico.
- **Revisão clínica do catálogo e da política** (22 exames e textos), **códigos TUSS/LOINC** (licença) e **assinatura ICP-Brasil** (NI2-535).
- **Status por exame** dentro da solicitação depende das histórias de resultados (NI2-539 e NI2-540).

## Banco de dados: migração 66

- `exam_regulatory_policies`: `change_summary`.
- `exam_catalog`: `clinic_id`, `origin`, `validation_status`, `submitted_by_user_id`, `validated_by_user_id`, `validated_at`, `validation_note`, `standard_code_system`, `standard_code`, `clinical_review_status`, `clinical_reviewed_by` e `clinical_reviewed_at`. Os 22 exames que já existem ficam como catálogo geral e validados.
- Nova tabela `exam_favorites`, vazia, com índice único por clínica, usuário e exame.
- `exam_requests`: `appointment_id`, `supersedes_request_id`, `root_request_id` e `version_number` (as solicitações existentes ficam na versão 1, sem vínculo).
- Ids em `BIGINT(20)`, sem chave estrangeira, sem DROP, DELETE ou UPDATE. Idempotente.
- `database/instalacao_mysql8.sql` (instalação limpa) já vem com a 66.

## Como subir (ordem)

1. **Backup** do banco e dos arquivos.
2. **Arquivos:** envie `clyonutri-v47.zip` para `/home3/maic6824/clyonutri.com`, faça o **Extract** (sobrescrever) e apague o zip do servidor. Não mexa em `.env` nem em `storage/`.
3. **SQL no phpMyAdmin**, no banco `maic6824_Clyonutri2`, aba SQL. Use **sempre os arquivos deste pacote**, não os de pacotes anteriores. Em cada versão rode preflight, depois completa, depois verificação, e só rode uma versão se o preflight dela pedir:
   1. v41 (migração 61), se ainda não aplicada
   2. v42 (62)
   3. v43 (63): sem ela, a v46 para no preflight
   4. v46 (65)
   5. **v47 (66)**: o preflight deve dizer "OK: PRONTO PARA MIGRAR (migração 66)" e a verificação deve ter todas as linhas OK.

   O preflight de cada versão diz se aquela parte já está aplicada ("já aplicada" ou "rodar de novo não muda nada"); nesse caso, pule para a próxima. Em vez do SQL manual, `php bin/migrate.php` faz o mesmo.
4. Limpe o cache e abra com Ctrl+F5.

## Conferência depois de subir (clínica TESTE)

- Paciente TESTE > Solicitações de exames > Nova solicitação: escolha o atendimento, marque três exames, mude a ordem com Subir, escreva a justificativa e salve. Envie para revisão e emita.
- Na solicitação emitida, clique em "Corrigir (nova versão)", informe o motivo e confira que abre a versão 2 e que a 1 aparece como substituída.
- Abra o Prontuário do paciente e a tela da consulta: as solicitações aparecem nos dois.
- Abra `/exames/catalogo`, marque um favorito e proponha um exame. Com a conta proprietária, aprove-o. Abra `/exames/politica`.

## Validação

- Testes novos, rodados em SQLite e em MariaDB 10.11 (instalação da v46 com a 66 aplicada pelo PHP): domínio com 71 verificações, telas e controladores com 42, prontuário, consulta e perfil renderizados com 3, e o fluxo da v46 com 3. Nenhuma falha. Saída completa em `docs/evidencias/ni2-529-v47/TESTES-V47.txt`.
- SQL manual v41 → v47 rodado com o banco ativo em `information_schema`, como o phpMyAdmin deixa, sobre um banco montado como a produção: nenhum erro, a segunda execução não muda nada e a solicitação antiga fica intacta. O preflight original da v46, no mesmo cenário, reproduz o erro #1109. Saída completa em `docs/evidencias/ni2-529-v47/SQL-MANUAL-V47-mariadb.txt`.
- Navegador real com a política de segurança ativa: 20 de 20, incluindo o celular sem rolagem lateral e o console sem erros. Saída completa em `docs/evidencias/ni2-529-v47/NAVEGADOR-V47.txt`.
- **A suíte completa do projeto (a pasta `tests/` original) não veio no pacote e não foi rodada.** Testes antigos que comparam textos como "Pedido emitido" vão precisar do texto novo ("Solicitação emitida").
- **Nada foi rodado no banco de produção.**

## Voltar atrás

Extraia o `clyonutri-v46.zip` por cima. As colunas e a tabela da 66 podem ficar no banco, porque a v46 não as usa.
