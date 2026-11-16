# ClyoNutri v54.65 · menu com grupos fixos (HostGator)

Pacote parcial `clyonutri-v54-65-hotfix-hostgator.zip`, aplicado sobre a v54.64 já instalada. Sem mudança de banco.

## O que muda no menu
- **Grupos fixos:** os três grupos ficam sempre abertos, sem seta e sem abrir ou fechar.
- **Atendimento:** Início, Agenda, Pacientes, Planos alimentares e Conversas (sem mudança).
- **Gestão:** vem logo depois de Atendimento, com Financeiro, Avisos e Configurações. O contador de avisos fica sempre visível.
- **Biblioteca nutricional:** Base de alimentos, Catálogo de exames, Receitas, Materiais, Modelos de plano e Clyo Academy.
- **Rodapé do menu:** Central de ajuda e Sair do consultório.
- **Itens que saíram do menu:** Assinatura, Equipe e Pedidos LGPD. Agora são áreas da tela Configurações, e quando uma delas está aberta o menu destaca Configurações.
- **Altura:** em telas de desktop baixas (até 900 px), os itens ficam um pouco mais compactos, e o menu inteiro cabe em 768 px de altura sem rolar. No celular nada muda.

## Arquivos no zip
- `views/layouts/_professional_navigation.php`
- `views/layouts/professional-shell.php` (o endereço do CSS ganha `?v=v54-65`)
- `src/Application/View/SettingsNavigation.php`
- `public/assets/css/workspace-v54-58.css`
- `src/Core/Release/BuildIdentity.php`
- `config/build-manifest.json`
- `RELEASE_INFO.json`
- este LEIA-ME

## Passos
1. **Backup:** baixe os 6 primeiros arquivos da lista acima, como estão hoje no servidor.
2. **Upload:** no Gerenciador de Arquivos, em `/home3/maic6824/clyonutri.com`, envie o zip, extraia sobrescrevendo e apague o zip.
3. **Cache:** limpe o OPcache, se o cPanel oferecer, e recarregue a página com Ctrl+Shift+R.
4. **Conferência:**
   - o rodapé do menu mostra **v54.65**;
   - os três grupos aparecem abertos, sem seta;
   - a tela Configurações mostra as áreas Equipe, Assinatura e Pedidos LGPD;
   - Central de ajuda aparece no rodapé do menu.

## Reversão
Volte os 6 arquivos do backup do passo 1.
