<!DOCTYPE html>
<html lang="pt-BR">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Clyo Nutri: da consulta ao plano alimentar</title>
  <meta name="description" content="Prontuário, anamnese, avaliações e planos alimentares no mesmo fluxo de atendimento nutricional. Conheça o Clyo Nutri.">
  <link rel="canonical" href="<?= htmlspecialchars($canonicalUrl ?? \ClyoNutri\Core\Security\ContactInfo::url('/')) ?>">
  <meta property="og:type" content="website">
  <meta property="og:title" content="Clyo Nutri: prontuário, avaliação e plano alimentar para nutricionistas">
  <meta property="og:description" content="Da consulta ao plano alimentar, com o histórico do paciente por perto.">
  <meta property="og:url" content="<?= htmlspecialchars(\ClyoNutri\Core\Security\ContactInfo::url('/')) ?>">
  <meta property="og:image" content="<?= htmlspecialchars(\ClyoNutri\Core\Security\ContactInfo::url('/assets/clyonutri-logo-light.png')) ?>">
  <meta property="og:image:alt" content="Logotipo do Clyo Nutri">
  <meta property="og:locale" content="pt_BR">
  <meta name="twitter:card" content="summary_large_image">
  <link rel="icon" type="image/svg+xml" href="/assets/clyonutri-icon.svg?v=botanical-v54-9">
  <link rel="preload" href="/assets/fonts/inter-normal-latin.woff2" as="font" type="font/woff2" crossorigin>
  <link rel="stylesheet" href="/assets/css/fonts.css?v=v54-66">
  <link rel="stylesheet" href="/assets/css/landing-premium.css?v=botanical-v54-9">
