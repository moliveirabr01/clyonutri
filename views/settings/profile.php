<?php $settingsAreaTitle=['perfil'=>'Consultório e perfil','agenda'=>'Agenda e disponibilidade','paciente-app'=>'Comunicação','conta'=>'Segurança'][$activeTab]??'Configurações'; ?>
<h1 data-settings-saved-section="<?= $success?htmlspecialchars((string)($lastSec??$_POST['section']??''),ENT_QUOTES,'UTF-8'):'' ?>" data-settings-error-section="<?= $error?htmlspecialchars((string)($_POST['section']??''),ENT_QUOTES,'UTF-8'):'' ?>"><?= htmlspecialchars($settingsAreaTitle,ENT_QUOTES) ?></h1>
<p><?= htmlspecialchars(['perfil'=>'Atualize seu cadastro e a identificação do consultório.','agenda'=>'Configure quando atende e como o paciente pode agendar.','paciente-app'=>'Abra a tarefa que deseja ajustar. O resumo mostra as regras já salvas.','conta'=>'Altere sua senha de acesso com a confirmação da senha atual.'][$activeTab]??'Escolha a configuração que deseja ajustar.',ENT_QUOTES) ?></p>

<?php require __DIR__ . '/_tabs.php'; ?>

<?php if($error): ?><p class="op-message op-message--error card" role="alert"><?= htmlspecialchars($error) ?></p><?php endif; ?>
<?php if($success): ?><p class="op-message op-message--success card" role="status"><?= htmlspecialchars($successMessage ?? 'Configurações salvas.') ?></p><?php endif; ?>
<?php $bs=$bookingSettings; $dis=$canManageBooking?'':'disabled'; ?>
<div class="u-maxw-page">

