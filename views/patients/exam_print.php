<?php
/**
 * Pedido de exames para impressão: página própria (sem menu/barra lateral), A4, sem script inline (CSP).
 * O botão usa #pi-print + /assets/js/prontuario-impressao.js, como o plano alimentar e o prontuário.
 * @var array $doc @var array $request @var array $patient
 */
$e = static fn($v) => htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
$pid = (int)$patient['id'];
$rid = (int)$request['id'];
?><!DOCTYPE html>
<html lang="pt-BR">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <meta name="robots" content="noindex">
  <title>Solicitação de exames <?= $e($doc['request_number']) ?> — <?= $e($doc['patient']['name']) ?></title>
  <link rel="stylesheet" href="/assets/css/prontuario-impressao.css?v=botanical-v54-9">
  <link rel="stylesheet" href="/assets/css/exame-documento.css?v=botanical-20261029a">
<link rel="stylesheet" href="/assets/css/operational-ui.css?v=v54-66">
</head>
<body class="exd-print">
<div class="pi-toolbar" role="region" aria-label="Opções de impressão">
  <div class="pi-actions">
    <a class="pi-btn" href="/pacientes/<?= $pid ?>/exames/<?= $rid ?>">Voltar à solicitação</a>
    <?php if ($request['status'] === 'issued' && !empty($request['pdf_path'])): ?>
      <a class="pi-btn" href="/pacientes/<?= $pid ?>/exames/<?= $rid ?>/pdf">Baixar PDF</a>
    <?php endif; ?>
    <button type="button" class="pi-btn pi-btn-primary" id="pi-print">Imprimir</button>
  </div>
</div>
<main class="pi-page">
<?php require __DIR__ . '/partials/exam_document.php'; ?>
</main>
<script src="/assets/js/prontuario-impressao.js?v=botanical-v54-9" defer></script>
</body>
</html>
