<div style="margin-bottom: 1.5rem; display: flex; align-items: center; justify-content: space-between; flex-wrap: wrap; gap: 1rem;">
  <div>
    <h2 style="font-size: 1.5rem; font-weight: 700; color: var(--admin-navy-900);">Gestão de clínicas (tenants)</h2>
    <p style="font-size: 0.88rem; color: var(--admin-gray-500);">Controle centralizado dos consultórios e clínicas assinantes da plataforma Clyo Nutri.</p>
  </div>
</div>

<div class="admin-card">
  <div class="admin-card__header">
    <form action="/admin/clinicas" method="GET" style="display: flex; gap: 0.75rem; flex-wrap: wrap; width: 100%; max-width: 650px;">
      <input type="text" name="q" value="<?= htmlspecialchars($_GET['q'] ?? '') ?>" placeholder="Filtrar por nome, documento ou e-mail..." style="flex: 1; padding: 0.45rem 0.75rem; border: 1px solid var(--border-input, #828B9B); border-radius: 6px; font-size: 0.85rem;">
      <select name="status" style="padding: 0.45rem 0.75rem; border: 1px solid var(--border-input, #828B9B); border-radius: 6px; font-size: 0.85rem;">
        <option value="">Todos os status</option>
        <option value="active" <?= ($_GET['status'] ?? '') === 'active' ? 'selected' : '' ?>>Ativas</option>
        <option value="suspended" <?= ($_GET['status'] ?? '') === 'suspended' ? 'selected' : '' ?>>Suspensas</option>
      </select>
<?php if (!empty($llAvailable)): ?>
      <select name="acesso" aria-label="Filtrar por acesso" style="padding: 0.45rem 0.75rem; border: 1px solid var(--border-input, #828B9B); border-radius: 6px; font-size: 0.85rem;">
        <option value="">Qualquer acesso</option>
        <option value="inativo30" <?= ($acesso ?? '') === 'inativo30' ? 'selected' : '' ?>>Sem acesso há mais de 30 dias</option>
      </select>
      <select name="ordem" aria-label="Ordenar por" style="padding: 0.45rem 0.75rem; border: 1px solid var(--border-input, #828B9B); border-radius: 6px; font-size: 0.85rem;">
        <option value="">Ordem: cadastro mais recente</option>
        <option value="ultimo_login" <?= ($ordem ?? '') === 'ultimo_login' ? 'selected' : '' ?>>Ordem: último login mais recente</option>
      </select>
<?php endif; ?>
      <?php if (($pagination['per_page'] ?? 0) !== ($pagination['default_per_page'] ?? 0)): ?>
        <input type="hidden" name="per_page" value="<?= (int)$pagination['per_page'] ?>">
      <?php endif; ?>
      <button type="submit" class="btn btn--secondary btn--sm">Filtrar</button>
      <?php if (!empty($paginationQuery)): ?>
        <a href="/admin/clinicas" class="btn btn--secondary btn--sm">Limpar</a>
      <?php endif; ?>
    </form>
    <div style="font-size: 0.85rem; color: var(--admin-gray-500); white-space: nowrap;">
      Total: <strong><?= (int)$pagination['total_records'] ?></strong> <?= (int)$pagination['total_records'] === 1 ? 'clínica' : 'clínicas' ?>
    </div>
  </div>

  <div class="admin-table-wrapper">
    <table class="admin-table">
      <thead>
        <tr>
          <th>ID</th>
          <th>Clínica / razão social</th>
          <th>Documento</th>
          <th>E-mail de Contato</th>
          <th>Membros</th>
          <th>Status</th>
<?php if (!empty($showPlan)): ?>
          <th>Plano</th>
<?php endif; ?>
          <th>Criado Em</th>
          <th>Último login</th>
          <th style="text-align: right;">Ações</th>
        </tr>
      </thead>
      <tbody>
        <?php if (empty($clinics)): ?>
          <tr><td colspan="<?= !empty($showPlan) ? 10 : 9 ?>" style="text-align: center; color: var(--admin-gray-400); padding: 2rem;">Nenhuma clínica encontrada com os critérios informados.</td></tr>
        <?php else: ?>
          <?php foreach ($clinics as $c): ?>
            <tr>
              <td><code>#<?= (int)$c['id'] ?></code></td>
              <td>
                <div style="font-weight: 600; color: var(--admin-navy-900);"><?= htmlspecialchars($c['name']) ?></div>
                <small style="color: var(--admin-gray-400); font-family: monospace;"><?= htmlspecialchars($c['uuid']) ?></small>
                <?php $delOpen = ($deletionByClinic ?? [])[(int)$c['id']] ?? null; if ($delOpen): ?>
                  <br><a href="/admin/comercial#pedidos-conta" class="badge badge--warning" style="text-decoration: none;">Pedido de exclusão <?= htmlspecialchars($delOpen['status'] === 'in_review' ? 'em análise' : 'em aberto') ?></a>
                <?php endif; ?>
              </td>
              <td><?= htmlspecialchars($c['document'] ?: '-') ?></td>
              <td><?= htmlspecialchars($c['email'] ?: '-') ?></td>
              <td>
                <span class="badge badge--info"><?= (int)($c['members_count'] ?? 0) ?> membros</span>
              </td>
              <td>
                <span class="badge badge--<?= $c['status'] === 'active' ? 'active' : 'suspended' ?>">
                  <?= htmlspecialchars($c['status']) ?>
                </span>
              </td>
<?php if (!empty($showPlan)): ?>
              <td>
                <?php $planInfo = ($planByClinic ?? [])[(int)$c['id']] ?? null; require __DIR__ . '/_plano_assinatura.php'; ?>
              </td>
<?php endif; ?>
              <td><?= date('d/m/Y', strtotime($c['created_at'])) ?></td>
              <td>
                <?php
                  $llRow = ($llByClinic ?? [])[(int)$c['id']] ?? null;
                  $llInfo = \ClyoNutri\Core\Auth\LastLoginPresenter::describe($llRow['at'] ?? null, $c['created_at'] ?? null, $llTrackingStart ?? null, !empty($llAvailable));
                  $llWho = !empty($llShowWho) ? ($llRow['who'] ?? null) : null;
                  require __DIR__ . '/_ultimo_login.php';
                ?>
              </td>
              <td style="text-align: right;">
                <form action="/admin/clinicas/<?= (int)$c['id'] ?>/status" method="POST" style="display: inline-block;">
                  <input type="hidden" name="_csrf_token" value="<?= htmlspecialchars($csrfToken ?? '') ?>">
                  <?php if ($c['status'] === 'active'): ?>
                    <input type="hidden" name="status" value="suspended">
                    <button type="submit" class="btn btn--danger btn--sm" data-confirm="Tem certeza que deseja suspender esta clínica?">Suspender</button>
                  <?php else: ?>
                    <input type="hidden" name="status" value="active">
                    <button type="submit" class="btn btn--primary btn--sm" data-confirm="Deseja reativar esta clínica?">Reativar</button>
                  <?php endif; ?>
                </form>
              </td>
            </tr>
          <?php endforeach; ?>
        <?php endif; ?>
      </tbody>
    </table>
  </div>
  <?php require __DIR__ . '/_pagination.php'; ?>
</div>
