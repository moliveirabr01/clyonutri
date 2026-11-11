<?php
/**
 * v34 — Central de notificações da equipe.
 * Entradas: $items, $filters, $eventLabels, $receives, $unreadTotal, $loadError, $pagination (+ $paginationBase/Query/Noun), $csrfToken.
 */
$e = static fn($v): string => htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
$fmt = static function (string $dt): string {
    $t = strtotime($dt);
    return $t ? date('d/m/Y \à\s H:i', $t) : '';
};
$tipo = (string)($filters['tipo'] ?? '');
$estado = (string)($filters['estado'] ?? '');
$hidden = static function (array $filters, array $pg) use ($e): string {
    $out = '';
    foreach (['tipo' => $filters['tipo'] ?? '', 'estado' => $filters['estado'] ?? ''] as $k => $v) {
        if ($v !== '') {
            $out .= '<input type="hidden" name="' . $e($k) . '" value="' . $e($v) . '">';
        }
    }
    if ((int)($pg['page'] ?? 1) > 1) {
        $out .= '<input type="hidden" name="page" value="' . (int)$pg['page'] . '">';
    }
    if ((int)($pg['per_page'] ?? 0) !== (int)($pg['default_per_page'] ?? 20)) {
        $out .= '<input type="hidden" name="per_page" value="' . (int)$pg['per_page'] . '">';
    }
    return $out;
};
$fieldStyle = 'padding: .5rem .7rem; border: 1px solid var(--border-input, #828B9B); border-radius: 6px; font-size: .88rem; font-family: inherit; min-height: 44px; background: #fff;';
?>
<style>
  .nt-list { list-style: none; margin: 0; padding: 0; }
  .nt-item { display: flex; gap: 1rem; justify-content: space-between; align-items: flex-start; flex-wrap: wrap; padding: 1rem 1.25rem; border-bottom: 1px solid var(--admin-gray-200); border-left: 4px solid transparent; }
  .nt-item--unread { background: var(--admin-copper-50); border-left-color: var(--admin-copper-600); }
  .nt-item__main { flex: 1 1 320px; min-width: 0; }
  .nt-item__meta { display: flex; flex-wrap: wrap; gap: .5rem; align-items: center; font-size: .75rem; color: var(--admin-gray-600); margin-bottom: .3rem; }
  .nt-tag { display: inline-block; padding: .1rem .5rem; border-radius: 9999px; background: var(--admin-gray-100); color: var(--admin-gray-800); font-weight: 600; }
  .nt-tag--new { background: var(--admin-copper-600); color: #fff; }
  .nt-item__title { margin: 0 0 .2rem; font-size: .98rem; font-weight: 700; color: var(--admin-navy-900); }
  .nt-item__body { margin: 0; font-size: .88rem; color: var(--admin-gray-700); line-height: 1.5; overflow-wrap: anywhere; }
  .nt-item__actions { display: flex; gap: .5rem; flex-wrap: wrap; align-items: center; }
  .nt-item__actions form { margin: 0; }
  .nt-empty { padding: 2.5rem 1.25rem; text-align: center; color: var(--admin-gray-600); font-size: .92rem; }
  .nt-filters { display: flex; flex-wrap: wrap; gap: .75rem; align-items: flex-end; }
  .nt-filters label { display: block; font-size: .78rem; font-weight: 600; color: var(--admin-gray-700); margin-bottom: .25rem; }
  @media (max-width: 600px) { .nt-item__actions { width: 100%; } .nt-item__actions .btn { flex: 1 1 auto; text-align: center; } }
</style>

<div style="display: flex; justify-content: space-between; align-items: flex-end; flex-wrap: wrap; gap: .75rem; margin-bottom: 1.25rem;">
  <div>
    <h2 style="font-size: 1.5rem; font-weight: 700; color: var(--admin-navy-900); margin: 0 0 .25rem;">Notificações</h2>
    <p style="font-size: .88rem; color: var(--admin-gray-500); margin: 0;">
      Novas clínicas, assinaturas, chamados e mudanças na equipe.
      <?= (int)$unreadTotal === 1 ? '1 aviso não lido.' : (int)$unreadTotal . ' avisos não lidos.' ?>
    </p>
  </div>
  <div style="display: flex; gap: .5rem; flex-wrap: wrap;">
    <?php if ($receives): ?>
    <a href="/admin/notificacoes/preferencias" class="btn btn--secondary">Preferências</a>
    <?php if ((int)$unreadTotal > 0): ?>
    <form method="POST" action="/admin/notificacoes/lidas" style="margin: 0;">
      <input type="hidden" name="_csrf_token" value="<?= $e($csrfToken ?? '') ?>">
      <button type="submit" class="btn btn--primary">Marcar todas como lidas</button>
    </form>
    <?php endif; ?>
    <?php endif; ?>
  </div>
</div>

<?php if (!$receives): ?>
  <div class="admin-card"><div class="admin-card__body nt-empty">
    Seu perfil não recebe notificações da plataforma. Por padrão, só o super admin recebe.
  </div></div>
<?php else: ?>

<div class="admin-card" style="margin-bottom: 1rem;">
  <div class="admin-card__body">
    <form method="GET" action="/admin/notificacoes" class="nt-filters">
      <div>
        <label for="nt-tipo">Tipo de aviso</label>
        <select id="nt-tipo" name="tipo" style="<?= $fieldStyle ?>">
          <option value="">Todos os tipos</option>
          <?php foreach ($eventLabels as $key => $label): ?>
            <option value="<?= $e($key) ?>" <?= $tipo === $key ? 'selected' : '' ?>><?= $e($label) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div>
        <label for="nt-estado">Situação</label>
        <select id="nt-estado" name="estado" style="<?= $fieldStyle ?>">
          <option value="">Lidas e não lidas</option>
          <option value="nao-lidas" <?= $estado === 'nao-lidas' ? 'selected' : '' ?>>Só não lidas</option>
          <option value="lidas" <?= $estado === 'lidas' ? 'selected' : '' ?>>Só lidas</option>
        </select>
      </div>
      <div style="display: flex; gap: .5rem;">
        <button type="submit" class="btn btn--primary">Filtrar</button>
        <?php if ($tipo !== '' || $estado !== ''): ?><a href="/admin/notificacoes" class="btn btn--secondary">Limpar</a><?php endif; ?>
      </div>
    </form>
  </div>
</div>

<div class="admin-card" style="overflow: hidden;">
  <?php if (!empty($loadError)): ?>
    <div class="nt-empty">Não foi possível carregar os avisos agora. Tente de novo em instantes.</div>
  <?php elseif (!$items): ?>
    <div class="nt-empty">
      <?= ($tipo !== '' || $estado !== '') ? 'Nenhum aviso com esses filtros.' : 'Nenhum aviso por enquanto. Quando alguém se cadastrar ou algo pedir sua atenção, aparece aqui.' ?>
    </div>
  <?php else: ?>
    <ul class="nt-list">
      <?php foreach ($items as $it): ?>
        <li class="nt-item <?= $it['read'] ? '' : 'nt-item--unread' ?>" data-testid="notification-item">
          <div class="nt-item__main">
            <div class="nt-item__meta">
              <?php if (!$it['read']): ?><span class="nt-tag nt-tag--new">Nova</span><?php endif; ?>
              <span class="nt-tag"><?= $e($it['label']) ?></span>
              <span><?= $e($fmt((string)$it['created_at'])) ?></span>
            </div>
            <h3 class="nt-item__title"><?= $e($it['title']) ?></h3>
            <p class="nt-item__body"><?= $e($it['body']) ?></p>
          </div>
          <div class="nt-item__actions">
            <?php if ($it['href'] !== null): ?>
            <form method="POST" action="/admin/notificacoes/<?= (int)$it['id'] ?>/lida">
              <input type="hidden" name="_csrf_token" value="<?= $e($csrfToken ?? '') ?>">
              <input type="hidden" name="abrir" value="1">
              <?= $hidden($filters, $pagination) ?>
              <button type="submit" class="btn btn--primary btn--sm">Abrir</button>
            </form>
            <?php endif; ?>
            <?php if (!$it['read']): ?>
            <form method="POST" action="/admin/notificacoes/<?= (int)$it['id'] ?>/lida">
              <input type="hidden" name="_csrf_token" value="<?= $e($csrfToken ?? '') ?>">
              <?= $hidden($filters, $pagination) ?>
              <button type="submit" class="btn btn--secondary btn--sm">Marcar como lida</button>
            </form>
            <?php endif; ?>
          </div>
        </li>
      <?php endforeach; ?>
    </ul>
    <?php require __DIR__ . '/_pagination.php'; ?>
  <?php endif; ?>
</div>
<p style="font-size: .78rem; color: var(--admin-gray-500); margin-top: .75rem;">Avisos lidos há mais de 90 dias são removidos automaticamente. A trilha de auditoria não é afetada.</p>

<?php endif; ?>
