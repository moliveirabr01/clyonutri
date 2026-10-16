<!DOCTYPE html>
<html lang="pt-BR">
<head>
  <link rel="icon" type="image/svg+xml" href="/assets/clyonutri-icon.svg">
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Criar Nova Senha — ClyoNutri</title>
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&family=Playfair+Display:wght@500;600&display=swap" rel="stylesheet">
  <link rel="stylesheet" href="/assets/css/clinical-botanical.css">
  <style>
    .auth-wrapper {
      min-height: 100vh;
      display: flex;
      align-items: center;
      justify-content: center;
      padding: var(--space-6);
      background: linear-gradient(135deg, #F8F4ED 0%, #F8F4ED 100%);
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
      background: #FFFBEB;
      border: 1px solid #FCD34D;
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
    .password-toggle { position: absolute; right: 6px; top: 50%; transform: translateY(-50%); min-height: 36px; min-width: 72px; border: 1px solid var(--neutral-gray-300, #CBD5E1); background: #fff; border-radius: 6px; font-size: 0.8rem; font-weight: 600; cursor: pointer; color: var(--brand-navy-900, #0F2747); }
    .password-toggle:focus-visible { outline: 3px solid var(--brand-copper-600, #A3532A); outline-offset: 2px; }
    .sr-only { position: absolute; width: 1px; height: 1px; padding: 0; margin: -1px; overflow: hidden; clip: rect(0,0,0,0); white-space: nowrap; border: 0; }
  </style>
</head>
<body>
  <div class="auth-wrapper">
    <div class="auth-card">
      <div class="auth-logo">
        <img src="/assets/clyonutri-logo-light.svg" alt="Clyo Nutri — Nutrição que transforma" width="260" height="64" style="display: block; width: 100%; max-width: 260px; height: auto;">
      </div>

      <h1 style="font-size: 1.35rem; font-weight: 700; text-align: center; color: var(--brand-navy-900); margin: 0 0 var(--space-2) 0;">
        Criar Nova Senha
      </h1>
      <p class="auth-subtitle">
        Defina sua nova credencial de acesso ao consultório ClyoNutri.
      </p>

      <?php if (!empty($error)): ?>
        <div class="alert-error">
          ⚠️ <?= htmlspecialchars($error) ?>
        </div>
      <?php endif; ?>

      <?php if ($isValidToken): ?>
        <form method="POST" action="/recuperar-senha/<?= htmlspecialchars($token) ?>">
          <input type="hidden" name="_csrf_token" value="<?= htmlspecialchars($csrfToken) ?>">
          <input type="hidden" name="token" value="<?= htmlspecialchars($token) ?>">

          <div class="form-group">
            <label class="form-label" for="password">Nova senha de acesso</label>
            <input type="password" id="password" name="password" class="form-control" placeholder="••••••••••••" required minlength="8" autocomplete="new-password" data-password-toggle autofocus>
            <div style="font-size: 0.8rem; color: var(--neutral-gray-500); margin-top: 4px;">
              Mínimo de 8 caracteres. Recomendamos combinar letras, números e símbolos.
            </div>
          </div>

          <div class="form-group" style="margin-top: var(--space-4);">
            <label class="form-label" for="password_confirmation">Confirmar nova senha</label>
            <input type="password" id="password_confirmation" name="password_confirmation" class="form-control" placeholder="••••••••••••" required minlength="8" autocomplete="new-password" data-password-toggle>
          </div>

          <button type="submit" class="btn btn-primary" style="width: 100%; margin-top: var(--space-6);">
            <span>Redefinir Senha & Acessar</span>
            <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/></svg>
          </button>
        </form>
      <?php else: ?>
        <div class="token-expired-card">
          <div style="font-size: 1.8rem; margin-bottom: var(--space-2);">⏳</div>
          <h2 style="font-size: 1.05rem; font-weight: 700; color: #92400E; margin: 0 0 var(--space-2) 0;">
            Link Inválido ou Expirado
          </h2>
          <p style="font-size: 0.875rem; color: #78350F; line-height: 1.5; margin: 0 0 var(--space-4) 0;">
            Este link de recuperação já foi utilizado ou ultrapassou a validade limite de 30 minutos.
          </p>
          <a href="/recuperar-senha" class="btn btn-primary" style="display: inline-block; width: 100%; text-decoration: none; text-align: center;">
            Solicitar Novo Link de Recuperação
          </a>
        </div>
      <?php endif; ?>

      <div class="auth-footer" style="margin-top: var(--space-6); padding-top: var(--space-4); border-top: var(--border-default);">
        <div>
          Lembrou sua credencial? <a href="/login">Voltar ao Login</a>
        </div>
        <div style="margin-top: var(--space-3); font-size: 0.8rem; color: var(--neutral-gray-400);">
          Dúvidas sobre sua conta? Contate <a href="mailto:<?= htmlspecialchars((string)(\ClyoNutri\Core\Security\Env::get('APP_SUPPORT_EMAIL') ?: 'suporte@clyonutri.com.br')) ?>" style="color: var(--neutral-gray-500); text-decoration: underline;"><?= htmlspecialchars((string)(\ClyoNutri\Core\Security\Env::get('APP_SUPPORT_EMAIL') ?: 'suporte@clyonutri.com.br')) ?></a>
        </div>
      </div>
    </div>
  </div>
  <script src="/assets/js/password-toggle.js" defer></script>
</body>
</html>
