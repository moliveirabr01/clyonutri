# Notas para o próximo pacote HostGator (depois da v26)

Itens que o LEIA-ME do próximo pacote (NI2-467) precisa trazer. Cada história acrescenta a sua parte aqui.

## NI2-449 · Aceite versionado dos Termos e da Política

1. **Antes de subir os arquivos**, no phpMyAdmin:
   - `database/manual/preflight-2026-10-26-aceite-termos-ni2-449.sql` (somente leitura);
   - `database/manual/migracao-2026-10-26-aceite-termos-ni2-449.sql` (migração 56, idempotente).
   Sem a tabela `user_legal_acceptances`, o cadastro de profissional falha (nada é gravado).
2. O reaceite fica desligado até a revisão jurídica (v27b). Com `LEGAL_REACCEPTANCE_ENABLED=true` no `.env`,
   todo profissional sem aceite da versão vigente vê `/aceite-termos` na primeira página que abrir, e o paciente
   vê a etapa de aceite ao entrar pela web em `/paciente/app`. Desligado, o aceite só é gravado no cadastro e no
   primeiro acesso do paciente.
3. O preflight geral da v24 (`atualizacao-v24-preflight.sql`) lista a 56 como desconhecida; o preflight final
   precisa incluí-la.

## NI2-446 · Domínio e e-mails de contato

Domínio oficial decidido em 25/10/2026: **clyonutri.com** (o `.com.br` não existe).

1. **Antes do deploy**, criar as caixas **no Titan** (painel da HostGator > E-mails Titan) e testar envio e
   recebimento. O MX de clyonutri.com aponta para o Titan (mx1/mx2.titan.email, conferido em 25/10/2026): caixa
   criada em cPanel > Contas de e-mail não recebe nada.
   - `suporte@clyonutri.com`
   - `dpo@clyonutri.com`
2. No `.env` da HostGator, conferir ou acrescentar:

   ```
   APP_URL=https://clyonutri.com
   APP_SUPPORT_EMAIL=suporte@clyonutri.com
   APP_DPO_EMAIL=dpo@clyonutri.com
   MAIL_FROM_ADDRESS=noreply@clyonutri.com
   ```

   Sem `APP_SUPPORT_EMAIL`/`APP_DPO_EMAIL`, o site usa `suporte@` e `dpo@` do domínio do `APP_URL`
   (com `APP_URL=https://clyonutri.com`, dá o mesmo endereço). Se o `APP_URL` estiver errado, os contatos e o
   canonical saem errados também.
3. SPF, DKIM e DMARC do domínio são da NI2-463 e precisam estar prontos antes de ligar o envio de e-mail.
4. Conferência depois do deploy: `/privacidade` mostra `dpo@clyonutri.com`; `/termos`, `/login`,
   `/recuperar-senha` e o rodapé da landing mostram `suporte@clyonutri.com`; o código-fonte da landing tem
   `<link rel="canonical" href="https://clyonutri.com/">`. Mande um e-mail para cada caixa e responda.

## NI2-478 · Lista de compras sem bloqueio da CSP

Sem passo de deploy. Conferência: abrir a lista de compras de um plano e testar imprimir, copiar, marcar item e
adicionar item avulso (antes, a CSP de produção bloqueava todos).

## NI2-479 · Limite de cadastros por IP

1. Opcional no `.env`: `REGISTER_MAX_PER_HOUR` (padrão 10 cadastros concluídos por hora por IP, de 1 a 100).
   O limite é folgado porque várias pessoas podem sair pelo mesmo IP.
2. Inatividade da sessão: continua no Console > Configurações da plataforma ("tempo de sessão", padrão 120 min).
   Se quiser 30 min, ajuste lá; não há mais valor fixo no código.
3. Conferência: cadastrar uma conta de teste com "TESTE" no nome; errar a senha algumas vezes não bloqueia.
