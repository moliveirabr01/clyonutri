<?php
$calendarPeriodRule=$calendarPeriodRule??[];
$calendarPeriodPartial=($calendarPeriodRule['kind']??'holiday')==='open' || !empty($calendarPeriodRule['start_time']);
?>
<div class="clyo-calendar__grid clyo-calendar__period-grid">
<label class="clyo-calendar__field">Período<select class="form-control" name="period" data-calendar-period required>
<?php if(($calendarPeriodRule['kind']??'holiday')!=='open'): ?><option value="full" <?= !$calendarPeriodPartial?'selected':'' ?>>Dia inteiro</option><?php endif; ?>
<option value="partial" <?= $calendarPeriodPartial?'selected':'' ?>>Definir horário</option>
</select></label>
<label class="clyo-calendar__field">Horário de início<input class="form-control" type="time" step="60" name="start_time" value="<?= $ce($calendarPeriodRule['start_time']??'') ?>" <?= $calendarPeriodPartial?'required':'disabled' ?>></label>
<label class="clyo-calendar__field">Horário de fim<input class="form-control" type="time" step="60" name="end_time" value="<?= $ce($calendarPeriodRule['end_time']??'') ?>" <?= $calendarPeriodPartial?'required':'disabled' ?>></label>
</div>
