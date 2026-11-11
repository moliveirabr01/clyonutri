<?php
/**
 * View da Central de Eventos de Produção no Backoffice (NI2-767 / NI2-772 / NI2-773).
 *
 * Exibe métricas de saúde operacional, painel de filtros avançados e timeline de eventos investigáveis.
 */
?>
<div style="margin-bottom: 1.5rem; display: flex; align-items: center; justify-content: space-between; flex-wrap: wrap; gap: 1rem;">
  <div>
    <h2 style="font-size: 1.5rem; font-weight: 700; color: var(--admin-navy-900);">Central de Eventos de Produção</h2>
    <p style="font-size: 0.88rem; color: var(--admin-gray-500);">Observabilidade em tempo real, rastreabilidade de incidentes e telemetria de segurança sem dados clínicos.</p>
  </div>
  <div style="display: flex; gap: 0.75rem;">
    <a href="/admin/eventos/exportar<?= !empty($_SERVER['QUERY_STRING']) ? '?' . htmlspecialchars($_SERVER['QUERY_STRING']) : '' ?>" class="btn btn--secondary btn--sm">
      <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" style="margin-right: 4px; vertical-align: -2px;"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="7 10 12 15 17 10"/><line x1="12" y1="15" x2="12" y2="3"/></svg>
      Exportar eventos (JSON)
    </a>
  </div>
</div>

<!-- Cartões de Resumo Executivo (KPIs 24h) -->
<div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(210px, 1fr)); gap: 1rem; margin-bottom: 1.5rem;">
  <div class="admin-card" style="padding: 1.25rem;">
    <div style="font-size: 0.8rem; font-weight: 600; color: var(--admin-gray-500); text-transform: uppercase;">Volume 24h</div>
    <div style="font-size: 1.8rem; font-weight: 700; color: var(--admin-navy-900); margin-top: 0.25rem;">
      <?= number_format((int)($summary['total_24h'] ?? 0), 0, ',', '.') ?>
    </div>
    <div style="font-size: 0.75rem; color: var(--admin-gray-400); margin-top: 0.25rem;">Eventos registrados no período</div>
  </div>

  <div class="admin-card" style="padding: 1.25rem;">
    <div style="font-size: 0.8rem; font-weight: 600; color: var(--admin-gray-500); text-transform: uppercase;">Taxa de Erro 24h</div>
    <div style="font-size: 1.8rem; font-weight: 700; color: <?= ($summary['error_rate'] ?? 0) > 1.0 ? '#DC2626' : '#16A34A' ?>; margin-top: 0.25rem;">
      <?= number_format((float)($summary['error_rate'] ?? 0.0), 2, ',', '.') ?>%
    </div>
    <div style="font-size: 0.75rem; color: var(--admin-gray-400); margin-top: 0.25rem;">
      <?= (int)($summary['errors_24h'] ?? 0) ?> falhas de <?= (int)($summary['total_24h'] ?? 0) ?> eventos
    </div>
  </div>

  <div class="admin-card" style="padding: 1.25rem;">
    <div style="font-size: 0.8rem; font-weight: 600; color: var(--admin-gray-500); text-transform: uppercase;">Alertas Ativos</div>
    <div style="font-size: 1.8rem; font-weight: 700; color: <?= ($summary['active_alerts_count'] ?? 0) > 0 ? '#EAB308' : '#16A34A' ?>; margin-top: 0.25rem;">
      <?= (int)($summary['active_alerts_count'] ?? 0) ?>
    </div>
    <div style="font-size: 0.75rem; color: var(--admin-gray-400); margin-top: 0.25rem;">Anomalias em monitoramento</div>
  </div>

  <div class="admin-card" style="padding: 1.25rem;">
    <div style="font-size: 0.8rem; font-weight: 600; color: var(--admin-gray-500); text-transform: uppercase;">Segurança & Auth</div>
    <div style="font-size: 1.8rem; font-weight: 700; color: var(--admin-navy-900); margin-top: 0.25rem;">
      <?= (int)(($summary['categories']['AUTH'] ?? 0) + ($summary['categories']['SECURITY'] ?? 0)) ?>
    </div>
    <div style="font-size: 0.75rem; color: var(--admin-gray-400); margin-top: 0.25rem;">Autenticações e acessos auditados</div>
  </div>
