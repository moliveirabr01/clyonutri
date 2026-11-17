<?php if(!empty($allergenSafety['requires_confirmation'])): ?>
<div class="op-message op-message--warning u-mb-4 u-text-compact" data-testid="allergen-confirmation-box">
  <?php if(!empty($allergenSafety['conflicts'])): ?>
    <strong>Conflito de alérgeno detectado:</strong>
    <ul data-testid="allergen-conflicts-list">
      <?php foreach($allergenSafety['conflicts'] as $food): ?>
      <li><?= htmlspecialchars((string)($food['food_name']??'Alimento'),ENT_QUOTES,'UTF-8') ?> — <?= htmlspecialchars((string)($food['allergen_group']??$food['matched_term']??'conflito'),ENT_QUOTES,'UTF-8') ?></li>
      <?php endforeach; ?>
    </ul>
  <?php endif; ?>
  <?php if(!empty($allergenSafety['unverified_foods'])): ?>
    <strong>Alimentos sem informação de alérgeno:</strong>
    <p>A ausência de informação no catálogo não confirma nem descarta a presença de alérgenos. Confira a composição e os rótulos.</p>
    <ul data-testid="allergen-unverified-list">
      <?php foreach($allergenSafety['unverified_foods'] as $food): ?>
      <li><?= htmlspecialchars(is_array($food)?(string)($food['food_name']??$food['name']??'Alimento'):(string)$food,ENT_QUOTES,'UTF-8') ?> (sem informação de alérgeno no catálogo)</li>
      <?php endforeach; ?>
    </ul>
  <?php endif; ?>
  <?php if(!empty($allergenSafety['unmapped_allergy_text'])): ?><p>A alergia informada ainda não foi relacionada aos alérgenos do catálogo. Confira os ingredientes antes de publicar.</p><?php endif; ?>
  <p>Revise os alimentos listados e registre a confirmação e a justificativa abaixo. A justificativa permanece na versão do plano e na auditoria clínica.</p>
</div>
<?php endif; ?>
