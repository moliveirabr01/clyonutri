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
<?php if($success): ?><p role="status" class="card">Configurações atualizadas com sucesso.</p><?php endif; ?>
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
<div class="form-group"><label class="form-label" for="profile-crn_region">Região do CRN</label><input class="form-control" id="profile-crn_region" name="crn_region" maxlength="10" required placeholder="Ex.: CRN-4" value="<?= htmlspecialchars($values['crn_region']??'',ENT_QUOTES) ?>"></div>
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
<div class="form-group"><label class="form-label" for="appointment-duration">Duração padrão da consulta (minutos)</label><input id="appointment-duration" class="form-control" name="default_duration_minutes" type="number" min="15" max="240" required value="<?= htmlspecialchars((string)$duration,ENT_QUOTES) ?>"></div>
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
