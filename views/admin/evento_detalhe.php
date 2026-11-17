<?php
/**
 * View de Detalhe e Investigação de Evento de Produção (NI2-767 / NI2-773 / NI2-778).
 */
$sevBadgeClass = match($event['severity'] ?? 'info') {
    'critical' => 'badge--danger',
    'error' => 'badge--danger',
    'warning' => 'badge--warning',
    'notice' => 'badge--info',
    default => 'badge--secondary',
};
$outcomeBadgeClass = match($event['outcome'] ?? 'success') {
    'success' => 'badge--success',
    'failure' => 'badge--danger',
    'denied' => 'badge--warning',
    'degraded' => 'badge--warning',
    default => 'badge--secondary',
};
?>
<div style="margin-bottom: 1.5rem; display: flex; align-items: center; justify-content: space-between; flex-wrap: wrap; gap: 1rem;">
  <div>
    <div style="font-size: 0.82rem; color: var(--admin-gray-500); margin-bottom: 0.25rem;">
      <a href="/admin/eventos" style="color: inherit; text-decoration: none;">&larr; Voltar para a Central de Eventos</a>
    </div>
    <h2 style="font-size: 1.5rem; font-weight: 700; color: var(--admin-navy-900); display: flex; align-items: center; gap: 0.75rem; flex-wrap: wrap;">
      <?= htmlspecialchars($event['event_type']) ?>
      <span class="badge <?= $sevBadgeClass ?>"><?= strtoupper(htmlspecialchars($event['severity'])) ?></span>
      <span class="badge <?= $outcomeBadgeClass ?>"><?= strtoupper(htmlspecialchars($event['outcome'])) ?></span>
    </h2>
    <div style="font-size: 0.85rem; color: var(--admin-gray-500); margin-top: 0.25rem;">
      ID Único: <code><?= htmlspecialchars($event['event_id']) ?></code> • Ocorrido em: <?= date('d/m/Y H:i:s \U\T\C', strtotime($event['occurred_at'])) ?>
    </div>
  </div>

  <div style="display: flex; gap: 0.75rem;">
    <form action="/admin/eventos/<?= htmlspecialchars($event['event_id']) ?>/criar-incidente" method="POST" data-confirm="Confirmar abertura de chamado de incidente no Service Desk com evidências sanitizadas?">
      <?= \ClyoNutri\Core\Security\Csrf::input() ?>
      <button type="submit" class="btn btn--danger btn--sm">
        <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" style="margin-right: 4px; vertical-align: -2px;"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg>
        Abrir incidente no Service Desk
      </button>
    </form>
  </div>
</div>

