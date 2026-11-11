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
    <p><?= $ctx['first_name'] !== '' ? $e($ctx['first_name']) . ', ' : '' ?>estas perguntas ajudam <?= $e($ctx['professional_name'] ?: 'seu nutricionista') ?> a preparar o atendimento. Responda com calma: você pode salvar e continuar depois neste dispositivo até <?= $e(date('d/m/Y \à\s H:i', strtotime((string)$ctx['token_expires_at']))) ?>.</p>
    <p class="pc-small">Campos com <span aria-hidden="true">*</span><span class="pc-sr">asterisco</span> são obrigatórios para enviar. As respostas vão apenas para a clínica e não geram diagnóstico automático.</p>
  </div>

  <?php if ($notice && !$errorCount): ?><p class="op-message op-message--info pc-notice" role="status"><?= $e($notice) ?></p><?php endif; ?>
  <?php if ($errorCount): ?>
    <div class="op-message op-message--error pc-error-summary" role="alert" tabindex="-1" id="pc-error-summary">
      <h2>Faltam <?= $errorCount ?> resposta(s)</h2>
      <ul><?php foreach ($errors as $qid => $msg): ?><li><a href="#q-<?= $e($qid) ?>"><?= $e($qid === '_consent' ? 'Consentimento' : ($all[$qid]['text'] ?? $qid)) ?></a>: <?= $e($msg) ?></li><?php endforeach; ?></ul>
    </div>
  <?php endif; ?>

  <?php if (!empty($conflict)): ?><details class="pc-card" open><summary>Compare com as respostas já salvas</summary><p>O formulário mantém o que você tentou enviar. Confira as respostas salvas antes de tentar novamente.</p><dl><?php foreach ($ctx['questions'] as $savedQ): if (!isset($ctx['answers'][$savedQ['id']])) continue; ?><dt><?= $e($savedQ['text']) ?></dt><dd><?= $e(PreConsultationService::answerText($savedQ, $ctx['answers'][$savedQ['id']])) ?></dd><?php endforeach; ?></dl></details><?php endif; ?>
  <form method="POST" action="/pre-consulta/salvar" novalidate class="pc-form" data-pc-form>
    <input type="hidden" name="_csrf_token" value="<?= $e($csrf) ?>">
    <input type="hidden" name="answer_revision" value="<?= (int)($ctx['answer_revision'] ?? 0) ?>">
    <nav aria-label="Etapas do questionário" data-pc-navigation hidden><p class="op-message op-message--info" role="status" aria-live="polite" data-pc-progress></p><button class="pc-btn pc-btn-secondary" type="button" data-pc-prev>Etapa anterior</button> <button class="pc-btn pc-btn-secondary" type="button" data-pc-next>Próxima etapa</button></nav>
    <?php foreach ($bySection as $section => $items): ?>
      <section class="pc-card" data-pc-step aria-labelledby="sec-<?= $e($section) ?>">
        <h2 id="sec-<?= $e($section) ?>"><?= $e($sections[$section] ?? $section) ?></h2>
        <?php foreach ($items as $q):
            $id = $q['id'];
            $name = 'answers[' . $id . ']';
            $val = $answers[$id] ?? null;
            $err = $errors[$id] ?? null;
            $describedBy = trim(($err ? "err-{$id} " : '') . (!empty($q['condition']) ? "cond-{$id}" : ''));
            $condAttr = '';
            if (!empty($q['condition'])) {
                $condAttr = ' data-condition="' . $e(json_encode($q['condition'], JSON_UNESCAPED_UNICODE)) . '"';
            }
            $req = !empty($q['required']);
            $star = $req ? ' <span class="pc-req" aria-hidden="true">*</span>' : '';
        ?>
          <div class="pc-question<?= $err ? ' pc-has-error' : '' ?>" id="q-<?= $e($id) ?>"<?= $condAttr ?>>
            <?php if (in_array($q['type'], ['single', 'yesno', 'multi'], true)): ?>
              <fieldset<?= $describedBy !== '' ? ' aria-describedby="' . $e($describedBy) . '"' : '' ?>>
                <legend class="pc-label"><?= $e($q['text']) ?><?= $star ?><?= $req ? '<span class="pc-sr"> (obrigatória)</span>' : ' <span class="pc-small">(opcional)</span>' ?></legend>
                <?php if (!empty($q['condition'])): ?><p class="pc-small" id="cond-<?= $e($id) ?>">Responda se aplicável à pergunta anterior: “<?= $e($all[$q['condition']['question'] ?? '']['text'] ?? 'Respostas anteriores') ?>”.</p><?php endif; ?>
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
              <label class="pc-label" for="in-<?= $e($id) ?>"><?= $e($q['text']) ?><?= $star ?><?= $req ? '<span class="pc-sr"> (obrigatória)</span>' : ' <span class="pc-small">(opcional)</span>' ?></label>
              <?php if (!empty($q['condition'])): ?><p class="pc-small" id="cond-<?= $e($id) ?>">Responda se aplicável à pergunta anterior: “<?= $e($all[$q['condition']['question'] ?? '']['text'] ?? 'Respostas anteriores') ?>”.</p><?php endif; ?>
              <?php if ($err): ?><p class="pc-error" id="err-<?= $e($id) ?>"><?= $e($err) ?></p><?php endif; ?>
              <?php $aria = ($err ? ' aria-invalid="true"' : '') . ($describedBy !== '' ? ' aria-describedby="' . $e($describedBy) . '"' : ''); ?>
              <?php if ($q['type'] === 'textarea'): ?>
                <textarea class="pc-input" id="in-<?= $e($id) ?>" name="<?= $e($name) ?>" rows="3" maxlength="3000"<?= $aria ?>><?= $e(is_scalar($val) ? $val : '') ?></textarea>
              <?php elseif (in_array($q['type'], ['number','scale'], true)): ?>
                <input class="pc-input pc-short" id="in-<?= $e($id) ?>" name="<?= $e($name) ?>" type="text" inputmode="decimal" value="<?= $e(is_scalar($val) ? $val : '') ?>"<?= $aria ?>>
              <?php elseif ($q['type'] === 'date'): ?>
                <input class="pc-input" id="in-<?= $e($id) ?>" name="<?= $e($name) ?>" type="date" value="<?= $e(is_scalar($val) ? $val : '') ?>"<?= $aria ?>>
              <?php else: ?>
                <input class="pc-input" id="in-<?= $e($id) ?>" name="<?= $e($name) ?>" type="text" maxlength="300" value="<?= $e(is_scalar($val) ? $val : '') ?>"<?= $aria ?>>
              <?php endif; ?>
            <?php endif; ?>
          </div>
        <?php endforeach; ?>
      </section>
    <?php endforeach; ?>

    <section class="pc-card" data-pc-step aria-labelledby="sec-consent">
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
<script src="/assets/js/pre-consulta.js?v=v54-17" defer></script>
</body>
</html>
