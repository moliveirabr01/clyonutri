<?php
/** @var array $appEvolution  @var int $pid  @var string $csrfToken  @var ?array $appEvolutionFlash  @var bool $canEditAppEvolution */
$e = static fn($v) => htmlspecialchars((string)($v ?? ''), ENT_QUOTES, 'UTF-8');
$fmtDate = static function ($d, bool $time = true) { $t = $d ? strtotime((string)$d) : false; return $t ? date($time ? 'd/m/Y H:i' : 'd/m/Y', $t) : '—'; };
$num = static fn($v) => rtrim(rtrim(number_format((float)$v, 1, ',', '.'), '0'), ',');
$statusLabels = ['active' => 'Ativa', 'achieved' => 'Atingida', 'cancelled' => 'Encerrada'];
?>
<section class="card u-mt-6" aria-labelledby="app-evolucao-titulo" id="app-evolucao" data-testid="app-evolution">
<h2 id="app-evolucao-titulo">Evolução no app da paciente</h2>
<?php if ($appEvolutionFlash): ?><p role="<?= $appEvolutionFlash['type'] === 'error' ? 'alert' : 'status' ?>" data-testid="app-evolution-flash"><strong><?= $e($appEvolutionFlash['message']) ?></strong></p><?php endif; ?>

<?php $vis = (array)($appEvolution['app_visibility'] ?? []); ?>
<div class="u-bg-neutral-gray-50 u-border u-radius-input u-p-4 u-mb-4" data-testid="app-evolution-visibility">
  <strong>O que o paciente vê no app:</strong>
  <ul class="u-m-0 u-mt-2 u-pl-4 u-text-compact">
    <li>Autorrelato no app: <strong><?= !empty($vis['has_enabled_metrics']) ? 'Liberado' : 'Nenhuma medida liberada' ?></strong></li>
    <li>Avaliações compartilhadas: <strong><?= (int)($vis['shared_assessments_count'] ?? 0) ?> de <?= (int)($vis['total_assessments_count'] ?? 0) ?></strong></li>
    <li>Metas ativas: <strong><?= (int)($vis['active_goals_count'] ?? 0) ?></strong></li>
  </ul>
  <?php if ($canEditAppEvolution && !empty($vis['has_missing_release'])): ?>
    <form method="post" action="/pacientes/<?= $pid ?>/evolucao/liberar" class="u-mt-3 u-mb-0">
      <input type="hidden" name="_csrf_token" value="<?= $e($csrfToken) ?>">
      <button type="submit" class="btn btn-primary btn-sm" data-testid="btn-release-evolution">Liberar evolução</button>
    </form>
  <?php endif; ?>
</div>

<h3 class="u-mt-5">Medidas que a paciente pode registrar</h3>
<?php if ($canEditAppEvolution): ?>
<form method="post" action="/pacientes/<?= $pid ?>/autorrelatos/permissoes" data-testid="self-metric-permissions" class="cn-profile-form">
  <input type="hidden" name="_csrf_token" value="<?= $e($csrfToken) ?>">
  <fieldset class="cn-fieldset"><legend>Liberar no app</legend>
  <?php foreach ($appEvolution['catalog'] as $m): ?>
    <label class="cn-check-row"><input class="ds-check" type="checkbox" name="metrics[]" value="<?= $e($m['type']) ?>" <?= in_array($m['type'], $appEvolution['enabled'], true) ? 'checked' : '' ?>>
      <?= $e($m['label']) ?> (<?= $e($m['unit']) ?>, de <?= $e($num($m['min'])) ?> a <?= $e($num($m['max'])) ?>)</label>
  <?php endforeach; ?>
  </fieldset>
  <button type="submit" class="btn btn-secondary">Salvar medidas liberadas</button>
</form>
<?php else: ?>
<p><?= $appEvolution['enabled'] ? $e(implode(', ', array_map(fn($t) => \ClyoNutri\Domain\Evolution\SelfMetricCatalog::label($t), $appEvolution['enabled']))) : 'Nenhuma medida liberada.' ?></p>
<?php endif; ?>

<h3 class="u-mt-5">Autorrelatos recentes</h3>
<p>Registrados pela própria paciente no app. Não são medições clínicas.</p>
<?php if (!$appEvolution['self_reports']): ?>
<p data-testid="self-reports-empty">Nenhum autorrelato registrado.</p>
<?php else: ?>
<div class="table-responsive"><table class="table" data-testid="self-reports">
  <caption class="sr-only">Autorrelatos da paciente, mais recentes primeiro</caption>
  <thead><tr><th scope="col">Data</th><th scope="col">Medida</th><th scope="col">Valor</th><th scope="col">Observação da paciente</th></tr></thead>
  <tbody>
  <?php foreach ($appEvolution['self_reports'] as $r): ?>
    <tr><td><?= $e($fmtDate($r['recorded_at'])) ?></td><td><?= $e($r['label']) ?></td><td><?= $e($num($r['value'])) ?> <?= $e($r['unit']) ?></td><td><?= $e($r['notes'] ?? '—') ?></td></tr>
  <?php endforeach; ?>
  </tbody>