<?php if ($activeTab === 'perfil'): ?>
<section id="settings-profile" data-settings-section aria-label="Perfil">
<form class="card" method="POST" action="/configuracoes" data-unsaved-form>
<h2>Dados cadastrais</h2>
<input type="hidden" name="tab" value="perfil">
<input type="hidden" name="section" value="profile">
<input type="hidden" name="_csrf_token" value="<?= \ClyoNutri\Core\Security\Csrf::token() ?>">
<input type="hidden" name="revision" value="<?= $revision ?>">
<?php foreach(['name'=>['Nome do profissional','text',120],'email'=>['E-mail de acesso','email',255],'phone'=>['Celular / WhatsApp','tel',30],'clinic_name'=>['Nome do consultório','text',150],'specialty'=>['Especialidade','text',100]] as $field=>[$label,$type,$max]): ?>
<div class="form-group"><label class="form-label" for="profile-<?= $field ?>"><?= $label ?></label><input class="form-control" id="profile-<?= $field ?>" name="<?= $field ?>" type="<?= $type ?>" maxlength="<?= $max ?>" value="<?= htmlspecialchars($values[$field]??'',ENT_QUOTES) ?>" <?= in_array($field,['name','email','clinic_name'])?'required':'' ?> <?= $field==='clinic_name'&&!in_array($profile['member_role'],['owner','admin'],true)?'readonly':'' ?>></div>
<?php endforeach; ?>
<label class="form-label" for="profile-bio">Apresentação profissional</label><textarea class="form-control" id="profile-bio" name="bio" maxlength="2000" rows="4"><?= htmlspecialchars($values['bio']??'') ?></textarea>
<fieldset class="u-border-0 u-p-0 u-my-6 u-mx-0" id="dados-cobranca" <?= !in_array($profile['member_role'],['owner','admin'],true)?'disabled':'' ?>>
<legend class="u-text-card-title u-font-bold u-mb-4">Dados do consultório para cobrança</legend>
<p>Usados para identificar o consultório no checkout do Asaas. Para assinar, preencha CPF/CNPJ, e-mail, telefone e endereço completo; complemento é opcional.</p>
<?php if(!in_array($profile['member_role'],['owner','admin'],true)): ?><p><strong>Somente o titular ou administrador altera estes dados.</strong></p><?php endif; ?>
<div class="u-grid u-cols-2 u-gap-4">
<?php foreach(['clinic_document'=>['CPF ou CNPJ (obrigatório para assinatura e nota fiscal)','text',20,'Somente números ou com pontuação'],'clinic_email'=>['E-mail do consultório','email',255,''],'clinic_phone'=>['Telefone do consultório (com DDD)','tel',30,'Se ficar vazio, usaremos seu Celular / WhatsApp ao assinar'],'clinic_address_zip'=>['CEP','text',20,'8 dígitos'],'clinic_address_street'=>['Rua / avenida','text',255,''],'clinic_address_number'=>['Número','text',30,''],'clinic_address_complement'=>['Complemento (opcional)','text',100,''],'clinic_address_neighborhood'=>['Bairro','text',100,''],'clinic_address_city'=>['Cidade','text',100,'']] as $field=>[$label,$type,$max,$hint]): ?>
<div class="form-group"><label class="form-label" for="profile-<?= $field ?>"><?= $label ?></label><input class="form-control" id="profile-<?= $field ?>" name="<?= $field ?>" type="<?= $type ?>" maxlength="<?= $max ?>" value="<?= htmlspecialchars($values[$field]??'',ENT_QUOTES,'UTF-8') ?>" <?= $field==='clinic_document'?'required':'' ?>><?php if($hint!==''): ?><small><?= $hint ?></small><?php endif; ?></div>
<?php endforeach; ?>
<div class="form-group"><label class="form-label" for="profile-clinic_address_state">UF</label><select class="form-control" id="profile-clinic_address_state" name="clinic_address_state"><option value="">Selecione...</option><?php foreach(['AC','AL','AP','AM','BA','CE','DF','ES','GO','MA','MT','MS','MG','PA','PB','PR','PE','PI','RJ','RN','RS','RO','RR','SC','SP','SE','TO'] as $uf): ?><option value="<?= $uf ?>" <?= strtoupper((string)($values['clinic_address_state']??''))===$uf?'selected':'' ?>><?= $uf ?></option><?php endforeach; ?></select></div>
</div>
</fieldset>
<fieldset class="u-border-0 u-p-0 u-my-6 u-mx-0">
<legend class="u-text-card-title u-font-bold u-mb-4">Registro no conselho (CRN) e identificação</legend>
<div class="u-grid u-cols-2 u-gap-4">
<div class="form-group"><label class="form-label" for="profile-crn">Número do CRN</label><input class="form-control" id="profile-crn" name="crn" maxlength="20" required value="<?= htmlspecialchars($values['crn']??'',ENT_QUOTES) ?>"></div>
<div class="form-group"><label class="form-label" for="profile-crn_region">Região do CRN</label>
<?php $crnOptions=['CRN-1'=>'CRN-1 (DF/GO/MT/TO)','CRN-2'=>'CRN-2 (RS)','CRN-3'=>'CRN-3 (SP/MS)','CRN-4'=>'CRN-4 (RJ/ES)','CRN-5'=>'CRN-5 (BA/SE)','CRN-6'=>'CRN-6 (PE/AL/PB/RN)','CRN-7'=>'CRN-7 (PA/AM/AP/AC/RO/RR)','CRN-8'=>'CRN-8 (PR)','CRN-9'=>'CRN-9 (MG)','CRN-10'=>'CRN-10 (SC)','CRN-11'=>'CRN-11 (CE/MA/PI)']; $currentReg=strtoupper(trim($values['crn_region']??'')); ?>
<select class="form-control" id="profile-crn_region" name="crn_region" required>
  <option value="">Selecione a região...</option>
  <?php foreach($crnOptions as $val=>$lbl): ?>
    <option value="<?= $val ?>" <?= $currentReg===$val || str_starts_with($currentReg,$val)?'selected':'' ?>><?= $lbl ?></option>
  <?php endforeach; ?>
</select>
</div>
</div>
<p class="u-text-compact">Alterações de e-mail ou CRN ficam pendentes de nova verificação cadastral.</p>
</fieldset>
<div class="form-group"><label class="form-label" for="profile-current-password">Senha atual para alterar o e-mail</label><input class="form-control" id="profile-current-password" name="current_password" type="password" autocomplete="current-password"><small>Preencha somente se estiver alterando o e-mail de acesso.</small></div>
<button type="submit" class="btn btn-primary">Salvar perfil</button>
</form>
</section>
<?php endif; ?>

<?php if ($activeTab === 'agenda'): ?>
<section id="settings-agenda" data-settings-section aria-label="Agenda">
<?php
  // NI2-612 (UX-R3-11): uma seção por vez e um aviso só por pendência do agendamento online.
  $agendaNav = ['horarios' => 'Horários', 'link' => 'Link online', 'feriados' => 'Feriados e bloqueios'];
  if ($canManageBooking) { $agendaNav['regras'] = 'Regras e lista de espera'; }
  $bookingNotice = \ClyoNutri\Domain\Booking\BookingSetupState::message($bookingSetupState);
