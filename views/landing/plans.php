<?php
/*
 * RASCUNHO PARA REVISÃO DE UM ADVOGADO / DECISÃO COMERCIAL.
 * Esta página lê os preços da tabela saas_plans (valores de semente, R$ 99 e R$ 189),
 * não de uma tabela de preços confirmada. Os links ?plano= ainda não são lidos pelo cadastro.
 * A página não aparece nos menus e está marcada como noindex até os valores serem confirmados.
 */
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
  <link rel="icon" type="image/svg+xml" href="/assets/clyonutri-icon.svg?v=botanical-v54-9">
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title><?= htmlspecialchars($pageTitle ?? 'Planos e Preços — Clyo Nutri') ?></title>
  <meta name="description" content="<?= htmlspecialchars($metaDescription ?? 'Planos e condições transparentes para nutricionistas clínicos.') ?>">
  <link rel="canonical" href="<?= htmlspecialchars($canonicalUrl ?? \ClyoNutri\Core\Security\ContactInfo::url('/planos')) ?>">
  <meta name="robots" content="noindex, nofollow">
  <link rel="preload" href="/assets/fonts/inter-normal-latin.woff2" as="font" type="font/woff2" crossorigin>
  <link rel="stylesheet" href="/assets/css/fonts.css?v=botanical-v54-9">
  <link rel="stylesheet" href="/assets/css/landing-premium.css?v=botanical-v54-9">
  <style>
    .plans-hero {
      padding: 60px 0 30px;
      text-align: center;
      max-width: 840px;
      margin: 0 auto;
    }
    .plans-hero h1 {
      font-family: var(--font-editorial);
      font-size: 2.8rem;
      color: var(--brand-navy-900);
      margin-bottom: 12px;
    }
    .plans-hero p {
      font-size: 1.15rem;
      color: var(--neutral-gray-600);
      line-height: 1.6;
      margin-bottom: 24px;
    }
    .billing-toggle {
      display: inline-flex;
      align-items: center;
      background: var(--neutral-gray-100);
      padding: 4px;
      border-radius: 999px;
      margin-bottom: 40px;
      border: 1px solid var(--neutral-gray-200);
    }
    .billing-toggle button {
      border: none;
      background: transparent;
      padding: 8px 20px;
      border-radius: 999px;
      font-weight: 600;
      font-size: 0.9rem;
      color: var(--neutral-gray-600);
      cursor: pointer;
      transition: all 0.2s ease;
    }
    .billing-toggle button.active {
      background: var(--brand-navy-900);
      color: #FFFFFF;
      box-shadow: 0 2px 4px rgba(0,0,0,0.1);
    }
    .discount-badge {
      background: #DCFCE7;
      color: #166534;
      font-size: 0.75rem;
      font-weight: 700;
      padding: 2px 8px;
      border-radius: 999px;
      margin-left: 6px;
    }
    .pricing-grid {
      display: grid;
      grid-template-columns: repeat(auto-fit, minmax(280px, 1fr));
      gap: 24px;
      margin-bottom: 60px;
    }
    .pricing-card {
      background: #FFFFFF;
      border: 1px solid var(--neutral-gray-200);
      border-radius: 12px;
      padding: 32px 24px;
      display: flex;
      flex-direction: column;
      position: relative;
      transition: transform 0.2s ease, box-shadow 0.2s ease;
    }
    .pricing-card:hover {
      transform: translateY(-4px);
      box-shadow: 0 12px 24px rgba(0,0,0,0.06);
    }
    .pricing-card.featured {
      border: 2px solid var(--brand-copper-500);
      box-shadow: 0 8px 20px rgba(199, 122, 60, 0.12);
    }
    .featured-tag {
      position: absolute;
      top: -12px;
      left: 50%;
      transform: translateX(-50%);
      background: var(--brand-copper-600);
      color: #FFFFFF;
      font-size: 0.75rem;
      font-weight: 700;
      text-transform: uppercase;
      letter-spacing: 0.05em;
      padding: 4px 14px;
      border-radius: 999px;
    }
    .pricing-header h2 {
      font-size: 1.35rem;
      font-weight: 700;
      color: var(--brand-navy-900);
      margin: 0 0 8px 0;
    }
    .pricing-price {
      margin: 20px 0;
    }
    .price-value {
      font-size: 2.5rem;
      font-weight: 700;
      color: var(--brand-navy-900);
    }
    .price-period {
      font-size: 0.95rem;
      color: var(--neutral-gray-500);
    }
    .price-annual-note {
      font-size: 0.85rem;
      color: var(--brand-copper-600);
      margin-top: 4px;
      min-height: 20px;
    }
    .pricing-features {
      list-style: none;
      padding: 0;
      margin: 24px 0;
      flex-grow: 1;
    }
    .pricing-features li {
      font-size: 0.92rem;
      color: var(--neutral-gray-700);
      padding: 8px 0;
      border-bottom: 1px solid var(--neutral-gray-100);
      display: flex;
      align-items: center;
      gap: 10px;
    }
    .pricing-features li svg {
      flex-shrink: 0;
      color: var(--brand-botanical-600);
    }
    .guarantee-box {
      background: var(--neutral-gray-50);
      border: 1px solid var(--neutral-gray-200);
      border-radius: 12px;
      padding: 32px;
      margin-bottom: 60px;
    }
    .guarantee-grid {
      display: grid;
      grid-template-columns: repeat(auto-fit, minmax(240px, 1fr));
      gap: 24px;
      margin-top: 20px;
    }
    .guarantee-item h3 {
      font-size: 1.05rem;
      color: var(--brand-navy-900);
      margin-top: 0;
      margin-bottom: 6px;
    }
    .guarantee-item p {
      font-size: 0.9rem;
      color: var(--neutral-gray-600);
      line-height: 1.5;
      margin: 0;
    }
  </style>
