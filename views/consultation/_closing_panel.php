<?php
/**
 * NI2-849 (UX-005): Painel unificado de Fechamento da Consulta.
 * Exibido ao concluir a consulta (no topo do perfil 360 ou na própria sala /consulta/{id})
 * com confirmação do prontuário, agendamento de retorno em +30 dias (1 clique),
 * entrega do plano (PDF / app do paciente) e atalho para o próximo paciente do dia.
 */
declare(strict_types=1);

use ClyoNutri\Core\Auth\TenantContext;
use ClyoNutri\Core\Database\Connection;
use ClyoNutri\Core\Security\Csrf;
use ClyoNutri\Domain\Appointment\AppointmentService;

$dbClosing = Connection::getInstance();
$cidClosing = (int)TenantContext::getClinicId();
$eClose = static fn($v): string => htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');

$closingApp = $closingApp ?? ($app ?? null);
$closingPid = (int)($pid ?? ($p['id'] ?? ($closingApp['patient_id'] ?? 0)));

if (!$closingApp && $closingPid > 0) {
    $reqConsultaId = isset($_GET['consulta']) && ctype_digit((string)$_GET['consulta']) ? (int)$_GET['consulta'] : 0;
    if ($reqConsultaId > 0) {
        $stApp = $dbClosing->prepare('SELECT * FROM appointments WHERE id = ? AND clinic_id = ? AND patient_id = ? LIMIT 1');
        $stApp->execute([$reqConsultaId, $cidClosing, $closingPid]);
        $closingApp = $stApp->fetch(\PDO::FETCH_ASSOC) ?: null;
    }
    if (!$closingApp) {
        $stApp = $dbClosing->prepare("SELECT * FROM appointments WHERE clinic_id = ? AND patient_id = ? AND status IN ('completed', 'in_progress') ORDER BY id DESC LIMIT 1");
        $stApp->execute([$cidClosing, $closingPid]);
        $closingApp = $stApp->fetch(\PDO::FETCH_ASSOC) ?: null;
    }
}

if (!$closingApp || $closingPid <= 0) {
    return;
}

$closingAppId = (int)$closingApp['id'];
$closingProfId = (int)($closingApp['professional_id'] ?? 1);
$closingDuration = max(15, min(240, (int)($closingApp['duration_minutes'] ?? 60)));
$closingCsrf = $csrfToken ?? Csrf::token();
$closingReturnTo = $closingReturnTo ?? ((string)parse_url($_SERVER['REQUEST_URI'] ?? '/consulta/' . $closingAppId, PHP_URL_PATH));

$appSvcClosing = new AppointmentService();
$followUpSuggest = $appSvcClosing->suggestFollowUpSlot(
    $closingProfId,
    (string)($closingApp['scheduled_at'] ?? date('Y-m-d H:i:s')),
    $closingDuration,
    30
);

// Verifica se já existe retorno futuro agendado para este paciente
$stFutureReturn = $dbClosing->prepare("SELECT id, scheduled_at, type FROM appointments WHERE clinic_id = ? AND patient_id = ? AND status IN ('scheduled', 'confirmed') AND scheduled_at > ? ORDER BY scheduled_at ASC LIMIT 1");
$stFutureReturn->execute([$cidClosing, $closingPid, (string)($closingApp['scheduled_at'] ?? date('Y-m-d H:i:s'))]);
$existingFollowUp = $stFutureReturn->fetch(\PDO::FETCH_ASSOC) ?: null;

$closingFeedback = $_SESSION['closing_followup_feedback'][$closingAppId] ?? null;
$closingError = $_SESSION['closing_followup_error'][$closingAppId] ?? null;
$closingSubmittedAt = $_SESSION['closing_followup_input'][$closingAppId] ?? null;
unset(
    $_SESSION['closing_followup_feedback'][$closingAppId],
    $_SESSION['closing_followup_error'][$closingAppId],
    $_SESSION['closing_followup_input'][$closingAppId]
);

$inputScheduledAt = is_string($closingSubmittedAt) && $closingSubmittedAt !== ''
    ? $closingSubmittedAt
    : $followUpSuggest['scheduled_at'];