?>
<?php if ($bookingNotice !== null): ?>
<div class="op-message op-message--warning card settings-booking-notice" role="alert" data-testid="<?= $bookingSetupState === \ClyoNutri\Domain\Booking\BookingSetupState::HOURS_UNCONFIRMED ? 'settings-legacy-hours' : 'settings-booking-notice' ?>" data-state="<?= htmlspecialchars($bookingSetupState, ENT_QUOTES) ?>">
  <strong><?= $bookingSetupState === \ClyoNutri\Domain\Booking\BookingSetupState::HOURS_UNCONFIRMED ? 'Confirme seus horários.' : 'Agendamento online:' ?></strong> <?= htmlspecialchars($bookingNotice) ?>
  <?php if ($agendaSection !== 'horarios'): ?> <a href="/configuracoes?tab=agenda&amp;secao=horarios#disponibilidade"><?= $bookingSetupState === \ClyoNutri\Domain\Booking\BookingSetupState::NO_SLOTS ? 'Revisar horários' : 'Confirmar horários' ?> →</a><?php endif; ?>
</div>
<?php endif; ?>
<nav class="settings-subnav" aria-label="Seções da agenda" data-testid="agenda-subnav">
<?php foreach ($agendaNav as $key => $label): ?>
  <a href="/configuracoes?tab=agenda&amp;secao=<?= $key ?>" data-testid="agenda-subnav-<?= $key ?>" <?= $agendaSection === $key ? 'aria-current="page"' : '' ?>><?= htmlspecialchars($label) ?></a>
<?php endforeach; ?>
</nav>
<?php if ($agendaSection === 'horarios'): ?>
<form class="card" method="POST" action="/configuracoes" data-unsaved-form id="disponibilidade">
<h2>Atendimento e disponibilidade semanal</h2>
<?php if(!empty($legacyHoursPending)): ?><p data-testid="settings-legacy-hint"><strong>Sua conta tinha só os dias de atendimento.</strong> Preenchemos uma sugestão (08:00 às 18:00, pausa das 12:00 às 13:00) nos dias marcados. Ajuste e clique em <strong>Confirmar estes horários</strong>. Até lá, a agenda interna segue sem trava de jornada.</p><?php endif; ?>
<p>Selecione os dias e horários em que atende. O intervalo para pausa é opcional. Horários no fuso <?= htmlspecialchars(getenv('APP_TIMEZONE')?:'America/Sao_Paulo') ?>.</p>
<input type="hidden" name="tab" value="agenda">
<input type="hidden" name="section" value="availability">
<input type="hidden" name="_csrf_token" value="<?= \ClyoNutri\Core\Security\Csrf::token() ?>">
<input type="hidden" name="revision" value="<?= $revision ?>">
<div class="u-grid u-cols-2 u-gap-4 u-mb-4">
  <div class="form-group"><label class="form-label" for="appointment-duration">Duração padrão da consulta (minutos)</label><input id="appointment-duration" class="form-control" name="default_duration_minutes" type="number" min="15" max="240" required value="<?= htmlspecialchars((string)$duration,ENT_QUOTES) ?>"></div>
  <div class="form-group"><label class="form-label" for="appointment-price">Valor padrão de referência (R$)</label><input id="appointment-price" class="form-control u-bg-neutral-gray-100" type="text" readonly value="R$ <?= number_format((float)$defaultPrice, 2, ',', '.') ?>"></div>
