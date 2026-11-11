<?php
  use ClyoNutri\Core\Security\PlatformPolicy;
  use ClyoNutri\Domain\Admin\UserAdministration as UA;
  $canManage = PlatformPolicy::can((string)($operatorRole ?? ''), 'users.manage');
  $statusCounts = $statusCounts ?? ['active' => 0, 'suspended' => 0, 'blocked' => 0, 'invited' => 0];
  $kpiDefs = [
    ['active', 'Ativos', 'Podem entrar normalmente', 'var(--admin-success)'],
    ['suspended', 'Suspensos', 'Pausa administrativa, reativação direta', 'var(--admin-warning, #B45309)'],
    ['blocked', 'Bloqueados', 'Acesso cortado por segurança', 'var(--admin-danger)'],
    ['invited', 'Convites pendentes', 'Equipe que ainda não criou a senha', 'var(--admin-navy-900)'],
  ];
?>
<div style="margin-bottom: 1.5rem; display: flex; align-items: center; justify-content: space-between; flex-wrap: wrap; gap: 1rem;">
  <div>
    <h2 style="font-size: 1.5rem; font-weight: 700; color: var(--admin-navy-900);">Usuários e perfis de acesso</h2>
    <p style="font-size: 0.88rem; color: var(--admin-gray-500);">Segregação de funções, governança de credenciais e revogação ativa de sessões.</p>
  </div>
  <?php if ($canManage): ?>
    <a href="/admin/usuarios/novo" class="btn btn--primary">Novo usuário da equipe</a>
  <?php endif; ?>
</div>

<div class="kpi-grid kpi-grid--4" aria-label="Resumo por status">
  <?php foreach ($kpiDefs as [$kpiStatus, $kpiTitle, $kpiSub, $kpiColor]): ?>
    <div class="kpi-card kpi-card--link">
      <div class="kpi-card__title"><?= htmlspecialchars($kpiTitle) ?></div>
      <div class="kpi-card__value" style="color: <?= $kpiColor ?>;"><a class="kpi-card__link" href="/admin/usuarios?status=<?= $kpiStatus ?>"><span class="kpi-card__sr"><?= htmlspecialchars($kpiTitle) ?>: </span><?= (int)($statusCounts[$kpiStatus] ?? 0) ?></a></div>
      <div class="kpi-card__subtitle"><?= htmlspecialchars($kpiSub) ?></div>
    </div>
  <?php endforeach; ?>
</div>

