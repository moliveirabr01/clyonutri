<?php
/**
 * NI2-714 (MVP) + v54.47 Z01/G01/V01: barra persistente do Modo Atendimento quando há consulta em andamento.
 * Entradas: $careStep (state=continue + appointment), opcional $pid, opcional $careFlow (CareProgress::snapshot — única fonte do progresso).
 * Sem estado no navegador: etapas, próxima ação e última etapa salva vêm do banco.
 */
$app = $careStep['appointment'] ?? null;
if (!$app || ($careStep['state'] ?? '') !== 'continue') return;
$e = static fn($v): string => htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
$aid = (int)$app['id'];
$pid = (int)($pid ?? $app['patient_id'] ?? 0);
$when = !empty($app['scheduled_at']) ? date('d/m/Y H:i', strtotime((string)$app['scheduled_at'])) : '';
$q = 'atendimento=' . $aid;
$flow = (isset($careFlow) && is_array($careFlow) && (int)($careFlow['appointment_id'] ?? 0) === $aid) ? $careFlow : null;
$stateLabels = \ClyoNutri\Domain\Appointment\CareWorkflow::LABELS;
?>
<aside class="attendance-mode" data-testid="attendance-mode" data-appointment="<?= $aid ?>" aria-label="Modo Atendimento">
  <a class="btn btn-secondary btn-sm" href="/consulta/<?= $aid ?>" data-testid="attendance-hub" title="Referência para suporte: <?= $aid ?>">Voltar ao atendimento</a>
  <details class="ux52-attendance-tools"><summary>Etapas e ferramentas do atendimento</summary>
  <div class="attendance-mode__main">
    <strong class="attendance-mode__badge">Modo Atendimento · em andamento</strong>
    <span class="attendance-mode__meta" title="Referência para suporte: <?= $aid ?>">Consulta<?= $when !== '' ? ' · ' . $e($when) : '' ?></span>
    <?php if ($flow): ?>
      <?php if (!empty($flow['quantitative'])): ?>
        <span class="attendance-mode__meta" data-testid="attendance-progress">Progresso: <?= (int)$flow['done'] ?> de <?= (int)$flow['total'] ?> etapas necessárias confirmadas</span>
      <?php else: ?>
        <span class="attendance-mode__meta" data-testid="attendance-progress">Aplicabilidade das etapas ainda não definida — sem percentual</span>
      <?php endif; ?>
      <?php if (!empty($flow['last_saved'])): ?>
        <span class="attendance-mode__meta" data-testid="attendance-last-saved">Último registro salvo: <?= $e($flow['last_saved']['label']) ?> · <?= $e(date('d/m H:i', strtotime((string)$flow['last_saved']['at']))) ?></span>
      <?php endif; ?>
      <ul class="attendance-mode__steps" data-testid="attendance-steps" aria-label="Etapas do atendimento">
        <?php foreach ($flow['steps'] as $s): ?>
          <li data-step="<?= $e($s['key']) ?>" data-state="<?= $e($s['state']) ?>" class="attendance-mode__step attendance-mode__step--<?= $e($s['state']) ?>"><span aria-hidden="true"><?= ['done' => '✓', 'in_progress' => '…', 'review' => '!', 'pending' => '○', 'not_applicable' => '–'][$s['state']] ?? '·' ?></span> <?= $e($s['label']) ?>: <?= $e($stateLabels[$s['state']] ?? $s['state']) ?></li>
        <?php endforeach; ?>
      </ul>
    <?php endif; ?>
  </div>
  <?php if ($flow && !empty($flow['next'])): ?>
    <a class="btn btn-primary btn-sm" href="<?= $e($flow['next']['url']) ?>" data-testid="attendance-resume">Próxima ação: <?= $e($flow['next']['action'] ?? ('Continuar ' . $flow['next']['label'])) ?></a>
  <?php endif; ?>
  <nav class="attendance-mode__nav" aria-label="Ferramentas do atendimento">

    <a class="btn btn-secondary btn-sm" href="/pacientes/<?= $pid ?>/anamnese?<?= $q ?>">Anamnese</a>
    <a class="btn btn-secondary btn-sm" href="/pacientes/<?= $pid ?>/antropometria?<?= $q ?>">Avaliações</a>
    <a class="btn btn-secondary btn-sm" href="/pacientes/<?= $pid ?>/plano?<?= $q ?>">Plano</a>
  </nav>
  </details>
</aside>