<link rel="stylesheet" href="/assets/css/operational-ui.css?v=v54-63">
</head>
<body>
  <!-- Header -->
  <header class="header">
    <div class="container header__inner">
      <a href="/" class="logo" aria-label="Clyo Nutri Página Inicial"><img src="/assets/clyonutri-logo-light.svg?v=botanical-v54-9" alt="Clyo Nutri — Nutrição que transforma" width="260" height="64" style="display: block; width: 200px; max-width: 100%; height: auto;"></a>
      <div class="header__actions">
        <a href="/" class="btn-link">Início</a>
        <a href="/termos" class="btn-link">Termos</a>
        <a href="/login" class="btn-secondary">Entrar</a>
      </div>
    </div>
  </header>

  <main class="container">
    <section class="plans-hero">
      <p class="eyebrow" style="color: var(--brand-copper-600); font-weight: 700; letter-spacing: 0.05em; text-transform: uppercase;">TRANSPARÊNCIA TOTAL</p>
      <h1>Planos claros.<br>Sem surpresas no final do mês.</h1>
      <p>Desenvolvido para atender desde o profissional autônomo até clínicas completas. Teste por 14 dias sem informar cartão de crédito.</p>

      <div class="billing-toggle" role="group" aria-label="Opções de ciclo de cobrança">
        <button type="button" id="btn-monthly" class="active" data-cycle="monthly">Cobrança Mensal</button>
        <button type="button" id="btn-annual" data-cycle="annual">
          Cobrança Anual <span class="discount-badge">20% OFF</span>
        </button>
      </div>
    </section>

    <!-- Grade de Planos Dinâmica lida de saas_plans -->
    <div class="pricing-grid">
      <?php foreach ($plans as $p): ?>
        <?php
          $features = !empty($p['features_json']) ? json_decode($p['features_json'], true) : [];
          $monthlyPrice = $p['price_cents'] / 100;
          $annualMonthlyEq = round($monthlyPrice * 0.8, 2);
          $annualTotal = round($annualMonthlyEq * 12, 2);
          $isFeatured = $p['code'] === 'PRO-02';
        ?>
        <div class="pricing-card <?= $isFeatured ? 'featured' : '' ?>" data-plan-code="<?= htmlspecialchars($p['code']) ?>">
          <?php if ($isFeatured): ?>
            <div class="featured-tag">Mais Escolhido</div>
          <?php endif; ?>

          <div class="pricing-header">
            <h2><?= htmlspecialchars($p['name']) ?></h2>
            <div class="pricing-price">
              <span class="price-value" data-monthly="R$ <?= number_format($monthlyPrice, 0, ',', '.') ?>" data-annual="R$ <?= number_format($annualMonthlyEq, 0, ',', '.') ?>">
                R$ <?= number_format($monthlyPrice, 0, ',', '.') ?>
              </span>
              <span class="price-period">/ mês</span>
              <div class="price-annual-note" data-monthly="" data-annual="Faturado anualmente (R$ <?= number_format($annualTotal, 2, ',', '.') ?>/ano)"></div>
            </div>
          </div>

          <ul class="pricing-features">
            <li>
              <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><polyline points="20 6 9 17 4 12"/></svg>
              <span><?= $p['max_professionals'] > 0 ? 'Até ' . (int)$p['max_professionals'] . ' profissional(is)' : 'Profissionais ilimitados' ?></span>
            </li>
            <li>
              <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><polyline points="20 6 9 17 4 12"/></svg>
              <span><?= $p['max_patients'] > 0 ? 'Até ' . (int)$p['max_patients'] . ' pacientes ativos' : 'Pacientes ilimitados' ?></span>
            </li>
            <li>
              <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><polyline points="20 6 9 17 4 12"/></svg>
              <span>Base TACO completa e cálculo calórico</span>
            </li>
            <?php if (!empty($features['teleconsultation'])): ?>
              <li>
                <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><polyline points="20 6 9 17 4 12"/></svg>
                <span>Teleconsulta com sala virtual integrada</span>
              </li>
            <?php endif; ?>
            <li>
              <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><polyline points="20 6 9 17 4 12"/></svg>
              <span>Prontuário com trilha auditável LGPD</span>
            </li>
            <?php if (!empty($features['priority_support'])): ?>
              <li>
                <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><polyline points="20 6 9 17 4 12"/></svg>
                <span>Suporte prioritário</span>
              </li>
            <?php endif; ?>
          </ul>

          <div style="margin-top: auto;">
            <a href="/cadastro?plano=<?= htmlspecialchars($p['code']) ?>" class="btn <?= $isFeatured ? 'btn-primary' : 'btn-secondary' ?>" style="display: block; text-align: center; text-decoration: none; padding: 12px; font-weight: 600; border-radius: 6px;">
              Começar Teste de 14 Dias →
            </a>
          </div>
        </div>
      <?php endforeach; ?>
    </div>

    <!-- Seção de Direitos Comerciais e Garantias CDC -->
    <section class="guarantee-box">
      <div style="text-align: center; max-width: 680px; margin: 0 auto 24px;">
        <h2 style="font-size: 1.6rem; color: var(--brand-navy-900); margin-bottom: 8px;">Compromisso de Clareza e Respeito ao Assinante</h2>
        <p style="color: var(--neutral-gray-600); font-size: 0.95rem; margin: 0;">
          Suas garantias fundamentadas no Código de Defesa do Consumidor (Lei 8.078/90) e Decreto 7.962/2013:
        </p>
      </div>

      <div class="guarantee-grid">
        <div class="guarantee-item">
          <h3>14 Dias Grátis sem Cartão</h3>
          <p>Conheça a plataforma na prática clínica antes de pagar qualquer valor. Sem necessidade de cadastrar cartão no início.</p>
        </div>
        <div class="guarantee-item">
          <h3>Direito de Arrependimento (CDC art. 49)</h3>
          <p>Se em até 7 dias corridos da contratação você desistir, nós devolvemos 100% do valor pago, sem burocracia ou letras miúdas.</p>
        </div>
        <div class="guarantee-item">
          <h3>Cancelamento em Autosserviço</h3>
          <p>Você cancela com um clique em <code>/configuracoes/assinatura</code> e recebe um protocolo comprovando o cancelamento imediato.</p>
        </div>
        <div class="guarantee-item">
          <h3>Sem Multas ou Fidelidade</h3>
          <p>Planos mensais e anuais sem taxas rescisórias ocultas. Seu acesso continua ativo até o final do ciclo já pago.</p>
        </div>
      </div>

      <div style="text-align: center; margin-top: 28px; padding-top: 20px; border-top: 1px solid var(--neutral-gray-200);">
        <p style="font-size: 0.9rem; color: var(--neutral-gray-600); margin: 0;">
          Dúvidas comerciais ou contratuais? Consulte os nossos <a href="/termos#preco" style="color: var(--brand-copper-600); font-weight: 600; text-decoration: underline;">Termos de Uso</a> ou fale com nossa equipe em <code><?= htmlspecialchars(\ClyoNutri\Core\Security\ContactInfo::supportEmail()) ?></code>.
        </p>
      </div>
    </section>
  </main>

  <footer class="footer">
    <div class="container footer-bottom" style="border-top: none; padding-top: 0;">
      <p>© 2026 Clyo Nutri. Todos os direitos reservados.</p>
      <p>
        <a href="/termos" style="color: var(--neutral-gray-500); text-decoration: underline; margin-right: 16px;">Termos de Uso</a>
        <a href="/privacidade" style="color: var(--neutral-gray-500); text-decoration: underline;">Política de Privacidade</a>
      </p>
    </div>
  </footer>

  <script nonce="<?= \ClyoNutri\Core\Http\SecurityHeaders::nonce() ?>">
    function switchBilling(cycle) {
      const btnMonthly = document.getElementById('btn-monthly');
      const btnAnnual = document.getElementById('btn-annual');
      const priceValues = document.querySelectorAll('.price-value');
      const annualNotes = document.querySelectorAll('.price-annual-note');

      if (cycle === 'annual') {
        btnAnnual.classList.add('active');
        btnMonthly.classList.remove('active');
        priceValues.forEach(el => el.textContent = el.dataset.annual);
        annualNotes.forEach(el => el.textContent = el.dataset.annual);
      } else {
        btnMonthly.classList.add('active');
        btnAnnual.classList.remove('active');
        priceValues.forEach(el => el.textContent = el.dataset.monthly);
        annualNotes.forEach(el => el.textContent = el.dataset.monthly);
      }
    }
    document.querySelectorAll('.billing-toggle [data-cycle]').forEach(btn => {
      btn.addEventListener('click', () => switchBilling(btn.dataset.cycle));
    });
  </script>
</body>
</html>
