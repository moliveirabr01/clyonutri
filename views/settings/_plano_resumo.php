<?php
/**
 * Resumo "Seu plano" no perfil do nutricionista (v37).
 * Espera: $planSummary (getClinicSubscription), $planCanManage, $planOpenDeletion.
 * Quem não é titular/administrador vê o nome do plano, sem preço e sem botões.
 */
$e = static fn($v): string => htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
$st = (string)$planSummary['status'];
$stLabel = ['trialing' => 'Em avaliação', 'active' => 'Ativa', 'expired' => 'Teste encerrado', 'canceled' => 'Cancelada'][$st] ?? $st;
$stClass = $st === 'active' ? 'badge-success' : ($st === 'canceled' ? 'badge-danger' : 'badge-warning');
$usage = (array)($planSummary['usage'] ?? []);
// NI2-614: "8 de 10" com limite; "8 (sem limite)" sem limite (antes saía "8 de sem limite").
$usageOf = static fn($used, $max): string => (int)$max > 0 ? (int)$used . ' de ' . (int)$max : (int)$used . ' (sem limite)';
$end = in_array($st, ['trialing', 'expired'], true) ? ($planSummary['trial_ends_at'] ?? $planSummary['current_period_end'] ?? null) : ($planSummary['current_period_end'] ?? null);
?>
<section id="seu-plano" class="card u-mb-6 u-accent-top u-accent-brand-copper-500" aria-labelledby="seu-plano-titulo">
  <div class="u-flex u-justify-between u-items-start u-wrap u-gap-3">
    <div>
      <h2 id="seu-plano-titulo" class="u-m-0">Seu plano</h2>
      <div class="u-text-h3 u-font-bold u-color-botanical-forest-900"><?= $e($planSummary['plan_name']) ?></div>
    </div>
    <span class="<?= $stClass ?> u-text-compact u-font-bold u-py-1 u-px-3 u-radius-pill"><?= $e($stLabel) ?></span>
  </div>

  <?php if ($planCanManage): ?>
    <p class="u-mt-2 u-mb-0 u-mx-0 u-color-neutral-gray-600 u-text-compact">
      <?= $e(\ClyoNutri\Domain\Subscription\PlanChangeService::formatBrl((int)($planSummary['catalog_price_cents'] ?? $planSummary['price_cents'] ?? 0))) ?> por mês de referência<?= $end ? ', ' . ($st === 'trialing' ? 'teste até ' : ($st === 'expired' ? 'teste encerrado em ' : ($st === 'canceled' ? 'acesso até ' : 'período até '))) . $e((new \DateTimeImmutable((string)$end))->format('d/m/Y')) : '' ?>.
    </p>
    <?php if ($st === 'expired'): ?><p class="op-message op-message--info u-mt-2 u-mb-0 u-mx-0 u-text-compact u-color-semantic-warning-strong" role="status" data-testid="plan-expired-note">O sistema continua liberado e seus dados seguem salvos. A contratação de um plano pelo sistema ainda não está disponível.</p><?php endif; ?>
    <div class="u-grid u-cols-auto-md u-gap-2 u-mt-3 u-text-compact u-color-neutral-gray-700">
      <div class="u-bg-neutral-gray-50 u-radius-input u-py-2 u-px-3">Profissionais <strong><?= $e($usageOf($usage['active_professionals'] ?? 0, $planSummary['max_professionals'] ?? 0)) ?></strong></div>
      <div class="u-bg-neutral-gray-50 u-radius-input u-py-2 u-px-3">Pacientes ativos <strong><?= $e($usageOf($usage['active_patients'] ?? 0, $planSummary['max_patients'] ?? 0)) ?></strong></div>
      <div class="u-bg-neutral-gray-50 u-radius-input u-py-2 u-px-3">Documentos <strong><?= $e(\ClyoNutri\Domain\Subscription\PlanChangeService::formatMb((float)($usage['storage_mb'] ?? 0))) ?> de <?= $e(\ClyoNutri\Domain\Subscription\PlanChangeService::formatMb((float)($planSummary['storage_mb'] ?? 0))) ?></strong></div>
    </div>
    <?php if (!empty($planOpenDeletion['is_open'])): ?>
      <p class="op-message op-message--info u-mt-3 u-mb-0 u-mx-0 u-text-compact" role="status">
        Há um pedido de exclusão da conta em aberto (protocolo <code><?= $e($planOpenDeletion['protocol']) ?></code>). <a href="/configuracoes/assinatura#conta" class="u-color-botanical-copper-600 u-font-semibold">Ver ou retirar o pedido</a>
      </p>
    <?php endif; ?>
    <div class="u-flex u-wrap u-gap-2 u-mt-4">
      <a class="btn btn-primary" href="/configuracoes/assinatura#plano">Trocar de plano</a>
      <?php if ($st !== 'canceled'): ?><a class="btn btn-secondary" href="/configuracoes/assinatura#cancelar">Cancelar o plano</a><?php endif; ?>
      <a class="btn btn-outline" href="/configuracoes/assinatura#conta">Excluir conta e dados</a>
    </div>
  <?php else: ?>
    <p class="u-mt-2 u-mb-0 u-mx-0 u-color-neutral-gray-600 u-text-compact">A gestão do plano, a troca e o cancelamento ficam com o titular ou o administrador do consultório.</p>
  <?php endif; ?>
</section>
