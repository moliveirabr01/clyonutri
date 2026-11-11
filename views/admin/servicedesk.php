<div style="margin-bottom: 1.5rem; display: flex; align-items: center; justify-content: space-between; flex-wrap: wrap; gap: 1rem;">
  <div>
    <h2 style="font-size: 1.5rem; font-weight: 700; color: var(--admin-navy-900);">Painel consolidado de service desk</h2>
    <p style="font-size: 0.88rem; color: var(--admin-gray-500);">Acompanhamento de incidentes, solicitações e SLAs operacionais de todos os tenants.</p>
  </div>
  <a href="/suporte/fila" class="btn btn--primary">
    <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M4 4h16c1.1 0 2 .9 2 2v12c0 1.1-.9 2-2 2H4c-1.1 0-2-.9-2-2V6c0-1.1.9-2 2-2z"/></svg>
    Ir para fila de triagem
  </a>
</div>

<div class="kpi-grid kpi-grid--4">
  <div class="kpi-card">
    <div class="kpi-card__title">Chamados em Aberto</div>
    <div class="kpi-card__value" style="color: var(--admin-copper-600);"><?= (int)($metrics['open_count'] ?? 0) ?></div>
    <div class="kpi-card__subtitle">Aguardando atendimento inicial</div>
  </div>
  <div class="kpi-card">
    <div class="kpi-card__title">Em Atendimento</div>
    <div class="kpi-card__value" style="color: var(--admin-info);"><?= (int)($metrics['in_progress_count'] ?? 0) ?></div>
    <div class="kpi-card__subtitle">Em análise por operadores</div>
  </div>
  <div class="kpi-card">
    <div class="kpi-card__title">Resolvidos / fechados</div>
    <div class="kpi-card__value" style="color: var(--admin-success);"><?= (int)($metrics['resolved_count'] ?? 0) ?></div>
    <div class="kpi-card__subtitle">Histórico consolidado</div>
  </div>
  <div class="kpi-card">
    <div class="kpi-card__title">Risco Crítico / SLA</div>
    <div class="kpi-card__value" style="color: var(--admin-danger);"><?= (int)($metrics['critical_count'] ?? 0) ?></div>
    <div class="kpi-card__subtitle">Prioridade máxima</div>
  </div>
</div>

<div class="admin-card">
  <div class="admin-card__header">
    <h3 class="admin-card__title">Chamados recentes da plataforma</h3>
    <a href="/suporte/fila" class="btn btn--secondary btn--sm">Ver todos</a>
  </div>
  <?php if (($situation ?? '') !== ''): ?>
    <p style="margin: 0; padding: 0.6rem 1.25rem; font-size: 0.82rem; color: var(--admin-gray-600); background: #fafbfc; border-bottom: 1px solid var(--admin-gray-200);">
      Filtro do painel: <?= $situation === 'sla' ? 'chamados em aberto fora do prazo de SLA' : 'chamados em aberto (não resolvidos nem fechados)' ?>.
      <a href="/admin/servicedesk" style="color: var(--admin-navy-900); text-decoration: underline;">Remover este filtro</a>
    </p>
  <?php endif; ?>
  <div class="admin-table-wrapper">
    <table class="admin-table">
      <thead>
        <tr>
          <th>Protocolo</th>
          <th>Clínica</th>
          <th>Assunto</th>
          <th>Categoria</th>
          <th>Prioridade</th>
          <th>Status</th>
          <th>Abertura</th>
          <th style="text-align: right;">Ação</th>
        </tr>
      </thead>
      <tbody>
        <?php if (empty($tickets)): ?>
          <tr><td colspan="8" style="text-align: center; color: var(--admin-gray-400); padding: 2rem;">Nenhum chamado de suporte registrado.</td></tr>
        <?php else: ?>
          <?php foreach ($tickets as $t): ?>
            <tr>
              <td><code><?= htmlspecialchars($t['protocol']) ?></code></td>
              <td><?= htmlspecialchars($t['clinic_name'] ?? 'Clínica #' . $t['clinic_id']) ?></td>
              <td><strong><?= htmlspecialchars($t['subject']) ?></strong></td>
              <td><?= htmlspecialchars($t['category'] ?? 'Geral') ?></td>
              <td>
                <span class="badge badge--<?= ($t['priority_operational'] ?? 'P3') === 'P1' ? 'danger' : 'info' ?>">
                  <?= htmlspecialchars($t['priority_operational'] ?? 'P3') ?>
                </span>
              </td>
              <td>
                <span class="badge badge--<?= in_array($t['status'], ['resolved','closed']) ? 'success' : 'warning' ?>">
                  <?= htmlspecialchars($t['status']) ?>
                </span>
              </td>
              <td><?= date('d/m/Y H:i', strtotime($t['created_at'])) ?></td>
              <td style="text-align: right;">
                <a href="/suporte/chamado/<?= (int)$t['id'] ?>" class="btn btn--secondary btn--sm">Atender</a>
              </td>
            </tr>
          <?php endforeach; ?>
        <?php endif; ?>
      </tbody>
    </table>
  </div>
  <?php require __DIR__ . '/_pagination.php'; ?>
</div>
