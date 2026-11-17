<?php
/**
 * Solicitação de exames laboratoriais: situação, ações do fluxo (rascunho → revisão → emissão), versões (v47:
 * corrigir uma emitida cria a versão seguinte) e prévia do documento.
 * Sem onclick/script inline (CSP): modal com data-dialog-open / data-dialog-close (clinical-journeys.js, csp-actions.js).
 * @var array $patient @var array $request @var array $policy
 * @var array $doc @var ?array $flash @var string $csrfToken @var array $resultDocs @var array $coverage (v48, NI2-539)
 */
$e = static fn($v) => htmlspecialchars((string)($v ?? ''), ENT_QUOTES, 'UTF-8');
$pid = (int)$patient['id'];
$rid = (int)$request['id'];
$base = "/pacientes/{$pid}/exames/{$rid}";
$st = (string)$request['status'];
$badge = ['draft' => 'badge-neutral', 'review' => 'badge-warning', 'issued' => 'badge-success', 'cancelled' => 'badge-danger'][$st] ?? 'badge-neutral';
$order = ['draft' => 1, 'review' => 2, 'issued' => 3, 'cancelled' => 0];
$cur = $order[$st] ?? 0;
$hasPdf = $st === 'issued' && !empty($request['pdf_path']);
$fmt = static fn($v) => $v ? date('d/m/Y H:i', strtotime((string)$v)) : '';
$ver = (int)($request['version_number'] ?? 1);
?>
<?= \ClyoNutri\Application\View\OperationalUI::state(['draft'=>'saved','review'=>'review','issued'=>'issued','cancelled'=>'completed'][$st]??'not_started',$request['updated_at']??$request['created_at']??null) ?>

