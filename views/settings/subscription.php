<div class="u-maxw-page u-my-0 u-mx-auto">
  <div class="u-flex u-items-center u-justify-between u-wrap u-gap-4 u-mb-4">
    <div>
      <h1 class="u-text-h1 u-font-bold u-color-botanical-forest-900 u-mt-0 u-mb-1 u-mx-0">Central de assinatura e contrato</h1>
      <p class="u-color-neutral-gray-500 u-m-0 u-text-body">Seu plano, uso, faturas e cancelamento em um só lugar.</p>
    </div>
    <?php if (!empty($asaasOverview['enabled'])): ?>
      <span class="ds-pill u-text-caption" title="Ambiente de testes do Asaas: nenhuma cobrança é real." data-testid="asaas-sandbox-badge">Sandbox</span>
    <?php endif; ?>
  </div>

  <!-- Navegação por abas em Configurações -->
  <div class="u-flex u-border-b-2 u-mb-6 u-gap-6">
    <a href="/configuracoes" class="ds-tabs__item">
      Perfil e Atendimento
    </a>
    <a href="/configuracoes/assinatura" class="ds-tabs__item" aria-current="page">
      Assinatura &amp; Faturamento
    </a>
  </div>

  <?php if (!empty($flashSuccess)): ?>
    <div class="op-message op-message--info u-mb-6" role="status">
      <?= htmlspecialchars($flashSuccess) ?>
    </div>
  <?php endif; ?>

  <?php if (!empty($flashError)): ?>
    <div class="op-message op-message--info u-mb-6" role="status">
      <?= htmlspecialchars($flashError) ?>
    </div>
  <?php endif; ?>

  <?php if ($subscription['status'] === 'trialing'): ?>
    <?php /* Banner do Período de Avaliação (NI2-450) */ ?>
    <div class="op-message op-message--info u-mb-6" role="status">
      <div class="u-font-bold u-text-body u-mb-1 u-flex u-items-center u-justify-between u-wrap u-gap-2">
        <span class="u-inline-flex u-items-center u-gap-1">
          <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></svg>
          Período de Avaliação Gratuita (14 dias)
        </span>
        <span class="u-bg-semantic-warning-border u-color-semantic-warning-strong u-text-compact u-font-bold u-py-1 u-px-2 u-radius-pill">
          <?= (int)$subscription['days_left_in_trial'] ?> dia(s) restante(s)
        </span>
      </div>
      <p class="u-m-0 u-text-compact u-leading-body u-color-semantic-warning-strong">
        Você está usufruindo do teste gratuito com todos os recursos do plano <strong><?= htmlspecialchars($subscription['plan_name']) ?></strong> liberados até <strong><?= !empty($subscription['trial_ends_at']) ? (new \DateTimeImmutable($subscription['trial_ends_at']))->format('d/m/Y') : '' ?></strong>, sem necessidade de cartão de crédito. Nenhuma cobrança é feita. <?= !empty($asaasOverview['enabled']) ? 'Para continuar depois do teste, escolha um plano abaixo.' : 'A contratação de um plano pelo sistema ainda não está disponível, e o sistema não bloqueia nada quando o teste termina.' ?> Seus dados continuam disponíveis para exportação.
      </p>
    </div>
  <?php elseif ($subscription['status'] === 'expired'): ?>
    <!-- Banner de Período de Teste Expirado -->
    <div class="op-message op-message--error u-mb-6" role="alert">
      <div class="u-font-bold u-text-body u-mb-1">
        Seu período de teste de 14 dias terminou
      </div>
      <p class="u-m-0 u-text-compact u-leading-body u-color-semantic-error-strong">
        Você continua usando o sistema normalmente, e seus pacientes e prontuários seguem salvos. <?= !empty($asaasOverview['enabled']) ? 'Escolha um plano abaixo para assinar.' : 'A contratação de um plano pelo sistema ainda não está disponível.' ?> Para falar sobre planos, escreva para <a href="mailto:<?= htmlspecialchars(\ClyoNutri\Core\Security\ContactInfo::supportEmail()) ?>" class="u-color-inherit u-font-bold"><?= htmlspecialchars(\ClyoNutri\Core\Security\ContactInfo::supportEmail()) ?></a>.
      </p>
    </div>
  <?php endif; ?>

  <!-- Visão Geral do Contrato Vigente -->
  <div class="card u-mb-6 u-accent-top <?= $subscription['status'] === 'canceled' ? 'u-accent-semantic-error' : ($subscription['status'] === 'trialing' ? 'u-accent-brand-copper-500' : 'u-accent-botanical-green-600') ?>">
    <div class="u-flex u-justify-between u-items-start u-wrap u-gap-4 u-mb-4">
      <div>
        <span class="u-text-compact u-uppercase u-tracking-wide u-font-bold u-color-neutral-gray-500"><?= $subscription['status'] === 'trialing' ? 'Plano em avaliação' : 'Plano contratado' ?></span>
        <h2 class="u-text-h3 u-font-bold u-color-botanical-forest-900 u-my-1 u-mx-0">
          <?= htmlspecialchars($subscription['plan_name']) ?> (<code><?= htmlspecialchars($subscription['plan_code']) ?></code>)
        </h2>
        <p class="u-m-0 u-color-neutral-gray-600 u-text-compact">
          <?= $subscription['status'] === 'trialing' ? 'Avaliação gratuita de 14 dias sem cartão de crédito cadastrado.' : 'Cobrança ' . ($subscription['billing_cycle'] === 'monthly' ? 'mensal recorrente' : 'anual') . ' sem fidelidade.' ?>
        </p>
      </div>
      <div>
        <?php if ($subscription['status'] === 'canceled'): ?>
          <span class="u-inline-flex u-items-center u-gap-1 u-bg-semantic-error-light u-color-semantic-error-strong u-py-1 u-px-3 u-radius-pill u-font-semibold u-text-compact">
            ● Assinatura Cancelada
          </span>
        <?php elseif ($subscription['status'] === 'trialing'): ?>
          <span class="u-inline-flex u-items-center u-gap-1 u-bg-semantic-warning-light u-color-semantic-warning-strong u-py-1 u-px-3 u-radius-pill u-font-semibold u-text-compact">
            ● Período de Avaliação (Trial)
          </span>
        <?php elseif ($subscription['status'] === 'expired'): ?>
          <span class="u-inline-flex u-items-center u-gap-1 u-bg-semantic-error-light u-color-semantic-error-strong u-py-1 u-px-3 u-radius-pill u-font-semibold u-text-compact">
            ● Teste Expirado
          </span>
        <?php else: ?>
          <span class="u-inline-flex u-items-center u-gap-1 u-bg-semantic-success-light u-color-semantic-success-text u-py-1 u-px-3 u-radius-pill u-font-semibold u-text-compact">
            ● Assinatura Ativa
          </span>
        <?php endif; ?>
      </div>
    </div>

    <div class="u-grid u-cols-auto-md u-gap-6 u-bg-neutral-gray-50 u-p-5 u-radius-input u-border">
      <div>
        <div class="u-text-compact u-color-neutral-gray-500 u-font-semibold">VALOR DA RECORRÊNCIA</div>
        <?php if ($subscription['status'] === 'trialing'): ?>
          <div class="u-text-h3 u-font-bold u-color-botanical-forest-900 u-mt-0">
            R$ 0,00 <span class="u-text-compact u-font-regular u-color-neutral-gray-500">(Período de Teste)</span>
          </div>
          <div class="u-text-caption u-color-neutral-gray-500 u-mt-0">
            R$ <?= number_format($subscription['price_cents'] / 100, 2, ',', '.') ?>/mês caso decida assinar
          </div>
        <?php else: ?>
          <div class="u-text-h3 u-font-bold u-color-botanical-forest-900 u-mt-0">
            R$ <?= number_format($subscription['price_cents'] / 100, 2, ',', '.') ?> <span class="u-text-compact u-font-regular u-color-neutral-gray-500">/ mês</span>
          </div>
        <?php endif; ?>
      </div>
      <div>
        <div class="u-text-compact u-color-neutral-gray-500 u-font-semibold"><?= in_array($subscription['status'], ['canceled', 'trialing', 'expired'], true) ? 'VIGÊNCIA' : 'PRÓXIMA COBRANÇA' ?></div>
        <div class="u-text-card-title u-font-semibold u-color-botanical-forest-900 u-mt-1">
          <?php
            // v54.50: a data exibida segue o campo que controla o acesso. Ativa = fim do período (próxima cobrança);
            // teste/expirado = fim do teste; cancelada = acesso até o fim do período pago.
            $stNow = (string)$subscription['status'];
            $endField = in_array($stNow, ['trialing', 'expired'], true)
              ? ($subscription['trial_ends_at'] ?? $subscription['current_period_end'] ?? null)
              : ($subscription['current_period_end'] ?? null);
            $endTxt = $endField ? (new \DateTimeImmutable((string)$endField))->format('d/m/Y') : '—';
          ?>
          <?= $stNow === 'trialing' ? 'Teste até ' : ($stNow === 'expired' ? 'Teste encerrado em ' : ($stNow === 'canceled' ? 'Acesso até ' : 'Próxima cobrança em ')) ?><?= $endTxt ?>
        </div>
      </div>
      <div>
        <div class="u-text-compact u-color-neutral-gray-500 u-font-semibold">DATA DE INÍCIO</div>
        <div class="u-text-card-title u-font-semibold u-color-botanical-forest-900 u-mt-1">
          <?= (new \DateTimeImmutable($subscription['started_at']))->format('d/m/Y') ?>
        </div>
      </div>
      <div>
        <div class="u-text-compact u-color-neutral-gray-500 u-font-semibold">TERMOS &amp; CONTRATO</div>
        <div class="u-mt-1">
          <a href="/termos" target="_blank" rel="noopener noreferrer" class="u-color-botanical-copper-600 u-underline u-text-compact u-font-medium">
            Ver termos vigentes ↗
          </a>
        </div>
      </div>
    </div>
  </div>

  <!-- Consumo de Recursos vs Limites Contratados -->
  <div class="card u-mb-6">
    <h2 class="u-text-h3 u-font-bold u-color-botanical-forest-900 u-mb-4">
      Consumo operacional e limites do plano
    </h2>

    <div class="u-grid u-cols-auto-lg u-gap-6">
      <!-- Profissionais -->
      <div class="u-border u-radius-input u-p-5">
        <div class="u-flex u-justify-between u-mb-2">
          <strong class="u-color-botanical-forest-900">Profissionais no Consultório</strong>
          <span class="u-text-compact u-color-neutral-gray-600">
            <?= (int)$subscription['usage']['active_professionals'] ?> / <?= $subscription['max_professionals'] > 0 ? (int)$subscription['max_professionals'] : 'Ilimitado' ?>
          </span>
        </div>
        <?php 
            $profPct = $subscription['max_professionals'] > 0 ? min(100, round(($subscription['usage']['active_professionals'] / $subscription['max_professionals']) * 100)) : 20;
          ?>
        <progress class="ds-meter" max="100" value="<?= (int)($profPct) ?>" aria-label="Uso de profissionais no plano"><?= (int)($profPct) ?>%</progress>
        <p class="u-text-compact u-color-neutral-gray-500 u-m-0">Equipe clínica com acesso ativo.</p>
      </div>

      <!-- Pacientes -->
      <div class="u-border u-radius-input u-p-5">
        <div class="u-flex u-justify-between u-mb-2">
          <strong class="u-color-botanical-forest-900">Pacientes ativos</strong>
          <span class="u-text-compact u-color-neutral-gray-600">
            <?= (int)$subscription['usage']['active_patients'] ?> / <?= $subscription['max_patients'] > 0 ? (int)$subscription['max_patients'] : 'Ilimitado' ?>
          </span>
        </div>
        <?php 
            $patPct = $subscription['max_patients'] > 0 ? min(100, round(($subscription['usage']['active_patients'] / $subscription['max_patients']) * 100)) : 15;
          ?>
        <progress class="ds-meter ds-meter--copper" max="100" value="<?= (int)($patPct) ?>" aria-label="Uso de pacientes no plano"><?= (int)($patPct) ?>%</progress>
        <p class="u-text-compact u-color-neutral-gray-500 u-m-0">Pacientes cadastrados com prontuário.</p>
      </div>

      <!-- Armazenamento -->
      <div class="u-border u-radius-input u-p-5">
        <div class="u-flex u-justify-between u-mb-2">
          <strong class="u-color-botanical-forest-900">Armazenamento de Arquivos</strong>
          <span class="u-text-compact u-color-neutral-gray-600">
            <?= $subscription['usage']['storage_mb'] ?> MB / <?= (int)$subscription['storage_mb'] ?> MB
          </span>
        </div>
        <?php 
            $storPct = min(100, round(($subscription['usage']['storage_mb'] / max(1, $subscription['storage_mb'])) * 100));
          ?>
        <progress class="ds-meter ds-meter--forest" max="100" value="<?= (int)(max(5, $storPct)) ?>" aria-label="Uso de armazenamento no plano"><?= (int)(max(5, $storPct)) ?>%</progress>
        <p class="u-text-compact u-color-neutral-gray-500 u-m-0">Exames, laudos e documentos clínicos.</p>
      </div>
    </div>
  </div>

  <?php require __DIR__ . '/_plano_asaas.php';
