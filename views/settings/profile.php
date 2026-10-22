<h1>Configurações</h1>
<p>Gerencie seu cadastro, identificação profissional, horários de atendimento, senha e assinatura.</p>

<!-- Navegação por abas em Configurações -->
<div style="display: flex; border-bottom: 2px solid var(--neutral-gray-200); margin-bottom: var(--space-6); gap: 1.5rem;">
  <a href="/configuracoes" style="padding: 10px 4px; text-decoration: none; color: var(--brand-copper-600); font-weight: 600; border-bottom: 2px solid var(--brand-copper-600); margin-bottom: -2px;">
    Perfil e Atendimento
  </a>
  <a href="/configuracoes/assinatura" style="padding: 10px 4px; text-decoration: none; color: var(--neutral-gray-600); font-weight: 500; border-bottom: 2px solid transparent; margin-bottom: -2px;">
    Assinatura &amp; Faturamento
  </a>
</div>

<?php if($error): ?><p role="alert" class="card"><?= htmlspecialchars($error) ?></p><?php endif; ?>
<?php if($success): ?><p role="status" class="card"><?= htmlspecialchars($successMessage ?? 'Configurações atualizadas com sucesso.') ?></p><?php endif; ?>
<div style="max-width: 960px;">
<form class="card" method="POST" action="/configuracoes" data-unsaved-form>
<h2>Dados cadastrais</h2>
<input type="hidden" name="section" value="profile">
<input type="hidden" name="_csrf_token" value="<?= \ClyoNutri\Core\Security\Csrf::token() ?>">
<input type="hidden" name="revision" value="<?= $revision ?>">
<?php foreach(['name'=>['Nome do profissional','text',120],'email'=>['E-mail de acesso','email',255],'phone'=>['Celular / WhatsApp','tel',30],'clinic_name'=>['Nome do consultório','text',150],'specialty'=>['Especialidade','text',100]] as $field=>[$label,$type,$max]): ?>
<div class="form-group"><label class="form-label" for="profile-<?= $field ?>"><?= $label ?></label><input class="form-control" id="profile-<?= $field ?>" name="<?= $field ?>" type="<?= $type ?>" maxlength="<?= $max ?>" value="<?= htmlspecialchars($values[$field]??'',ENT_QUOTES) ?>" <?= in_array($field,['name','email','clinic_name'])?'required':'' ?> <?= $field==='clinic_name'&&!in_array($profile['member_role'],['owner','admin'],true)?'readonly':'' ?>></div>
<?php endforeach; ?>
<label class="form-label" for="profile-bio">Apresentação profissional</label><textarea class="form-control" id="profile-bio" name="bio" maxlength="2000" rows="4"><?= htmlspecialchars($values['bio']??'') ?></textarea>
<fieldset style="border: 0; padding: 0; margin: 24px 0;">
<legend style="font-size: 1.2rem; font-weight: 700; margin-bottom: 16px;">Registro no Conselho (CRN) &amp; Identificação</legend>
<div style="display: grid; grid-template-columns: 1fr 1fr; gap: 16px;">
<div class="form-group"><label class="form-label" for="profile-crn">Número do CRN</label><input class="form-control" id="profile-crn" name="crn" maxlength="20" required value="<?= htmlspecialchars($values['crn']??'',ENT_QUOTES) ?>"></div>
<div class="form-group"><label class="form-label" for="profile-crn_region">Região do CRN</label>
<?php $crnOptions=['CRN-1'=>'CRN-1 (DF/GO/MT/TO)','CRN-2'=>'CRN-2 (RS)','CRN-3'=>'CRN-3 (SP/MS)','CRN-4'=>'CRN-4 (RJ/ES)','CRN-5'=>'CRN-5 (BA/SE)','CRN-6'=>'CRN-6 (PE/AL/PB/RN/CE/PI/MA)','CRN-7'=>'CRN-7 (PA/AM/AP/AC/RO/RR)','CRN-8'=>'CRN-8 (PR)','CRN-9'=>'CRN-9 (MG)','CRN-10'=>'CRN-10 (SC)','CRN-11'=>'CRN-11 (CE/PI/MA)']; $currentReg=strtoupper(trim($values['crn_region']??'')); ?>
<select class="form-control" id="profile-crn_region" name="crn_region" required>
  <option value="">Selecione a região...</option>
  <?php foreach($crnOptions as $val=>$lbl): ?>
    <option value="<?= $val ?>" <?= $currentReg===$val || str_starts_with($currentReg,$val)?'selected':'' ?>><?= $lbl ?></option>
  <?php endforeach; ?>
