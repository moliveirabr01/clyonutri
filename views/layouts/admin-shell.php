<!DOCTYPE html>
<html lang="pt-BR">
<head>
  <link rel="icon" type="image/svg+xml" href="/assets/clyonutri-icon.svg?v=botanical-v54-9">
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title><?= htmlspecialchars($title ?? 'Console administrativo') ?> · Clyo Nutri administração</title>
  <link rel="preload" href="/assets/fonts/inter-normal-latin.woff2" as="font" type="font/woff2" crossorigin>
  <link rel="stylesheet" href="/assets/css/fonts.css?v=botanical-v54-9">
  <style>
    :root {
      --admin-navy-950: #0A2A10;
      --admin-navy-900: #0F3E17;
      --admin-navy-850: #154820;
      --admin-navy-800: #1B5226;
      --admin-navy-700: #2F6140;
      --admin-green-600: #39734A;
      --admin-green-700: #2F6140;
      --admin-copper-600: #B04A1B;
      --admin-copper-500: #EF8F4F;
      --admin-copper-400: #F4A06A;
      --admin-copper-100: #FBEADF;
      --admin-copper-50: #FDF4EE;
      --admin-gray-50: #f8fafc;
      --admin-gray-100: #f1f5f9;
      --admin-gray-200: #e2e8f0;
      --admin-gray-300: #cbd5e1;
      --admin-gray-400: #94a3b8;
      --admin-gray-500: #64748b;
      --admin-gray-600: #475569;
      --admin-gray-700: #334155;
      --admin-gray-800: #1e293b;
      --admin-gray-900: #0f172a;
      /* Semânticos = os mesmos do DS (clinical-botanical.css). Os Tailwind 600 de antes
         ficavam abaixo de 4,5:1 como texto (sucesso 3,3; alerta 3,2; info 4,1). NI2-25.
         Sucesso é teal (distinto do Clyo Green da marca). */
      --admin-success: #0A7075;
      --admin-success-bg: #E3F4F2;
      --admin-warning: #946200;
      --admin-warning-bg: #fef3c7;
      --admin-danger: #B42318;
      --admin-danger-bg: #fee2e2;
      --admin-info: #175CD3;
      --admin-info-bg: #e0f2fe;
      --admin-border-input: #828B9B; /* v54.24: mesma borda de campo do DS (--border-input), 3,6:1 sobre branco */
    }

    * {
      box-sizing: border-box;
      margin: 0;
      padding: 0;
    }

    body {
      font-family: 'Inter', -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif;
      background-color: var(--admin-gray-50);
      color: var(--admin-gray-900);
      line-height: 1.5;
      min-height: 100vh;
      display: flex;
      flex-direction: column;
    }

    .skip-admin-content,
    .skip-link {
      position: absolute;
      top: -9999px;
      left: 1rem;
      background: var(--admin-copper-600);
      color: #fff;
      padding: 0.6rem 1.2rem;
      border-radius: 6px;
      z-index: 9999;
      text-decoration: none;
      font-weight: 600;
      box-shadow: 0 4px 12px rgba(0, 0, 0, 0.15);
      transition: top 0.15s ease-in-out;
    }
    .skip-admin-content:focus,
    .skip-admin-content:focus-visible,
    .skip-link:focus,
    .skip-link:focus-visible {
      top: 1rem;
      outline: 3px solid var(--admin-navy-900, #0F3E17);
      outline-offset: 2px;
    }

    .admin-layout {
      display: flex;
      min-height: 100vh;
      width: 100%;
    }

    /* Sidebar - Forest escuro com acentos cobre (nomes --admin-navy-* são legados; o valor é Forest) */
    .admin-sidebar {
      width: 270px;
      background-color: var(--admin-navy-900);
      color: #fff;
      display: flex;
      flex-direction: column;
      flex-shrink: 0;
      border-right: 1px solid var(--admin-navy-850);
      transition: transform 0.25s ease-in-out;
      z-index: 40;
    }

    .admin-sidebar__brand {
      padding: 1.25rem 1.5rem;
      border-bottom: 1px solid rgba(255, 255, 255, 0.08);
      display: flex;
      align-items: center;
      gap: 0.75rem;
    }

    .admin-sidebar__logo-badge {
      width: 38px;
      height: 38px;
      border-radius: 8px;
      background: linear-gradient(135deg, var(--admin-copper-500), var(--admin-copper-600));
      display: flex;
      align-items: center;
      justify-content: center;
      color: #fff;
      font-weight: 700;
      font-size: 1.1rem;
      box-shadow: 0 2px 8px rgba(239, 143, 79, 0.35);
    }

    .admin-sidebar__brand-text h1 {
      font-size: 1.05rem;
      font-weight: 700;
      letter-spacing: -0.02em;
      color: #fff;
    }
    .admin-sidebar__brand-text span {
      font-size: 0.7rem;
      text-transform: uppercase;
      letter-spacing: 0.08em;
      color: var(--admin-copper-400);
      font-weight: 600;
      display: block;
    }

    .admin-sidebar__nav {
      padding: 1rem 0.75rem;
      display: flex;
      flex-direction: column;
      gap: 0.25rem;
      flex: 1;
      overflow-y: auto;
    }

    .admin-nav__group-label {
      font-size: 0.68rem;
      text-transform: uppercase;
      letter-spacing: 0.08em;
      color: var(--admin-gray-400);
      padding: 0.75rem 0.75rem 0.25rem;
      font-weight: 600;
    }

    .admin-nav__item {
      display: flex;
      align-items: center;
      gap: 0.75rem;
      padding: 0.65rem 0.85rem;
      color: #cbd5e1;
      text-decoration: none;
      font-size: 0.88rem;
      font-weight: 500;
      border-radius: 6px;
      transition: all 0.15s ease;
    }
    .admin-nav__item:hover {
      background-color: var(--admin-navy-850);
      color: #fff;
    }
    .admin-nav__item.active {
      background-color: var(--admin-navy-800);
      color: #fff;
      font-weight: 600;
      border-left: 3px solid var(--admin-copper-500);
    }
    .admin-nav__item svg {
      width: 18px;
      height: 18px;
      stroke-width: 2;
      flex-shrink: 0;
      color: var(--admin-gray-400);
    }
    .admin-nav__item:hover svg,
    .admin-nav__item.active svg {
      color: var(--admin-copper-400);
    }
    .admin-nav__badge {
      margin-left: auto;
      font-size: 0.72rem;
      padding: 0.15rem 0.45rem;
      border-radius: 9999px;
      background-color: var(--admin-copper-600);
      color: #fff;
      font-weight: 600;
    }

    .admin-sidebar__footer {
      padding: 1rem 1.25rem;
      border-top: 1px solid rgba(255, 255, 255, 0.08);
      background-color: var(--admin-navy-950);
      display: flex;
      align-items: center;
      justify-content: space-between;
      font-size: 0.78rem;
      color: var(--admin-gray-400);
    }
    .admin-sidebar__env {
      display: inline-flex;
      align-items: center;
      gap: 6px;
    }
    .admin-sidebar__status-dot {
      width: 8px;
      height: 8px;
      border-radius: 50%;
      background-color: var(--admin-success);
      box-shadow: 0 0 6px rgba(10, 112, 117, 0.6);
    }

    /* Main Content Area */
    .admin-main {
      flex: 1;
      display: flex;
      flex-direction: column;
      min-width: 0;
      background-color: var(--admin-gray-50);
    }

    /* Header Topbar */
    .admin-header {
      height: 64px;
      background-color: #fff;
      border-bottom: 1px solid var(--admin-gray-200);
      display: flex;
      align-items: center;
      justify-content: space-between;
      padding: 0 1.5rem;
      position: sticky;
      top: 0;
      z-index: 30;
    }

    .admin-header__left {
      display: flex;
      align-items: center;
      gap: 1rem;
      flex: 1;
      max-width: 600px;
    }

    .admin-menu-toggle {
      display: none;
      background: none;
      border: 1px solid #828B9B;
      border-radius: 6px;
      padding: 0.4rem;
      cursor: pointer;
      color: var(--admin-gray-700);
      min-width: 44px;
      min-height: 44px;
    }

    .admin-search-form {
      position: relative;
      width: 100%;
      max-width: 440px;
    }
    .admin-search-form input {
      width: 100%;
      min-height: 40px;
      padding: 0.48rem 0.85rem 0.48rem 2.25rem;
      font-size: 0.85rem;
      border: 1px solid #828B9B;
      border-radius: 6px;
      background-color: var(--admin-gray-50);
      transition: all 0.2s;
    }
    .admin-search-form input:focus {
      outline: none;
      border-color: var(--admin-green-600);
      background-color: #fff;
      box-shadow: 0 0 0 3px rgba(57, 115, 74, 0.18);
    }
    .admin-search-icon {
      position: absolute;
      left: 0.75rem;
      top: 50%;
      transform: translateY(-50%);
      width: 16px;
      height: 16px;
      color: var(--admin-gray-400);
      pointer-events: none;
    }

    .admin-header__right {
      display: flex;
      align-items: center;
      gap: 1.25rem;
    }

    .admin-operator-badge {
      display: flex;
      align-items: center;
      gap: 0.65rem;
      font-size: 0.84rem;
    }
    .admin-operator-avatar {
      width: 34px;
      height: 34px;
      border-radius: 50%;
      background: var(--admin-navy-800);
      color: #fff;
      display: flex;
      align-items: center;
      justify-content: center;
      font-weight: 600;
      font-size: 0.85rem;
      border: 2px solid var(--admin-copper-500);
    }
    .admin-operator-info {
      line-height: 1.2;
    }
    .admin-operator-name {
      font-weight: 600;
      color: var(--admin-gray-900);
    }
    .admin-operator-role {
      font-size: 0.72rem;
      color: var(--admin-copper-600);
      font-weight: 600;
      text-transform: uppercase;
    }

    .admin-logout-btn {
      display: inline-flex;
      align-items: center;
      gap: 4px;
      color: var(--admin-danger);
      text-decoration: none;
      font-size: 0.84rem;
      font-weight: 500;
      padding: 0.4rem 0.75rem;
      border-radius: 6px;
      border: 1px solid rgba(220, 38, 38, 0.2);
      transition: all 0.15s;
      background: transparent;
      font-family: inherit;
      cursor: pointer;
      min-height: 44px;
    }
    /* v34: sino de notificações do backoffice (contador na carga da página, sem JavaScript) */
    .admin-bell {
      position: relative;
      display: inline-flex;
      align-items: center;
      justify-content: center;
      width: 44px;
      min-height: 44px;
      border-radius: 8px;
      border: 1px solid var(--admin-gray-200);
      color: var(--admin-navy-900);
      background: #fff;
      text-decoration: none;
    }
    .admin-bell:hover { background: var(--botanical-sage-100, #E5F0E5); border-color: var(--admin-navy-900); }
    .admin-bell:focus-visible { outline: 3px solid var(--admin-navy-900); outline-offset: 2px; }
    .admin-bell svg { width: 20px; height: 20px; }
    .admin-bell__count {
      position: absolute;
      top: -6px;
      right: -6px;
      min-width: 20px;
      height: 20px;
      padding: 0 5px;
      border-radius: 9999px;
      background: var(--admin-copper-600);
      color: #fff;
      font-size: 0.7rem;
      font-weight: 700;
      line-height: 20px;
      text-align: center;
      border: 2px solid #fff;
      box-sizing: content-box;
    }
    @media (max-width: 600px) {
      .admin-header__right { gap: 0.6rem; }
    }

    .admin-logout-form { display: inline-flex; margin: 0; }
    .admin-logout-btn:hover {
      background-color: var(--admin-danger-bg);
      border-color: var(--admin-danger);
    }

    /* Content Body & Breadcrumbs */
    .admin-content {
      padding: 1.5rem;
      flex: 1;
    }

    .admin-breadcrumbs {
      display: flex;
      align-items: center;
      gap: 0.5rem;
      font-size: 0.82rem;
      color: var(--admin-gray-500);
      margin-bottom: 1.25rem;
    }
    .admin-breadcrumbs a {
      color: var(--admin-gray-600);
      text-decoration: none;
      transition: color 0.15s;
    }
    .admin-breadcrumbs a:hover {
      color: var(--admin-copper-600);
    }
    .admin-breadcrumbs span {
      color: var(--admin-gray-400);
    }
    .admin-breadcrumbs .current {
      color: var(--admin-gray-900);
      font-weight: 600;
    }

    /* Flash Alerts */
    .admin-alert {
      padding: 0.85rem 1.25rem;
      border-radius: 6px;
      margin-bottom: 1.25rem;
      font-size: 0.88rem;
      display: flex;
      align-items: center;
      justify-content: space-between;
    }
    .admin-alert--success {
      background-color: var(--admin-success-bg);
      color: #0A5F63;
      border: 1px solid #B7DFDC;
    }
    .admin-alert--error {
      background-color: var(--admin-danger-bg);
      color: #7f1d1d;
      border: 1px solid #fecaca;
    }

    /* v54.24: --danger era usado nas telas da Academy sem existir; vira apelido de --error */
    .admin-alert--danger {
      background-color: var(--admin-danger-bg);
      color: #7f1d1d;
      border: 1px solid #fecaca;
    }

    /* Common Card Styles */
    .admin-card {
      background: #fff;
      border-radius: 8px;
      border: 1px solid var(--admin-gray-200);
      box-shadow: 0 1px 3px rgba(0,0,0,0.04);
      margin-bottom: 1.5rem;
      overflow: hidden;
    }
    .admin-card__header {
      padding: 1rem 1.25rem;
      border-bottom: 1px solid var(--admin-gray-200);
      display: flex;
      align-items: center;
      justify-content: space-between;
      background-color: #fafbfc;
    }
    .admin-card__title {
      font-size: 1rem;
      font-weight: 600;
      color: var(--admin-gray-900);
    }
    .admin-card__body {
      padding: 1.25rem;
    }

    /* Data Tables */
    .admin-table-wrapper {
      width: 100%;
      overflow-x: auto;
    }
    .admin-table {
      width: 100%;
      border-collapse: collapse;
      font-size: 0.85rem;
      text-align: left;
    }
    .admin-table th {
      background-color: #f8fafc;
      padding: 0.75rem 1rem;
      font-weight: 600;
      color: var(--admin-gray-600);
      border-bottom: 1px solid var(--admin-gray-200);
      text-transform: uppercase;
      font-size: 0.72rem;
      letter-spacing: 0.04em;
    }
    .admin-table td {
      padding: 0.85rem 1rem;
      border-bottom: 1px solid var(--admin-gray-200);
      color: var(--admin-gray-800);
      vertical-align: middle;
    }
    .admin-table tbody tr:hover {
      background-color: #f8fafc;
    }

    .badge {
      display: inline-flex;
      align-items: center;
      padding: 0.2rem 0.55rem;
      font-size: 0.72rem;
      font-weight: 600;
      border-radius: 9999px;
      text-transform: uppercase;
    }
    .badge--active, .badge--success {
      background-color: var(--admin-success-bg);
      color: #0A5F63;
    }
    .badge--suspended, .badge--danger {
      background-color: var(--admin-danger-bg);
      color: #b91c1c;
    }
    .badge--pending, .badge--warning {
      background-color: var(--admin-warning-bg);
      color: #b45309;
    }
    .badge--info {
      background-color: var(--botanical-sage-100, #E5F0E5);
      color: #0F3E17;
    }

    .btn {
      display: inline-flex;
      align-items: center;
      justify-content: center;
      gap: 0.4rem;
      padding: 0.45rem 0.85rem;
      font-size: 0.82rem;
      font-weight: 500;
      border-radius: 6px;
      cursor: pointer;
      text-decoration: none;
      border: 1px solid transparent;
      transition: all 0.15s;
    }
    .btn--primary {
      background-color: var(--admin-green-600);
      color: #fff;
    }
    .btn--primary:hover {
      background-color: var(--admin-green-700);
    }
    .btn--secondary {
      background-color: #fff;
      border-color: var(--admin-gray-300);
      color: var(--admin-gray-700);
    }
    .btn--secondary:hover {
      background-color: var(--admin-gray-50);
      border-color: var(--admin-gray-400);
    }
    .btn--danger {
      background-color: #fff;
      border-color: rgba(220, 38, 38, 0.3);
      color: var(--admin-danger);
    }
    .btn--danger:hover {
      background-color: var(--admin-danger-bg);
      border-color: var(--admin-danger);
    }
    .btn--sm {
      padding: 0.3rem 0.6rem;
      font-size: 0.75rem;
    }

    /* Responsividade */
    @media (max-width: 900px) {
      .admin-sidebar {
        position: fixed;
        top: 0;
        bottom: 0;
        left: 0;
        transform: translateX(-100%);
      }
      .admin-sidebar.open {
        transform: translateX(0);
      }
      .admin-menu-toggle {
        display: block;
      }
      .admin-overlay {
        display: none;
        position: fixed;
        inset: 0;
        background-color: rgba(0, 0, 0, 0.5);
        z-index: 35;
      }
      .admin-overlay.open {
        display: block;
      }
    }

    /* KPI cards compartilhados (painel, service desk, comercial). Antes só existiam no <style> do dashboard,
       então qualquer outra tela que usasse .kpi-card aparecia como texto empilhado. */
    .kpi-grid {
      display: grid;
      grid-template-columns: repeat(auto-fit, minmax(220px, 1fr));
      gap: 1.25rem;
      margin-bottom: 1.5rem;
    }
    .kpi-card {
      background: #fff;
      padding: 1.25rem;
      border-radius: 8px;
      border: 1px solid var(--admin-gray-200);
      box-shadow: 0 1px 3px rgba(0,0,0,0.03);
      display: flex;
      flex-direction: column;
      justify-content: space-between;
    }
    .kpi-card__header {
      display: flex;
      align-items: center;
      justify-content: space-between;
      margin-bottom: 0.5rem;
    }
    .kpi-card__title {
      font-size: 0.8rem;
      text-transform: uppercase;
      letter-spacing: 0.05em;
      color: var(--admin-gray-500);
      font-weight: 600;
    }
    .kpi-card__icon {
      width: 32px;
      height: 32px;
      border-radius: 6px;
      display: flex;
      align-items: center;
      justify-content: center;
      background-color: var(--admin-copper-50);
      color: var(--admin-copper-600);
    }
    .kpi-card__icon svg {
      width: 18px;
      height: 18px;
    }
    .kpi-card__value {
      font-size: 1.75rem;
      font-weight: 700;
      color: var(--admin-navy-900);
      line-height: 1.2;
      margin-bottom: 0.25rem;
    }
    .kpi-card__subtitle {
      font-size: 0.78rem;
      color: var(--admin-gray-500);
    }

    /* Cartões clicáveis: o link principal cobre o cartão inteiro (sem <a> aninhado);
       links secundários do subtítulo ficam acima dele. */
    .kpi-card--link {
      position: relative;
      transition: border-color 0.15s ease, box-shadow 0.15s ease;
    }
    .kpi-card--link:hover {
      border-color: var(--admin-navy-900);
      box-shadow: 0 2px 8px rgba(15, 62, 23, 0.12);
    }
    .kpi-card__link {
      color: inherit;
      text-decoration: none;
    }
    .kpi-card__link::after {
      content: "";
      position: absolute;
      inset: 0;
      border-radius: 8px;
    }
    .kpi-card__link:focus-visible {
      outline: none;
    }
    .kpi-card__link:focus-visible::after {
      outline: 3px solid var(--admin-navy-900);
      outline-offset: 2px;
    }
    .kpi-card__sublink {
      position: relative;
      z-index: 1;
      color: var(--admin-navy-900);
      text-decoration: underline;
      text-underline-offset: 2px;
    }
    .kpi-card__sublink:hover {
      text-decoration-thickness: 2px;
    }
    .kpi-card__sublink:focus-visible,
    .kpi-rolelink:focus-visible {
      outline: 3px solid var(--admin-navy-900);
      outline-offset: 2px;
      border-radius: 2px;
    }
    .kpi-card__sr {
      position: absolute;
      width: 1px;
      height: 1px;
      overflow: hidden;
      clip: rect(0 0 0 0);
      white-space: nowrap;
    }
    .kpi-rolelink {
      color: var(--admin-navy-900);
      text-decoration: underline;
      text-underline-offset: 2px;
    }
    .kpi-rolelink:hover {
      text-decoration-thickness: 2px;
    }
    /* Grade fixa de 4 cartões: 4 colunas no desktop, 2 no tablet, 1 no celular. */
    .kpi-grid--4 {
      grid-template-columns: repeat(4, minmax(0, 1fr));
    }
    @media (max-width: 1100px) {
      .kpi-grid--4 { grid-template-columns: repeat(2, minmax(0, 1fr)); }
    }
    @media (max-width: 600px) {
      .kpi-grid--4 { grid-template-columns: 1fr; }
    }

    /* v54.24: peças do console da Clyo Academy (antes em style="" nas views).
       Mesmos valores que estavam inline; campos ganham a borda e o foco do DS. */
    .admin-card--pad { padding: 1rem 1.25rem; }
    .admin-card--pad > * + * { margin-top: .75rem; }
    .admin-block { margin-bottom: 1.5rem; }
    .admin-heading { font-size: 1.5rem; font-weight: 700; color: var(--admin-navy-900); }
    .admin-page-title { font-size: 1.25rem; }
    .admin-section-title { font-size: 1.05rem; font-weight: 600; color: var(--admin-gray-900); }
    .admin-hint { font-size: .85rem; color: var(--admin-gray-600); }
    .admin-lead { max-width: 860px; }
    .admin-small { font-size: .85rem; }
    .admin-code { font-size: .75rem; }
    .admin-pre { white-space: pre-line; font-size: .85rem; }
    .admin-pre--box { max-height: 320px; overflow: auto; border: 1px solid var(--admin-gray-200); border-radius: 6px; padding: .75rem; font-size: .88rem; }
    .admin-preview-img { display: block; max-width: 260px; border: 1px solid var(--admin-gray-200); border-radius: 6px; }
    .admin-divided { border-top: 1px solid var(--admin-gray-200); padding: .75rem 0; }
    .admin-dd { margin: 0 0 .5rem; }
    .admin-mt { margin-top: .75rem; }
    .admin-flush { margin: 0; }
    .admin-stack { display: grid; gap: 1rem; }
    .admin-stack--sm { gap: .5rem; }
    .admin-stack > * + *, .admin-card--pad.admin-stack > * + * { margin-top: 0; }
    .admin-actions { display: flex; flex-wrap: wrap; gap: .5rem; align-items: center; }
    .admin-form { display: grid; gap: .75rem; grid-template-columns: repeat(auto-fit, minmax(min(100%, 240px), 1fr)); align-items: end; }
    .admin-form--dense { gap: .5rem; grid-template-columns: repeat(auto-fit, minmax(min(100%, 200px), 1fr)); }
    .admin-form__wide { grid-column: 1 / -1; }
    .admin-inline-form { display: flex; flex-wrap: wrap; gap: .5rem; align-items: flex-end; margin: 0; }
    .admin-inline-form--inline { display: inline-flex; }
    .admin-grow { flex: 1 1 240px; min-width: 0; }
    .admin-field-reason { min-width: min(100%, 280px); }
    .admin-form label, .admin-inline-form label { display: flex; flex-direction: column; gap: .3rem; font-size: .85rem; font-weight: 600; color: var(--admin-gray-700); }
    .admin-form label > br, .admin-inline-form label > br { display: none; }
    .admin-form :is(input:not([type=hidden]):not([type=checkbox]):not([type=radio]):not([type=file]), select, textarea),
    .admin-inline-form :is(input:not([type=hidden]):not([type=checkbox]):not([type=radio]):not([type=file]), select, textarea) {
      min-height: 40px; padding: .5rem .75rem; border: 1px solid var(--admin-border-input); border-radius: 8px;
      background: #fff; color: var(--admin-gray-900); font: inherit; font-size: .9rem; font-weight: 400;
    }
    .admin-form :is(input:not([type=hidden]):not([type=checkbox]):not([type=radio]):not([type=file]), select, textarea) { width: 100%; }
    .admin-form textarea, .admin-inline-form textarea { min-height: 80px; }
    .admin-form input[type=file] { font-size: .85rem; font-weight: 400; }
    .admin-form :is(input, select, textarea):focus-visible, .admin-inline-form :is(input, select, textarea):focus-visible { outline: 2px solid var(--admin-green-600); outline-offset: 2px; border-color: var(--admin-green-600); }
  </style>
  <link rel="stylesheet" href="/assets/css/operational-ui.css?v=v54-63">
</head>
<body>
  <a class="skip-link skip-admin-content" href="#admin-main-content">Pular para o conteúdo principal</a>
  <div class="admin-overlay" id="admin-overlay" data-call="toggleAdminMenu"></div>

  <div class="admin-layout">
    <!-- Sidebar Navegação Administrativa Isolada -->
    <aside class="admin-sidebar" id="admin-sidebar">
      <div class="admin-sidebar__brand">
        <div class="admin-sidebar__logo-badge">CII</div>
        <div class="admin-sidebar__brand-text">
          <h1>Clyo Nutri</h1>
          <span>Backoffice SaaS</span>
        </div>
      </div>

      <nav class="admin-sidebar__nav" aria-label="Navegação administrativa">
        <div class="admin-nav__group-label">Visão geral</div>
        <a href="/admin/dashboard" class="admin-nav__item <?= ($activeNav ?? '') === 'dashboard' ? 'active' : '' ?>">
          <svg viewBox="0 0 24 24" fill="none" stroke="currentColor"><rect width="7" height="9" x="3" y="3" rx="1"/><rect width="7" height="5" x="14" y="3" rx="1"/><rect width="7" height="9" x="14" y="12" rx="1"/><rect width="7" height="5" x="3" y="16" rx="1"/></svg>
          <span>Painel</span>
        </a>
        <?php if (!empty($notificationsBell)): ?>
        <a href="/admin/notificacoes" class="admin-nav__item <?= ($activeNav ?? '') === 'notificacoes' ? 'active' : '' ?>">
          <svg viewBox="0 0 24 24" fill="none" stroke="currentColor"><path d="M18 8A6 6 0 0 0 6 8c0 7-3 9-3 9h18s-3-2-3-9"/><path d="M13.73 21a2 2 0 0 1-3.46 0"/></svg>
          <span>Notificações</span>
          <?php if (!empty($notificationsUnread)): ?>
            <span class="admin-nav__badge"><?= (int)$notificationsUnread ?></span>
          <?php endif; ?>
        </a>
        <?php endif; ?>
        <?php if (\ClyoNutri\Core\Security\PlatformPolicy::can($operatorRole ?? '', 'analytics.read')): ?>
        <a href="/admin/metricas-adesao" class="admin-nav__item <?= ($activeNav ?? '') === 'metricas-adesao' ? 'active' : '' ?>">
          <svg viewBox="0 0 24 24" fill="none" stroke="currentColor"><path d="M3 3v18h18"/><path d="m7 15 4-4 3 3 5-6"/></svg>
          <span>Métricas de adesão</span>
        </a>
        <?php endif; ?>

        <?php if (\ClyoNutri\Core\Security\PlatformPolicy::can($operatorRole ?? '', 'clinics.read') || \ClyoNutri\Core\Security\PlatformPolicy::can($operatorRole ?? '', 'users.read') || \ClyoNutri\Core\Security\PlatformPolicy::can($operatorRole ?? '', 'tickets.read')): ?>
        <div class="admin-nav__group-label">Gestão de Plataforma</div>
        <?php if (\ClyoNutri\Core\Security\PlatformPolicy::can($operatorRole ?? '', 'clinics.read')): ?>
        <a href="/admin/clinicas" class="admin-nav__item <?= ($activeNav ?? '') === 'clinicas' ? 'active' : '' ?>">
          <svg viewBox="0 0 24 24" fill="none" stroke="currentColor"><path d="M3 9l9-7 9 7v11a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2z"/><polyline points="9 22 9 12 15 12 15 22"/></svg>
          <span>Clínicas (tenants)</span>
        </a>
        <?php endif; ?>
        <?php if (\ClyoNutri\Core\Security\PlatformPolicy::can($operatorRole ?? '', 'users.read')): ?>
        <a href="/admin/usuarios" class="admin-nav__item <?= ($activeNav ?? '') === 'usuarios' ? 'active' : '' ?>">
          <svg viewBox="0 0 24 24" fill="none" stroke="currentColor"><path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M22 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/></svg>
          <span>Usuários e perfis</span>
        </a>
        <?php endif; ?>
        <?php if (\ClyoNutri\Core\Security\PlatformPolicy::can($operatorRole ?? '', 'tickets.read')): ?>
        <a href="/admin/servicedesk" class="admin-nav__item <?= ($activeNav ?? '') === 'servicedesk' ? 'active' : '' ?>">
          <svg viewBox="0 0 24 24" fill="none" stroke="currentColor"><path d="M4 4h16c1.1 0 2 .9 2 2v12c0 1.1-.9 2-2 2H4c-1.1 0-2-.9-2-2V6c0-1.1.9-2 2-2z"/><polyline points="22,6 12,13 2,6"/></svg>
          <span>Service desk</span>
          <?php if (!empty($openTicketsCount) && $openTicketsCount > 0): ?>
            <span class="admin-nav__badge"><?= (int)$openTicketsCount ?></span>
          <?php endif; ?>
        </a>
        <?php endif; ?>
        <?php endif; ?>

        <?php if (\ClyoNutri\Core\Security\PlatformPolicy::can($operatorRole ?? '', 'billing.read')): ?>
        <div class="admin-nav__group-label">Governança comercial</div>
        <a href="/admin/comercial" class="admin-nav__item <?= ($activeNav ?? '') === 'comercial' ? 'active' : '' ?>">
          <svg viewBox="0 0 24 24" fill="none" stroke="currentColor"><rect width="20" height="14" x="2" y="5" rx="2"/><line x1="2" x2="22" y1="10" y2="10"/></svg>
          <span>Planos e assinaturas</span>
        </a>
        <?php endif; ?>

        <?php if (\ClyoNutri\Core\Security\PlatformPolicy::can($operatorRole ?? '', 'sessions.read') || \ClyoNutri\Core\Security\PlatformPolicy::can($operatorRole ?? '', 'audit.read') || \ClyoNutri\Core\Security\PlatformPolicy::can($operatorRole ?? '', 'settings.read')): ?>
        <div class="admin-nav__group-label">Segurança e controle</div>
        <?php if (\ClyoNutri\Core\Security\PlatformPolicy::can($operatorRole ?? '', 'sessions.read')): ?>
        <a href="/admin/acessos" class="admin-nav__item <?= ($activeNav ?? '') === 'acessos' ? 'active' : '' ?>">
          <svg viewBox="0 0 24 24" fill="none" stroke="currentColor"><rect width="18" height="11" x="3" y="11" rx="2" ry="2"/><path d="M7 11V7a5 5 0 0 1 10 0v4"/></svg>
          <span>Sessões & RBAC</span>
        </a>
        <?php endif; ?>
        <?php if (\ClyoNutri\Core\Security\PlatformPolicy::can($operatorRole ?? '', 'audit.read')): ?>
        <a href="/admin/auditoria" class="admin-nav__item <?= ($activeNav ?? '') === 'auditoria' ? 'active' : '' ?>">
          <svg viewBox="0 0 24 24" fill="none" stroke="currentColor"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/><line x1="16" y1="13" x2="8" y2="13"/><line x1="16" y1="17" x2="8" y2="17"/></svg>
          <span>Auditoria imutável</span>
        </a>
        <?php endif; ?>
        <?php if (\ClyoNutri\Core\Security\PlatformPolicy::can($operatorRole ?? '', 'events.read')): ?>
        <a href="/admin/eventos" class="admin-nav__item <?= ($activeNav ?? '') === 'eventos' ? 'active' : '' ?>">
          <svg viewBox="0 0 24 24" fill="none" stroke="currentColor"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></svg>
          <span>Central de eventos</span>
        </a>
        <?php endif; ?>
        <?php if (\ClyoNutri\Core\Security\PlatformPolicy::can($operatorRole ?? '', 'settings.read')): ?>
        <a href="/admin/protocolos" class="admin-nav__item <?= ($activeNav ?? '') === 'protocolos' ? 'active' : '' ?>">
          <svg viewBox="0 0 24 24" fill="none" stroke="currentColor"><path d="M9 11l3 3L22 4"/><path d="M21 12v7a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h11"/></svg>
          <span>Protocolos clínicos</span>
        </a>
        <?php endif; ?>
        <?php if (\ClyoNutri\Core\Security\PlatformPolicy::can($operatorRole ?? '', 'settings.read')): ?>
        <a href="/admin/academy" class="admin-nav__item <?= ($activeNav ?? '') === 'academy' ? 'active' : '' ?>">
          <svg viewBox="0 0 24 24" fill="none" stroke="currentColor"><path d="M4 19.5v-15A2.5 2.5 0 0 1 6.5 2H20v20H6.5a2.5 2.5 0 0 1-2.5-2.5Z"/></svg>
          <span>Clyo Academy</span>
        </a>
        <?php endif; ?>
        <?php if (\ClyoNutri\Core\Security\PlatformPolicy::can($operatorRole ?? '', 'settings.read')): ?>
        <a href="/admin/configuracoes" class="admin-nav__item <?= ($activeNav ?? '') === 'configuracoes' ? 'active' : '' ?>">
          <svg viewBox="0 0 24 24" fill="none" stroke="currentColor"><circle cx="12" cy="12" r="3"/><path d="M19.4 15a1.65 1.65 0 0 0 .33 1.82l.06.06a2 2 0 0 1 0 2.83 2 2 0 0 1-2.83 0l-.06-.06a1.65 1.65 0 0 0-1.82-.33 1.65 1.65 0 0 0-1 1.51V21a2 2 0 0 1-2 2 2 2 0 0 1-2-2v-.09A1.65 1.65 0 0 0 9 19.4a1.65 1.65 0 0 0-1.82.33l-.06.06a2 2 0 0 1-2.83 0 2 2 0 0 1 0-2.83l.06-.06a1.65 1.65 0 0 0 .33-1.82 1.65 1.65 0 0 0-1.51-1H3a2 2 0 0 1-2-2 2 2 0 0 1 2-2h.09A1.65 1.65 0 0 0 4.6 9a1.65 1.65 0 0 0-.33-1.82l-.06-.06a2 2 0 0 1 0-2.83 2 2 0 0 1 2.83 0l.06.06a1.65 1.65 0 0 0 1.82.33H9a1.65 1.65 0 0 0 1-1.51V3a2 2 0 0 1 2-2 2 2 0 0 1 2 2v.09a1.65 1.65 0 0 0 1 1.51 1.65 1.65 0 0 0 1.82-.33l.06-.06a2 2 0 0 1 2.83 0 2 2 0 0 1 0 2.83l-.06.06a1.65 1.65 0 0 0-.33 1.82V9a1.65 1.65 0 0 0 1.51 1H21a2 2 0 0 1 2 2 2 2 0 0 1-2 2h-.09a1.65 1.65 0 0 0-1.51 1z"/></svg>
          <span>Configurações globais</span>
        </a>
        <?php endif; ?>
        <?php endif; ?>
      </nav>

      <div class="admin-sidebar__footer">
        <div class="admin-sidebar__env">
          <span class="admin-sidebar__status-dot"></span>
          <span>SaaS produção</span>
        </div>
        <span>v2.0 admin</span>
      </div>
    </aside>

    <!-- Área Principal -->
    <main class="admin-main">
      <header class="admin-header">
        <div class="admin-header__left">
          <button type="button" class="admin-menu-toggle" aria-label="Abrir menu de navegação" data-call="toggleAdminMenu">
            <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="3" y1="12" x2="21" y2="12"/><line x1="3" y1="6" x2="21" y2="6"/><line x1="3" y1="18" x2="21" y2="18"/></svg>
          </button>
          
          <!-- Formulário de Busca Global -->
          <form class="admin-search-form" action="/admin/busca" method="GET" role="search">
            <svg class="admin-search-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/></svg>
            <input type="search" name="q" value="<?= htmlspecialchars($_GET['q'] ?? '') ?>" placeholder="Busca global de clínicas, usuários, e-mails..." aria-label="Busca global">
          </form>
        </div>

        <div class="admin-header__right">
          <?php if (!empty($notificationsBell)):
            $bellCount = (int)($notificationsUnread ?? 0);
            $bellLabel = $bellCount > 0 ? 'Notificações: ' . $bellCount . ($bellCount === 1 ? ' não lida' : ' não lidas') : 'Notificações: nenhuma não lida'; ?>
          <a href="/admin/notificacoes" class="admin-bell" aria-label="<?= htmlspecialchars($bellLabel, ENT_QUOTES, 'UTF-8') ?>" title="<?= htmlspecialchars($bellLabel, ENT_QUOTES, 'UTF-8') ?>" data-testid="admin-bell">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false"><path d="M18 8A6 6 0 0 0 6 8c0 7-3 9-3 9h18s-3-2-3-9"/><path d="M13.73 21a2 2 0 0 1-3.46 0"/></svg>
            <?php if ($bellCount > 0): ?><span class="admin-bell__count" aria-hidden="true"><?= $bellCount > 99 ? '99+' : $bellCount ?></span><?php endif; ?>
          </a>
          <?php endif; ?>
          <div class="admin-operator-badge">
            <div class="admin-operator-avatar" title="<?= htmlspecialchars($operatorName ?? 'Operador') ?>">
              <?= strtoupper(substr($operatorName ?? 'OP', 0, 2)) ?>
            </div>
            <div class="admin-operator-info">
              <div class="admin-operator-name"><?= htmlspecialchars($operatorName ?? 'Operador') ?></div>
              <div class="admin-operator-role"><?= htmlspecialchars($operatorRole ?? 'superadmin') ?></div>
            </div>
          </div>

          <form method="POST" action="/logout" class="admin-logout-form" data-testid="logout-form">
            <input type="hidden" name="_csrf_token" value="<?= htmlspecialchars(\ClyoNutri\Core\Security\Csrf::token(), ENT_QUOTES, 'UTF-8') ?>">
            <button type="submit" class="admin-logout-btn" title="Encerrar sessão com segurança">
              <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true" focusable="false"><path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"/><polyline points="16 17 21 12 16 7"/><line x1="21" y1="12" x2="9" y2="12"/></svg>
              <span>Sair</span>
            </button>
          </form>
        </div>
      </header>

      <section class="admin-content" id="admin-main-content" tabindex="-1">
        <?php if (!empty($breadcrumbs)): ?>
          <nav class="admin-breadcrumbs" aria-label="Trilha de navegação">
            <a href="/admin/dashboard">Admin</a>
            <?php foreach ($breadcrumbs as $crumb): ?>
              <span>/</span>
              <?php if (!empty($crumb['url'])): ?>
                <a href="<?= htmlspecialchars($crumb['url']) ?>"><?= htmlspecialchars($crumb['label']) ?></a>
              <?php else: ?>
                <span class="current"><?= htmlspecialchars($crumb['label']) ?></span>
              <?php endif; ?>
            <?php endforeach; ?>
          </nav>
        <?php endif; ?>

        <?php if (!empty($_SESSION['flash_success'])): ?>
          <div class="op-message op-message--error admin-alert admin-alert--success" role="alert">
            <span><?= htmlspecialchars($_SESSION['flash_success']) ?></span>
            <button type="button" data-remove-parent style="background:none;border:none;cursor:pointer;font-weight:700;">&times;</button>
          </div>
          <?php unset($_SESSION['flash_success']); ?>
        <?php endif; ?>

        <?php if (!empty($_SESSION['flash_error'])): ?>
          <div class="op-message op-message--error admin-alert admin-alert--error" role="alert">
            <span><?= htmlspecialchars($_SESSION['flash_error']) ?></span>
            <button type="button" data-remove-parent style="background:none;border:none;cursor:pointer;font-weight:700;">&times;</button>
          </div>
          <?php unset($_SESSION['flash_error']); ?>
        <?php endif; ?>

        <?= $content ?? '' ?>
      </section>
    </main>
  </div>

  <script nonce="<?= \ClyoNutri\Core\Http\SecurityHeaders::nonce() ?>">
    function toggleAdminMenu() {
      const sidebar = document.getElementById('admin-sidebar');
      const overlay = document.getElementById('admin-overlay');
      sidebar.classList.toggle('open');
      overlay.classList.toggle('open');
    }
  </script>
<script src="/assets/js/operational-ui.js?v=v54-64" defer></script>
</body>
</html>
