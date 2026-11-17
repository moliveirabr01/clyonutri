# ClyoNutri v54.71 · pacote completo para HostGator

O pacote `clyonutri-v54-71-hostgator.zip` é **completo**: traz todo o código que roda no servidor, incluindo o `vendor/`. Ficam fora `.env`, `.git`, `tests/`, `docs/`, `scripts/`, `mobile/` e `database/scripts/`. A pasta `storage/` vem só com a estrutura.

Ele serve para:
- **atualizar** qualquer versão a partir da v54.65, já que inclui a v54.66, a v54.67 e a v54.68 e os hotfixes v54.69, v54.70 e v54.71;
- **reinstalar** os arquivos do zero.

## Conteúdo acumulado
- **v54.67 e v54.68:** correções da Auditoria 360° (épico NI2-856):
  - financeiro com vírgula;
  - aceite de transcrição pelo paciente, com envio do código por e-mail;
  - prontuário ligado à consulta;
  - cifragem de alergias, observações e prontuário, com a trava de alérgenos funcionando;
  - agenda que se mantém no mesmo dia;
  - textos de teste encerrado e modo leitura;
  - retentativa automática da anamnese.
- **v54.69:** perfis de plataforma vão para o console em vez de cair no 403 do painel.
- **v54.70:** a tela de MFA reconhece a sessão já aberta.
- **v54.71:**
  - o **MFA do admin está suspenso**: volta com `MFA_PLATFORM_REQUIRED=true` no `.env`;
  - um usuário sem consultório ativo vê uma mensagem em vez de cair em loop no login.

## Banco
A única mudança de banco desde a v54.65 é a **migration 096**, que só acrescenta colunas.
- **Se a produção já está na v54.68 ou acima e os SQL da v54.68 foram rodados:** não há nada a fazer no banco.
- **Se não tiver certeza:** importe `database/manual/atualizacao-v54-68-preflight.sql` no phpMyAdmin.
  - **P4 = 14:** a 096 já foi aplicada.
  - **P4 = 0:** importe `atualizacao-v54-68-completa.sql` e depois `atualizacao-v54-68-verificacao.sql`. O esperado na verificação é V1 = 14 e V2 = 1.

  Os scripts são idempotentes e compatíveis com MySQL 5.7.

## Antes de começar
- **`.env` de produção:**
  - precisa ter `CLINICAL_ENCRYPTION_KEY` preenchida. **Se estiver vazia, pare**, e **nunca troque essa chave**;
  - para e-mails reais (convite, nova senha, código de aceite), precisa de `MAIL_DRIVER=smtp` e dos dados SMTP;
  - não precisa de `MFA_PLATFORM_REQUIRED`: sem ela, o MFA do admin fica desligado.

## Passos
1. **Backup:**
   - banco: em cPanel › Backup, baixe o `maic6824_Clyonutri2`;
   - arquivos: compacte e baixe `/home3/maic6824/clyonutri.com`. Pode deixar `storage/uploads` de fora, se estiver grande;
   - `.env`: guarde uma cópia à parte;
   - cron: pause a tarefa do `bin/cron_scheduler.php`.
2. **Manutenção:** em `/admin/configuracoes`, ponha **Modo de manutenção da plataforma** em **Ativo**.
3. **Banco:** só se necessário (ver acima).
4. **Arquivos:**
   - envie o zip para `/home3/maic6824/clyonutri.com` e **extraia sobrescrevendo**. O `.env` e os arquivos já existentes em `storage/` não são tocados;
   - apague o zip do servidor;
   - apague da raiz do site os arquivos `COMPARACAO-*.txt` e `VALIDACAO-*.txt`, se houver. Eram de versões antigas e ficam legíveis pela web.
5. **Permissões:** arquivos 644, pastas 755 e `storage/` com escrita.
6. **Cache:** limpe o OPcache, se o cPanel oferecer, ou espere 2 minutos. No navegador, apague os cookies de clyonutri.com ou use uma janela anônima.
7. **Conferência:**
   - o rodapé mostra **v54.71**;
   - o Administrador entra direto no console `/admin/dashboard`, sem MFA;
   - um nutricionista entra no painel normalmente;
   - um paciente TESTE com alergia "leite" mostra "Alergias: leite" na ficha. No plano dele, incluir "Leite desnatado" é bloqueado por conflito de lactose;
   - um convite ou uma redefinição de senha enviado para um e-mail seu chega.
8. **Fim da manutenção:** volte o Modo de manutenção para **Inativo** e reative o cron.

## Cifrar dados antigos (opcional)
Veja a seção "Cifrar os dados que já existem" em `LEIA-ME-HOSTGATOR-v54-68.md`, que está neste pacote. É feito pelo Cron do cPanel: primeiro com `--dry-run`, depois de verdade.

## Reversão
Volte os arquivos do backup do passo 1. O banco pode ficar como está.

**Atenção:** pacientes criados ou editados a partir da v54.68 têm alergia e observações cifradas, e a v54.65 não lê esses campos.

## Integridade
O manifesto `config/build-manifest.json` (build `v54.71-5cc4eba6387a6987`) confere com o conteúdo do pacote. Para checar, use `bin/build_identity.php --verify`; o resultado esperado é `integrity: consistent`.
