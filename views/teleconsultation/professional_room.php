<?php
/** @var array $session @var array $recentRecords @var ?string $invite @var ?string $notice @var string $csrf @var ?array $draft @var ?array $lastDelivery @var bool $patientHasEmail @var array $clock */
/* v54.27: sala em formato "vídeo primeiro" — vídeo grande, sua imagem flutuante, barra de controles embaixo,
 * faixa compacta da sala de espera no topo e anotações num painel recolhível (aberto ao lado a partir de 1280 px). */
$e = static fn($v) => htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
$sid = (int)$session['id'];
$status = (string)$session['status'];
$terminal = in_array($status, \ClyoNutri\Domain\Telehealth\TeleconsultationService::TERMINAL_STATUSES, true);
$statusLabel = \ClyoNutri\Core\I18n\LabelMap::teleconsultationStatus($status);
$waiting = $status === 'waiting_room';
$clock = $clock ?? [];
$scheduledLabel = !empty($session['scheduled_at']) ? date('d/m/Y \à\s H:i', strtotime((string)$session['scheduled_at'])) : '';
?><!DOCTYPE html>
<html lang="pt-BR">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Consultório online — <?= $e($session['patient_name']) ?></title>
  <link rel="stylesheet" href="/assets/css/fonts.css?v=botanical-v54-9">
  <link rel="stylesheet" href="/assets/css/clinical-botanical.css?v=botanical-v54-9">
  <link rel="stylesheet" href="/assets/css/teleconsulta.css?v=v54-27">
  <link rel="stylesheet" href="/assets/css/operational-ui.css?v=v54-30">
</head>
<body class="tc-room tc-room--pro" data-tc-session="<?= $sid ?>" data-tc-csrf="<?= $e($csrf) ?>" data-tc-status="<?= $e($status) ?>" data-tc-opens-at="<?= $e($clock['join_opens_at_utc'] ?? '') ?>" data-tc-server-now="<?= $e($clock['server_now_utc'] ?? '') ?>" data-tc-opens-label="<?= $e($clock['opens_label'] ?? '') ?>" data-tc-is-open="<?= !empty($clock['is_open']) ? '1' : '0' ?>">
<?php require __DIR__ . '/partials/icons.php'; ?>
<a class="tc-skip" href="#tc-conteudo">Pular para o vídeo</a>
<a class="tc-skip" href="#tc-panel">Pular para as anotações</a>
<header class="tc-topbar">
  <div class="tc-topbar__title">
    <h1 class="tc-brand"><?= $e($session['patient_name']) ?></h1>
    <div class="tc-topbar__sub">Consultório online · consulta de <?= $e($scheduledLabel) ?> · sem gravação</div>
  </div>
  <div class="tc-topbar__actions">
    <span class="tc-pill tc-topbar__hide-sm">Situação: <strong id="tc-session-status"><?= $e($statusLabel) ?></strong></span>
    <a class="tc-btn tc-topbar__hide-sm" href="/consulta/<?= (int)$session['appointment_id'] ?>">Ir para a consulta</a>
  </div>
</header>

