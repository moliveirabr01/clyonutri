<?php
// Read-only rendering: no clinical input or save form exists in this view.
$fields = [
    'objectives' => 'Objetivos & Metas', 'allergies' => 'Alergias Alimentares',
    'restrictions' => 'Restrições & Intolerâncias', 'routine' => 'Rotina Diária & Sono',
    'food_preferences' => 'Preferências Alimentares', 'symptoms' => 'Sinais & Sintomas',
    'habits' => 'Hábitos Alimentares & Vida', 'clinical_history' => 'Histórico Clínico & Patologias',
    'water_intake_ml' => 'Meta Hídrica (ml)', 'notes' => 'Observações',
];
?>
<section class="card" aria-label="Versão histórica da anamnese" data-testid="historical-anamnesis">
  <h2>Versão histórica v<?= (int)$selectedVersion['version'] ?> — Somente leitura</h2>
  <p>
    Registrada em <time><?= htmlspecialchars($selectedVersion['created_at']) ?></time>
    por <strong><?= htmlspecialchars($selectedVersion['professional_name']) ?></strong>.
    Estado: <span class="badge badge-<?= $selectedVersion['is_draft'] ? 'warning' : 'success' ?>"><?= $selectedVersion['is_draft'] ? 'Rascunho' : 'Finalizada' ?></span>
  </p>
  <p>Este documento preserva o conteúdo registrado nesta versão.</p>
  <div style="display: flex; gap: var(--space-3); margin-bottom: var(--space-6); flex-wrap: wrap;">
    <a class="btn btn-primary" href="/pacientes/<?= (int)$patient['id'] ?>/anamnese?revision_from=<?= (int)$selectedVersion['version'] ?>">Criar nova revisão a partir desta versão</a>
    <a class="btn btn-secondary" href="/pacientes/<?= (int)$patient['id'] ?>/anamnese">Voltar à coleta atual</a>
  </div>
  <p>Avaliação de alergias: <?= htmlspecialchars(!empty($selectedVersion['allergies']) ? 'Alergia registrada' : (empty($selectedVersion['is_draft']) && ($selectedVersion['allergy_assessment'] ?? '') === 'denied' ? 'Nega alergias' : 'Não avaliado')) ?></p>
  <dl>
    <?php foreach ($fields as $key => $label): ?>
      <dt style="font-weight: 600; color: var(--brand-navy-900); margin-top: var(--space-4);"><?= htmlspecialchars($label) ?></dt>
      <dd style="margin-top: var(--space-2); color: var(--neutral-ink-900);">
        <?= ($selectedVersion[$key] === null || $selectedVersion[$key] === '') ? 'Não informado nesta versão.' : nl2br(htmlspecialchars((string)$selectedVersion[$key])) ?>
      </dd>
    <?php endforeach; ?>
  </dl>
</section>
