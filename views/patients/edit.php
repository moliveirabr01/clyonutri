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
<label class="form-label" for="edit_gender">Gênero / Sexo biológico</label>
<select class="form-control" id="edit_gender" name="gender">
<option value="">Selecione...</option>
<?php foreach (['Feminino' => 'Feminino', 'Masculino' => 'Masculino', 'Outro' => 'Outro / Não informado', 'female' => 'Feminino', 'male' => 'Masculino', 'other' => 'Outro / Não informado'] as $value => $label): ?>
<option value="<?= $value ?>" <?= ($patient['gender'] ?? '') === $value ? 'selected' : '' ?>><?= $label ?></option>
<?php endforeach; ?>
</select>
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
