<?php
/**
 * NI2-639/640/642/652: anamnese adaptativa na página da anamnese.
 * Entradas: $q (ProtocolWorkspace::questionnaire), $conds, $available (aprovados e ligados na clínica), $approved, $patientId, $flash, $csrfToken.
 * Regras visíveis: sugestão ≠ diagnóstico; módulo só com condição confirmada; alerta só encaminha; rascunho ≠ concluído.
 * v54.16: dispensar/reativar módulo com motivo (NI2-642), comparação entre conclusões e conflito de gravação (NI2-652).
 */
use ClyoNutri\Domain\ClinicalProtocol\PatientConditionService as PCS;

$ea = static fn($v): string => htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
$composed = $q['composed'];
$answers = $q['answers'];
$names = array_map(static fn($p) => $p['name'], $approved);
$base = '/pacientes/' . (int)$patientId;
$labels = array_column($composed['items'], 'text', 'answer_key');
$dismissed = $dismissed ?? [];
$compare = $compare ?? [];
$conflict = $conflict ?? [];
$suggested = array_values(array_filter($conds, static fn($c) => $c['status'] === 'suggested'));
$active = array_values(array_filter($conds, static fn($c) => in_array($c['status'], PCS::ACTIVE, true)));
$closed = array_values(array_filter($conds, static fn($c) => in_array($c['status'], ['resolved', 'dismissed'], true)));
$statusOpts = ['reported' => 'Relatada', 'suspected' => 'Em investigação', 'documented' => 'Documentada (laudo/avaliação)'];
$provOpts = ['self_reported' => 'Autorrelato na consulta', 'patient' => 'Paciente (pré-consulta)', 'professional' => 'Avaliação do profissional', 'report' => 'Laudo ou exame'];
$sel = static function (string $name, array $opts, string $id, string $cur = '') use ($ea): string {
    $h = '<select class="form-control" name="' . $ea($name) . '" id="' . $ea($id) . '" required>';
    foreach ($opts as $k => $l) {
        $h .= '<option value="' . $ea($k) . '"' . ($k === $cur ? ' selected' : '') . '>' . $ea($l) . '</option>';
    }
    return $h . '</select>';
};
?>
<section class="card adaptive" id="anamnese-adaptativa" aria-labelledby="adaptive-title" data-testid="adaptive-anamnesis">
  <h2 id="adaptive-title">Anamnese adaptativa: condições e módulos</h2>
  <p class="adaptive__lead">Os módulos aparecem só para condições <strong>confirmadas por você</strong>, com a origem da informação. Sugestões vindas de texto livre não são diagnóstico e não ativam nada até a confirmação.</p>
  <?php if ($flash): ?><div class="team-flash team-flash--<?= $flash['type'] === 'error' ? 'error' : 'success' ?>" role="<?= $flash['type'] === 'error' ? 'alert' : 'status' ?>" data-testid="cp-flash"><?= $ea($flash['text']) ?></div><?php endif; ?>

  <?php if ($composed['red_flags']): ?>
    <div class="op-message op-message--error adaptive__flags" role="alert" data-testid="adaptive-red-flags">
      <strong>Sinais de alerta (encaminhamento, não conduta)</strong>
      <ul><?php foreach ($composed['red_flags'] as $f): ?><li><span class="adaptive__flag-action"><?= $ea($f['action_label']) ?>:</span> <?= $ea($f['message']) ?> <span class="adaptive__src">(<?= $ea($f['module']) ?>)</span></li><?php endforeach; ?></ul>
    </div>
  <?php endif; ?>

  <h3>Condições</h3>
  <?php if ($suggested): ?>
    <div class="adaptive__suggestions" data-testid="adaptive-suggestions">
      <p><strong>Sugestões para confirmar ou descartar</strong></p>
      <?php foreach ($suggested as $c): $cid = (int)$c['id']; ?>
        <div class="adaptive__cond" data-testid="condition" data-code="<?= $ea($c['protocol_code']) ?>" data-status="suggested">
          <p><strong><?= $ea($names[$c['protocol_code']] ?? $c['protocol_code']) ?></strong> · <span class="badge badge-warning">Sugestão a confirmar</span><br><span class="adaptive__src"><?= $ea($c['evidence_text']) ?></span></p>
          <form method="POST" action="<?= $base ?>/condicoes/<?= $cid ?>/confirmar" class="adaptive__inline">
            <input type="hidden" name="_csrf_token" value="<?= $ea($csrfToken) ?>">
            <label class="visually-hidden" for="cs-<?= $cid ?>">Status</label><?= $sel('status', $statusOpts, 'cs-' . $cid) ?>
            <label class="visually-hidden" for="cp-<?= $cid ?>">Origem</label><?= $sel('provenance', $provOpts, 'cp-' . $cid) ?>
            <button class="btn btn-secondary btn-sm" type="submit" data-testid="condition-confirm">Confirmar</button>
          </form>
          <form method="POST" action="<?= $base ?>/condicoes/<?= $cid ?>/encerrar" class="adaptive__inline">
            <input type="hidden" name="_csrf_token" value="<?= $ea($csrfToken) ?>"><input type="hidden" name="to" value="dismissed">
            <label class="visually-hidden" for="cd-<?= $cid ?>">Motivo do descarte</label><input class="form-control" id="cd-<?= $cid ?>" name="note" required minlength="5" placeholder="Motivo do descarte">
            <button class="btn btn-secondary btn-sm" type="submit" data-testid="condition-dismiss">Descartar</button>
          </form>
        </div>
      <?php endforeach; ?>
    </div>
  <?php endif; ?>

  <?php if ($active): ?>
    <ul class="adaptive__active" data-testid="adaptive-active">
      <?php foreach ($active as $c): $cid = (int)$c['id']; ?>
        <li data-testid="condition" data-code="<?= $ea($c['protocol_code']) ?>" data-status="<?= $ea($c['status']) ?>">
          <strong><?= $ea($names[$c['protocol_code']] ?? $c['protocol_code']) ?></strong> · <?= $ea($c['status_label']) ?> · origem: <?= $ea($c['provenance_label']) ?><?= $c['valid_until'] ? ' · válida até ' . $ea(date('d/m/Y', strtotime((string)$c['valid_until']))) : '' ?>
          <details class="adaptive__more"><summary>Encerrar</summary>
            <form method="POST" action="<?= $base ?>/condicoes/<?= $cid ?>/encerrar" class="adaptive__inline">
              <input type="hidden" name="_csrf_token" value="<?= $ea($csrfToken) ?>"><input type="hidden" name="to" value="resolved">
              <label class="visually-hidden" for="cr-<?= $cid ?>">Motivo</label><input class="form-control" id="cr-<?= $cid ?>" name="note" required minlength="5" placeholder="Motivo (fica no histórico)">
              <button class="btn btn-secondary btn-sm" type="submit">Marcar como resolvida</button>
            </form>
          </details>
        </li>
      <?php endforeach; ?>
    </ul>
  <?php elseif (!$suggested): ?>
    <p data-testid="adaptive-no-conditions">Nenhuma condição registrada para este paciente.</p>
  <?php endif; ?>

  <?php if ($available): ?>
  <details class="adaptive__add" data-testid="adaptive-add">
    <summary>Registrar condição</summary>
    <form method="POST" action="<?= $base ?>/condicoes" class="adaptive__grid">
      <input type="hidden" name="_csrf_token" value="<?= $ea($csrfToken) ?>">
      <div class="form-group"><label class="form-label" for="ac-code">Protocolo</label>
        <select class="form-control" id="ac-code" name="code" required><?php foreach ($available as $code => $p): ?><option value="<?= $ea($code) ?>"><?= $ea($p['name']) ?></option><?php endforeach; ?></select></div>
      <div class="form-group"><label class="form-label" for="ac-status">Status</label><?= $sel('status', $statusOpts, 'ac-status') ?></div>
      <div class="form-group"><label class="form-label" for="ac-prov">Origem da informação</label><?= $sel('provenance', $provOpts, 'ac-prov') ?></div>
      <div class="form-group"><label class="form-label" for="ac-ev">Evidência (opcional)</label><input class="form-control" id="ac-ev" name="evidence" maxlength="500" placeholder="Ex.: laudo de 10/2026"></div>
      <div class="form-group"><label class="form-label" for="ac-until">Válida até (opcional)</label><input class="form-control" type="date" id="ac-until" name="valid_until"></div>
      <div><button class="btn btn-secondary" type="submit" data-testid="condition-record">Registrar condição</button></div>
    </form>
  </details>
  <form method="POST" action="<?= $base ?>/condicoes/sugerir" class="adaptive__inline">
    <input type="hidden" name="_csrf_token" value="<?= $ea($csrfToken) ?>">
    <button class="btn btn-link" type="submit" data-testid="condition-suggest">Procurar sugestões no texto da anamnese vigente</button>
  </form>
  <?php endif; ?>

  <?php if ($q['modules']): ?>
    <h3>Módulos ativos</h3>
    <ul class="adaptive__progress" data-testid="adaptive-progress">
      <?php foreach ($composed['completeness'] as $code => $m): ?>
        <li data-code="<?= $ea($code) ?>"><strong><?= $ea($m['name']) ?></strong> v<?= (int)$m['version'] ?> · <?= (int)$m['answered'] ?> de <?= (int)$m['required'] ?> obrigatórias<?= $m['complete'] ? ' · completo' : '' ?>
          <details class="adaptive__more" data-testid="module-dismiss"><summary>Dispensar este módulo</summary>
            <form method="POST" action="<?= $base ?>/protocolos/<?= $ea(rawurlencode((string)$code)) ?>/dispensar" class="adaptive__inline">
              <input type="hidden" name="_csrf_token" value="<?= $ea($csrfToken) ?>">
              <label class="visually-hidden" for="md-<?= $ea($code) ?>">Motivo da dispensa</label><input class="form-control" id="md-<?= $ea($code) ?>" name="reason" required minlength="5" maxlength="400" placeholder="Motivo (fica no histórico)">
              <button class="btn btn-secondary btn-sm" type="submit">Dispensar</button>
            </form>
          </details>
        </li>
      <?php endforeach; ?>
    </ul>
    <?php if ($q['draft_in_progress']): ?><p class="adaptive__src" data-testid="adaptive-draft">Há respostas em rascunho, ainda não concluídas. A última versão concluída continua valendo.</p><?php endif; ?>
    <?php if ($conflict): ?>
      <div class="op-message op-message--error adaptive__flags" role="alert" data-testid="adaptive-conflict">
        <strong>Suas respostas que não foram gravadas</strong> (outra pessoa salvou antes). Confira as respostas atuais no formulário e digite de novo o que ainda valer:
        <ul><?php foreach ($conflict as $k => $v): ?><li><?= $ea($labels[$k] ?? $k) ?>: <?= $ea(is_array($v) ? implode(', ', $v) : $v) ?></li><?php endforeach; ?></ul>
      </div>
    <?php endif; ?>
    <form method="POST" action="<?= $base ?>/protocolos" data-testid="adaptive-form" class="adaptive__form">
      <input type="hidden" name="_csrf_token" value="<?= $ea($csrfToken) ?>">
      <input type="hidden" name="base_revision" value="<?= (int)($q['revision'] ?? 0) ?>">
      <?php $section = null; foreach ($composed['items'] as $it): if (!$it['visible']) { continue; }
        $key = $it['answer_key']; $fid = 'aq-' . preg_replace('/[^a-z0-9]+/', '-', $key); $val = $answers[$key] ?? null; $err = $composed['errors'][$key] ?? null;
        if ($it['section'] !== $section): if ($section !== null) echo '</fieldset>'; $section = $it['section']; ?>
          <fieldset class="adaptive__section"><legend><?= $ea($section) ?></legend>
        <?php endif; ?>
        <div class="form-group adaptive__q<?= $err ? ' has-error' : '' ?>" data-key="<?= $ea($key) ?>">
          <?php if (in_array($it['type'], ['single', 'multi', 'yesno'], true)): ?>
            <fieldset><legend class="form-label"><?= $ea($it['text']) ?><?= $it['required'] ? ' <span aria-hidden="true">*</span><span class="visually-hidden">(obrigatória)</span>' : '' ?></legend>
              <?php foreach ($it['options'] as $ov => $ol): $checked = $it['type'] === 'multi' ? in_array((string)$ov, array_map('strval', (array)$val), true) : (string)$val === (string)$ov; ?>
                <label class="adaptive__opt"><input type="<?= $it['type'] === 'multi' ? 'checkbox' : 'radio' ?>" name="answers[<?= $ea($key) ?>]<?= $it['type'] === 'multi' ? '[]' : '' ?>" value="<?= $ea($ov) ?>"<?= $checked ? ' checked' : '' ?>> <?= $ea($ol) ?></label>
              <?php endforeach; ?>
            </fieldset>
          <?php else: ?>
            <label class="form-label" for="<?= $ea($fid) ?>"><?= $ea($it['text']) ?><?= $it['unit'] ? ' (' . $ea($it['unit']) . ')' : '' ?><?= $it['required'] ? ' <span aria-hidden="true">*</span><span class="visually-hidden">(obrigatória)</span>' : '' ?></label>
            <?php if ($it['type'] === 'textarea'): ?><textarea class="form-control" id="<?= $ea($fid) ?>" name="answers[<?= $ea($key) ?>]" rows="2" maxlength="4000"><?= $ea($val) ?></textarea>
            <?php else: ?><input class="form-control" id="<?= $ea($fid) ?>" name="answers[<?= $ea($key) ?>]" value="<?= $ea($val) ?>" type="<?= $it['type'] === 'date' ? 'date' : (in_array($it['type'], ['number', 'scale'], true) ? 'number' : 'text') ?>"<?= $it['min'] !== null ? ' min="' . $ea($it['min']) . '"' : '' ?><?= $it['max'] !== null ? ' max="' . $ea($it['max']) . '"' : '' ?> step="any"<?= $err ? ' aria-invalid="true" aria-describedby="' . $ea($fid) . '-err"' : '' ?>><?php endif; ?>
          <?php endif; ?>
          <?php if ($it['help']): ?><p class="adaptive__src"><?= $ea($it['help']) ?></p><?php endif; ?>
          <?php if (count($it['modules']) > 1): ?><p class="adaptive__src" data-testid="adaptive-shared">Usada por: <?= $ea(implode(', ', $it['modules'])) ?></p><?php endif; ?>
          <?php if ($err): ?><p class="adaptive__err" id="<?= $ea($fid) ?>-err" role="alert"><?= $ea($err) ?></p><?php endif; ?>
        </div>
      <?php endforeach; if ($section !== null) echo '</fieldset>'; ?>
      <div class="adaptive__actions">
        <button class="btn btn-secondary" type="submit" name="conclude" value="0" data-testid="adaptive-save-draft">Salvar rascunho</button>
        <button class="btn btn-primary" type="submit" name="conclude" value="1" data-testid="adaptive-conclude">Concluir módulos</button>
      </div>
    </form>
  <?php elseif ($available && !$dismissed): ?>
    <p class="adaptive__src">Nenhum módulo ativo: confirme uma condição para que o módulo correspondente apareça.</p>
  <?php endif; ?>

  <?php if ($dismissed): ?>
    <div data-testid="adaptive-dismissed">
      <h3>Módulos dispensados</h3>
      <ul class="adaptive__active">
        <?php foreach ($dismissed as $code => $d): ?>
          <li data-code="<?= $ea($code) ?>"><strong><?= $ea($names[$code] ?? $code) ?></strong> · dispensado em <?= $ea(date('d/m/Y', strtotime((string)$d['at']))) ?> · motivo: <?= $ea($d['note']) ?>
            <form method="POST" action="<?= $base ?>/protocolos/<?= $ea(rawurlencode((string)$code)) ?>/reativar" class="adaptive__inline">
              <input type="hidden" name="_csrf_token" value="<?= $ea($csrfToken) ?>"><button class="btn btn-secondary btn-sm" type="submit" data-testid="module-restore">Reativar</button>
            </form>
          </li>
        <?php endforeach; ?>
      </ul>
    </div>
  <?php endif; ?>

  <?php if ($compare): ?>
    <details class="adaptive__more" data-testid="adaptive-compare"><summary>Comparar com a conclusão anterior</summary>
      <?php foreach ($compare as $cmp): ?>
        <p><strong><?= $ea($cmp['name']) ?></strong>: <?= $ea(date('d/m/Y H:i', strtotime($cmp['from']))) ?> → <?= $ea(date('d/m/Y H:i', strtotime($cmp['to']))) ?></p>
        <?php if (!$cmp['changes']): ?><p class="adaptive__src">Sem mudanças nas respostas.</p><?php else: ?>
        <div class="adaptive__table-wrap"><table class="table adaptive__diff"><thead><tr><th scope="col">Pergunta</th><th scope="col">Antes</th><th scope="col">Depois</th></tr></thead><tbody>
          <?php foreach ($cmp['changes'] as $ch): ?><tr><td><?= $ea($ch['label']) ?></td><td><?= $ea($ch['before']) ?></td><td><?= $ea($ch['after']) ?></td></tr><?php endforeach; ?>
        </tbody></table></div>
        <?php endif; ?>
      <?php endforeach; ?>
    </details>
  <?php endif; ?>

  <?php if ($closed): ?>
    <details class="adaptive__more"><summary>Condições encerradas (<?= count($closed) ?>)</summary>
      <ul><?php foreach ($closed as $c): ?><li><?= $ea($names[$c['protocol_code']] ?? $c['protocol_code']) ?> · <?= $ea($c['status_label']) ?> em <?= $ea(date('d/m/Y', strtotime((string)$c['updated_at']))) ?></li><?php endforeach; ?></ul>
    </details>
  <?php endif; ?>
</section>
