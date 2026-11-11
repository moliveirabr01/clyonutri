<fieldset data-editor-question class="card">
<legend>Pergunta</legend>
<input type="hidden" data-question-key name="questions[<?= $i ?>][key]" value="<?= $e($q['key'] ?: 'pergunta_' . $i) ?>">
<label>Texto <input class="form-control" name="questions[<?= $i ?>][text]" value="<?= $e($q['text']) ?>" required></label>
<details><summary>Ajuda, grupo e regras de exibição</summary><label>Ajuda <input class="form-control" name="questions[<?= $i ?>][help]" value="<?= $e($q['help'] ?? '') ?>"></label>
<label>Grupo <input class="form-control" name="questions[<?= $i ?>][group]" value="<?= $e($definition['sections'][$q['section'] ?? ''] ?? '') ?>"></label>
</details><label>Tipo <select class="form-control" name="questions[<?= $i ?>][type]"><?php foreach (['text'=>'Texto curto','textarea'=>'Texto longo','number'=>'Número','scale'=>'Escala','single'=>'Escolha única','multi'=>'Múltipla escolha','yesno'=>'Sim ou não','date'=>'Data'] as $type=>$label): ?><option value="<?= $type ?>" <?= $q['type']===$type ? 'selected' : '' ?>><?= $label ?></option><?php endforeach; ?></select></label>
<details><summary>Opções, limites e condições</summary><label>Opções (uma por linha, valor=rótulo) <textarea class="form-control" name="questions[<?= $i ?>][options_text]"><?= $e(implode("\n", array_map(static fn($k,$v)=>$k.'='.$v, array_keys($q['options'] ?? []), array_values($q['options'] ?? [])))) ?></textarea></label>
<label>Mínimo <input class="form-control" type="number" step="any" name="questions[<?= $i ?>][min]" value="<?= $e($q['min'] ?? '') ?>"></label>
<label>Máximo <input class="form-control" type="number" step="any" name="questions[<?= $i ?>][max]" value="<?= $e($q['max'] ?? '') ?>"></label>
<label>Exibir somente quando a pergunta <select class="form-control" data-dependency name="questions[<?= $i ?>][depends_on]" data-selected="<?= $e($q['condition']['question'] ?? '') ?>"><option value="">Sempre exibir</option></select></label>
<label>For igual ao valor <input class="form-control" name="questions[<?= $i ?>][depends_value]" value="<?= $e($q['condition']['equals'] ?? '') ?>"></label>
</details><label><input type="checkbox" name="questions[<?= $i ?>][required]" value="1" <?= !empty($q['required']) ? 'checked' : '' ?>> Obrigatória</label>
<label>Quem responde <select class="form-control" name="questions[<?= $i ?>][audience]"><option value="both">Paciente ou profissional</option><option value="professional" <?= ($q['audience'] ?? '')==='professional' ? 'selected' : '' ?>>Somente profissional</option></select></label>
<button class="btn btn-secondary" type="button" data-move-up>Subir pergunta</button>
<button class="btn btn-secondary" type="button" data-move-down>Descer pergunta</button>
<button class="btn btn-secondary" type="button" data-remove-question>Remover pergunta</button>
</fieldset>
