<?php
/** @var array $patient @var array $requests @var ?array $flash @var array $resultCounts */
$e = static fn($v) => htmlspecialchars((string)($v ?? ''), ENT_QUOTES, 'UTF-8');
$badge = ['draft' => 'badge-neutral', 'review' => 'badge-warning', 'issued' => 'badge-success', 'cancelled' => 'badge-danger'];
$labels = \ClyoNutri\Domain\LaboratoryExam\ExamRequestService::STATUS_LABELS;
$fmt = static fn($v) => $v ? date('d/m/Y H:i', strtotime((string)$v)) : '';
?>
<link rel="stylesheet" href="/assets/css/exames.css?v=v54-30">
<?= \ClyoNutri\Application\View\PatientHeader::render(\ClyoNutri\Core\Database\Connection::getInstance(), (int)$patient['id'], 'exames') // NI2-661 ?>
<div class="clinical-journey ex-page">
  <div class="ex-head">
    <div>
      <h2 class="ex-title patient-page-title">Solicitações de exames laboratoriais</h2>
    </div>
    <div class="ex-actions">
      <a href="/exames/catalogo" class="btn btn-outline">Catálogo e favoritos</a>
      <a href="/pacientes/<?= (int)$patient['id'] ?>/exames/novo" class="btn btn-primary">Nova solicitação de exames</a>
    </div>
  </div>

  <?php if ($flash): ?>
    <div class="ex-msg <?= $flash['type'] === 'error' ? 'ex-msg-error' : 'ex-msg-success' ?>" role="<?= $flash['type'] === 'error' ? 'alert' : 'status' ?>"><?= $e($flash['message']) ?></div>
  <?php endif; ?>

  <?php if (empty($requests)): ?>
    <div class="card ex-empty">
      <h2 class="section-title">Nenhuma solicitação de exames ainda</h2>
      <p class="ex-muted">Monte uma solicitação com exames do catálogo laboratorial e a justificativa nutricional.</p>
      <a href="/pacientes/<?= (int)$patient['id'] ?>/exames/novo" class="btn btn-secondary">Montar a primeira solicitação</a>
    </div>
  <?php else: ?>
    <div class="card table-responsive">
      <table class="table ex-table">
        <thead><tr><th>Solicitação</th><th>Data</th><th>Nutricionista</th><th>Exames</th><th>Situação</th><th>Laudos</th><th class="text-right">Ações</th></tr></thead>
        <tbody>
        <?php foreach ($requests as $r): ?>
          <tr>
            <td class="ex-mono"><?= $e($r['request_number']) ?><?= (int)($r['version_number'] ?? 1) > 1 ? '<br><span class="ex-muted">versão ' . (int)$r['version_number'] . '</span>' : '' ?><?= !empty($r['appointment_scheduled_at']) ? '<br><span class="ex-muted">consulta ' . $e($fmt($r['appointment_scheduled_at'])) . '</span>' : '' ?></td>
            <td><?= $e($r['issued_at'] ? $fmt($r['issued_at']) : $fmt($r['updated_at'] ?: $r['created_at'])) ?></td>
            <td><?= $e($r['professional_name']) ?><?php if ($r['professional_crn'] !== ''): ?><br><span class="ex-muted"><?= $e($r['professional_crn']) ?></span><?php endif; ?></td>
            <td><?= (int)$r['total_items'] ?></td>
            <td><span class="badge <?= $badge[$r['status']] ?? 'badge-neutral' ?>"><?= $e($labels[$r['status']] ?? $r['status']) ?></span></td>
            <?php $rc = $resultCounts[(int)$r['id']] ?? ['accepted' => 0, 'pending' => 0]; ?>
            <td><?= (int)$rc['accepted'] ?><?php if ($rc['pending'] > 0): ?> <a class="badge badge-warning" href="/pacientes/<?= (int)$patient['id'] ?>/exames/<?= (int)$r['id'] ?>#laudos"><?= (int)$rc['pending'] ?> para revisar</a><?php endif; ?></td>
            <td class="text-right">
              <a class="btn btn-outline" href="/pacientes/<?= (int)$patient['id'] ?>/exames/<?= (int)$r['id'] ?>">Abrir</a>
              <?php if (in_array($r['status'], ['issued', 'cancelled'], true)): ?>
                <a class="btn btn-secondary" href="/pacientes/<?= (int)$patient['id'] ?>/exames/<?= (int)$r['id'] ?>/imprimir" target="_blank" rel="noopener">Imprimir</a>
              <?php endif; ?>
            </td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  <?php endif; ?>
</div>
