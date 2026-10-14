<!DOCTYPE html>
<html lang="pt-BR">
<head>
  <link rel="icon" type="image/svg+xml" href="/assets/clyonutri-icon.svg">
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Recuperar Acesso — ClyoNutri</title>
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
    .alert-success {
      background-color: var(--semantic-success-light, #E8F5E9);
      color: var(--semantic-success, #18794E);
      padding: var(--space-3) var(--space-4);
      border-radius: var(--radius-input);
      font-size: 0.9rem;
      margin-bottom: var(--space-5);
      border: 1px solid rgba(24, 121, 78, 0.2);
      line-height: 1.5;
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
  </style>
</head>
<body>
  <div class="auth-wrapper">
    <div class="auth-card">
      <div class="auth-logo">
        <img src="/assets/clyonutri-logo-light.svg" alt="Clyo Nutri — Nutrição que transforma" width="260" height="64" style="display: block; width: 100%; max-width: 260px; height: auto;">
      </div>

      <h1 style="font-size: 1.35rem; font-weight: 700; text-align: center; color: var(--brand-navy-900); margin: 0 0 var(--space-2) 0;">
        Recuperar Acesso
      </h1>
      <p class="auth-subtitle">
        Informe seu e-mail profissional cadastrado para receber as instruções e o link seguro de redefinição de senha.
      </p>

      <?php if (!empty($error)): ?>
        <div class="alert-error">
          ⚠️ <?= htmlspecialchars($error) ?>
        </div>
      <?php endif; ?>

      <?php if (!empty($success)): ?>
        <div class="alert-success">
          ✓ <?= htmlspecialchars($success) ?>
        </div>
      <?php endif; ?>

      <form method="POST" action="/recuperar-senha">
        <input type="hidden" name="_csrf_token" value="<?= htmlspecialchars($csrfToken) ?>">

        <div class="form-group">
          <label class="form-label" for="email">E-mail profissional cadastrado</label>
          <input type="email" id="email" name="email" class="form-control" placeholder="seu.email@exemplo.com.br" required autofocus value="<?= htmlspecialchars($oldEmail ?? '') ?>">
          <div style="font-size: 0.8rem; color: var(--neutral-gray-500); margin-top: 4px;">
            O link de redefinição possui validade de 30 minutos a partir do envio.
          </div>
        </div>

        <button type="submit" class="btn btn-primary" style="width: 100%; margin-top: var(--space-4);">
          <span>Enviar Instruções de Recuperação</span>
          <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="22" y1="2" x2="11" y2="13"/><polygon points="22 2 15 22 11 13 2 9 22 2"/></svg>
        </button>
      </form>

      <div class="auth-footer" style="margin-top: var(--space-6); padding-top: var(--space-4); border-top: var(--border-default);">
        <div>
          Lembrou sua senha? <a href="/login">Voltar ao Login</a>
        </div>
        <div style="margin-top: var(--space-3); font-size: 0.8rem; color: var(--neutral-gray-400);">
          Precisa de suporte com seu acesso? Fale com <a href="mailto:<?= htmlspecialchars((string)(\ClyoNutri\Core\Security\Env::get('APP_SUPPORT_EMAIL') ?: 'suporte@clyonutri.com.br')) ?>" style="color: var(--neutral-gray-500); text-decoration: underline;"><?= htmlspecialchars((string)(\ClyoNutri\Core\Security\Env::get('APP_SUPPORT_EMAIL') ?: 'suporte@clyonutri.com.br')) ?></a>
        </div>
      </div>
    </div>
  </div>
</body>
</html>
