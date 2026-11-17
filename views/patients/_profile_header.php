<?php
/**
 * NI2-622: cabeçalho do perfil do paciente.
 *  - Contexto estável: nome, selo de alergias, idade/sexo e a situação do atendimento numa linha.
 *  - UMA ação principal, conforme o estado do atendimento (AppointmentService::careStepForPatient):
 *      continue → Continuar atendimento · start_today → Iniciar atendimento · upcoming → Preparar consulta · none → Iniciar agora + Agendar
 *  - Ações raras no menu "Mais ações".
 *  - Navegação clínica única (abas), sem entradas repetidas, com indicadores discretos de pendência.
 * Entradas: $p, $careStep, $csrfToken, $clinicalSummary, $activePlan, $profileBadgeHtml, $pendingExamCount, $activeTab
 */
$e = static fn($v): string => htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
$pid = (int)$p['id'];
$stepApp = $careStep['appointment'] ?? null;
$stepState = (string)($careStep['state'] ?? 'none');
$stepWhen = $stepApp ? date('d/m/Y', strtotime($stepApp['scheduled_at'])) . ' às ' . date('H:i', strtotime($stepApp['scheduled_at'])) : '';
$age = $p['birth_date'] ? (new \DateTimeImmutable($p['birth_date']))->diff(new \DateTimeImmutable('today'))->y . ' anos' : 'idade não informada';
$draftAnamnesis = !empty($clinicalSummary['draft_in_progress']);
$pendingDiaryCount = 0;
try {
    $pendingDiaryCount = (new \ClyoNutri\Domain\FoodDiary\FoodDiaryService(\ClyoNutri\Core\Database\Connection::getInstance()))->countPendingDiaries(\ClyoNutri\Core\Auth\TenantContext::getClinicId(), $pid);
} catch (\Throwable $e) {
    $pendingDiaryCount = 0;
}
$atendimentoHint = $stepState === 'continue'
    ? 'atendimento em andamento'
    : ($pendingDiaryCount > 0 ? ($pendingDiaryCount === 1 ? '1 diário sem leitura' : $pendingDiaryCount . ' diários sem leitura') : null);
