<?php
$scheduleEscape=static fn($v)=>htmlspecialchars((string)$v,ENT_QUOTES,'UTF-8');
$scheduleDateValue=$scheduleFieldValues['scheduled_date']??substr(str_replace(' ','T',$scheduleFieldValue),0,10);
$scheduleTimeValue=$scheduleFieldValues['scheduled_time']??substr(str_replace(' ','T',$scheduleFieldValue),11,5);
?>
<div><label for="<?= $scheduleEscape($scheduleFieldPrefix) ?>-date" class="u-block u-font-semibold u-text-compact u-mb-1">Data *</label>
<input class="form-control" id="<?= $scheduleEscape($scheduleFieldPrefix) ?>-date" type="date" name="scheduled_date" required value="<?= $scheduleEscape($scheduleDateValue) ?>" <?= $scheduleFieldConflict?'aria-invalid="true"':'' ?>></div>
<div><label for="<?= $scheduleEscape($scheduleFieldPrefix) ?>-time" class="u-block u-font-semibold u-text-compact u-mb-1">Horário *</label>
<input class="form-control" id="<?= $scheduleEscape($scheduleFieldPrefix) ?>-time" type="time" step="60" name="scheduled_time" required value="<?= $scheduleEscape($scheduleTimeValue) ?>" <?= $scheduleFieldConflict?'aria-invalid="true" autofocus data-conflict-highlight':'' ?>>
<small class="u-block u-color-neutral-gray-500 u-mt-1">Horário da clínica (<?= $scheduleEscape(\ClyoNutri\Domain\Booking\BookingCalendarService::tz()->getName()) ?>).</small></div>
