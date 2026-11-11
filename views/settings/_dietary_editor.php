<details class="card" <?= !empty($definition['dietary_condition'])?'open':'' ?>>
 <summary>Sugestões dietéticas vinculadas a este protocolo</summary>
 <p>O conteúdo segue a revisão clínica e a versão do protocolo. A inclusão no plano exige outra confirmação específica do nutricionista. Não inclua medicamentos ou metas universais.</p>
 <label>Comorbidade
  <select name="dietary_condition" class="form-control">
   <option value="">Sem sugestões dietéticas</option>
   <?php foreach(\ClyoNutri\Domain\Dietary\DietaryDefinition::CONDITIONS as $key=>$label):?><option value="<?= $e($key) ?>" <?= ($definition['dietary_condition']??'')===$key?'selected':'' ?>><?= $e($label) ?></option><?php endforeach;?>
  </select>
 </label>
 <?php $dietRows=$definition['dietary_suggestions']??[];if(count($dietRows)<5)$dietRows[]=[];foreach($dietRows as $di=>$row):?>
 <fieldset style="min-width:0;padding:1rem;margin:1rem 0"><legend>Orientação <?= $di+1 ?></legend>
  <input type="hidden" name="dietary[<?= $di ?>][key]" value="<?= $e($row['key']??'orientacao_'.($di+1)) ?>">
  <label>Título <input class="form-control" name="dietary[<?= $di ?>][title]" maxlength="160" value="<?= $e($row['title']??'') ?>"></label>
  <label>Texto proposto para o paciente <textarea class="form-control" name="dietary[<?= $di ?>][guidance]" maxlength="1500" rows="3"><?= $e($row['guidance']??'') ?></textarea></label>
  <label>Motivo e limites clínicos <textarea class="form-control" name="dietary[<?= $di ?>][rationale]" maxlength="1000" rows="3"><?= $e($row['rationale']??'') ?></textarea></label>
  <label>Fonte HTTPS <input type="url" class="form-control" name="dietary[<?= $di ?>][source_url]" value="<?= $e($row['source_url']??'') ?>"></label>
  <label style="display:flex;align-items:center;gap:0.5rem;min-height:44px;cursor:pointer;"><input type="checkbox" name="dietary[<?= $di ?>][review_only]" <?= !empty($row['review_only'])?'checked':'' ?> style="width:18px;height:18px;accent-color:var(--botanical-green-600,#0F3E17);"> Somente avaliação individual; impedir inclusão pelo fluxo de sugestões</label>
  <fieldset><legend>Impedir inclusão quando houver também</legend>
   <?php foreach(\ClyoNutri\Domain\Dietary\DietaryDefinition::CONDITIONS as $key=>$label):?><label style="display:flex;align-items:center;gap:0.5rem;min-height:36px;cursor:pointer;"><input type="checkbox" name="dietary[<?= $di ?>][exclude_conditions][]" value="<?= $e($key) ?>" <?= in_array($key,$row['exclude_conditions']??[],true)?'checked':'' ?> style="width:18px;height:18px;accent-color:var(--botanical-green-600,#0F3E17);"> <?= $e($label) ?></label><?php endforeach;?>
  </fieldset>
  <fieldset><legend>Impedir inclusão para estes grupos de alergia ou restrição</legend>
   <?php foreach(\ClyoNutri\Domain\Clinical\AllergenSafetyValidator::groupKeys() as $group):?><label style="display:flex;align-items:center;gap:0.5rem;min-height:36px;cursor:pointer;"><input type="checkbox" name="dietary[<?= $di ?>][exclude_allergens][]" value="<?= $e($group) ?>" <?= in_array($group,$row['exclude_allergens']??[],true)?'checked':'' ?> style="width:18px;height:18px;accent-color:var(--botanical-green-600,#0F3E17);"> <?= $e($group==='lactose'?'Leite e lactose':ucfirst($group)) ?></label><?php endforeach;?>
  </fieldset>
 </fieldset>
 <?php endforeach;?>
</details>
