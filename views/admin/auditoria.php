<div style="margin-bottom: 1.5rem; display: flex; align-items: center; justify-content: space-between; flex-wrap: wrap; gap: 1rem;">
  <div>
    <h2 style="font-size: 1.5rem; font-weight: 700; color: var(--admin-navy-900);">Trilha Imutável de Auditoria</h2>
    <p style="font-size: 0.88rem; color: var(--admin-gray-500);">Registro criptográfico e sequencial de todas as operações administrativas e eventos de segurança.</p>
  </div>
</div>

<div class="admin-card">
  <div class="admin-card__header">
    <form action="/admin/auditoria" method="GET" style="display: flex; gap: 0.75rem; flex-wrap: wrap; width: 100%; max-width: 650px;">
      <input type="text" name="action" value="<?= htmlspecialchars($_GET['action'] ?? '') ?>" placeholder="Filtrar por ação (ex: SECURITY, LOGIN, ADMIN)..." style="flex: 1; padding: 0.45rem 0.75rem; border: 1px solid var(--admin-gray-300); border-radius: 6px; font-size: 0.85rem;">
      <button type="submit" class="btn btn--secondary btn--sm">Filtrar</button>
      <?php if (!empty($_GET['action'])): ?>
        <a href="/admin/auditoria" class="btn btn--secondary btn--sm">Limpar</a>
      <?php endif; ?>
    </form>
    <div style="font-size: 0.85rem; color: var(--admin-gray-500);">
      Exibindo últimos <strong><?= count($auditLogs) ?></strong> registros
    </div>
  </div>

  <div class="admin-table-wrapper">
    <table class="admin-table">
      <thead>
        <tr>
          <th>ID</th>
          <th>Data / Hora</th>
          <th>Ação / Evento</th>
          <th>Recurso</th>
          <th>ID Recurso</th>
          <th>Usuário Responsável</th>
          <th>Clínica ID</th>
          <th>IP Origem</th>
          <th>Detalhes / Payload</th>
        </tr>
      </thead>
      <tbody>
        <?php if (empty($auditLogs)): ?>
          <tr><td colspan="9" style="text-align: center; color: var(--admin-gray-400); padding: 2rem;">Nenhum registro de auditoria encontrado.</td></tr>
        <?php else: ?>
          <?php foreach ($auditLogs as $log): ?>
            <tr>
              <td><code>#<?= (int)$log['id'] ?></code></td>
              <td style="white-space: nowrap; font-size: 0.8rem; color: var(--admin-gray-600);">
                <?= date('d/m/Y H:i:s', strtotime($log['created_at'])) ?>
              </td>
              <td><span class="badge badge--info"><?= htmlspecialchars($log['action']) ?></span></td>
              <td><?= htmlspecialchars($log['resource'] ?? '-') ?></td>
              <td><code><?= htmlspecialchars($log['resource_id'] ?? '-') ?></code></td>
              <td><?= htmlspecialchars($log['user_name'] ?? 'ID ' . ($log['user_id'] ?? 'Sistema')) ?></td>
              <td><?= htmlspecialchars($log['clinic_id'] ? '#' . $log['clinic_id'] : 'Global') ?></td>
              <td><small><?= htmlspecialchars($log['ip_address'] ?? '127.0.0.1') ?></small></td>
              <td style="max-width: 250px; overflow: hidden; text-overflow: ellipsis; white-space: nowrap;">
                <code title="<?= htmlspecialchars($log['details'] ?? '') ?>"><?= htmlspecialchars(substr($log['details'] ?? '', 0, 60)) ?></code>
              </td>
            </tr>
          <?php endforeach; ?>
        <?php endif; ?>
      </tbody>
    </table>
  </div>
</div>