<div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(320px, 1fr)); gap: 1.5rem; margin-bottom: 1.5rem;">
  <!-- Bloco de Contexto Operacional -->
  <div class="admin-card">
    <div class="admin-card__header">
      <h3 class="admin-card__title">Contexto & Identificadores</h3>
    </div>
    <div class="admin-card__body">
      <dl style="display: grid; grid-template-columns: 140px 1fr; gap: 0.65rem 1rem; font-size: 0.85rem; margin: 0;">
        <dt style="font-weight: 600; color: var(--admin-gray-600);">Categoria:</dt>
        <dd style="margin: 0;"><span class="badge badge--info"><?= htmlspecialchars($event['category']) ?></span></dd>

        <dt style="font-weight: 600; color: var(--admin-gray-600);">Serviço:</dt>
        <dd style="margin: 0;"><?= htmlspecialchars($event['service']) ?> (<?= htmlspecialchars($event['environment']) ?>)</dd>

        <dt style="font-weight: 600; color: var(--admin-gray-600);">Clínica:</dt>
        <dd style="margin: 0;"><?= $event['clinic_id'] ? '#' . (int)$event['clinic_id'] : 'Global / Sistema' ?></dd>

        <dt style="font-weight: 600; color: var(--admin-gray-600);">Ator Responsável:</dt>
        <dd style="margin: 0;"><?= htmlspecialchars($event['actor_type']) ?> <?= $event['actor_id'] ? '(ID: ' . htmlspecialchars($event['actor_id']) . ')' : '' ?></dd>

        <dt style="font-weight: 600; color: var(--admin-gray-600);">Recurso Alvo:</dt>
        <dd style="margin: 0;"><?= htmlspecialchars($event['resource_type'] ?? 'N/A') ?> <code><?= htmlspecialchars($event['resource_ref'] ?? '') ?></code></dd>

        <dt style="font-weight: 600; color: var(--admin-gray-600);">Duração:</dt>
        <dd style="margin: 0;"><?= $event['duration_ms'] !== null ? $event['duration_ms'] . ' ms' : 'N/A' ?></dd>

        <dt style="font-weight: 600; color: var(--admin-gray-600);">Tentativas (Retries):</dt>
        <dd style="margin: 0;"><?= (int)$event['retry_count'] ?></dd>

        <dt style="font-weight: 600; color: var(--admin-gray-600);">Correlation ID:</dt>
        <dd style="margin: 0;"><code><?= htmlspecialchars($event['correlation_id'] ?? 'Sem correlação') ?></code></dd>

        <dt style="font-weight: 600; color: var(--admin-gray-600);">Request ID:</dt>
        <dd style="margin: 0;"><code><?= htmlspecialchars($event['request_id'] ?? 'N/A') ?></code></dd>
      </dl>
    </div>
  </div>

  <!-- Bloco de Metadados Sanitizados -->
  <div class="admin-card">
    <div class="admin-card__header">
      <h3 class="admin-card__title">Metadados Auditáveis (Sem PHI)</h3>
    </div>
    <div class="admin-card__body">
      <?php if (empty($event['metadata'])): ?>
        <p style="color: var(--admin-gray-400); font-size: 0.85rem; margin: 0;">Nenhum metadado adicional registrado para este evento.</p>
      <?php else: ?>
        <pre style="background: #F8FAFC; border: 1px solid #E2E8F0; border-radius: 6px; padding: 1rem; font-size: 0.8rem; overflow-x: auto; margin: 0;"><code><?= htmlspecialchars(json_encode($event['metadata'], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)) ?></code></pre>
      <?php endif; ?>
    </div>
  </div>
</div>

<!-- Linha do Tempo Correlacionada (Mesmo correlation_id) -->
<?php if (!empty($timeline) && count($timeline) > 1): ?>
  <div class="admin-card">
    <div class="admin-card__header">
      <h3 class="admin-card__title">Rastreabilidade em Cadeia: Linha do Tempo Correlacionada (<?= count($timeline) ?> eventos)</h3>
    </div>
    <div class="admin-table-wrapper">
      <table class="admin-table">
        <thead>
          <tr>
            <th>Ordem</th>
            <th>Horário (UTC)</th>
            <th>Severidade</th>
            <th>Categoria</th>
            <th>Tipo de Evento</th>
            <th>Resultado</th>
            <th>Ação</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($timeline as $idx => $te): ?>
            <tr style="<?= $te['event_id'] === $event['event_id'] ? 'background-color: #F0FDF4;' : '' ?>">
              <td>#<?= $idx + 1 ?></td>
              <td style="font-size: 0.8rem; color: var(--admin-gray-600);"><?= date('d/m/Y H:i:s', strtotime($te['occurred_at'])) ?></td>
              <td><span class="badge badge--sm"><?= htmlspecialchars(strtoupper($te['severity'])) ?></span></td>
              <td><span class="badge badge--info badge--sm"><?= htmlspecialchars($te['category']) ?></span></td>
              <td style="font-weight: 600;">
                <a href="/admin/eventos/<?= htmlspecialchars($te['event_id']) ?>"><?= htmlspecialchars($te['event_type']) ?></a>
              </td>
              <td><span class="badge badge--sm"><?= htmlspecialchars(strtoupper($te['outcome'])) ?></span></td>
              <td>
                <?php if ($te['event_id'] === $event['event_id']): ?>
                  <span style="font-size: 0.75rem; font-weight: 700; color: #16A34A;">Evento Atual</span>
                <?php else: ?>
                  <a href="/admin/eventos/<?= htmlspecialchars($te['event_id']) ?>" class="btn btn--secondary btn--sm">Abrir</a>
                <?php endif; ?>
              </td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </div>
<?php endif; ?>
