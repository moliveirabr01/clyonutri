<?php
/**
 * NI2-92: hidratação registrada pela paciente no app — visão do profissional (somente leitura).
 * @var ?array $appHydration resultado de HydrationService::professionalSummary()
 * @var int $pid
 */
$h = static fn($v) => htmlspecialchars((string)($v ?? ''), ENT_QUOTES, 'UTF-8');
$ml = static fn(int $v) => number_format($v, 0, ',', '.') . ' ml';
?>
<section class="card u-mt-6" aria-labelledby="app-hidratacao-titulo" id="app-hidratacao" data-testid="app-hydration">
<h2 id="app-hidratacao-titulo">Hidratação registrada no app</h2>
<?php if ($appHydration === null): ?>
  <p class="op-message op-message--info" role="status">Não foi possível carregar a hidratação agora. Atualize a página para tentar de novo.</p>
<?php else: ?>
  <p class="u-color-neutral-gray-500">
    Registros da própria paciente, por dia (fuso <?= $h($appHydration['timezone']) ?>), nos últimos <?= (int)$appHydration['period_days'] ?> dias.
    <?php if ($appHydration['goal_ml'] !== null): ?>
      Meta prescrita na anamnese: <strong><?= $h($ml((int)$appHydration['goal_ml'])) ?>/dia</strong>.
    <?php else: ?>
      Nenhuma meta hídrica na anamnese finalizada — defina em <a href="/pacientes/<?= (int)$pid ?>/anamnese">Anamnese</a>, campo "Meta Hídrica".
    <?php endif; ?>
  </p>
  <?php if ($appHydration['days_with_records'] === 0): ?>
    <p data-testid="app-hydration-empty">Nenhum registro de água no período.</p>
  <?php else: ?>
    <p data-testid="app-hydration-summary">
      Dias com registro: <strong><?= (int)$appHydration['days_with_records'] ?></strong> de <?= (int)$appHydration['period_days'] ?>
      · Média nesses dias: <strong><?= $h($ml((int)$appHydration['average_ml_on_recorded_days'])) ?></strong>
      <?php if ($appHydration['days_goal_reached'] !== null): ?> · Meta atingida em <strong><?= (int)$appHydration['days_goal_reached'] ?></strong> dia(s)<?php endif; ?>
      <?php if ($appHydration['last_logged_at']): ?> · Último registro: <?= $h(date('d/m/Y H:i', strtotime($appHydration['last_logged_at']))) ?><?php endif; ?>
    </p>
    <div class="table-responsive"><table class="table" data-testid="app-hydration-days">
      <caption class="sr-only">Total de água por dia, mais recente primeiro</caption>
      <thead><tr><th scope="col">Dia</th><th scope="col">Total</th><th scope="col">Registros</th><?php if ($appHydration['goal_ml'] !== null): ?><th scope="col">Meta</th><?php endif; ?></tr></thead>
      <tbody>
      <?php foreach ($appHydration['days'] as $d): ?>
        <tr>
          <td><?= $h(date('d/m', strtotime($d['date']))) ?></td>
          <td><?= $d['entries_count'] ? $h($ml((int)$d['total_ml'])) : '—' ?></td>
          <td><?= (int)$d['entries_count'] ?></td>
          <?php if ($appHydration['goal_ml'] !== null): ?>
            <td><?= $d['goal_reached'] === null ? 'Sem registro' : ($d['goal_reached'] ? 'Atingida' : 'Abaixo da meta') ?></td>
          <?php endif; ?>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table></div>
  <?php endif; ?>
<?php endif; ?>
</section>
