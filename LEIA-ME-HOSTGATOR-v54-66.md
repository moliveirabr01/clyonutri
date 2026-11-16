# ClyoNutri v54.66 · ajustes de tipografia e design system (HostGator)

Pacote parcial `clyonutri-v54-66-hotfix-hostgator.zip`, aplicado sobre a v54.65 já instalada. Sem mudança de banco.

## O que muda
- **Símbolos na fonte certa:** setas, ≤ ≥ ≈ Δ ✓ ★ ⚠ e afins passam a sair na Inter (nova face `inter-normal-symbols.woff2`, 21 glifos). Antes caíam numa fonte do sistema e ficavam com outro desenho.
- **"Mais ações"** (Início) usa o ícone de seta do sistema de ícones; botões de fechar usam "×".
- **Nada abaixo de 12 px:** textos de 0,68 a 0,72 rem em Receitas e Onboarding sobem para o token de legenda (0,75 rem). O rótulo dos grupos do menu também, e o menu continua cabendo em 1366×768.
- **Inclusão rápida de alimento no plano:** os campos ganham o estilo padrão (altura 44 px, borda e raio de input). Selects ficam com fundo branco.
- **Toque e links:** "Imprimir plano" com área de toque de 44 px; "Ver na agenda" com estilo de link.
- **Tokens:** bordas soltas em Materiais e Academy passam para os tokens do DS.
- **Acessibilidade:** Conversas ganha um título de página para leitor de tela.
- **Cache:** todos os CSS alterados ganham `?v=v54-66`, então ninguém fica com a versão velha.

## Arquivos no zip
52 arquivos de aplicação (39 views, 7 classes PHP, 5 CSS e 1 fonte nova) mais `config/build-manifest.json`, `RELEASE_INFO.json` e este LEIA-ME. A lista completa está em `RELEASE_INFO.json`, em `package.files`. A maior parte das views muda só o `?v=` dos CSS.

## Passos
1. **Backup:** baixe as pastas `views`, `src`, `public/assets/css` e `config` como estão hoje no servidor (é mais simples que separar arquivo por arquivo).
2. **Upload:** no Gerenciador de Arquivos, em `/home3/maic6824/clyonutri.com`, envie o zip, extraia sobrescrevendo e apague o zip.
3. **Cache:** limpe o OPcache, se o cPanel oferecer, e recarregue com Ctrl+Shift+R.
4. **Conferência:**
   - o rodapé do menu mostra **v54.66**;
   - em Início, "Mais ações" mostra uma seta fina (ícone), não "▾";
   - abra um plano alimentar e use a inclusão rápida de alimento: os campos têm a mesma altura e borda dos outros formulários;
   - em DevTools > Network, `fonts.css?v=v54-66` e `inter-normal-symbols.woff2` carregam com 200 numa página que tenha seta ou "≥".

## Reversão
Volte as pastas do backup do passo 1. O arquivo novo `public/assets/fonts/inter-normal-symbols.woff2` pode ficar; sem a v54.66 ninguém o pede.
