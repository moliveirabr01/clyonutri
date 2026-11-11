<?php
/** @var array $session @var string $csrf @var array $term @var array $clock */
$e = static fn($v) => htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
$clock = $clock ?? [];
$isOpen = !empty($clock['is_open']);
$opensLabel = (string)($clock['opens_label'] ?? '');
$opensTs = !empty($clock['join_opens_at_utc']) ? strtotime((string)$clock['join_opens_at_utc']) : false;
// Se a abertura não for hoje, mostra também o dia (evita "abre às 23:45" ambíguo).
$opensDay = ($opensTs !== false && date('Y-m-d', $opensTs) !== date('Y-m-d')) ? date('d/m', $opensTs) : '';
$opensText = $opensDay !== '' ? ('dia ' . $opensDay . ' às ' . $opensLabel) : ('às ' . $opensLabel);
?><!DOCTYPE html>
<html lang="pt-BR">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <meta name="robots" content="noindex">
  <title>Sala de espera · Teleconsulta</title>
  <link rel="stylesheet" href="/assets/css/fonts.css?v=botanical-v54-9">
  <link rel="stylesheet" href="/assets/css/clinical-botanical.css?v=botanical-v54-9">
  <link rel="stylesheet" href="/assets/css/teleconsulta.css?v=v54-27">
  <link rel="stylesheet" href="/assets/css/operational-ui.css?v=v54-30">
</head>
<body class="tc-page" data-tc-csrf="<?= $e($csrf) ?>" data-tc-term-id="<?= (int)$term['id'] ?>" data-tc-opens-at="<?= $e($clock['join_opens_at_utc'] ?? '') ?>" data-tc-server-now="<?= $e($clock['server_now_utc'] ?? '') ?>" data-tc-opens-label="<?= $e($opensText) ?>">
<?php require __DIR__ . '/partials/icons.php'; ?>
<a class="tc-skip" href="#tc-conteudo">Pular para o conteúdo</a>
<header class="tc-header">
  <h1 class="tc-brand">Teleconsulta · <?= $e($session['clinic_name']) ?></h1>
</header>
<main id="tc-conteudo" class="tc-narrow">
  <div id="tc-alert" class="tc-alert tc-hidden" role="alert" tabindex="-1">
    <svg class="tc-icon" aria-hidden="true"><use href="#i-alert"/></svg>
    <span id="tc-alert-text"></span>
  </div>

  <div class="tc-card">
    <h2>Olá, <?= $e(explode(' ', (string)$session['patient_name'])[0]) ?></h2>
    <p>Consulta com <strong><?= $e($session['professional_name']) ?></strong> em <?= $e(date('d/m/Y \à\s H:i', strtotime((string)$session['scheduled_at']))) ?> (horário de Brasília).</p>
    <div id="tc-opening" class="tc-opening<?= $isOpen ? ' is-open' : '' ?>">
      <span class="tc-opening__time" id="tc-opening-time"><?= $isOpen ? 'A sala de espera está aberta' : 'A sala abre ' . $e($opensText) ?></span>
      <span class="tc-opening__count<?= $isOpen ? ' tc-hidden' : '' ?>" id="tc-countdown" role="timer" aria-live="off"></span>
    </div>
    <p class="tc-small">Você já pode fazer as etapas 1 e 2 agora. Use um local reservado. A chamada não é gravada e o vídeo vai direto entre você e o(a) nutricionista.</p>
  </div>

  <ol class="tc-steps">
    <li class="tc-card">
      <h2>1. Teste sua câmera e microfone</h2>
      <div class="tc-preview">
        <video id="tc-preview" autoplay playsinline muted aria-label="Prévia da sua câmera"></video>
      </div>
      <div class="tc-controls">
        <button type="button" id="tc-test" class="tc-btn">Testar câmera e microfone</button>
        <button type="button" id="tc-audio-only" class="tc-btn tc-btn-quiet tc-hidden">Entrar apenas com áudio</button>
        <button type="button" id="tc-bypass" class="tc-btn tc-btn-quiet tc-hidden">Continuar sem câmera</button>
      </div>
      <div id="tc-test-status" class="op-message op-message--info tc-status" role="status" aria-live="polite">O navegador vai pedir permissão. Nada é gravado.</div>
    </li>

    <li class="tc-card">
      <h2>2. Termo da teleconsulta</h2>
      <div id="tc-term" class="tc-small tc-term" tabindex="0" role="region" aria-label="Texto do termo da teleconsulta">
        <p><strong><?= $e($term['title']) ?></strong></p>
<?php foreach (preg_split('/\R{2,}/', trim((string)$term['term_text'])) as $paragraph): ?>
        <p><?= $e($paragraph) ?></p>
<?php endforeach; ?>
        <p class="tc-small">Versão <?= $e($term['version']) ?></p>
      </div>
      <label class="tc-check-label">
        <input type="checkbox" id="tc-consent" class="tc-check" aria-describedby="tc-term">
        <span>Li e concordo com o termo da teleconsulta.</span>
      </label>
    </li>

    <li class="tc-card">
      <h2>3. Sala de espera</h2>
      <button type="button" id="tc-enter" class="tc-btn tc-btn-primary" disabled><?= $isOpen ? 'Entrar na sala de espera' : 'A sala abre ' . $e($opensText) ?></button>
      <div id="tc-wait-status" class="op-message op-message--info tc-status" role="status" aria-live="polite"><?= $isOpen ? 'Conclua as etapas 1 e 2.' : 'Conclua as etapas 1 e 2. O botão libera sozinho no horário.' ?></div>
    </li>
  </ol>
</main>
<script src="/assets/js/teleconsulta-espera.js?v=v54-27"></script>
</body>
</html>
