<!DOCTYPE html>
<html lang="pt-BR">
<head>
  <link rel="icon" type="image/svg+xml" href="/assets/clyonutri-icon.svg?v=botanical-v54-9">
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Criar nova senha · Clyo Nutri</title>
  <link rel="preload" href="/assets/fonts/inter-normal-latin.woff2" as="font" type="font/woff2" crossorigin>
  <link rel="stylesheet" href="/assets/css/fonts.css?v=v54-66">
  <link rel="stylesheet" href="/assets/css/clinical-botanical.css?v=botanical-v54-9">
  <style>
    .auth-wrapper {
      min-height: 100vh;
      display: flex;
      align-items: center;
      justify-content: center;
      padding: var(--space-6);
      background: linear-gradient(135deg, var(--botanical-cream-50, #FAF9F4) 0%, var(--botanical-cream-50, #FAF9F4) 100%);
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
    .auth-subtitle {
      text-align: center;
      color: var(--neutral-gray-500);
      font-size: 0.95rem;
      margin-bottom: var(--space-6);
      line-height: 1.5;
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
    .token-expired-card {
      background: var(--semantic-warning-light, #FEF3C7);
      border: 1px solid var(--semantic-warning-border, #FEDF89);
      border-radius: var(--radius-input);
      padding: var(--space-5);
      margin-bottom: var(--space-6);
      text-align: center;
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
    .password-toggle { position: absolute; right: 6px; top: 50%; transform: translateY(-50%); min-height: 36px; min-width: 72px; border: 1px solid var(--border-input, #828B9B); background: #fff; border-radius: 6px; font-size: 0.8rem; font-weight: 600; cursor: pointer; color: var(--brand-navy-900, #0F3E17); }
    .password-toggle:focus-visible { outline: 3px solid var(--brand-copper-600, #B04A1B); outline-offset: 2px; }
    .sr-only { position: absolute; width: 1px; height: 1px; padding: 0; margin: -1px; overflow: hidden; clip: rect(0,0,0,0); white-space: nowrap; border: 0; }
  </style>
<link rel="stylesheet" href="/assets/css/operational-ui.css?v=v54-66">
</head>
<body>
  <div class="auth-wrapper">
    <div class="auth-card">
      <div class="auth-logo">
        <img src="/assets/clyonutri-logo-light.svg?v=botanical-v54-9" alt="Clyo Nutri" width="260" height="64" style="display: block; width: 100%; max-width: 260px; height: auto;">
      </div>

      <h1 style="font-size: 1.35rem; font-weight: 700; text-align: center; color: var(--brand-navy-900); margin: 0 0 var(--space-2) 0;">
        Criar nova senha
      </h1>
      <p class="auth-subtitle">
        Defina sua nova credencial de acesso ao consultório Clyo Nutri.
      </p>

      <?php if (!empty($error)): ?>
        <div class="alert-error">
          <?= htmlspecialchars($error) ?>
        </div>
      <?php endif; ?>

      <?php if ($isValidToken): ?>
        <form method="POST" action="/recuperar-senha/<?= htmlspecialchars($token) ?>">
          <input type="hidden" name="_csrf_token" value="<?= htmlspecialchars($csrfToken) ?>">
          <input type="hidden" name="token" value="<?= htmlspecialchars($token) ?>">

          <div class="form-group">
            <label class="form-label" for="password">Nova senha de acesso</label>
            <input type="password" id="password" name="password" class="form-control" placeholder="••••••••••••" required minlength="<?= (int)($tokenData['password_min_length'] ?? 8) ?>" autocomplete="new-password" data-password-toggle autofocus>
            <div style="font-size: 0.8rem; color: var(--neutral-gray-500); margin-top: 4px;">
              <?php if ((int)($tokenData['password_min_length'] ?? 8) > 8): ?>
              Mínimo de <?= (int)$tokenData['password_min_length'] ?> caracteres, com letras e também números ou símbolos. Contas da equipe Clyo Nutri usam uma senha mais forte.
              <?php else: ?>
              Mínimo de 8 caracteres. Recomendamos combinar letras, números e símbolos.
              <?php endif; ?>
            </div>
          </div>

          <div class="form-group" style="margin-top: var(--space-4);">
            <label class="form-label" for="password_confirmation">Confirmar nova senha</label>
            <input type="password" id="password_confirmation" name="password_confirmation" class="form-control" placeholder="••••••••••••" required minlength="<?= (int)($tokenData['password_min_length'] ?? 8) ?>" autocomplete="new-password" data-password-toggle>
          </div>

          <button type="submit" class="btn btn-primary" style="width: 100%; margin-top: var(--space-6);">
            <span>Redefinir senha e acessar</span>
            <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/></svg>
          </button>
        </form>
      <?php elseif (!empty($linkBlocked)): ?>
        <a href="/recuperar-senha" class="btn btn-secondary" data-testid="reset-blocked" style="display: inline-block; width: 100%; text-decoration: none; text-align: center;">
          Pedir um novo link de recuperação
        </a>
      <?php else: ?>
        <div class="token-expired-card" data-testid="reset-invalid">
          <div style="font-size: 1.8rem; margin-bottom: var(--space-2); color: var(--botanical-forest-900);"><?= \ClyoNutri\Application\View\Icon::svg('hourglass', 28) ?></div>
          <h2 style="font-size: 1.05rem; font-weight: 700; color: var(--semantic-warning-strong, #7A4A00); margin: 0 0 var(--space-2) 0;">
            Link inválido ou expirado
          </h2>
          <p style="font-size: 0.875rem; color: var(--semantic-warning-strong, #7A4A00); line-height: 1.5; margin: 0 0 var(--space-4) 0;">
            Este link de recuperação já foi utilizado ou ultrapassou a validade limite de 30 minutos.
          </p>
          <a href="/recuperar-senha" class="btn btn-primary" style="display: inline-block; width: 100%; text-decoration: none; text-align: center;">
            Solicitar novo link de recuperação
          </a>
        </div>
      <?php endif; ?>

      <div class="auth-footer" style="margin-top: var(--space-6); padding-top: var(--space-4); border-top: var(--border-default);">
        <div>
          Lembrou sua credencial? <a href="/login">Voltar ao Login</a>
        </div>
        <div style="margin-top: var(--space-3); font-size: 0.8rem; color: var(--neutral-gray-400);">
          Dúvidas sobre sua conta? Contate <a href="mailto:<?= htmlspecialchars(\ClyoNutri\Core\Security\ContactInfo::supportEmail()) ?>" style="color: var(--neutral-gray-500); text-decoration: underline;"><?= htmlspecialchars(\ClyoNutri\Core\Security\ContactInfo::supportEmail()) ?></a>
        </div>
      </div>
    </div>
  </div>
  <script src="/assets/js/password-toggle.js" defer></script>
</body>
</html>
