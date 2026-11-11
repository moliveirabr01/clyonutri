<?php
/**
 * NI2-459: a view só usa o que o controller entrega. Erro e valores digitados vêm da
 * sessão (HelpdeskController::storeTicket), nunca de $_GET.
 *
 * @var string      $selectedCat
 * @var string      $csrfToken
 * @var string|null $errorMessage
 * @var string|null $errorField   subject|description|category|type
 * @var array       $old
 */
$csrfToken = $csrfToken ?? '';
$formError = $errorMessage ?? null;
$errorField = $errorField ?? null;
$old = $old ?? [];
$selectedCat = (string)($old['category'] ?? ($selectedCat ?? 'question'));
$oldValue = static fn (string $k): string => htmlspecialchars((string)($old[$k] ?? ''), ENT_QUOTES, 'UTF-8');
// Campo com erro: aria-invalid + descrição ligada à mensagem de erro (WCAG 3.3.1 / 4.1.2).
$errorAttrs = static function (string $field, string $hintId = '') use ($formError, $errorField): string {
    $ids = trim($hintId . ($formError && $errorField === $field ? ' ticket-error-msg' : ''));
    $attrs = $ids !== '' ? ' aria-describedby="' . $ids . '"' : '';
    return $attrs . ($formError && $errorField === $field ? ' aria-invalid="true"' : '');
};
$fieldBorder = 'border: 1px solid var(--border-input, #828B9B);'; // WCAG 1.4.11: >= 3:1 em branco e cinza claro
?>
<div style="max-width: 780px; margin: 0 auto; padding-bottom: var(--space-8);">
  <div style="margin-bottom: 20px;">
    <a href="/ajuda" style="font-size: 0.85rem; color: var(--neutral-gray-500, #667085); text-decoration: none;">← Voltar para central de ajuda</a>
  </div>

  <div class="card">
    <div class="card__header" style="border-bottom: 1px solid var(--neutral-gray-200, #E5E7EB); padding-bottom: 16px; margin-bottom: 20px;">
      <span style="font-size: 0.8rem; font-weight: 700; color: var(--botanical-green-600, #39734A); text-transform: uppercase;">SERVICE DESK CLYONUTRI II</span>
      <h1 style="margin: 4px 0 0 0; font-size: 1.5rem; color: var(--botanical-forest-900, #0F3E17);">Abrir novo chamado de suporte</h1>
      <p style="margin: 4px 0 0 0; color: var(--neutral-gray-500, #667085); font-size: 0.85rem;">
        Descreva sua dúvida, sugestão ou incidente técnico. Um protocolo exclusivo será gerado imediatamente.
      </p>
    </div>

    <?php if (!empty($formError)): ?>
      <div id="ticket-error-msg" class="op-message op-message--error" role="alert" tabindex="-1" style="background: var(--semantic-error-light, #FEE2E2); border-left: 4px solid var(--semantic-error, #B42318); color: var(--semantic-error, #B42318); padding: 12px 16px; border-radius: 6px; margin-bottom: 20px; font-size: 0.9rem;">
        <strong>Erro no formulário:</strong> <?= htmlspecialchars($formError) ?>
      </div>
    <?php endif; ?>

    <form action="/ajuda/abrir-chamado" method="POST" enctype="multipart/form-data" id="form-ticket">
      <input type="hidden" name="_csrf_token" value="<?= $csrfToken ?>">

      <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 16px; margin-bottom: 16px;">
        <div>
          <label for="ticket-category" style="display: block; font-weight: 600; font-size: 0.85rem; color: var(--neutral-gray-700, #344054); margin-bottom: 6px;">
            Categoria do Clyo Nutri *
          </label>
          <select id="ticket-category" name="category" required<?= $errorAttrs('category') ?> style="width: 100%; padding: 10px; <?= $fieldBorder ?> border-radius: 6px; font-size: 0.9rem;">
            <?php foreach (\ClyoNutri\Domain\Helpdesk\KnowledgeBaseService::CATEGORIES as $key => $cat): ?>
              <option value="<?= $key ?>" <?= $selectedCat === $key ? 'selected' : '' ?>>
                <?= htmlspecialchars($cat['name']) ?>
              </option>
            <?php endforeach; ?>
          </select>
        </div>

        <div>
          <label for="ticket-type" style="display: block; font-weight: 600; font-size: 0.85rem; color: var(--neutral-gray-700, #344054); margin-bottom: 6px;">
            Tipo de Chamado *
          </label>
          <select id="ticket-type" name="type" required<?= $errorAttrs('type') ?> style="width: 100%; padding: 10px; <?= $fieldBorder ?> border-radius: 6px; font-size: 0.9rem;">
            <?php foreach (\ClyoNutri\Domain\Helpdesk\HelpdeskTicketService::TYPES as $key => $label): ?>
              <option value="<?= $key ?>" <?= ($old['type'] ?? '') === $key ? 'selected' : '' ?>><?= htmlspecialchars($label) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
      </div>

      <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 16px; margin-bottom: 16px;">
        <div>
          <label for="ticket-priority-perceived" style="display: block; font-weight: 600; font-size: 0.85rem; color: var(--neutral-gray-700, #344054); margin-bottom: 6px;">
            Impacto / prioridade percebida
          </label>
          <select id="ticket-priority-perceived" name="priority_perceived" style="width: 100%; padding: 10px; <?= $fieldBorder ?> border-radius: 6px; font-size: 0.9rem;">
            <option value="low" <?= ($old['priority_perceived'] ?? 'medium') === 'low' ? 'selected' : '' ?>>Baixa (Dúvida pontual ou melhoria futura)</option>
            <option value="medium" <?= ($old['priority_perceived'] ?? 'medium') === 'medium' ? 'selected' : '' ?>>Média (Impacto parcial, consigo contornar)</option>
            <option value="high" <?= ($old['priority_perceived'] ?? 'medium') === 'high' ? 'selected' : '' ?>>Alta (Impacto no fluxo de atendimento clínico)</option>
            <option value="urgent" <?= ($old['priority_perceived'] ?? 'medium') === 'urgent' ? 'selected' : '' ?>>Urgente (bloqueio total / consulta em andamento)</option>
          </select>
        </div>

        <div>
          <label for="ticket-origin-route" style="display: block; font-weight: 600; font-size: 0.85rem; color: var(--neutral-gray-700, #344054); margin-bottom: 6px;">
            Tela ou módulo de origem
          </label>
          <input type="text" id="ticket-origin-route" name="origin_route" value="<?= $oldValue('origin_route') ?>" placeholder="Ex: /planos, /antropometria, /teleconsulta" 
                 style="width: 100%; padding: 10px; <?= $fieldBorder ?> border-radius: 6px; font-size: 0.9rem; box-sizing: border-box;">
        </div>
      </div>

      <div style="margin-bottom: 16px;">
        <label for="ticket-subject" style="display: block; font-weight: 600; font-size: 0.85rem; color: var(--neutral-gray-700, #344054); margin-bottom: 6px;">
          Assunto resumido *
        </label>
        <input type="text" id="ticket-subject" name="subject" required minlength="5" value="<?= $oldValue('subject') ?>"<?= $errorAttrs('subject') ?> placeholder="Ex: Erro ao salvar plano alimentar com alimentos TACO personalizados" 
               style="width: 100%; padding: 10px; <?= $fieldBorder ?> border-radius: 6px; font-size: 0.9rem; box-sizing: border-box;">
      </div>

      <div style="margin-bottom: 16px;">
        <label for="ticket-description" style="display: block; font-weight: 600; font-size: 0.85rem; color: var(--neutral-gray-700, #344054); margin-bottom: 6px;">
          Descrição detalhada do problema *
        </label>
        <textarea id="ticket-description" name="description" required minlength="10" rows="6" 
                 <?= $errorAttrs('description', 'ticket-description-hint') ?>
                  placeholder="Descreva os passos que realizou, mensagens de erro apresentadas ou comportamento esperado..." 
                  style="width: 100%; padding: 10px; <?= $fieldBorder ?> border-radius: 6px; font-size: 0.9rem; box-sizing: border-box;"><?= $oldValue('description') ?></textarea>
        <span id="ticket-description-hint" style="display: block; font-size: 0.75rem; color: var(--neutral-gray-500, #667085); margin-top: 4px;">
          Por conformidade com a LGPD e sigilo profissional CFN, <strong>não insira dados de identificação pessoal de pacientes</strong> (como CPF ou prontuários completos).
        </span>
      </div>

      <?php /* Upload de Anexo Seguro (NI2-200) */ ?>
      <div style="margin-bottom: 24px; padding: 16px; background: var(--neutral-gray-50, #F9FAFB); border: 1px dashed var(--neutral-gray-300, #D1D5DB); border-radius: 8px;">
        <label for="ticket-attachment" style="display: block; font-weight: 600; font-size: 0.85rem; color: var(--neutral-gray-700, #344054); margin-bottom: 6px;">
          Anexo opcional (captura de tela ou documento de evidência)
        </label>
        <input type="file" id="ticket-attachment" name="attachment" accept=".png,.jpg,.jpeg,.pdf" 
               aria-describedby="ticket-attachment-hint"
               style="font-size: 0.85rem; color: var(--neutral-gray-600, #475467);">
        <div id="ticket-attachment-hint" style="font-size: 0.75rem; color: var(--neutral-gray-500, #667085); margin-top: 6px;">
          Formatos aceitos: <strong>PNG, JPG e PDF</strong> (Tamanho máximo: 10 MB). Os arquivos são armazenados fora do diretório público.
        </div>
      </div>

      <div style="display: flex; justify-content: flex-end; gap: 12px;">
        <a href="/ajuda" class="btn btn-secondary">Cancelar</a>
        <button type="submit" class="btn btn-primary" id="btn-submit-ticket" style="padding: 10px 24px; font-weight: 600;">
          Registrar chamado
        </button>
      </div>
    </form>
  </div>
</div>
<script nonce="<?= \ClyoNutri\Core\Http\SecurityHeaders::nonce() ?>">
  // NI2-459: com erro, o foco vai para a mensagem, que o leitor de tela anuncia ao chegar.
  var ticketError = document.getElementById('ticket-error-msg');
  if (ticketError) { ticketError.focus(); }

  // Prevenção de duplo clique
  document.getElementById('form-ticket').addEventListener('submit', function() {
    var btn = document.getElementById('btn-submit-ticket');
    btn.disabled = true;
    btn.innerText = 'Processando e Gerando Protocolo...';
  });
</script>
