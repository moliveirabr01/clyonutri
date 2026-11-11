<style>
  .admin-grid-2 {
    display: grid;
    grid-template-columns: 2fr 1fr;
    gap: 1.5rem;
    margin-bottom: 1.5rem;
  }
  @media (max-width: 1024px) {
    .admin-grid-2 {
      grid-template-columns: 1fr;
    }
  }
</style>

<div style="margin-bottom: 1.5rem; display: flex; align-items: center; justify-content: space-between; flex-wrap: wrap; gap: 1rem;">
  <div>
    <h2 style="font-size: 1.5rem; font-weight: 700; color: var(--admin-navy-900);">Painel executivo da plataforma</h2>
    <p style="font-size: 0.88rem; color: var(--admin-gray-500);">Indicadores operacionais consolidados e governança multitenant do Clyo Nutri.</p>
  </div>
  <div style="display: flex; gap: 0.75rem;">
    <a href="/admin/clinicas" class="btn btn--secondary">
      <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M3 9l9-7 9 7v11a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2z"/></svg>
      Ver clínicas
    </a>
    <a href="/admin/servicedesk" class="btn btn--primary">
      <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M4 4h16c1.1 0 2 .9 2 2v12c0 1.1-.9 2-2 2H4c-1.1 0-2-.9-2-2V6c0-1.1.9-2 2-2z"/></svg>
      Service desk
    </a>
  </div>
</div>

<?php
  // Link só quando o papel pode abrir a tela de destino; senão o número aparece sem link.
  $kpiCan = $kpiCan ?? ['clinics' => true, 'users' => true, 'sessions' => true, 'tickets' => true];
  $kpiLink = static function (string $area, string $href, string $class, string $inner) use ($kpiCan): string {
      if (empty($kpiCan[$area])) {
          return $inner;
      }
      return '<a class="' . $class . '" href="' . $href . '">' . $inner . '</a>';
  };
  $kpiCardClass = static fn(string $area): string => !empty($kpiCan[$area]) ? 'kpi-card kpi-card--link' : 'kpi-card';
?>
<!-- KPIs Executivos do Banco Relacional Real -->
<div class="kpi-grid">
  <div class="<?= $kpiCardClass('clinics') ?>">
    <div class="kpi-card__header">
      <span class="kpi-card__title">Clínicas ativas</span>
      <div class="kpi-card__icon">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M3 9l9-7 9 7v11a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2z"/></svg>
      </div>
    </div>
    <div class="kpi-card__value"><?= $kpiLink('clinics', '/admin/clinicas?status=active', 'kpi-card__link', '<span class="kpi-card__sr">Clínicas ativas: </span>' . (int)($stats['active_clinics'] ?? 0)) ?></div>
    <div class="kpi-card__subtitle">De um total de <?= $kpiLink('clinics', '/admin/clinicas', 'kpi-card__sublink', (int)($stats['total_clinics'] ?? 0) . ' tenants cadastrados') ?></div>
  </div>

  <div class="<?= $kpiCardClass('users') ?>">
    <div class="kpi-card__header">
      <span class="kpi-card__title">Profissionais</span>
      <div class="kpi-card__icon" style="background-color: var(--admin-info-bg); color: var(--admin-info);">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/></svg>
      </div>
    </div>
    <div class="kpi-card__value"><?= $kpiLink('users', '/admin/usuarios?grupo=profissionais', 'kpi-card__link', '<span class="kpi-card__sr">Profissionais: </span>' . (int)($stats['total_professionals'] ?? 0)) ?></div>
    <div class="kpi-card__subtitle">Nutricionistas e equipe clínica ativos</div>
  </div>

  <div class="kpi-card">
    <div class="kpi-card__header">
      <span class="kpi-card__title">Pacientes cadastrados</span>
      <div class="kpi-card__icon" style="background-color: var(--admin-success-bg); color: var(--admin-success);">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M23 21v-2a4 4 0 0 0-3-3.87"/></svg>
      </div>
    </div>
    <div class="kpi-card__value"><?= (int)($stats['total_patients'] ?? 0) ?></div>
    <div class="kpi-card__subtitle">Pacientes acompanhados no ecossistema</div>
  </div>

  <div class="<?= $kpiCardClass('sessions') ?>">
    <div class="kpi-card__header">
      <span class="kpi-card__title">Sessões ativas</span>
      <div class="kpi-card__icon" style="background-color: var(--admin-warning-bg); color: var(--admin-warning);">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect width="18" height="11" x="3" y="11" rx="2" ry="2"/><path d="M7 11V7a5 5 0 0 1 10 0v4"/></svg>
      </div>
    </div>
    <div class="kpi-card__value"><?= $kpiLink('sessions', '/admin/acessos', 'kpi-card__link', '<span class="kpi-card__sr">Sessões ativas: </span>' . (int)($stats['active_sessions'] ?? 0)) ?></div>
    <div class="kpi-card__subtitle">Sessões autenticadas na base relacional</div>
  </div>

  <div class="<?= $kpiCardClass('tickets') ?>">
    <div class="kpi-card__header">
      <span class="kpi-card__title">Chamados suporte</span>
      <div class="kpi-card__icon" style="background-color: var(--admin-copper-50); color: var(--admin-copper-600);">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M4 4h16c1.1 0 2 .9 2 2v12c0 1.1-.9 2-2 2H4c-1.1 0-2-.9-2-2V6c0-1.1.9-2 2-2z"/></svg>
      </div>
    </div>
    <div class="kpi-card__value"><?= $kpiLink('tickets', '/admin/servicedesk?situacao=abertos', 'kpi-card__link', '<span class="kpi-card__sr">Chamados de suporte em aberto: </span>' . (int)($stats['open_tickets'] ?? 0)) ?></div>
    <div class="kpi-card__subtitle"><?= $kpiLink('tickets', '/admin/servicedesk?situacao=sla', 'kpi-card__sublink', (int)($stats['overdue_tickets'] ?? 0) . ' em risco de SLA') ?></div>
  </div>
