<?php
/**
 * Avisos do checkout Asaas (NI2-727; v54.50 enxuto). Espera: $asaasOverview (enabled, latest, idempotency_key, return).
 * v54.50: não há mais card próprio de "Assinar via Asaas". Quando o Asaas está habilitado, o botão de assinar fica
 * dentro de cada plano em _plano_trocar.php. Aqui só ficam o retorno do checkout e o único aviso de pagamento pendente.
 */
$e = static fn($v): string => htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
$az = (array)($asaasOverview ?? []);
$azLatest = (array)($az['latest'] ?? []);
$azStatus = (string)($azLatest['status'] ?? '');
$azReturn = (string)($az['return'] ?? '');
$azPending = !empty($az['enabled']) && in_array($azStatus, ['pending', 'awaiting_payment'], true) && !empty($azLatest['hosted_url']);
?>
<?php if ($azReturn !== ''): ?>
  <div class="op-message op-message--info u-mb-4 u-text-compact" role="status">
    <?php if ($azReturn === 'retorno'): ?>
      <strong>Você voltou da página do Asaas.</strong> O plano muda assim que o Asaas confirmar o pagamento. Se acabou de pagar, recarregue em alguns instantes.
    <?php elseif ($azReturn === 'cancelado'): ?>
      <strong>Checkout cancelado.</strong> Nada foi cobrado e seu plano não mudou.
    <?php else: ?>
      <strong>Checkout expirado.</strong> Nada foi cobrado. Você pode assinar de novo em Planos.
    <?php endif; ?>
  </div>
<?php endif; ?>

<?php if ($azPending): ?>
  <div id="asaas" class="op-message op-message--warning u-mb-6 u-text-compact u-scroll-margin u-flex u-items-center u-justify-between u-wrap u-gap-3" role="status" data-testid="asaas-pending">
    <span><strong>Pagamento do plano <?= $e($azLatest['plan_name'] ?? '') ?> pendente.</strong> Seu plano atual vale até o Asaas confirmar.</span>
    <a href="<?= $e($azLatest['hosted_url']) ?>" class="btn btn-primary" rel="noopener">Continuar pagamento</a>
  </div>
<?php elseif (!empty($az['enabled']) && $azStatus === 'active' && !empty($azLatest['activated_at']) && (time() - strtotime((string)$azLatest['activated_at'])) < 86400): ?>
  <div id="asaas" class="op-message op-message--success u-mb-6 u-text-compact" role="status">
    <strong>Plano <?= $e($azLatest['plan_name'] ?? '') ?> ativado</strong> em <?= $e((new \DateTimeImmutable($azLatest['activated_at']))->format('d/m/Y H:i')) ?> após confirmação do pagamento.
  </div>
<?php endif; ?>
