<?php
/** @var array $session @var string $token @var string $csrf */
$e = static fn($v) => htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
?><!DOCTYPE html>
<html lang="pt-BR">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <meta name="robots" content="noindex">
  <title>Teleconsulta com <?= $e($session['professional_name']) ?></title>
  <link rel="stylesheet" href="/assets/css/teleconsulta.css">
</head>
<body data-tc-token="<?= $e($token) ?>
<a class="tc-skip" href="#tc-conteudo">Pular para o conteúdo</a>" data-tc-csrf="<?= $e($csrf) ?>">
<header class="tc-header">
  <h1 class="tc-brand">Teleconsulta · <?= $e($session['professional_name']) ?></h1>
  <div class="tc-meta">Sem gravação</div>
</header>
<main id="tc-conteudo" class="tc-narrow" style="max-width:960px">
  <div class="tc-card">
    <div class="tc-video">
      <video id="tc-remote" autoplay playsinline aria-label="Vídeo do(a) nutricionista"></video>
      <video id="tc-local" class="tc-self" autoplay playsinline muted aria-label="Sua câmera"></video>
      <div id="tc-daily" class="tc-daily tc-hidden"></div>
        <button type="button" id="tc-unmute" class="tc-btn tc-btn-primary tc-unmute tc-hidden">Toque para ativar o som da chamada</button>
    </div>
    <div class="tc-controls">
      <button type="button" id="tc-mic" class="tc-btn" aria-pressed="false">Silenciar microfone</button>
      <button type="button" id="tc-cam" class="tc-btn" aria-pressed="false">Desligar câmera</button>
      <button type="button" id="tc-leave" class="tc-btn tc-btn-danger">Sair</button>
    </div>
    <div id="tc-status" class="tc-status" role="status" aria-live="polite">Conectando…</div>
  </div>
</main>
<script src="/assets/js/teleconsulta-webrtc.js"></script>
<script src="/assets/js/teleconsulta-sala.js"></script>
</body>
</html>
