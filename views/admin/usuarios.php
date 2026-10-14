<div style="margin-bottom: 1.5rem; display: flex; align-items: center; justify-content: space-between; flex-wrap: wrap; gap: 1rem;">
  <div>
    <h2 style="font-size: 1.5rem; font-weight: 700; color: var(--admin-navy-900);">Usuários & Perfis de Acesso</h2>
    <p style="font-size: 0.88rem; color: var(--admin-gray-500);">Segregação de funções, governança de credenciais e revogação ativa de sessões.</p>
  </div>
</div>

<div class="admin-card">
  <div class="admin-card__header">
    <form action="/admin/usuarios" method="GET" style="display: flex; gap: 0.75rem; flex-wrap: wrap; width: 100%; max-width: 650px;">
      <input type="text" name="q" value="<?= htmlspecialchars($_GET['q'] ?? '') ?>" placeholder="Filtrar por nome ou e-mail..." style="flex: 1; padding: 0.45rem 0.75rem; border: 1px solid var(--admin-gray-300); border-radius: 6px; font-size: 0.85rem;">
      <select name="role" style="padding: 0.45rem 0.75rem; border: 1px solid var(--admin-gray-300); border-radius: 6px; font-size: 0.85rem;">
        <option value="">Todos os perfis</option>
        <option value="nutritionist" <?= ($_GET['role'] ?? '') === 'nutritionist' ? 'selected' : '' ?>>Nutricionista</option>
        <option value="receptionist" <?= ($_GET['role'] ?? '') === 'receptionist' ? 'selected' : '' ?>>Recepcionista</option>
        <option value="operator" <?= ($_GET['role'] ?? '') === 'operator' ? 'selected' : '' ?>>Operador</option>
        <option value="support_agent" <?= ($_GET['role'] ?? '') === 'support_agent' ? 'selected' : '' ?>>Suporte</option>
        <option value="superadmin" <?= ($_GET['role'] ?? '') === 'superadmin' ? 'selected' : '' ?>>Superadmin</option>
      </select>
      <select name="status" style="padding: 0.45rem 0.75rem; border: 1px solid var(--admin-gray-300); border-radius: 6px; font-size: 0.85rem;">
        <option value="">Status</option>
        <option value="active" <?= ($_GET['status'] ?? '') === 'active' ? 'selected' : '' ?>>Ativos</option>
        <option value="suspended" <?= ($_GET['status'] ?? '') === 'suspended' ? 'selected' : '' ?>>Suspensos</option>
      </select>
      <button type="submit" class="btn btn--secondary btn--sm">Filtrar</button>
      <?php if (!empty($_GET['q']) || !empty($_GET['role']) || !empty($_GET['status'])): ?>
        <a href="/admin/usuarios" class="btn btn--secondary btn--sm">Limpar</a>
      <?php endif; ?>
    </form>
    <div style="font-size: 0.85rem; color: var(--admin-gray-500); white-space: nowrap;">
      Total: <strong><?= count($users) ?></strong> usuários
    </div>
  </div>

  <div class="admin-table-wrapper">
    <table class="admin-table">
      <thead>
        <tr>
          <th>ID</th>
          <th>Nome & E-mail</th>
          <th>Perfil Global</th>
          <th>Clínica Principal</th>
          <th>Versão Auth</th>
          <th>Status</th>
          <th>Cadastrado</th>
          <th style="text-align: right;">Ações de Segurança</th>
        </tr>
      </thead>
      <tbody>
        <?php if (empty($users)): ?>
          <tr><td colspan="8" style="text-align: center; color: var(--admin-gray-400); padding: 2rem;">Nenhum usuário encontrado.</td></tr>
        <?php else: ?>
          <?php foreach ($users as $u): ?>
            <tr>
              <td><code>#<?= (int)$u['id'] ?></code></td>
              <td>
                <div style="font-weight: 600; color: var(--admin-navy-900);"><?= htmlspecialchars($u['name']) ?></div>
                <small style="color: var(--admin-gray-500);"><?= htmlspecialchars($u['email']) ?></small>
              </td>
              <td>
                <span class="badge badge--<?= in_array($u['role'], ['superadmin','admin_operacional','operator','support_agent']) ? 'warning' : 'info' ?>">
                  <?= htmlspecialchars($u['role']) ?>
                </span>
              </td>
              <td><?= htmlspecialchars($u['clinic_name'] ?? 'Sem clínica') ?></td>
              <td><span title="Versão de autenticação para invalidação de sessão">v<?= (int)$u['auth_version'] ?></span></td>
              <td>
                <span class="badge badge--<?= $u['status'] === 'active' ? 'active' : 'suspended' ?>">
                  <?= htmlspecialchars($u['status']) ?>
                </span>
              </td>
              <td><?= date('d/m/Y', strtotime($u['created_at'])) ?></td>
              <td style="text-align: right; white-space: nowrap;">
                <!-- Botão de Revogação Imediata de Sessões -->
                <form action="/admin/usuarios/<?= (int)$u['id'] ?>/revogar-sessoes" method="POST" style="display: inline-block;">
                  <input type="hidden" name="_csrf_token" value="<?= htmlspecialchars($csrfToken ?? '') ?>">
                  <button type="submit" class="btn btn--danger btn--sm" title="Desconecta todas as sessões ativas deste usuário imediatamente">
                    Revogar Sessões
                  </button>
                </form>

                <!-- Ativar / Suspender -->
                <form action="/admin/usuarios/<?= (int)$u['id'] ?>/status" method="POST" style="display: inline-block; margin-left: 4px;">
                  <input type="hidden" name="_csrf_token" value="<?= htmlspecialchars($csrfToken ?? '') ?>">
                  <?php if ($u['status'] === 'active'): ?>
                    <input type="hidden" name="status" value="suspended">
                    <button type="submit" class="btn btn--secondary btn--sm" onclick="return confirm('Deseja suspender o acesso deste usuário?')">Suspender</button>
                  <?php else: ?>
                    <input type="hidden" name="status" value="active">
                    <button type="submit" class="btn btn--primary btn--sm">Ativar</button>
                  <?php endif; ?>
                </form>
              </td>
            </tr>
          <?php endforeach; ?>
        <?php endif; ?>
      </tbody>
    </table>
  </div>
</div>
