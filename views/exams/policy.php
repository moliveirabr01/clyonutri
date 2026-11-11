<?php
/**
 * Versões da política de solicitação de exames (v47, NI2-530). Somente leitura: nova versão entra pelo SQL da
 * release (nova policy_version com effective_from); as anteriores não mudam e valem para as solicitações que as gravaram.
 * @var array $versions
 */
$e = static fn($v) => htmlspecialchars((string)($v ?? ''), ENT_QUOTES, 'UTF-8');
$d = static fn($v) => $v ? date('d/m/Y', strtotime((string)$v)) : '';
?>
<link rel="stylesheet" href="/assets/css/exames.css?v=v54-30">
<div class="clinical-journey ex-page">
  <a class="ex-back" href="/exames/catalogo">← Catálogo de exames</a>
  <div class="ex-head">
    <div>
      <h1 class="ex-title">Política de solicitação de exames</h1>
      <p class="ex-sub">Cada solicitação grava a versão vigente quando é criada e emitida. Documentos emitidos continuam mostrando a versão com que saíram.</p>
    </div>
  </div>
  <?php if (!$versions): ?>
    <div class="card ex-empty"><p class="ex-muted">Nenhuma política cadastrada.</p></div>
  <?php endif; ?>
  <?php foreach ($versions as $v): ?>
    <section class="card ex-card" aria-labelledby="pol-<?= $e($v['policy_version']) ?>">
      <h2 id="pol-<?= $e($v['policy_version']) ?>">Versão <?= $e($v['policy_version']) ?>
        <?php if ($v['is_active']): ?><span class="badge badge-success">Vigente</span>
        <?php elseif (strtotime((string)$v['effective_from']) > time()): ?><span class="badge badge-warning">Futura</span>
        <?php else: ?><span class="badge badge-neutral">Anterior</span><?php endif; ?></h2>
      <dl class="ex-dl">
        <dt>Vigência a partir de</dt><dd><?= $e($d($v['effective_from'])) ?></dd>
        <dt>Solicitações desta clínica</dt><dd><?= (int)$v['requests_in_clinic'] ?></dd>
        <?php if (!empty($v['change_summary'])): ?><dt>O que mudou</dt><dd><?= $e($v['change_summary']) ?></dd><?php endif; ?>
        <dt>Base legal</dt><dd><?= $e($v['legal_basis']) ?></dd>
        <dt>Escopo</dt><dd><?= $e($v['statement']) ?></dd>
        <dt>Aviso de cobertura</dt><dd><?= $e(\ClyoNutri\Core\Brand::textoExibido((string)$v['disclaimer_coverage'])) ?></dd>
      </dl>
    </section>
  <?php endforeach; ?>
  <p class="ex-muted">A emissão não depende de aprovação registrada no sistema (decisão do responsável, v46). A revisão clínica e jurídica da política e do catálogo segue como pendência fora do sistema.</p>
</div>
