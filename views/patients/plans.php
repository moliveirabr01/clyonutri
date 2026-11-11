<?php
/** NI2-711: aba Plano com rota própria. Entradas: $p, $profile, $csrfToken, $careStep, $clinicalSummary, $activePlan */
$e = static fn($v): string => htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
$pid = (int)$p['id'];
$plans = $profile['meal_plans'] ?? [];
?>
<section class="card" id="secao-planos" data-testid="plans-page" aria-labelledby="plans-title">
  <div class="card__header u-flex u-justify-between u-items-center u-mb-3">
    <h2 id="plans-title" class="u-m-0">Planos alimentares (<?= count($plans) ?>)</h2>
    <div class="u-flex u-gap-2 u-wrap">
      <?php
        // v54.57 (UX-115): entrada visível para a comparação quando há 2+ versões publicadas
        $publishedCount = 0;
        try { $publishedCount = count((new \ClyoNutri\Domain\MealPlan\MealPlanVersionDiffService(\ClyoNutri\Core\Database\Connection::getInstance()))->listPublishedVersions($pid)); } catch (\Throwable $x) { $publishedCount = 0; }
      ?>
      <?php if ($publishedCount >= 2): ?>
        <a href="/pacientes/<?= $pid ?>/planos/comparar" class="btn btn-outline u-text-compact u-py-1 u-px-2" data-testid="plans-compare-link">Comparar com a versão anterior</a>
      <?php endif; ?>
      <button type="button" class="btn btn-secondary u-text-compact u-py-1 u-px-2" data-show="modal-new-plan-tab">+ Novo Plano</button>
    </div>
  </div>
  <?php if (!$plans): ?>
    <p class="u-color-neutral-gray-500">Nenhum plano alimentar prescrito ainda para este paciente.</p>
  <?php else: ?>
    <div class="u-flex u-flex-col u-gap-2">
      <?php foreach ($plans as $mp): ?>
        <div class="u-flex u-justify-between u-items-center u-py-2 u-px-3 u-bg-neutral-gray-50 u-border u-radius-input">
          <div>
            <strong class="u-color-botanical-forest-900"><?= $e($mp['title']) ?></strong>
            <span class="u-text-caption u-color-neutral-gray-500 u-ml-1">v<?= (int)$mp['version'] ?></span>
            <span class="u-ml-1"><?= \ClyoNutri\Core\I18n\LabelMap::mealPlanStatusBadge($mp['status']) ?></span>
          </div>
          <a href="/planos/<?= (int)$mp['id'] ?>" class="btn btn-sm btn-outline">Abrir editor →</a>
        </div>
      <?php endforeach; ?>
    </div>
  <?php endif; ?>
</section>
<div id="modal-new-plan-tab" data-dialog="modal" class="modal-overlay">
  <div class="cn-dialog__panel u-bg-neutral-white u-radius-card u-w-full u-maxw-form u-p-6 u-shadow-lg">
    <h3 class="u-text-h3 u-font-bold u-color-botanical-forest-900 u-mt-0 u-mb-2">Nova prescrição alimentar</h3>
    <p class="u-color-neutral-gray-500 u-text-compact u-mb-4">Paciente: <strong><?= $e($p['name']) ?></strong></p>
    <form method="POST" action="/planos/novo">
      <input type="hidden" name="_csrf_token" value="<?= $e($csrfToken) ?>">
      <input type="hidden" name="patient_id" value="<?= $pid ?>">
      <div class="u-mb-4">
        <label for="plan-tab-title" class="u-block u-font-semibold u-mb-1 u-text-compact">Título do Plano *</label>
        <input id="plan-tab-title" type="text" name="title" required placeholder="Ex: Plano Alimentar Fase 1" class="u-w-full u-py-2 u-px-3 u-border u-bc-border-input u-radius-input">
      </div>
      <div class="u-mb-5">
        <label for="plan-tab-notes" class="u-block u-font-semibold u-mb-1 u-text-compact">Orientações iniciais (opcional)</label>
        <textarea id="plan-tab-notes" name="notes" rows="3" class="u-w-full u-py-2 u-px-3 u-border u-bc-border-input u-radius-input"></textarea>
      </div>
      <div class="u-flex u-justify-end u-gap-2">
        <button type="button" data-dialog-close class="btn btn-outline" data-hide="modal-new-plan-tab">Cancelar</button>
        <button type="submit" class="btn btn-primary">Abrir editor</button>
      </div>
    </form>
  </div>
</div>
