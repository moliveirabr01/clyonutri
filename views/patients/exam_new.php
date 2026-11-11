<?php
/**
 * Formulário da solicitação de exames laboratoriais (rascunho). v47: atendimento de origem, favoritos primeiro
 * (sem vir marcados) e ordem dos exames (exam_order, campo de texto oculto para contar como alteração não salva). Sem script inline nem onclick (CSP): o filtro fica em
 * /assets/js/exames-solicitacao.js e o catálogo inteiro vem do servidor, então o que já foi marcado
 * nunca some da tela ao buscar.
 * @var array $patient @var ?array $request @var array $old @var ?string $errorMessage @var ?string $eligibilityError
 * @var array $policy @var array $categories @var array $catalog @var string $csrfToken
 * @var int[] $favoriteIds @var array $appointments
 */
$e = static fn($v) => htmlspecialchars((string)($v ?? ''), ENT_QUOTES, 'UTF-8');
$pid = (int)$patient['id'];
$selected = array_flip(array_map('intval', (array)($old['exam_ids'] ?? [])));
$synonyms = (array)($old['matched_synonym'] ?? []);
$instr = (array)($old['instructions'] ?? []);
$action = $request ? "/pacientes/{$pid}/exames/" . (int)$request['id'] . '/editar' : "/pacientes/{$pid}/exames";
$coverage = !empty($old['coverage_disclaimer_acknowledged']);
$favSet = array_flip(array_map('intval', $favoriteIds ?? []));
$byId = [];
foreach ($catalog as $x) { $byId[(int)$x['id']] = $x; }
$orderIds = array_values(array_filter(array_map('intval', (array)($old['exam_ids'] ?? [])), fn($id) => isset($byId[$id])));
if (!empty($old['exam_order'])) { $orderIds = \ClyoNutri\Domain\LaboratoryExam\ExamRequestService::applyOrder($orderIds, $old['exam_order']); }
$apptId = (int)($old['appointment_id'] ?? 0);
$typeLabel = ['first_visit' => 'Primeira consulta', 'follow_up' => 'Retorno', 'return' => 'Retorno'];
?>
<link rel="stylesheet" href="/assets/css/exames.css?v=v54-30">
<?= \ClyoNutri\Application\View\PatientHeader::render(\ClyoNutri\Core\Database\Connection::getInstance(), (int)$patient['id'], 'exames') // NI2-629/661 ?>
<div class="clinical-journey ex-page">
  <a class="ex-back" href="<?= $request ? "/pacientes/{$pid}/exames/" . (int)$request['id'] : "/pacientes/{$pid}/exames" ?>">← Voltar</a>
  <div class="ex-head">
    <div>
      <h2 class="ex-title patient-page-title"><?= $request ? 'Editar solicitação de exames laboratoriais' : 'Nova solicitação de exames laboratoriais' ?><?= $request && (int)($request['version_number'] ?? 1) > 1 ? ' · versão ' . (int)$request['version_number'] : '' ?></h2>
      <p class="ex-sub">Paciente: <strong><?= $e($patient['name']) ?></strong><?= !empty($patient['birth_date']) ? ' · nascimento ' . $e(date('d/m/Y', strtotime((string)$patient['birth_date']))) : '' ?></p>
    </div>
  </div>

  <ol class="ex-steps" aria-label="Etapas da solicitação">
    <li class="ex-step is-current">1. Rascunho</li><li class="ex-step">2. Revisão</li><li class="ex-step">3. Emissão do PDF</li>
  </ol>

  <?php if (!empty($flash)): ?>
    <div class="ex-msg <?= $flash['type'] === 'error' ? 'ex-msg-error' : 'ex-msg-success' ?>" role="<?= $flash['type'] === 'error' ? 'alert' : 'status' ?>"><?= $e($flash['message']) ?></div>
  <?php endif; ?>
  <?php if ($errorMessage): ?>
    <div class="op-message op-message--error ex-msg ex-msg-error" role="alert"><strong>Não foi possível salvar.</strong> <?= $e($errorMessage) ?></div>
  <?php endif; ?>
  <?php if ($eligibilityError): ?>
    <div class="op-message op-message--error ex-msg ex-msg-error" role="alert"><?= $e($eligibilityError) ?> <a href="/configuracoes">Abrir Configurações</a></div>
  <?php endif; ?>

  <?php $ew=(new \ClyoNutri\Domain\Clinical\WorkflowWrites(\ClyoNutri\Core\Database\Connection::getInstance()))->state('exam',(int)($request['id']??0),$pid); ?>
  <form data-workflow-draft="/api/workflow/exam/<?= (int)($request['id']??0) ?>" data-revision="<?= $e($ew['revision']) ?>" method="POST" action="<?= $e($action) ?>" id="exam-request-form" data-busy-text="Salvando…"<?= $errorMessage ? ' data-start-dirty' : '' ?>>
    <?= \ClyoNutri\Application\View\OperationalUI::state($ew['state'],$ew['saved_at'],$ew['resume_url']) ?>
    <input type="hidden" name="patient_id" value="<?= $pid ?>">
    <input type="hidden" name="_workflow_revision" value="<?= $e($ew['revision']) ?>"><input type="hidden" name="_final_request_key" value="<?= bin2hex(random_bytes(16)) ?>">
    <input type="hidden" name="_csrf_token" value="<?= $e($csrfToken) ?>">

    <section class="card ex-card">
      <h2>Atendimento de origem</h2>
      <div class="form-group">
        <label class="form-label" for="appointment_id">Vincular a um atendimento (opcional)</label>
        <select id="appointment_id" name="appointment_id" class="form-control">
          <option value="0">Sem vínculo com atendimento</option>
          <?php foreach ($appointments as $ap): $aid = (int)$ap['id']; ?>
            <option value="<?= $aid ?>"<?= $aid === $apptId ? ' selected' : '' ?>><?= $e(date('d/m/Y H:i', strtotime((string)$ap['scheduled_at']))) ?> · <?= $e($typeLabel[$ap['type']] ?? 'Consulta') ?></option>
          <?php endforeach; ?>
        </select>
        <span class="ex-muted">A solicitação aparece no prontuário e na tela da consulta escolhida.</span>
      </div>
    </section>

    <section class="card ex-card">
      <h2>1. Exames laboratoriais</h2>
      <div class="ex-search">
        <div class="form-group">
          <label class="form-label" for="exam-search">Buscar exame (nome, sigla ou sinônimo)</label>
          <input type="search" id="exam-search" class="form-control" autocomplete="off" placeholder="Ex.: glicose, ferritina, TSH, vitamina D" data-exam-search="/api/exames/buscar">
        </div>
        <div class="form-group">
          <label class="form-label" for="exam-category">Categoria</label>
          <select id="exam-category" class="form-control" data-exam-category>
            <option value="">Todas</option>
            <?php if ($favSet): ?><option value="__fav">★ Meus favoritos</option><?php endif; ?>
            <?php foreach ($categories as $cat): ?><option value="<?= $e($cat) ?>"><?= $e($cat) ?></option><?php endforeach; ?>
          </select>
        </div>
      </div>
      <div class="ex-catalog" id="exam-catalog" role="group" aria-label="Catálogo de exames">
        <?php foreach ($catalog as $exam): $id = (int)$exam['id']; $isSel = isset($selected[$id]); $syn = (string)($synonyms[$id] ?? ''); ?>
          <label class="ex-item<?= $isSel ? ' is-checked' : '' ?>" data-exam-item="<?= $id ?>" data-category="<?= $e($exam['category']) ?>"<?= isset($favSet[$id]) ? ' data-favorite="1"' : '' ?> data-name="<?= $e($exam['canonical_name']) ?>">
            <input type="checkbox" name="exam_ids[]" value="<?= $id ?>"<?= $isSel ? ' checked' : '' ?>>
            <input type="hidden" name="matched_synonym[<?= $id ?>]" value="<?= $e($isSel ? $syn : '') ?>" data-exam-synonym>
            <?php if (!empty($instr[$id])): ?><input type="hidden" name="specific_instructions[<?= $id ?>]" value="<?= $e($instr[$id]) ?>"><?php endif; ?>
            <span>
              <span class="ex-item-name"><?= isset($favSet[$id]) ? '<span class="ex-star" title="Favorito">★</span> ' : '' ?><?= $e($exam['canonical_name']) ?><?= ($exam['origin'] ?? '') === 'clinic_custom' ? ' <span class="badge badge-neutral">da clínica</span>' : '' ?></span><br>
              <span class="ex-item-meta"><?= $e($exam['category']) ?> · <?= $e($exam['sample_type']) ?><?= !empty($exam['fasting_hours']) ? ' · jejum ' . (int)$exam['fasting_hours'] . 'h' : '' ?></span>
              <span class="ex-item-syn" data-exam-synonym-label><?= $isSel && $syn !== '' ? '<br>achado por: ' . $e($syn) : '' ?></span>
            </span>
          </label>
        <?php endforeach; ?>
      </div>
      <div class="op-message op-message--info ex-none" id="exam-none" role="status">Nenhum exame laboratorial encontrado com esse termo. Exames de imagem e procedimentos invasivos não fazem parte do catálogo.</div>
      <p class="ex-count" id="exam-count" aria-live="polite"><?= count($selected) ?> exame(s) selecionado(s)</p>
      <p class="ex-muted">Não achou o exame? <a href="/exames/catalogo#personalizados">Proponha no catálogo</a> · <a href="/exames/catalogo">Gerenciar favoritos</a></p>
    </section>

    <section class="card ex-card" aria-labelledby="exam-order-title">
      <h2 id="exam-order-title">Ordem dos exames na solicitação</h2>
      <p class="ex-muted" id="exam-order-help">Os exames saem no documento nesta ordem. Use Subir e Descer para mudar.</p>
      <input type="text" name="exam_order" id="exam-order" value="<?= $e(implode(',', $orderIds)) ?>" hidden aria-hidden="true" tabindex="-1">
      <ol class="ex-order" id="exam-order-list" aria-describedby="exam-order-help">
        <?php foreach ($orderIds as $pos => $oid): ?>
          <li data-order-id="<?= $oid ?>"><span class="ex-order-name"><?= $e($byId[$oid]['canonical_name']) ?></span>
            <span class="ex-order-btns"><button type="button" class="btn btn-outline" data-order-move="up" aria-label="Subir <?= $e($byId[$oid]['canonical_name']) ?>"<?= $pos === 0 ? ' disabled' : '' ?>>Subir</button><button type="button" class="btn btn-outline" data-order-move="down" aria-label="Descer <?= $e($byId[$oid]['canonical_name']) ?>"<?= $pos === count($orderIds) - 1 ? ' disabled' : '' ?>>Descer</button></span></li>
        <?php endforeach; ?>
      </ol>
      <p class="ex-muted" id="exam-order-empty"<?= $orderIds ? ' hidden' : '' ?>>Marque exames acima para definir a ordem.</p>
    </section>

    <section class="card ex-card">
      <h2>2. Justificativa nutricional</h2>
      <div class="form-group">
        <label class="form-label" for="clinical_justification">Justificativa (obrigatória para revisar e emitir)</label>
        <textarea id="clinical_justification" name="clinical_justification" class="form-control" rows="5" maxlength="4000" placeholder="Ex.: avaliar reservas de ferro e perfil glicêmico para ajustar o plano alimentar."><?= $e($old['clinical_justification'] ?? '') ?></textarea>
        <span class="ex-muted">Mínimo de 15 caracteres na revisão. Use termos do acompanhamento nutricional.</span>
      </div>
      <div class="form-group">
        <label class="form-label" for="diagnostic_hypothesis_nutritional">Diagnóstico ou hipótese nutricional (opcional)</label>
        <input type="text" id="diagnostic_hypothesis_nutritional" name="diagnostic_hypothesis_nutritional" class="form-control" maxlength="255" value="<?= $e($old['diagnostic_hypothesis_nutritional'] ?? '') ?>" placeholder="Ex.: risco de deficiência de ferro">
      </div>
    </section>

    <section class="card ex-card">
      <h2>3. Aviso de cobertura</h2>
      <div class="ex-disclaimer">
        <p class="ex-muted"><?= $e(\ClyoNutri\Core\Brand::textoExibido((string)($policy['disclaimer_coverage'] ?? ''))) ?></p>
        <label class="ex-check">
          <input type="checkbox" name="coverage_disclaimer_acknowledged" value="1"<?= $coverage ? ' checked' : '' ?>>
          <span>Li o aviso e vou orientar o paciente sobre a cobertura do plano de saúde. (Obrigatório para emitir.)</span>
        </label>
      </div>
    </section>

    <div class="ex-actions">
      <button type="submit" class="btn btn-primary"<?= $eligibilityError ? ' disabled' : '' ?>><?= $request ? 'Salvar alterações' : 'Salvar rascunho' ?></button>
      <a class="btn btn-outline" href="/pacientes/<?= $pid ?>/exames">Cancelar</a>
    </div>
  </form>
</div>
<script src="/assets/js/exames-solicitacao.js?v=botanical-20261029b" defer></script>