// Busca plano alimentar mais recente do paciente
$stPlanClose = $dbClosing->prepare("SELECT id, title, status, version, daily_energy_kcal FROM meal_plans WHERE clinic_id = ? AND patient_id = ? ORDER BY CASE WHEN status = 'published' THEN 0 WHEN status = 'active' THEN 1 ELSE 2 END, id DESC LIMIT 1");
$stPlanClose->execute([$cidClosing, $closingPid]);
$closingPlan = $stPlanClose->fetch(\PDO::FETCH_ASSOC) ?: null;

// Verifica se o paciente possui conta ativa vinculada no portal/app
$patientHasAppAccount = !empty($p['password_hash']) || !empty($p['access_pin']);
if (!$patientHasAppAccount && $closingPid > 0) {
    try {
        $stPatUser = $dbClosing->prepare("SELECT password_hash, access_pin FROM patients WHERE id = ? AND clinic_id = ? LIMIT 1");
        $stPatUser->execute([$closingPid, $cidClosing]);
        $patRow = $stPatUser->fetch(\PDO::FETCH_ASSOC);
        $patientHasAppAccount = !empty($patRow['password_hash']) || !empty($patRow['access_pin']);
    } catch (\Throwable) {
        $patientHasAppAccount = false;
    }
}

// Próximo paciente do dia na agenda
$todayPrefix = substr((string)($closingApp['scheduled_at'] ?? date('Y-m-d')), 0, 10);
$stNextToday = $dbClosing->prepare("SELECT a.id, a.scheduled_at, p.name AS patient_name FROM appointments a JOIN patients p ON p.id = a.patient_id WHERE a.clinic_id = ? AND a.id <> ? AND a.scheduled_at >= ? AND a.scheduled_at <= ? AND a.status IN ('scheduled', 'confirmed', 'in_progress') ORDER BY a.scheduled_at ASC LIMIT 1");
$stNextToday->execute([$cidClosing, $closingAppId, $todayPrefix . ' 00:00:00', $todayPrefix . ' 23:59:59']);
$nextTodayApp = $stNextToday->fetch(\PDO::FETCH_ASSOC) ?: null;
?>
<section id="consultation-closing-panel" class="card u-p-5 u-mb-5 u-border u-bc-botanical-sage-200 u-bg-botanical-cream-50" data-testid="consultation-closing-panel" aria-labelledby="closing-panel-title">
  <div class="u-flex u-justify-between u-items-start u-wrap u-gap-3 u-mb-3">
    <div>
      <div class="u-flex u-items-center u-gap-2 u-mb-1">
        <span class="badge ds-pill ds-pill--success">Realizada</span>
        <span class="u-text-compact u-color-neutral-gray-600">Consulta #<?= $closingAppId ?></span>
      </div>
      <h2 id="closing-panel-title" class="u-text-h3 u-font-bold u-color-botanical-forest-900 u-m-0">Fechamento da consulta — próximos passos</h2>
      <p class="u-text-compact u-color-neutral-gray-700 u-mt-1 u-mb-0">Evolução clínica registrada no prontuário. Agende o retorno e entregue o plano ao paciente sem sair desta tela.</p>
    </div>
    <div class="u-flex u-gap-2 u-items-center u-wrap">
      <?php if ($nextTodayApp): ?>
        <a href="/consulta/<?= (int)$nextTodayApp['id'] ?>" class="btn btn-outline btn-sm" data-testid="closing-next-patient">
          Ir para o próximo paciente do dia (<?= $eClose($nextTodayApp['patient_name']) ?> · <?= $eClose(date('H:i', strtotime((string)$nextTodayApp['scheduled_at']))) ?>)
        </a>
      <?php else: ?>
        <a href="/agenda?date=<?= $eClose($todayPrefix) ?>" class="btn btn-outline btn-sm" data-testid="closing-next-patient">
          Ir para o próximo paciente do dia na Agenda
        </a>
      <?php endif; ?>
    </div>
  </div>

  <?php if ($closingFeedback && !$existingFollowUp): /* R-003: com retorno marcado, a confirmação com link já aparece no card 1 */ ?>
    <div class="op-message op-message--success u-mb-3" role="status" data-testid="closing-followup-feedback">
      <?= $eClose($closingFeedback) ?>
    </div>
  <?php endif; ?>

  <?php if ($closingError): ?>
    <div class="op-message op-message--error u-mb-3" role="alert" data-testid="closing-followup-error">
      <?= $eClose($closingError) ?>
    </div>
  <?php endif; ?>

  <div class="u-grid u-cols-auto-md u-gap-4">
    <!-- 1. Agendar Retorno em 1 clique -->
    <div class="card u-p-4 u-bg-neutral-white u-border">
      <h3 class="u-text-card-title u-font-bold u-color-botanical-forest-900 u-mt-0 u-mb-2">1. Agendar retorno</h3>
      <?php if ($existingFollowUp): ?>
        <p class="op-message op-message--success u-text-compact u-mb-2" data-testid="closing-followup-scheduled">
          <strong>Retorno agendado:</strong> <?= $eClose(date('d/m/Y \à\s H:i', strtotime((string)$existingFollowUp['scheduled_at']))) ?>.
          <a href="/agenda?view=day&amp;date=<?= $eClose(substr((string)$existingFollowUp['scheduled_at'], 0, 10)) ?>" class="u-ml-1 u-link">Ver na agenda</a>
        </p>
      <?php endif; ?>

      <?php /* Reteste UX v54.62 (R-003): com retorno já marcado, o painel só confirma. A sugestão recalculada colidia
               com o próprio retorno e dizia "caiu indisponível" logo após o sucesso. Outro horário fica em "Marcar outro retorno". */ ?>
      <?php if ($existingFollowUp): ?>
      <details class="u-mt-2" data-testid="closing-followup-more">
        <summary class="u-text-compact">Marcar outro retorno</summary>
      <?php endif; ?>
      <?php if (!$existingFollowUp && $followUpSuggest['adjusted'] && $followUpSuggest['reason']): ?>
        <p class="op-message op-message--info u-text-compact u-mb-2" role="status" data-testid="closing-followup-adjusted">
          O retorno em +30 dias (<?= $eClose(date('d/m/Y H:i', strtotime(str_replace('T', ' ', $followUpSuggest['requested_at'])))) ?>) caiu indisponível (<?= $eClose($followUpSuggest['reason']) ?>). Sugerimos o próximo horário livre: <strong><?= $eClose(date('d/m/Y \à\s H:i', strtotime(str_replace('T', ' ', $followUpSuggest['scheduled_at'])))) ?></strong>.
        </p>
      <?php elseif (!$existingFollowUp): ?>
        <p class="u-text-compact u-color-neutral-gray-600 u-mt-0 u-mb-2">Sugerido para <strong>+30 dias</strong> no mesmo horário. Ajuste se desejar e confirme em 1 clique.</p>
      <?php endif; ?>

      <form method="POST" action="/consulta/<?= $closingAppId ?>/retorno" class="u-flex u-gap-2 u-items-end u-wrap" data-testid="closing-followup-form" data-no-dirty>
        <input type="hidden" name="_csrf_token" value="<?= $eClose($closingCsrf) ?>">
        <input type="hidden" name="return_to" value="<?= $eClose($closingReturnTo) ?>">
        <div class="u-flex-1 u-minw-md">
          <label for="closing-followup-at" class="u-block u-font-semibold u-text-compact u-mb-1">Data e hora do retorno</label>
          <input id="closing-followup-at" type="datetime-local" name="scheduled_at" required value="<?= $eClose($inputScheduledAt) ?>" class="form-control" data-testid="closing-followup-input">
        </div>
        <button type="submit" class="btn btn-primary" data-testid="closing-schedule-followup-btn">Agendar retorno</button>
      </form>

      <?php if (!empty($followUpSuggest['alternatives'])): ?>
        <div class="u-mt-2 u-text-caption u-color-neutral-gray-600" data-testid="closing-followup-alternatives">
          <span>Horários livres próximos:</span>
          <?php foreach ($followUpSuggest['alternatives'] as $altIso): ?>
            <button type="button" class="btn btn-sm btn-outline u-py-0 u-px-2 u-text-caption u-ml-1" data-fill-followup="<?= $eClose($altIso) ?>">
              <?= $eClose(date('d/m H:i', strtotime(str_replace('T', ' ', $altIso)))) ?>
            </button>
          <?php endforeach; ?>
        </div>
      <?php endif; ?>
      <?php if ($existingFollowUp): ?>
      </details>
      <?php endif; ?>
    </div>

    <!-- 2. Entregar plano ao paciente -->
    <div class="card u-p-4 u-bg-neutral-white u-border" data-testid="closing-plan-delivery">
      <h3 class="u-text-card-title u-font-bold u-color-botanical-forest-900 u-mt-0 u-mb-2">2. Entregar plano ao paciente</h3>
      <?php if ($closingPlan && in_array((string)$closingPlan['status'], ['published', 'active'], true)): ?>
        <p class="u-text-compact u-color-neutral-gray-700 u-mt-0 u-mb-3">
          Plano publicado: <strong><?= $eClose($closingPlan['title']) ?> (v<?= (int)$closingPlan['version'] ?>)</strong>
          <?php if ((float)($closingPlan['daily_energy_kcal'] ?? 0) > 0): ?> · <?= number_format((float)$closingPlan['daily_energy_kcal'], 0, ',', '.') ?> kcal<?php endif; ?>
        </p>
        <div class="u-flex u-gap-2 u-items-center u-wrap u-mb-2">
          <a href="/planos/<?= (int)$closingPlan['id'] ?>/imprimir" target="_blank" rel="noopener" class="btn btn-primary btn-sm" data-testid="closing-plan-pdf">
            Baixar PDF / Imprimir
          </a>
          <a href="/planos/<?= (int)$closingPlan['id'] ?>" class="btn btn-secondary btn-sm" data-testid="closing-plan-open">
            Ver plano publicado
          </a>
          <?php if ($patientHasAppAccount): ?>
            <span class="badge ds-pill ds-pill--success" data-testid="closing-plan-app-status">Liberado no app do paciente</span>
          <?php else: ?>
            <form method="POST" action="/pacientes/<?= $closingPid ?>/convite-app" class="u-inline u-m-0" data-no-dirty>
              <input type="hidden" name="_csrf_token" value="<?= $eClose($closingCsrf) ?>">
              <button type="submit" class="btn btn-outline btn-sm" data-testid="closing-plan-app-invite">Liberar no app (enviar convite)</button>
            </form>
          <?php endif; ?>
        </div>
        <?php if (!$patientHasAppAccount): ?>
          <p class="u-text-caption u-color-neutral-gray-600 u-m-0" data-testid="closing-plan-app-hint">
            O paciente ainda não ativou o aplicativo. Clique em <strong>Liberar no app (enviar convite)</strong> para gerar o link de acesso ou entregue o PDF acima.
          </p>
        <?php endif; ?>
      <?php elseif ($closingPlan): ?>
        <p class="u-text-compact u-color-neutral-gray-700 u-mt-0 u-mb-3">
          O plano <strong><?= $eClose($closingPlan['title']) ?></strong> ainda está em rascunho. Revise e publique para liberar no app e gerar o PDF final.
        </p>
        <div class="u-flex u-gap-2 u-items-center u-wrap">
          <a href="/planos/<?= (int)$closingPlan['id'] ?>?atendimento=<?= $closingAppId ?>" class="btn btn-primary btn-sm" data-testid="closing-plan-review">
            Revisar e publicar plano
          </a>
          <a href="/planos/<?= (int)$closingPlan['id'] ?>/imprimir" target="_blank" rel="noopener" class="btn btn-outline btn-sm" data-testid="closing-plan-pdf">
            Baixar PDF do rascunho
          </a>
        </div>
      <?php else: ?>
        <p class="u-text-compact u-color-neutral-gray-600 u-mt-0 u-mb-3">
          Nenhum plano alimentar vinculado ainda. Você pode criar ou publicar o plano quando desejar.
        </p>
        <a href="/pacientes/<?= $closingPid ?>/plano?atendimento=<?= $closingAppId ?>" class="btn btn-secondary btn-sm" data-testid="closing-plan-create">
          + Criar plano alimentar
        </a>
      <?php endif; ?>
    </div>
  </div>
</section>