<section id="tc-lobby" class="tc-lobby<?= $waiting ? ' is-waiting' : '' ?>" aria-label="Sala de espera">
  <p class="tc-lobby__text"><span class="tc-lobby__dot" aria-hidden="true"></span><span id="tc-lobby-text"><?php
    if ($waiting) { echo 'O paciente está aguardando para entrar.'; }
    elseif (in_array($status, ['admitted', 'in_progress'], true)) { echo 'Paciente admitido.'; }
    elseif ($terminal) { echo 'Sala fechada.'; }
    elseif (empty($clock['is_open']) && !empty($clock['opens_label'])) { echo 'Ninguém aguardando. A sala do paciente abre às ' . $e($clock['opens_label']) . '.'; }
    else { echo 'Ninguém aguardando no momento.'; }
  ?></span></p>
  <div id="tc-lobby-actions" class="tc-lobby__actions <?= $waiting ? '' : 'tc-hidden' ?>">
    <form method="POST" action="/teleconsulta/<?= $sid ?>/admitir" id="tc-admit-form">
      <input type="hidden" name="_csrf" value="<?= $e($csrf) ?>">
      <button type="submit" class="tc-btn tc-btn-primary" id="tc-admit">Admitir</button>
    </form>
    <details class="tc-reject">
      <summary>Recusar entrada</summary>
      <form method="POST" action="/teleconsulta/<?= $sid ?>/rejeitar" data-confirm="Recusar a entrada? O convite atual deixará de funcionar.">
        <input type="hidden" name="_csrf" value="<?= $e($csrf) ?>">
        <label class="tc-small" for="tc-reason">Motivo</label>
        <select id="tc-reason" name="reason" class="tc-btn">
          <option value="identity_not_confirmed">Identidade não confirmada</option>
          <option value="wrong_patient">Não é o paciente agendado</option>
          <option value="patient_request">A pedido do paciente</option>
          <option value="other">Outro</option>
        </select>
        <button type="submit" class="tc-btn">Recusar entrada</button>
      </form>
    </details>
  </div>
  <div id="tc-arrival" class="tc-sr" role="alert" aria-live="assertive"></div>
  <details class="tc-lobby__more" id="tc-invite-box"<?= ($invite || $notice) ? ' open' : '' ?>>
    <summary>Convite e lembrete</summary>
    <div class="tc-lobby__more-body">
      <?php if ($invite): ?>
      <p class="tc-small">Este link aparece só agora. Envie ao paciente por um canal seu (ele não contém dados clínicos). Gerar outro convite invalida este.</p>
      <div class="tc-invite">
        <input id="tc-invite-link" type="text" readonly value="<?= $e($invite) ?>" aria-label="Link do convite">
        <button type="button" class="tc-btn" id="tc-copy-invite">Copiar</button>
      </div>
      <?php endif; ?>
      <?php if (!$terminal && empty($session['invite_revoked_at_utc'])): ?>
        <?php if ($patientHasEmail): ?>
        <form method="POST" action="/teleconsulta/<?= $sid ?>/convite-email">
          <input type="hidden" name="_csrf" value="<?= $e($csrf) ?>">
          <button type="submit" class="tc-btn">Enviar convite por e-mail</button>
        </form>
        <?php else: ?>
        <p class="tc-small">O paciente não tem e-mail cadastrado. Envie o link por outro canal.</p>
        <?php endif; ?>
        <?php if ($lastDelivery): $map = ['invite_email_sent' => 'Convite enviado', 'invite_email_failed' => 'Falha ao enviar convite', 'invite_email_skipped' => 'Convite não enviado', 'reminder_email_sent' => 'Lembrete enviado', 'reminder_email_failed' => 'Falha no lembrete', 'reminder_email_skipped' => 'Lembrete não enviado']; ?>
        <p class="tc-small"><?= $e($map[$lastDelivery['event_type']] ?? $lastDelivery['event_type']) ?> em <?= $e(date('d/m H:i', strtotime($lastDelivery['created_at'] . ' UTC'))) ?>. A confirmação é do servidor de e-mail, não de leitura.</p>
        <?php endif; ?>
        <p class="tc-small">Um lembrete automático é enviado por e-mail cerca de 1 hora antes da sala abrir.</p>
      <?php endif; ?>
      <?php if (!$terminal || in_array($status, ['rejected', 'expired'], true)): ?>
      <form method="POST" action="/teleconsulta/<?= $sid ?>/convite" data-confirm="Gerar um novo convite? O link anterior deixará de funcionar.">
        <input type="hidden" name="_csrf" value="<?= $e($csrf) ?>">
        <button type="submit" class="tc-btn">Gerar novo convite</button>
      </form>
      <?php endif; ?>
    </div>
  </details>
  <?php if ($notice): ?><div class="op-message op-message--info tc-notice" role="status"><?= $e($notice) ?></div><?php endif; ?>
</section>

<div class="tc-workspace">
  <main id="tc-conteudo" class="tc-stage" tabindex="-1" aria-label="Vídeo da teleconsulta">