</select>
</div>
</div>
<p style="font-size: .875rem;">Alterações de e-mail ou CRN ficam pendentes de nova verificação cadastral.</p>
</fieldset>
<div class="form-group"><label class="form-label" for="profile-current-password">Senha atual para alterar o e-mail</label><input class="form-control" id="profile-current-password" name="current_password" type="password" autocomplete="current-password"><small>Preencha somente se estiver alterando o e-mail de acesso.</small></div>
<button type="submit" class="btn btn-primary">Salvar perfil</button>
</form>
<form class="card" method="POST" action="/configuracoes" data-unsaved-form>
<h2>Atendimento &amp; Disponibilidade Semanal</h2>
<p>Selecione os dias e horários em que atende. O intervalo para pausa é opcional. Horários no fuso <?= htmlspecialchars(getenv('APP_TIMEZONE')?:'America/Sao_Paulo') ?>.</p>
<input type="hidden" name="section" value="availability">
<input type="hidden" name="_csrf_token" value="<?= \ClyoNutri\Core\Security\Csrf::token() ?>">
<input type="hidden" name="revision" value="<?= $revision ?>">
<div style="display: grid; grid-template-columns: 1fr 1fr; gap: 16px; margin-bottom: 16px;">
  <div class="form-group"><label class="form-label" for="appointment-duration">Duração padrão da consulta (minutos)</label><input id="appointment-duration" class="form-control" name="default_duration_minutes" type="number" min="15" max="240" required value="<?= htmlspecialchars((string)$duration,ENT_QUOTES) ?>"></div>
  <div class="form-group"><label class="form-label" for="appointment-price">Valor padrão de referência (R$)</label><input id="appointment-price" class="form-control" type="text" readonly value="R$ <?= number_format((float)$defaultPrice, 2, ',', '.') ?>" style="background: var(--neutral-gray-100);"></div>
