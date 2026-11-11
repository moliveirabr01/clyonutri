<?php /* NI2-635: um protocolo, suas versões e a trilha editorial. Entradas: $protocol, $versions, $events, $flash, $csrfToken, $operatorId. */
$e = static fn($v): string => htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
$old = $_SESSION['protocol_old'] ?? null; unset($_SESSION['protocol_old']);
$statusLabel = ['draft' => 'Rascunho', 'in_review' => 'Em revisão', 'approved' => 'Aprovada', 'retired' => 'Aposentada'];
$typeLabel = ['text' => 'texto curto', 'textarea' => 'texto', 'number' => 'número', 'single' => 'escolha única', 'multi' => 'múltipla escolha', 'yesno' => 'sim/não', 'date' => 'data', 'scale' => 'escala'];
$pid = (int)$protocol['id'];
$hasDraft = (bool)array_filter($versions, static fn($v) => $v['status'] === 'draft');
$example = json_encode(['title' => $protocol['name'], 'sections' => ['geral' => 'Geral'], 'suggestion_terms' => [], 'questions' => [['key' => 'exemplo', 'section' => 'geral', 'type' => 'yesno', 'options' => ['sim' => 'Sim', 'nao' => 'Não'], 'required' => true, 'text' => 'Pergunta revisada pela curadoria']]], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
?>
<div style="margin-bottom: 1rem;">
  <h2 style="font-size: 1.5rem; font-weight: 700; color: var(--admin-navy-900);"><?= $e($protocol['name']) ?> <code style="font-size: .9rem;"><?= $e($protocol['code']) ?></code></h2>
  <p style="font-size: .85rem; color: var(--admin-gray-500);">Fase <?= $e($protocol['catalog_phase']) ?> · <?= $protocol['kind'] === 'profile' ? 'perfil não-diagnóstico' : 'condição' ?><?= (int)$protocol['requires_specialist_review'] ? ' · aprovação exige parecer de especialista' : '' ?></p>
</div>
<?php if ($flash): ?><div role="<?= $flash['type'] === 'error' ? 'alert' : 'status' ?>" data-testid="protocol-flash" style="margin-bottom: 1rem; padding: .75rem 1rem; border-radius: 6px; background: <?= $flash['type'] === 'error' ? '#FEF3F2' : '#ECFDF3' ?>;"><?= $e($flash['text']) ?></div><?php endif; ?>

<?php foreach ($versions as $v): $def = json_decode((string)$v['definition_json'], true) ?: []; $vid = (int)$v['id']; ?>
<section class="admin-card" style="margin-bottom: 1rem; padding: 1rem 1.25rem;" data-testid="protocol-version" data-status="<?= $e($v['status']) ?>" data-version="<?= (int)$v['version'] ?>">
  <h3 style="margin: 0 0 .25rem;">v<?= (int)$v['version'] ?> · <?= $e($statusLabel[$v['status']] ?? $v['status']) ?></h3>
  <p style="font-size: .8rem; color: var(--admin-gray-500); margin: 0 0 .75rem;">
    Criada em <?= $e(date('d/m/Y H:i', strtotime((string)$v['created_at']))) ?> pelo usuário #<?= (int)$v['authored_by_user_id'] ?>
    <?php if ($v['approved_at']): ?> · aprovada em <?= $e(date('d/m/Y', strtotime((string)$v['approved_at']))) ?> por <?= $e($v['approved_by_name']) ?> (<?= $e($v['approved_by_crn']) ?>) · revisar até <?= $e(date('d/m/Y', strtotime((string)$v['review_due_at']))) ?><?php endif; ?>
    · SHA-256 <?= $e(substr((string)$v['content_sha256'], 0, 12)) ?>…
  </p>
  <details<?= $v['status'] === 'in_review' ? ' open' : '' ?>>
    <summary>Conteúdo para revisão (<?= count($def['questions'] ?? []) ?> perguntas)</summary>
    <ol style="font-size: .88rem;" data-testid="protocol-preview">
      <?php foreach ($def['questions'] ?? [] as $q): ?>
        <li style="margin-bottom: .4rem;"><strong><?= $e($q['text']) ?></strong> <span style="color: var(--admin-gray-500);">(<?= $e($typeLabel[$q['type']] ?? $q['type']) ?><?= !empty($q['required']) ? ', obrigatória' : '' ?><?= ($q['audience'] ?? 'both') === 'professional' ? ', só profissional' : '' ?><?= !empty($q['shared_key']) ? ', compartilhada ' . $e($q['shared_key']) : '' ?>)</span>
          <?php if (!empty($q['options'])): ?><br><span style="font-size: .8rem;">Opções: <?= $e(implode(' · ', $q['options'])) ?></span><?php endif; ?>
          <?php if (!empty($q['condition'])): ?><br><span style="font-size: .8rem;">Aparece se: <code><?= $e(json_encode($q['condition'], JSON_UNESCAPED_UNICODE)) ?></code></span><?php endif; ?>
          <?php if (!empty($q['red_flag'])): ?><br><span style="font-size: .8rem; color: #912018;">Alerta (<?= $e(\ClyoNutri\Domain\ClinicalProtocol\ProtocolDefinition::RED_FLAG_ACTIONS[$q['red_flag']['action']] ?? '') ?>): <?= $e($q['red_flag']['message']) ?></span><?php endif; ?>
        </li>
      <?php endforeach; ?>
    </ol>
    <?php if (!empty($def['suggestion_terms'])): ?><p style="font-size: .8rem;">Termos que geram sugestão (nunca diagnóstico): <?= $e(implode(', ', $def['suggestion_terms'])) ?></p><?php endif; ?>
    <p style="font-size: .8rem;"><strong>Referências:</strong> <?= $v['references_text'] ? nl2br($e($v['references_text'])) : '<em>nenhuma</em>' ?></p>
  </details>
  <div style="display: flex; gap: .75rem; flex-wrap: wrap; margin-top: .75rem;">
    <?php if ($v['status'] === 'draft'): ?>
      <form method="POST" action="/admin/protocolos/<?= $pid ?>/enviar"><input type="hidden" name="_csrf_token" value="<?= $e($csrfToken) ?>"><input type="hidden" name="version_id" value="<?= $vid ?>"><button class="btn btn--primary btn--sm" type="submit" data-testid="protocol-submit">Enviar para revisão clínica</button></form>
    <?php elseif ($v['status'] === 'in_review'): ?>
      <?php if ((int)$v['authored_by_user_id'] === (int)$operatorId): ?>
        <p style="font-size: .85rem;" data-testid="protocol-own-version">Você escreveu esta versão: a aprovação precisa ser feita por outra pessoa habilitada.</p>
      <?php else: ?>
      <form method="POST" action="/admin/protocolos/<?= $pid ?>/aprovar" data-testid="protocol-approve" style="display: grid; gap: .5rem; grid-template-columns: repeat(auto-fit, minmax(180px, 1fr)); width: 100%;">
        <input type="hidden" name="_csrf_token" value="<?= $e($csrfToken) ?>"><input type="hidden" name="version_id" value="<?= $vid ?>">
        <label>Nutricionista aprovador(a)<br><input name="approver_name" required style="width: 100%;"></label>
        <label>CRN com região<br><input name="approver_crn" required placeholder="CRN-3 12345" style="width: 100%;"></label>
        <label>Revisar até<br><input type="date" name="review_due_at" required style="width: 100%;"></label>
        <?php if ((int)$protocol['requires_specialist_review']): ?><label style="grid-column: 1 / -1;">Parecer da revisão especializada<br><textarea name="specialist_note" required rows="2" style="width: 100%;"></textarea></label><?php endif; ?>
        <label style="grid-column: 1 / -1; display: flex; gap: .5rem;"><input type="checkbox" name="confirm_review" value="1" required> Revisei o conteúdo clínico, as referências e os alertas desta versão.</label>
        <button class="btn btn--primary btn--sm" type="submit" style="justify-self: start;">Aprovar versão</button>
      </form>
      <?php endif; ?>
    <?php elseif ($v['status'] === 'approved'): ?>
      <form method="POST" action="/admin/protocolos/<?= $pid ?>/aposentar" style="display: flex; gap: .5rem; flex-wrap: wrap;"><input type="hidden" name="_csrf_token" value="<?= $e($csrfToken) ?>"><input type="hidden" name="version_id" value="<?= $vid ?>"><input name="reason" required placeholder="Motivo da retirada" style="min-width: 240px;"><button class="btn btn--danger btn--sm" type="submit">Aposentar</button></form>
    <?php endif; ?>
  </div>
  <?php if ($v['status'] === 'draft'): ?>
  <details style="margin-top: .75rem;"<?= $old && (int)$old['version_id'] === $vid ? ' open' : '' ?>><summary>Editar rascunho</summary>
    <form method="POST" action="/admin/protocolos/<?= $pid ?>/rascunho"><input type="hidden" name="_csrf_token" value="<?= $e($csrfToken) ?>"><input type="hidden" name="version_id" value="<?= $vid ?>">
      <label>Definição (JSON)<br><textarea name="definition_json" rows="14" style="width: 100%; font-family: monospace; font-size: .8rem;"><?= $e($old && (int)$old['version_id'] === $vid ? $old['definition_json'] : json_encode($def, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)) ?></textarea></label>
      <label>Referências clínicas<br><textarea name="references_text" rows="3" style="width: 100%;"><?= $e($old && (int)$old['version_id'] === $vid ? $old['references_text'] : (string)$v['references_text']) ?></textarea></label>
      <button class="btn btn--secondary btn--sm" type="submit">Salvar rascunho</button>
    </form>
  </details>
  <?php endif; ?>
</section>
<?php endforeach; ?>

<?php if (!$hasDraft): ?>
<section class="admin-card" style="padding: 1rem 1.25rem;">
  <h3 style="margin-top: 0;">Nova versão (rascunho)</h3>
  <p style="font-size: .85rem; color: var(--admin-gray-500);">Formato descrito em docs/clinical-protocols. O rascunho não aparece para ninguém.</p>
  <form method="POST" action="/admin/protocolos/<?= $pid ?>/rascunho" data-testid="protocol-new-draft"><input type="hidden" name="_csrf_token" value="<?= $e($csrfToken) ?>">
    <label>Definição (JSON)<br><textarea name="definition_json" rows="14" required style="width: 100%; font-family: monospace; font-size: .8rem;"><?= $e($old && !(int)$old['version_id'] ? $old['definition_json'] : $example) ?></textarea></label>
    <label>Referências clínicas (obrigatórias para aprovar)<br><textarea name="references_text" rows="3" style="width: 100%;"><?= $e($old && !(int)$old['version_id'] ? $old['references_text'] : '') ?></textarea></label>
    <button class="btn btn--primary btn--sm" type="submit">Criar rascunho</button>
  </form>
</section>
<?php endif; ?>

<section class="admin-card" style="margin-top: 1rem; padding: 1rem 1.25rem;">
  <h3 style="margin-top: 0;">Trilha editorial</h3>
  <ul style="font-size: .85rem;" data-testid="protocol-events">
    <?php foreach ($events as $ev): ?><li><?= $e(date('d/m/Y H:i', strtotime((string)$ev['created_at']))) ?> · v<?= (int)$ev['version'] ?> · <?= $e($ev['action']) ?> · usuário #<?= (int)$ev['actor_user_id'] ?><?= $ev['note'] ? ' · ' . $e($ev['note']) : '' ?></li><?php endforeach; ?>
    <?php if (!$events): ?><li>Sem eventos.</li><?php endif; ?>
  </ul>
</section>
