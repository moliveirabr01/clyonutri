<?php
/**
 * ClyoNutri — Tela de Erro / Indisponibilidade da Teleconsulta (Design System Clinical Botanical)
 *
 * @var string $title
 * @var string $message
 * @var string $backUrl
 * @var string $backLabel
 * @var string|null $clinicName
 * @var string|null $tone
 * @var bool|null $isPatient
 */
$e = static fn($v) => htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
$title = $title ?? 'Não foi possível abrir a teleconsulta';
$message = $message ?? 'A teleconsulta solicitada não está disponível no momento.';
$backUrl = $backUrl ?? '/agenda';
$backLabel = $backLabel ?? 'Voltar para a agenda';
$tone = $tone ?? 'warn';
$brandName = !empty($clinicName) ? ('Teleconsulta · ' . $clinicName) : 'Teleconsulta · ClyoNutri';
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <meta name="robots" content="noindex, nofollow">
  <title><?= $e($title) ?> · ClyoNutri</title>
  <link rel="stylesheet" href="/assets/css/fonts.css?v=v54-66">
  <link rel="stylesheet" href="/assets/css/clinical-botanical.css?v=botanical-v54-9">
  <link rel="stylesheet" href="/assets/css/teleconsulta.css?v=v54-27">
  <link rel="stylesheet" href="/assets/css/operational-ui.css?v=v54-66">
</head>
<body class="tc-page">
  <?php require __DIR__ . '/partials/icons.php'; ?>
  <header class="tc-header">
    <h1 class="tc-brand"><?= $e($brandName) ?></h1>
  </header>

  <main class="tc-narrow">
    <div class="tc-card">
      <h1><?= $e($title) ?></h1>

      <div class="tc-alert" data-tone="<?= $e($tone) ?>" role="alert">
        <svg class="tc-icon" aria-hidden="true"><use href="#i-alert"/></svg>
        <div>
          <p><?= $e($message) ?></p>
        </div>
      </div>

      <div class="tc-controls">
        <a class="tc-btn tc-btn-primary" href="<?= $e($backUrl) ?>"><?= $e($backLabel) ?></a>
        <?php if (empty($isPatient)): ?>
          <a class="tc-btn" href="/agenda">Ver agenda completa</a>
        <?php endif; ?>
      </div>
    </div>
  </main>
</body>
</html>