</div>

<div class="admin-grid-2">
  <!-- Tabela de Usuários por Perfil -->
  <div class="admin-card">
    <div class="admin-card__header">
      <h3 class="admin-card__title">Distribuição de usuários por perfil</h3>
      <a href="/admin/usuarios" class="btn btn--secondary btn--sm">Gerenciar usuários</a>
    </div>
    <div class="admin-table-wrapper">
      <table class="admin-table">
        <thead>
          <tr>
            <th>Perfil / papel</th>
            <th>Total ativos</th>
            <th>Suspensos</th>
            <th>Proporção</th>
          </tr>
        </thead>
        <tbody>
          <?php if (empty($usersByRole)): ?>
            <tr><td colspan="4" style="text-align: center; color: var(--admin-gray-400); padding: 1.5rem;">Nenhum usuário registrado</td></tr>
          <?php else: ?>
            <?php foreach ($usersByRole as $roleRow): ?>
              <?php
                $roleLinks = !empty($kpiCan['users']);
                $roleQuery = 'role=' . rawurlencode((string)$roleRow['role']);
                $roleLabel = htmlspecialchars((string)$roleRow['role']);
              ?>
              <tr>
                <td>
                  <?php if ($roleLinks): ?>
                    <a class="kpi-rolelink" href="/admin/usuarios?<?= $roleQuery ?>" aria-label="Ver todos os usuários do perfil <?= $roleLabel ?>"><strong><?= $roleLabel ?></strong></a>
                  <?php else: ?>
                    <strong><?= $roleLabel ?></strong>
                  <?php endif; ?>
                </td>
                <td>
                  <?php if ($roleLinks && (int)$roleRow['active_count'] > 0): ?>
                    <a href="/admin/usuarios?<?= $roleQuery ?>&amp;status=active" class="badge badge--success kpi-rolelink" aria-label="Ver os <?= (int)$roleRow['active_count'] ?> usuários ativos do perfil <?= $roleLabel ?>"><?= (int)$roleRow['active_count'] ?></a>
                  <?php else: ?>
                    <span class="badge badge--success"><?= (int)$roleRow['active_count'] ?></span>
                  <?php endif; ?>
                </td>
                <td>
                  <?php if ($roleLinks && (int)$roleRow['suspended_count'] > 0): ?>
                    <a href="/admin/usuarios?<?= $roleQuery ?>&amp;status=suspended" class="kpi-rolelink" aria-label="Ver os <?= (int)$roleRow['suspended_count'] ?> usuários suspensos do perfil <?= $roleLabel ?>"><?= (int)$roleRow['suspended_count'] ?></a>
                  <?php else: ?>
                    <?= (int)$roleRow['suspended_count'] ?>
                  <?php endif; ?>
                </td>
                <td>
                  <?php 
                    $totalUsers = max(1, (int)($stats['total_users'] ?? 1));
                    $pct = round(((int)$roleRow['active_count'] / $totalUsers) * 100, 1);
                  ?>
                  <div style="display: flex; align-items: center; gap: 8px;">
                    <div style="flex: 1; height: 6px; background-color: var(--admin-gray-200); border-radius: 9999px; overflow: hidden;">
                      <div style="height: 100%; width: <?= $pct ?>%; background-color: var(--admin-copper-500);"></div>
                    </div>
                    <span style="font-size: 0.75rem; color: var(--admin-gray-500); width: 35px;"><?= $pct ?>%</span>
                  </div>
                </td>
              </tr>
            <?php endforeach; ?>
          <?php endif; ?>
        </tbody>
      </table>
    </div>
  </div>

  <!-- Clínicas Recentes -->
  <div class="admin-card">
    <div class="admin-card__header">
      <h3 class="admin-card__title">Tenants recentes</h3>
      <a href="/admin/clinicas" class="btn btn--secondary btn--sm">Ver todas</a>
    </div>
    <div class="admin-card__body" style="padding: 0;">
      <ul style="list-style: none;">
        <?php if (empty($recentClinics)): ?>
          <li style="padding: 1.25rem; text-align: center; color: var(--admin-gray-400);">Nenhuma clínica encontrada</li>
        <?php else: ?>
          <?php foreach ($recentClinics as $c): ?>
            <li style="padding: 0.85rem 1.25rem; border-bottom: 1px solid var(--admin-gray-200); display: flex; align-items: center; justify-content: space-between;">
              <div>
                <a href="/admin/clinicas/<?= (int)$c['id'] ?>" style="font-weight: 600; color: var(--admin-navy-900); text-decoration: none;">
                  <?= htmlspecialchars($c['name']) ?>
                </a>
                <div style="font-size: 0.75rem; color: var(--admin-gray-500);">
                  <?= htmlspecialchars($c['document'] ?: 'Sem CNPJ/CPF') ?> • Criado em <?= date('d/m/Y', strtotime($c['created_at'])) ?>
                </div>
              </div>
              <span class="badge badge--<?= $c['status'] === 'active' ? 'active' : 'suspended' ?>">
                <?= htmlspecialchars($c['status']) ?>
              </span>
            </li>
          <?php endforeach; ?>
        <?php endif; ?>
      </ul>
    </div>
  </div>
