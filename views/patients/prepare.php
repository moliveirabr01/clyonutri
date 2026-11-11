<?php
/**
 * NI2-712: painel Preparar consulta — separado de anamnese e de iniciar retorno.
 * Entradas: $p, $careStep, $clinicalSummary, $activePlan, $assessments, $lastCompleted,
 *           $pendingExamCount, $csrfToken, $latestAnamnesis (opcional).
 */
$e = static fn($v): string => htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
$pid = (int)$p['id'];
$stepApp = $careStep['appointment'] ?? null;
$stepState = (string)($careStep['state'] ?? 'none');
$allergyLabel = (string)($clinicalSummary['allergy_label'] ?? 'Alergias não avaliadas');
$allergyExtra = !empty($clinicalSummary['allergies']) ? ': ' . (string)$clinicalSummary['allergies'] : '';
$planStatus = $activePlan
    ? ((string)($activePlan['title'] ?? 'Plano') . ' · ' . match ((string)($activePlan['status'] ?? '')) {
        'active' => 'vigente', 'draft' => 'rascunho', default => (string)($activePlan['status'] ?? ''),
      })
    : 'Nenhum plano vigente';
$lastAnthro = !empty($assessments) ? $assessments[array_key_last($assessments)] : null;
if ($lastAnthro === null && !empty($assessments[0])) $lastAnthro = $assessments[0];
$anthroLabel = $lastAnthro
    ? (date('d/m/Y', strtotime((string)($lastAnthro['assessed_at'] ?? $lastAnthro['created_at'] ?? 'now')))
        . ' · ' . \ClyoNutri\Core\I18n\LabelMap::num($lastAnthro['weight_kg'] ?? null, 1) . ' kg'
        . (isset($lastAnthro['bmi']) ? ' · IMC ' . \ClyoNutri\Core\I18n\LabelMap::num($lastAnthro['bmi'], 2) : ''))
    : 'Sem avaliação recente';
$lastConsult = !empty($lastCompleted['scheduled_at'])
    ? date('d/m/Y H:i', strtotime((string)$lastCompleted['scheduled_at']))
    : '—';
$pendingBits = [];
if (!empty($clinicalSummary['draft_in_progress'])) $pendingBits[] = 'anamnese em alteração';
if (($activePlan['status'] ?? '') === 'draft') $pendingBits[] = 'plano em rascunho';
if ((int)($pendingExamCount ?? 0) > 0) $pendingBits[] = (int)$pendingExamCount . ((int)$pendingExamCount === 1 ? ' exame a concluir' : ' exames a concluir');
?>
<section class="prep-panel card" data-testid="prepare-panel" aria-labelledby="prep-title">
  <h2 id="prep-title" class="u-mt-0">Preparar consulta</h2>
  <p class="prep-panel__lead">Revise o essencial antes de iniciar. A anamnese completa e o início do atendimento são ações separadas.</p>

  <dl class="prep-panel__grid">
    <div><dt>Consulta prevista</dt><dd data-testid="prep-scheduled"><?php
      if ($stepState === 'continue') echo 'Em andamento · ' . $e(date('d/m/Y H:i', strtotime((string)$stepApp['scheduled_at'])));
      elseif ($stepApp) echo $e(date('d/m/Y H:i', strtotime((string)$stepApp['scheduled_at']))) . ' · ' . $e(\ClyoNutri\Core\I18n\LabelMap::appointmentType($stepApp['type'] ?? null));
      else echo 'Sem consulta agendada';
    ?></dd></div>
    <div><dt>Último atendimento</dt><dd data-testid="prep-last"><?= $e($lastConsult) ?></dd></div>
    <div><dt>Evolução / anamnese</dt><dd data-testid="prep-evolution"><?php
      if (!empty($clinicalSummary['draft_in_progress'])) echo $e((string)$clinicalSummary['draft_in_progress']['label']);
      elseif (!empty($latestAnamnesis)) echo 'v' . (int)$latestAnamnesis['version'] . (!empty($clinicalSummary['is_draft']) ? ' (rascunho)' : ' (vigente)');
      else echo 'Sem anamnese registrada';
    ?></dd></div>
    <div><dt>Plano</dt><dd data-testid="prep-plan"><?= $e($planStatus) ?></dd></div>
    <div><dt>Antropometria</dt><dd data-testid="prep-anthro"><?= $e($anthroLabel) ?></dd></div>
    <div><dt>Pendências</dt><dd data-testid="prep-pending"><?= $e($pendingBits ? implode(' · ', $pendingBits) : 'Nenhuma pendência listada') ?></dd></div>
    <div><dt>Alergias</dt><dd data-testid="prep-allergy"><?= $e($allergyLabel . $allergyExtra) ?></dd></div>
  </dl>

  <div class="prep-panel__actions" data-testid="prep-actions">
    <?php if ($stepState === 'continue' && $stepApp): ?>
      <a class="btn btn-primary" href="/consulta/<?= (int)$stepApp['id'] ?>" data-testid="prep-continue">Continuar atendimento</a>
    <?php elseif ($stepState === 'start_today' && $stepApp): ?>
      <form method="POST" action="/agenda/<?= (int)$stepApp['id'] ?>/status" class="u-m-0" data-no-dirty data-confirm="<?= $e('Iniciar agora o atendimento de ' . $p['name'] . ' (consulta das ' . date('H:i', strtotime((string)$stepApp['scheduled_at'])) . ')?') ?>">
        <input type="hidden" name="_csrf_token" value="<?= $e($csrfToken) ?>">
        <input type="hidden" name="status" value="in_progress">
        <button type="submit" class="btn btn-primary" data-testid="prep-start-return">Iniciar retorno</button>
      </form>
    <?php else: ?>
      <form method="POST" action="/pacientes/<?= $pid ?>/iniciar-agora" class="u-m-0" data-no-dirty data-confirm="<?= $e('Iniciar agora um atendimento de ' . $p['name'] . ' sem depender só da agenda?') ?>">
        <input type="hidden" name="_csrf_token" value="<?= $e($csrfToken) ?>">
        <button type="submit" class="btn btn-primary" data-testid="prep-start-now">Iniciar retorno agora</button>
      </form>
    <?php endif; ?>
    <a class="btn btn-secondary" href="/pacientes/<?= $pid ?>/anamnese" data-testid="prep-open-anamnesis">Abrir anamnese</a>
    <a class="btn btn-secondary" href="/pacientes/<?= $pid ?>/antropometria">Abrir avaliações</a>
    <a class="btn btn-secondary" href="/pacientes/<?= $pid ?>/plano">Ver plano</a>
    <a class="btn btn-outline" href="/pacientes/<?= $pid ?>">← Voltar ao resumo</a>
  </div>
</section>
