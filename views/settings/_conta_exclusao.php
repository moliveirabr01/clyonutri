<?php
/**
 * Card "Excluir conta e dados" (v37). Espera: $deletion (AccountDeletionRequestService::current), $deletionAvailable, $legalParagraphs.
 * O pedido só registra a solicitação. Nada é apagado por aqui.
 */
$e = static fn($v): string => htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
$fmt = static fn($v): string => $v ? (new \DateTimeImmutable((string)$v))->format('d/m/Y \à\s H:i') : '';
$fmtDay = static fn($v): string => $v ? (new \DateTimeImmutable((string)$v))->format('d/m/Y') : '';
$delOpen = !empty($deletion['is_open']);
$phrase = \ClyoNutri\Domain\Privacy\AccountDeletionRequestService::CONFIRM_PHRASE;
?>
<section id="conta" class="card u-mt-6 u-border u-bc-semantic-error-border u-scroll-margin" aria-labelledby="conta-titulo">
  <h2 id="conta-titulo" class="u-text-h3 u-font-bold u-color-semantic-error-strong u-mt-0 u-mb-1 u-mx-0">Excluir a conta e os dados</h2>
  <p class="u-color-neutral-gray-600 u-text-compact u-mt-0 u-mb-4 u-mx-0 u-leading-body">
    Diferente de cancelar o plano: é um pedido (LGPD) que a equipe analisa. Nada é apagado automaticamente.
    <a href="/privacidade" target="_blank" rel="noopener noreferrer" class="u-color-botanical-copper-600 u-underline">Política de privacidade</a>
  </p>

  <details class="u-mb-4" <?= $delOpen ? 'open' : '' ?>>
  <summary class="u-text-compact u-font-semibold u-color-botanical-forest-900">Ver aviso legal e pedir a exclusão</summary>
  <div role="note" class="u-bg-neutral-gray-50 u-border u-border-dashed u-bc-neutral-gray-400 u-radius-input u-py-3 u-px-4 u-my-4 u-color-neutral-gray-700 u-text-compact u-leading-body">
    <?php foreach ($legalParagraphs as $p): ?>
      <p class="u-mt-0 u-mb-2 u-mx-0"><?= $e($p) ?></p>
    <?php endforeach; ?>
  </div>

  <?php if (!$deletionAvailable): ?>
    <p class="op-message op-message--info u-color-neutral-gray-600 u-text-compact u-m-0" role="status">O pedido de exclusão ainda não está disponível neste ambiente. Escreva para <?= $e(\ClyoNutri\Core\Security\ContactInfo::supportEmail()) ?>.</p>
  <?php else: ?>

    <?php if ($deletion): ?>
      <div class="op-message <?= $delOpen ? 'op-message--warning' : 'op-message--info' ?> u-mb-4 u-leading-body" role="status">
        <div class="u-flex u-justify-between u-wrap u-gap-2 u-mb-1">
          <strong><?= $delOpen ? 'Pedido de exclusão em aberto' : 'Último pedido de exclusão' ?></strong>
          <span class="<?= $delOpen ? 'badge-warning' : 'badge-success' ?> u-text-caption u-font-bold u-py-1 u-px-2 u-radius-pill"><?= $e($deletion['status_label']) ?></span>
        </div>
        <div>Protocolo <code><?= $e($deletion['protocol']) ?></code>, pedido em <?= $e($fmt($deletion['requested_at'])) ?> por <?= $e($deletion['requester_name']) ?>.</div>
        <?php if ($delOpen): ?>
          <div>A equipe responde até <strong><?= $e($fmtDay($deletion['deadline_at'])) ?></strong>.<?= !empty($deletion['is_overdue']) ? ' O prazo passou, e já avisamos a equipe.' : '' ?></div>
          <div class="u-color-neutral-gray-600">Seus dados continuam intactos e o acesso segue normal enquanto o pedido está aberto.</div>
        <?php elseif ($deletion['status'] === 'canceled'): ?>
          <div>Retirado em <?= $e($fmt($deletion['canceled_at'])) ?>. A conta e os dados seguiram como estavam.</div>
        <?php elseif (!empty($deletion['resolution_note'])): ?>
          <div>Encerrado em <?= $e($fmt($deletion['resolved_at'])) ?>. Resposta da equipe: <?= $e($deletion['resolution_note']) ?></div>
        <?php endif; ?>
      </div>
    <?php endif; ?>

    <?php if ($delOpen): ?>
      <form method="POST" action="/configuracoes/conta/exclusao/cancelar" data-confirm="Retirar o pedido de exclusão? A conta e os dados seguem como estão.">
        <input type="hidden" name="_csrf_token" value="<?= \ClyoNutri\Core\Security\Csrf::token() ?>">
        <button type="submit" class="btn btn-secondary">Retirar o pedido de exclusão</button>
      </form>
    <?php else: ?>
      <form method="POST" action="/configuracoes/conta/exclusao" autocomplete="off"
            data-confirm="Registrar o pedido de exclusão da conta e dos dados? Nada será apagado agora. A equipe vai analisar e responder.">
        <input type="hidden" name="_csrf_token" value="<?= \ClyoNutri\Core\Security\Csrf::token() ?>">
        <div class="u-grid u-cols-auto-lg u-gap-4 u-mb-4">
          <div class="form-group u-m-0">
            <label class="form-label" for="del-password">Sua senha atual</label>
            <input class="form-control" id="del-password" type="password" name="password" required autocomplete="current-password">
          </div>
          <div class="form-group u-m-0">
            <label class="form-label" for="del-phrase">Digite <strong><?= $e($phrase) ?></strong></label>
            <input class="form-control" id="del-phrase" type="text" name="confirm_phrase" required placeholder="<?= $e($phrase) ?>" autocomplete="off">
          </div>
        </div>
        <div class="form-group u-mb-4">
          <label class="form-label" for="del-reason">Motivo, se quiser contar (opcional)</label>
          <textarea class="form-control" id="del-reason" name="reason" rows="2" maxlength="500"></textarea>
        </div>
        <label class="u-flex u-gap-2 u-items-start u-text-compact u-color-neutral-gray-700 u-mb-4 u-leading-body">
          <input type="checkbox" name="acknowledge" value="1" required class="u-mt-1">
          <span>Li o aviso acima e entendo que prontuários e outros registros podem ser mantidos pelo prazo exigido em lei, e que nada é apagado antes da resposta da equipe.</span>
        </label>
        <div class="u-flex u-items-center u-justify-between u-wrap u-gap-4">
          <span class="u-text-compact u-color-neutral-gray-500">Apenas titulares e administradores podem fazer este pedido.</span>
          <button type="submit" class="btn btn-danger">Pedir exclusão da conta e dos dados</button>
        </div>
      </form>
    <?php endif; ?>
  <?php endif; ?>
  </details>
</section>
