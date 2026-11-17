<?php
/**
 * NI2-710: faixa clínica curta no Resumo (acima das dezenas de cards).
 * Entradas: $p, $careStep, $clinicalSummary, $activePlan, $pendingExamCount, $assessments (opcional), $lastCompleted (opcional), $priorityActions (lista de [label,href]).
 */
$e = static fn($v): string => htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
$pid = (int)$p['id'];
$stepApp = $careStep['appointment'] ?? null;
$stepState = (string)($careStep['state'] ?? 'none');
$allergyLabel = (string)($clinicalSummary['allergy_label'] ?? 'Alergias não avaliadas');
$allergyExtra = !empty($clinicalSummary['allergies']) ? ': ' . (string)$clinicalSummary['allergies'] : '';
$planStatusPt = match ($activePlan['status'] ?? '') {
    'active' => 'vigente',
    'published' => 'publicado',
    'draft' => 'rascunho',
    'archived' => 'arquivado',
    default => mb_strtolower(\ClyoNutri\Core\I18n\LabelMap::mealPlanStatus((string)($activePlan['status'] ?? '')), 'UTF-8'),
};
$planLabel = $activePlan
    ? ((string)($activePlan['title'] ?? 'Plano') . ' · ' . $planStatusPt)
    : 'Nenhum plano vigente';
$nextLabel = match ($stepState) {
    'continue' => 'Em andamento · ' . date('d/m/Y H:i', strtotime((string)$stepApp['scheduled_at'])),
    'start_today' => 'Hoje às ' . date('H:i', strtotime((string)$stepApp['scheduled_at'])),
    'upcoming' => 'Próximo: ' . date('d/m/Y H:i', strtotime((string)$stepApp['scheduled_at'])),
    default => 'Sem consulta agendada',
};
$lastLabel = !empty($lastCompleted['scheduled_at'])
    ? date('d/m/Y H:i', strtotime((string)($lastCompleted['effective_at'] ?? $lastCompleted['scheduled_at']))) // NI2-879
    : '—';
$pendingBits = [];
if (!empty($clinicalSummary['draft_in_progress'])) $pendingBits[] = 'anamnese em alteração';
if (($activePlan['status'] ?? '') === 'draft') $pendingBits[] = 'plano em rascunho';
if ((int)($pendingExamCount ?? 0) > 0) $pendingBits[] = (int)$pendingExamCount . ((int)$pendingExamCount === 1 ? ' exame a concluir' : ' exames a concluir');
$pendingLabel = $pendingBits ? implode(' · ', $pendingBits) : 'Nenhuma pendência clínica listada';
$priorityActions = $priorityActions ?? [];
// NI2-887: última evolução registrada no prontuário (título, data e um trecho curto).
$lastEvolution = $lastEvolution ?? null;
$evolutionText = '';
if ($lastEvolution) {
    $plain = trim(\ClyoNutri\Domain\Clinical\ClinicalRecordContent::plainText($lastEvolution['content'] ?? ($lastEvolution['content_json'] ?? null)));
    $evolutionText = mb_strlen($plain, 'UTF-8') > 90 ? rtrim(mb_substr($plain, 0, 89, 'UTF-8')) . '…' : $plain;
}
?>
<section class="op-strip card" data-testid="operational-strip" aria-labelledby="op-strip-title">
  <div class="op-strip__head">
    <h2 id="op-strip-title" class="op-strip__title">Resumo operacional</h2>
    <p class="op-strip__lead">O essencial para o próximo passo. Os detalhes ficam em “Mais detalhes”.</p>
  </div>
  <dl class="op-strip__grid">
    <div><dt>Próximo / atual</dt><dd data-testid="op-strip-next"><?= $e($nextLabel) ?></dd></div>
    <div><dt>Último atendimento</dt><dd data-testid="op-strip-last"><?= $e($lastLabel) ?></dd></div>
    <div><dt>Pendências</dt><dd data-testid="op-strip-pending"><?= $e($pendingLabel) ?></dd></div>
    <div><dt>Alergias</dt><dd data-testid="op-strip-allergy"><?= $e($allergyLabel . $allergyExtra) ?></dd></div>
    <div><dt>Plano vigente</dt><dd data-testid="op-strip-plan"><?= $e($planLabel) ?></dd></div>
    <div class="op-strip__wide"><dt>Última evolução</dt><dd data-testid="op-strip-evolution"><?php if ($lastEvolution): /* v54.77: texto, não link — a aba Atendimento abre o prontuário */ ?><strong><?= $e($lastEvolution['title'] ?? 'Registro') ?></strong> · <?= $e(date('d/m/Y', strtotime((string)$lastEvolution['created_at']))) ?><?php if ($evolutionText !== ''): ?><span class="op-strip__excerpt"><?= $e($evolutionText) ?></span><?php endif; ?><?php else: ?>Nenhuma evolução registrada<?php endif; ?></dd></div>
  </dl>
  <?php if ($priorityActions): ?>
  <div class="op-strip__actions" data-testid="op-strip-actions">
    <span class="op-strip__actions-label">Ações</span>
    <?php foreach ($priorityActions as $act): ?>
      <a class="btn btn-secondary btn-sm" href="<?= $e($act['href']) ?>"<?= !empty($act['testid']) ? ' data-testid="' . $e($act['testid']) . '"' : '' ?>><?= $e($act['label']) ?></a>
    <?php endforeach; ?>
  </div>
  <?php endif; ?>
</section>
