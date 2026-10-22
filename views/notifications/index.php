<?php
/** NI2-95: avisos do profissional. Variáveis: $inbox, $available, $hasProfessional, $flash. */
$e = static fn($v) => htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
$fmt = static function (string $dt): string {
    $ts = strtotime($dt);
    if (!$ts) return '';
    $today = date('Y-m-d');
    $day = date('Y-m-d', $ts);
    if ($day === $today) return 'hoje às ' . date('H:i', $ts);
    if ($day === date('Y-m-d', strtotime('-1 day'))) return 'ontem às ' . date('H:i', $ts);
    return date('d/m/Y \à\s H:i', $ts);
};
?>
<div class="page-header" style="display: flex; justify-content: space-between; align-items: center; gap: 12px; flex-wrap: wrap;">
  <div>
    <h1>Avisos</h1>
    <p>Mensagens novas de pacientes e outros avisos do consultório. Para escolher o que também chega por e-mail, vá em <a href="/configuracoes#notification-settings">Configurações</a>.</p>
  </div>
  <?php if (($inbox['unread_count'] ?? 0) > 0): ?>
  <form method="POST" action="/avisos/lidas">
    <input type="hidden" name="_csrf_token" value="<?= \ClyoNutri\Core\Security\Csrf::token() ?>">
    <button type="submit" class="btn btn-secondary">Marcar todos como lidos</button>
  </form>
  <?php endif; ?>
</div>

<?php if (!empty($flash)): ?>
<div class="alert alert-info" role="status"><?= $e($flash) ?></div>
<?php endif; ?>

<div class="card" data-testid="notices-inbox">
<?php if (!$available): ?>
  <p>Os avisos ainda não foram ativados neste servidor.</p>
<?php elseif (!$hasProfessional): ?>
  <p>Sua conta não tem cadastro de profissional, então não recebe avisos de pacientes.</p>
<?php elseif (empty($inbox['items'])): ?>
  <p>Você não tem avisos por enquanto.</p>
<?php else: ?>
  <ul style="list-style: none; margin: 0; padding: 0;">
  <?php foreach ($inbox['items'] as $n): ?>
    <li style="padding: 12px 0; border-bottom: 1px solid var(--color-border, #e5e5e5); <?= $n['read'] ? '' : 'border-left: 3px solid var(--color-accent, #b87333); padding-left: 10px;' ?>" data-testid="notice-item">
      <form method="POST" action="/avisos/<?= (int)$n['id'] ?>/lida" style="display: flex; justify-content: space-between; gap: 12px; align-items: flex-start; flex-wrap: wrap;">
        <input type="hidden" name="_csrf_token" value="<?= \ClyoNutri\Core\Security\Csrf::token() ?>">
        <div style="flex: 1; min-width: 220px;">
          <strong style="<?= $n['read'] ? 'font-weight: 500;' : '' ?>"><?= $n['read'] ? '' : '<span class="sr-only">Novo: </span>' ?><?= $e($n['title']) ?></strong>
          <p style="margin: 4px 0;"><?= $e($n['body']) ?></p>
          <small><?= $e($fmt((string)$n['created_at'])) ?></small>
        </div>
        <div style="display: flex; gap: 8px;">
          <?php if (!empty($n['link'])): ?>
          <button type="submit" name="abrir" value="1" class="btn btn-primary">Abrir</button>
          <?php endif; ?>
          <?php if (!$n['read']): ?>
          <button type="submit" class="btn btn-secondary">Marcar como lido</button>
          <?php endif; ?>
        </div>
      </form>
    </li>
  <?php endforeach; ?>
  </ul>
  <?php if (!empty($inbox['next_before_id'])): ?>
  <p style="margin-top: 12px;"><a href="/avisos?antes=<?= (int)$inbox['next_before_id'] ?>">Ver avisos mais antigos</a></p>
  <?php endif; ?>
<?php endif; ?>
</div>
