<?php
/** Sala de consulta (execução e conclusão do atendimento).
 *  v54.25 (NI2-220 I9): classes do DS + clinical-record.css, sem estilo inline e sem cor fixa.
 *  NI2-220 (B1/B3/I8): mensagens próprias para quem não tem CRN, medidas fora da faixa, regra de negócio
 *  e falha técnica (só o código de suporte aparece; o detalhe técnico fica no log).
 *  Variáveis: $app, $id, $timeline, $canStart, $isEarly, $inProgress, $draft, $draftScope,
 *  $draftRecoveredFromPost, $draftSavedLocal, $profile, $p, $csrfToken, $pendingReview (NI2-706). */
use ClyoNutri\Core\Database\Connection;
use ClyoNutri\Core\I18n\LabelMap;
use ClyoNutri\Domain\Appointment\AppointmentService;
use ClyoNutri\Domain\Clinical\ClinicalAuthor;

$pid = (int)$app['patient_id'];
$err = isset($_GET['error']) && is_string($_GET['error']) ? $_GET['error'] : '';
$consultFieldErrors = [];
if ($err === 'medidas_invalidas' && isset($_SESSION['consultation_field_errors'][$id]) && is_array($_SESSION['consultation_field_errors'][$id])) {
    $consultFieldErrors = $_SESSION['consultation_field_errors'][$id];
}
$ruleMsg = ($err === 'regra' && isset($_SESSION['consultation_error_msg'][$id])) ? (string)$_SESSION['consultation_error_msg'][$id] : '';
unset($_SESSION['consultation_field_errors'][$id], $_SESSION['consultation_error_msg'][$id]);
$errorRef = isset($_GET['ref']) && is_string($_GET['ref']) && preg_match('/^[a-f0-9]{12}$/D', $_GET['ref']) ? $_GET['ref'] : null;
$errorText = match ($err) {
    'resumo_obrigatorio' => 'Informe o resumo da evolução clínica antes de concluir a consulta. Os demais campos preenchidos foram preservados.',
    'sem_crn' => ClinicalAuthor::SEM_CRN_MSG . ' Suas anotações foram preservadas como rascunho.',
    'medidas_invalidas' => 'Confira as medidas destacadas abaixo. Nada foi gravado no prontuário; suas anotações foram preservadas.',
    'revisao_pendente' => 'Há etapas com revisão pendente neste atendimento. Revise-as ou confirme explicitamente a conclusão mesmo assim (a decisão fica registrada na auditoria). Suas anotações foram preservadas.',
    'regra' => ($ruleMsg !== '' ? $ruleMsg : 'A consulta não pode ser concluída no estado atual.') . ' Suas anotações foram preservadas.',
    default => 'Ocorreu uma falha temporária ao concluir o atendimento. Suas anotações foram preservadas abaixo; revise e tente novamente.',
};
$fieldAttr = static function (string $f) use ($consultFieldErrors): string {
    return isset($consultFieldErrors[$f]) ? ' aria-invalid="true" aria-describedby="err_' . $f . '"' : '';
};
$fieldMsg = static function (string $f) use ($consultFieldErrors): string {
    return isset($consultFieldErrors[$f]) ? '<span class="cr-field-error" id="err_' . $f . '">' . htmlspecialchars((string)$consultFieldErrors[$f]) . '</span>' : '';
};
?>
<link rel="stylesheet" href="/assets/css/clinical-record.css?v=v54-27">
<div class="cr" data-testid="consulta-page">
<div class="cr-head">
  <div>
    <p class="cr-head__back"><a href="/agenda">← Voltar para a Agenda</a></p>
    <h1>Atendimento clínico — <?= htmlspecialchars($app['patient_name']) ?></h1>
    <p class="cr-head__lead">Tipo: <?= htmlspecialchars(LabelMap::appointmentType($app['type'] ?? null)) ?> • Duração prevista: <?= (int)$app['duration_minutes'] ?> min</p>
    <dl class="cn-consult-times cr-times" data-testid="consult-times">
      <div><dt>Agendada para</dt><dd data-time="scheduled"><strong><?= date('d/m/Y H:i', strtotime($app['scheduled_at'])) ?></strong></dd></div>
      <div><dt>Iniciada em</dt><dd data-time="started"><strong><?= $timeline['started_at'] ? date('d/m/Y H:i', strtotime($timeline['started_at'])) : 'Não iniciada' ?></strong></dd></div>
      <div><dt>Concluída em</dt><dd data-time="completed"><strong><?= $timeline['completed_at'] ? date('d/m/Y H:i', strtotime($timeline['completed_at'])) : 'Não concluída' ?></strong></dd></div>
    </dl>
  </div>
  <div class="cr-actions">
    <span class="badge badge-warning cr-badge--status"><?= htmlspecialchars(LabelMap::appointmentStatus($app['status'])) ?></span>
    <?php if (\ClyoNutri\Domain\Telehealth\TeleconsultationService::isOnlineType($app['type'] ?? null)): /* NI2-221 F19: só consulta online */ ?>
      <a href="/teleconsulta/sessao-agenda/<?= (int)$app['id'] ?>" class="btn btn-primary"><span>Consultório online</span></a>
    <?php endif; ?>
    <a href="/pacientes/<?= $pid ?>" class="cr-profile-link">Ver perfil do paciente</a>
  </div>
