<?php
/**
 * Laudos e resultados da solicitação (v48, NI2-539). Sem script inline (CSP): formulários dentro de <details>.
 * @var array $request @var array $resultDocs @var array $coverage @var string $csrfToken @var callable $e @var int $pid
 */
$base = "/pacientes/{$pid}/exames/" . (int)$request['id'];
$items = (array)($request['items'] ?? []);
$canAttach = $request['status'] === 'issued';
$docBadge = ['pending_review' => 'badge-warning', 'accepted' => 'badge-success', 'rejected' => 'badge-danger'];
$fmtD = static fn($v) => $v ? date('d/m/Y', strtotime((string)$v)) : '';
$fmtDT = static fn($v) => $v ? date('d/m/Y H:i', strtotime((string)$v)) : '';
$kb = static fn($b) => ((int)$b) < 1024 ? (int)$b . ' B' : number_format(((int)$b) / 1024, 0, ',', '.') . ' KB';
$pending = array_values(array_filter($resultDocs, fn($d) => $d['status'] === 'pending_review'));
$itemCheckboxes = static function (string $prefix, array $checked = []) use ($items, $e): string {
    $html = '<fieldset class="ex-fieldset"><legend class="form-label">Exames que este laudo traz</legend>';
    foreach ($items as $it) {
        $id = (int)$it['id'];
        $html .= '<label class="ex-check"><input type="checkbox" name="item_ids[]" value="' . $id . '"' . (in_array($id, $checked, true) ? ' checked' : '') . '> <span>' . $e($it['exam_canonical_name']) . '</span></label>';
    }
    return $html . '</fieldset>';
};
$metaFields = static function (string $prefix, array $v = []) use ($e): string {
    $max = date('Y-m-d');
    return '<div class="ex-form-grid">'
        . '<div class="form-group ex-span2"><label class="form-label" for="' . $prefix . '-lab">Laboratório (opcional)</label><input type="text" id="' . $prefix . '-lab" name="laboratory_name" class="form-control" maxlength="255" value="' . $e($v['laboratory_name'] ?? '') . '"></div>'
        . '<div class="form-group"><label class="form-label" for="' . $prefix . '-col">Data da coleta</label><input type="date" id="' . $prefix . '-col" name="collected_at" class="form-control" max="' . $max . '" value="' . $e($v['collected_at'] ?? '') . '"></div>'
        . '<div class="form-group"><label class="form-label" for="' . $prefix . '-iss">Data do laudo</label><input type="date" id="' . $prefix . '-iss" name="report_issued_at" class="form-control" max="' . $max . '" value="' . $e($v['report_issued_at'] ?? '') . '"></div>'
        . '<div class="form-group ex-span2"><label class="form-label" for="' . $prefix . '-notes">Observação (opcional)</label><input type="text" id="' . $prefix . '-notes" name="notes" class="form-control" maxlength="1000" value="' . $e($v['notes'] ?? '') . '"></div>'
        . '</div>';
};
?>
<section class="card ex-card" id="laudos" aria-labelledby="laudos-titulo">
  <h2 id="laudos-titulo">Laudos e resultados</h2>
  <?php if ($pending): ?>
    <div class="op-message op-message--info ex-msg ex-msg-warning" role="status"><strong><?= count($pending) ?> laudo(s) enviado(s) pela paciente aguardando sua revisão.</strong> Enquanto não for aceito, o laudo não conta como resultado recebido.</div>
  <?php endif; ?>

  <table class="table ex-table ex-coverage" aria-label="Situação por exame">
    <thead><tr><th>Exame</th><th>Resultado</th></tr></thead>
    <tbody>
    <?php foreach ($items as $it): $c = $coverage[(int)$it['id']] ?? null; ?>
      <tr><td><?= $e($it['exam_canonical_name']) ?></td>
        <td><?php if ($c): ?><span class="badge badge-success">Laudo recebido</span><?= $c['last_collected_at'] ? ' <span class="ex-muted">coleta ' . $e($fmtD($c['last_collected_at'])) . '</span>' : '' ?><?php else: ?><span class="badge badge-neutral">Aguardando laudo</span><?php endif; ?></td></tr>
    <?php endforeach; ?>
    </tbody>
  </table>

  <?php if ($resultDocs): ?>
    <ul class="ex-docs">
    <?php foreach ($resultDocs as $d): $did = (int)$d['id']; ?>
      <li class="ex-doc<?= $d['is_superseded'] ? ' is-superseded' : '' ?>" id="laudo-<?= $did ?>">
        <div class="ex-doc-head">
          <strong><?= $e($d['original_name']) ?></strong>
          <span class="badge <?= $docBadge[$d['status']] ?? 'badge-neutral' ?>"><?= $e($d['status_label']) ?></span>
          <?php if ((int)$d['version_number'] > 1): ?><span class="ex-muted">versão <?= (int)$d['version_number'] ?></span><?php endif; ?>
          <?php if ($d['is_superseded']): ?><span class="badge badge-neutral">Substituído pela versão <?= (int)$d['superseded_by_version'] ?></span><?php endif; ?>
          <a class="btn btn-sm btn-outline" href="<?= $base ?>/laudos/<?= $did ?>/abrir" rel="noopener" target="_blank"><?= $d['mime_type'] === 'application/pdf' ? 'Baixar PDF' : 'Abrir imagem' ?></a>
        </div>
        <p class="ex-muted ex-doc-meta">
          <?= $d['uploaded_by_type'] === 'patient' ? 'Enviado pela paciente pelo app' : 'Anexado por ' . $e($d['uploaded_by_user_name']) ?> em <?= $e($fmtDT($d['created_at'])) ?>
          · <?= $e($kb($d['size_bytes'])) ?>
          <?= $d['laboratory_name'] ? ' · ' . $e($d['laboratory_name']) : '' ?>
          <?= $d['collected_at'] ? ' · coleta ' . $e($fmtD($d['collected_at'])) : '' ?>
          <?= $d['report_issued_at'] ? ' · laudo de ' . $e($fmtD($d['report_issued_at'])) : '' ?>
        </p>
        <?php if ($d['items']): ?><p class="ex-doc-meta">Exames: <?= $e(implode(' · ', array_column($d['items'], 'exam_canonical_name'))) ?></p><?php endif; ?>
        <?php if ($d['notes']): ?><p class="ex-doc-meta">Observação: <?= $e($d['notes']) ?></p><?php endif; ?>
        <?php if ($d['correction_reason']): ?><p class="ex-doc-meta">Correção: <?= $e($d['correction_reason']) ?></p><?php endif; ?>
        <?php if ($d['status'] !== 'pending_review' && $d['reviewed_by_name'] && $d['uploaded_by_type'] === 'patient'): ?>
          <p class="ex-doc-meta">Revisado por <?= $e($d['reviewed_by_name']) ?> em <?= $e($fmtDT($d['reviewed_at'])) ?><?= $d['review_note'] ? ': ' . $e($d['review_note']) : '' ?></p>
        <?php endif; ?>
        <p class="ex-doc-meta ex-mono" title="Código de integridade (SHA-256) do arquivo original">SHA-256 <?= $e(substr((string)$d['sha256'], 0, 16)) ?>…</p>

        <?php if ($d['status'] === 'pending_review'): ?>
          <details class="ex-details" open>
            <summary>Revisar este laudo</summary>
            <form method="POST" action="<?= $base ?>/laudos/<?= $did ?>/revisar" data-no-dirty>
              <input type="hidden" name="_csrf_token" value="<?= $e($csrfToken) ?>">
              <?= $itemCheckboxes('rv' . $did, array_column($d['items'], 'exam_request_item_id')) ?>
              <div class="form-group"><label class="form-label" for="rv-note-<?= $did ?>">Nota (obrigatória para recusar; a paciente vê)</label>
                <input type="text" id="rv-note-<?= $did ?>" name="review_note" class="form-control" maxlength="1000"></div>
              <div class="ex-actions">
                <button type="submit" name="decision" value="accept" class="btn btn-primary">Aceitar laudo</button>
                <button type="submit" name="decision" value="reject" class="btn btn-outline">Recusar</button>
              </div>
            </form>
          </details>
        <?php elseif ($d['status'] === 'accepted' && !$d['is_superseded'] && $canAttach): ?>
          <details class="ex-details">
            <summary>Corrigir (anexar nova versão)</summary>
            <form method="POST" action="<?= $base ?>/laudos/<?= $did ?>/nova-versao" enctype="multipart/form-data">
              <input type="hidden" name="_csrf_token" value="<?= $e($csrfToken) ?>">
              <p class="ex-muted">O arquivo atual continua guardado como versão <?= (int)$d['version_number'] ?>. Campos vazios repetem os dados atuais.</p>
              <div class="form-group"><label class="form-label" for="nv-file-<?= $did ?>">Arquivo corrigido (PDF, JPG ou PNG, até 15 MB)</label>
                <input type="file" id="nv-file-<?= $did ?>" name="laudo" class="form-control" accept=".pdf,.jpg,.jpeg,.png,application/pdf,image/jpeg,image/png" required></div>
              <div class="form-group"><label class="form-label" for="nv-reason-<?= $did ?>">O que está sendo corrigido</label>
                <input type="text" id="nv-reason-<?= $did ?>" name="correction_reason" class="form-control" minlength="5" maxlength="1000" required></div>
              <div class="ex-actions"><button type="submit" class="btn btn-primary">Anexar versão <?= (int)$d['version_number'] + 1 ?></button></div>
            </form>
          </details>
        <?php endif; ?>
      </li>
    <?php endforeach; ?>
    </ul>
  <?php else: ?>
    <p class="ex-muted">Nenhum laudo anexado ainda. A paciente também pode enviar o laudo pelo app; ele chega aqui aguardando sua revisão.</p>
  <?php endif; ?>

  <?php if ($canAttach): ?>
    <?php
      $recovery = (!empty($_SESSION['exam_form_recovery']) && (int)($_SESSION['exam_form_recovery']['req_id'] ?? 0) === (int)$request['id'])
          ? $_SESSION['exam_form_recovery']
          : null;
      $hasRecovery = !empty($recovery);
      $recValues = $hasRecovery ? [
          'laboratory_name' => $recovery['laboratory_name'] ?? '',
          'collected_at' => $recovery['collected_at'] ?? '',
          'report_issued_at' => $recovery['report_issued_at'] ?? '',
          'notes' => $recovery['notes'] ?? '',
      ] : [];
      $recItems = $hasRecovery ? (array)($recovery['item_ids'] ?? []) : [];
      $fileError = $hasRecovery ? ($recovery['error_message'] ?? '') : '';
    ?>
    <details class="ex-details"<?= ($hasRecovery || !$resultDocs) ? ' open' : '' ?>>
      <summary>Anexar laudo</summary>
      <form method="POST" action="<?= $base ?>/laudos" enctype="multipart/form-data" id="form-anexar-laudo">
        <input type="hidden" name="_csrf_token" value="<?= $e($csrfToken) ?>">
        <p class="ex-muted">O arquivo é guardado exatamente como chegou (original), com código de integridade. PDF com conteúdo ativo, com senha ou arquivo que não seja PDF/JPG/PNG de verdade é recusado.</p>
        <div class="form-group"><label class="form-label" for="up-file">Arquivo do laudo (PDF, JPG ou PNG, até 15 MB)</label>
          <input type="file" id="up-file" name="laudo" class="form-control" accept=".pdf,.jpg,.jpeg,.png,application/pdf,image/jpeg,image/png" required>
          <p class="op-message op-message--error form-error" id="up-file-error" role="alert"<?= $fileError ? '' : ' hidden' ?>><?= $e($fileError) ?></p>
        </div>
        <?= $metaFields('up', $recValues) ?>
        <?= $itemCheckboxes('up', $recItems) ?>
        <div class="ex-actions"><button type="submit" class="btn btn-primary">Anexar laudo</button></div>
      </form>
    </details>
  <?php elseif ($request['status'] !== 'issued'): ?>
    <p class="ex-muted">Laudos são anexados a solicitações emitidas.</p>
  <?php endif; ?>
</section>
