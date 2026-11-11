<?php $escape = static fn($value) => htmlspecialchars((string)($value ?? ''), ENT_QUOTES, 'UTF-8'); ?>
<a href="/pacientes">← Voltar para Pacientes</a>
<h1 class="u-my-4 u-mx-0">Editar paciente</h1>
<?php if ($errors): ?>
<div class="op-message op-message--error u-color-semantic-error u-mb-4" role="alert"><?= $escape(implode(' ', $errors)) ?></div>
<?php endif; ?>
<div class="card u-maxw-prose">
<form method="POST" action="/pacientes/<?= (int)$patient['id'] ?>/editar">
<input type="hidden" name="_csrf_token" value="<?= $escape(\ClyoNutri\Core\Security\Csrf::token()) ?>">
<?php foreach (['name' => ['Nome completo *', 'text'], 'email' => ['E-mail', 'email'], 'phone' => ['Telefone / WhatsApp', 'tel'], 'birth_date' => ['Data de nascimento', 'date']] as $field => [$label, $type]): ?>
<div class="form-group">
<label class="form-label" for="edit_<?= $field ?>"><?= $label ?></label>
<input class="form-control" id="edit_<?= $field ?>" name="<?= $field ?>" type="<?= $type ?>" value="<?= $escape($patient[$field]) ?>" <?= $field === 'name' ? 'required' : '' ?>>
</div>
<?php endforeach; ?>
<div class="form-group">
<label class="form-label" for="edit_gender">Sexo registrado para referências clínicas (opcional)</label>
<select class="form-control" id="edit_gender" name="gender" aria-describedby="edit_gender_help">
<option value="">Selecione...</option>
<?php $selectedGender = \ClyoNutri\Core\I18n\LabelMap::canonicalGender($patient['gender'] ?? null); /* UX-10 (v14): legado female/male/other cai na opção equivalente */ ?>
<?php foreach (\ClyoNutri\Core\I18n\LabelMap::GENDER_OPTIONS as $value => $label): ?>
<option value="<?= $value ?>" <?= $selectedGender === $value ? 'selected' : '' ?>><?= $label ?></option>
<?php endforeach; ?>
</select>
<small id="edit_gender_help" class="u-block u-text-caption u-color-neutral-gray-500 u-mt-1">O parâmetro usado nas equações não define a identidade de gênero. Quando confirmado pelo profissional, pode ser usado na classificação da RCQ e na elegibilidade de protocolos de dobras, junto com a idade. Sem os dados exigidos, a classificação ou equação fica indisponível; confirme o parâmetro no contexto da avaliação. Não o deduza da identidade de gênero.</small>
</div>
<div class="form-group">
<label class="form-label" for="edit_allergies">Alergias e intolerâncias alimentares</label>
<input class="form-control" id="edit_allergies" name="allergies" type="text" value="<?= $escape($patient['allergies'] ?? '') ?>" placeholder="Ex: amendoim, glúten, leite, frutos do mar...">
<label class="u-flex u-items-center u-gap-2 u-text-compact u-mt-1 u-pointer">
  <input type="checkbox" id="edit_allergy_denied" name="allergy_denied" value="1" <?= ($patient['allergy_assessment'] ?? '') === 'denied' ? 'checked' : '' ?> data-allergy-toggle="edit_allergies">
  <span>Nega alergias alimentares conhecidas</span>
</label>
</div>
<div class="form-group">
<label class="form-label" for="edit_notes">Observações</label>
<textarea class="form-control" id="edit_notes" name="notes" rows="4"><?= $escape($patient['notes']) ?></textarea>
</div>
<div class="u-flex u-gap-3 u-wrap">
<a href="/pacientes" class="btn btn-secondary">Cancelar</a>
<button type="submit" class="btn btn-primary">Salvar alterações</button>
</div>
</form>
</div>

<?php if (($_GET['success'] ?? '') === 'status'): ?><p class="op-message op-message--info" role="status">Status atualizado.</p><?php endif; ?>
<?php if (in_array($patient['status'], ['active','inactive'], true)): ?>
<div class="card u-maxw-prose u-mt-5">
<h2>Status do paciente: <?= $patient['status'] === 'active' ? 'Ativo' : 'Inativo' ?></h2>
<p>A inativação preserva o histórico e encerra o acesso ao app e os convites pendentes.</p>
<form method="post" action="/pacientes/<?= (int)$patient['id'] ?>/status">
<input type="hidden" name="_csrf_token" value="<?= $escape(\ClyoNutri\Core\Security\Csrf::token()) ?>">
<input type="hidden" name="status" value="<?= $patient['status'] === 'active' ? 'inactive' : 'active' ?>">
<label><input type="checkbox" name="confirm_status" value="yes" required> Confirmo a alteração de status deste paciente.</label>
<button class="btn btn-secondary" type="submit"><?= $patient['status'] === 'active' ? 'Inativar paciente' : 'Reativar paciente' ?></button>
</form></div><?php endif; ?>
