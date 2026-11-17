# ClyoNutri v54.71 · MFA do admin suspenso e fim do loop no login (HostGator)

Pacote parcial `clyonutri-v54-71-hotfix-hostgator.zip`, aplicado sobre a v54.70. Sem mudança de banco.

## O que muda
- **MFA do admin suspenso.** Superadmin, admin operacional e suporte entram no `/admin` só com e-mail e senha. O MFA não é pedido nem para quem já tinha cadastrado. O MFA opcional dos nutricionistas continua como estava.
  - Para voltar a exigir o MFA mais tarde, basta acrescentar `MFA_PLATFORM_REQUIRED=true` no `.env`, sem mexer no código.
- **Fim do loop de usuário sem consultório.** Um usuário sem vínculo ativo com um consultório entrava num loop entre `/admin` e `/login`. Agora o login mostra "Seu usuário não está vinculado a um consultório ativo. Fale com o suporte…".
  - Sessões que já estavam presas nesse loop são limpas ao abrir `/login`.

## Arquivos no zip
- `src/Application/Controllers/AuthController.php`
- `src/Core/Auth/MfaService.php`
- `src/Core/Release/BuildIdentity.php`
- `config/build-manifest.json`
- `.env.example`, que traz a nova chave, desligada (o `.env` de produção **não** precisa mudar)
- `RELEASE_INFO.json`
- este LEIA-ME

## Passos
1. **Backup:** baixe os 4 arquivos de código listados acima, que estão no servidor hoje.
2. **Upload:** envie o zip para `/home3/maic6824/clyonutri.com`, extraia sobrescrevendo e apague o zip.
3. **Cache:** limpe o OPcache, se o cPanel oferecer, ou espere 2 minutos.
4. **Navegador:** apague os cookies de clyonutri.com ou use uma janela anônima, para sair da sessão que ficou presa.
5. **Conferência:**
   - o rodapé mostra **v54.71**;
   - com o Administrador, o login leva direto ao console administrativo, sem tela de MFA.
   - **Se aparecer a mensagem "não está vinculado a um consultório ativo",** o usuário Administrador não tem vínculo ativo em `clinic_members`. Me avise que preparo o SQL para vincular.

## Reversão
Volte os arquivos do backup do passo 1.
