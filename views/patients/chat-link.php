<?php
/** @var int $pid @var array $chatLinks @var array $chatProfessionals @var ?array $chatLinkFlash @var array $chatActor @var string $csrfToken */
$e = static fn($v) => htmlspecialchars((string)($v ?? ''), ENT_QUOTES, 'UTF-8');
$fmt = static function ($d) { $t = $d ? strtotime((string)$d) : false; return $t ? date('d/m/Y', $t) : '—'; };
$canManage = in_array($chatActor['role'], ['owner', 'admin', 'nutritionist'], true);
$isManager = in_array($chatActor['role'], ['owner', 'admin'], true);
$activeProf = array_map(static fn($l) => (int)$l['professional_id'], array_filter($chatLinks, static fn($l) => $l['status'] === 'active'));
$sources = ['manual' => 'liberado pela equipe', 'appointment' => 'consulta marcada', 'self_booking' => 'agendamento online', 'migration_care_history' => 'histórico de atendimento'];
?>
<section class="card" style="margin-top:24px" aria-labelledby="chat-vinculo-titulo" id="chat-vinculo" data-testid="chat-link">
<h2 id="chat-vinculo-titulo">Chat com a paciente</h2>
<p>A paciente só conversa pelo app com profissionais que têm vínculo ativo com ela. Consulta marcada libera o vínculo automaticamente.</p>
<?php if ($chatLinkFlash): ?><p role="<?= $chatLinkFlash['type'] === 'error' ? 'alert' : 'status' ?>" data-testid="chat-link-flash"><strong><?= $e($chatLinkFlash['message']) ?></strong></p><?php endif; ?>
<?php if (!$chatLinks): ?>
  <p data-testid="chat-link-empty">Nenhum vínculo. A paciente vê “Seu consultório ainda não liberou o chat”.</p>
<?php else: ?>
<ul style="list-style:none;padding:0;margin:0">
<?php foreach ($chatLinks as $l): ?>
  <li style="padding:8px 0;border-bottom:1px solid #e5e7eb" data-link-id="<?= (int)$l['id'] ?>">
    <strong><?= $e($l['professional_name']) ?></strong> ·
    <?php if ($l['status'] === 'active'): ?>
      <span class="badge badge-success">Chat liberado</span> desde <?= $e($fmt($l['started_at'])) ?> (<?= $e($sources[$l['source']] ?? $l['source']) ?>)
      <?php if ($canManage && ($isManager || (int)$l['professional_id'] === (int)$chatActor['professional_id'])): ?>
      <form method="post" action="/pacientes/<?= $pid ?>/chat/vinculo/<?= (int)$l['id'] ?>/encerrar" style="margin-top:6px">
        <input type="hidden" name="_csrf_token" value="<?= $e($csrfToken) ?>">
        <label for="end-reason-<?= (int)$l['id'] ?>"><small>Motivo (opcional, fica no histórico)</small></label>
        <input class="form-control" type="text" id="end-reason-<?= (int)$l['id'] ?>" name="ended_reason" maxlength="200" style="max-width:360px">
        <button type="submit" class="btn btn-secondary" style="margin-top:6px">Encerrar chat</button>
      </form>
      <?php endif; ?>
    <?php else: ?>
      <span class="badge badge-secondary">Encerrado</span> em <?= $e($fmt($l['ended_at'])) ?><?= $l['ended_by_name'] ? ' por ' . $e($l['ended_by_name']) : '' ?><?= $l['ended_reason'] ? ' · ' . $e($l['ended_reason']) : '' ?>
    <?php endif; ?>
  </li>
<?php endforeach; ?>
</ul>
<?php endif; ?>
<?php if ($canManage):
  $options = array_filter($chatProfessionals, static fn($p) => !in_array((int)$p['id'], $activeProf, true) && ($isManager || (int)$p['id'] === (int)$chatActor['professional_id'])); ?>
  <?php if ($options): ?>
  <form method="post" action="/pacientes/<?= $pid ?>/chat/vinculo" style="margin-top:12px" data-testid="chat-link-activate">
    <input type="hidden" name="_csrf_token" value="<?= $e($csrfToken) ?>">
    <label class="form-label" for="chat-link-prof">Liberar chat com</label>
    <select class="form-control" id="chat-link-prof" name="professional_id" style="max-width:360px">
      <?php foreach ($options as $p): ?><option value="<?= (int)$p['id'] ?>"><?= $e($p['name']) ?></option><?php endforeach; ?>
    </select>
    <button type="submit" class="btn btn-primary" style="margin-top:6px">Liberar chat</button>
  </form>
  <?php endif; ?>
<?php endif; ?>
</section>