</div>

<!-- Filtros Investigativos -->
<div class="admin-card" style="margin-bottom: 1.5rem;">
  <div class="admin-card__header">
    <h3 class="admin-card__title">Filtros de Investigação</h3>
  </div>
  <div class="admin-card__body">
    <form action="/admin/eventos" method="GET" style="display: grid; grid-template-columns: repeat(auto-fit, minmax(180px, 1fr)); gap: 1rem; align-items: flex-end;">
      <div>
        <label style="display: block; font-size: 0.75rem; font-weight: 600; color: var(--admin-gray-600); margin-bottom: 0.35rem;">Categoria</label>
        <select name="category" style="width: 100%; padding: 0.45rem 0.65rem; border: 1px solid var(--border-input, #828B9B); border-radius: 6px; font-size: 0.85rem;">
          <option value="">Todas as categorias</option>
          <?php foreach (\ClyoNutri\Domain\Observability\ProductionEvent::CATEGORIES as $cat): ?>
            <option value="<?= $cat ?>" <?= ($filters['category'] ?? '') === $cat ? 'selected' : '' ?>><?= $cat ?></option>
          <?php endforeach; ?>
        </select>
      </div>

      <div>
        <label style="display: block; font-size: 0.75rem; font-weight: 600; color: var(--admin-gray-600); margin-bottom: 0.35rem;">Severidade</label>
        <select name="severity" style="width: 100%; padding: 0.45rem 0.65rem; border: 1px solid var(--border-input, #828B9B); border-radius: 6px; font-size: 0.85rem;">
          <option value="">Todas</option>
          <?php foreach (\ClyoNutri\Domain\Observability\ProductionEvent::SEVERITIES as $sev): ?>
            <option value="<?= $sev ?>" <?= ($filters['severity'] ?? '') === $sev ? 'selected' : '' ?>><?= ucfirst($sev) ?></option>
          <?php endforeach; ?>
        </select>
      </div>

      <div>
        <label style="display: block; font-size: 0.75rem; font-weight: 600; color: var(--admin-gray-600); margin-bottom: 0.35rem;">Resultado</label>
        <select name="outcome" style="width: 100%; padding: 0.45rem 0.65rem; border: 1px solid var(--border-input, #828B9B); border-radius: 6px; font-size: 0.85rem;">
          <option value="">Todos</option>
          <?php foreach (\ClyoNutri\Domain\Observability\ProductionEvent::OUTCOMES as $out): ?>
            <option value="<?= $out ?>" <?= ($filters['outcome'] ?? '') === $out ? 'selected' : '' ?>><?= ucfirst($out) ?></option>
          <?php endforeach; ?>
        </select>
      </div>

      <div>
        <label style="display: block; font-size: 0.75rem; font-weight: 600; color: var(--admin-gray-600); margin-bottom: 0.35rem;">Tipo de Evento</label>
        <input type="text" name="event_type" value="<?= htmlspecialchars($filters['event_type'] ?? '') ?>" placeholder="ex: LOGIN_SUCCESS, APPOINTMENT" style="width: 100%; padding: 0.45rem 0.65rem; border: 1px solid var(--border-input, #828B9B); border-radius: 6px; font-size: 0.85rem;">
      </div>

      <div>
        <label style="display: block; font-size: 0.75rem; font-weight: 600; color: var(--admin-gray-600); margin-bottom: 0.35rem;">Correlação (ID)</label>
        <input type="text" name="correlation_id" value="<?= htmlspecialchars($filters['correlation_id'] ?? '') ?>" placeholder="Buscar rastreamento..." style="width: 100%; padding: 0.45rem 0.65rem; border: 1px solid var(--border-input, #828B9B); border-radius: 6px; font-size: 0.85rem;">
      </div>

      <div style="display: flex; gap: 0.5rem;">
        <button type="submit" class="btn btn--primary btn--sm" style="flex: 1;">Filtrar</button>
        <?php if (!empty($paginationQuery)): ?>
          <a href="/admin/eventos" class="btn btn--secondary btn--sm">Limpar</a>
        <?php endif; ?>
      </div>
    </form>
  </div>
