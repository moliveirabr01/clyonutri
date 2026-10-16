<?php
/** @var array $session @var string $csrf @var string $termVersion */
$e = static fn($v) => htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
?><!DOCTYPE html>
<html lang="pt-BR">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <meta name="robots" content="noindex">
  <title>Sala de espera — Teleconsulta</title>
  <link rel="stylesheet" href="/assets/css/teleconsulta.css">
</head>
<body data-tc-csrf="<?= $e($csrf) ?>">
<a class="tc-skip" href="#tc-conteudo">Pular para o conteúdo</a>
<header class="tc-header">
  <h1 class="tc-brand">Teleconsulta · <?= $e($session['clinic_name']) ?></h1>
</header>
<main id="tc-conteudo" class="tc-narrow">
  <div class="tc-card">
    <h2>Olá, <?= $e(explode(' ', (string)$session['patient_name'])[0]) ?></h2>
    <p>Consulta com <strong><?= $e($session['professional_name']) ?></strong> em <?= $e(date('d/m/Y \à\s H:i', strtotime((string)$session['scheduled_at']))) ?> (horário de Brasília).</p>
    <p class="tc-small">Use um local reservado. A chamada não é gravada e o vídeo vai direto entre você e o(a) nutricionista.</p>
  </div>

  <ol class="tc-steps">
    <li class="tc-card">
      <h2>1. Teste sua câmera e microfone</h2>
      <div class="tc-video" style="max-width:420px">
        <video id="tc-preview" autoplay playsinline muted aria-label="Prévia da sua câmera"></video>
      </div>
      <div class="tc-controls"><button type="button" id="tc-test" class="tc-btn">Testar câmera e microfone</button></div>
      <div id="tc-test-status" class="tc-status" role="status" aria-live="polite">O navegador vai pedir permissão. Nada é gravado.</div>
    </li>

    <li class="tc-card">
      <h2>2. Termo da teleconsulta</h2>
      <div id="tc-term" class="tc-small tc-term" tabindex="0" role="region" aria-label="Texto do termo da teleconsulta">
        <p>Concordo em ser atendido(a) por teleconsulta, ciente de que o atendimento remoto tem limitações em relação ao presencial (por exemplo, não permite exame físico) e de que o(a) nutricionista pode indicar consulta presencial quando necessário.</p>
        <p>A chamada não é gravada. Meus dados de saúde são tratados para a prestação do atendimento nutricional, conforme a LGPD, e as anotações ficam no meu prontuário. Posso interromper a teleconsulta a qualquer momento.</p>
        <p class="tc-small">Versão <?= $e($termVersion) ?> — texto sujeito a revisão jurídica e regulatória (NI2-253).</p>
      </div>
      <label class="tc-check-label">
        <input type="checkbox" id="tc-consent" class="tc-check" aria-describedby="tc-term">
        <span>Li e concordo com o termo da teleconsulta.</span>
      </label>
    </li>

    <li class="tc-card">
      <h2>3. Sala de espera</h2>
      <button type="button" id="tc-enter" class="tc-btn tc-btn-primary" disabled>Entrar na sala de espera</button>
      <div id="tc-wait-status" class="tc-status" role="status" aria-live="polite">Conclua as etapas 1 e 2.</div>
    </li>
  </ol>
</main>
<script src="/assets/js/teleconsulta-espera.js"></script>
</body>
</html>