require __DIR__ . '/_plano_trocar.php'; ?>

  <!-- Histórico de Faturas & Conciliação Financeira -->
  <div class="card u-mb-6">
    <div class="u-flex u-justify-between u-items-center u-mb-4 u-wrap u-gap-2">
      <h2 class="u-text-h3 u-font-bold u-color-botanical-forest-900 u-m-0">
        Faturas
      </h2>
      <a href="/ajuda" class="u-text-compact u-color-botanical-copper-600 u-underline">
        Dúvida sobre uma cobrança? Fale com o suporte
      </a>
    </div>

    <?php if (empty($subscription['invoices'])): ?>
      <p class="u-color-neutral-gray-500 u-text-compact u-m-0">
        <?= $subscription['status'] === 'trialing' ? 'Nenhuma fatura emitida. O período de avaliação de 14 dias não gera cobranças nem faturas fictícias.' : 'Nenhuma fatura emitida até o momento.' ?>
      </p>
    <?php else: ?>
      <div class="u-overflow-x-auto">
        <table class="table u-w-full u-collapse u-text-compact">
          <thead>
            <tr class="u-border-b-2 u-text-left">
              <th class="u-p-2">Fatura</th>
              <th class="u-p-2">Vencimento</th>
              <th class="u-p-2">Data do Pagamento</th>
              <th class="u-p-2">Valor</th>
              <th class="u-p-2">Forma</th>
              <th class="u-p-2">Status</th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($subscription['invoices'] as $inv): ?>
              <tr class="u-border-b">
                <td class="u-py-3 u-px-2">
                  <?php $invUrl = (string)($inv['receipt_url'] ?? ''); ?>
                  <?php if (\ClyoNutri\Domain\Billing\AsaasReceiptUrl::isSafe($invUrl)): ?>
                    <a href="<?= htmlspecialchars($invUrl, ENT_QUOTES) ?>" target="_blank" rel="noopener noreferrer" class="u-color-botanical-copper-600 u-underline" data-invoice-receipt aria-label="Ver recibo da fatura <?= htmlspecialchars($inv['invoice_number'], ENT_QUOTES) ?> no Asaas (abre em nova aba)"><code><?= htmlspecialchars($inv['invoice_number']) ?></code> · Ver recibo</a>
                  <?php else: ?>
                    <code><?= htmlspecialchars($inv['invoice_number']) ?></code>
                  <?php endif; ?>
                </td>
                <td class="u-py-3 u-px-2"><?= (new \DateTimeImmutable($inv['due_date']))->format('d/m/Y') ?></td>
                <td class="u-py-3 u-px-2"><?= !empty($inv['paid_at']) ? (new \DateTimeImmutable($inv['paid_at']))->format('d/m/Y') : '—' ?></td>
                <td class="u-py-3 u-px-2 u-font-semibold">R$ <?= number_format($inv['amount_cents'] / 100, 2, ',', '.') ?></td>
                <td class="u-py-3 u-px-2"><?= htmlspecialchars(['credit_card' => 'Cartão de crédito', 'boleto' => 'Boleto', 'pix' => 'Pix', 'undefined' => 'A definir'][strtolower((string)$inv['payment_method'])] ?? (string)$inv['payment_method']) ?></td>
                <td class="u-py-3 u-px-2">
                  <?php if ($inv['status'] === 'paid'): ?>
                    <span class="u-bg-semantic-success-light u-color-semantic-success-text u-py-1 u-px-2 u-radius-input u-text-compact u-font-semibold">Paga</span>
                  <?php elseif ($inv['status'] === 'void'): ?>
                    <span class="u-bg-neutral-gray-100 u-color-neutral-gray-500 u-py-1 u-px-2 u-radius-input u-text-compact u-font-semibold">Anulada</span>
                  <?php else: ?>
                    <span class="u-bg-semantic-warning-light u-color-semantic-warning-strong u-py-1 u-px-2 u-radius-input u-text-compact u-font-semibold"><?= htmlspecialchars(['open' => 'Em aberto', 'pending' => 'Pendente', 'overdue' => 'Vencida', 'failed' => 'Falhou'][$inv['status']] ?? ucfirst((string)$inv['status'])) ?></span>
                  <?php endif; ?>
                </td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    <?php endif; ?>
  </div>

  <!-- Seção de Cancelamento Autosserviço Sem Barreiras (TRUST-02 & TRUST-11) -->
  <div id="cancelar" class="card u-scroll-margin u-border <?= $subscription['status'] === 'canceled' ? 'u-bc-semantic-error-border u-bg-semantic-error-light' : 'u-bc-neutral-gray-300 u-bg-neutral-white' ?>">
    <?php if ($subscription['status'] === 'canceled'): ?>
      <div class="u-py-2 u-px-0">
        <div class="u-flex u-items-center u-gap-2 u-mb-2">
          <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" class="u-color-semantic-error" stroke-width="2"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg>
          <h2 class="u-text-h3 u-font-bold u-color-semantic-error-strong u-m-0">Assinatura cancelada</h2>
        </div>
        <p class="u-color-semantic-error-strong u-text-body u-mb-3">
          Cancelamento registrado. Não haverá novas renovações ou cobranças no seu cartão.
        </p>
        <div class="u-bg-neutral-white u-border u-bc-semantic-error-border u-radius-input u-p-4 u-maxw-form">
          <div class="u-mb-1">
            <strong class="u-color-botanical-forest-900">Protocolo de Cancelamento:</strong>
            <code class="u-text-body u-font-bold u-color-semantic-error"><?= htmlspecialchars($subscription['cancellation_protocol'] ?? '—') ?></code>
          </div>
          <div class="u-mb-1">
            <strong class="u-color-botanical-forest-900">Data da Efetivação:</strong>
            <span><?= !empty($subscription['canceled_at']) ? (new \DateTimeImmutable($subscription['canceled_at']))->format('d/m/Y H:i') : '—' ?></span>
          </div>
          <div>
            <strong class="u-color-botanical-forest-900">Acesso liberado até:</strong>
            <span><?= (new \DateTimeImmutable($subscription['current_period_end']))->format('d/m/Y') ?> (fim do ciclo faturado)</span>
          </div>
        </div>
      </div>
    <?php else: ?>
      <details>
      <summary class="u-text-h3 u-font-bold u-color-botanical-forest-900">Cancelar a assinatura</summary>
      <p class="u-color-neutral-gray-600 u-text-compact u-mt-2 u-mb-4 u-leading-body">
        A renovação para na hora e o acesso continua até <strong><?= (new \DateTimeImmutable($subscription['current_period_end']))->format('d/m/Y') ?></strong>. Seus dados ficam guardados e você recebe um protocolo.
        Na primeira contratação, em até 7 dias, o reembolso é integral (CDC art. 49).
        <a href="/termos#reembolso" target="_blank" rel="noopener noreferrer" class="u-color-botanical-copper-600 u-underline">Ver regras nos Termos</a>
      </p>

      <form method="POST" action="/configuracoes/assinatura/cancelar" data-confirm="Tem certeza de que deseja cancelar a assinatura do consultório? O acesso permanecerá até o fim do ciclo pago.">
        <input type="hidden" name="_csrf_token" value="<?= \ClyoNutri\Core\Security\Csrf::token() ?>">

        <fieldset class="u-border u-radius-input u-p-5 u-mb-4">
          <legend class="u-py-0 u-px-2 u-font-semibold u-text-compact u-color-botanical-forest-900">
            Motivo <span class="u-font-regular u-text-compact u-color-neutral-gray-500">(opcional)</span>
          </legend>

          <div class="form-group u-mb-3">
            <label class="form-label u-text-compact" for="cancel-reason">Qual o principal motivo da sua saída? (Opcional)</label>
            <select class="form-control" id="cancel-reason" name="reason_category">
              <option value="">— Não desejo informar —</option>
              <?php foreach ($reasons as $code => $label): ?>
                <option value="<?= htmlspecialchars($code) ?>"><?= htmlspecialchars($label) ?></option>
              <?php endforeach; ?>
            </select>
          </div>

          <div class="form-group u-mb-0">
            <label class="form-label u-text-compact" for="cancel-feedback">Comentários ou sugestões para melhorarmos (Opcional):</label>
            <textarea class="form-control" id="cancel-feedback" name="feedback" rows="3" maxlength="1000" placeholder="Compartilhe o que faltou para o Clyo Nutri atender plenamente seu dia a dia..."></textarea>
          </div>
        </fieldset>

        <div class="u-flex u-items-center u-justify-between u-wrap u-gap-4">
          <span class="u-text-compact u-color-neutral-gray-500">
            Apenas titulares e administradores do consultório podem executar esta ação.
          </span>
          <button type="submit" class="btn btn-danger-solid">
            Confirmar cancelamento da assinatura
          </button>
        </div>
      </form>
      </details>
    <?php endif; ?>
  </div>

  <?php require __DIR__ . '/_conta_exclusao.php'; ?>
</div>
