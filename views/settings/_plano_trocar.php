<?php
/**
 * Card "Trocar de plano" (v37). Espera: $planOverview (PlanChangeService::overview), $flashBlockers.
 * Só é incluído para titular/administrador (a rota inteira já exige canManageSubscription).
 */
$e = static fn($v): string => htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
$brl = static fn(int $c): string => \ClyoNutri\Domain\Subscription\PlanChangeService::formatBrl($c);
$mb = static fn($v): string => \ClyoNutri\Domain\Subscription\PlanChangeService::formatMb((float)$v);
$pcSub = $planOverview['subscription'];
$pcUsage = (array)($pcSub['usage'] ?? []);
$pcCanChange = !empty($planOverview['can_change']);
$typeLabel = ['upgrade' => 'Upgrade', 'downgrade' => 'Downgrade'];
// v54.50: com Asaas habilitado, subir de plano = um botão por plano que abre o checkout Asaas (sem troca manual em paralelo).
$pcAz = (array)($asaasOverview ?? []);
$pcAsaas = !empty($pcAz['enabled']);
$pcAzLatest = (array)($pcAz['latest'] ?? []);
$pcPendingPlanId = in_array((string)($pcAzLatest['status'] ?? ''), ['pending', 'awaiting_payment'], true) && !empty($pcAzLatest['hosted_url'])
  ? (int)($pcAzLatest['plan_id'] ?? 0) : 0;