</div>
<?= \ClyoNutri\Application\View\PatientHeader::render(Connection::getInstance(), $pid, 'atendimento', null, ['state'=>'continue','appointment'=>$app], null, null, false, 'context') // NI2-623/629 ?>

<details class="ux52-exam-context"><summary>Exames desta consulta</summary><?php $examPatientId=$pid;$examAppointmentId=(int)$id;require __DIR__.'/../patients/partials/exam_context.php'; ?></details>
<div class="consultation-room-layout">
  <div>
    <section class="card" aria-labelledby="cr-evol-title">
      <div class="cr-card__head">
        <div>
          <h3 id="cr-evol-title">Evolução e conduta</h3>
          <p class="cr-card__lead">Revise a evolução antes de concluir. O registro será incluído no prontuário com data, hora e autoria profissional.</p>
        </div>
      </div>

      <?php if ($canStart): ?>
      <section class="cn-start-consult cr-start<?= $isEarly ? ' cr-start--early' : '' ?>" data-testid="consult-start-panel">
        <h4>Esta consulta ainda não foi iniciada</h4>
        <p>Agendada para <strong><?= date('d/m/Y \à\s H:i', strtotime($app['scheduled_at'])) ?></strong>. Ao iniciar, o horário real de início é registrado na trilha de auditoria.</p>
        <form method="POST" action="/agenda/<?= (int)$app['id'] ?>/status" data-no-dirty>
          <input type="hidden" name="_csrf_token" value="<?= htmlspecialchars($csrfToken) ?>">
          <input type="hidden" name="status" value="in_progress">
          <?php if ($isEarly): ?>
            <p class="op-message op-message--warning" role="alert" data-testid="early-start-warning">Início antecipado: faltam <?= htmlspecialchars(AppointmentService::humanUntil((string)$app['scheduled_at'])) ?> para o horário marcado.</p>
            <label class="cr-check"><input type="checkbox" name="confirm_early" value="1" required> Confirmo que quero iniciar antes do horário agendado</label>
          <?php endif; ?>
          <div class="cr-actions">
            <button type="submit" class="btn btn-primary" data-testid="consult-start">Iniciar atendimento agora</button>
            <a href="/agenda" class="btn btn-secondary">Voltar à agenda</a>
          </div>
        </form>
      </section>
      <?php elseif (!$inProgress): ?>
      <p class="cr-closed" data-testid="consult-closed" data-draft-scope="<?= htmlspecialchars($draftScope) ?>">Consulta com status <strong><?= htmlspecialchars(LabelMap::appointmentStatus($app['status'])) ?></strong>; não há anotações em andamento.</p>
      <script src="/assets/js/consulta-rascunho.js?v=v54-18" defer></script>
      <?php else: ?>
      <?php if ($err !== ''): ?>
        <div class="op-message op-message--error" role="alert" data-testid="consult-submit-error" data-error="<?= htmlspecialchars($err) ?>">
          <strong>Não foi possível concluir a consulta:</strong> <?= htmlspecialchars($errorText) ?>
          <?php if ($err === 'falha' && $errorRef): ?><span class="cr-caption"> Código para o suporte: <?= htmlspecialchars($errorRef) ?>.</span><?php endif; ?>
        </div>
      <?php endif; ?>
      <form action="/consulta/<?= (int)$app['id'] ?>/concluir" method="POST" data-no-dirty data-consult-draft
            data-draft-url="/consulta/<?= (int)$app['id'] ?>/rascunho" data-draft-scope="<?= htmlspecialchars($draftScope) ?>"<?= $draftRecoveredFromPost ? ' data-draft-recovered="1"' : '' ?> data-draft-version="<?= (int)$draft['version'] ?>" data-csrf="<?= htmlspecialchars($csrfToken) ?>">
        <p id="consulta-draft-status" class="op-message op-message--info op-state cr-draft-status" role="status" aria-live="polite" data-testid="draft-status"><?= $draftRecoveredFromPost ? 'Preenchimento recuperado após tentativa de conclusão. Suas anotações estão preservadas.' : ($draftSavedLocal ? 'Salvo como rascunho (confirmado em ' . htmlspecialchars($draftSavedLocal) . '). Continue de onde parou.' : 'As anotações são salvas automaticamente como rascunho enquanto você digita.') ?></p>
        <input type="hidden" name="_csrf_token" value="<?= htmlspecialchars($csrfToken) ?>">

        <div class="form-group cr-field">
          <label for="evolution_summary" class="form-label">Anotações Clínicas / Resumo da Evolução <span class="cr-required" aria-hidden="true">*</span></label>
          <textarea class="form-control" id="evolution_summary" name="evolution_summary" rows="8" required placeholder="Tópicos abordados: anamnese alimentar, queixas gastrointestinais, rotina, metas acordadas e ajustes na prescrição"><?= htmlspecialchars((string)($draft['evolution_summary'] ?? '')) ?></textarea>
        </div>

        <div class="cr-anthro">
          <h4>Atualização antropométrica desta consulta (opcional)</h4>
          <div class="cn-form-grid">
            <div>
              <label for="dc9-weight_kg" class="cr-label">Peso Atual (kg)</label>
              <input class="form-control" id="dc9-weight_kg" type="number" inputmode="decimal" step="0.1" min="<?= AppointmentService::CONSULT_WEIGHT_RANGE[0] ?>" max="<?= AppointmentService::CONSULT_WEIGHT_RANGE[1] ?>" name="weight_kg" value="<?= htmlspecialchars((string)($draft['weight_kg'] ?? '')) ?>" placeholder="Ex.: 68,5"<?= $fieldAttr('weight_kg') ?>>
              <?= $fieldMsg('weight_kg') ?>
            </div>
            <div>
              <label for="dc10-waist_cm" class="cr-label">Circunf. Cintura (cm)</label>
              <input class="form-control" id="dc10-waist_cm" type="number" inputmode="decimal" step="0.5" min="<?= AppointmentService::CONSULT_WAIST_RANGE[0] ?>" max="<?= AppointmentService::CONSULT_WAIST_RANGE[1] ?>" name="waist_cm" value="<?= htmlspecialchars((string)($draft['waist_cm'] ?? '')) ?>" placeholder="Ex.: 78,0"<?= $fieldAttr('waist_cm') ?>>
              <?= $fieldMsg('waist_cm') ?>
            </div>
          </div>
          <span class="cr-help">Peso entre <?= AppointmentService::CONSULT_WEIGHT_RANGE[0] ?> e <?= AppointmentService::CONSULT_WEIGHT_RANGE[1] ?> kg; cintura entre <?= AppointmentService::CONSULT_WAIST_RANGE[0] ?> e <?= AppointmentService::CONSULT_WAIST_RANGE[1] ?> cm.</span>
        </div>

        <?php $pendingReview = $pendingReview ?? []; if ($pendingReview !== []): ?>
        <div id="cr-pending-review" class="op-message op-message--warning" role="group" aria-labelledby="cr-pending-title" data-testid="consult-pending-review">
          <strong id="cr-pending-title">Etapas com revisão pendente neste atendimento</strong>
          <ul class="cr-pending-list">
            <?php foreach ($pendingReview as $pr): ?>
            <li data-step="<?= htmlspecialchars($pr['key']) ?>"><a href="<?= htmlspecialchars($pr['url']) ?>"><?= htmlspecialchars($pr['label']) ?></a> — <?= htmlspecialchars($pr['state_label']) ?></li>
            <?php endforeach; ?>
          </ul>
          <input type="hidden" name="pending_review_keys" value="<?= htmlspecialchars(implode(',', array_column($pendingReview, 'key'))) ?>">
          <label class="cr-check"><input type="checkbox" name="confirm_pending_review" value="1" data-testid="confirm-pending-review" required> Estou ciente das etapas pendentes e quero concluir a consulta mesmo assim (registrado na auditoria)</label>
        </div>
        <?php endif; ?>

        <div class="cr-form-foot">
          <a href="/agenda?date=<?= date('Y-m-d', strtotime($app['scheduled_at'])) ?>" class="btn btn-secondary" data-draft-pause>Pausar e voltar à agenda</a>
          <button type="submit" class="btn btn-primary">
            <svg class="cr-icon" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" aria-hidden="true"><polyline points="20 6 9 17 4 12"/></svg>
            Concluir consulta e registrar no prontuário
          </button>
        </div>
      </form>
      <script src="/assets/js/consulta-rascunho.js?v=v54-18" defer></script>
      <?php endif; ?>
    </section>

    <?php if (!empty($app['history'])): ?>
      <section class="card cr-card" aria-labelledby="cr-trail-title">
        <div class="cr-card__head"><h4 id="cr-trail-title">Trilha de auditoria desta consulta</h4></div>
        <div class="cr-trail">
          <?php foreach ($app['history'] as $h): ?>
            <div class="cr-trail__row">
              <div>
                <strong><?= htmlspecialchars(!empty($h['from_status']) ? LabelMap::appointmentStatus($h['from_status']) : 'Criação') ?></strong> →
                <?= LabelMap::appointmentStatusBadge($h['to_status'] ?? '') ?>
                <?php if (!empty($h['notes'])): ?><span class="cr-muted">— <?= htmlspecialchars($h['notes']) ?></span><?php endif; ?>
              </div>
              <span class="cr-muted"><?= date('H:i:s d/m', strtotime($h['created_at'])) ?></span>
            </div>
          <?php endforeach; ?>
        </div>
      </section>
    <?php endif; ?>
  </div>

  <div class="cr-stack">
    <section class="card cr-context" aria-labelledby="cr-ctx-title">
      <div class="cr-card__head"><h3 id="cr-ctx-title">Contexto do Paciente</h3></div>
      <p><strong>Telefone:</strong> <?= htmlspecialchars($p['phone'] ?? '—') ?></p>
      <p><strong>E-mail:</strong> <?= htmlspecialchars($p['email'] ?? '—') ?></p>
      <p><strong>Nascimento:</strong> <?= !empty($p['birth_date']) ? date('d/m/Y', strtotime($p['birth_date'])) : '—' ?></p>
      <p><strong>Sexo registrado para referências clínicas:</strong> <?= htmlspecialchars(LabelMap::patientGender($p['gender'] ?? null)) ?></p>
      <?php if (!empty($app['notes'])): ?>
        <div class="cr-note">
          <strong>Observações do Agendamento:</strong>
          <p><?= nl2br(htmlspecialchars($app['notes'])) ?></p>
        </div>
      <?php endif; ?>
    </section>

    <section class="card" aria-labelledby="cr-measures-title">
      <div class="cr-card__head">
        <h3 id="cr-measures-title">Últimas medidas</h3>
        <a href="/pacientes/<?= $pid ?>/antropometria?atendimento=<?= (int)$id ?>" class="btn btn-secondary cr-btn--compact">Avaliações</a>
      </div>
      <?php if (empty($profile['assessments'])): ?>
        <p class="cr-muted">Nenhuma avaliação antropométrica registrada ainda.</p>
      <?php else: ?>
        <?php $lastAssessment = $profile['assessments'][0]; ?>
        <div class="cr-metrics">
          <div class="cr-metric"><span>Peso</span><strong><?= htmlspecialchars(LabelMap::num($lastAssessment['weight_kg'] ?? null, 1, 'kg')) ?></strong></div>
          <div class="cr-metric"><span>Altura</span><strong><?= htmlspecialchars(LabelMap::num($lastAssessment['height_cm'] ?? null, 1, 'cm')) ?></strong></div>
          <div class="cr-metric"><span>IMC</span><strong><?= htmlspecialchars(LabelMap::num($lastAssessment['bmi'] ?? null, 2)) ?></strong></div>
        </div>
        <small class="cr-caption">Registrado em: <?= date('d/m/Y', strtotime($lastAssessment['assessed_at'])) ?></small>
      <?php endif; ?>
    </section>

    <section class="card" aria-labelledby="cr-plan-title">
      <div class="cr-card__head">
        <h3 id="cr-plan-title">Prescrição alimentar</h3>
        <a href="/planos" target="_blank" rel="noopener" class="btn btn-secondary cr-btn--compact">Todos os Planos</a>
      </div>
      <?php if (empty($profile['meal_plans'])): ?>
        <p class="cr-muted">Nenhum plano prescrito ainda. Inicie a prescrição desta consulta.</p>
        <a href="/pacientes/<?= $pid ?>/plano?atendimento=<?= (int)$id ?>" class="btn btn-primary cr-btn--compact">+ Prescrever plano</a>
      <?php else: ?>
        <?php $plan = $profile['meal_plans'][0]; ?>
        <div class="cr-inline">
          <strong><?= htmlspecialchars($plan['title']) ?> (v<?= (int)$plan['version'] ?>)</strong>
          <?= LabelMap::mealPlanStatusBadge($plan['status'] ?? '') ?>
        </div>
        <a href="/planos/<?= (int)$plan['id'] ?>?atendimento=<?= (int)$id ?>" class="btn btn-outline cr-btn--compact">Abrir editor da prescrição</a>
      <?php endif; ?>
    </section>

    <section class="card" aria-labelledby="cr-an-title">
      <div class="cr-card__head">
        <h3 id="cr-an-title">Anamnese nutricional</h3>
        <a href="/pacientes/<?= $pid ?>/anamnese?atendimento=<?= (int)$id ?>" class="btn btn-secondary cr-btn--compact"><?= !empty($clinicalSummary['draft_in_progress']) || !empty($profile['anamneses'][0]['is_draft']) ? 'Continuar anamnese' : 'Abrir anamnese' ?></a>
      </div>
      <p class="cr-muted">Consulte ou atualize o histórico clínico, alergias e rotina do paciente.</p>

    </section>
  </div>

</div>
</div>
