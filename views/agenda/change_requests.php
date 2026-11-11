<?php
/** @var array $pending @var array $recent @var string $csrfToken @var ?array $flash */
$e = fn($v) => htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
$fmt = function ($d) { $t = $d ? strtotime((string)$d) : false; return $t ? date('d/m/Y', $t) . ' às ' . date('H:i', $t) : '—'; };
$statusLabels = ['accepted' => 'Aceito', 'declined' => 'Recusado', 'cancelled' => 'Encerrado', 'expired' => 'Expirado'];
?>
<div class="u-text-compact u-mb-1"><a href="/agenda" class="u-color-botanical-copper-600">← Voltar para a agenda</a></div>
<h1>Pedidos de remarcação</h1>
<p>Pedidos feitos pela paciente no app. A paciente escolheu um horário livre da sua disponibilidade; a consulta só muda quando você aceita.</p>
<?php if ($flash): ?><p role="<?= $flash['type'] === 'error' ? 'alert' : 'status' ?>" class="card" data-testid="change-request-flash"><?= $e($flash['message']) ?></p><?php endif; ?>
<?php if (!$pending): ?>
  <p class="card" data-testid="change-requests-empty">Nenhum pedido aguardando resposta.</p>
<?php else: ?>
<div class="table-responsive card">
<table class="table" data-testid="change-requests">
  <caption class="sr-only">Pedidos de remarcação aguardando resposta, pelo horário pedido</caption>
  <thead><tr><th scope="col">Paciente</th><th scope="col">Horário atual</th><th scope="col">Horário pedido</th><th scope="col">Motivo</th><th scope="col">Ação</th></tr></thead>
  <tbody>
  <?php foreach ($pending as $r): ?>
    <tr data-request-id="<?= (int)$r['id'] ?>">
      <td><a href="/pacientes/<?= (int)$r['patient_id'] ?>"><?= $e($r['patient_name']) ?></a><?php if (!empty($r['professional_name'])): ?><br><small>com <?= $e($r['professional_name']) ?></small><?php endif; ?></td>
      <td><?= $e($fmt($r['appointment_scheduled_at'])) ?><br><small><?= $e(\ClyoNutri\Core\I18n\LabelMap::appointmentStatus($r['appointment_status'])) ?></small></td>
      <td><strong><?= $e($fmt($r['requested_scheduled_at'])) ?></strong><br><small><?= (int)$r['duration_minutes'] ?> minutos · pedido em <?= $e($fmt($r['created_at'])) ?></small></td>
      <td><?= $r['reason'] !== null && $r['reason'] !== '' ? $e($r['reason']) : '<small>Não informado</small>' ?></td>
      <td>
        <form method="post" action="/agenda/pedidos-remarcacao/<?= (int)$r['id'] ?>/aceitar" class="u-mb-2">
          <input type="hidden" name="_csrf_token" value="<?= $e($csrfToken) ?>">
          <button type="submit" class="btn btn-primary" data-testid="approve-request">Aceitar novo horário</button>
        </form>
        <form method="post" action="/agenda/pedidos-remarcacao/<?= (int)$r['id'] ?>/recusar">
          <input type="hidden" name="_csrf_token" value="<?= $e($csrfToken) ?>">
          <label for="decline-note-<?= (int)$r['id'] ?>"><small>Resposta para a paciente (opcional)</small></label>
          <input class="form-control" type="text" id="decline-note-<?= (int)$r['id'] ?>" name="decision_note" maxlength="300" placeholder="Ex.: esse horário não dá; me chame no chat">
          <button type="submit" class="btn btn-secondary u-mt-1" data-testid="decline-request">Recusar</button>
        </form>
      </td>
    </tr>
  <?php endforeach; ?>
  </tbody>
</table>
</div>
<?php endif; ?>
<?php if ($recent): ?>
<h2 class="u-mt-6">Respondidos nos últimos 14 dias</h2>
<div class="table-responsive card">
<table class="table" data-testid="change-requests-recent">
  <caption class="sr-only">Pedidos de remarcação respondidos ou encerrados</caption>
  <thead><tr><th scope="col">Paciente</th><th scope="col">Horário pedido</th><th scope="col">Situação</th><th scope="col">Por</th></tr></thead>
  <tbody>
  <?php foreach ($recent as $r): ?>
    <tr>
      <td><?= $e($r['patient_name']) ?></td>
      <td><?= $e($fmt($r['requested_scheduled_at'])) ?></td>
      <td><?= $e($statusLabels[$r['status']] ?? $r['status']) ?><?php if (!empty($r['decision_note'])): ?><br><small><?= $e($r['decision_note']) ?></small><?php endif; ?></td>
      <td><?= $r['decided_by_name'] ? $e($r['decided_by_name']) : ($r['status'] === 'cancelled' ? 'Paciente' : '—') ?></td>
    </tr>
  <?php endforeach; ?>
  </tbody>
</table>
</div>
<?php endif; ?>
