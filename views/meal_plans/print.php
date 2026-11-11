<?php
/** @var array $doc */
$e = static fn($v) => htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
$plan = $doc['plan'];
$a = $doc['author'];
$n = static fn(float $v, int $d = 0) => number_format($v, $d, ',', '.');
?><!DOCTYPE html>
<html lang="pt-BR">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <meta name="robots" content="noindex">
  <title>Plano alimentar — <?= $e($doc['patient']['name'] ?? 'paciente a definir') ?></title>
  <link rel="stylesheet" href="/assets/css/prontuario-impressao.css?v=botanical-v54-9">
  <link rel="stylesheet" href="/assets/css/plano-impressao.css?v=botanical-v54-9">
<link rel="stylesheet" href="/assets/css/operational-ui.css?v=v54-30">
</head>
<body>
<div class="pi-toolbar" role="region" aria-label="Opções de impressão">
  <form method="GET" action="/planos/<?= (int)$plan['id'] ?>/imprimir" class="pi-options">
    <label class="pi-check"><input type="checkbox" name="nutricional" value="1" <?= $doc['show_nutrition'] ? 'checked' : '' ?>> Mostrar calorias e macronutrientes</label>
    <button type="submit" class="pi-btn">Atualizar</button>
  </form>
  <div class="pi-actions">
    <a class="pi-btn" href="/planos/<?= (int)$plan['id'] ?>">Voltar ao plano</a>
    <button type="button" class="pi-btn pi-btn-primary" id="pi-print">Imprimir ou salvar em PDF</button>
  </div>
</div>

<main class="pi-page">
  <header class="pi-header">
    <div>
      <div class="pi-clinic"><?= $e($doc['clinic']['name'] ?? '') ?></div>
      <h1><?= $e($plan['title']) ?></h1>
      <div class="pi-muted">Plano alimentar de <strong><?= $e($doc['patient']['name'] ?? 'paciente a definir') ?></strong></div>
    </div>
    <div class="pi-meta">
      <?php if ($plan['is_draft']): ?>
        <div><strong>Situação:</strong> rascunho</div>
      <?php else: ?>
        <div><strong>Versão:</strong> <?= (int)$plan['version_number'] ?><?= $plan['status'] === 'superseded' ? ' (substituída)' : '' ?></div>
        <div><strong>Publicado em:</strong> <?= $e($plan['published_at_local']) ?></div>
      <?php endif; ?>
      <div><strong>Emitido em:</strong> <?= $e($doc['printed_at_local']) ?></div>
    </div>
  </header>

  <?php if ($plan['is_draft']): ?>
  <div class="op-message op-message--error pi-alert" role="alert"><strong>Rascunho — plano não publicado.</strong> Pode mudar antes da publicação; não entregue como versão final.</div>
  <?php elseif ($plan['status'] === 'superseded'): ?>
  <div class="op-message op-message--error pi-alert" role="alert"><strong>Versão substituída.</strong> Existe um plano mais recente para este paciente.</div>
  <?php endif; ?>

  <?php if ($plan['notes'] !== ''): ?>
  <section class="pi-section">
    <h2>Orientações gerais</h2>
    <p class="mp-text"><?= nl2br($e($plan['notes'])) ?></p>
  </section>
  <?php endif; ?>

  <?php if (!$doc['meals']): ?>
    <p class="pi-muted">Este plano ainda não tem refeições.</p>
  <?php endif; ?>

  <?php foreach ($doc['meals'] as $meal): ?>
  <section class="pi-section mp-meal">
    <h2><?= $meal['time'] ? '<span class="mp-time">' . $e($meal['time']) . '</span> ' : '' ?><?= $e($meal['name']) ?>
      <?php if ($doc['show_nutrition'] && $meal['totals']): ?><span class="mp-kcal"><?= $n((float)$meal['totals']['energy_kcal']) ?> kcal</span><?php endif; ?>
    </h2>
    <?php if ($meal['notes'] !== ''): ?><p class="mp-note"><?= nl2br($e($meal['notes'])) ?></p><?php endif; ?>
    <?php if (!$meal['items']): ?>
      <p class="pi-muted">Sem alimentos nesta refeição.</p>
    <?php else: ?>
    <ul class="mp-items">
      <?php foreach ($meal['items'] as $item): ?>
      <li>
        <div class="mp-line"><span class="mp-food"><?= $e($item['food']) ?></span><span class="mp-qty"><?= $e($item['quantity']) ?></span></div>
        <?php if ($item['notes'] !== ''): ?><div class="mp-note"><?= nl2br($e($item['notes'])) ?></div><?php endif; ?>
        <?php if ($item['substitutions']): ?>
        <div class="mp-subs">
          <span class="mp-subs-label">Pode substituir por:</span>
          <ul>
            <?php foreach ($item['substitutions'] as $s): ?>
            <li><?= $e($s['food']) ?><?= $s['quantity'] !== '' ? ' — ' . $e($s['quantity']) : '' ?><?= $s['notes'] !== '' ? ' (' . $e($s['notes']) . ')' : '' ?></li>
            <?php endforeach; ?>
          </ul>
        </div>
        <?php endif; ?>
      </li>
      <?php endforeach; ?>
    </ul>
    <?php endif; ?>
  </section>
  <?php endforeach; ?>

  <?php if ($doc['show_nutrition']): $t = $plan['totals']; ?>
  <section class="pi-section">
    <h2>Valores diários estimados</h2>
    <table class="pi-table">
      <thead><tr><th scope="col">Energia</th><th scope="col">Proteínas</th><th scope="col">Carboidratos</th><th scope="col">Lipídios</th><th scope="col">Fibras</th></tr></thead>
      <tbody><tr>
        <td><?= $n($t['energy_kcal']) ?> kcal</td><td><?= $n($t['protein_g'], 1) ?> g</td><td><?= $n($t['carbohydrate_g'], 1) ?> g</td><td><?= $n($t['lipid_g'], 1) ?> g</td><td><?= $n($t['fiber_g'], 1) ?> g</td>
      </tr></tbody>
    </table>
    <p class="pi-muted">Valores calculados a partir da base de alimentos; podem variar conforme marca e preparo.</p>
  </section>
  <?php endif; ?>

  <section class="pi-section pi-sign">
    <div class="pi-sign-line"></div>
    <div><?= $e($a['name']) ?><?= !empty($a['crn']) ? ' — ' . $e($a['crn']) . (!empty($a['crn_region']) && !str_contains((string)$a['crn'], (string)$a['crn_region']) ? ' (' . $e($a['crn_region']) . ')' : '') : '' ?></div>
    <div class="pi-muted">Nutricionista responsável</div>
  </section>

  <footer class="pi-footer">
    Plano individual, elaborado para <?= $e($doc['patient']['name'] ?? 'um paciente ainda não definido') ?>. Não siga este plano sem orientação do(a) seu(sua) nutricionista e não o compartilhe.
    Emitido pelo Clyo Nutri por <?= $e($doc['printer']['name']) ?> em <?= $e($doc['printed_at_local']) ?>.
  </footer>
</main>
<script src="/assets/js/prontuario-impressao.js"></script>
</body>
</html>
