<?php
$we = static fn($v) => htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
$dayNamesUpper = ['DOM', 'SEG', 'TER', 'QUA', 'QUI', 'SEX', 'SÁB'];
?>
<div class="card agenda-week-board" aria-labelledby="week-timeline-title" data-week-timeline>
  <h3 id="week-timeline-title" class="sr-only">Semana de <?= $we(date('d/m', strtotime($weekData['start_date']))) ?> a <?= $we(date('d/m/Y', strtotime($weekData['end_date']))) ?></h3>
  <div class="op-week-grid agenda-week-grid" role="group" aria-label="Grade semanal de horários">
    <?php foreach ($weekData['days'] as $date => $apps):
      $dow = (int)date('w', strtotime($date));
      $isToday = isset($todayDate) && $date === $todayDate;
      $agendaDayRules = $agendaCalendarRules[$date] ?? [];
      $isHoliday = (bool)array_filter($agendaDayRules, static fn($r) => ($r['kind'] ?? '') === 'holiday');
    ?>
      <section class="op-week-day agenda-week-col<?= $isToday ? ' is-today' : '' ?><?= $isHoliday ? ' is-holiday' : '' ?>" aria-label="<?= $we($date) ?>">
        <h4 class="agenda-week-col__head">
          <a href="/agenda?view=day&amp;date=<?= $we($date) ?>" class="agenda-week-col__link" aria-label="<?= $we($dayNamesUpper[$dow] . ' ' . date('d/m/Y', strtotime($date)) . ($isToday ? ' (Hoje)' : '')) ?>">
            <span class="agenda-week-col__dow"><?= $we($dayNamesUpper[$dow]) ?></span>
            <span class="agenda-week-col__day"><?= $we(date('d', strtotime($date))) ?></span>
          </a>
        </h4>
        <div class="agenda-week-col__body">
          <?php $agendaMarkerDate = $date; require __DIR__ . '/_calendar_markers.php'; ?>
          <?php foreach ($apps as $app):
            $statusKey = (string)($app['status'] ?? 'scheduled');
            $statusMod = match ($statusKey) {
                'confirmed', 'completed' => 'confirmed',
                'in_progress' => 'in-progress',
                'cancelled', 'no_show' => 'muted',
                default => 'scheduled',
            };
          ?>
            <a class="op-week-appointment agenda-week-card agenda-week-card--<?= $statusMod ?>" href="/consulta/<?= (int)$app['id'] ?>">
              <span class="agenda-week-card__time"><?= $we(date('H:i', strtotime($app['scheduled_at']))) ?></span>
              <strong class="agenda-week-card__patient" title="<?= $we($app['patient_name']) ?>"><?= $we($app['patient_name']) ?></strong>
              <span class="agenda-week-card__status"><?= $we(\ClyoNutri\Core\I18n\LabelMap::appointmentStatus($statusKey)) ?></span>
            </a>
          <?php endforeach; ?>
        </div>
      </section>
    <?php endforeach; ?>
  </div>
</div>
