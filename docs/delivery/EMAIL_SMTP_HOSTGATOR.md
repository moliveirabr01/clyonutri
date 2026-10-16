# E-mail transacional (recuperação de senha) — configuração na HostGator

O link de "Esqueci minha senha" agora é enviado por e-mail. O envio usa a
biblioteca **PHPMailer** (`phpmailer/phpmailer` no `composer.json`) por SMTP autenticado.

## 1. Deploy

- A pasta `vendor/` **precisa ir junto** no upload (ou rode `composer install --no-dev`
  no servidor, se tiver acesso SSH). Sem ela, o app quebra ao carregar o autoload.
- `APP_URL` precisa ser o endereço público real, com `https://` e **sem barra no final**,
  por exemplo `https://staging.clyonutri.com`. O link do e-mail é montado só a partir
  do `APP_URL` (nunca do cabeçalho Host da requisição). Se `APP_URL` estiver vazio ou
  inválido, nenhum e-mail é enviado e o erro vai para o `error_log`.

## 2. Variáveis no `.env`

| Variável | Exemplo (HostGator) | Descrição |
|---|---|---|
| `MAIL_DRIVER` | `smtp` | `smtp` envia de verdade; `mail` usa o `mail()` do PHP (cai mais em spam); `log` grava em `storage/logs/mail.log` (só desenvolvimento); `null` descarta. `MAIL_MAILER` é aceito como sinônimo; se as duas existirem, vale `MAIL_DRIVER` |
| `MAIL_HOST` | `mail.clyonutri.com` | Servidor SMTP. No cPanel: `mail.<seu-dominio>` |
| `MAIL_PORT` | `465` | `465` com `ssl` ou `587` com `tls` |
| `MAIL_ENCRYPTION` | `ssl` | `ssl` (porta 465) ou `tls` (porta 587) |
| `MAIL_USERNAME` | `nao-responda@clyonutri.com` | Conta de e-mail completa criada no cPanel |
| `MAIL_PASSWORD` | `********` | Senha dessa conta |
| `MAIL_FROM_ADDRESS` | `nao-responda@clyonutri.com` | Remetente. Use a **mesma** conta do `MAIL_USERNAME` |
| `MAIL_FROM_NAME` | `"Clyo Nutri"` | Nome exibido |
| `MAIL_TIMEOUT` | `15` | Opcional. Segundos de espera pelo servidor SMTP |
| `MAIL_VERIFY_PEER` | `true` | Opcional. Só use `false` se o certificado do servidor não bater com `MAIL_HOST` (prefira usar o nome que aparece em "Connect Devices") |
| `APP_SUPPORT_EMAIL` | `suporte@clyonutri.com` | Opcional. Vira o "responder para" do e-mail e o contato exibido nas telas |

Bloco pronto para colar:

```
MAIL_DRIVER=smtp
MAIL_HOST=mail.clyonutri.com
MAIL_PORT=465
MAIL_ENCRYPTION=ssl
MAIL_USERNAME=nao-responda@clyonutri.com
MAIL_PASSWORD=
MAIL_FROM_ADDRESS=nao-responda@clyonutri.com
MAIL_FROM_NAME="Clyo Nutri"
```

Sem `MAIL_DRIVER`: fora de produção o padrão é `log`; com `APP_ENV=production` o envio
falha de forma explícita (registrado no `error_log` e em `PASSWORD_RESET_EMAIL_FAILED`). Em produção, **sempre** defina
`MAIL_DRIVER=smtp`. Não use `log` em produção: o arquivo guarda links válidos de redefinição.

## 3. Passo a passo no cPanel

1. **E-mail > Contas de e-mail > Criar**: crie `nao-responda@<dominio>` com senha forte.
2. Em **Conectar dispositivos** dessa conta, confira o servidor de saída (SMTP) e as portas.
3. **E-mail > Entregabilidade (Email Deliverability)**: deixe **SPF** e **DKIM** como "Válido".
   Se o DNS do domínio não estiver na HostGator, copie os registros sugeridos para o seu provedor de DNS.
   Recomendado também um registro **DMARC**, por exemplo `_dmarc` TXT `v=DMARC1; p=none; rua=mailto:<seu-email>`.
4. Preencha o `.env` (seção 2) e rode no Terminal do cPanel `php bin/test_mail.php voce@exemplo.com`
   (mostra a configuração sem a senha, envia uma mensagem de teste e explica o erro).
   `php bin/healthcheck.php` também avisa se o e-mail ou o `APP_URL` estão mal configurados.
   Depois teste em `/recuperar-senha` com um e-mail cadastrado.
5. Se não chegar, veja o `error_log` (linhas `[ClyoNutri][password-reset]`) e a auditoria
   (`audit_logs.action = 'PASSWORD_RESET_EMAIL_FAILED'`). A tela continua mostrando a
   mensagem genérica de propósito, para não revelar se a conta existe.

## 4. Limites e quando trocar de provedor

- A HostGator limita o envio por hora em planos compartilhados (tipicamente na casa de
  centenas de e-mails/hora por domínio; confira o valor do seu plano com o suporte).
  Mensagens acima do limite são recusadas ou atrasadas.
- O IP compartilhado pode ter reputação ruim e cair em spam, mesmo com SPF/DKIM.
- Para volume maior ou entregabilidade melhor, use um provedor transacional externo
  com SMTP — basta trocar `MAIL_HOST`/`MAIL_PORT`/`MAIL_USERNAME`/`MAIL_PASSWORD`:
  - **Brevo**: `smtp-relay.brevo.com`, porta `587`, `tls` (usuário e chave SMTP do painel Brevo).
  - **Amazon SES**: `email-smtp.<regiao>.amazonaws.com`, porta `587`, `tls` (credenciais SMTP do SES; domínio verificado).
  Em ambos, valide o domínio remetente (SPF/DKIM) no painel do provedor.
- Algumas hospedagens bloqueiam conexões SMTP de saída para servidores externos; se o
  envio para Brevo/SES falhar com timeout, peça liberação ao suporte da HostGator.

## 5. Segurança do token (mudança de comportamento)

- O banco guarda apenas o **hash SHA-256** do token (`password_resets.token`); o token
  em claro só existe no e-mail. Links gerados **antes** desta versão (token em texto puro)
  deixam de funcionar — basta pedir um novo.
- `expires_at` passa a ser gravado em **UTC** e comparado com o "agora" UTC do PHP,
  então a validade de 30 minutos é correta em SQLite e MySQL 5.7/8, qualquer que seja o
  fuso do servidor. Nenhuma migração é necessária (o hash de 64 caracteres cabe no `VARCHAR(255)`).
- A página `/recuperar-senha/{token}` envia `Referrer-Policy: no-referrer`.
- O e-mail é enviado **depois** que a resposta sai para o navegador (`fastcgi_finish_request`/
  `litespeed_finish_request`), então o tempo de resposta também não revela se a conta existe.
