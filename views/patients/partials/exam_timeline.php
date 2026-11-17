<?php
/**
 * Solicitações de exames laboratoriais na linha do tempo (v47, NI2-537): prontuário e tela da consulta.
 * Lista sem duplicar dados: só número, situação, versão, data, autor, atendimento e nomes dos exames, com link.
 * @var array $examTimeline  ExamRequestService::timeline()
 * @var int $examPatientId
 * @var ?int $examAppointmentId  quando vem da consulta (o botão de nova solicitação já leva o atendimento)
 */
$e = static fn($v) => htmlspecialchars((string)($v ?? ''), ENT_QUOTES, 'UTF-8');
$fmt = static fn($v) => $v ? date('d/m/Y H:i', strtotime((string)$v)) : '';
$badge = ['draft' => 'badge-neutral', 'review' => 'badge-warning', 'issued' => 'badge-success', 'cancelled' => 'badge-danger'];
$examReturn=!empty($examAppointmentId)?'?atendimento='.(int)$examAppointmentId:'';
$newUrl = '/pacientes/' . (int)$examPatientId . '/exames/novo' . (!empty($examAppointmentId) ? '?atendimento=' . (int)$examAppointmentId : '');
?>
<link rel="stylesheet" href="/assets/css/exames.css?v=v54-68">
<section class="card ex-timeline-card" aria-labelledby="exam-timeline-title" data-testid="exam-timeline">
  <div class="card__header ex-timeline-card__head">
    <h3 id="exam-timeline-title">Solicitações de exames laboratoriais<?= !empty($examAppointmentId) ? ' desta consulta' : '' ?> (<?= count($examTimeline) ?>)</h3>
    <div class="ex-actions">
      <a class="btn btn-sm btn-outline" href="/pacientes/<?= (int)$examPatientId ?>/exames">Ver todas</a>
      <a class="btn btn-sm btn-primary" href="<?= $e($newUrl) ?>">Nova solicitação<?= !empty($examAppointmentId) ? ' nesta consulta' : '' ?></a>
    </div>
  </div>
  <?php if (!empty($examTimelineUnavailable)): ?><p class="op-message op-message--info" role="status">Histórico de exames indisponível: confira a atualização deste módulo.</p>
  <?php elseif (!$examTimeline): ?>
    <p class="ex-muted ex-flush">Nenhuma solicitação de exames<?= !empty($examAppointmentId) ? ' vinculada a esta consulta' : '' ?>.</p>
  <?php else: ?>
    <ol class="ex-timeline">
      <?php foreach ($examTimeline as $r): ?>
        <li class="<?= $r['status'] === 'cancelled' ? 'is-cancelled' : '' ?>">
          <div class="ex-timeline-head">
            <a href="/pacientes/<?= (int)$examPatientId ?>/exames/<?= (int)$r['id'] ?><?= $e($examReturn) ?>"><strong><?= $e($r['request_number']) ?></strong></a>
            <span class="badge <?= $badge[$r['status']] ?? 'badge-neutral' ?>"><?= $e($r['status_label']) ?></span>
            <?php if ((int)($r['version_number'] ?? 1) > 1): ?><span class="ex-muted">versão <?= (int)$r['version_number'] ?></span><?php endif; ?>
            <span class="ex-muted"><?= $e($fmt($r['issued_at'] ?: $r['created_at'])) ?> · <?= $e($r['professional_name']) ?><?= $r['professional_crn'] !== '' ? ' (' . $e($r['professional_crn']) . ')' : '' ?></span>
            <?php if (empty($examAppointmentId) && !empty($r['appointment_id'])): ?>
              <a class="ex-muted" href="/consulta/<?= (int)$r['appointment_id'] ?>">consulta de <?= $e($fmt($r['appointment_scheduled_at'])) ?></a>
            <?php endif; ?>
          </div>
          <p class="ex-timeline-exams"><?= $e(implode(' · ', $r['exam_names'])) ?></p>
          <?php if (!empty($r['results_accepted']) || !empty($r['results_pending'])): ?>
            <p class="ex-timeline-exams">Laudos: <?= (int)($r['results_accepted'] ?? 0) ?><?php if (!empty($r['results_pending'])): ?> · <a href="/pacientes/<?= (int)$examPatientId ?>/exames/<?= (int)$r['id'] ?>#laudos"><?= (int)$r['results_pending'] ?> aguardando revisão</a><?php endif; ?></p>
          <?php endif; ?>
          <?php if ($r['status'] === 'cancelled' && !empty($r['cancellation_reason'])): ?>
            <p class="ex-timeline-exams">Cancelada: <?= $e($r['cancellation_reason']) ?></p>
          <?php endif; ?>
        </li>
      <?php endforeach; ?>
    </ol>
  <?php endif; ?>
</section>
