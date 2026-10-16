<?php
/** @var array $session @var array $recentRecords @var ?string $invite @var ?string $notice @var string $csrf @var ?array $draft @var ?array $lastDelivery @var bool $patientHasEmail */
$e = static fn($v) => htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
$sid = (int)$session['id'];
$status = (string)$session['status'];
$terminal = in_array($status, \ClyoNutri\Domain\Telehealth\TeleconsultationService::TERMINAL_STATUSES, true);
$statusLabel = \ClyoNutri\Core\I18n\LabelMap::teleconsultationStatus($status);
?><!DOCTYPE html>
<html lang="pt-BR">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Consultório Online — <?= $e($session['patient_name']) ?></title>
  <link rel="stylesheet" href="/assets/css/teleconsulta.css">
</head>
<body data-tc-session="<?= $sid ?>
<a class="tc-skip" href="#tc-conteudo">Pular para o conteúdo</a>" data-tc-csrf="<?= $e($csrf) ?>">
<header class="tc-header">
  <div>
    <h1 class="tc-brand">Consultório Online</h1>
    <div class="tc-meta">Paciente: <strong><?= $e($session['patient_name']) ?></strong> · Consulta de <?= $e(date('d/m/Y H:i', strtotime((string)$session['scheduled_at']))) ?></div>
  </div>
  <div class="tc-meta">Situação: <strong id="tc-session-status"><?= $e($statusLabel) ?></strong></div>
  <?php if (!$terminal): ?>
  <form method="POST" action="/teleconsulta/<?= $sid ?>/encerrar" data-confirm="Encerrar a chamada? A consulta continua aberta para você concluir a evolução.">
    <input type="hidden" name="_csrf" value="<?= $e($csrf) ?>">
    <button type="submit" class="tc-btn tc-btn-danger">Encerrar chamada</button>
  </form>
  <?php else: ?>
  <a class="tc-btn" style="display:inline-flex;align-items:center;text-decoration:none" href="/consulta/<?= (int)$session['appointment_id'] ?>">Ir para a consulta</a>
  <?php endif; ?>
</header>

