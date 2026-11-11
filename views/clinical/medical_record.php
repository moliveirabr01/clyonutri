<?php
/** Prontuário e evolução clínica do paciente.
 *  v54.25 (NI2-220 I9): classes do DS + clinical-record.css, sem estilo inline e sem cor fixa.
 *  NI2-220 (I10): o texto da integridade diz só o que a cadeia SHA-256 garante (detecção de alteração),
 *  sem prometer autenticidade nem proteção contra adulteração.
 *  Variáveis: $patient, $integrity, $timeline, $clinicalSummary, $canSeeAudit, $csrfToken. */
use ClyoNutri\Core\I18n\LabelMap;
use ClyoNutri\Domain\Clinical\ClinicalRecordContent;
use ClyoNutri\Domain\Clinical\ClinicalRecordService;

$pid = (int)$patient['id'];
$isValid = !empty($integrity['is_valid']);
$errorRef = isset($_GET['ref']) && is_string($_GET['ref']) && preg_match('/^[a-f0-9]{12}$/D', $_GET['ref']) ? $_GET['ref'] : null;
$src = $clinicalSummary['source'] ?? null;
?>
<link rel="stylesheet" href="/assets/css/clinical-record.css?v=v54-27">
<div class="cr" data-testid="prontuario-page">
<?php if (!empty($_GET['error'])): ?>
  <div class="op-message op-message--error" role="alert" data-testid="prontuario-error">
    <strong>Erro:</strong> <?= !empty($_GET['msg']) && is_string($_GET['msg']) ? htmlspecialchars($_GET['msg']) : 'Não foi possível concluir a operação no prontuário. Nada foi alterado; tente de novo.' ?>
    <?php if ($errorRef): ?><span class="cr-caption"> Código para o suporte: <?= htmlspecialchars($errorRef) ?>.</span><?php endif; ?>
  </div>
<?php endif; ?>
<?php if (!empty($_GET['success'])): ?>
  <div class="op-message op-message--success" role="status">
    <?= $_GET['success'] === 'registro_adicionado' ? 'Evolução registrada no prontuário.' : ($_GET['success'] === 'registro_retificado' ? 'Retificação salva como nova versão, com a justificativa.' : 'Pronto, alteração salva.') ?>
  </div>
<?php endif; ?>

<?= \ClyoNutri\Application\View\PatientHeader::render(\ClyoNutri\Core\Database\Connection::getInstance(), $pid, 'atendimento') // NI2-661 ?>
<div class="cr-head">
  <div>
    <h2 class="patient-page-title">Prontuário e evolução clínica</h2>
    <p class="cr-head__lead">Histórico de registros clínicos.</p>
  </div>
  <div class="cr-actions">
    <a href="/pacientes/<?= $pid ?>/prontuario/imprimir" target="_blank" rel="noopener" class="btn btn-secondary">
      <svg class="cr-icon" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><polyline points="6 9 6 2 18 2 18 9"/><path d="M6 18H4a2 2 0 0 1-2-2v-5a2 2 0 0 1 2-2h16a2 2 0 0 1 2 2v5a2 2 0 0 1-2 2h-2"/><rect x="6" y="14" width="12" height="8"/></svg>
      Imprimir prontuário
    </a>
    <a href="/pacientes/<?= $pid ?>/prontuario/exportar" target="_blank" rel="noopener" class="btn btn-secondary">
      <svg class="cr-icon" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="7 10 12 15 17 10"/><line x1="12" y1="15" x2="12" y2="3"/></svg>
      Exportar histórico de registros
    </a>
    <button type="button" class="btn btn-primary" data-show="modal-new-record">+ Nova Evolução / Anotação</button>
  </div>
</div>

<?php if ((int)($integrity['total_records'] ?? 0) > 0 || !$isValid): ?>
<section class="cr-integrity<?= $isValid ? '' : ' cr-integrity--warn' ?>" data-testid="integrity-status" data-valid="<?= $isValid ? '1' : '0' ?>" aria-label="Conferência do encadeamento dos registros">
  <span class="cr-integrity__icon" aria-hidden="true"><?= $isValid ? '✓' : '!' ?></span>
  <div class="cr-integrity__body">
    <strong class="cr-integrity__title"><?= $isValid ? 'Encadeamento dos registros conferido, sem divergências' : 'Atenção: a conferência do encadeamento encontrou divergência' ?></strong>
    <p class="cr-integrity__text">
      <?= (int)($integrity['total_records'] ?? 0) ?> versões registradas; as anteriores são preservadas.
      Cada versão guarda um código SHA-256 ligado à versão anterior, o que permite detectar alteração ou remoção feita fora do sistema.
      Não é assinatura digital (ICP-Brasil) e não substitui backup nem o controle de acesso ao banco.
      <?php if (!empty($integrity['legacy_records'])): ?>Título e justificativa de <?= (int)$integrity['legacy_records'] ?> registro(s) antigo(s) não fazem parte desta conferência.<?php endif; ?>
    </p>
    <?php if ($canSeeAudit && !empty($integrity['latest_hash'])): ?>
      <details class="cr-tech">
        <summary>Ver detalhes técnicos da conferência</summary>
        <div class="cr-tech__code">Código do último elo da cadeia (SHA-256, não é assinatura digital): <?= htmlspecialchars($integrity['latest_hash']) ?></div>
      </details>
    <?php endif; ?>
  </div>
