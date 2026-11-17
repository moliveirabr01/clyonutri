<?php
/** @var array $pending */
/** @var string $csrfToken */
/** @var ?string $error */
$e = static fn($v): string => htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
$needsSetup = !empty($pending['needs_setup']);
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Verificação em duas etapas (MFA) · Clyo Nutri</title>
  <link rel="stylesheet" href="/assets/css/fonts.css?v=v54-66">
  <link rel="stylesheet" href="/assets/css/clinical-botanical.css?v=botanical-v54-9">
  <link rel="stylesheet" href="/assets/css/operational-ui.css?v=v54-66">
</head>
<body class="u-bg-botanical-cream-50">
  <main class="u-maxw-form u-mx-auto u-my-12 u-p-6 card" data-testid="mfa-challenge-card">
    <h1 class="u-text-h2 u-font-bold u-color-botanical-forest-900 u-mt-0">
      <?= $needsSetup ? 'Configurar autenticação em duas etapas (MFA)' : 'Verificação em duas etapas (MFA)' ?>
    </h1>
    <?php if ($error): ?>
      <div class="op-message op-message--error u-mb-4" role="alert" data-testid="mfa-error"><?= $e($error) ?></div>
    <?php endif; ?>

    <?php if ($needsSetup): ?>
      <p class="u-text-compact u-color-neutral-gray-700">
        Seu papel de plataforma exige autenticação em duas etapas (TOTP). Cadastre a chave abaixo no seu aplicativo autenticador (Google Authenticator, 1Password, Authy) e informe o código de 6 dígitos.
      </p>
      <div class="u-p-3 u-bg-neutral-gray-50 u-border u-radius-input u-mb-4">
        <div class="u-text-caption u-color-neutral-gray-500">Chave secreta (Base32)</div>
        <code class="u-font-bold" data-testid="mfa-setup-secret"><?= $e($pending['setup_secret'] ?? '') ?></code>
      </div>
    <?php else: ?>
      <p class="u-text-compact u-color-neutral-gray-700">
        Informe o código de 6 dígitos do seu autenticador ou um código de recuperação para continuar.
      </p>
    <?php endif; ?>

    <form method="POST" action="/login/mfa" data-testid="mfa-form">
      <input type="hidden" name="_csrf_token" value="<?= $e($csrfToken) ?>">
      <div class="u-mb-4">
        <label for="mfa-code" class="u-block u-font-semibold u-text-compact u-mb-1">Código TOTP ou de recuperação *</label>
        <input id="mfa-code" type="text" name="code" required autofocus autocomplete="one-time-code" placeholder="000000" class="form-control u-w-full" data-testid="mfa-code-input">
      </div>
      <div class="u-flex u-justify-between u-items-center">
        <a href="/login" class="btn btn-outline">Voltar ao login</a>
        <button type="submit" class="btn btn-primary" data-testid="mfa-submit">Confirmar</button>
      </div>
    </form>
  </main>
</body>
</html>
