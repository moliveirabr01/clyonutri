<?php
/** @var array $doc @var int $patientId */
$e = static fn($v) => htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
$p = $doc['patient'];
$pr = $doc['printer'];
$fmtDate = static fn(?string $d) => $d ? date('d/m/Y', strtotime($d)) : '';
$num = static fn($v, int $dec = 1) => ($v === null || $v === '') ? '—' : number_format((float)$v, $dec, ',', '.');
$genders = ['female' => 'Feminino', 'male' => 'Masculino', 'F' => 'Feminino', 'M' => 'Masculino', 'other' => 'Outro'];
$period = $doc['period']['from'] || $doc['period']['to']
    ? 'de ' . ($doc['period']['from'] ? $fmtDate($doc['period']['from']) : 'início') . ' até ' . ($doc['period']['to'] ? $fmtDate($doc['period']['to']) : 'hoje')
    : 'todo o histórico';
?><!DOCTYPE html>
<html lang="pt-BR">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <meta name="robots" content="noindex">
  <title>Prontuário — <?= $e($p['name']) ?></title>
  <link rel="stylesheet" href="/assets/css/prontuario-impressao.css?v=botanical-v54-9">
<link rel="stylesheet" href="/assets/css/operational-ui.css?v=v54-66">
</head>
<body>
<div class="pi-toolbar" role="region" aria-label="Opções de impressão">
  <form method="GET" action="/pacientes/<?= $patientId ?>/prontuario/imprimir" class="pi-options">
    <label>De <input type="date" name="de" value="<?= $e($doc['period']['from']) ?>"></label>
    <label>Até <input type="date" name="ate" value="<?= $e($doc['period']['to']) ?>"></label>
    <label class="pi-check"><input type="checkbox" name="historico" value="1" <?= $doc['include_history'] ? 'checked' : '' ?>> Incluir versões substituídas por retificação</label>
    <button type="submit" class="pi-btn">Atualizar</button>
  </form>
  <div class="pi-actions">
    <a class="pi-btn" href="/pacientes/<?= $patientId ?>/prontuario">Voltar ao prontuário</a>
    <button type="button" class="pi-btn pi-btn-primary" id="pi-print">Imprimir ou salvar em PDF</button>
  </div>
</div>