</section>
<?php endif; ?>

<section class="card cr-card cr-summary" aria-labelledby="cr-summary-title">
  <div class="cr-card__head">
    <h3 id="cr-summary-title">Resumo crítico da anamnese para prescrição</h3>
    <?php if ($clinicalSummary['has_data'] && $src): ?>
      <span class="cr-summary__source" data-testid="summary-source">
        <?php if (($src['kind'] ?? '') === 'cadastro'): ?>
          Fonte: <a class="cr-link" href="<?= htmlspecialchars((string)$src['url']) ?>"><?= htmlspecialchars((string)$src['label']) ?></a>
        <?php else: ?>
          Fonte: <a class="cr-link" href="<?= htmlspecialchars((string)$src['url']) ?>">Anamnese v<?= (int)$src['version'] ?></a> por <?= htmlspecialchars((string)$src['author']) ?> em <?= date('d/m/Y', strtotime((string)$src['recorded_at'])) ?>
        <?php endif; ?>
      </span>
    <?php endif; ?>
  </div>
  <?php if (!$clinicalSummary['has_data']): ?>
    <p class="cr-muted">Nenhum dado clínico registrado na anamnese ainda. Não há dados inferidos.</p>
  <?php else: ?>
    <div class="cr-summary__grid">
      <div class="cr-summary__item<?= !empty($clinicalSummary['allergies']) ? ' cr-summary__item--alert' : '' ?>">
        <span class="cr-summary__label">Alergias alimentares</span>
        <span class="cr-summary__value"><?= htmlspecialchars($clinicalSummary['allergy_label']) ?><?= !empty($clinicalSummary['allergies']) ? ': ' . htmlspecialchars($clinicalSummary['allergies']) : '' ?></span>
      </div>
      <div class="cr-summary__item<?= !empty($clinicalSummary['restrictions']) ? ' cr-summary__item--warning' : '' ?>">
        <span class="cr-summary__label">Restrições e intolerâncias</span>
        <span class="cr-summary__value"><?= htmlspecialchars(LabelMap::recorded($clinicalSummary['restrictions'] ?? null)) ?></span>
      </div>
      <div class="cr-summary__item">
        <span class="cr-summary__label">Objetivos principais</span>
        <span class="cr-summary__value"><?= htmlspecialchars(LabelMap::recorded($clinicalSummary['objectives'] ?? null)) ?></span>
      </div>
    </div>
  <?php endif; ?>
</section>

<?php $rxPatientId=$pid;$rxAppointmentId=null;require __DIR__.'/../prescriptions/timeline.php'; ?>
<?php $examPatientId=$pid;$examAppointmentId=null;require __DIR__.'/../patients/partials/exam_context.php'; ?>

