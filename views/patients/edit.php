<?php $escape = static fn($value) => htmlspecialchars((string)($value ?? ''), ENT_QUOTES, 'UTF-8'); ?>
<a href="/pacientes">← Voltar para Pacientes</a>
<h1 style="margin: 16px 0;">Editar paciente</h1>
<?php if ($errors): ?>
<div role="alert" style="color: #B91C1C; margin-bottom: 16px;"><?= $escape(implode(' ', $errors)) ?></div>
<?php endif; ?>
<div class="card" style="max-width: 720px;">
<form method="POST" action="/pacientes/<?= (int)$patient['id'] ?>/editar">
<input type="hidden" name="_csrf_token" value="<?= $escape(\ClyoNutri\Core\Security\Csrf::token()) ?>">
<?php foreach (['name' => ['Nome completo *', 'text'], 'email' => ['E-mail', 'email'], 'phone' => ['Telefone / WhatsApp', 'tel'], 'birth_date' => ['Data de nascimento', 'date']] as $field => [$label, $type]): ?>
<div class="form-group">
<label class="form-label" for="edit_<?= $field ?>"><?= $label ?></label>
<input class="form-control" id="edit_<?= $field ?>" name="<?= $field ?>" type="<?= $type ?>" value="<?= $escape($patient[$field]) ?>" <?= $field === 'name' ? 'required' : '' ?>>
</div>
<?php endforeach; ?>
<div class="form-group">
<label class="form-label" for="edit_gender">Gênero / Sexo biológico (opcional)</label>
<select class="form-control" id="edit_gender" name="gender" aria-describedby="edit_gender_help">
<option value="">Selecione...</option>
<?php foreach (['Feminino' => 'Feminino', 'Masculino' => 'Masculino', 'Outro' => 'Outro / Não informado', 'female' => 'Feminino', 'male' => 'Masculino', 'other' => 'Outro / Não informado'] as $value => $label): ?>
<option value="<?= $value ?>" <?= ($patient['gender'] ?? '') === $value ? 'selected' : '' ?>><?= $label ?></option>
<?php endforeach; ?>
</select>
<small id="edit_gender_help" style="display:block;font-size:0.75rem;color:var(--neutral-gray-500);margin-top:4px">No ClyoNutri, hoje é usado só para escolher os pontos de corte da relação cintura-quadril (OMS) na avaliação antropométrica; vazio ou &quot;Outro&quot; deixam essa classificação sem faixa. O sistema não deduz este dado de nenhum outro.</small>
</div>
<div class="form-group">
<label class="form-label" for="edit_notes">Observações</label>
<textarea class="form-control" id="edit_notes" name="notes" rows="4"><?= $escape($patient['notes']) ?></textarea>
</div>
<div style="display: flex; gap: 12px; flex-wrap: wrap;">
<a href="/pacientes" class="btn btn-secondary">Cancelar</a>
<button type="submit" class="btn btn-primary">Salvar alterações</button>
</div>
</form>
</div>

<?php if (($_GET['success'] ?? '') === 'status'): ?><p role="status">Status atualizado com sucesso.</p><?php endif; ?>
<?php if (in_array($patient['status'], ['active','inactive'], true)): ?>
<div class="card" style="max-width:720px;margin-top:20px">
<h2>Status do paciente: <?= $patient['status'] === 'active' ? 'Ativo' : 'Inativo' ?></h2>
<p>A inativação preserva o histórico e encerra o acesso ao app e os convites pendentes.</p>
<form method="post" action="/pacientes/<?= (int)$patient['id'] ?>/status">
<input type="hidden" name="_csrf_token" value="<?= $escape(\ClyoNutri\Core\Security\Csrf::token()) ?>">
<input type="hidden" name="status" value="<?= $patient['status'] === 'active' ? 'inactive' : 'active' ?>">
<label><input type="checkbox" name="confirm_status" value="yes" required> Confirmo a alteração de status deste paciente.</label>
<button class="btn btn-secondary" type="submit"><?= $patient['status'] === 'active' ? 'Inativar paciente' : 'Reativar paciente' ?></button>
</form></div><?php endif; ?>
