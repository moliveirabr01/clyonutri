# ClyoNutri v48 · Laudos e resultados laboratoriais anexados à solicitação (NI2-539) (29/10/2026)

**SQL novo na v48: SIM** (migração 67: duas tabelas novas, vazias). Ensaiei esse SQL numa cópia do banco de produção (o seu dump de 29/10, com a v47 aplicada): deu tudo OK e nenhuma tabela existente mudou. Não rodei nada em produção.

## O que muda para a nutricionista

Na solicitação **emitida** aparece a seção **"Laudos e resultados"**:

- **Situação por exame:** "Laudo recebido" (com a data da coleta) ou "Aguardando laudo". Só conta laudo aceito e vigente.
- **Anexar laudo:** escolha o arquivo (PDF, JPG ou PNG, até 15 MB) e informe laboratório, data da coleta, data do laudo, observação e **quais exames ele traz**.
- **O original é preservado:** o arquivo é guardado exatamente como chegou, fora da pasta pública e com nome aleatório, junto com o código de integridade (SHA-256). O sistema nunca sobrescreve nem apaga o arquivo.
- **Corrigir:** "Corrigir (anexar nova versão)" guarda o arquivo corrigido como versão 2 e informa o motivo. A versão 1 continua guardada, abrindo normalmente e marcada como "Substituído pela versão 2".
- **Abrir ou baixar:** o botão gera um link que **vale 5 minutos**, só para a mesma pessoa logada na mesma clínica. A cada entrega o sistema confere o código de integridade. PDF sai sempre como download; imagem abre no navegador, sem executar nada.
- **Duplicata:** o mesmo arquivo não pode ser anexado duas vezes à mesma solicitação.
- A lista de solicitações do paciente e o prontuário mostram quantos laudos cada solicitação tem e quantos estão **aguardando revisão**.

## O que muda para a paciente (app)

O app da paciente fica fora deste pacote. Ele ganha três chamadas novas na API, que precisam ser ligadas no app:

| Chamada | O que faz |
|---|---|
| `GET /api/v1/patient/exam-requests` | Lista as solicitações **emitidas** da paciente, com os exames e os laudos de cada uma (situação e, se recusado, o motivo). Não expõe caminho nem código interno. |
| `POST /api/v1/patient/exam-requests/{id}/results` | Envia o laudo (multipart: `file`, e opcionais `laboratory_name`, `collected_at` AAAA-MM-DD, `notes`, `item_ids[]`). Responde 201. Máximo de 20 envios por dia. |
| `POST /api/v1/patient/exam-results/{id}/link` | Gera o link de abertura, válido por **2 minutos**: `GET /api/v1/exames/laudo/{token}`. |

**O envio da paciente fica "Aguardando revisão" e não vira dado clínico:** não conta como resultado recebido até a nutricionista **aceitar** (marcando os exames que o laudo traz) ou **recusar** (com motivo, que a paciente vê no app). Recusado continua guardado.

## Segurança do arquivo (antes de guardar)

O arquivo é recusado inteiro, e nada é guardado, nestes casos:
- O **tipo real** pelos bytes do arquivo não é PDF, JPEG ou PNG (o nome e o que o celular declara não contam). Um PHP com nome `.jpg` é recusado.
- O nome não combina com o conteúdo, ou tem extensão de programa no meio (`laudo.php.pdf`).
- O arquivo tem trecho de código de servidor ou de página escondido (por exemplo, uma imagem com `<?php` dentro).
- O PDF tem conteúdo ativo (JavaScript, abre programa, arquivo embutido, mídia, formulário XFA), inclusive disfarçado em hexadecimal, está protegido por senha ou está incompleto.
- A imagem não abre ou passa de 12.000 pixels por lado.
- O arquivo está vazio ou tem mais de 15 MB.

**Antivírus:** se o servidor tiver ClamAV, ele é usado. Sem ClamAV, o arquivo passa pelas checagens acima e fica registrado "antivírus indisponível". Em servidor compartilhado do HostGator o ClamAV normalmente não está disponível para o PHP. Para exigir antivírus e recusar o envio quando não houver, use `EXAM_FILES_ANTIVIRUS=required` no `.env` (o padrão é `auto`). A checagem de PDF procura palavras-chave e **não pega conteúdo ativo escondido dentro de fluxos compactados**; o antivírus é a camada mais forte.

**Limitação conhecida:** os arquivos ficam guardados sem criptografia em disco (mesma pendência da v47: falta decidir onde a chave fica).

## Banco de dados: migração 67