<link rel="stylesheet" href="/assets/css/exames.css?v=v54-68">
<link rel="stylesheet" href="/assets/css/exame-documento.css?v=botanical-20261029a">
<?= \ClyoNutri\Application\View\PatientHeader::render(\ClyoNutri\Core\Database\Connection::getInstance(), (int)$patient['id'], 'exames') // NI2-629/661 ?>
<div class="clinical-journey ex-page">
  <a class="ex-back" href="/pacientes/<?= $pid ?>/exames">← Voltar às solicitações do paciente</a>
  <div class="ex-head">
    <div>
      <h2 class="ex-title patient-page-title">Solicitação <?= $e($request['request_number']) ?></h2>
      <p class="ex-sub">Paciente: <strong><?= $e($patient['name']) ?></strong> · <span class="badge <?= $badge ?>"><?= $e($doc['status_label']) ?></span><?= $ver > 1 ? ' · versão ' . $ver : '' ?></p>
      <?php if (!empty($request['appointment_id'])): ?>
        <p class="ex-version">Atendimento de origem: <a href="/consulta/<?= (int)$request['appointment_id'] ?>"><?= $e($fmt($request['appointment_scheduled_at'] ?? '')) ?: 'abrir consulta' ?></a></p>
      <?php endif; ?>
      <?php if (!empty($request['supersedes_request_id'])): ?>
        <p class="ex-version">Substitui a solicitação <a href="/pacientes/<?= $pid ?>/exames/<?= (int)$request['supersedes_request_id'] ?>"><?= $e($request['supersedes_request_number'] ?? '') ?></a>.</p>
      <?php endif; ?>
      <?php if (!empty($request['superseded_by_id'])): ?>
        <p class="ex-version"><strong>Substituída</strong> pela solicitação <a href="/pacientes/<?= $pid ?>/exames/<?= (int)$request['superseded_by_id'] ?>"><?= $e($request['superseded_by_number'] ?? '') ?></a>.</p>
      <?php endif; ?>
    </div>
    <div class="ex-actions">
      <?php if ($st === 'draft'): ?><form method="POST" action="<?= $base ?>/revisar"><input type="hidden" name="_csrf_token" value="<?= $e($csrfToken) ?>"><?= \ClyoNutri\Application\View\OperationalUI::button('Enviar para revisão','primary','submit') ?></form><?php endif; ?>
      <?php if (in_array($st, ['issued', 'cancelled'], true)): ?>
        <a class="btn btn-primary" href="<?= $base ?>/imprimir" target="_blank" rel="noopener">Imprimir</a>
      <?php endif; ?>
      <?php if ($hasPdf): ?>
        <a class="btn btn-secondary" href="<?= $base ?>/pdf">Baixar PDF</a>
      <?php endif; ?>
      <?php if (in_array($st, ['draft', 'review'], true)): ?>
        <a class="btn btn-secondary" href="<?= $base ?>/editar">Editar</a>
      <?php endif; ?>
      <?php if ($st === 'issued'): ?>
        <button type="button" class="btn btn-outline" data-dialog-open="modal-exam-version">Corrigir (nova versão)</button>
      <?php endif; ?>
      <?php if ($st !== 'cancelled'): ?>
        <button type="button" class="btn btn-outline u-color-neutral-gray-500 u-bc-neutral-gray-300" data-dialog-open="modal-exam-cancel">Cancelar solicitação</button>
      <?php endif; ?>
    </div>
  </div>

  <?php if ($st !== 'cancelled'): ?>
  <ol class="ex-steps" aria-label="Etapas da solicitação">
    <li class="ex-step <?= $cur === 1 ? 'is-current' : 'is-done' ?>">1. Rascunho</li>
    <li class="ex-step <?= $cur === 2 ? 'is-current' : ($cur > 2 ? 'is-done' : '') ?>">2. Revisão e emissão</li>
    <li class="ex-step <?= $cur === 3 ? 'is-current' : '' ?>">3. PDF emitido</li>
  </ol>
  <?php endif; ?>

  <?php if ($flash): ?>
    <div class="ex-msg <?= $flash['type'] === 'error' ? 'ex-msg-error' : 'ex-msg-success' ?>" role="<?= $flash['type'] === 'error' ? 'alert' : 'status' ?>"><?= $e($flash['message']) ?></div>
  <?php endif; ?>

  <?php if ($st === 'review'): ?>
    <section class="card ex-card" id="revisao-emissao">
      <h2>Revisão e emissão</h2>
      <p class="ex-muted">Ao emitir, o PDF é gerado e guardado no servidor com um código de integridade (SHA-256). Confira a prévia do documento abaixo e confirme a emissão.</p>
      <form method="POST" action="<?= $base ?>/emitir" data-busy-text="Gerando o PDF…">
        <input type="hidden" name="_csrf_token" value="<?= $e($csrfToken) ?>">
        <div class="ex-disclaimer">
          <p class="ex-muted"><?= $e(\ClyoNutri\Core\Brand::textoExibido((string)($policy['disclaimer_coverage'] ?? ''))) ?></p>
          <label class="ex-check"><input type="checkbox" name="coverage_disclaimer_acknowledged" value="1" required<?= (int)$request['coverage_disclaimer_acknowledged'] === 1 ? ' checked' : '' ?>>
            <span>Li o aviso e vou orientar o paciente sobre a cobertura do plano de saúde.</span></label>
        </div>
        <div class="u-flex u-gap-2 u-items-center u-mt-3">
          <button type="submit" class="btn btn-primary" data-testid="btn-emitir-pdf">Revisar e emitir PDF</button>
          <a class="btn btn-outline" href="<?= $base ?>/editar">Editar</a>
        </div>
      </form>
    </section>
  <?php elseif ($st === 'issued'): ?>
    <section class="card ex-card">
      <h2>Emitido</h2>
      <dl class="ex-dl">
        <dt>Emitido em</dt><dd><?= $e($doc['issued_at_local']) ?></dd>
        <?php if ($hasPdf): ?>
          <dt>PDF</dt><dd><?= (int)$request['pdf_size'] < 1024 ? (int)$request['pdf_size'] . ' B' : number_format((int)$request['pdf_size'] / 1024, 1, ',', '.') . ' KB' ?>, gerado em <?= $e($fmt($request['pdf_generated_at'])) ?></dd>
          <dt>Integridade (SHA-256)</dt><dd class="ex-mono"><?= $e($request['pdf_hash']) ?></dd>
        <?php else: ?>
          <dt>PDF</dt><dd>Emitida antes da versão 46, sem PDF guardado. Use Imprimir; para ter o PDF, use "Corrigir (nova versão)" e emita.</dd>
        <?php endif; ?>
      </dl>
      <p class="ex-muted">O documento sai com linha para assinatura e carimbo do nutricionista. Assinatura digital ainda não está disponível.</p>
    </section>
  <?php elseif ($st === 'cancelled'): ?>
    <div class="op-message op-message--info ex-msg ex-msg-error" role="status"><strong>Solicitação cancelada em <?= $e($doc['cancelled_at_local']) ?>.</strong> Motivo: <?= $e($request['cancellation_reason']) ?></div>
  <?php endif; ?>

  <?php if ($st === 'issued' || !empty($resultDocs)) { require __DIR__ . '/partials/exam_results.php'; } ?>

  <section class="card ex-card">
    <h2><?= in_array($st, ['issued', 'cancelled'], true) ? 'Documento' : 'Prévia do documento' ?></h2>
    <?php if (!in_array($st, ['issued', 'cancelled'], true)): ?><p class="ex-muted">Prévia: número, data e dados do profissional são fixados na emissão.</p><?php endif; ?>
    <div class="ex-preview"><?php require __DIR__ . '/partials/exam_document.php'; ?></div>
  </section>

  <?php if ($st !== 'cancelled'): ?>
  <div class="modal-overlay" id="modal-exam-cancel" data-dialog="modal">
    <div class="modal-dialog">
      <div class="modal-header"><h2 class="section-title">Cancelar solicitação de exames</h2></div>
      <form method="POST" action="<?= $base ?>/cancelar">
        <div class="modal-body">
          <input type="hidden" name="_csrf_token" value="<?= $e($csrfToken) ?>">
          <p class="ex-muted">A solicitação fica no histórico como cancelada<?= $st === 'issued' ? ' e o PDF deixa de ser entregue' : '' ?>.</p>
          <div class="form-group">
            <label class="form-label" for="cancellation_reason">Motivo do cancelamento</label>
            <textarea id="cancellation_reason" name="cancellation_reason" class="form-control" rows="3" required minlength="5" maxlength="1000"></textarea>
          </div>
        </div>
        <div class="modal-footer ex-modal-actions">
          <button type="button" class="btn btn-outline" data-dialog-close>Voltar</button>
          <button type="submit" class="btn btn-danger">Confirmar cancelamento</button>
        </div>
      </form>
    </div>
  </div>
  <?php endif; ?>

  <?php if ($st === 'issued'): ?>
  <div class="modal-overlay" id="modal-exam-version" data-dialog="modal">
    <div class="modal-dialog">
      <div class="modal-header"><h2 class="section-title">Corrigir com nova versão</h2></div>
      <form method="POST" action="<?= $base ?>/nova-versao">
        <div class="modal-body">
          <input type="hidden" name="_csrf_token" value="<?= $e($csrfToken) ?>">
          <p class="ex-muted">Esta solicitação não muda: ela fica cancelada como "substituída pela versão <?= $ver + 1 ?>", com o PDF guardado no histórico. Abre um rascunho com os mesmos exames, justificativa e atendimento para você corrigir, revisar e emitir.</p>
          <div class="form-group">
            <label class="form-label" for="version_reason">O que será corrigido</label>
            <textarea id="version_reason" name="version_reason" class="form-control" rows="3" required minlength="5" maxlength="800"></textarea>
          </div>
        </div>
        <div class="modal-footer ex-modal-actions">
          <button type="button" class="btn btn-outline" data-dialog-close>Voltar</button>
          <button type="submit" class="btn btn-primary">Criar versão <?= $ver + 1 ?></button>
        </div>
      </form>
    </div>
  </div>
  <?php endif; ?>
</div>