<section class="card cr-card" aria-labelledby="cr-timeline-title">
  <div class="cr-card__head">
    <h3 id="cr-timeline-title">Evolução clínica e histórico preservado (<?= count($timeline) ?> registros)</h3>
    <span class="cr-caption">Cada lançamento guarda autor, data e hora</span>
  </div>
  <?php if (empty($timeline)): ?>
    <div class="cr-empty">
      <p>Nenhuma entrada clínica registrada ainda neste prontuário.</p>
      <button type="button" class="btn btn-primary" data-show="modal-new-record">Registrar primeira evolução</button>
    </div>
  <?php else: ?>
    <ol class="cr-timeline">
      <?php foreach (array_reverse($timeline) as $rec): ?>
        <li class="cr-entry<?= $rec['is_retracted'] ? ' cr-entry--retracted' : '' ?>">
          <div class="cr-entry__head">
            <div>
              <div class="cr-entry__title">
                <span class="badge cr-badge--version">v<?= (int)$rec['version'] ?></span>
                <h4><?= htmlspecialchars($rec['title']) ?></h4>
                <?php if ($rec['is_retracted']): ?><span class="badge badge-warning">Superado / retificado</span><?php endif; ?>
                <?php if ($rec['supersedes_id']): ?><span class="badge badge-info">Retificação do registro #<?= (int)$rec['supersedes_id'] ?></span><?php endif; ?>
              </div>
              <p class="cr-entry__meta">Por <strong><?= htmlspecialchars($rec['professional_name']) ?></strong> em <?= date('d/m/Y \à\s H:i', strtotime($rec['created_at'])) ?></p>
            </div>
            <?php if (!$rec['is_retracted']): ?>
              <button type="button" class="btn btn-outline cr-btn--compact" data-call="openRevisionModal" data-args="[<?= (int)$rec['id'] ?>, <?= htmlspecialchars(json_encode((string)$rec['title'], JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP), ENT_QUOTES) ?>]"
                <?php $rv = ClinicalRecordContent::present($rec['content'] ?? ($rec['content_unparsed'] ?? null)); ?>
                data-rev-id="<?= (int)$rec['id'] ?>" data-rev-notes="<?= htmlspecialchars($rv['text'], ENT_QUOTES) ?>"
                data-rev-goals="<?= htmlspecialchars($rv['goals'], ENT_QUOTES) ?>" data-rev-structured="<?= $rv['structured'] ? '1' : '0' ?>">
                Retificar formalmente (v<?= (int)$rec['version'] + 1 ?>)
              </button>
            <?php endif; ?>
          </div>
          <?php if (!empty($rec['revision_reason'])): ?>
            <p class="cr-entry__reason"><strong>Justificativa da retificação:</strong> <?= htmlspecialchars($rec['revision_reason']) ?></p>
          <?php endif; ?>
          <?php $view = ClinicalRecordContent::present($rec['content'] ?? ($rec['content_unparsed'] ?? null)); // v54.27: texto, nunca JSON ?>
          <div class="cr-entry__body" data-testid="cr-entry-body">
            <?php $labelled = $view['structured'] || $view['sections']; ?>
            <?php if ($view['text'] !== ''): ?>
              <?php if ($labelled): ?><p class="cr-entry__label">Anotações</p><?php endif; ?>
              <p><?= nl2br(htmlspecialchars($view['text'])) ?></p>
            <?php elseif (!$view['sections'] && $view['weight_kg'] === null): ?>
              <p class="cr-caption">Registro sem texto.</p>
            <?php endif; ?>
            <?php foreach ($view['sections'] as $sec): ?>
              <p class="cr-entry__label"><?= htmlspecialchars($sec['label']) ?></p>
              <p><?= nl2br(htmlspecialchars($sec['value'])) ?></p>
            <?php endforeach; ?>
            <?php if ($view['weight_kg'] !== null): ?>
              <div class="cr-entry__measures">
                <strong>Peso aferido:</strong> <?= htmlspecialchars(LabelMap::num($view['weight_kg'], 1)) ?> kg
                <?php if ($view['waist_cm'] !== null): ?> | <strong>Cintura:</strong> <?= htmlspecialchars(LabelMap::num($view['waist_cm'], 1)) ?> cm<?php endif; ?>
              </div>
            <?php endif; ?>
            <?php if ($view['via_teleconsultation']): ?>
              <p class="cr-entry__origin" data-testid="cr-entry-tele">
                <span class="badge badge-info">Registrada durante a teleconsulta</span>
                <?php if ($view['appointment_id'] !== null && isset($consultLinkIds[$view['appointment_id']])): ?>
                  <a href="/consulta/<?= (int)$view['appointment_id'] ?>" class="cr-entry__origin-link">Ver consulta</a>
                <?php endif; ?>
              </p>
            <?php endif; ?>
          </div>
          <?php if ($canSeeAudit): ?>
            <details class="cr-tech">
              <summary>Ver código de conferência deste registro</summary>
              <div class="cr-tech__code">
                <div><strong>Código de integridade (SHA-256):</strong> <?= htmlspecialchars($rec['record_hash'] ?? '') ?></div>
                <div><strong>Elo anterior:</strong> <?= (!empty($rec['previous_hash']) && $rec['previous_hash'] !== ClinicalRecordService::GENESIS_HASH) ? htmlspecialchars($rec['previous_hash']) : 'Registro inicial' ?></div>
              </div>
            </details>
          <?php endif; ?>
        </li>
      <?php endforeach; ?>
    </ol>
  <?php endif; ?>
</section>