<main id="tc-conteudo" class="tc-main tc-split">
  <section style="display:grid;gap:16px;align-content:start">
    <?php if ($notice): ?><div class="tc-notice" role="status"><?= $e($notice) ?></div><?php endif; ?>

    <?php if ($invite): ?>
    <div class="tc-card">
      <h2>Convite do paciente</h2>
      <p class="tc-small">Este link aparece só agora. Envie ao paciente por um canal seu (ele não contém dados clínicos). Gerar outro convite invalida este.</p>
      <div class="tc-invite">
        <input id="tc-invite-link" type="text" readonly value="<?= $e($invite) ?>" aria-label="Link do convite">
        <button type="button" class="tc-btn" id="tc-copy-invite">Copiar</button>
      </div>
    </div>
    <?php endif; ?>

    <div class="tc-card" id="tc-lobby">
      <h2>Sala de espera</h2>
      <p id="tc-lobby-text" class="tc-small"><?= $status === 'waiting_room' ? 'O paciente está aguardando.' : 'Ninguém aguardando no momento.' ?></p>
      <div id="tc-lobby-actions" class="tc-controls <?= $status === 'waiting_room' ? '' : 'tc-hidden' ?>">
        <form method="POST" action="/teleconsulta/<?= $sid ?>/admitir">
          <input type="hidden" name="_csrf" value="<?= $e($csrf) ?>">
          <button type="submit" class="tc-btn tc-btn-primary">Admitir paciente</button>
        </form>
        <form method="POST" action="/teleconsulta/<?= $sid ?>/rejeitar" class="tc-controls" data-confirm="Recusar a entrada? O convite atual deixará de funcionar.">
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
      </div>
      <?php if (!$terminal && empty($session['invite_revoked_at_utc'])): ?>
      <div style="margin-top:16px;border-top:1px solid var(--border);padding-top:12px">
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
      </div>
      <?php endif; ?>
      <?php if (!$terminal || in_array($status, ['rejected', 'expired'], true)): ?>
      <form method="POST" action="/teleconsulta/<?= $sid ?>/convite" style="margin-top:12px" data-confirm="Gerar um novo convite? O link anterior deixará de funcionar.">
        <input type="hidden" name="_csrf" value="<?= $e($csrf) ?>">
        <button type="submit" class="tc-btn">Gerar novo convite</button>
      </form>
      <?php endif; ?>
    </div>

    <?php if (!$terminal): ?>
    <div class="tc-card">
      <h2>Vídeo</h2>
      <div class="tc-video">
        <video id="tc-remote" autoplay playsinline aria-label="Vídeo do paciente"></video>
        <video id="tc-local" class="tc-self" autoplay playsinline muted aria-label="Sua câmera"></video>
        <div id="tc-daily" class="tc-daily tc-hidden"></div>
        <button type="button" id="tc-unmute" class="tc-btn tc-btn-primary tc-unmute tc-hidden">Toque para ativar o som da chamada</button>
      </div>
      <div class="tc-controls">
        <button type="button" id="tc-start" class="tc-btn tc-btn-primary">Ligar câmera e entrar</button>
        <button type="button" id="tc-mic" class="tc-btn" disabled aria-pressed="false">Silenciar microfone</button>
        <button type="button" id="tc-cam" class="tc-btn" disabled aria-pressed="false">Desligar câmera</button>
        <button type="button" id="tc-screen" class="tc-btn tc-hidden" disabled aria-pressed="false">Compartilhar tela</button>
      </div>
      <div id="tc-status" class="tc-status" role="status" aria-live="polite">Vídeo desligado. Sem gravação: áudio e vídeo não são armazenados.</div>
    </div>
    <?php endif; ?>
  </section>

  <section class="tc-card" style="align-content:start">
    <h2>Evolução no prontuário</h2>
    <p class="tc-small">Visível só para você. Encerrar a chamada não conclui a consulta nem publica plano alimentar.</p>
    <form method="POST" action="/teleconsulta/<?= $sid ?>/evoluir" id="tc-evolution" data-has-flash-draft="<?= $draft ? '1' : '0' ?>">
      <input type="hidden" name="_csrf" value="<?= $e($csrf) ?>">
      <div class="tc-field">
        <label for="tc-title">Título</label>
        <input id="tc-title" name="title" required maxlength="255" value="<?= $e($draft['title'] ?? ('Evolução — teleconsulta ' . date('d/m/Y'))) ?>">
      </div>
      <div class="tc-field">
        <label for="tc-notes">Anotações clínicas</label>
        <textarea id="tc-notes" name="clinical_notes" required rows="8"><?= $e($draft['notes'] ?? '') ?></textarea>
      </div>
      <div class="tc-field">
        <label for="tc-goals">Metas até o retorno</label>
        <input id="tc-goals" name="goals" value="<?= $e($draft['goals'] ?? '') ?>">
      </div>
      <div id="tc-draft-status" class="tc-small" role="status" aria-live="polite">O rascunho é salvo automaticamente enquanto você escreve.</div>
      <div id="tc-draft-conflict" class="tc-notice tc-hidden" role="alert" style="margin:8px 0">
        Este rascunho foi alterado em outra aba ou dispositivo.
        <div class="tc-controls">
          <button type="button" class="tc-btn" id="tc-draft-load">Carregar a versão salva</button>
          <button type="button" class="tc-btn" id="tc-draft-keep">Manter o que está aqui</button>
        </div>
      </div>
      <button type="submit" class="tc-btn tc-btn-primary" style="margin-top:8px">Finalizar e salvar no prontuário</button>
      <p class="tc-small">O rascunho não é prontuário. O registro só é criado ao finalizar e não pode ser editado depois (novas anotações viram novos registros).</p>
    </form>
    <h3 style="font-size:14px;margin:20px 0 8px">Últimos registros</h3>
    <ul class="tc-records">
      <?php foreach ($recentRecords as $rec): ?>
        <li><?= $e($rec['title']) ?> <span class="tc-small">· v<?= (int)$rec['version'] ?> · <?= $e(date('d/m/Y', strtotime((string)$rec['created_at']))) ?></span></li>
      <?php endforeach; ?>
      <?php if (!$recentRecords): ?><li class="tc-small">Sem registros anteriores.</li><?php endif; ?>
    </ul>
  </section>
</main>

<script src="/assets/js/teleconsulta-comum.js"></script>
<?php if (!$terminal): ?><script src="/assets/js/teleconsulta-webrtc.js"></script><?php endif; ?>
<script src="/assets/js/teleconsulta-profissional.js"></script>
</body>
</html>
