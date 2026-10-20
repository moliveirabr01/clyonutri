<?php
use ClyoNutri\Domain\PreConsultation\PreConsultationService;

$pageTitle = 'Questionário antes da consulta — Pré-consulta';
$clinicTitle = $ctx['clinic_name'];
$questions = $ctx['questions'];
$bySection = [];
foreach ($questions as $q) { $bySection[$q['section']][] = $q; }
$all = [];
foreach ($questions as $q) { $all[$q['id']] = $q; }
$errorCount = count($errors);
require __DIR__ . '/patient_head.php';
?>
  <div class="pc-card">
    <h1>Antes da sua consulta</h1>
    <p><?= $ctx['first_name'] !== '' ? $e($ctx['first_name']) . ', ' : '' ?>estas perguntas ajudam <?= $e($ctx['professional_name'] ?: 'seu nutricionista') ?> a preparar o atendimento. Responda com calma: você pode salvar e continuar depois pelo mesmo link até <?= $e(date('d/m/Y \à\s H:i', strtotime((string)$ctx['token_expires_at']))) ?>.</p>
    <p class="pc-small">Campos com <span aria-hidden="true">*</span><span class="pc-sr">asterisco</span> são obrigatórios para enviar. As respostas vão apenas para a clínica e não geram diagnóstico automático.</p>
  </div>

  <?php if ($notice && !$errorCount): ?><p class="pc-notice" role="status"><?= $e($notice) ?></p><?php endif; ?>
  <?php if ($errorCount): ?>
    <div class="pc-error-summary" role="alert" tabindex="-1" id="pc-error-summary">
      <h2>Faltam <?= $errorCount ?> resposta(s)</h2>
      <ul><?php foreach ($errors as $qid => $msg): ?><li><a href="#q-<?= $e($qid) ?>"><?= $e($qid === '_consent' ? 'Consentimento' : ($all[$qid]['text'] ?? $qid)) ?></a>: <?= $e($msg) ?></li><?php endforeach; ?></ul>
    </div>
  <?php endif; ?>

  <form method="POST" action="/pre-consulta/salvar" novalidate class="pc-form" data-pc-form>
    <input type="hidden" name="_csrf_token" value="<?= $e($csrf) ?>">
    <?php foreach ($bySection as $section => $items): ?>
      <section class="pc-card" aria-labelledby="sec-<?= $e($section) ?>">
        <h2 id="sec-<?= $e($section) ?>"><?= $e($sections[$section] ?? $section) ?></h2>
        <?php foreach ($items as $q):
            $id = $q['id'];
            $name = 'answers[' . $id . ']';
            $val = $answers[$id] ?? null;
            $err = $errors[$id] ?? null;
            $describedBy = trim(($err ? "err-{$id} " : '') . (!empty($q['condition']) ? "cond-{$id}" : ''));
            $condAttr = '';
            if (!empty($q['condition'])) {
                $condAttr = ' data-cond-q="' . $e($q['condition']['question']) . '"' . (isset($q['condition']['equals']) ? ' data-cond-eq="' . $e($q['condition']['equals']) . '"' : ' data-cond-inc="' . $e($q['condition']['includes']) . '"');
            }
            $req = !empty($q['required']);
            $star = $req ? ' <span class="pc-req" aria-hidden="true">*</span>' : '';
        ?>
          <div class="pc-question<?= $err ? ' pc-has-error' : '' ?>" id="q-<?= $e($id) ?>"<?= $condAttr ?>>
            <?php if (in_array($q['type'], ['single', 'yesno', 'multi'], true)): ?>
              <fieldset<?= $describedBy !== '' ? ' aria-describedby="' . $e($describedBy) . '"' : '' ?>>
                <legend class="pc-label"><?= $e($q['text']) ?><?= $star ?><?= $req ? '<span class="pc-sr"> (obrigatória)</span>' : '' ?></legend>
                <?php if (!empty($q['condition'])): ?><p class="pc-small" id="cond-<?= $e($id) ?>">Responda se aplicável à pergunta anterior: “<?= $e($all[$q['condition']['question']]['text'] ?? '') ?>”.</p><?php endif; ?>
                <?php if ($err): ?><p class="pc-error" id="err-<?= $e($id) ?>"><?= $e($err) ?></p><?php endif; ?>
                <?php foreach ($q['options'] as $ov => $ol):
                    $checked = $q['type'] === 'multi' ? (is_array($val) && in_array($ov, $val, true)) : ($val === $ov); ?>
                  <label class="pc-option">
                    <input type="<?= $q['type'] === 'multi' ? 'checkbox' : 'radio' ?>" name="<?= $e($name) ?><?= $q['type'] === 'multi' ? '[]' : '' ?>" value="<?= $e($ov) ?>" <?= $checked ? 'checked' : '' ?>>
                    <span><?= $e($ol) ?></span>
                  </label>
                <?php endforeach; ?>
              </fieldset>
            <?php else: ?>
              <label class="pc-label" for="in-<?= $e($id) ?>"><?= $e($q['text']) ?><?= $star ?><?= $req ? '<span class="pc-sr"> (obrigatória)</span>' : '' ?></label>
              <?php if (!empty($q['condition'])): ?><p class="pc-small" id="cond-<?= $e($id) ?>">Responda se aplicável à pergunta anterior: “<?= $e($all[$q['condition']['question']]['text'] ?? '') ?>”.</p><?php endif; ?>
              <?php if ($err): ?><p class="pc-error" id="err-<?= $e($id) ?>"><?= $e($err) ?></p><?php endif; ?>
              <?php $aria = ($err ? ' aria-invalid="true"' : '') . ($describedBy !== '' ? ' aria-describedby="' . $e($describedBy) . '"' : ''); ?>
              <?php if ($q['type'] === 'textarea'): ?>
                <textarea class="pc-input" id="in-<?= $e($id) ?>" name="<?= $e($name) ?>" rows="3" maxlength="3000"<?= $aria ?>><?= $e(is_scalar($val) ? $val : '') ?></textarea>
              <?php elseif ($q['type'] === 'number'): ?>
                <input class="pc-input pc-short" id="in-<?= $e($id) ?>" name="<?= $e($name) ?>" type="text" inputmode="decimal" value="<?= $e(is_scalar($val) ? $val : '') ?>"<?= $aria ?>>
              <?php else: ?>
                <input class="pc-input" id="in-<?= $e($id) ?>" name="<?= $e($name) ?>" type="text" maxlength="300" value="<?= $e(is_scalar($val) ? $val : '') ?>"<?= $aria ?>>
              <?php endif; ?>
            <?php endif; ?>
          </div>
        <?php endforeach; ?>
      </section>
    <?php endforeach; ?>

    <section class="pc-card" aria-labelledby="sec-consent">
      <h2 id="sec-consent">Envio</h2>
      <?php if (!empty($ctx['term'])): ?>
        <div class="pc-question<?= isset($errors['_consent']) ? ' pc-has-error' : '' ?>" id="q-_consent">
          <p class="pc-small"><?= $e($ctx['term']['term_text']) ?> <span class="pc-muted">(versão <?= $e($ctx['term']['version']) ?>)</span></p>
          <?php if (isset($errors['_consent'])): ?><p class="pc-error" id="err-consent"><?= $e($errors['_consent']) ?></p><?php endif; ?>
          <label class="pc-option"><input type="checkbox" name="consent" value="1"<?= isset($errors['_consent']) ? ' aria-describedby="err-consent" aria-invalid="true"' : '' ?>> <span>Li e concordo com o uso das minhas respostas no meu atendimento.</span></label>
        </div>
      <?php endif; ?>
      <div class="pc-actions">
        <button class="pc-btn pc-btn-secondary" type="submit" name="action" value="draft">Salvar e continuar depois</button>
        <button class="pc-btn" type="submit" name="action" value="submit">Enviar respostas</button>
      </div>
      <p class="pc-small">Depois de enviar, as respostas não podem ser alteradas por aqui.</p>
    </section>
  </form>
</main>
<script src="/assets/js/pre-consulta.js" defer></script>
</body>
</html>
