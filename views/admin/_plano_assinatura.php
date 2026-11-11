<?php
/**
 * Célula "Plano" do backoffice: código e nome do plano com a situação da assinatura.
 *
 * Entrada: $planInfo = ['code' => ..., 'name' => ..., 'status' => ...] ou null (clínica sem assinatura).
 * Só dados comerciais. Quem inclui este arquivo já checou billing.read.
 */

use ClyoNutri\Domain\Subscription\CommercialSubscribers;

$planInfo = $planInfo ?? null;
?>
<?php if (is_array($planInfo)): ?>
  <div style="font-weight: 600; color: var(--admin-navy-900);"><code><?= htmlspecialchars((string)$planInfo['code']) ?></code></div>
  <small style="color: var(--admin-gray-500);"><?= htmlspecialchars((string)$planInfo['name']) ?></small><br>
  <span class="badge badge--<?= htmlspecialchars(CommercialSubscribers::statusBadge((string)$planInfo['status'])) ?>"><?= htmlspecialchars(CommercialSubscribers::statusLabel((string)$planInfo['status'])) ?></span>
<?php else: ?>
  <span style="color: var(--admin-gray-500);">Sem assinatura</span>
<?php endif; ?>
