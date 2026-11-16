<?php
/** v54.57 (UX-102): plano alimentar publicado, em leitura, no portal web do paciente. Variáveis: $portal (portalData). */
$plan = $portal['plan'] ?? null;
$e = static fn($v): string => htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
$num = static function ($v): string { $f = (float)$v; return rtrim(rtrim(number_format($f, 1, ',', '.'), '0'), ','); };
$pub = '';
if (!empty($plan['published_at'])) {
    try { $pub = (new DateTimeImmutable((string)$plan['published_at']))->setTimezone(new DateTimeZone(date_default_timezone_get()))->format('d/m/Y \à\s H:i'); } catch (\Throwable $x) { $pub = ''; }
}
?><!DOCTYPE html>
<html lang="pt-BR">
<head>
  <meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Meu plano alimentar · Clyo Nutri</title>
  <link rel="stylesheet" href="/assets/css/fonts.css?v=v54-66">
  <link rel="stylesheet" href="/assets/css/clinical-botanical.css?v=botanical-v54-9">
  <link rel="stylesheet" href="/assets/css/patient-self-service.css?v=1">
  <link rel="stylesheet" href="/assets/css/patient-portal-v54-57.css?v=v54-57">
</head>
<body>
<header><a href="/paciente/app">← Portal do paciente</a><strong>Clyo Nutri</strong></header>
<main class="pp-plan" data-testid="portal-plano">
  <h1>Meu plano alimentar</h1>
  <?php if (!$plan): ?>
    <p>Seu nutricionista ainda não publicou um plano alimentar para você. Quando publicar, ele aparece aqui.</p>
  <?php else: ?>
    <p class="pp-muted"><?= $e($plan['title'] ?? 'Plano alimentar') ?> · versão <?= (int)($plan['version_number'] ?? 1) ?><?= $pub ? ' · publicada em ' . $e($pub) : '' ?><?= !empty($plan['professional_name']) ? ' · ' . $e($plan['professional_name']) : '' ?></p>
    <?php if (!empty($plan['daily_energy_kcal'])): ?><p>Energia prevista: <strong><?= $num($plan['daily_energy_kcal']) ?> kcal/dia</strong></p><?php endif; ?>
    <?php if (!empty($plan['notes'])): ?><p><?= nl2br($e($plan['notes'])) ?></p><?php endif; ?>
    <?php foreach ($plan['meals'] ?? [] as $m): ?>
      <section aria-label="<?= $e($m['name'] ?? 'Refeição') ?>">
        <h2><?= $e($m['name'] ?? 'Refeição') ?><?= !empty($m['time']) ? ' · ' . $e(substr((string)$m['time'], 0, 5)) : '' ?></h2>
        <?php if (empty($m['items'])): ?><p class="pp-sub">Sem itens nesta refeição.</p><?php else: ?>
        <ul>
          <?php foreach ($m['items'] as $it): ?>
            <li><?= $e($it['food_name'] ?? 'Alimento') ?> — <?= $num($it['quantity'] ?? 0) ?> <?= $e($it['unit'] ?? 'g') ?><?= !empty($it['weight_grams']) && ($it['unit'] ?? 'g') !== 'g' ? ' (' . $num($it['weight_grams']) . ' g)' : '' ?>
              <?php if (!empty($it['notes'])): ?><div class="pp-sub"><?= $e($it['notes']) ?></div><?php endif; ?>
              <?php if (!empty($it['substitutions'])): ?><div class="pp-sub">Pode trocar por: <?= $e(implode('; ', array_map(static fn($s) => ($s['food_name'] ?? '') . ' ' . rtrim(rtrim(number_format((float)($s['amount'] ?? 0), 1, ',', '.'), '0'), ',') . ' ' . ($s['measure_unit'] ?? 'g'), $it['substitutions']))) ?></div><?php endif; ?>
            </li>
          <?php endforeach; ?>
        </ul>
        <?php endif; ?>
        <?php if (!empty($m['notes'])): ?><p class="pp-sub"><?= $e($m['notes']) ?></p><?php endif; ?>
      </section>
    <?php endforeach; ?>
  <?php endif; ?>
</main>
</body>
</html>
