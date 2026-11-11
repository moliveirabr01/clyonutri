<?php
/**
 * v37: trocas de plano e pedidos de exclusão de conta, no Comercial.
 * Espera: $planChangesList, $planChangesLast30, $deletionRequests, $deletionOpenCount, $canManageDeletions, $csrfToken.
 */
$e = static fn($v): string => htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
$brlC = static fn($c): string => \ClyoNutri\Domain\Subscription\PlanChangeService::formatBrl((int)$c);
$dt = static fn($v): string => $v ? (new \DateTimeImmutable((string)$v))->format('d/m/Y H:i') : '-';
$d = static fn($v): string => $v ? (new \DateTimeImmutable((string)$v))->format('d/m/Y') : '-';
$typeLabel = ['upgrade' => 'Upgrade', 'downgrade' => 'Downgrade'];
$delLabels = \ClyoNutri\Domain\Privacy\AccountDeletionRequestService::STATUS_LABELS;
$delBadge = ['pending' => 'warning', 'in_review' => 'info', 'completed' => 'success', 'rejected' => 'danger', 'canceled' => 'pending'];
?>
<div class="admin-card" id="trocas-de-plano" style="margin-top: 1.5rem; scroll-margin-top: 90px;">
  <div class="admin-card__header">
    <h3 class="admin-card__title">Trocas de plano pedidas pelos consultórios</h3>
    <div style="font-size: 0.85rem; color: var(--admin-gray-500);"><strong><?= (int)($planChangesLast30 ?? 0) ?></strong> nos últimos 30 dias</div>
  </div>
  <p style="margin: 0 1rem 0.75rem; font-size: 0.85rem; color: var(--admin-gray-500);">A troca já vale para o consultório. A cobrança é manual: entre em contato para acertar a diferença de valor. Não há fatura gerada pelo sistema.</p>
  <div class="admin-table-wrapper">
    <table class="admin-table">
      <thead>
        <tr><th>Quando</th><th>Consultório</th><th>Tipo</th><th>De</th><th>Para</th><th>Diferença mensal</th><th>Protocolo</th></tr>
      </thead>
      <tbody>
        <?php if (empty($planChangesList)): ?>
          <tr><td colspan="7" style="text-align: center; color: var(--admin-gray-400); padding: 1.5rem;">Nenhuma troca de plano registrada.</td></tr>
        <?php else: foreach ($planChangesList as $pc):
            $diff = (int)$pc['to_price_cents'] - (int)$pc['from_price_cents']; ?>
          <tr>
            <td><?= $e($dt($pc['effective_at'])) ?></td>
            <td>
              <?= $e($pc['clinic_name']) ?> <small style="color: var(--admin-gray-400);">#<?= (int)$pc['clinic_id'] ?></small>
              <?php if (!empty($pc['requested_by_name'])): ?><br><small style="color: var(--admin-gray-500);">por <?= $e($pc['requested_by_name']) ?></small><?php endif; ?>
            </td>
            <td><span class="badge badge--<?= $pc['change_type'] === 'upgrade' ? 'success' : 'warning' ?>"><?= $e($typeLabel[$pc['change_type']] ?? $pc['change_type']) ?></span></td>
            <td><?= $e($pc['from_name']) ?></td>
            <td><?= $e($pc['to_name']) ?></td>
            <td><?= $diff >= 0 ? '+' : '-' ?><?= $e($brlC(abs($diff))) ?></td>
            <td><code><?= $e($pc['protocol']) ?></code></td>
          </tr>
        <?php endforeach; endif; ?>
      </tbody>
    </table>
  </div>
</div>