</div>
<?php foreach(['seg'=>'Segunda-feira','ter'=>'Terça-feira','qua'=>'Quarta-feira','qui'=>'Quinta-feira','sex'=>'Sexta-feira','sab'=>'Sábado','dom'=>'Domingo'] as $day=>$label): $row=is_array($weekly[$day]??null)?$weekly[$day]:[]; ?>
<fieldset style="border: 1px solid var(--neutral-gray-300); border-radius: 8px; padding: 16px; margin-bottom: 16px;">
<legend style="padding: 0 8px; font-weight: 600;"><?= $label ?></legend>
<label style="display: inline-flex; align-items: center; gap: 8px; margin-bottom: 12px;"><input type="checkbox" name="weekly[<?= $day ?>][enabled]" value="1" <?= !empty($row['enabled'])?'checked':'' ?>> Atendo neste dia</label>
<div style="display: grid; grid-template-columns: repeat(4,minmax(0,1fr)); gap: 12px;">
<?php foreach(['start'=>'Início','end'=>'Fim','break_start'=>'Início da pausa','break_end'=>'Fim da pausa'] as $field=>$fieldLabel): ?>
<div><label class="form-label" for="availability-<?= $day ?>-<?= $field ?>"><?= $fieldLabel ?></label><input class="form-control" type="time" id="availability-<?= $day ?>-<?= $field ?>" name="weekly[<?= $day ?>][<?= $field ?>]" value="<?= htmlspecialchars(is_string($row[$field]??null)?$row[$field]:'',ENT_QUOTES) ?>"></div>
<?php endforeach; ?>
</div></fieldset>
<?php endforeach; ?>
<p>Depois de salvar, novos agendamentos e alterações de horário devem respeitar esta disponibilidade. Consultas já agendadas são mantidas.</p>
<button type="submit" class="btn btn-primary">Salvar disponibilidade</button>
</form>
<form class="card" method="POST" action="/configuracoes" id="booking-settings" data-testid="booking-settings">
<h2>Agendamento online</h2>
<p>Pacientes marcam consultas pelo seu link, só nos horários livres da disponibilidade acima. Cada horário fica reservado por alguns minutos enquanto o paciente preenche os dados; a consulta entra na sua agenda ao confirmar.</p>
<input type="hidden" name="section" value="booking">
<input type="hidden" name="_csrf_token" value="<?= \ClyoNutri\Core\Security\Csrf::token() ?>">
<?php $bs=$bookingSettings; $dis=$canManageBooking?'':'disabled'; ?>
<?php if(!$canManageBooking): ?><p role="note"><strong>Somente a gestão do consultório altera estas regras.</strong></p><?php endif; ?>
<label style="display: inline-flex; align-items: center; gap: 8px; margin-bottom: 12px; font-weight: 600;"><input type="checkbox" name="booking_enabled" value="1" <?= !empty($bs['is_enabled'])?'checked':'' ?> <?= $dis ?>> Aceitar agendamento online</label>
<?php if(!empty($bs['is_enabled'])&&!$bookingHasAvailability): ?><p role="alert" class="card" style="border-left: 4px solid #B45309;">Ligado, mas sem dias de atendimento configurados: o link não mostra horários. Preencha a disponibilidade semanal acima.</p><?php endif; ?>
<fieldset style="border: 0; padding: 0; margin: 0 0 12px;"><legend class="form-label">Modalidades oferecidas</legend>
<label style="display: inline-flex; gap: 6px; margin-right: 16px;"><input type="checkbox" name="booking_allow_presential" value="1" <?= !empty($bs['allow_presential'])?'checked':'' ?> <?= $dis ?>> Presencial</label>
<label style="display: inline-flex; gap: 6px;"><input type="checkbox" name="booking_allow_telehealth" value="1" <?= !empty($bs['allow_telehealth'])?'checked':'' ?> <?= $dis ?>> Teleconsulta</label></fieldset>
<div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 12px;">
<?php foreach(['min_advance_hours'=>['Antecedência mínima para marcar (horas)',0,168],'max_future_days'=>['Agenda aberta até (dias à frente)',1,365],'hold_ttl_minutes'=>['Tempo de reserva do horário (minutos)',5,60]] as $f=>[$lbl,$mn,$mx]): ?>
<div class="form-group"><label class="form-label" for="booking-<?= $f ?>"><?= $lbl ?></label><input class="form-control" type="number" id="booking-<?= $f ?>" name="booking_<?= $f ?>" min="<?= $mn ?>" max="<?= $mx ?>" required value="<?= (int)$bs[$f] ?>" <?= $dis ?>></div>
<?php endforeach; ?>
</div>
<?php if($bookingLink): ?><div class="form-group"><label class="form-label" for="booking-link">Seu link de agendamento</label><input class="form-control" id="booking-link" type="text" readonly value="<?= htmlspecialchars($bookingLink,ENT_QUOTES) ?>"><small><?= !empty($bs['is_enabled'])?'Envie este link aos pacientes.':'O link só funciona depois de ligar o agendamento online.' ?></small></div><?php endif; ?>
<?php if($canManageBooking): ?><button type="submit" class="btn btn-primary">Salvar agendamento online</button><?php endif; ?>
</form>
<form class="card" method="POST" action="/configuracoes" id="patient-actions-settings" data-testid="patient-actions-settings">
<h2>Consulta no app da paciente</h2>
<p>O que a paciente pode fazer com a próxima consulta pelo app e pelo portal. Cancelamentos aparecem na agenda; pedidos de remarcação ficam em <a href="/agenda/pedidos-remarcacao">Pedidos de remarcação</a> até você aceitar ou recusar.</p>
<input type="hidden" name="section" value="patient_actions">
<input type="hidden" name="_csrf_token" value="<?= \ClyoNutri\Core\Security\Csrf::token() ?>">
<?php if(!$canManageBooking): ?><p role="note"><strong>Somente a gestão do consultório altera estas regras.</strong></p><?php endif; ?>
<fieldset style="border: 0; padding: 0; margin: 0 0 12px;"><legend class="form-label">A paciente pode</legend>
<?php foreach(['patient_can_confirm'=>'Confirmar presença','patient_can_cancel'=>'Cancelar a consulta','patient_can_request_reschedule'=>'Pedir outro horário (você aceita ou recusa)'] as $f=>$lbl): ?>
<label style="display: flex; gap: 8px; align-items: center; margin-bottom: 6px;"><input type="checkbox" name="<?= $f ?>" value="1" <?= !empty($bs[$f])?'checked':'' ?> <?= $dis ?>> <?= $lbl ?></label>
<?php endforeach; ?></fieldset>
<div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 12px;">
<?php foreach(['cancel_min_advance_hours'=>'Cancelar pelo app até (horas antes)','reschedule_min_advance_hours'=>'Pedir remarcação até (horas antes)'] as $f=>$lbl): ?>
<div class="form-group"><label class="form-label" for="booking-<?= $f ?>"><?= $lbl ?></label><input class="form-control" type="number" id="booking-<?= $f ?>" name="booking_<?= $f ?>" min="1" max="168" required value="<?= max(1,(int)$bs[$f]) ?>" <?= $dis ?>></div>
<?php endforeach; ?>
</div>
<label style="display: flex; gap: 8px; align-items: center; margin-bottom: 12px;"><input type="checkbox" name="patient_show_address" value="1" <?= !empty($bs['patient_show_address'])?'checked':'' ?> <?= $dis ?>> Mostrar o endereço do consultório na consulta presencial</label>
<?php if($canManageBooking): ?><button type="submit" class="btn btn-primary">Salvar regras do app</button><?php endif; ?>
</form>
<form class="card" method="POST" action="/configuracoes" id="chat-settings" data-testid="chat-settings">
<h2>Chat com pacientes</h2>
<p>O app mostra à paciente o tempo de resposta que você escolher, sempre como estimativa (não é prazo garantido), e avisa que o chat não é canal de urgência.</p>
<input type="hidden" name="section" value="chat">
<input type="hidden" name="_csrf_token" value="<?= \ClyoNutri\Core\Security\Csrf::token() ?>">
<?php if(!$canManageBooking): ?><p role="note"><strong>Somente a gestão do consultório altera estas regras.</strong></p><?php endif; ?>
<?php $cs=$chatSettings??['response_time_label'=>null,'retention_years'=>20]; ?>
<div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(220px, 1fr)); gap: 12px;">
<div class="form-group"><label class="form-label" for="chat-response-time">O consultório costuma responder</label>
<select class="form-control" id="chat-response-time" name="chat_response_time" <?= $dis ?>>
<option value="">Não informar (a paciente vê “sem prazo garantido”)</option>
<?php foreach(\ClyoNutri\Domain\Chat\ChatSettingsService::RESPONSE_TIMES as $rt): ?><option value="<?= htmlspecialchars($rt,ENT_QUOTES) ?>" <?= $cs['response_time_label']===$rt?'selected':'' ?>><?= htmlspecialchars($rt) ?></option><?php endforeach; ?>
</select></div>
<div class="form-group"><label class="form-label" for="chat-retention">Guardar mensagens e anexos por (anos)</label>
<input class="form-control" type="number" id="chat-retention" name="chat_retention_years" min="<?= \ClyoNutri\Domain\Chat\ChatSettingsService::MIN_RETENTION_YEARS ?>" max="<?= \ClyoNutri\Domain\Chat\ChatSettingsService::MAX_RETENTION_YEARS ?>" required value="<?= (int)$cs['retention_years'] ?>" <?= $dis ?> aria-describedby="chat-retention-help">
<small id="chat-retention-help">Padrão: 20 anos, o mesmo prazo do prontuário. Depois disso, a rotina diária apaga mensagens e arquivos.</small></div>
</div>
<?php if($canManageBooking): ?><button type="submit" class="btn btn-primary">Salvar regras do chat</button><?php endif; ?>
</form>
<?php if(!empty($notificationPrefs)): $np=$notificationPrefs; $npChat=array_values(array_filter($np['categories'],static fn($c)=>$c['category']==='chat'))[0]??['email_enabled'=>true];$npCheckin=array_values(array_filter($np['categories'],static fn($c)=>$c['category']==='checkin'))[0]??['email_enabled'=>true]; ?>
<form class="card" method="POST" action="/configuracoes" id="notification-settings" data-testid="notification-settings">
<h2>Seus avisos</h2>
<p>Mensagens de paciente aparecem no chat na hora, e cada check-in diário novo aparece em <a href="/avisos">Avisos</a>. Aqui você escolhe se também quer e-mail (sem o conteúdo da mensagem nem as respostas do check-in) e em que horário não quer ser incomodado.</p>
<input type="hidden" name="section" value="notifications">
<input type="hidden" name="_csrf_token" value="<?= \ClyoNutri\Core\Security\Csrf::token() ?>">
<label style="display: flex; gap: 8px; align-items: center; margin-bottom: 12px;"><input type="checkbox" name="notify_chat_email" value="1" <?= !empty($npChat['email_enabled'])?'checked':'' ?>> Receber e-mail quando chegar mensagem nova</label>
<label style="display: flex; gap: 8px; align-items: center; margin-bottom: 12px;"><input type="checkbox" name="notify_checkin_email" value="1" <?= !empty($npCheckin['email_enabled'])?'checked':'' ?>> Receber e-mail quando uma paciente enviar check-in (no máximo um por dia)</label>
<label style="display: flex; gap: 8px; align-items: center; margin-bottom: 8px;"><input type="checkbox" name="quiet_enabled" value="1" <?= !empty($np['quiet_hours']['enabled'])?'checked':'' ?>> Não mandar e-mail no horário de silêncio</label>
<div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(160px, 1fr)); gap: 12px;">
<div class="form-group"><label class="form-label" for="quiet-start">Silêncio a partir de</label><input class="form-control" type="time" id="quiet-start" name="quiet_start" value="<?= htmlspecialchars((string)$np['quiet_hours']['start'],ENT_QUOTES) ?>"></div>
<div class="form-group"><label class="form-label" for="quiet-end">Até</label><input class="form-control" type="time" id="quiet-end" name="quiet_end" value="<?= htmlspecialchars((string)$np['quiet_hours']['end'],ENT_QUOTES) ?>"></div>
</div>
<small>Horário de Brasília. Seus avisos também ficam em <a href="/avisos">Avisos</a>, no menu. O aviso no celular (push) é para as pacientes, pelo app.</small>
<div style="margin-top: 12px;"><button type="submit" class="btn btn-primary">Salvar avisos</button></div>
</form>
<?php endif; ?>
<?php if(!empty($reminderSettings)): $rsCan=!empty($canManageBooking); $rsOwn=($bookingProfessionalId??0)>0; ?>
<?php if($rsCan||$rsOwn): ?>
<form class="card" method="POST" action="/configuracoes" id="reminder-settings" data-testid="reminder-settings">
<h2>Lembrete de consulta</h2>
<p>A paciente recebe um aviso no app (e por e-mail ou no celular, se ela deixou ligado) antes da consulta. Escolha com quantas horas de antecedência, de 1 a 72.</p>
<input type="hidden" name="section" value="reminders">
<input type="hidden" name="_csrf_token" value="<?= \ClyoNutri\Core\Security\Csrf::token() ?>">
<div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(220px, 1fr)); gap: 12px;">
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
<p style="margin-top: 8px;">Hoje o lembrete das suas consultas sai <strong><?= (int)$rsEff['hours'] ?> h antes</strong><?= $rsEff['source']==='professional'?' (seu ajuste)':($rsEff['source']==='clinic'?' (padrão do consultório)':' (padrão do sistema)') ?>. Mudanças valem para os próximos lembretes; quem já foi avisada não recebe de novo.</p>
<div style="margin-top: 12px;"><button type="submit" class="btn btn-primary">Salvar lembrete</button></div>
</form>
<?php endif; endif; ?>
<form class="card" method="POST" action="/configuracoes">
<h2>Alterar senha</h2>
<input type="hidden" name="section" value="password">
<input type="hidden" name="_csrf_token" value="<?= \ClyoNutri\Core\Security\Csrf::token() ?>">
<?php foreach(['current_password'=>'Senha atual','new_password'=>'Nova senha','password_confirmation'=>'Confirmar nova senha'] as $field=>$label): ?>
<div class="form-group"><label class="form-label" for="password-<?= $field ?>"><?= $label ?></label><input class="form-control" type="password" id="password-<?= $field ?>" name="<?= $field ?>" autocomplete="<?= $field==='current_password'?'current-password':'new-password' ?>" required <?= $field==='current_password'?'':'minlength="8" maxlength="72"' ?>></div>
<?php endforeach; ?>
<p>Use de 8 a 72 caracteres. Após a alteração, será necessário entrar novamente, inclusive nos outros dispositivos.</p>
<button type="submit" class="btn btn-primary">Alterar senha</button>
<p style="margin-top: 16px;"><a href="/recuperar-senha">Esqueci minha senha atual</a></p>
</form>
</div>
