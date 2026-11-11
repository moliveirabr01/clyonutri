<?php
/**
 * Modelos de plano da clínica (NI2-514 fase 2, NI2-519).
 * Variáveis: $templates, $patients, $showArchived, $feedback, $error, $csrfToken
 * v43: $isManager (owner/admin aprova), $hasV43 (migração 63 aplicada), $currentUserId (autor pode mudar a visibilidade)
 */
$isManager = !empty($isManager);
$hasV43 = !empty($hasV43);
$currentUserId = (int)($currentUserId ?? 0);
$methodLabels = \ClyoNutri\Domain\MealPlan\MealPlanTemplateService::METHODS;
$fmt = static fn($v) => number_format((float)$v, 0, ',', '.');
$e = static fn($v) => htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
$own = array_values(array_filter($templates, fn($t) => !$t['is_global']));
$global = array_values(array_filter($templates, fn($t) => $t['is_global']));
?>
<div data-testid="plan-templates-page" class="u-maxw-page">
  <div class="u-mb-4">
    <a href="/planos" class="u-text-compact u-link">← Planos alimentares</a>
    <h1 class="u-text-h2 u-font-bold u-color-botanical-forest-900 u-my-1 u-mx-0">Modelos de plano</h1>
    <p class="u-color-neutral-gray-600 u-m-0 u-text-body u-leading-body">
      Um modelo guarda a estrutura de um plano: refeições, horários, alimentos, quantidades e substituições.
      Ele não guarda paciente nem anamnese. A meta de energia e macronutrientes só entra se você marcar ao salvar, e vira meta de referência no plano novo.
      Para criar um modelo, abra um plano e use “Salvar como modelo”. Usar um modelo cria um rascunho novo; o modelo continua igual.
      <?php if ($hasV43): ?>Modelos pessoais aparecem só para quem criou. O dono ou um administrador da clínica pode aprovar um modelo para a equipe.<?php endif; ?>
    </p>
  </div>

  <?php if ($error): ?>
    <div class="op-message op-message--error u-mb-4" role="alert"><?= $e($error) ?></div>
  <?php endif; ?>
  <?php if ($feedback): ?>
    <div class="op-message op-message--info u-mb-4" role="status"><?= $e($feedback) ?></div>
  <?php endif; ?>

  <p class="u-mt-0 u-mb-3 u-mx-0 u-text-compact">
    <?php if ($showArchived): ?>
      <a href="/modelos">Ver só os modelos ativos</a>
    <?php else: ?>
      <a href="/modelos?arquivados=1" data-testid="templates-show-archived">Ver também os arquivados</a>
    <?php endif; ?>
  </p>

  <?php if ($own === [] && $global === []): ?>
    <div class="card u-p-5" data-testid="templates-empty">
      <p class="u-mt-0 u-mb-2 u-mx-0 u-font-semibold">Nenhum modelo ainda.</p>
      <p class="u-m-0 u-color-neutral-gray-600">Monte um plano que você costuma repetir, abra-o e clique em “Salvar como modelo”. Na próxima vez, ele aparece aqui e na criação de um plano novo.</p>
    </div>
  <?php endif; ?>

  <?php foreach (['Da clínica' => $own, 'Globais (somente leitura)' => $global] as $groupLabel => $list): ?>
    <?php if ($list === []) { continue; } ?>
    <h2 class="u-text-card-title u-mt-4 u-mb-2 u-mx-0"><?= $e($groupLabel) ?></h2>
    <div class="u-grid u-gap-3">
      <?php foreach ($list as $t): ?>
        <?php $archived = ($t['status'] ?? 'active') === 'archived'; ?>
        <article class="card" data-testid="template-card" data-template-id="<?= (int)$t['id'] ?>" class="u-p-4<?= $archived ? ' ds-is-archived' : '' ?>">
          <div class="u-flex u-justify-between u-gap-3 u-wrap">
            <div class="u-minw-lg u-flex-1 u-basis-lg">
              <h3 class="u-mt-0 u-mb-1 u-mx-0 u-text-body u-color-botanical-forest-900">
                <?= $e($t['title']) ?>
                <?php if (!empty($t['is_clinic_default'])): ?><span class="badge badge-success u-ml-1 u-text-caption" data-testid="template-default-badge">Padrão da clínica</span><?php endif; ?>
                <?php if ($hasV43 && !$t['is_global'] && ($t['approval_status'] ?? '') === 'aprovado'): ?><span class="badge badge-success u-ml-1 u-text-caption" data-testid="template-approved-badge">Aprovado pela clínica</span><?php endif; ?>
                <?php if ($hasV43 && ($t['visibility'] ?? 'clinica') === 'pessoal'): ?><span class="badge u-ml-1 u-text-caption" data-testid="template-personal-badge">Pessoal</span><?php endif; ?>
                <?php if ($archived): ?><span class="badge u-ml-1 u-text-caption">Arquivado</span><?php endif; ?>
              </h3>
              <p class="u-mt-0 u-mb-1 u-mx-0 u-color-neutral-gray-600 u-text-compact">
                <?= $e($t['category']) ?> · <?= (int)$t['meal_count'] ?> <?= (int)$t['meal_count'] === 1 ? 'refeição' : 'refeições' ?> · <?= (int)$t['item_count'] ?> <?= (int)$t['item_count'] === 1 ? 'alimento' : 'alimentos' ?>
                <?php if ($t['target_energy_kcal'] !== null && (float)$t['target_energy_kcal'] > 0): ?>
                  · meta de referência <?= $e(number_format((float)$t['target_energy_kcal'], 0, ',', '.')) ?> kcal
                <?php endif; ?>
              </p>
              <?php if ($hasV43): ?>
                <p class="u-mt-0 u-mb-1 u-mx-0 u-text-compact" data-testid="template-method">Método: <?= $e($methodLabels[$t['plan_method']] ?? $t['plan_method']) ?></p>
                <?php $rt = $t['reference_target'] ?? null; if (is_array($rt) && (float)($rt['vet_kcal'] ?? 0) > 0): ?>
                  <p class="u-mt-0 u-mb-1 u-mx-0 u-text-compact u-color-neutral-gray-700" data-testid="template-reference-target-summary">
                    Meta de referência: <?= $e($fmt($rt['vet_kcal'])) ?> kcal<?php foreach (['protein_g' => 'P', 'carbohydrate_g' => 'C', 'lipid_g' => 'G'] as $k => $lbl): ?><?php if (isset($rt[$k]) && $rt[$k] !== null): ?> · <?= $lbl ?> <?= $e($fmt($rt[$k])) ?> g<?php endif; ?><?php endforeach; ?>
                  </p>
                <?php endif; ?>
              <?php endif; ?>
              <?php if ($t['meal_names'] !== []): ?>
                <p class="u-mt-0 u-mb-1 u-mx-0 u-text-compact"><?= $e(implode(' · ', $t['meal_names'])) ?></p>
              <?php endif; ?>
              <?php if (!empty($t['description'])): ?>
                <p class="u-mt-0 u-mb-1 u-mx-0 u-text-compact u-color-neutral-gray-700"><?= $e($t['description']) ?></p>
              <?php endif; ?>
              <p class="u-m-0 u-text-caption u-color-neutral-gray-500">
                <?php if (!empty($t['created_by_name'])): ?>Criado por <?= $e($t['created_by_name']) ?> · <?php endif; ?><?= $e(date('d/m/Y', strtotime((string)$t['created_at']))) ?>
                <?php if (!empty($t['source_plan_id'])): ?> · a partir do plano #<?= (int)$t['source_plan_id'] ?><?php endif; ?>
              </p>
            </div>
            <?php if (!$archived): ?>
            <div class="u-flex-0 u-basis-lg u-grid u-gap-2 u-content-start">
              <form method="POST" action="/modelos/<?= (int)$t['id'] ?>/usar" data-busy-text="Criando plano..." data-testid="template-use-form" class="u-grid u-gap-1">
                <input type="hidden" name="_csrf_token" value="<?= $e($csrfToken) ?>">
                <label for="tpl-patient-<?= (int)$t['id'] ?>" class="u-text-compact u-font-semibold">Paciente (opcional)</label>
                <select id="tpl-patient-<?= (int)$t['id'] ?>" name="patient_id" class="u-minh-touch u-py-0 u-px-2">
                  <option value="">Sem paciente por enquanto</option>
                  <?php foreach ($patients as $p): ?>
                    <option value="<?= (int)$p['id'] ?>"><?= $e($p['name']) ?></option>
                  <?php endforeach; ?>
                </select>
                <button type="submit" class="btn btn-primary u-minh-touch">Usar este modelo</button>
              </form>
              <?php if (!$t['is_global']): ?>
                <div class="u-flex u-gap-2 u-wrap">
                  <form method="POST" action="/modelos/<?= (int)$t['id'] ?>/padrao">
                    <input type="hidden" name="_csrf_token" value="<?= $e($csrfToken) ?>">
                    <input type="hidden" name="is_default" value="<?= !empty($t['is_clinic_default']) ? '0' : '1' ?>">
                    <button type="submit" class="btn btn-sm btn-outline"><?= !empty($t['is_clinic_default']) ? 'Deixar de ser padrão' : 'Usar como padrão da clínica' ?></button>
                  </form>
                  <?php if ($hasV43 && $isManager && ($t['visibility'] ?? 'clinica') === 'clinica'): ?>
                  <form method="POST" action="/modelos/<?= (int)$t['id'] ?>/aprovacao">
                    <input type="hidden" name="_csrf_token" value="<?= $e($csrfToken) ?>">
                    <input type="hidden" name="approved" value="<?= ($t['approval_status'] ?? '') === 'aprovado' ? '0' : '1' ?>">
                    <button type="submit" class="btn btn-sm btn-outline" data-testid="template-approve"><?= ($t['approval_status'] ?? '') === 'aprovado' ? 'Retirar aprovação' : 'Aprovar para a clínica' ?></button>
                  </form>
                  <?php endif; ?>
                  <?php if ($hasV43 && ($isManager || (int)($t['created_by_user_id'] ?? 0) === $currentUserId)): ?>
                  <form method="POST" action="/modelos/<?= (int)$t['id'] ?>/visibilidade">
                    <input type="hidden" name="_csrf_token" value="<?= $e($csrfToken) ?>">
                    <input type="hidden" name="visibility" value="<?= ($t['visibility'] ?? 'clinica') === 'pessoal' ? 'clinica' : 'pessoal' ?>">
                    <button type="submit" class="btn btn-sm btn-outline" data-testid="template-visibility"><?= ($t['visibility'] ?? 'clinica') === 'pessoal' ? 'Compartilhar com a clínica' : 'Tornar pessoal' ?></button>
                  </form>
                  <?php endif; ?>
                  <form method="POST" action="/modelos/<?= (int)$t['id'] ?>/arquivar" data-confirm="Arquivar este modelo? Ele sai da lista e não poderá mais ser usado. Os planos já criados não mudam.">
                    <input type="hidden" name="_csrf_token" value="<?= $e($csrfToken) ?>">
                    <button type="submit" class="btn btn-sm btn-outline" data-testid="template-archive">Arquivar</button>
                  </form>
                </div>
              <?php endif; ?>
            </div>
            <?php endif; ?>
          </div>
        </article>
      <?php endforeach; ?>
    </div>
  <?php endforeach; ?>
</div>