$tabs = [
    'resumo' => ['Resumo', '/pacientes/' . $pid, null],
    'atendimento' => ['Atendimento', '/pacientes/' . $pid . '/prontuario', $atendimentoHint],
    'plano' => ['Plano', '/pacientes/' . $pid . '/plano', $activePlan && ($activePlan['status'] ?? '') === 'draft' ? 'plano em rascunho' : null], // NI2-711
    'anamnese' => ['Anamnese', '/pacientes/' . $pid . '/anamnese', $draftAnamnesis ? 'alteração em andamento' : null],
    // NI2-708: a aba leva à antropometria/avaliações físicas; "Evolução" fica reservada à evolução clínica (prontuário).
    'avaliacoes' => ['Avaliações', '/pacientes/' . $pid . '/antropometria', null],
    'exames' => ['Exames', '/pacientes/' . $pid . '/exames', $pendingExamCount > 0 ? $pendingExamCount . ($pendingExamCount === 1 ? ' solicitação a concluir' : ' solicitações a concluir') : null],
    'documentos' => ['Documentos', '/pacientes/' . $pid . '/documentos', null], // NI2-711
];
?>
<header class="patient-header" data-testid="patient-header">
  <div class="patient-header__main">
    <div class="patient-header__identity">
      <div class="patient-header__name-row">
        <h1 class="patient-header__name"><?= $e($p['name']) ?></h1>
        <?= $profileBadgeHtml ?>
      </div>
      <p class="patient-header__meta">
        <span><?= $e($age) ?></span><span aria-hidden="true">·</span><span><?= $e(\ClyoNutri\Core\I18n\LabelMap::patientGender($p['gender'] ?? null)) ?></span><span aria-hidden="true">·</span>
        <span data-testid="patient-care-context">
          <?php if ($stepState === 'continue'): ?><?= $e(\ClyoNutri\Application\View\AttendanceContext::label($stepApp)) ?><?= \ClyoNutri\Application\View\AttendanceContext::selected($pid) ? ' · contexto selecionado' : ' · seleção automática: mais recente em andamento' ?>
          <?php elseif ($stepState === 'selected'): ?><?= $e(\ClyoNutri\Application\View\AttendanceContext::label($stepApp)) // v54.57 (UX-119): tipo, data e hora ?>
          <?php elseif ($stepState === 'start_today'): ?>Consulta hoje às <?= $e(date('H:i', strtotime($stepApp['scheduled_at']))) ?>
          <?php elseif ($stepState === 'upcoming'): ?>Próxima consulta: <?= $e($stepWhen) ?>
          <?php elseif ($stepState === 'unavailable'): ?>Agenda temporariamente indisponível
          <?php else: ?>Nenhuma consulta agendada<?php endif; ?>
        </span>
      </p>
      <?php if (in_array($stepState, ['continue', 'selected'], true)): // v54.57 (UX-105): etapas navegáveis sem voltar à sala ?>
        <?= \ClyoNutri\Application\View\AttendanceContext::stepsNav($stepApp) ?>
      <?php endif; ?>
    </div>
    <div class="patient-header__actions" data-testid="care-next-step" data-state="<?= $e($stepState) ?>">
      <?php if ($stepState === 'continue'): ?>
        <a class="btn btn-primary" href="/consulta/<?= (int)$stepApp['id'] ?>" data-testid="care-continue">Continuar atendimento</a>
      <?php elseif ($stepState === 'selected'): ?>
        <a class="btn btn-primary" href="/consulta/<?= (int)$stepApp['id'] ?>">Ver consulta selecionada</a>
      <?php elseif ($stepState === 'start_today'): ?>
        <form method="POST" action="/agenda/<?= (int)$stepApp['id'] ?>/status" class="u-m-0" data-no-dirty data-confirm="<?= $e('Iniciar agora o atendimento de ' . $p['name'] . ' (consulta das ' . date('H:i', strtotime($stepApp['scheduled_at'])) . ')?') ?>">
          <input type="hidden" name="_csrf_token" value="<?= $e($csrfToken) ?>">
          <input type="hidden" name="status" value="in_progress">
          <button type="submit" class="btn btn-primary" data-testid="care-start">Iniciar atendimento</button>
        </form>
      <?php elseif ($stepState === 'upcoming'): ?>
        <a class="btn btn-primary" href="/pacientes/<?= $pid ?>/preparar" data-testid="care-prepare">Preparar consulta</a>
        <form method="POST" action="/pacientes/<?= $pid ?>/iniciar-agora" class="u-m-0" data-no-dirty data-confirm="<?= $e('Iniciar agora um atendimento de ' . $p['name'] . ' sem agendamento?') ?>">
          <input type="hidden" name="_csrf_token" value="<?= $e($csrfToken) ?>">
          <button type="submit" class="btn btn-secondary" data-testid="care-start-now">Iniciar agora</button>
        </form>
      <?php elseif ($stepState === 'unavailable'): ?>
        <?php /* NI2-692: agenda falhou — não inventar horários; alergias/contexto já renderizados acima. */ ?>
        <p class="op-message op-message--warning u-m-0" role="status" data-testid="care-step-unavailable">Agenda temporariamente indisponível. O contexto clínico (alergias) permanece visível.</p>
        <?= \ClyoNutri\Application\View\NewConsultationButton::link('/agenda?novo=1&paciente='.$pid.'&de=paciente','care-schedule') ?>
      <?php else: ?>
        <?php /* NI2-705: atendimento imediato sem passar pela agenda; agendar continua disponível. */ ?>
        <form method="POST" action="/pacientes/<?= $pid ?>/iniciar-agora" class="u-m-0" data-no-dirty data-confirm="<?= $e('Iniciar agora um atendimento de ' . $p['name'] . ' sem agendamento?') ?>">
          <input type="hidden" name="_csrf_token" value="<?= $e($csrfToken) ?>">
          <button type="submit" class="btn btn-primary" data-testid="care-start-now">Iniciar agora</button>
        </form>
        <?= \ClyoNutri\Application\View\NewConsultationButton::link('/agenda?novo=1&paciente='.$pid.'&de=paciente','care-schedule') ?>
      <?php endif; ?>
      <details class="patient-header__more" data-profile-tools>
        <summary class="btn btn-secondary" data-testid="patient-more">Mais ações</summary>
        <div class="patient-header__menu" role="group" aria-label="Mais ações do paciente">
          <?php if ($stepApp && in_array($stepState, ['start_today', 'upcoming'], true)): ?>
            <a class="btn btn-secondary" href="/agenda/<?= (int)$stepApp['id'] ?>/reagendar?de=paciente" data-testid="care-reschedule">Reagendar consulta</a>
            <a class="btn btn-secondary" href="/agenda?view=day&amp;date=<?= $e(substr((string)$stepApp['scheduled_at'], 0, 10)) ?>">Ver na agenda</a>
          <?php endif; ?>
          <?php if (in_array($stepState, ['continue','start_today','upcoming'], true)): /* NI2-707: retorno quando há consulta conhecida */ ?>
            <a class="btn btn-secondary" href="/agenda?novo=1&amp;paciente=<?= $pid ?>&amp;de=paciente" data-testid="care-schedule-return">Agendar retorno</a>
          <?php endif; ?>
          <a class="btn btn-secondary" href="/pacientes/<?= $pid ?>/editar">Editar cadastro</a>
          <a class="btn btn-secondary" href="/chat?patient_id=<?= $pid ?>">Conversar pelo chat</a>
          <a class="btn btn-secondary" href="/materiais?para=<?= $pid ?>" data-testid="patient-send-material">Enviar material educativo</a>
          <button type="button" class="btn btn-secondary" data-call="openAnthroModal">Nova avaliação física</button>
          <form method="post" action="/pacientes/<?= $pid ?>/convite-app" class="u-m-0">
            <input type="hidden" name="_csrf_token" value="<?= $e($csrfToken) ?>">
            <button class="btn btn-secondary" type="submit">Gerar convite para o app</button>
          </form>
          <label class="patient-header__pref"><input type="checkbox" data-profile-tools-preference data-transient> Deixar este menu aberto neste navegador</label>
        </div>
      </details>
    </div>
  </div>
  <nav class="patient-tabs" aria-label="Navegação clínica do paciente" data-testid="patient-tabs">
    <ul>
      <?php $activeTabKey = ($activeTab ?? 'resumo') === 'evolucao' ? 'avaliacoes' : ($activeTab ?? 'resumo'); foreach ($tabs as $key => [$label, $href, $pending]): $on = $activeTabKey === $key; ?>
        <li><a href="<?= $e($href) ?>" data-tab="<?= $e($key) ?>"<?= $on ? ' aria-current="page"' : '' ?>><?= $e($label) ?><?php if ($pending): ?><span class="patient-tabs__dot" aria-hidden="true"></span><span class="visually-hidden"> (<?= $e($pending) ?>)</span><?php endif; ?></a></li>
      <?php endforeach; ?>
    </ul>
  </nav>

</header>