</table></div>
<?php endif; ?>

<h3 class="u-mt-5">Metas</h3>
<?php if (!$appEvolution['goals']): ?><p>Nenhuma meta definida.</p><?php endif; ?>
<?php foreach ($appEvolution['goals'] as $g): ?>
  <div class="u-border-t u-py-2 u-px-0" data-goal-id="<?= (int)$g['id'] ?>">
    <strong><?= $e($g['direction_label']) ?> <?= $e(mb_strtolower($g['label'])) ?>: <?= $e($num($g['target_value'])) ?> <?= $e($g['unit']) ?></strong>
    <?= $g['target_date'] ? ' até ' . $e($fmtDate($g['target_date'], false)) : '' ?> · <?= $e($statusLabels[$g['status']] ?? $g['status']) ?>
    <?php if ($g['message_for_patient']): ?><br><small>Mensagem para a paciente: <?= $e($g['message_for_patient']) ?></small><?php endif; ?>
    <br><small>Última avaliação clínica: <?= $g['latest_clinical'] ? $e($num($g['latest_clinical']['value'])) . ' ' . $e($g['unit']) . ' em ' . $e($fmtDate($g['latest_clinical']['date'], false)) : '—' ?>
    · Último autorrelato: <?= $g['latest_self_report'] ? $e($num($g['latest_self_report']['value'])) . ' ' . $e($g['unit']) . ' em ' . $e($fmtDate($g['latest_self_report']['date'], false)) : '—' ?></small>
    <?php if ($canEditAppEvolution && $g['status'] === 'active'): ?>
      <form method="post" action="/pacientes/<?= $pid ?>/metas/<?= (int)$g['id'] ?>/encerrar" class="u-inline-flex u-gap-2 u-mt-1">
        <input type="hidden" name="_csrf_token" value="<?= $e($csrfToken) ?>">
        <button type="submit" name="status" value="achieved" class="btn btn-secondary">Marcar como atingida</button>
        <button type="submit" name="status" value="cancelled" class="btn btn-secondary">Encerrar meta</button>
      </form>
    <?php endif; ?>
  </div>
<?php endforeach; ?>

<?php if ($canEditAppEvolution): ?>
<form method="post" action="/pacientes/<?= $pid ?>/metas" data-testid="goal-form" class="cn-profile-form u-mt-5">
  <input type="hidden" name="_csrf_token" value="<?= $e($csrfToken) ?>">
  <fieldset class="cn-fieldset"><legend>Nova meta</legend>
  <label for="goal-metric" class="form-label">Medida</label>
  <select id="goal-metric" name="metric_type" class="form-control" required>
    <?php foreach ($appEvolution['catalog'] as $m): ?><option value="<?= $e($m['type']) ?>"><?= $e($m['label']) ?> (<?= $e($m['unit']) ?>)</option><?php endforeach; ?>
  </select>
  <label for="goal-direction" class="form-label">Objetivo</label>
  <select id="goal-direction" name="direction" class="form-control" required>
    <?php foreach (\ClyoNutri\Domain\Evolution\PatientEvolutionService::DIRECTIONS as $k => $label): ?><option value="<?= $e($k) ?>"><?= $e($label) ?></option><?php endforeach; ?>
  </select>
  <label for="goal-value" class="form-label">Valor da meta</label>
  <input id="goal-value" name="target_value" class="form-control" inputmode="decimal" required>
  <label for="goal-date" class="form-label">Data (opcional)</label>
  <input id="goal-date" name="target_date" type="date" class="form-control">
  <label for="goal-message" class="form-label">Mensagem para a paciente (opcional)</label>
  <textarea id="goal-message" name="message_for_patient" class="form-control" maxlength="500" rows="2"></textarea>
  <label class="cn-check-row"><input class="ds-check" type="checkbox" name="allow_self_report" value="1" checked> Permitir que o paciente registre esta medida pelo app</label>
  <label class="cn-check-row"><input class="ds-check" type="checkbox" name="share_clinical_assessments" value="1" checked> Mostrar ao paciente as avaliações já registradas desta medida</label>
  <p class="cn-profile-note">A paciente vê a meta e os registros mais recentes. O app não calcula "quanto falta" nem sugere mudanças no tratamento.</p>
  <button type="submit" class="btn btn-secondary">Criar meta</button>
  </fieldset>
</form>
<?php endif; ?>
</section>
