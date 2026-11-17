<?php
/**
 * NI2-880: Bloco "Diário do paciente" reutilizável em Preparar consulta, aba Atendimento e /pacientes/{id}/diario.
 * Espera: $diaryPanel (retorno de FoodDiaryService::professionalWebPanel), $pid (int), $csrfToken (string),
 *         $diaryReturnTo (string, ex: '/pacientes/1/preparar#diario-paciente'), $diaryFilterAction (string).
 */
$e = static fn($v): string => htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
$dp = (array)($diaryPanel ?? []);
$entries = (array)($dp['entries'] ?? []);
$count = (int)($dp['count'] ?? count($entries));
$pending = (int)($dp['pending_count'] ?? 0);
$avgAdh = $dp['avg_adherence_pct'] ?? null;
$sinceLabel = (string)($dp['since_label'] ?? 'o início');
$fromVal = (string)($dp['from'] ?? '');
$toVal = (string)($dp['to'] ?? '');
$showAll = !empty($dp['show_all']);
$returnTo = (string)($diaryReturnTo ?? ('/pacientes/' . $pid . '/diario#diario-paciente'));
$filterAction = (string)($diaryFilterAction ?? strtok($returnTo, '#'));
?>
<section id="diario-paciente" class="card u-mt-6 u-mb-6 u-scroll-margin" aria-labelledby="diario-paciente-titulo" data-testid="food-diary-panel">
  <div class="u-flex u-justify-between u-items-start u-wrap u-gap-3 u-mb-4">
    <div>
      <h2 id="diario-paciente-titulo" class="u-text-h3 u-font-bold u-color-botanical-forest-900 u-m-0">
        Diário do paciente
        <?php if ($pending > 0): ?>
          <span class="badge badge-warning u-text-caption u-ml-2" data-testid="food-diary-pending-badge"><?= $pending ?> sem devolutiva</span>
        <?php endif; ?>
      </h2>
      <p class="u-text-compact u-color-neutral-gray-600 u-mt-1 u-mb-0" data-testid="food-diary-summary">
        <strong><?= $count ?></strong> <?= $count === 1 ? 'registro' : 'registros' ?> no período
        · Adesão média nos check-ins: <strong><?= $avgAdh !== null ? (int)$avgAdh . '%' : 'sem check-ins no período' ?></strong>
      </p>
    </div>

    <form method="GET" action="<?= $e($filterAction) ?>#diario-paciente" class="u-flex u-wrap u-gap-2 u-items-end">
      <div>
        <label for="diary-from-<?= $pid ?>" class="u-text-caption u-color-neutral-gray-600 u-block">De</label>
        <input id="diary-from-<?= $pid ?>" type="date" name="diario_de" value="<?= $e($fromVal) ?>" class="form-control" style="padding: 0.35rem 0.5rem;">
      </div>
      <div>
        <label for="diary-to-<?= $pid ?>" class="u-text-caption u-color-neutral-gray-600 u-block">Até</label>
        <input id="diary-to-<?= $pid ?>" type="date" name="diario_ate" value="<?= $e($toVal) ?>" class="form-control" style="padding: 0.35rem 0.5rem;">
      </div>
      <button type="submit" class="btn btn-secondary btn-sm">Filtrar</button>
      <?php if (!$showAll): ?>
        <a href="<?= $e($filterAction) ?>?diario_tudo=1#diario-paciente" class="btn btn-outline btn-sm">Ver tudo</a>
      <?php else: ?>
        <a href="<?= $e($filterAction) ?>#diario-paciente" class="btn btn-outline btn-sm">Desde última consulta</a>
      <?php endif; ?>
    </form>
  </div>

  <?php if (!empty($_GET['diario_feedback']) && $_GET['diario_feedback'] === 'enviado'): ?>
    <div class="op-message op-message--success u-mb-4 u-text-compact" role="status" data-testid="food-diary-feedback-ok">
      Devolutiva enviada ao paciente.
    </div>
  <?php elseif (!empty($_GET['diario_error'])): ?>
    <div class="op-message op-message--error u-mb-4 u-text-compact" role="alert">
      <?= $e((string)($_GET['diario_msg'] ?? 'Não foi possível enviar a devolutiva.')) ?>
    </div>
  <?php endif; ?>

  <?php if (empty($entries)): ?>
    <p class="u-color-neutral-gray-500 u-text-compact u-m-0" data-testid="food-diary-empty">
      Sem registros desde <?= $e($sinceLabel) ?>.
    </p>
  <?php else: ?>
    <div class="u-flex u-flex-col u-gap-4" data-testid="food-diary-list">
      <?php foreach ($entries as $entry): ?>
        <?php
          $eid = (int)$entry['id'];
          $consumedFmt = !empty($entry['consumed_at']) ? date('d/m/Y H:i', strtotime((string)$entry['consumed_at'])) : '—';
          $feedbackTxt = trim((string)($entry['feedback_from_professional'] ?? ''));
          $reviewedFmt = !empty($entry['reviewed_at']) ? date('d/m/Y H:i', strtotime((string)$entry['reviewed_at'])) : null;
          $photo = trim((string)($entry['photo_path'] ?? ''));
        ?>
        <article class="u-border u-radius-input u-p-4 u-bg-neutral-gray-50" data-testid="food-diary-entry" data-entry-id="<?= $eid ?>">
          <div class="u-flex u-justify-between u-items-center u-wrap u-gap-2 u-mb-2">
            <div>
              <strong class="u-color-botanical-forest-900"><?= $e($entry['meal_name'] ?? 'Refeição') ?></strong>
              <span class="u-text-caption u-color-neutral-gray-600 u-ml-2"><?= $e($consumedFmt) ?></span>
            </div>
            <div class="u-text-caption u-color-neutral-gray-600">
              <?php if (isset($entry['hunger_level'])): ?>Fome: <strong><?= (int)$entry['hunger_level'] ?>/5</strong><?php endif; ?>
              <?php if (isset($entry['satiety_level'])): ?> · Saciedade: <strong><?= (int)$entry['satiety_level'] ?>/5</strong><?php endif; ?>
            </div>
          </div>

          <?php if (!empty($entry['description'])): ?>
            <p class="u-text-body u-m-0 u-mb-2" data-testid="food-diary-description"><?= nl2br($e((string)$entry['description'])) ?></p>
          <?php endif; ?>

          <?php if (!empty($entry['symptoms'])): ?>
            <p class="u-text-compact u-color-neutral-gray-700 u-m-0 u-mb-2"><strong>Sintomas:</strong> <?= $e((string)$entry['symptoms']) ?></p>
          <?php endif; ?>

          <?php if ($photo !== ''): ?>
            <p class="u-text-compact u-m-0 u-mb-2"><strong>Foto registrada:</strong> <code><?= $e(basename($photo)) ?></code></p>
          <?php endif; ?>

          <?php if ($feedbackTxt !== ''): ?>
            <div class="u-bg-neutral-white u-border u-radius-input u-p-3 u-mb-3 u-text-compact" data-testid="food-diary-feedback-sent">
              <strong>Devolutiva enviada<?= $reviewedFmt ? ' (lida/registrada em ' . $e($reviewedFmt) . ')' : '' ?>:</strong>
              <p class="u-m-0 u-mt-1"><?= nl2br($e($feedbackTxt)) ?></p>
            </div>
          <?php endif; ?>

          <form method="POST" action="/pacientes/<?= $pid ?>/diario/<?= $eid ?>/devolutiva" class="u-flex u-wrap u-gap-2 u-items-end">
            <input type="hidden" name="_csrf_token" value="<?= $e($csrfToken) ?>">
            <input type="hidden" name="return_to" value="<?= $e($returnTo) ?>">
            <div style="flex: 1 1 260px;">
              <label for="diary-fb-<?= $eid ?>" class="sr-only">Devolutiva para a refeição <?= $e($entry['meal_name'] ?? '') ?></label>
              <input id="diary-fb-<?= $eid ?>" type="text" name="feedback" class="form-control" maxlength="1000" required
                     placeholder="<?= $feedbackTxt !== '' ? 'Atualizar devolutiva...' : 'Escrever devolutiva curta para o paciente...' ?>"
                     value="">
            </div>
            <button type="submit" class="btn btn-primary btn-sm" data-testid="food-diary-send-feedback">Enviar</button>
          </form>
        </article>
      <?php endforeach; ?>
    </div>
  <?php endif; ?>
</section>
