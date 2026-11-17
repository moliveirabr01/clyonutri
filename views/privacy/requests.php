<?php
/** @var array $requests @var bool $canProcessDeletion @var string $csrfToken @var ?array $flash @var string $confirmWord */
$e = fn($v) => htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
$types = ['export' => 'Exportação dos dados', 'access' => 'Acesso aos dados', 'deletion' => 'Exclusão', 'anonymization' => 'Anonimização', 'rectification' => 'Correção'];
$statuses = ['pending' => 'Aguardando', 'in_review' => 'Em análise', 'completed' => 'Concluído', 'completed_legal_hold' => 'Concluído com guarda legal do prontuário', 'rejected_legal_hold' => 'Concluído com guarda legal do prontuário'];
$fmt = function ($d) { $t = $d ? strtotime((string)$d) : false; return $t ? date('d/m/Y H:i', $t) : '—'; };
$open = array_values(array_filter($requests, fn($r) => $r['is_open']));
$overdue = count(array_filter($open, fn($r) => $r['is_overdue']));
?>
<h1>Pedidos de titulares (LGPD)</h1>
<p>Pedidos de pacientes sobre os próprios dados. O prazo de resposta é de 15 dias a partir do pedido.</p>
<?php if ($flash): ?><p role="<?= $flash['type'] === 'error' ? 'alert' : 'status' ?>" class="card" data-testid="privacy-flash"><?= $e($flash['message']) ?></p><?php endif; ?>
<p class="card" data-testid="privacy-summary"><strong><?= count($open) ?></strong> <?= \ClyoNutri\Application\View\UserText::count(count($open), 'pedido', 'pedidos') ?> em aberto<?= $overdue ? ' · <strong>' . $overdue . ' com prazo vencido</strong>' : '' ?>.</p>
<?php if (!$requests): ?>
  <p class="card">Nenhum pedido registrado. Pedidos feitos pela paciente no app ou registrados no perfil do paciente aparecem aqui.</p>
<?php else: ?>
<div class="table-responsive card">
<table class="table" data-testid="privacy-requests">
  <caption class="sr-only">Pedidos de titulares, em aberto primeiro, por prazo</caption>
  <thead><tr><th scope="col">Paciente</th><th scope="col">Pedido</th><th scope="col">Aberto em</th><th scope="col">Prazo</th><th scope="col">Situação</th><th scope="col">Ação</th></tr></thead>
  <tbody>
  <?php foreach ($requests as $r): ?>
    <tr data-request-id="<?= (int)$r['id'] ?>">
      <td><a href="/pacientes/<?= (int)$r['patient_id'] ?>"><?= $e($r['patient_name']) ?></a></td>
      <td><?= $e($types[$r['request_type']] ?? $r['request_type']) ?><?php if (!empty($r['details'])): ?><br><small><?= $e($r['details']) ?></small><?php endif; ?></td>
      <td><?= $e($fmt($r['created_at'])) ?></td>
      <td><?= $e($fmt($r['deadline_at'])) ?><?= $r['is_overdue'] ? ' <span class="badge badge-danger">Prazo vencido</span>' : '' ?></td>
      <td><?= $e($statuses[$r['status']] ?? $r['status']) ?><?php if (!$r['is_open'] && $r['completed_at']): ?><br><small><?= $e($fmt($r['completed_at'])) ?></small><?php endif; ?></td>
      <td>
      <?php if ($r['is_open'] && in_array($r['request_type'], ['export', 'access'], true)): ?>
        <form method="post" action="/privacidade/pedidos/<?= (int)$r['id'] ?>/exportar">
          <input type="hidden" name="_csrf_token" value="<?= $e($csrfToken) ?>">
          <button type="submit" class="btn btn-secondary">Gerar pacote de dados</button>
        </form>
      <?php elseif ($r['is_open'] && in_array($r['request_type'], ['deletion', 'anonymization'], true)): ?>
        <?php if ($canProcessDeletion): ?>
        <form method="post" action="/privacidade/pedidos/<?= (int)$r['id'] ?>/anonimizar">
          <input type="hidden" name="_csrf_token" value="<?= $e($csrfToken) ?>">
          <p><small>Anonimiza o cadastro de forma irreversível. Prontuário, anamneses, avaliações e planos ficam preservados sob guarda legal.</small></p>
          <label for="confirm-word-<?= (int)$r['id'] ?>">Digite <?= $e($confirmWord) ?> para confirmar</label>
          <input id="confirm-word-<?= (int)$r['id'] ?>" name="confirm_word" class="form-control" autocomplete="off" required>
          <label><input type="checkbox" name="confirm_irreversible" value="yes" required> Entendo que não pode ser desfeito</label>
          <button type="submit" class="btn btn-secondary">Anonimizar cadastro</button>
        </form>
        <?php else: ?><small>Só a gestão do consultório (dono ou administrador) processa exclusões.</small><?php endif; ?>
      <?php elseif ($r['is_open']): ?><small>Atender pelo perfil do paciente.</small>
      <?php else: ?><small><?= $e($r['response_notes'] ?? '') ?></small><?php endif; ?>
      </td>
    </tr>
  <?php endforeach; ?>
  </tbody>
</table>
</div>
<?php endif; ?>
