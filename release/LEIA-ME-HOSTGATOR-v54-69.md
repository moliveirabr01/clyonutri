# ClyoNutri v54.69 · hotfix do 403 para superadmin (HostGator)

Pacote parcial `clyonutri-v54-69-hotfix-hostgator.zip`, aplicado sobre a v54.68 já instalada. Sem mudança de banco.

## O que muda
- **Superadmin não cai mais no 403:** quem é de plataforma (superadmin, admin operacional, financeiro, auditor) e abre `/login` já logado, ou volta para `/dashboard`, vai direto para o console `/admin/dashboard`. Antes ia para o painel clínico e recebia "Acesso não permitido para o seu perfil".
- **Mesma regra para os outros perfis:** suporte vai para a fila e recepção para a agenda.
- **403 com saída:** quando um perfil abre uma página que não pode, o erro passa a oferecer "Ir para o console administrativo" ou "Ir para a fila de suporte", conforme o perfil. Antes não aparecia nenhum caminho.

## Arquivos no zip
- `public/index.php`
- `src/Core/Http/ErrorPage.php`
- `src/Core/Security/ClinicalAuthorization.php`
- `src/Application/Controllers/AuthController.php`
- `src/Core/Release/BuildIdentity.php`
- `config/build-manifest.json`
- `RELEASE_INFO.json`
- este LEIA-ME

## Passos
1. **Backup:** baixe esses arquivos como estão hoje no servidor.
2. **Upload:** no Gerenciador de Arquivos, em `/home3/maic6824/clyonutri.com`, envie o zip, extraia sobrescrevendo e apague o zip.
3. **Cache:** limpe o OPcache, se houver, ou espere 1 a 2 minutos.
4. **Conferência:**
   - o rodapé mostra **v54.69**;
   - com o usuário Administrador logado, abrir `clyonutri.com/login` ou `clyonutri.com/dashboard` leva ao console administrativo;
   - um nutricionista continua entrando no painel normalmente.

## Reversão
Volte os arquivos do backup do passo 1.
