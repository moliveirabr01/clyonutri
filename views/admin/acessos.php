<div style="margin-bottom: 1.5rem; display: flex; align-items: center; justify-content: space-between; flex-wrap: wrap; gap: 1rem;">
  <div>
    <h2 style="font-size: 1.5rem; font-weight: 700; color: var(--admin-navy-900);">Controle de Acessos & Sessões Ativas</h2>
    <p style="font-size: 0.88rem; color: var(--admin-gray-500);">Monitoramento de sessões simultâneas, integridade de credenciais e revogação forçada de acessos.</p>
  </div>
</div>

<div class="admin-card">
  <div class="admin-card__header">
    <h3 class="admin-card__title">Sessões Ativas Registradas no Banco Relacional</h3>
    <span class="badge badge--info"><?= count($sessions) ?> sessões em andamento</span>
  </div>
  <div class="admin-table-wrapper">
    <table class="admin-table">
      <thead>
        <tr>
          <th>ID Sessão</th>
          <th>Usuário</th>
          <th>Clínica</th>
          <th>Endereço IP</th>
          <th>Navegador / User Agent</th>
          <th>Última Atividade</th>
          <th style="text-align: right;">Ação</th>
        </tr>
      </thead>
      <tbody>
        <?php if (empty($sessions)): ?>
          <tr><td colspan="7" style="text-align: center; color: var(--admin-gray-400); padding: 2rem;">Nenhuma sessão persistida ativa no momento.</td></tr>
        <?php else: ?>
          <?php foreach ($sessions as $s): ?>
            <tr>
              <td><code><?= htmlspecialchars(substr($s['id'], 0, 16)) ?>...</code></td>
              <td>
                <strong><?= htmlspecialchars($s['user_name'] ?? 'Usuário #' . $s['user_id']) ?></strong>
                <div style="font-size: 0.75rem; color: var(--admin-gray-500);"><?= htmlspecialchars($s['user_email'] ?? '') ?></div>
              </td>
              <td><?= htmlspecialchars($s['clinic_name'] ?? 'Clínica #' . $s['clinic_id']) ?></td>
              <td><code><?= htmlspecialchars($s['ip_address'] ?? '127.0.0.1') ?></code></td>
              <td style="max-width: 250px; overflow: hidden; text-overflow: ellipsis; white-space: nowrap;">
                <small title="<?= htmlspecialchars($s['user_agent'] ?? '') ?>"><?= htmlspecialchars($s['user_agent'] ?? 'Desconhecido') ?></small>
              </td>
              <td><?= !empty($s['last_activity']) ? date('d/m/Y H:i:s', (int)$s['last_activity']) : '-' ?></td>
              <td style="text-align: right;">
                <form action="/admin/acessos/revogar-sessao" method="POST" style="display: inline-block;">
                  <input type="hidden" name="_csrf_token" value="<?= htmlspecialchars($csrfToken ?? '') ?>">
                  <input type="hidden" name="session_id" value="<?= htmlspecialchars($s['id']) ?>">
                  <button type="submit" class="btn btn--danger btn--sm" onclick="return confirm('Deseja revogar esta sessão imediatamente?')">Revogar</button>
                </form>
              </td>
            </tr>
          <?php endforeach; ?>
        <?php endif; ?>
      </tbody>
    </table>
  </div>
</div>