<div id="modal-new-record" class="cr-modal" data-dialog="modal">
  <div class="cn-dialog__panel cr-modal__panel">
    <h3>Adicionar entrada ao prontuário</h3>
    <p class="cr-modal__lead">O registro fica gravado com data, hora e autor, e encadeado por código de integridade: alterações posteriores ficam detectáveis. Não há assinatura digital (ICP-Brasil).</p>
    <form method="POST" action="/pacientes/<?= $pid ?>/prontuario">
      <input type="hidden" name="_csrf_token" value="<?= htmlspecialchars($csrfToken) ?>">
      <div class="cr-field">
        <label for="dc4-title" class="form-label">Título da entrada <span class="cr-required" aria-hidden="true">*</span></label>
        <input id="dc4-title" class="form-control" type="text" name="title" required maxlength="255" placeholder="Ex.: evolução de retorno, intercorrência, orientação">
      </div>
      <div class="cr-field">
        <label for="dc5-category" class="form-label">Categoria</label>
        <select id="dc5-category" class="form-control" name="category">
          <option value="evolucao">Evolução clínica / atendimento</option>
          <option value="observacao">Observação clínica geral</option>
          <option value="retorno">Consulta de retorno</option>
          <option value="intercorrencia">Intercorrência clínica</option>
        </select>
      </div>
      <div class="cr-field">
        <label for="dc6-content_text" class="form-label">Conteúdo e conduta nutricional (máx. 10.000 caracteres) <span class="cr-required" aria-hidden="true">*</span></label>
        <textarea id="dc6-content_text" class="form-control" name="content_text" required rows="6" maxlength="10000" placeholder="Achados, relato do paciente, metas nutricionais e conduta"></textarea>
      </div>
      <div class="cr-modal__actions">
        <button type="button" data-dialog-close class="btn btn-outline" data-hide="modal-new-record">Cancelar</button>
        <button type="submit" class="btn btn-primary">Gravar no prontuário</button>
      </div>
    </form>
  </div>
</div>

<div id="modal-revise-record" class="cr-modal" data-dialog="modal">
  <div class="cn-dialog__panel cr-modal__panel">
    <h3>Retificação formal de registro clínico</h3>
    <p class="cr-modal__lead">O registro anterior será marcado como superado e continua preservado no histórico. Uma nova versão é gravada com a justificativa obrigatória.</p>
    <form id="form-revise-record" method="POST" action="">
      <input type="hidden" name="_csrf_token" value="<?= htmlspecialchars($csrfToken) ?>">
      <div class="cr-field">
        <label for="dc7-revision_reason" class="form-label">Justificativa formal da retificação (mín. 10 caracteres) <span class="cr-required" aria-hidden="true">*</span></label>
        <input id="dc7-revision_reason" class="form-control" type="text" name="revision_reason" minlength="10" required placeholder="Ex.: correção de digitação no percentual de gordura">
      </div>
      <div class="cr-field">
        <label for="dc8-revised_text" class="form-label" id="dc8-label">Novo texto corrigido <span class="cr-required" aria-hidden="true">*</span></label>
        <textarea id="dc8-revised_text" class="form-control" name="revised_text" required rows="6" maxlength="10000" placeholder="Texto retificado e atualizado para este atendimento"></textarea>
      </div>
      <div class="cr-field" id="dc11-goals-field" hidden>
        <label for="dc11-revised_goals" class="form-label">Metas até o retorno</label>
        <textarea id="dc11-revised_goals" class="form-control" name="revised_goals" rows="3" maxlength="10000" disabled placeholder="Deixe em branco se não houver metas"></textarea>
      </div>
      <div class="cr-modal__actions">
        <button type="button" data-dialog-close class="btn btn-outline" data-hide="modal-revise-record">Cancelar</button>
        <button type="submit" class="btn btn-primary cr-btn--warning">Publicar retificação versionada</button>
      </div>
    </form>
  </div>
</div>
</div>
<script nonce="<?= \ClyoNutri\Core\Http\SecurityHeaders::nonce() ?>">
  function openRevisionModal(recId, title) {
    var form = document.getElementById('form-revise-record');
    form.action = '/pacientes/<?= $pid ?>/prontuario/' + recId + '/retificar';
    // v54.27: o formulário já vem com o texto vigente; registro da teleconsulta mostra também as metas.
    var src = document.querySelector('[data-rev-id="' + parseInt(recId, 10) + '"]');
    var notes = document.getElementById('dc8-revised_text');
    var goalsField = document.getElementById('dc11-goals-field');
    var goals = document.getElementById('dc11-revised_goals');
    var label = document.getElementById('dc8-label');
    var structured = !!(src && src.getAttribute('data-rev-structured') === '1');
    notes.value = src ? (src.getAttribute('data-rev-notes') || '') : '';
    goals.value = structured ? (src.getAttribute('data-rev-goals') || '') : '';
    goals.disabled = !structured;
    goalsField.hidden = !structured;
    label.firstChild.nodeValue = structured ? 'Anotações corrigidas ' : 'Novo texto corrigido ';
    document.getElementById('modal-revise-record').style.display = 'flex';
  }
</script>