?>
<section id="plano" class="card u-mb-6 u-scroll-margin" aria-labelledby="plano-titulo">
  <h2 id="plano-titulo" class="u-text-h3 u-font-bold u-color-botanical-forest-900 u-mt-0 u-mb-1 u-mx-0">Planos</h2>
  <?php if ($pcAsaas): ?>
    <p class="u-color-neutral-gray-600 u-text-compact u-mt-0 u-mb-4 u-mx-0 u-leading-body">
      Para subir de plano, pague na página segura do Asaas (cartão, cobrança mensal). O plano muda quando o pagamento é confirmado. Para descer, a troca vale na hora se o uso couber no plano.
    </p>
  <?php else: ?>
    <p class="u-color-neutral-gray-600 u-text-compact u-mt-0 u-mb-4 u-mx-0 u-leading-body">
      A troca vale na hora (para descer, o uso precisa caber no plano). A diferença de valor é combinada com a nossa equipe; o sistema não cobra nada sozinho.
    </p>
  <?php endif; ?>

  <?php if (!empty($flashBlockers['items'])): ?>
    <div class="op-message op-message--error u-mb-4 u-text-compact" role="alert">
      <div class="u-font-bold u-mb-1"><?= $e($flashBlockers['title'] ?? 'A troca não foi feita.') ?></div>
      <ul class="u-m-0 u-pl-5 u-leading-body">
        <?php foreach ($flashBlockers['items'] as $item): ?><li><?= $e($item) ?></li><?php endforeach; ?>
      </ul>
    </div>
  <?php endif; ?>

  <?php if (!$pcCanChange): ?>
    <div class="op-message op-message--info u-bg-neutral-gray-50 u-border u-radius-input u-py-3 u-px-4 u-mb-4 u-color-neutral-gray-700 u-text-compact u-leading-body" role="status">
      <?= $pcSub['status'] === 'canceled'
        ? 'A assinatura está cancelada, então não é possível trocar de plano por aqui. Para voltar a assinar, escreva para ' . $e(\ClyoNutri\Core\Security\ContactInfo::supportEmail()) . '.'
        : 'A troca de plano não está disponível para a situação atual da assinatura.' ?>
    </div>
  <?php endif; ?>

  <div class="u-grid u-cols-auto-lg u-gap-4">
    <?php foreach ($planOverview['options'] as $opt):
      $plan = $opt['plan'];
      $isCurrent = !empty($opt['current']);
      $dir = (string)$opt['direction'];
      $fits = $opt['blockers'] === [];
      $border = $isCurrent ? 'u-border-2 u-bc-brand-copper-500 u-bg-semantic-warning-light' : 'u-border u-bg-neutral-white';
    ?>
      <article class="<?= $border ?> u-radius-button u-p-5 u-flex u-flex-col u-gap-2" aria-label="Plano <?= $e($plan['name']) ?>">
        <div class="u-flex u-justify-between u-items-center u-gap-2">
          <h3 class="u-m-0 u-text-card-title u-color-botanical-forest-900"><?= $e($plan['name']) ?></h3>
          <?php if ($isCurrent): ?>
            <span class="ds-pill ds-pill--copper">Seu plano</span>
          <?php elseif ($dir === 'upgrade'): ?>
            <span class="badge-success u-text-caption u-font-bold u-py-1 u-px-2 u-radius-pill">Upgrade</span>
          <?php elseif ($dir === 'downgrade'): ?>
            <span class="badge-warning u-text-caption u-font-bold u-py-1 u-px-2 u-radius-pill">Downgrade</span>
          <?php endif; ?>
        </div>
        <div class="u-text-h2 u-font-bold u-color-botanical-forest-900"><?= $e($brl((int)$plan['price_cents'])) ?> <span class="u-text-compact u-font-regular u-color-neutral-gray-700">por mês</span></div>
        <ul class="u-m-0 u-pl-4 u-color-neutral-gray-700 u-text-compact u-leading-body">
          <li><?= (int)$plan['max_professionals'] > 0 ? (int)$plan['max_professionals'] . ((int)$plan['max_professionals'] === 1 ? ' profissional' : ' profissionais') : 'Profissionais sem limite' ?></li>
          <li><?= (int)$plan['max_patients'] > 0 ? 'Até ' . (int)$plan['max_patients'] . ' pacientes ativos' : 'Pacientes sem limite' ?></li>
          <li><?= $e($mb((float)$plan['storage_mb'])) ?> de documentos</li>
        </ul>

        <?php if ($isCurrent && $pcAsaas && (string)$pcSub['status'] !== 'active' && (string)$pcSub['status'] !== 'canceled'): ?>
          <?php if ($pcPendingPlanId === (int)$plan['id']): ?>
            <a href="<?= $e($pcAzLatest['hosted_url']) ?>" class="btn btn-primary u-w-full u-mt-auto" rel="noopener">Continuar pagamento</a>
          <?php else: ?>
            <form method="POST" action="/configuracoes/assinatura/asaas" class="u-mt-auto">
              <input type="hidden" name="_csrf_token" value="<?= \ClyoNutri\Core\Security\Csrf::token() ?>">
              <input type="hidden" name="plan_id" value="<?= (int)$plan['id'] ?>">
              <input type="hidden" name="idempotency_key" value="<?= $e($pcAz['idempotency_key'] ?? '') ?>">
              <button type="submit" class="btn btn-primary u-w-full">Assinar <?= $e($plan['name']) ?></button>
            </form>
          <?php endif; ?>
        <?php endif; ?>
        <?php if (!$isCurrent && $dir !== 'lateral'): ?>
          <?php if (!$fits): ?>
            <div role="note" class="op-message op-message--error u-text-compact">
              <strong>Não cabe hoje.</strong>
              <ul class="u-mt-1 u-mb-0 u-mx-0 u-pl-4">
                <?php foreach ($opt['blockers'] as $b): ?><li><?= $e($b['message']) ?></li><?php endforeach; ?>
              </ul>
            </div>
          <?php elseif ($dir === 'downgrade'): ?>
            <p class="u-m-0 u-text-compact u-color-neutral-gray-600">Seu uso atual cabe neste plano. A diferença de valor é de <?= $e($brl(abs((int)$opt['price_diff_cents']))) ?> a menos por mês, acertada com a equipe.</p>
          <?php elseif (!$pcAsaas): ?>
            <p class="u-m-0 u-text-compact u-color-neutral-gray-600">Acréscimo de referência de <?= $e($brl(abs((int)$opt['price_diff_cents']))) ?> por mês, combinado com a equipe.</p>
          <?php endif; ?>

          <?php if ($pcAsaas && $dir === 'upgrade'): ?>
            <?php if ($pcPendingPlanId === (int)$plan['id']): ?>
              <a href="<?= $e($pcAzLatest['hosted_url']) ?>" class="btn btn-primary u-w-full u-mt-auto" rel="noopener">Continuar pagamento</a>
            <?php else: ?>
              <form method="POST" action="/configuracoes/assinatura/asaas" class="u-mt-auto">
                <input type="hidden" name="_csrf_token" value="<?= \ClyoNutri\Core\Security\Csrf::token() ?>">
                <input type="hidden" name="plan_id" value="<?= (int)$plan['id'] ?>">
                <input type="hidden" name="idempotency_key" value="<?= $e($pcAz['idempotency_key'] ?? '') ?>">
                <button type="submit" class="btn btn-primary u-w-full" <?= ($fits && $pcCanChange) ? '' : 'disabled aria-disabled="true"' ?>>Assinar <?= $e($plan['name']) ?></button>
              </form>
            <?php endif; ?>
          <?php else: ?>
          <form method="POST" action="/configuracoes/assinatura/plano" class="u-mt-auto"
                data-confirm="<?= $e(($dir === 'upgrade' ? 'Fazer upgrade' : 'Fazer downgrade') . ' para o plano ' . $plan['name'] . '? A troca vale na hora e a cobrança será combinada com a nossa equipe.') ?>">
            <input type="hidden" name="_csrf_token" value="<?= \ClyoNutri\Core\Security\Csrf::token() ?>">
            <input type="hidden" name="plan_id" value="<?= (int)$plan['id'] ?>">
            <input type="hidden" name="idempotency_key" value="<?= $e($planOverview['idempotency_key']) ?>">
            <button type="submit" class="<?= $dir === 'upgrade' ? 'btn btn-primary' : 'btn btn-secondary' ?> u-w-full" <?= ($fits && $pcCanChange) ? '' : 'disabled aria-disabled="true"' ?>>
              <?= $dir === 'upgrade' ? 'Fazer upgrade' : 'Fazer downgrade' ?>
            </button>
          </form>
          <?php endif; ?>
        <?php endif; ?>
      </article>
    <?php endforeach; ?>
  </div>

  <div id="trocas-historico" class="u-mt-6">
    <h3 class="u-text-body u-font-bold u-color-botanical-forest-900 u-mt-0 u-mb-2 u-mx-0">Histórico de trocas</h3>
    <?php if (empty($planOverview['history'])): ?>
      <p class="u-color-neutral-gray-500 u-text-compact u-m-0">Nenhuma troca de plano registrada até agora.</p>
    <?php else: ?>
      <div class="u-overflow-x-auto">
        <table class="u-w-full u-collapse u-text-compact">
          <thead>
            <tr class="u-border-b-2 u-text-left">
              <th class="u-p-2">Data</th><th class="u-p-2">Tipo</th><th class="u-p-2">Troca</th><th class="u-p-2">Protocolo</th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($planOverview['history'] as $h): ?>
              <tr class="u-border-b">
                <td class="u-p-2"><?= $e((new \DateTimeImmutable($h['effective_at']))->format('d/m/Y H:i')) ?></td>
                <td class="u-p-2"><?= $e($typeLabel[$h['change_type']] ?? $h['change_type']) ?></td>
                <td class="u-p-2"><?= $e($h['from_name']) ?> → <?= $e($h['to_name']) ?></td>
                <td class="u-p-2"><code><?= $e($h['protocol']) ?></code></td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    <?php endif; ?>
  </div>
</section>