<?php if (!$terminal): ?>
    <div class="tc-screen" id="tc-video-area" data-state="idle">
      <video id="tc-remote" autoplay playsinline aria-label="Vídeo do paciente"></video>
      <div class="tc-placeholder" id="tc-placeholder">
        <svg class="tc-icon" aria-hidden="true"><use href="#i-user"/></svg>
        <strong id="tc-placeholder-title"><?= $waiting ? 'O paciente está na sala de espera' : 'Aguardando o paciente' ?></strong>
        <span id="tc-placeholder-text"><?= $waiting ? 'Toque em "Admitir" e depois em "Ligar câmera e entrar".' : 'Ligue sua câmera quando quiser. O vídeo do paciente aparece aqui.' ?></span>
      </div>
      <span class="tc-chip" id="tc-chip" data-state="<?= $waiting ? 'waiting' : 'idle' ?>"><?= $waiting ? 'Paciente aguardando' : 'Vídeo desligado' ?></span>
      <div class="tc-pip tc-hidden" id="tc-pip" data-corner="br">
        <video id="tc-local" class="tc-self" autoplay playsinline muted aria-label="Sua câmera"></video>
        <button type="button" class="tc-pip__move" id="tc-pip-move" aria-label="Mover sua imagem para outro canto"><svg class="tc-icon" aria-hidden="true"><use href="#i-move"/></svg></button>
      </div>
      <div id="tc-daily" class="tc-daily tc-hidden"></div>
      <button type="button" id="tc-unmute" class="tc-btn tc-btn-primary tc-unmute tc-hidden">Toque para ativar o som da chamada</button>
    </div>
    <div id="tc-status" class="tc-statusline" role="status" aria-live="polite">Vídeo desligado. Sem gravação: áudio e vídeo não são armazenados.</div>
    <div class="tc-callbar" role="group" aria-label="Controles da chamada">
      <button type="button" id="tc-start" class="tc-cbtn tc-cbtn--primary"><svg class="tc-icon" aria-hidden="true"><use href="#i-video"/></svg><span>Ligar câmera e entrar</span></button>
      <button type="button" id="tc-mic" class="tc-cbtn tc-cbtn--off" disabled aria-pressed="false"><svg class="tc-icon tc-icon-on" aria-hidden="true"><use href="#i-mic"/></svg><svg class="tc-icon tc-icon-off" aria-hidden="true"><use href="#i-mic-off"/></svg><span>Silenciar</span></button>
      <button type="button" id="tc-cam" class="tc-cbtn tc-cbtn--off" disabled aria-pressed="false"><svg class="tc-icon tc-icon-on" aria-hidden="true"><use href="#i-cam"/></svg><svg class="tc-icon tc-icon-off" aria-hidden="true"><use href="#i-cam-off"/></svg><span>Pausar câmera</span></button>
      <button type="button" id="tc-screen" class="tc-cbtn tc-cbtn--wide tc-hidden" disabled aria-pressed="false"><svg class="tc-icon" aria-hidden="true"><use href="#i-screen"/></svg><span>Compartilhar</span></button>
      <button type="button" id="tc-fullscreen" class="tc-cbtn tc-hidden" aria-pressed="false"><svg class="tc-icon tc-icon-on" aria-hidden="true"><use href="#i-expand"/></svg><svg class="tc-icon tc-icon-off" aria-hidden="true"><use href="#i-compress"/></svg><span>Tela cheia</span></button>
      <button type="button" id="tc-focus" class="tc-cbtn tc-cbtn--focus" aria-pressed="false"><svg class="tc-icon" aria-hidden="true"><use href="#i-focus"/></svg><span>Modo foco</span></button>
      <button type="button" id="tc-panel-toggle" class="tc-cbtn" aria-expanded="true" aria-controls="tc-panel"><svg class="tc-icon" aria-hidden="true"><use href="#i-notes"/></svg><span>Anotações</span></button>
      <form method="POST" action="/teleconsulta/<?= $sid ?>/encerrar" data-confirm="Encerrar a chamada? A consulta continua aberta para você concluir a evolução.">
        <input type="hidden" name="_csrf" value="<?= $e($csrf) ?>">
        <button type="submit" class="tc-cbtn tc-cbtn--end" id="tc-end"><svg class="tc-icon" aria-hidden="true"><use href="#i-end"/></svg><span>Encerrar</span></button>
      </form>
    </div>