<link rel="stylesheet" href="/assets/css/operational-ui.css?v=v54-66">
</head>
<body>
  <a class="skip-link" href="#main-content">Pular para o conteúdo principal</a>
  <header class="header">
    <div class="container header__inner">
      <a href="/" class="logo" aria-label="Clyo Nutri Página Inicial"><img src="/assets/clyonutri-logo-light.svg?v=botanical-v54-9" alt="Clyo Nutri" width="260" height="64"></a>
      <nav class="nav" aria-label="Navegação principal">
        <a href="#produto" class="tap-target">O atendimento</a><a href="#prescricao" class="tap-target">O plano alimentar</a><a href="#acesso" class="tap-target">Acesso</a><a href="/nutricionistas" class="tap-target">Encontrar nutricionista</a>
      </nav>
      <div class="header__actions">
        <a class="desktop-login tap-target" href="/login">Entrar</a>
        <a class="btn-primary desktop-cta" href="<?= !empty($isLoggedIn) ? '/dashboard' : '/cadastro' ?>"><?= !empty($isLoggedIn) ? 'Meu consultório' : 'Criar conta' ?></a>
        <button class="mobile-toggle tap-target" type="button" aria-expanded="false" aria-controls="mobile-menu" aria-label="Abrir menu de navegação">Menu <span aria-hidden="true">☰</span></button>
      </div>
    </div>
    <nav id="mobile-menu" class="mobile-menu" aria-label="Navegação móvel" hidden>
      <a href="#produto" class="tap-target">O atendimento</a><a href="#prescricao" class="tap-target">O plano alimentar</a><a href="#acesso" class="tap-target">Acesso e condições</a><a href="/nutricionistas" class="tap-target">Encontrar nutricionista</a><a href="/login" class="tap-target">Entrar</a><a href="/cadastro" class="tap-target">Criar conta</a>
    </nav>
  </header>
  <main id="main-content" tabindex="-1">
    <section class="hero">
      <div class="container hero-grid">
        <div class="hero-copy">
          <p class="eyebrow">PARA QUEM CUIDA DA NUTRIÇÃO</p>
          <h1>Da consulta ao plano alimentar.<br><em>Sem perder o contexto.</em></h1>
          <p class="lead">Prontuário, anamnese, avaliações e planos por refeição. Você atende com o histórico do paciente à mão, sem procurar papel.</p>
          <div class="hero-actions"><a href="#produto" class="btn-primary">Conhecer o atendimento <span aria-hidden="true">↗</span></a><a href="/cadastro" class="text-link">Criar minha conta →</a></div>
          <p class="fine-print">Uma ferramenta de apoio. A decisão clínica continua com você.</p>
        </div>
        <div class="clinical-preview" aria-label="Exemplo ilustrativo de atendimento, com dados fictícios">
          <div class="preview-top"><span class="demo-label">EXEMPLO ILUSTRATIVO</span><span class="preview-symbol" aria-hidden="true">↗</span></div>
          <p class="preview-caption">O contexto vem primeiro.</p>
          <h2>Marina, paciente de exemplo</h2>
          <p class="muted">Retorno · acompanhamento nutricional</p>
          <div class="clinical-note"><span class="note-index">01</span><div><p class="small-label">NA CONSULTA</p><p>Rever a rotina de refeições e as preferências registradas.</p></div></div>
          <div class="clinical-note"><span class="note-index">02</span><div><p class="small-label">NA PRESCRIÇÃO</p><p>Organizar refeições com quantidades e orientações claras.</p></div></div>
          <div class="preview-bottom"><span>Contexto</span><span aria-hidden="true">——</span><span>Plano</span><span aria-hidden="true">——</span><span>Revisão</span></div>
        </div>
      </div>
    </section>
    <section class="workflow section-padding" id="produto" aria-labelledby="workflow-title">
      <div class="container">
        <div class="section-intro"><p class="eyebrow">UM FIO DE CONTINUIDADE</p><h2 id="workflow-title">O atendimento muda de etapa.<br>O contexto permanece.</h2><p>Explore como as áreas do Clyo Nutri se conectam. Esta apresentação usa situações e dados fictícios.</p></div>
        <div class="journey-tabs" role="tablist" aria-label="Etapas do atendimento">
          <button type="button" role="tab" id="tab-contexto" aria-controls="panel-contexto" aria-selected="true" tabindex="0"><span>01</span> Conhecer</button>
          <button type="button" role="tab" id="tab-plano" aria-controls="panel-plano" aria-selected="false" tabindex="-1"><span>02</span> Prescrever</button>
          <button type="button" role="tab" id="tab-revisao" aria-controls="panel-revisao" aria-selected="false" tabindex="-1"><span>03</span> Revisar</button>
        </div>
        <div class="journey-panel" id="panel-contexto" role="tabpanel" aria-labelledby="tab-contexto" tabindex="0">
          <div><p class="small-label">PRONTUÁRIO + ANAMNESE</p><h3>Comece pela pessoa.<br>Não por uma tela em branco.</h3><p>Consulte informações cadastrais, anamnese e registros clínicos no perfil do paciente. Mantenha o histórico como referência para o próximo atendimento.</p><a class="text-link" href="/cadastro">Conhecer no meu consultório →</a></div>
          <div class="example-sheet"><span class="demo-label">EXEMPLO · SEM DADOS REAIS</span><h4>Antes de prescrever</h4><dl><div><dt>Objetivo registrado</dt><dd>Organizar a rotina alimentar</dd></div><div><dt>Preferência relatada</dt><dd>Refeições simples de preparar</dd></div><div><dt>Ponto para conversar</dt><dd>Horários de trabalho e pausas</dd></div></dl></div>
        </div>
        <div class="journey-panel" id="panel-plano" role="tabpanel" aria-labelledby="tab-plano" tabindex="0" hidden>
          <div><p class="small-label">PLANO ALIMENTAR</p><h3>Uma refeição de cada vez.<br>Uma orientação compreensível.</h3><p>Organize o plano por refeições, selecione alimentos do catálogo e registre quantidades e orientações. Revise o rascunho antes de disponibilizar a prescrição.</p><a class="text-link" href="#prescricao">Explorar um exemplo de cálculo →</a></div>
          <div class="example-sheet"><span class="demo-label">EXEMPLO ILUSTRATIVO</span><h4>Almoço · orientação</h4><p>Quantidades e alimentos definidos pelo profissional, de acordo com a avaliação individual.</p><div class="sheet-status">Rascunho → Revisão → Publicação</div></div>
        </div>
        <div class="journey-panel" id="panel-revisao" role="tabpanel" aria-labelledby="tab-revisao" tabindex="0" hidden>
          <div><p class="small-label">AVALIAÇÕES + EVOLUÇÃO</p><h3>Volte ao histórico.<br>Prepare a próxima decisão.</h3><p>Consulte avaliações antropométricas e registros anteriores para apoiar a revisão da conduta. O comparativo depende das medições registradas pelo profissional.</p><a class="text-link" href="/cadastro">Criar minha conta →</a></div>
          <div class="example-sheet"><span class="demo-label">EXEMPLO ILUSTRATIVO</span><h4>Na consulta de retorno</h4><ul class="plain-list"><li>Retomar os objetivos registrados</li><li>Rever medidas e observações</li><li>Registrar a nova conduta</li></ul></div>
        </div>
        <noscript><p>O Clyo Nutri permite organizar planos por refeição e consultar avaliações anteriores. A demonstração de etapas usa JavaScript; o cadastro e os demais links continuam disponíveis.</p></noscript>
      </div>
    </section>
    <section class="prescription section-padding" id="prescricao" aria-labelledby="prescription-title">
      <div class="container prescription-grid">
        <div><p class="eyebrow">CLAREZA NA QUANTIDADE</p><h2 id="prescription-title">O detalhe faz parte<br>do cuidado.</h2><p class="section-description">Uma quantidade deve vir acompanhada de unidade e referência. Experimente este cálculo demonstrativo para ver a diferença entre 100 g e 150 g.</p><p class="fine-print">Exemplo isolado da aplicação. Não salva dados, não constitui prescrição e não substitui avaliação profissional.</p></div>
        <div class="calculation-demo">
          <span class="demo-label">DEMONSTRAÇÃO INTERATIVA</span><h3>Arroz integral cozido</h3><p class="muted">Referência do exemplo: 124 kcal a cada 100 g.</p>
          <label for="demo-quantity">Quantidade em gramas</label><select id="demo-quantity"><option value="100">100 g</option><option value="150">150 g</option><option value="200">200 g</option></select>
          <div class="energy-result" aria-live="polite" aria-atomic="true"><strong id="demo-energy">124</strong><span>kcal para <span id="demo-weight">100</span> g</span></div><p class="calculation-rule">Quantidade × 124 ÷ 100. Valor arredondado para kcal inteiras.</p>
          <noscript><p>Com 150 g, este exemplo corresponde a 186 kcal. Ative JavaScript para alternar as quantidades.</p></noscript>
        </div>
      </div>
    </section>
    <section class="patient-section section-padding" aria-labelledby="patient-title">
      <div class="container patient-grid"><div class="patient-statement"><p class="eyebrow">DO CONSULTÓRIO PARA A ROTINA</p><h2 id="patient-title">Uma orientação precisa<br>ser compreendida.</h2></div><div><p class="section-description">O plano por refeições ajuda a organizar alimentos, quantidades e observações em uma sequência que faz sentido para o atendimento.</p><p>O paciente acessa pelo navegador do celular: vê o plano publicado e registra água e refeições. O app ainda está em beta, então alguns recursos podem mudar.</p><a class="text-link" href="mailto:<?= htmlspecialchars(\ClyoNutri\Core\Security\ContactInfo::supportEmail()) ?>">Consultar disponibilidade →</a></div></div>
    </section>
    <section class="trust section-padding" id="confianca" aria-labelledby="trust-title">
      <div class="container"><div class="section-intro"><p class="eyebrow">CONFIANÇA EXIGE CONTEXTO</p><h2 id="trust-title">Informação clara.<br>Responsabilidades também.</h2></div><div class="trust-grid"><article><span class="trust-number">01</span><h3>Histórico clínico</h3><p>Registros anteriores ajudam a acompanhar o atendimento. Consulte a documentação sobre versionamento e trilhas de integridade.</p><a href="/ajuda" class="text-link">Central de ajuda →</a></article><article><span class="trust-number">02</span><h3>Privacidade</h3><p>Entenda quais dados são tratados, suas finalidades e os papéis do profissional e da plataforma.</p><a href="/privacidade" class="text-link">Política de privacidade →</a></article><article><span class="trust-number">03</span><h3>Julgamento profissional</h3><p>O software apoia a organização e a prescrição. A avaliação e as decisões clínicas são do nutricionista responsável.</p><a href="/termos#etica" class="text-link">Responsabilidades de uso →</a></article></div></div>
    </section>
    <section class="access section-padding" id="acesso" aria-labelledby="access-title"><div class="container access-grid"><div><p class="eyebrow">SEU PRÓXIMO PASSO</p><h2 id="access-title">Conheça o produto.<br>Decida com clareza.</h2><p class="section-description">Crie sua conta profissional para acessar o fluxo de configuração do consultório.</p></div><div class="access-details"><h3>Antes de começar</h3><dl><div><dt>Cadastro profissional</dt><dd>Nome, clínica, CRN, e-mail e senha.</dd></div><div><dt>Período de avaliação</dt><dd>São 14 dias de uso sem cartão. Depois, para continuar editando e cadastrando, é preciso assinar um plano.</dd></div><div><dt>Assinatura e continuidade</dt><dd>Os preços ainda não estão nesta página. Fale com a gente para saber valores e limites antes de assinar.</dd></div></dl><a href="/cadastro" class="btn-primary">Criar minha conta →</a><a class="text-link" href="mailto:<?= htmlspecialchars(\ClyoNutri\Core\Security\ContactInfo::supportEmail()) ?>">Esclarecer condições de acesso</a></div></div></section>
    <section class="faq section-padding" id="faq" aria-labelledby="faq-title"><div class="container faq-grid"><div><p class="eyebrow">ANTES DA PRIMEIRA CONSULTA</p><h2 id="faq-title">Dúvidas que merecem<br>respostas diretas.</h2></div><div class="faq-list">
      <details><summary>Preciso informar cartão no cadastro?</summary><p>Não. O cadastro não pede cartão de crédito.</p></details>
      <details><summary>O que acontece depois dos 14 dias?</summary><p>Para continuar editando e cadastrando pacientes, você assina um plano. Os preços ainda não estão publicados; fale com o suporte.</p></details>
      <details><summary>As demonstrações usam dados de pacientes?</summary><p>Não. Os exemplos desta página são fictícios, e o cálculo de quantidade só funciona aqui na demonstração.</p></details>
      <details><summary>O aplicativo do paciente já está disponível?</summary><p>Sim, em beta e pelo navegador do celular: o paciente vê o plano publicado e registra água e refeições. Não precisa instalar nada.</p></details>
      <details><summary>Como solicito a exportação dos meus dados?</summary><p>Peça pelo suporte. Os termos garantem a portabilidade, e a gente combina com você o formato e o que entra na exportação.</p></details>
      <details><summary>Onde encontro informações sobre privacidade?</summary><p>A <a href="/privacidade">Política de Privacidade</a> descreve finalidades, responsabilidades e o canal de contato para questões sobre dados.</p></details>
    </div></div></section>
    <section class="final-section"><div class="container final-inner"><div><p class="eyebrow">CLYO NUTRI</p><h2>Clareza que acompanha<br>o cuidado.</h2></div><div><a href="/cadastro" class="btn-primary">Criar minha conta →</a><a class="final-login" href="/login">Já tenho conta · Entrar</a></div></div></section>
  </main>
  <footer class="footer"><div class="container footer-grid"><div><a href="/" class="logo" aria-label="Clyo Nutri Página Inicial"><img src="/assets/clyonutri-logo-light.svg?v=botanical-v54-9" alt="Clyo Nutri" width="260" height="64" loading="lazy"></a><p>Ferramentas para o atendimento nutricional.<br>Contexto para cuidar.</p></div><nav aria-label="Informações e contato"><a href="/privacidade">Privacidade</a><a href="/termos">Termos de uso</a><a href="mailto:<?= htmlspecialchars(\ClyoNutri\Core\Security\ContactInfo::supportEmail()) ?>"><?= htmlspecialchars(\ClyoNutri\Core\Security\ContactInfo::supportEmail()) ?></a></nav></div><div class="container footer-bottom"><p>© 2026 Clyo Nutri.</p><a href="/login">Acessar meu consultório →</a></div></footer>
  <script nonce="<?= \ClyoNutri\Core\Http\SecurityHeaders::nonce() ?>">
    const menuButton = document.querySelector('.mobile-toggle');
    const menu = document.getElementById('mobile-menu');
    function closeMenu(restoreFocus = false) {
      menu.hidden = true;
      menuButton.setAttribute('aria-expanded', 'false');
      menuButton.setAttribute('aria-label', 'Abrir menu de navegação');
      if (restoreFocus) { menuButton.focus(); }
    }
    menuButton.addEventListener('click', () => {
      const isOpen = menuButton.getAttribute('aria-expanded') === 'true';
      if (isOpen) { closeMenu(); } else {
        menu.hidden = false;
        menuButton.setAttribute('aria-expanded', 'true');
        menuButton.setAttribute('aria-label', 'Fechar menu de navegação');
      }
    });
    menu.addEventListener('click', (event) => { if (event.target.closest('a')) { closeMenu(); } });
    document.addEventListener('keydown', (event) => { if (event.key === 'Escape' && !menu.hidden) { closeMenu(true); } });
    const desktopQuery = window.matchMedia('(min-width: 901px)');
    desktopQuery.addEventListener('change', (event) => { if (event.matches) { closeMenu(); } });
    const tabs = Array.from(document.querySelectorAll('[role="tab"]'));
    function selectTab(selected, focus = false) {
      tabs.forEach((tab) => {
        const active = tab === selected;
        tab.setAttribute('aria-selected', String(active));
        tab.tabIndex = active ? 0 : -1;
        document.getElementById(tab.getAttribute('aria-controls')).hidden = !active;
      });
      if (focus) { selected.focus(); }
    }
    tabs.forEach((tab, index) => {
      tab.addEventListener('click', () => selectTab(tab));
      tab.addEventListener('keydown', (event) => {
        let next;
        if (event.key === 'ArrowRight') { next = (index + 1) % tabs.length; }
        if (event.key === 'ArrowLeft') { next = (index - 1 + tabs.length) % tabs.length; }
        if (event.key === 'Home') { next = 0; }
        if (event.key === 'End') { next = tabs.length - 1; }
        if (next !== undefined) { event.preventDefault(); selectTab(tabs[next], true); }
      });
    });
    document.getElementById('demo-quantity').addEventListener('change', (event) => {
      const quantity = Number(event.target.value);
      document.getElementById('demo-energy').textContent = String(Math.round(quantity * 124 / 100));
      document.getElementById('demo-weight').textContent = String(quantity);
    });
  </script>
</body>
</html>