</div>

<?php if ($canReadAudit ?? false): ?>
<!-- Trilha Recente de Auditoria Administrativa Imutável -->
<div class="admin-card">
  <div class="admin-card__header">
    <h3 class="admin-card__title">Atividades e auditoria administrativa recentes</h3>
    <a href="/admin/auditoria" class="btn btn--secondary btn--sm">Ver auditoria completa</a>
  </div>
  <div class="admin-table-wrapper">
    <table class="admin-table">
      <thead>
        <tr>
          <th>Data / hora</th>
          <th>Ação</th>
          <th>Recurso</th>
          <th>ID recurso</th>
          <th>Usuário / operador</th>
          <th>IP origem</th>
        </tr>
      </thead>
      <tbody>
        <?php if (empty($recentAuditLogs)): ?>
          <tr><td colspan="6" style="text-align: center; color: var(--admin-gray-400); padding: 1.5rem;">Nenhum registro de auditoria</td></tr>
        <?php else: ?>
          <?php foreach ($recentAuditLogs as $log): ?>
            <tr>
              <td style="white-space: nowrap; font-size: 0.8rem; color: var(--admin-gray-600);">
                <?= date('d/m/Y H:i:s', strtotime($log['created_at'])) ?>
              </td>
              <td><span class="badge badge--info"><?= htmlspecialchars($log['action']) ?></span></td>
              <td><?= htmlspecialchars($log['resource'] ?? '-') ?></td>
              <td><code><?= htmlspecialchars($log['resource_id'] ?? '-') ?></code></td>
              <td><?= htmlspecialchars($log['user_name'] ?? 'ID ' . ($log['user_id'] ?? 'Sistema')) ?></td>
              <td><small><?= htmlspecialchars($log['ip_address'] ?? '127.0.0.1') ?></small></td>
            </tr>
          <?php endforeach; ?>
        <?php endif; ?>
      </tbody>
    </table>
  </div>
</div>

<?php endif; ?>
