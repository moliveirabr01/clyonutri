# ClyoNutri v54.64 · hotfix do menu (HostGator)

Pacote parcial `clyonutri-v54-64-hotfix-hostgator.zip`, aplicado sobre a v54.63 já instalada. Sem mudança de banco.

## O que corrige
- **Indicadores:** saiu do menu Gestão. O item apontava para `/indicadores`, que não existe, e dava "Erro 404 · Página não encontrada".
- **Modelos de plano:** o item da Biblioteca nutricional abria `/planos/modelos`, que dava 403. Agora abre `/modelos` e fica destacado no menu.
- **Cache de CSS e JS:** a v54.62 alterou 7 arquivos sem trocar o `?v=`, e o navegador continuava usando a versão antiga guardada. Isso explicava o menu com seta dupla e o "▾" caindo para a linha de baixo, e podia afetar a inclusão de alimento na refeição, a agenda e os rascunhos. Agora todos usam `?v=v54-64`.

## Arquivos no zip
- `views/layouts/_professional_navigation.php`
- `views/layouts/professional-shell.php`
- `views/layouts/admin-shell.php`
- `views/consultation/room.php`
- `src/Application/Controllers/DashboardController.php`
- `src/Application/Controllers/RecipeController.php`
- `src/Core/Release/BuildIdentity.php`
- `config/build-manifest.json`
- `RELEASE_INFO.json`
- este LEIA-ME

## Passos
1. **Backup:** baixe os 8 primeiros arquivos da lista acima, como estão hoje no servidor, para poder voltar.
2. **Upload:** no Gerenciador de Arquivos, em `/home3/maic6824/clyonutri.com`, envie o zip, extraia sobrescrevendo e apague o zip.
   - Não precisa de manutenção nem de SQL.
3. **Cache:** limpe o OPcache, se o cPanel oferecer. Se não, espere 1 a 2 minutos.
4. **Conferência:**
   - o rodapé do menu mostra **v54.64**;
   - o menu Gestão não tem mais "Indicadores";
   - "Modelos de plano" abre a lista de modelos;
   - os grupos "Biblioteca nutricional" e "Gestão" mostram uma única seta, à direita, que gira ao abrir.

## Reversão
Volte os 8 arquivos do backup do passo 1.