- `exam_result_documents` (26 colunas): solicitação, paciente, quem enviou (nutricionista ou paciente), nome original, caminho guardado, tipo real, tamanho, SHA-256, resultado das checagens, laboratório, coleta, data do laudo, observação, situação, revisão, versão, laudo substituído, primeiro laudo da cadeia, motivo da correção e data.
- `exam_result_document_items`: quais exames da solicitação cada laudo traz.
- Ids em BIGINT(20), sem chave estrangeira, idempotente. Não mexe em nenhuma tabela existente.
- `database/instalacao_mysql8.sql` (instalação limpa) já vem com a 67.

## Como subir (ordem)

1. **Backup** do banco e dos arquivos.
2. **Limite de envio do PHP:** no cPanel, em **MultiPHP INI Editor**, confira se `upload_max_filesize` é pelo menos `16M` e `post_max_size` pelo menos `20M`. Se for menor, laudos grandes são barrados antes de chegar ao sistema, que mostra o aviso "passa do limite de envio do servidor".
3. **Arquivos:** envie `clyonutri-v48.zip` para `/home3/maic6824/clyonutri.com`, faça o **Extract** (sobrescrever) e apague o zip do servidor. Não mexa em `.env` nem em `storage/`. Confira se `storage/app` aceita gravação: o sistema cria `storage/app/exam_results` (750) com bloqueio de acesso pela web.
4. **SQL no phpMyAdmin**, banco `maic6824_Clyonutri2`, aba SQL, usando os arquivos deste pacote: `atualizacao-v48-preflight.sql` (esperado: "OK: PRONTO PARA MIGRAR (migração 67)"), depois `atualizacao-v48-completa.sql`, depois `atualizacao-v48-verificacao.sql` (todas as linhas OK).
5. Limpe o cache e abra com Ctrl+F5.

O `.env` já precisa ter `APP_KEY`, que o chat também usa; sem ele, abrir laudo mostra um aviso de configuração.

## Conferência depois de subir (clínica TESTE)

- Abra uma solicitação **emitida** do Paciente TESTE. Em "Laudos e resultados", anexe um PDF, marque um exame e confira que ele passa para "Laudo recebido".
- Clique em "Baixar PDF": o arquivo baixado deve ser o mesmo que você enviou.
- Tente anexar um arquivo `.txt` renomeado para `.pdf`: a tela deve recusar.
- Use "Corrigir (anexar nova versão)" com outro arquivo e confira que a versão 1 aparece como substituída.
- Para conferir os arquivos guardados (PDFs das solicitações e laudos), rode `php bin/exam_pdf_integrity.php`. Sai com código 0 se tudo conferir.

## Validação

- Testes novos da NI2-539, com 87 verificações, incluindo arquivos maliciosos de verdade (PDF com JavaScript, inclusive disfarçado, PDF que abre programa, PDF com arquivo embutido, PDF com senha, PDF truncado, PHP com nome `.pdf` ou `.jpg`, imagem com PHP escondido, SVG, HTML, arquivo vazio e de 15 MB), outra clínica, outra paciente e links adulterados ou vencidos. Os testes da v47 também foram rodados de novo (119). Tudo passou em SQLite e em MariaDB 10.11, conforme `docs/evidencias/ni2-539-v48/TESTES-V48.txt`.
- HTTP no servidor local, com envio multipart real pela tela e pela API do app com token de paciente: 33 de 33. Navegador real com a política de segurança ativa, incluindo celular: 4 de 4. Ver `HTTP-NAVEGADOR-V48.txt`.
- Falsos positivos: dos 40 PDFs reais do ambiente, 39 foram aceitos; o recusado era um atalho quebrado. Ver `FALSOS-POSITIVOS-PDF.txt`.
- SQL da v48 ensaiado numa cópia do banco de produção, com o banco ativo em `information_schema` como no phpMyAdmin: nenhum erro, a segunda execução não muda nada, a contagem de linhas de todas as tabelas existentes ficou igual e o `migrate.php` não encontra nada pendente depois. Ver `SQL-MANUAL-V48-copia-producao.txt`.
- **Não testado:** o app da paciente em si, porque ele fica fora deste repositório; só a API foi testada. ClamAV real também não foi testado, porque não está instalado aqui; o antivírus foi simulado limpo, infectado e indisponível. A suíte original `tests/` do projeto não veio no pacote.
- **Nada foi rodado no banco de produção.**

## Voltar atrás

Extraia o `clyonutri-v47.zip` por cima. As tabelas da 67 podem ficar no banco, porque a v47 não as usa. Os laudos já anexados continuam em `storage/app/exam_results`.
