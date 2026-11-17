<?php
/** v54.68 / N-03: exibição única dos códigos de recuperação do MFA. */
/** @var list<string> $codes */
/** @var string $csrfToken */
/** @var ?string $error */
$e = static fn($v): string => htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <meta name="robots" content="noindex">
  <title>Códigos de recuperação · Clyo Nutri</title>
  <link rel="stylesheet" href="/assets/css/fonts.css?v=v54-68">
  <link rel="stylesheet" href="/assets/css/clinical-botanical.css?v=botanical-v54-9">
  <link rel="stylesheet" href="/assets/css/operational-ui.css?v=v54-68">
</head>
<body class="u-bg-botanical-cream-50">
  <main class="u-maxw-form u-mx-auto u-my-12 u-p-6 card" data-testid="mfa-recovery-card">
    <h1 class="u-text-h2 u-font-bold u-color-botanical-forest-900 u-mt-0">Guarde seus códigos de recuperação</h1>
    <?php if ($error): ?>
      <div class="op-message op-message--error u-mb-4" role="alert" data-testid="mfa-recovery-error"><?= $e($error) ?></div>
    <?php endif; ?>
    <p class="u-text-compact u-color-neutral-gray-700">
      Se perder o celular com o autenticador, use um destes códigos no lugar do código de 6 dígitos. Cada código funciona uma vez.
      <strong>Eles não serão mostrados de novo.</strong>
    </p>
    <ol class="u-p-3 u-pl-8 u-bg-neutral-gray-50 u-border u-radius-input u-mb-4" data-testid="mfa-recovery-codes">
      <?php foreach ($codes as $c): ?>
        <li><code class="u-font-bold"><?= $e($c) ?></code></li>
      <?php endforeach; ?>
    </ol>
    <p class="u-text-caption u-color-neutral-gray-500 u-mb-4">Guarde num gerenciador de senhas ou imprima e mantenha em local seguro. Não envie por e-mail ou mensagem.</p>
    <form method="POST" action="/login/mfa/codigos" data-testid="mfa-recovery-form">
      <input type="hidden" name="_csrf_token" value="<?= $e($csrfToken) ?>">
      <label class="u-flex u-items-center u-gap-2 u-mb-4">
        <input type="checkbox" name="saved" value="1" required data-testid="mfa-recovery-saved">
        <span>Guardei os códigos em local seguro</span>
      </label>
      <div class="u-flex u-justify-end u-items-center">
        <button type="submit" class="btn btn-primary" data-testid="mfa-recovery-continue">Continuar</button>
      </div>
    </form>
  </main>
</body>
</html>
