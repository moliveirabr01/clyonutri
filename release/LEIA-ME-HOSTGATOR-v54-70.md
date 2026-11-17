# ClyoNutri v54.70 · hotfix do "redirecionamento em excesso" no login (HostGator)

Pacote parcial `clyonutri-v54-70-hotfix-hostgator.zip`, aplicado sobre a v54.69. Sem mudança de banco.

## O que muda
Corrige o "Redirecionamento em excesso" (ERR_TOO_MANY_REDIRECTS) ao abrir `/login` com o Administrador.

**O que acontecia:** a sessão desse usuário foi aberta antes de existir o MFA, então ela não tinha o MFA verificado. Com isso, `/login` mandava para `/admin`, o `/admin` mandava para `/login/mfa` e essa tela, sem um login pendente, devolvia para `/login`. O navegador ficava nesse ciclo.

**Agora:** `/login/mfa` reconhece a sessão já aberta e pede o MFA ali mesmo.
- **Primeiro acesso:** mostra a tela de cadastro do autenticador e, depois, os 8 códigos de recuperação.
- **MFA já cadastrado:** pede o código de 6 dígitos.

## Contorno imediato (antes de subir o hotfix)
Apague os cookies de clyonutri.com no navegador (ou use uma janela anônima) e entre de novo. Um login novo passa pelo MFA normalmente.

## Arquivos no zip
- `src/Application/Controllers/AuthController.php`
- `src/Core/Release/BuildIdentity.php`
- `config/build-manifest.json`
- `RELEASE_INFO.json`
- este LEIA-ME

## Passos
1. **Backup:** baixe `src/Application/Controllers/AuthController.php`, `src/Core/Release/BuildIdentity.php`, `config/build-manifest.json` e `RELEASE_INFO.json` como estão hoje.
2. **Upload:** envie o zip para `/home3/maic6824/clyonutri.com`, extraia sobrescrevendo e apague o zip.
3. **Cache:** limpe o OPcache, se houver, ou espere 1 a 2 minutos.
4. **Conferência:**
   - o rodapé mostra **v54.70**;
   - com o Administrador, `clyonutri.com/login` abre a tela de MFA (cadastro na primeira vez) e, depois do código, o console administrativo.

## Reversão
Volte os arquivos do backup do passo 1.
