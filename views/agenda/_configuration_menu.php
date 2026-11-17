<?php if($canConfigureAgenda): ?>
<details class="patient-header__more" data-testid="agenda-configuration-menu">
  <summary class="btn btn-secondary" data-testid="agenda-to-settings-btn">Configurar agenda</summary>
  <div class="patient-header__menu">
    <a class="btn btn-secondary" href="/configuracoes?tab=agenda&amp;secao=horarios">Horários e regras</a>
    <a class="btn btn-secondary" href="/configuracoes?tab=agenda&amp;secao=feriados">Feriados e bloqueios</a>
    <a class="btn btn-secondary" href="/configuracoes?tab=agenda&amp;secao=link">Link de agendamento</a>
  </div>
</details>
<?php endif; ?>
