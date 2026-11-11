# Clyo Nutri v49 · Nome novo nas telas e correções sobre a v48 (31/10/2026)

**SQL novo na v49: SIM** (migração 68: 17 colunas novas que aceitam vazio, sem mexer em dado existente). Testei no MySQL 5.7. Não rodei nada em produção.

## O que muda

**Nome do produto.** Em tudo o que a nutricionista, a recepção e a paciente veem, o nome passa a ser **Clyo Nutri**: telas, títulos das abas, e-mails (assunto e texto), avisos, PDF da solicitação de exames, termos e página inicial. O aviso de cobertura da política de exames, que está guardado no banco com o nome antigo, aparece com o nome novo na tela sem alterar o banco. Mensagens e notificações já enviadas antes da v49 continuam com o nome antigo.

Continua igual, de propósito: nomes no código (namespace `ClyoNutri\`), banco `maic6824_Clyonutri2`, domínio, pastas, chaves do `.env`, prefixo `[ClyoNutri]` nos logs e os nomes dos planos (ClyoNutri Start, ClyoNutri Profissional e ClyoNutri Clínica Elite), porque aparecem em cobranças e contratos já feitos.

**Correções sobre a v48:**

- **SQL da migração 68.** A v48 trouxe o arquivo da migração 68 (antropometria com 14 circunferências e bioimpedância, alerta de alérgeno no plano alimentar), mas não o SQL para o phpMyAdmin. A v49 traz o trio e põe a 68 no instalador limpo.
- **SQL da v47 e da v48 revisado.** Em vez do nome do banco fixo em todo o arquivo, os scripts usam o banco selecionado no phpMyAdmin e só recorrem a `maic6824_Clyonutri2` quando o phpMyAdmin troca o banco ativo para o `information_schema` (o erro #1109). O SQL das tabelas é o mesmo.
- **SQL já publicado da v41, v42, v43 e v46** voltou ao conteúdo original. A v48 tinha reescrito esses arquivos; como a produção já passou por eles, o certo é ficarem como foram publicados.
- **Laudo em PDF moderno.** PDFs 1.5 ou mais novos podem guardar partes do arquivo compactadas dentro de "fluxos de objetos", onde a checagem da v48 não olhava. Agora essas partes são descompactadas e verificadas. O que não dá para inspecionar (outro tipo de compactação ou tamanho exagerado) é recusado com a mensagem "recusado por segurança".
- **Antivírus em modo auto.** Quando o servidor não tem ClamAV, o laudo continua aceito depois das checagens, mas agora fica registrado no log do PHP como `EXAM_RESULT_ANTIVIRUS_UNAVAILABLE`.
- **Recepção.** A v48 liberava para a recepção um endereço que não existe; a prévia da confirmação de consulta (`/agenda/{id}/previa-confirmacao`) ficava bloqueada. Agora a recepção abre a prévia, só da própria clínica.
- **Agenda.** As prévias de confirmação do dia saem numa consulta só ao banco, e uma consulta cujo profissional não tem usuário não derruba mais a página.
- **Mensagens de erro.** Erro interno na tela de exames, na prévia e no envio da confirmação aparece como mensagem simples para quem usa; o detalhe técnico vai só para o log.
- **HTML sem comentários internos.** Os comentários com códigos de tarefa (NI2, PX) saíram do HTML da agenda e de outras telas.

## Como subir (ordem)

1. **Backup** do banco e dos arquivos.
2. **Se a v47 ainda não foi aplicada em produção:** aplique a v47, depois a v48 e depois a v49, cada uma com seu SQL, nessa ordem. Os trios da v47 e da v48 já vêm neste pacote em `database/manual/` (versão revisada) e na pasta `v49-sql/anteriores-v47-v48`. Se a v47 já está aplicada, rode só o SQL da v48 (se ainda não rodou) e o da v49.
3. **Limite de envio do PHP:** no cPanel, em **MultiPHP INI Editor**, confira se `upload_max_filesize` é pelo menos `16M` e `post_max_size` pelo menos `20M`.
4. **Arquivos:** envie `clyonutri-v49.zip` para `/home3/maic6824/clyonutri.com`, faça o **Extract** (sobrescrever) e apague o zip do servidor. Não mexa em `.env` nem em `storage/`. O pacote não traz `.env`.
5. **SQL no phpMyAdmin**, banco `maic6824_Clyonutri2`, aba SQL:
   - `atualizacao-v49-preflight.sql`: esperado "OK: PRONTO PARA MIGRAR (migração 68)". Se aparecer "OK: v49 já aplicada", alguém já rodou o `php bin/migrate.php` depois da v48 e o banco já está pronto.
   - `atualizacao-v49-completa.sql`: pode rodar de novo sem problema.
   - `atualizacao-v49-verificacao.sql`: todas as linhas OK.
   Não precisa rodar `php bin/migrate.php`: a completa já registra a 68.
6. Limpe o cache e abra com Ctrl+F5.

O `.env` precisa ter `APP_KEY` com pelo menos 16 caracteres. Os links de laudo e o chat dependem dele. O nome do remetente dos e-mails vem de `MAIL_FROM_NAME` (ou `APP_NAME`) no `.env`: se estiver "ClyoNutri", troque para `MAIL_FROM_NAME="Clyo Nutri"`.

## Conferência depois de subir (clínica TESTE)

- Abra `/login`, a página inicial e o painel: o nome deve aparecer como "Clyo Nutri".
- Entre como recepção e abra a agenda do dia: a prévia de confirmação de uma consulta deve abrir.
- Abra uma solicitação de exames emitida do Paciente TESTE, imprima e confira o nome no PDF.
- Anexe um laudo em PDF: deve ser aceito. No log do PHP deve aparecer uma linha `EXAM_RESULT_ANTIVIRUS_UNAVAILABLE` se o servidor não tiver ClamAV.

## Validação (fora do zip)

- Suíte do projeto (137 arquivos de teste, SQLite): 92 passam. Os 45 que falham já falhavam antes: 43 herdados da v46 e 2 que dependem do horário (falham à noite também com o código da v48). Nenhuma falha nova. O teste `dashboard_review_fixes`, que falhava às vezes, passou.
- Testes novos da v49 (`v49_correcoes`): 40 de 40. Exames e laudos (`ni2_529`): 119 de 119.
- `php -l` em 585 arquivos PHP do sistema: nenhum erro.
- SQL no MySQL 5.7.44: produção sem a v47, produção com a v47, banco com outro nome, banco onde a 68 entrou pelo `migrate.php` e instalação limpa. Tudo OK, segunda execução sem efeito, chaves estrangeiras iguais antes e depois.
- Sistema rodando em servidor local com banco montado pelo mesmo caminho da produção: 103 de 103 conferências (solicitação, PDF, impressão, laudos, PDFs maliciosos recusados, recepção, outra clínica bloqueada e nome Clyo Nutri nas telas).
- **Não testado:** app da paciente (fica fora deste repositório) e ClamAV real.
- **Nada foi rodado no banco de produção.**

## Voltar atrás

Extraia o `clyonutri-v48.zip` por cima. As colunas da 68 podem ficar no banco, porque a v48 já conhecia a migração e as colunas aceitam vazio.