<?php if (!empty($canManageDeletions)): ?>
<div class="admin-card" id="pedidos-conta" style="margin-top: 1.5rem; scroll-margin-top: 90px;">
  <div class="admin-card__header">
    <h3 class="admin-card__title">Pedidos de exclusão de conta (LGPD)</h3>
    <div style="font-size: 0.85rem; color: var(--admin-gray-500);"><strong><?= (int)($deletionOpenCount ?? 0) ?></strong> em aberto</div>
  </div>
  <p style="margin: 0 1rem 0.75rem; font-size: 0.85rem; color: var(--admin-gray-500);">Nenhum dado é apagado pelo sistema. Analise cada pedido, trate a exclusão ou a anonimização manualmente (respeitando a retenção legal de prontuário) e registre o desfecho aqui.</p>
  <div class="admin-table-wrapper">
    <table class="admin-table">
      <thead>
        <tr><th>Consultório</th><th>Pedido</th><th>Prazo</th><th>Situação</th><th style="min-width: 260px;">Ação da equipe</th></tr>
      </thead>
      <tbody>
        <?php if (empty($deletionRequests)): ?>
          <tr><td colspan="5" style="text-align: center; color: var(--admin-gray-400); padding: 1.5rem;">Nenhum pedido de exclusão de conta.</td></tr>
        <?php else: foreach ($deletionRequests as $dr): ?>
          <tr>
            <td>
              <?= $e($dr['clinic_name']) ?> <small style="color: var(--admin-gray-400);">#<?= (int)$dr['clinic_id'] ?></small>
              <br><small style="color: var(--admin-gray-500);"><?= $e($dr['requester_name']) ?> (<?= $e($dr['requester_role']) ?>)</small>
            </td>
            <td>
              <code><?= $e($dr['protocol']) ?></code><br>
              <small style="color: var(--admin-gray-500);"><?= $e($dt($dr['requested_at'])) ?></small>
              <?php if (!empty($dr['reason_text'])): ?><br><small style="color: var(--admin-gray-500);">Motivo: <?= $e($dr['reason_text']) ?></small><?php endif; ?>
            </td>
            <td>
              <?= $e($d($dr['deadline_at'])) ?>
              <?php if (!empty($dr['is_overdue'])): ?><br><span class="badge badge--danger">Prazo vencido</span><?php endif; ?>
            </td>
            <td>
              <span class="badge badge--<?= $e($delBadge[$dr['status']] ?? 'pending') ?>"><?= $e($delLabels[$dr['status']] ?? $dr['status']) ?></span>
              <?php if (!empty($dr['resolution_note'])): ?><br><small style="color: var(--admin-gray-500);"><?= $e($dr['resolution_note']) ?></small><?php endif; ?>
            </td>
            <td>
              <?php if (!empty($dr['is_open'])): ?>
                <?php if ($dr['status'] === 'pending'): ?>
                  <form action="/admin/pedidos-exclusao/<?= (int)$dr['id'] ?>/analise" method="POST" style="margin-bottom: 8px;">
                    <input type="hidden" name="_csrf_token" value="<?= $e($csrfToken ?? '') ?>">
                    <button type="submit" class="btn btn--secondary btn--sm">Marcar como em análise</button>
                  </form>
                <?php endif; ?>
                <form action="/admin/pedidos-exclusao/<?= (int)$dr['id'] ?>/encerrar" method="POST" style="display: grid; gap: 6px;">
                  <input type="hidden" name="_csrf_token" value="<?= $e($csrfToken ?? '') ?>">
                  <label class="admin-sr-only" for="note-<?= (int)$dr['id'] ?>" style="position: absolute; left: -9999px;">Registro do que foi feito</label>
                  <textarea id="note-<?= (int)$dr['id'] ?>" name="note" rows="2" required minlength="10" maxlength="1000" placeholder="O que foi apagado, anonimizado ou mantido, e por quê" style="padding: 0.4rem 0.6rem; border: 1px solid var(--border-input, #828B9B); border-radius: 6px; font-size: 0.82rem;"></textarea>
                  <div style="display: flex; gap: 6px; flex-wrap: wrap;">
                    <button type="submit" name="outcome" value="completed" class="btn btn--primary btn--sm" data-confirm="Registrar que a equipe concluiu o tratamento deste pedido? O sistema não apaga nada: isso só fecha o registro.">Concluir</button>
                    <button type="submit" name="outcome" value="rejected" class="btn btn--secondary btn--sm" data-confirm="Encerrar o pedido sem exclusão (por exemplo, retenção legal)?">Encerrar sem exclusão</button>
                  </div>
                </form>
              <?php else: ?>
                <small style="color: var(--admin-gray-500);">Encerrado<?= !empty($dr['resolved_at']) ? ' em ' . $e($dt($dr['resolved_at'])) : (!empty($dr['canceled_at']) ? ' em ' . $e($dt($dr['canceled_at'])) : '') ?></small>
              <?php endif; ?>
            </td>
          </tr>
        <?php endforeach; endif; ?>
      </tbody>
    </table>
  </div>
</div>
<?php endif; ?>