</div>
<?php foreach(['seg'=>'Segunda-feira','ter'=>'Terça-feira','qua'=>'Quarta-feira','qui'=>'Quinta-feira','sex'=>'Sexta-feira','sab'=>'Sábado','dom'=>'Domingo'] as $day=>$label): $row=is_array($weekly[$day]??null)?$weekly[$day]:[]; ?>
<fieldset class="availability-day">
<legend class="availability-day__name"><?= $label ?></legend>
<label class="availability-day__toggle"><input type="checkbox" name="weekly[<?= $day ?>][enabled]" value="1" <?= !empty($row['enabled'])?'checked':'' ?>> Atendo</label>
<div class="availability-day__times">
<?php foreach(['start'=>'Início','end'=>'Fim','break_start'=>'Início da pausa','break_end'=>'Fim da pausa'] as $field=>$fieldLabel): ?>
<div><label class="form-label" for="availability-<?= $day ?>-<?= $field ?>"><?= $fieldLabel ?></label><input class="form-control" type="time" id="availability-<?= $day ?>-<?= $field ?>" name="weekly[<?= $day ?>][<?= $field ?>]" value="<?= htmlspecialchars(is_string($row[$field]??null)?$row[$field]:'',ENT_QUOTES) ?>"></div>
<?php endforeach; ?>
</div></fieldset>
<?php endforeach; ?>
<p>Depois de salvar, novos agendamentos e alterações de horário devem respeitar esta disponibilidade. Consultas já agendadas são mantidas.</p>
<?php if($error&&($_POST['section']??'')==='availability'): ?><p class="op-message op-message--error" role="alert" data-testid="availability-error">Não salvo: <?= htmlspecialchars($error) ?></p><?php endif; ?>
<button type="submit" class="btn btn-primary"><?= !empty($legacyHoursPending)?'Confirmar estes horários':'Salvar disponibilidade' ?></button>
</form>
<?php elseif ($agendaSection === 'link'): ?>
<form class="card" method="POST" action="/configuracoes" id="booking-settings" data-testid="booking-settings">
<h2>Agendamento online</h2>
<p>Pacientes marcam consultas pelo seu link, só nos horários livres da disponibilidade da seção Horários. Cada horário fica reservado por alguns minutos enquanto o paciente preenche os dados; a consulta entra na sua agenda ao confirmar.</p>
<input type="hidden" name="tab" value="agenda">
<input type="hidden" name="section" value="booking">
<input type="hidden" name="_csrf_token" value="<?= \ClyoNutri\Core\Security\Csrf::token() ?>">
<?php $bs=$bookingSettings; $dis=$canManageBooking?'':'disabled'; ?>
<?php if(!$canManageBooking): ?><p role="note"><strong>Somente a gestão do consultório altera estas regras.</strong></p><?php endif; ?>
<label class="u-inline-flex u-items-center u-gap-2 u-mb-3 u-font-semibold"><input type="checkbox" name="booking_enabled" value="1" <?= $settingsRecovery->checked('booking','booking_enabled',!empty($bs['is_enabled']))?'checked':'' ?> <?= $dis ?>> Aceitar agendamento online</label>
<fieldset class="u-border-0 u-p-0 u-mt-0 u-mb-3 u-mx-0"><legend class="form-label">Modalidades oferecidas</legend>
<label class="u-inline-flex u-gap-1 u-mr-4"><input type="checkbox" name="booking_allow_presential" value="1" <?= $settingsRecovery->checked('booking','booking_allow_presential',!empty($bs['allow_presential']))?'checked':'' ?> <?= $dis ?>> Presencial</label>
<label class="u-inline-flex u-gap-1"><input type="checkbox" name="booking_allow_telehealth" value="1" <?= $settingsRecovery->checked('booking','booking_allow_telehealth',!empty($bs['allow_telehealth']))?'checked':'' ?> <?= $dis ?>> Teleconsulta</label></fieldset>
<div class="u-grid u-cols-auto-md u-gap-3">
<?php foreach(['min_advance_hours'=>['Antecedência mínima para marcar (horas)',0,168],'max_future_days'=>['Agenda aberta até (dias à frente)',1,365],'hold_ttl_minutes'=>['Tempo de reserva do horário (minutos)',5,60]] as $f=>[$lbl,$mn,$mx]): ?>
<div class="form-group"><label class="form-label" for="booking-<?= $f ?>"><?= $lbl ?></label><input class="form-control" type="number" id="booking-<?= $f ?>" name="booking_<?= $f ?>" min="<?= $mn ?>" max="<?= $mx ?>" required value="<?= htmlspecialchars((string)$settingsRecovery->value('booking','booking_'.$f,$bs[$f]),ENT_QUOTES) ?>" <?= $dis ?>></div>
<?php endforeach; ?>
</div>
<label class="u-flex u-gap-2 u-items-center u-mb-3"><input type="checkbox" name="booking_public_show_address" value="1" <?= $settingsRecovery->checked('booking','booking_public_show_address',!empty($bs['public_show_address']))?'checked':'' ?> <?= $dis ?>> Mostrar o endereço do consultório na página do link de agendamento</label>
<p class="text-muted ds-hint-tight u-text-compact">Desligado, o endereço só aparece para a paciente depois de marcar a consulta presencial.</p>
<?php if($bookingLink): ?><div class="form-group"><label class="form-label" for="booking-link">Seu link de agendamento</label><input class="form-control" id="booking-link" type="text" readonly value="<?= htmlspecialchars($bookingLink,ENT_QUOTES) ?>"><small><?= !empty($bs['is_enabled'])?'Envie este link aos pacientes.':'O link só funciona depois de ligar o agendamento online.' ?></small></div><?php endif; ?>
<?php if($canManageBooking): ?><button type="submit" class="btn btn-primary">Salvar agendamento online</button><?php endif; ?>
</form>
<?php else: ?>
<?php require __DIR__ . '/_calendar.php'; ?>
<?php endif; ?>
</section>
<?php endif; ?>

