<div style="margin-bottom: 1.5rem; display: flex; align-items: center; justify-content: space-between; flex-wrap: wrap; gap: 1rem;">
  <div>
    <h2 style="font-size: 1.5rem; font-weight: 700; color: var(--admin-navy-900);">Resultados da busca global</h2>
    <p style="font-size: 0.88rem; color: var(--admin-gray-500);">
      Termo pesquisado: <strong>"<?= htmlspecialchars($query ?? '') ?>"</strong>
    </p>
  </div>
</div>

<!-- Clínicas Encontradas -->
<div class="admin-card">
  <div class="admin-card__header">
    <h3 class="admin-card__title">Clínicas / tenants (<?= count($clinics ?? []) ?>)</h3>
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
<?php if (!empty($llShowClinics)): ?>          <th>Último login</th>
<?php endif; ?>
          <th style="text-align: right;">Ação</th>
        </tr>
      </thead>
      <tbody>
        <?php if (empty($clinics)): ?>
          <tr><td colspan="<?= !empty($llShowClinics) ? 7 : 6 ?>" style="text-align: center; color: var(--admin-gray-400); padding: 1.5rem;">Nenhuma clínica encontrada para este termo.</td></tr>
        <?php else: ?>
          <?php foreach ($clinics as $c): ?>
            <tr>
              <td><code>#<?= (int)$c['id'] ?></code></td>
              <td><strong><?= htmlspecialchars($c['name']) ?></strong></td>
              <td><?= htmlspecialchars($c['document'] ?: '-') ?></td>
              <td><?= htmlspecialchars($c['email'] ?: '-') ?></td>
              <td><span class="badge badge--<?= $c['status'] === 'active' ? 'active' : 'suspended' ?>"><?= htmlspecialchars($c['status']) ?></span></td>
              <?php if (!empty($llShowClinics)): ?>
              <td>
                <?php
                  $llRow = ($llByClinic ?? [])[(int)$c['id']] ?? null;
                  $llInfo = \ClyoNutri\Core\Auth\LastLoginPresenter::describe($llRow['at'] ?? null, $c['created_at'] ?? null, $llTrackingStart ?? null, !empty($llAvailable));
                  $llWho = !empty($llShowUsers) ? ($llRow['who'] ?? null) : null;
                  require __DIR__ . '/_ultimo_login.php';
                ?>
              </td>
              <?php endif; ?>
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
    <h3 class="admin-card__title">Usuários e credenciais (<?= count($users ?? []) ?>)</h3>
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
<?php if (!empty($llShowUsers)): ?>          <th>Último login</th>
<?php endif; ?>
          <th style="text-align: right;">Ação</th>
        </tr>
      </thead>
      <tbody>
        <?php if (empty($users)): ?>
          <tr><td colspan="<?= !empty($llShowUsers) ? 7 : 6 ?>" style="text-align: center; color: var(--admin-gray-400); padding: 1.5rem;">Nenhum usuário encontrado para este termo.</td></tr>
        <?php else: ?>
          <?php foreach ($users as $u): ?>
            <tr>
              <td><code>#<?= (int)$u['id'] ?></code></td>
              <td><strong><?= htmlspecialchars($u['name']) ?></strong></td>
              <td><?= htmlspecialchars($u['email']) ?></td>
              <td><span class="badge badge--info"><?= htmlspecialchars($u['role']) ?></span></td>
              <td><span class="badge badge--<?= $u['status'] === 'active' ? 'active' : 'suspended' ?>"><?= htmlspecialchars($u['status']) ?></span></td>
              <?php if (!empty($llShowUsers)): ?>
              <td>
                <?php
                  $llInfo = \ClyoNutri\Core\Auth\LastLoginPresenter::describe($u['last_login_at'] ?? null, $u['created_at'] ?? null, $llTrackingStart ?? null, !empty($llAvailable));
                  $llWho = null;
                  require __DIR__ . '/_ultimo_login.php';
                ?>
              </td>
              <?php endif; ?>
              <td style="text-align: right;">
                <a href="/admin/usuarios?q=<?= urlencode($u['email']) ?>" class="btn btn--secondary btn--sm">Ver usuário</a>
              </td>
            </tr>
          <?php endforeach; ?>
        <?php endif; ?>
      </tbody>
    </table>
  </div>
</div>