<?php else: ?>
    <div class="tc-ended">
      <h2>Chamada encerrada</h2>
      <p>Situação: <?= $e($statusLabel) ?>. Você ainda pode registrar a evolução ao lado.</p>
      <a class="tc-btn tc-btn-primary" href="/consulta/<?= (int)$session['appointment_id'] ?>">Ir para a consulta</a>
    </div>
    <div class="tc-callbar" role="group" aria-label="Controles">
      <button type="button" id="tc-panel-toggle" class="tc-cbtn" aria-expanded="true" aria-controls="tc-panel"><svg class="tc-icon" aria-hidden="true"><use href="#i-notes"/></svg><span>Anotações</span></button>
    </div>
<?php endif; ?>
  </main>

  <aside id="tc-panel" class="tc-panel" aria-labelledby="tc-panel-title" tabindex="-1">
    <div class="tc-panel__head">
      <h2 id="tc-panel-title">Evolução no prontuário</h2>
      <button type="button" class="tc-btn tc-panel__close" id="tc-panel-close" aria-label="Fechar anotações"><svg class="tc-icon" aria-hidden="true"><use href="#i-close"/></svg></button>
    </div>
    <p class="tc-small">Visível só para você. Encerrar a chamada não conclui a consulta nem publica plano alimentar.</p>
    <form method="POST" action="/teleconsulta/<?= $sid ?>/evoluir" id="tc-evolution" data-has-flash-draft="<?= $draft ? '1' : '0' ?>">
      <input type="hidden" name="_csrf" value="<?= $e($csrf) ?>">
      <div class="tc-field">
        <label for="tc-title">Título</label>
        <input id="tc-title" name="title" required maxlength="255" value="<?= $e($draft['title'] ?? ('Evolução — teleconsulta ' . (!empty($session['scheduled_at']) ? date('d/m/Y', strtotime((string)$session['scheduled_at'])) : date('d/m/Y')))) ?>">
      </div>
      <div class="tc-field">
        <label for="tc-notes">Anotações clínicas</label>
        <textarea id="tc-notes" name="clinical_notes" required rows="8"><?= $e($draft['notes'] ?? '') ?></textarea>
      </div>
      <div class="tc-field">
        <label for="tc-goals">Metas até o retorno</label>
        <input id="tc-goals" name="goals" value="<?= $e($draft['goals'] ?? '') ?>">
      </div>
      <div id="tc-draft-status" class="op-message op-message--info tc-small" role="status" aria-live="polite">O rascunho é salvo automaticamente enquanto você escreve.</div>
      <div id="tc-draft-conflict" class="op-message op-message--error tc-notice tc-hidden" role="alert">
        Este rascunho foi alterado em outra aba ou dispositivo.
        <div class="tc-controls">
          <button type="button" class="tc-btn" id="tc-draft-load">Carregar a versão salva</button>
          <button type="button" class="tc-btn" id="tc-draft-keep">Manter o que está aqui</button>
        </div>
      </div>
      <button type="submit" class="tc-btn tc-btn-primary">Finalizar e salvar no prontuário</button>
      <p class="tc-small">O rascunho não é prontuário. O registro só é criado ao finalizar e não pode ser editado depois (novas anotações viram novos registros).</p>
    </form>
    <h3>Últimos registros</h3>
    <ul class="tc-records">
      <?php foreach ($recentRecords as $rec): ?>
        <li><?= $e($rec['title']) ?> <span class="tc-small">· v<?= (int)$rec['version'] ?> · <?= $e(date('d/m/Y', strtotime((string)$rec['created_at']))) ?></span></li>
      <?php endforeach; ?>
      <?php if (!$recentRecords): ?><li class="tc-small">Sem registros anteriores.</li><?php endif; ?>
    </ul>
  </aside>
</div>

<script src="/assets/js/teleconsulta-comum.js"></script>
<?php if (!$terminal): ?><script src="/assets/js/teleconsulta-webrtc.js?v=v54-27"></script><?php endif; ?>
<script src="/assets/js/teleconsulta-profissional.js?v=v54-27"></script>
</body>
</html>