<?php if ($activeTab === 'paciente-app'): ?>
<section id="settings-patient" data-settings-section aria-label="Paciente">
<div class="cn-policy-summary"><h2>O que o paciente pode fazer hoje</h2><dl>
<?php foreach(['patient_can_confirm'=>'Confirmar presença','patient_can_cancel'=>'Cancelar','patient_can_request_reschedule'=>'Pedir remarcação'] as $flag=>$label): ?><div><dt><?= $label ?></dt><dd><?= !empty($bs[$flag])?'Habilitado':'Desabilitado' ?></dd></div><?php endforeach; ?>
<div><dt>Remarcar imediatamente</dt><dd><?= (new \ClyoNutri\Domain\Booking\PatientSelfService(\ClyoNutri\Core\Database\Connection::getInstance()))->policy(\ClyoNutri\Core\Auth\TenantContext::getClinicId())?'Habilitado':'Desabilitado' ?></dd></div>
</dl><p>Este resumo representa as regras salvas. Antecedência e disponibilidade continuam sendo verificadas em cada operação.</p></div>
<details class="op-settings-task" id="task-patient_actions" data-settings-task <?= ($communicationSection??'')==='patient_actions'?'open':'' ?>><summary><h2>Regras de consulta no aplicativo</h2></summary><form data-unsaved-form class="card" method="POST" action="/configuracoes" id="patient-actions-settings" data-testid="patient-actions-settings">
<h2>Consulta no app da paciente</h2>
<p>O que a paciente pode fazer com a próxima consulta pelo app e pelo portal. Cancelamentos aparecem na agenda; pedidos de remarcação ficam em <a href="/agenda/pedidos-remarcacao">Pedidos de remarcação</a> até você aceitar ou recusar.</p>
<input type="hidden" name="tab" value="paciente-app">
<input type="hidden" name="section" value="patient_actions">
<input type="hidden" name="_csrf_token" value="<?= \ClyoNutri\Core\Security\Csrf::token() ?>">
<?php if(!$canManageBooking): ?><p role="note"><strong>Somente a gestão do consultório altera estas regras.</strong></p><?php endif; ?>
<fieldset class="u-border-0 u-p-0 u-mt-0 u-mb-3 u-mx-0"><legend class="form-label">A paciente pode</legend>
<?php foreach(['patient_can_confirm'=>'Confirmar presença','patient_can_cancel'=>'Cancelar a consulta','patient_can_request_reschedule'=>'Pedir outro horário (você aceita ou recusa)'] as $f=>$lbl): ?>
<label class="u-flex u-gap-2 u-items-center u-mb-1"><input type="checkbox" name="<?= $f ?>" value="1" <?= $settingsRecovery->checked('patient_actions',$f,!empty($bs[$f]))?'checked':'' ?> <?= $dis ?>> <?= $lbl ?></label>
<?php endforeach; ?></fieldset>
<div class="u-grid u-cols-auto-md u-gap-3">
<?php foreach(['cancel_min_advance_hours'=>'Cancelar pelo app até (horas antes)','reschedule_min_advance_hours'=>'Pedir remarcação até (horas antes)'] as $f=>$lbl): ?>
<div class="form-group"><label class="form-label" for="booking-<?= $f ?>"><?= $lbl ?></label><input class="form-control" type="number" id="booking-<?= $f ?>" name="booking_<?= $f ?>" min="1" max="168" required value="<?= htmlspecialchars((string)$settingsRecovery->value('patient_actions','booking_'.$f,max(1,(int)$bs[$f])),ENT_QUOTES) ?>" <?= $dis ?>></div>
<?php endforeach; ?>
</div>
<label class="u-flex u-gap-2 u-items-center u-mb-3"><input type="checkbox" name="patient_show_address" value="1" <?= $settingsRecovery->checked('patient_actions','patient_show_address',!empty($bs['patient_show_address']))?'checked':'' ?> <?= $dis ?>> Mostrar o endereço do consultório na consulta presencial</label>
<?php if($canManageBooking): ?><button type="submit" class="btn btn-primary">Salvar regras do app</button><?php endif; ?>
</form></details>
<details class="op-settings-task" id="task-self_service" data-settings-task <?= ($communicationSection??'')==='self_service'?'open':'' ?>><summary><h2>Remarcação imediata</h2></summary><form data-unsaved-form class="card" method="POST" action="/configuracoes" id="self-service-settings"><h2>Remarcação imediata pelo paciente</h2><p>Quando habilitada, o paciente escolhe um horário livre e a agenda é atualizada sem aprovação da recepção. Antecedência, feriados, buffers e conflitos continuam valendo. Desligada por padrão; o fluxo de pedidos existente é preservado.</p><input type="hidden" name="tab" value="paciente-app"><input type="hidden" name="section" value="self_service"><input type="hidden" name="_csrf_token" value="<?= \ClyoNutri\Core\Security\Csrf::token() ?>"><label><input type="checkbox" name="direct_reschedule" value="1" <?= $settingsRecovery->checked('self_service','direct_reschedule',(new \ClyoNutri\Domain\Booking\PatientSelfService(\ClyoNutri\Core\Database\Connection::getInstance()))->policy(\ClyoNutri\Core\Auth\TenantContext::getClinicId()))?'checked':'' ?> <?= $dis ?>> Permitir remarcação imediata no portal e app</label><?php if($canManageBooking): ?><p><button class="btn btn-primary">Salvar remarcação imediata</button></p><a href="/agenda/indicadores-autoagendamento" target="_blank" rel="noopener">Consultar contadores de agendamento dos últimos 30 dias</a><?php endif; ?></form></details>
</section>
<section id="settings-communication" data-settings-section aria-label="Comunicação">
<details class="op-settings-task" id="task-chat" data-settings-task <?= ($communicationSection??'')==='chat'?'open':'' ?>><summary><h2>Regras do chat</h2></summary><form data-unsaved-form class="card" method="POST" action="/configuracoes" id="chat-settings" data-testid="chat-settings">
<h2>Chat com pacientes</h2>
<p>O app mostra à paciente o tempo de resposta que você escolher, sempre como estimativa (não é prazo garantido), e avisa que o chat não é canal de urgência.</p>
<input type="hidden" name="tab" value="paciente-app">
<input type="hidden" name="section" value="chat">
<input type="hidden" name="_csrf_token" value="<?= \ClyoNutri\Core\Security\Csrf::token() ?>">
<?php if(!$canManageBooking): ?><p role="note"><strong>Somente a gestão do consultório altera estas regras.</strong></p><?php endif; ?>
<?php $cs=$chatSettings??['response_time_label'=>null,'retention_years'=>20]; ?>
<div class="u-grid u-cols-auto-md u-gap-3">
<div class="form-group"><label class="form-label" for="chat-response-time">O consultório costuma responder</label>
<select class="form-control" id="chat-response-time" name="chat_response_time" <?= $dis ?>>
<option value="">Não informar (a paciente vê “sem prazo garantido”)</option>
<?php foreach(\ClyoNutri\Domain\Chat\ChatSettingsService::RESPONSE_TIMES as $rt): ?><option value="<?= htmlspecialchars($rt,ENT_QUOTES) ?>" <?= $settingsRecovery->value('chat','chat_response_time',$cs['response_time_label'])===$rt?'selected':'' ?>><?= htmlspecialchars($rt) ?></option><?php endforeach; ?>
</select></div>
<div class="form-group"><label class="form-label" for="chat-retention">Guardar mensagens e anexos por (anos)</label>
<input class="form-control" type="number" id="chat-retention" name="chat_retention_years" min="<?= \ClyoNutri\Domain\Chat\ChatSettingsService::MIN_RETENTION_YEARS ?>" max="<?= \ClyoNutri\Domain\Chat\ChatSettingsService::MAX_RETENTION_YEARS ?>" required value="<?= htmlspecialchars((string)$settingsRecovery->value('chat','chat_retention_years',$cs['retention_years']),ENT_QUOTES) ?>" <?= $dis ?> aria-describedby="chat-retention-help">
<small id="chat-retention-help">Padrão: 20 anos, o mesmo prazo do prontuário. Depois disso, a rotina diária apaga mensagens e arquivos.</small></div>
</div>
<?php if($canManageBooking): ?><button type="submit" class="btn btn-primary">Salvar regras do chat</button><?php endif; ?>
</form></details>
<?php if(!empty($notificationPrefs)): $np=$notificationPrefs; $npChat=array_values(array_filter($np['categories'],static fn($c)=>$c['category']==='chat'))[0]??['email_enabled'=>true];$npCheckin=array_values(array_filter($np['categories'],static fn($c)=>$c['category']==='checkin'))[0]??['email_enabled'=>true]; ?>
<details class="op-settings-task" id="task-notifications" data-settings-task <?= ($communicationSection??'')==='notifications'?'open':'' ?>><summary><h2>Seus avisos</h2></summary><form data-unsaved-form class="card" method="POST" action="/configuracoes" id="notification-settings" data-testid="notification-settings">
<h2>Seus avisos</h2>
<p>Mensagens de paciente aparecem no chat na hora, e cada check-in diário novo aparece em <a href="/avisos">Avisos</a>. Aqui você escolhe se também quer e-mail (sem o conteúdo da mensagem nem as respostas do check-in) e em que horário não quer ser incomodado.</p>
<input type="hidden" name="tab" value="paciente-app">
<input type="hidden" name="section" value="notifications">
<input type="hidden" name="_csrf_token" value="<?= \ClyoNutri\Core\Security\Csrf::token() ?>">
<label class="u-flex u-gap-2 u-items-center u-mb-3"><input type="checkbox" name="notify_chat_email" value="1" <?= $settingsRecovery->checked('notifications','notify_chat_email',!empty($npChat['email_enabled']))?'checked':'' ?>> Receber e-mail quando chegar mensagem nova</label>
<label class="u-flex u-gap-2 u-items-center u-mb-3"><input type="checkbox" name="notify_checkin_email" value="1" <?= $settingsRecovery->checked('notifications','notify_checkin_email',!empty($npCheckin['email_enabled']))?'checked':'' ?>> Receber e-mail quando chegar um novo check-in (no máximo um por dia)</label>
<label class="u-flex u-gap-2 u-items-center u-mb-2"><input type="checkbox" name="quiet_enabled" value="1" <?= $settingsRecovery->checked('notifications','quiet_enabled',!empty($np['quiet_hours']['enabled']))?'checked':'' ?>> Não mandar e-mail no horário de silêncio</label>
<div class="u-grid u-cols-auto-sm u-gap-3">
<div class="form-group"><label class="form-label" for="quiet-start">Silêncio a partir de</label><input class="form-control" type="time" id="quiet-start" name="quiet_start" value="<?= htmlspecialchars((string)$settingsRecovery->value('notifications','quiet_start',$np['quiet_hours']['start']),ENT_QUOTES) ?>"></div>
<div class="form-group"><label class="form-label" for="quiet-end">Até</label><input class="form-control" type="time" id="quiet-end" name="quiet_end" value="<?= htmlspecialchars((string)$settingsRecovery->value('notifications','quiet_end',$np['quiet_hours']['end']),ENT_QUOTES) ?>"></div>
</div>
<small>Horário de Brasília. Seus avisos também ficam em <a href="/avisos">Avisos</a>, no menu. O aviso no celular (push) é para as pacientes, pelo app.</small>
<div class="u-mt-3"><button type="submit" class="btn btn-primary">Salvar avisos</button></div>
</form></details>
<?php endif; ?>
<?php if(!empty($reminderSettings)): $rsCan=!empty($canManageBooking); $rsOwn=($bookingProfessionalId??0)>0; ?>
<?php if($rsCan||$rsOwn): ?>
<details class="op-settings-task" id="task-reminders" data-settings-task <?= ($communicationSection??'')==='reminders'?'open':'' ?>><summary><h2>Lembrete de consulta</h2></summary><form data-unsaved-form class="card" method="POST" action="/configuracoes" id="reminder-settings" data-testid="reminder-settings">
<h2>Lembrete de consulta</h2>
<p>A paciente recebe um aviso no app (e por e-mail ou no celular, se ela deixou ligado) antes da consulta. Escolha com quantas horas de antecedência, de 1 a 72.</p>
<input type="hidden" name="tab" value="paciente-app">
<input type="hidden" name="section" value="reminders">
<input type="hidden" name="_csrf_token" value="<?= \ClyoNutri\Core\Security\Csrf::token() ?>">
<div class="u-grid u-cols-auto-md u-gap-3">
<?php if($rsCan): ?>
<div class="form-group"><label class="form-label" for="reminder-clinic-hours">Padrão do consultório (horas antes)</label>
<input class="form-control" type="number" min="1" max="72" step="1" inputmode="numeric" id="reminder-clinic-hours" name="reminder_clinic_hours" placeholder="<?= (int)$reminderSettings['default'] ?>" value="<?= htmlspecialchars((string)($reminderSettings['posted_clinic'] ?? $reminderSettings['clinic'] ?? ''),ENT_QUOTES) ?>">
<small>Em branco, vale <?= (int)$reminderSettings['default'] ?> h.</small></div>
<?php endif; ?>
<?php if($rsOwn): ?>
<div class="form-group"><label class="form-label" for="reminder-own-hours">Para as suas consultas (horas antes)</label>
<input class="form-control" type="number" min="1" max="72" step="1" inputmode="numeric" id="reminder-own-hours" name="reminder_own_hours" placeholder="<?= (int)($reminderSettings['clinic'] ?? $reminderSettings['default']) ?>" value="<?= htmlspecialchars((string)($reminderSettings['posted_professional'] ?? $reminderSettings['professional'] ?? ''),ENT_QUOTES) ?>">
<small>Em branco, segue o padrão do consultório.</small></div>
<?php endif; ?>
</div>
<?php $rsEff=$reminderSettings['effective']; ?>
<p class="u-mt-2">Hoje o lembrete das suas consultas sai <strong><?= (int)$rsEff['hours'] ?> h antes</strong><?= $rsEff['source']==='professional'?' (seu ajuste)':($rsEff['source']==='clinic'?' (padrão do consultório)':' (padrão do sistema)') ?>. Mudanças valem para os próximos lembretes; quem já foi avisada não recebe de novo.</p>
<div class="u-mt-3"><button type="submit" class="btn btn-primary">Salvar lembrete</button></div>
</form></details>
<?php endif; endif; ?>
</section>
<?php endif; ?>

