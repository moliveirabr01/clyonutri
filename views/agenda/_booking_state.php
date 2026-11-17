<?php if($canConfigureAgenda): ?>
<div class="op-help" data-testid="agenda-booking-shortcut" data-booking-state="<?= htmlspecialchars($bookingUiState,ENT_QUOTES,'UTF-8') ?>">
<?php if(empty($agendaBookingSettings['is_enabled'])): ?>
  <a href="/configuracoes?tab=agenda&amp;secao=link" data-testid="agenda-booking-activate">Agendamento online: desativado · Ativar</a>
<?php else: ?>
  <span>Agendamento online: ativo</span> · <button type="button" class="btn btn-secondary" data-copy-booking-link data-booking-url="<?= htmlspecialchars($agendaPublicBookingLink,ENT_QUOTES,'UTF-8') ?>">Copiar link</button> · <a href="/configuracoes?tab=agenda&amp;secao=link">Configurar</a>
  <label data-booking-link-fallback hidden>Link de agendamento<input class="form-control" type="text" readonly value="<?= htmlspecialchars($agendaPublicBookingLink,ENT_QUOTES,'UTF-8') ?>"></label>
  <span role="status" aria-live="polite" data-booking-copy-status></span>
<?php endif; ?>
</div>
<?php endif; ?>
