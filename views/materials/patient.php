<?php
/** Área do paciente: materiais enviados pela clínica. Entradas: $materials. */
use ClyoNutri\Domain\Materials\MaterialService as M;
$e = static fn($v): string => htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
?><!doctype html><html lang="pt-BR"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Meus materiais — Clyo Nutri</title>
<link rel="stylesheet" href="/assets/css/materials.css?v=v54-21"></head>
<body class="mat-patient"><main class="mat" style="max-width: 760px; margin: 0 auto; padding: 24px 16px;">
<p style="margin: 0;"><a href="/app">← Área do paciente</a></p>
<div class="mat-head"><div><h1>Meus materiais</h1><p>Orientações, receitas e listas que sua nutricionista enviou para você.</p></div></div>
<?php if (!$materials): ?>
  <div class="mat-card mat-empty" data-testid="patient-materials-empty">Sua nutricionista ainda não enviou materiais.</div>
<?php else: ?>
<div class="mat-grid" style="grid-template-columns: 1fr;" data-testid="patient-materials">
<?php foreach ($materials as $m): ?>
  <article class="mat-item" data-testid="patient-material" data-id="<?= (int)$m['id'] ?>">
    <div class="mat-meta"><span class="mat-tag"><?= $e($m['category_label']) ?></span><span class="mat-type"><?= $e(strtoupper(M::MIME[$m['mime_type']] ?? '')) ?> · <?= $e(M::humanSize((int)$m['size_bytes'])) ?></span><?= $m['first_opened_at'] ? '' : '<span class="mat-type" style="font-weight:700;">Novo</span>' ?></div>
    <h2 style="overflow-wrap: anywhere;"><?= $e($m['title']) ?></h2>
    <?php if (!empty($m['description'])): ?><p style="margin: 0; white-space: pre-line; overflow-wrap: anywhere;"><?= $e($m['description']) ?></p><?php endif; ?>
    <?php if (!empty($m['note'])): ?><p style="margin: 0; padding: 8px 12px; border-left: 3px solid #2f6b3a; background: #f3f7f2; overflow-wrap: anywhere;"><strong>Recado:</strong> <?= $e($m['note']) ?></p><?php endif; ?>
    <p class="mat-meta" style="margin: 0;">Recebido em <?= $e(date('d/m/Y', strtotime((string)$m['shared_at']))) ?></p>
    <div class="mat-actions">
      <a class="btn btn-secondary" href="/api/v1/patient/materials/<?= (int)$m['id'] ?>" target="_blank" rel="noopener">Abrir</a>
      <a class="btn btn-primary" href="/api/v1/patient/materials/<?= (int)$m['id'] ?>?baixar=1" data-testid="patient-material-download">Baixar</a>
    </div>
  </article>
<?php endforeach; ?>
</div>
<?php endif; ?>
</main></body></html>
