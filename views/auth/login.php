<!DOCTYPE html>
<html lang="pt-BR">
<head>
  <link rel="icon" type="image/svg+xml" href="/assets/clyonutri-icon.svg">
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Entrar — ClyoNutri</title>
  <link rel="preload" href="/assets/fonts/inter-normal-latin.woff2" as="font" type="font/woff2" crossorigin>
  <link rel="stylesheet" href="/assets/css/fonts.css">
  <link rel="stylesheet" href="/assets/css/clinical-botanical.css">
  <style>
    .auth-wrapper {
      min-height: 100vh;
      display: flex;
      align-items: center;
      justify-content: center;
      padding: var(--space-6);
      background: linear-gradient(135deg, #FAF9F4 0%, #FAF9F4 100%);
    }
    .auth-card {
      width: 100%;
      max-width: 440px;
      background: var(--neutral-white);
      border-radius: var(--radius-card);
      border: var(--border-default);
      box-shadow: var(--shadow-md);
      padding: var(--space-8);
    }
    .auth-logo {
      display: flex;
      align-items: center;
      justify-content: center;
      gap: var(--space-2);
      margin-bottom: var(--space-6);
      font-size: 1.6rem;
      font-weight: 700;
      color: var(--brand-navy-900);
    }
    .auth-logo span {
      color: var(--brand-copper-500);
    }
    .auth-subtitle {
      text-align: center;
      color: var(--neutral-gray-500);
      font-size: 0.95rem;
      margin-bottom: var(--space-6);
    }
    .alert-error {
      background-color: var(--semantic-error-light);
      color: var(--semantic-error);
      padding: var(--space-3) var(--space-4);
      border-radius: var(--radius-input);
      font-size: 0.9rem;
      margin-bottom: var(--space-5);
      border: 1px solid rgba(180, 35, 24, 0.2);
    }
    .alert-success {
      background-color: var(--semantic-success-light, #E3F4F2);
      color: var(--semantic-success, #0A7075);
      padding: var(--space-3) var(--space-4);
      border-radius: var(--radius-input);
      font-size: 0.9rem;
      margin-bottom: var(--space-5);
      border: 1px solid rgba(10, 112, 117, 0.2);
    }
    .auth-footer {
      margin-top: var(--space-6);
      text-align: center;
      font-size: 0.875rem;
      color: var(--neutral-gray-500);
    }
    .auth-footer a {
      color: var(--brand-copper-600);
      text-decoration: none;
      font-weight: 600;
    }
    .auth-footer a:hover {
      text-decoration: underline;
    }
  
    .password-field { position: relative; }
    .password-field input { padding-right: 88px; }
    .password-toggle { position: absolute; right: 6px; top: 50%; transform: translateY(-50%); min-height: 36px; min-width: 72px; border: 1px solid var(--neutral-gray-300, #CBD5E1); background: #fff; border-radius: 6px; font-size: 0.8rem; font-weight: 600; cursor: pointer; color: var(--brand-navy-900, #0F3E17); }
    .password-toggle:focus-visible { outline: 3px solid var(--brand-copper-600, #B04A1B); outline-offset: 2px; }
    .sr-only { position: absolute; width: 1px; height: 1px; padding: 0; margin: -1px; overflow: hidden; clip: rect(0,0,0,0); white-space: nowrap; border: 0; }
  </style>
</head>
<body>
  <div class="auth-wrapper">
    <div class="auth-card">
      <div class="auth-logo">
        <img src="/assets/clyonutri-logo-light.svg" alt="Clyo Nutri — Nutrição que transforma" width="260" height="64" style="display: block; width: 100%; max-width: 260px; height: auto;">
      </div>

      <p class="auth-subtitle">Acesse o sistema operacional do seu consultório</p>

      <?php if (!empty($error)): ?>
        <div class="alert-error"><p role="alert" style="margin: 0;">⚠️ <?= htmlspecialchars($error) ?><?php if (str_starts_with((string)$error, 'Muitas tentativas')): ?> Se não lembrar a senha, use <a href="/recuperar-senha">Esqueci minha senha</a>.<?php endif; ?></p></div>
      <?php endif; ?>

      <?php if (!empty($success)): ?>
        <div class="alert-success">
          ✓ <?= htmlspecialchars($success) ?>
        </div>
      <?php endif; ?>

      <form method="POST" action="/login">
        <input type="hidden" name="_csrf_token" value="<?= htmlspecialchars($csrfToken) ?>">

        <div class="form-group">
          <label class="form-label" for="email">E-mail profissional</label>
          <input type="email" id="email" name="email" class="form-control" placeholder="seu.email@exemplo.com.br" autocomplete="username" inputmode="email" autocapitalize="none" spellcheck="false" required autofocus value="<?= htmlspecialchars($oldEmail ?? '') ?>">
        </div>

        <div class="form-group">
          <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: var(--space-2);">
            <label class="form-label" for="password" style="margin-bottom: 0;">Senha de acesso</label>
            <a href="/recuperar-senha" style="font-size: 0.8rem; color: var(--brand-copper-600); text-decoration: none; font-weight: 500;">Esqueci minha senha</a>
          </div>
          <input type="password" id="password" name="password" class="form-control" placeholder="••••••••••••" autocomplete="current-password" data-password-toggle required>
        </div>

        <button type="submit" class="btn btn-primary" style="width: 100%; margin-top: var(--space-4);">
          <span>Entrar no Consultório</span>
          <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="5" y1="12" x2="19" y2="12"/><polyline points="12 5 19 12 12 19"/></svg>
        </button>
      </form>

      <div class="auth-footer" style="margin-top: var(--space-6); padding-top: var(--space-4); border-top: var(--border-default);">
        <div>Ainda não tem conta? <a href="/cadastro">Cadastre-se</a></div>
        <div style="margin-top: var(--space-3); font-size: 0.8rem; color: var(--neutral-gray-400);">
          Precisa de suporte? <a href="mailto:<?= htmlspecialchars((string)(\ClyoNutri\Core\Security\Env::get('APP_SUPPORT_EMAIL') ?: 'suporte@clyonutri.com.br')) ?>" style="color: var(--neutral-gray-500); text-decoration: underline;"><?= htmlspecialchars((string)(\ClyoNutri\Core\Security\Env::get('APP_SUPPORT_EMAIL') ?: 'suporte@clyonutri.com.br')) ?></a>
        </div>
      </div>
    </div>
  </div>
  <script src="/assets/js/password-toggle.js" defer></script>
</body>
</html>
