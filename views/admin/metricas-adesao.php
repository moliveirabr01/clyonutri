<?php
/** @var array $report NI2-96 — AdherenceAnalytics::report() */
$e = static fn($v) => htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
$n = static fn(?int $v) => $v === null ? 'menos de ' . (int)$report['k_min'] : number_format($v, 0, ',', '.');
$d = static fn(string $ymd) => date('d/m/Y', strtotime($ymd));
$metrics = \ClyoNutri\Domain\Analytics\AdherenceAnalytics::METRICS;
$labels = \ClyoNutri\Domain\Analytics\AdherenceAnalytics::LABELS;
?>
<style>
  .am-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(220px, 1fr)); gap: 1rem; margin-bottom: 1.25rem; }
  .am-kpi { background: #fff; border: 1px solid var(--admin-gray-200); border-radius: 8px; padding: 1rem 1.25rem; }
  .am-kpi h3 { margin: 0 0 .35rem; font-size: .8rem; font-weight: 600; color: var(--admin-gray-600); }
  .am-kpi strong { display: block; font-size: 1.6rem; color: var(--admin-gray-900); font-variant-numeric: tabular-nums; }
  .am-kpi p { margin: .35rem 0 0; font-size: .8rem; color: var(--admin-gray-600); }
  .am-periods { display: flex; gap: .5rem; flex-wrap: wrap; margin-bottom: 1rem; }
  .am-note { font-size: .82rem; color: var(--admin-gray-700); background: var(--admin-gray-50); border: 1px solid var(--admin-gray-200); border-radius: 6px; padding: .75rem 1rem; margin-bottom: 1rem; }
  .am-num { text-align: right; font-variant-numeric: tabular-nums; }
</style>

<div class="admin-card" style="margin-bottom:1rem">
  <div class="admin-card__body">
    <p style="margin:0 0 .75rem;color:var(--admin-gray-700)">
      Uso do app pelas pacientes, somado por dia e por clínica: plano visto, check-in enviado e água registrada.
      Período: <strong data-testid="am-period"><?= $e($d($report['from'])) ?> a <?= $e($d($report['to'])) ?></strong>.
    </p>
    <nav class="am-periods" aria-label="Período">
      <?php foreach (\ClyoNutri\Domain\Analytics\AdherenceAnalytics::PERIODS as $p): ?>
        <a class="btn btn--sm <?= $p === (int)$report['period_days'] ? 'btn--primary' : 'btn--secondary' ?>" href="/admin/metricas-adesao?dias=<?= $p ?>" <?= $p === (int)$report['period_days'] ? 'aria-current="page"' : '' ?>>Últimos <?= $p ?> dias</a>
      <?php endforeach; ?>
    </nav>
    <?php if (!$report['enabled']): ?>
      <p class="op-message op-message--info am-note" role="status" data-testid="am-disabled"><strong>Coleta desligada</strong> em Configurações globais. Os números abaixo são de antes de desligar.</p>
    <?php endif; ?>
    <p class="am-note" data-testid="am-privacy">
      Sem nome, id de paciente, valores (ml, % de adesão, observações) ou conteúdo do plano.
      "Pacientes por dia" soma, dia a dia, quantas pacientes diferentes fizeram a ação; a mesma paciente em dois dias conta duas vezes.
      Números abaixo de <?= (int)$report['k_min'] ?> aparecem como "menos de <?= (int)$report['k_min'] ?>".
    </p>
  </div>
</div>

<div class="am-grid">
  <div class="am-kpi">
    <h3>Pacientes com acesso ao app</h3>
    <strong data-testid="am-app-patients"><?= $e($n($report['app_patients'])) ?></strong>
    <p>Ativas, com senha do app criada (hoje)</p>
  </div>
  <?php foreach ($metrics as $m): $t = $report['totals'][$m]; ?>
    <div class="am-kpi" data-testid="am-kpi-<?= $e($m) ?>">
      <h3><?= $e($labels[$m]) ?></h3>
      <strong><?= $e($t['avg_patients_per_day'] === null ? 'menos de ' . (int)$report['k_min'] : number_format($t['avg_patients_per_day'], 1, ',', '.')) ?></strong>
      <p>pacientes por dia, em média · <?= $e($n($t['patient_days'])) ?> no período · <?= $e($n($t['events'])) ?> registros · <?= (int)$t['clinics'] ?> clínica(s)</p>
    </div>
  <?php endforeach; ?>
</div>

<div class="admin-card" style="margin-bottom:1rem">
  <div class="admin-card__header"><h2 class="admin-card__title">Por semana (pacientes por dia, somados)</h2></div>
  <div class="admin-card__body">
    <?php if (!$report['weeks']): ?>
      <p data-testid="am-empty">Nenhum registro no período.</p>
    <?php else: ?>
      <div class="admin-table-wrapper"><table class="admin-table" data-testid="am-weeks">
        <thead><tr><th scope="col">Semana de</th><?php foreach ($metrics as $m): ?><th scope="col" class="am-num"><?= $e($labels[$m]) ?></th><?php endforeach; ?></tr></thead>
        <tbody>
          <?php foreach ($report['weeks'] as $w): ?>
            <tr><td><?= $e($d($w['week_start'])) ?></td><?php foreach ($metrics as $m): ?><td class="am-num"><?= $e($n($w[$m])) ?></td><?php endforeach; ?></tr>
          <?php endforeach; ?>
        </tbody>
      </table></div>
    <?php endif; ?>
  </div>
</div>

<div class="admin-card">
  <div class="admin-card__header"><h2 class="admin-card__title">Por clínica</h2></div>
  <div class="admin-card__body">
    <?php if (!$report['clinics']): ?>
      <p data-testid="am-clinics-empty">Nenhuma clínica com pelo menos <?= (int)$report['k_min'] ?> pacientes no app teve registros no período.</p>
    <?php else: ?>
      <div class="admin-table-wrapper"><table class="admin-table" data-testid="am-clinics">
        <thead><tr><th scope="col">Clínica</th><th scope="col" class="am-num">Pacientes no app</th><?php foreach ($metrics as $m): ?><th scope="col" class="am-num"><?= $e($labels[$m]) ?> (pacientes por dia, somados)</th><?php endforeach; ?></tr></thead>
        <tbody>
          <?php foreach ($report['clinics'] as $c): ?>
            <tr><td><?= $e($c['clinic']) ?></td><td class="am-num"><?= (int)$c['app_patients'] ?></td><?php foreach ($metrics as $m): ?><td class="am-num"><?= $e($n($c[$m])) ?></td><?php endforeach; ?></tr>
          <?php endforeach; ?>
        </tbody>
      </table></div>
    <?php endif; ?>
    <?php if ($report['clinics_hidden'] > 0): ?>
      <p style="margin-top:.75rem;font-size:.82rem;color:var(--admin-gray-600)" data-testid="am-clinics-hidden"><?= (int)$report['clinics_hidden'] ?> clínica(s) com menos de <?= (int)$report['k_min'] ?> pacientes no app não aparecem separadas (entram só nos totais).</p>
    <?php endif; ?>
  </div>
</div>