<?php if ($activeTab === 'conta'): ?>
<section id="settings-account" data-settings-section aria-label="Conta">
<?php if(!empty($planSummary)) require __DIR__ . '/_plano_resumo.php'; ?>
<form class="card" method="POST" action="/configuracoes">
<h2>Alterar senha</h2>
<input type="hidden" name="tab" value="conta">
<input type="hidden" name="section" value="password">
<input type="hidden" name="_csrf_token" value="<?= \ClyoNutri\Core\Security\Csrf::token() ?>">
<?php foreach(['current_password'=>'Senha atual','new_password'=>'Nova senha','password_confirmation'=>'Confirmar nova senha'] as $field=>$label): ?>
<div class="form-group"><label class="form-label" for="password-<?= $field ?>"><?= $label ?></label><input class="form-control" type="password" id="password-<?= $field ?>" name="<?= $field ?>" autocomplete="<?= $field==='current_password'?'current-password':'new-password' ?>" required <?= $field==='current_password'?'':'minlength="8" maxlength="72"' ?>></div>
<?php endforeach; ?>
<p>Use de 8 a 72 caracteres. Após a alteração, será necessário entrar novamente, inclusive nos outros dispositivos.</p>
<button type="submit" class="btn btn-primary">Alterar senha</button>
<p class="u-mt-4"><a href="/recuperar-senha">Esqueci minha senha atual</a></p>
</form>
</section>
<?php endif; ?>
</div>

<?php if (($activeTab ?? '') === 'agenda'): ?><p class="op-help" data-booking-state="<?= htmlspecialchars($bookingSetupState) ?>">Agendamento online: <strong><?= htmlspecialchars(\ClyoNutri\Domain\Booking\BookingSetupState::label($bookingSetupState)) ?></strong></p><?php endif; ?>

<script src="/assets/js/settings-sections.js?v=v54-26" defer></script>
