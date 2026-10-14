<div style="margin-bottom: 1.5rem; display: flex; align-items: center; justify-content: space-between; flex-wrap: wrap; gap: 1rem;">
  <div>
    <h2 style="font-size: 1.5rem; font-weight: 700; color: var(--admin-navy-900);">Resultados da Busca Global</h2>
    <p style="font-size: 0.88rem; color: var(--admin-gray-500);">
      Termo pesquisado: <strong>"<?= htmlspecialchars($query ?? '') ?>"</strong>
    </p>
  </div>
</div>

<!-- Clínicas Encontradas -->
<div class="admin-card">
  <div class="admin-card__header">
    <h3 class="admin-card__title">Clínicas / Tenants (<?= count($clinics ?? []) ?>)</h3>
  </div>
  <div class="admin-table-wrapper">
    <table class="admin-table">
      <thead>
        <tr>
          <th>ID</th>
          <th>Nome</th>
          <th>Documento</th>
          <th>E-mail</th>
          <th>Status</th>
          <th style="text-align: right;">Ação</th>
        </tr>
      </thead>
      <tbody>
        <?php if (empty($clinics)): ?>
          <tr><td colspan="6" style="text-align: center; color: var(--admin-gray-400); padding: 1.5rem;">Nenhuma clínica encontrada para este termo.</td></tr>
        <?php else: ?>
          <?php foreach ($clinics as $c): ?>
            <tr>
              <td><code>#<?= (int)$c['id'] ?></code></td>
              <td><strong><?= htmlspecialchars($c['name']) ?></strong></td>
              <td><?= htmlspecialchars($c['document'] ?: '-') ?></td>
              <td><?= htmlspecialchars($c['email'] ?: '-') ?></td>
              <td><span class="badge badge--<?= $c['status'] === 'active' ? 'active' : 'suspended' ?>"><?= htmlspecialchars($c['status']) ?></span></td>
              <td style="text-align: right;">
                <a href="/admin/clinicas?q=<?= urlencode($c['name']) ?>" class="btn btn--secondary btn--sm">Ver na Lista</a>
              </td>
            </tr>
          <?php endforeach; ?>
        <?php endif; ?>
      </tbody>
    </table>
  </div>
</div>

<!-- Usuários Encontrados -->
<div class="admin-card">
  <div class="admin-card__header">
    <h3 class="admin-card__title">Usuários & Credenciais (<?= count($users ?? []) ?>)</h3>
  </div>
  <div class="admin-table-wrapper">
    <table class="admin-table">
      <thead>
        <tr>
          <th>ID</th>
          <th>Nome</th>
          <th>E-mail</th>
          <th>Perfil</th>
          <th>Status</th>
          <th style="text-align: right;">Ação</th>
        </tr>
      </thead>
      <tbody>
        <?php if (empty($users)): ?>
          <tr><td colspan="6" style="text-align: center; color: var(--admin-gray-400); padding: 1.5rem;">Nenhum usuário encontrado para este termo.</td></tr>
        <?php else: ?>
          <?php foreach ($users as $u): ?>
            <tr>
              <td><code>#<?= (int)$u['id'] ?></code></td>
              <td><strong><?= htmlspecialchars($u['name']) ?></strong></td>
              <td><?= htmlspecialchars($u['email']) ?></td>
              <td><span class="badge badge--info"><?= htmlspecialchars($u['role']) ?></span></td>
              <td><span class="badge badge--<?= $u['status'] === 'active' ? 'active' : 'suspended' ?>"><?= htmlspecialchars($u['status']) ?></span></td>
              <td style="text-align: right;">
                <a href="/admin/usuarios?q=<?= urlencode($u['email']) ?>" class="btn btn--secondary btn--sm">Ver Usuário</a>
              </td>
            </tr>
          <?php endforeach; ?>
        <?php endif; ?>
      </tbody>
    </table>
  </div>
</div>