<div class="admin-card">
  <div class="admin-card__header">
    <form action="/admin/usuarios" method="GET" style="display: flex; gap: 0.75rem; flex-wrap: wrap; width: 100%; max-width: 650px;">
      <input type="text" name="q" value="<?= htmlspecialchars($_GET['q'] ?? '') ?>" placeholder="Filtrar por nome ou e-mail..." style="flex: 1; padding: 0.45rem 0.75rem; border: 1px solid var(--border-input, #828B9B); border-radius: 6px; font-size: 0.85rem;">
      <select name="role" aria-label="Filtrar por perfil" style="padding: 0.45rem 0.75rem; border: 1px solid var(--border-input, #828B9B); border-radius: 6px; font-size: 0.85rem;">
        <option value="">Todos os perfis</option>
        <option value="nutritionist" <?= ($_GET['role'] ?? '') === 'nutritionist' ? 'selected' : '' ?>>Nutricionista</option>
        <option value="receptionist" <?= ($_GET['role'] ?? '') === 'receptionist' ? 'selected' : '' ?>>Recepcionista</option>
        <option value="superadmin" <?= ($_GET['role'] ?? '') === 'superadmin' ? 'selected' : '' ?>>Super admin</option>
        <option value="admin_operacional" <?= ($_GET['role'] ?? '') === 'admin_operacional' ? 'selected' : '' ?>>Administrador de operação</option>
        <option value="operator" <?= ($_GET['role'] ?? '') === 'operator' ? 'selected' : '' ?>>Operador</option>
        <option value="support_agent" <?= ($_GET['role'] ?? '') === 'support_agent' ? 'selected' : '' ?>>Suporte</option>
        <option value="finance_admin" <?= ($_GET['role'] ?? '') === 'finance_admin' ? 'selected' : '' ?>>Financeiro</option>
        <option value="security_auditor" <?= ($_GET['role'] ?? '') === 'security_auditor' ? 'selected' : '' ?>>Auditor de segurança</option>
        <?php
          $knownRoles = ['nutritionist', 'receptionist', 'operator', 'support_agent', 'superadmin', 'admin_operacional', 'finance_admin', 'security_auditor'];
          $currentRole = (string)($paginationQuery['role'] ?? '');
        ?>
        <?php if ($currentRole !== '' && !in_array($currentRole, $knownRoles, true)): ?>
          <option value="<?= htmlspecialchars($currentRole) ?>" selected><?= htmlspecialchars($currentRole) ?></option>
        <?php endif; ?>
      </select>
      <select name="status" aria-label="Filtrar por status" style="padding: 0.45rem 0.75rem; border: 1px solid var(--border-input, #828B9B); border-radius: 6px; font-size: 0.85rem;">
        <option value="">Status</option>
        <option value="active" <?= ($_GET['status'] ?? '') === 'active' ? 'selected' : '' ?>>Ativos</option>
        <option value="suspended" <?= ($_GET['status'] ?? '') === 'suspended' ? 'selected' : '' ?>>Suspensos</option>
        <option value="blocked" <?= ($_GET['status'] ?? '') === 'blocked' ? 'selected' : '' ?>>Bloqueados</option>
        <option value="invited" <?= ($_GET['status'] ?? '') === 'invited' ? 'selected' : '' ?>>Convite pendente</option>
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
      <?php if (($group ?? '') !== ''): ?>
        <input type="hidden" name="grupo" value="<?= htmlspecialchars((string)$group) ?>">
      <?php endif; ?>
      <button type="submit" class="btn btn--secondary btn--sm">Filtrar</button>
      <?php if (!empty($paginationQuery)): ?>
        <a href="/admin/usuarios" class="btn btn--secondary btn--sm">Limpar</a>
      <?php endif; ?>
    </form>
    <div style="font-size: 0.85rem; color: var(--admin-gray-500); white-space: nowrap;">
      Total: <strong><?= (int)$pagination['total_records'] ?></strong> <?= (int)$pagination['total_records'] === 1 ? 'usuário' : 'usuários' ?>
    </div>
  </div>

  <?php if (($group ?? '') === 'profissionais'): ?>
    <p style="margin: 0; padding: 0.6rem 1.25rem; font-size: 0.82rem; color: var(--admin-gray-600); background: #fafbfc; border-bottom: 1px solid var(--admin-gray-200);">
      Filtro do painel: profissionais (nutricionistas, proprietários e administradores de clínica).
      <a href="/admin/usuarios" style="color: var(--admin-navy-900); text-decoration: underline;">Remover este filtro</a>
    </p>
  <?php endif; ?>

  <div class="admin-table-wrapper">
    <table class="admin-table">
      <thead>
        <tr>
          <th>ID</th>
          <th>Nome & E-mail</th>
          <th>Perfil global</th>
          <th>Clínica principal</th>
          <th>Versão auth</th>
          <th>Status</th>
          <th>Cadastrado</th>
          <th>Último login</th>
          <th style="text-align: right;">Ações</th>
        </tr>
      </thead>
      <tbody>
        <?php if (empty($users)): ?>
          <tr><td colspan="9" style="text-align: center; color: var(--admin-gray-400); padding: 2rem;">Nenhum usuário encontrado.</td></tr>
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
                  <?= htmlspecialchars(UA::roleLabel((string)$u['role'])) ?>
                </span>
              </td>
              <td><?= htmlspecialchars($u['clinic_name'] ?? 'Sem clínica') ?></td>
              <td><span title="Versão de autenticação para invalidação de sessão">v<?= (int)$u['auth_version'] ?></span></td>
              <td>
                <?php $badgeClass = ['active' => 'active', 'suspended' => 'pending', 'blocked' => 'suspended', 'invited' => 'info'][(string)$u['status']] ?? 'info'; ?>
                <span class="badge badge--<?= $badgeClass ?>" data-status="<?= htmlspecialchars((string)$u['status']) ?>">
                  <?= htmlspecialchars(UA::statusLabel((string)$u['status'])) ?>
                </span>
              </td>
              <td><?= date('d/m/Y', strtotime($u['created_at'])) ?></td>
              <td>
                <?php
                  $llInfo = \ClyoNutri\Core\Auth\LastLoginPresenter::describe($u['last_login_at'] ?? null, $u['created_at'] ?? null, $llTrackingStart ?? null, !empty($llAvailable));
                  $llWho = null;
                  require __DIR__ . '/_ultimo_login.php';
                ?>
              </td>
              <td style="text-align: right; white-space: nowrap;">
                <a href="/admin/usuarios/<?= (int)$u['id'] ?>" class="btn btn--secondary btn--sm" aria-label="Gerenciar <?= htmlspecialchars($u['name']) ?>">Gerenciar</a>
              </td>
            </tr>
          <?php endforeach; ?>
        <?php endif; ?>
      </tbody>
    </table>
  </div>
  <?php require __DIR__ . '/_pagination.php'; ?>
</div>