<main class="pi-page">
  <header class="pi-header">
    <div>
      <div class="pi-clinic"><?= $e($doc['clinic']['name'] ?? '') ?></div>
      <h1>Prontuário nutricional</h1>
    </div>
    <div class="pi-meta">
      <div><strong>Período:</strong> <?= $e($period) ?></div>
      <div><strong>Emitido em:</strong> <?= $e($doc['printed_at_local']) ?></div>
    </div>
  </header>

  <?php if (!$doc['integrity']['is_valid']): ?>
  <div class="op-message op-message--error pi-alert" role="alert">
    <strong>Atenção:</strong> a verificação de integridade do prontuário não foi confirmada. Este documento pode não refletir o histórico original. Comunique o suporte antes de usá-lo como documento oficial.
  </div>
  <?php endif; ?>

  <section class="pi-section">
    <h2>Identificação do paciente</h2>
    <dl class="pi-grid">
      <div><dt>Nome</dt><dd><?= $e($p['name']) ?></dd></div>
      <div><dt>Nascimento</dt><dd><?= $p['birth_date'] ? $e($fmtDate($p['birth_date'])) . ($p['age'] !== null ? ' (' . (int)$p['age'] . ' anos)' : '') : '—' ?></dd></div>
      <div><dt>Sexo</dt><dd><?= $e($genders[$p['gender'] ?? ''] ?? ($p['gender'] ?: '—')) ?></dd></div>
      <div><dt>Contato</dt><dd><?= $e(implode(' · ', array_filter([(string)($p['phone'] ?? ''), (string)($p['email'] ?? '')])) ?: '—') ?></dd></div>
    </dl>
  </section>

  <section class="pi-section">
    <h2>Alertas clínicos da anamnese</h2>
    <?php if (!$doc['summary']['has_data']): ?>
      <p class="pi-muted">Nenhuma anamnese registrada.</p>
    <?php else: ?>
      <dl class="pi-grid">
        <div><dt>Alergias alimentares</dt><dd><?= $e($doc['summary']['allergy_label']) ?><?= $doc['summary']['allergies'] ? ': ' . $e($doc['summary']['allergies']) : '' ?></dd></div>
        <div><dt>Restrições e intolerâncias</dt><dd><?= $e(\ClyoNutri\Core\I18n\LabelMap::recorded($doc['summary']['restrictions'] ?? null)) ?></dd></div>
        <div class="pi-wide"><dt>Objetivos</dt><dd><?= $e(\ClyoNutri\Core\I18n\LabelMap::recorded($doc['summary']['objectives'] ?? null)) ?></dd></div>
      </dl>
      <?php if (!empty($doc['summary']['source'])): ?>
        <p class="pi-muted">Fonte: anamnese versão <?= (int)$doc['summary']['source']['version'] ?>, por <?= $e($doc['summary']['source']['author']) ?>, em <?= $e($fmtDate($doc['summary']['source']['recorded_at'])) ?>.</p>
      <?php endif; ?>
    <?php endif; ?>
  </section>

  <section class="pi-section">
    <h2>Antropometria</h2>
    <?php if (!$doc['anthropometry']): ?>
      <p class="pi-muted">Nenhuma avaliação no período.</p>
    <?php else: ?>
      <table class="pi-table">
        <thead><tr><th scope="col">Data</th><th scope="col">Peso (kg)</th><th scope="col">Altura (cm)</th><th scope="col">IMC</th><th scope="col">Cintura (cm)</th><th scope="col">Quadril (cm)</th><th scope="col">RCQ</th><th scope="col">Gordura (%)</th><th scope="col">Massa muscular (%)</th></tr></thead>
        <tbody>
        <?php foreach ($doc['anthropometry'] as $a): ?>
          <tr>
            <td><?= $e($fmtDate($a['assessed_at'])) ?></td>
            <td><?= $num($a['weight_kg']) ?></td><td><?= $num($a['height_cm']) ?></td><td><?= $num($a['bmi']) ?></td>
            <td><?= $num($a['waist_cm']) ?></td><td><?= $num($a['hip_cm']) ?></td><td><?= $num($a['whr'], 2) ?></td>
            <td><?= $num($a['body_fat_pct']) ?></td><td><?= $num($a['muscle_mass_pct']) ?></td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    <?php endif; ?>
  </section>

  <section class="pi-section">
    <h2>Evolução clínica</h2>
    <?php if (!$doc['entries']): ?>
      <p class="pi-muted">Nenhum registro no período.</p>
    <?php endif; ?>
    <?php foreach ($doc['entries'] as $r): ?>
      <article class="pi-entry<?= $r['superseded_by_version'] ? ' pi-superseded' : '' ?>">
        <header>
          <h3><?= $e($r['title']) ?></h3>
          <div class="pi-muted"><?= $e($r['created_at_local']) ?> · <?= $e($r['author']) ?> · versão <?= (int)$r['version'] ?><?= $r['via_teleconsultation'] ? ' · registrada durante a teleconsulta' : '' ?></div>
        </header>
        <?php if ($r['superseded_by_version']): ?>
          <p class="pi-tag">Substituído pela versão <?= (int)$r['superseded_by_version'] ?> (retificação). Mantido para histórico.</p>
        <?php endif; ?>
        <?php if ($r['is_revision']): ?>
          <p class="pi-tag">Retificação<?= $r['revision_reason'] ? ' — motivo: ' . $e($r['revision_reason']) : '' ?></p>
        <?php endif; ?>
        <?php if ($r['is_retracted'] && !$r['superseded_by_version']): ?><p class="pi-tag">Registro anulado.</p><?php endif; ?>
        <dl class="pi-fields">
          <?php foreach ($r['fields'] as $f): ?>
            <dt><?= $e($f['label']) ?></dt><dd><?= nl2br($e($f['value'])) ?></dd>
          <?php endforeach; ?>
        </dl>
      </article>
    <?php endforeach; ?>
  </section>

  <section class="pi-section pi-sign">
    <div class="pi-sign-line"></div>
    <div><?= $e($pr['name']) ?><?= !empty($pr['crn']) ? ' — ' . $e($pr['crn']) . (!empty($pr['crn_region']) && !str_contains((string)$pr['crn'], (string)$pr['crn_region']) ? ' (' . $e($pr['crn_region']) . ')' : '') : '' ?></div>
    <div class="pi-muted">Nutricionista responsável pela emissão</div>
  </section>

  <footer class="pi-footer">
    Documento confidencial, com dados de saúde protegidos pela LGPD. Uso restrito ao cuidado do paciente.
    Emitido pelo Clyo Nutri por <?= $e($pr['name']) ?> em <?= $e($doc['printed_at_local']) ?> (<?= $e($doc['timezone']) ?>).
    <?php if ($doc['integrity']['is_valid'] && $doc['integrity']['chain_fingerprint']): ?>
      Integridade verificada: <?= (int)$doc['integrity']['total_records'] ?> versões; identificador da cadeia <?= $e($doc['integrity']['chain_fingerprint']) ?>.
    <?php endif; ?>
  </footer>
</main>
<script src="/assets/js/prontuario-impressao.js"></script>
</body>
</html>
