<?php
$pageTitle = 'Confirme sua identidade — Pré-consulta';
$clinicTitle = $ctx['clinic_name'];
require __DIR__ . '/patient_head.php';
?>
  <div class="pc-card">
    <h1>Olá<?= $ctx['first_name'] !== '' ? ', ' . $e($ctx['first_name']) : '' ?></h1>
    <p><?= $e($ctx['professional_name'] ?: 'Seu nutricionista') ?> pediu que você responda algumas perguntas antes da consulta.</p>
    <p>Para proteger suas informações, confirme sua data de nascimento.</p>
    <?php if (!empty($error)): ?>
      <p class="pc-error" role="alert" id="pc-verify-error"><?= $e($error) ?></p>
    <?php endif; ?>
    <form method="POST" action="/pre-consulta/verificar" novalidate>
      <input type="hidden" name="_csrf_token" value="<?= $e($csrf) ?>">
      <label class="pc-label" for="pc-birth">Data de nascimento</label>
      <input class="pc-input" id="pc-birth" name="birth_date" type="date" required autocomplete="bday" <?= !empty($error) ? 'aria-invalid="true" aria-describedby="pc-verify-error"' : '' ?>>
      <button class="pc-btn" type="submit">Continuar</button>
    </form>
  </div>
</main>
</body>
</html>
