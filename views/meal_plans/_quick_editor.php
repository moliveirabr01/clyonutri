<?php
$oqe=static fn($v)=>htmlspecialchars((string)$v,ENT_QUOTES,'UTF-8');
$workflow=(new \ClyoNutri\Domain\Clinical\WorkflowWrites($db))->state('plan',$planId);
?>
<section class="card" id="quick-plan-editor" data-quick-plan data-endpoint="/api/workflow/plan/<?= (int)$planId ?>" data-revision="<?= $oqe($workflow['revision']) ?>">
 <h2>Montar refeições</h2><p><?= $oqe($plan['title']) ?> · Rascunho v<?= (int)$plan['version'] ?>. Publicação exige revisão.</p>
 <?= \ClyoNutri\Application\View\OperationalUI::state($workflow['state'],$workflow['saved_at'],$workflow['resume_url']) ?>
 <p class="op-message op-message--error op-error" role="alert" data-quick-error hidden></p>
 <button type="button" class="op-button op-button--secondary" data-quick-retry hidden>Conferir e repetir a mesma operação</button>
 <form method="POST" action="/api/workflow/plan/<?= (int)$planId ?>" class="op-inline-form" data-quick-meal>
  <input type="hidden" name="_csrf_token" value="<?= $oqe($csrfToken) ?>"><input type="hidden" name="action" value="meal">
  <div class="op-form-grid"><label>Nome da refeição<input id="quick-meal-name" name="name" required maxlength="100" autocomplete="off"></label><label>Horário (opcional)<input id="quick-meal-time" name="time_of_day" type="time"></label><button class="op-button op-button--primary" type="submit">Adicionar refeição</button></div>
 </form>
 <form method="POST" action="/api/workflow/plan/<?= (int)$planId ?>" class="op-inline-form" data-quick-item data-unit-scope>
  <input type="hidden" name="_csrf_token" value="<?= $oqe($csrfToken) ?>"><input type="hidden" name="action" value="item"><input type="hidden" name="enforce_allergen_gate" value="1">
  <label>Refeição<select id="quick-meal-select" name="meal_id" required><option value="">Selecione uma refeição</option><?php foreach($plan['meals'] as $meal): ?><option value="<?= (int)$meal['id'] ?>"><?= $oqe($meal['name']) ?></option><?php endforeach; ?></select></label>
  <div data-food-picker data-plan-id="<?= (int)$planId ?>"><label for="quick-food-query">Buscar alimento</label><input type="search" id="quick-food-query" data-food-query data-transient maxlength="80" placeholder="Digite o nome do alimento"><p class="op-message op-message--info" role="status" aria-live="polite">Escolha um alimento do catálogo.</p><label for="quick-food-select" data-food-results-label>Alimento selecionado</label><select id="quick-food-select" name="food_id" required><option value="">Selecione um alimento</option></select><button type="button" data-food-next hidden>Mais resultados</button><button type="button" data-food-retry hidden>Tentar novamente</button></div>
  <div data-allergen-item-alert hidden></div>
  <div class="op-form-grid"><label>Quantidade<input id="quick-amount" name="amount" type="number" step="any" min="0.01" required></label><label>Medida<select name="measure_unit" id="quick-unit" required><?= \ClyoNutri\Application\Controllers\UnitCheckView::unitOptions() ?></select></label><button type="submit" class="op-button op-button--primary">Adicionar alimento</button></div>
  <?= \ClyoNutri\Application\Controllers\UnitCheckView::block('quick-confirm-estimated') ?>
  <details><summary>Orientação e revisão de alergias</summary><label>Orientação<input name="notes" maxlength="1000"></label><label><input type="checkbox" name="confirm_allergen_conflict" value="1"> Revisei o alerta específico de alergia</label><label>Justificativa clínica, se houver conflito<textarea name="allergen_ack_reason" minlength="10"></textarea></label></details>
 </form>
 <button type="button" class="op-button op-button--secondary" data-quick-undo hidden>Desfazer a última inclusão de alimento</button>
 <details><summary>Copiar refeição para outro horário</summary><p>Os alimentos, porções e substituições são revalidados. A cópia não estabelece equivalência clínica.</p><form method="POST" action="/api/workflow/plan/<?= (int)$planId ?>" class="op-inline-form op-stack" data-quick-copy><input type="hidden" name="_csrf_token" value="<?= $oqe($csrfToken) ?>"><input type="hidden" name="action" value="copy"><label>Refeição de origem<select name="meal_id" data-copy-meals required><option value="">Selecione</option><?php foreach($plan['meals'] as $meal): ?><option value="<?= (int)$meal['id'] ?>"><?= $oqe($meal['name']) ?></option><?php endforeach; ?></select></label><label>Nome da cópia<input name="name" required maxlength="100"></label><label>Novo horário<input type="time" name="time_of_day"></label><label><input type="checkbox" name="confirm_estimated" value="1"> Confirmo porções identificadas como estimadas</label><label><input type="checkbox" name="confirm_allergen_conflict" value="1"> Revisei eventuais conflitos específicos de alergias</label><label>Justificativa para conflitos<textarea name="allergen_ack_reason" minlength="10"></textarea></label><button class="op-button op-button--secondary" type="submit">Criar cópia para revisão</button></form></details>
</section>