</div>

<!-- Tabela da Timeline de Eventos -->
<div class="admin-card">
  <div class="admin-card__header" style="display: flex; justify-content: space-between; align-items: center;">
    <h3 class="admin-card__title">Timeline Operacional de Produção</h3>
    <div style="font-size: 0.85rem; color: var(--admin-gray-500);">
      Exibindo <strong><?= count($events) ?></strong> de <strong><?= (int)$pagination['total_records'] ?></strong> eventos
    </div>
  </div>

  <div class="admin-table-wrapper">
    <table class="admin-table">
      <thead>
        <tr>
          <th>Horário (UTC)</th>
          <th>Severidade</th>
          <th>Categoria</th>
          <th>Tipo de Evento</th>
          <th>Resultado</th>
          <th>Clínica</th>
          <th>Ator / Origem</th>
          <th>Correlação</th>
          <th style="text-align: right;">Ações</th>
        </tr>
      </thead>
      <tbody>
        <?php if (empty($events)): ?>
          <tr>
            <td colspan="9" style="text-align: center; color: var(--admin-gray-400); padding: 3rem;">
              Nenhum evento operacional encontrado para os filtros selecionados.
            </td>
          </tr>
        <?php else: ?>
          <?php foreach ($events as $ev): ?>
            <?php
              $sevBadgeClass = match($ev['severity']) {
                'critical' => 'badge--danger',
                'error' => 'badge--danger',
                'warning' => 'badge--warning',
                'notice' => 'badge--info',
                default => 'badge--secondary',
              };
              $outcomeBadgeClass = match($ev['outcome']) {
                'success' => 'badge--success',
                'failure' => 'badge--danger',
                'denied' => 'badge--warning',
                'degraded' => 'badge--warning',
                default => 'badge--secondary',
              };
            ?>
            <tr>
              <td style="white-space: nowrap; font-size: 0.8rem; color: var(--admin-gray-600);">
                <?= date('d/m/Y H:i:s', strtotime($ev['occurred_at'])) ?>
              </td>
              <td><span class="badge <?= $sevBadgeClass ?>"><?= htmlspecialchars(strtoupper($ev['severity'])) ?></span></td>
              <td><span class="badge badge--info"><?= htmlspecialchars($ev['category']) ?></span></td>
              <td style="font-weight: 600; color: var(--admin-navy-900);">
                <a href="/admin/eventos/<?= htmlspecialchars($ev['event_id']) ?>" style="color: inherit; text-decoration: none;">
                  <?= htmlspecialchars($ev['event_type']) ?>
                </a>
              </td>
              <td><span class="badge <?= $outcomeBadgeClass ?>"><?= htmlspecialchars(strtoupper($ev['outcome'])) ?></span></td>
              <td><?= $ev['clinic_id'] ? '#' . (int)$ev['clinic_id'] : '<span style="color: var(--admin-gray-400);">Global</span>' ?></td>
              <td><small><?= htmlspecialchars($ev['actor_type'] . ($ev['actor_id'] ? ':' . substr($ev['actor_id'], 0, 8) : '')) ?></small></td>
              <td>
                <?php if (!empty($ev['correlation_id'])): ?>
                  <a href="/admin/eventos?correlation_id=<?= urlencode($ev['correlation_id']) ?>" title="Filtrar por esta correlação" style="font-family: monospace; font-size: 0.78rem; text-decoration: underline;">
                    <?= htmlspecialchars(substr($ev['correlation_id'], 0, 10)) ?>...
                  </a>
                <?php else: ?>
                  <span style="color: var(--admin-gray-400);">-</span>
                <?php endif; ?>
              </td>
              <td style="text-align: right; white-space: nowrap;">
                <a href="/admin/eventos/<?= htmlspecialchars($ev['event_id']) ?>" class="btn btn--secondary btn--sm">
                  Investigar
                </a>
              </td>
            </tr>
          <?php endforeach; ?>
        <?php endif; ?>
      </tbody>
    </table>
  </div>

  <?php require __DIR__ . '/_pagination.php'; ?>
</div>
