<?php
/** @var array $session @var string $csrf */
/* v54.27: vídeo de ponta a ponta no celular, sua imagem flutuante e controles embaixo. */
$e = static fn($v) => htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
?><!DOCTYPE html>
<html lang="pt-BR">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
  <meta name="robots" content="noindex">
  <title>Teleconsulta com <?= $e($session['professional_name']) ?></title>
  <link rel="stylesheet" href="/assets/css/fonts.css?v=v54-66">
  <link rel="stylesheet" href="/assets/css/clinical-botanical.css?v=botanical-v54-9">
  <link rel="stylesheet" href="/assets/css/teleconsulta.css?v=v54-27">
  <link rel="stylesheet" href="/assets/css/operational-ui.css?v=v54-66">
</head>
<body class="tc-room tc-room--patient" data-tc-csrf="<?= $e($csrf) ?>">
<?php require __DIR__ . '/partials/icons.php'; ?>
<a class="tc-skip" href="#tc-conteudo">Pular para o vídeo</a>
<header class="tc-topbar">
  <div class="tc-topbar__title">
    <h1 class="tc-brand">Teleconsulta · <?= $e($session['professional_name']) ?></h1>
    <div class="tc-topbar__sub">Sem gravação · vídeo direto entre vocês</div>
  </div>
</header>
<main id="tc-conteudo" class="tc-stage" tabindex="-1" aria-label="Vídeo da teleconsulta">
  <div class="tc-screen" id="tc-video-area" data-state="connecting">
    <video id="tc-remote" autoplay playsinline aria-label="Vídeo do(a) nutricionista"></video>
    <div class="tc-placeholder" id="tc-placeholder">
      <svg class="tc-icon" aria-hidden="true"><use href="#i-user"/></svg>
      <strong id="tc-placeholder-title">Conectando…</strong>
      <span id="tc-placeholder-text">Mantenha esta página aberta.</span>
    </div>
    <span class="tc-chip" id="tc-chip" data-state="connecting">Conectando…</span>
    <div class="tc-pip" id="tc-pip" data-corner="br">
      <video id="tc-local" class="tc-self" autoplay playsinline muted aria-label="Sua câmera"></video>
      <button type="button" class="tc-pip__move" id="tc-pip-move" aria-label="Mover sua imagem para outro canto"><svg class="tc-icon" aria-hidden="true"><use href="#i-move"/></svg></button>
    </div>
    <div id="tc-daily" class="tc-daily tc-hidden"></div>
    <button type="button" id="tc-unmute" class="tc-btn tc-btn-primary tc-unmute tc-hidden">Toque para ativar o som da chamada</button>
  </div>
  <div id="tc-status" class="tc-statusline" role="status" aria-live="polite">Conectando…</div>
  <div class="tc-callbar" role="group" aria-label="Controles da chamada">
    <button type="button" id="tc-mic" class="tc-cbtn tc-cbtn--off" aria-pressed="false"><svg class="tc-icon tc-icon-on" aria-hidden="true"><use href="#i-mic"/></svg><svg class="tc-icon tc-icon-off" aria-hidden="true"><use href="#i-mic-off"/></svg><span>Silenciar</span></button>
    <button type="button" id="tc-cam" class="tc-cbtn tc-cbtn--off" aria-pressed="false"><svg class="tc-icon tc-icon-on" aria-hidden="true"><use href="#i-cam"/></svg><svg class="tc-icon tc-icon-off" aria-hidden="true"><use href="#i-cam-off"/></svg><span>Pausar câmera</span></button>
    <button type="button" id="tc-fullscreen" class="tc-cbtn tc-hidden" aria-pressed="false"><svg class="tc-icon tc-icon-on" aria-hidden="true"><use href="#i-expand"/></svg><svg class="tc-icon tc-icon-off" aria-hidden="true"><use href="#i-compress"/></svg><span>Tela cheia</span></button>
    <button type="button" id="tc-leave" class="tc-cbtn tc-cbtn--end"><svg class="tc-icon" aria-hidden="true"><use href="#i-end"/></svg><span>Sair</span></button>
  </div>
</main>
<script src="/assets/js/teleconsulta-webrtc.js?v=v54-27"></script>
<script src="/assets/js/teleconsulta-sala.js?v=v54-27"></script>
</body>
</html>
