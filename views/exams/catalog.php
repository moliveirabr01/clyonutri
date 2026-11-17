<?php
/**
 * Catálogo de exames laboratoriais (v47, NI2-532). Sem script inline (CSP).
 * @var array $catalog @var int[] $favoriteIds @var array $custom @var bool $isManager @var ?array $flash
 * @var string $csrfToken @var array $old @var ?string $error @var ?string $eligibilityError
 */
$e = static fn($v) => htmlspecialchars((string)($v ?? ''), ENT_QUOTES, 'UTF-8');
$fav = array_flip(array_map('intval', $favoriteIds));
$valLabel = ['pending' => 'Aguardando validação', 'approved' => 'Validado', 'rejected' => 'Recusado'];
$valBadge = ['pending' => 'badge-warning', 'approved' => 'badge-success', 'rejected' => 'badge-danger'];
$fmt = static fn($v) => $v ? date('d/m/Y H:i', strtotime((string)$v)) : '';
// NI2-891: conta só o catálogo geral (os exames da clínica têm validação própria) e o aviso aparece só para dona/administradora.
$pendingReview = !empty($isManager) ? count(array_filter($catalog, fn($x) => ($x['origin'] ?? 'curated') !== 'clinic_custom' && ($x['clinical_review_status'] ?? 'pending') !== 'reviewed')) : 0;
?>
<link rel="stylesheet" href="/assets/css/exames.css?v=v54-68">
<div class="clinical-journey ex-page">
  <a class="ex-back" href="/pacientes">← Voltar aos pacientes</a>
  <div class="ex-head">
    <div>
      <h1 class="ex-title">Catálogo de exames laboratoriais</h1>
      <p class="ex-sub">Exames que podem entrar em uma solicitação para acompanhamento nutricional. <a href="/exames/politica">Ver a política de solicitação</a></p>
    </div>
  </div>

  <?php if ($flash): ?>
    <div class="ex-msg <?= $flash['type'] === 'error' ? 'ex-msg-error' : 'ex-msg-success' ?>" role="<?= $flash['type'] === 'error' ? 'alert' : 'status' ?>"><?= $e($flash['message']) ?></div>
  <?php endif; ?>
  <?php if ($pendingReview > 0): ?>
    <div class="ex-msg ex-msg-info" role="note" data-testid="exam-catalog-review-notice"><?= $e(\ClyoNutri\Application\View\UserText::count((int)$pendingReview, '1 exame do catálogo geral aguarda', '{n} exames do catálogo geral aguardam')) ?> a revisão do nutricionista responsável técnico do Clyo Nutri<?= !empty($catalogRtName) ? ', <span data-testid="exam-catalog-rt">' . $e($catalogRtName) . '</span>' : '' ?>. Até lá, a coluna Código fica em branco para esses exames. Aviso visível só para a dona ou administradora da clínica.</div>
  <?php endif; ?>

  <section class="card ex-card" aria-labelledby="cat-titulo">
    <h2 id="cat-titulo">Exames disponíveis (<?= count($catalog) ?>)</h2>
    <p class="ex-muted">A estrela marca um favorito seu: ele aparece primeiro no editor, mas nunca vem marcado. Cada solicitação continua exigindo escolha e justificativa caso a caso.</p>
    <div class="table-responsive">
      <table class="table ex-table">
        <thead><tr><th>Exame</th><th>Categoria · material</th><th>Código</th><th>Origem</th><th class="text-right">Favorito</th></tr></thead>
        <tbody>
        <?php foreach ($catalog as $x): $id = (int)$x['id']; $isFav = isset($fav[$id]); ?>
          <tr id="exame-<?= $id ?>">
            <td><strong><?= $e($x['canonical_name']) ?></strong><?php if (!empty($x['synonyms'])): ?><br><span class="ex-muted">Sinônimos: <?= $e($x['synonyms']) ?></span><?php endif; ?></td>
            <td><?= $e($x['category']) ?><br><span class="ex-muted"><?= $e($x['sample_type']) ?><?= !empty($x['fasting_hours']) ? ' · jejum ' . (int)$x['fasting_hours'] . 'h' : '' ?></span></td>
            <td><?= !empty($x['standard_code']) ? $e($x['standard_code_system'] . ' ' . $x['standard_code']) : (!empty($x['standard_code_note']) ? '<span class="ex-muted">Sem código: ' . $e($x['standard_code_note']) . '</span>' : '<span class="ex-muted">—</span>') ?></td>
            <td><?= ($x['origin'] ?? 'curated') === 'clinic_custom' ? '<span class="badge badge-neutral">Da clínica</span>' : '<span class="ex-muted">Catálogo geral</span>' ?></td>
            <td class="text-right">
              <form method="POST" action="/exames/catalogo/favoritos/<?= $id ?>" data-no-dirty>
                <input type="hidden" name="_csrf_token" value="<?= $e($csrfToken) ?>">
                <button type="submit" class="btn btn-outline ex-fav-btn" aria-pressed="<?= $isFav ? 'true' : 'false' ?>" aria-label="<?= $isFav ? 'Favorito — retirar dos favoritos' : 'Favoritar' ?>: <?= $e($x['canonical_name']) ?>"<?= $eligibilityError ? ' disabled' : '' ?>><?= $isFav ? '★ Favorito' : 'Favoritar' ?></button>
              </form>
            </td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </section>

  <section class="card ex-card" id="personalizados" aria-labelledby="pers-titulo">
    <h2 id="pers-titulo">Exames da clínica</h2>
    <p class="ex-muted">Precisa de um exame laboratorial que não está no catálogo? Proponha aqui. Ele só entra em solicitações depois que a responsável pela clínica (proprietária ou administradora) validar. Exames de imagem, endoscopias, biópsias e outros procedimentos não laboratoriais são recusados automaticamente.</p>

    <?php if ($custom): ?>
      <div class="table-responsive">
        <table class="table ex-table">
          <thead><tr><th>Exame</th><th>Proposto por</th><th>Situação</th><?php if ($isManager): ?><th class="text-right">Validação</th><?php endif; ?></tr></thead>
          <tbody>
          <?php foreach ($custom as $x): $st = (string)$x['validation_status']; ?>
            <tr>
              <td><strong><?= $e($x['canonical_name']) ?></strong><br><span class="ex-muted"><?= $e($x['category']) ?> · <?= $e($x['sample_type']) ?></span><br><span class="ex-muted">Pertinência: <?= $e($x['nutritional_indication']) ?></span></td>
              <td><?= $e($x['submitted_by_name']) ?><br><span class="ex-muted"><?= $e($fmt($x['created_at'])) ?></span></td>
              <td><span class="badge <?= $valBadge[$st] ?? 'badge-neutral' ?>"><?= $e($valLabel[$st] ?? $st) ?></span>
                <?php if ($st !== 'pending'): ?><br><span class="ex-muted"><?= $e($x['validated_by_name']) ?> · <?= $e($fmt($x['validated_at'])) ?></span><?php endif; ?>
                <?php if (!empty($x['validation_note'])): ?><br><span class="ex-muted">Nota: <?= $e($x['validation_note']) ?></span><?php endif; ?>
              </td>
              <?php if ($isManager): ?>
              <td class="text-right">
                <?php if ($st === 'pending'): ?>
                  <form method="POST" action="/exames/catalogo/personalizados/<?= (int)$x['id'] ?>/validar" class="ex-validate" data-no-dirty>
                    <input type="hidden" name="_csrf_token" value="<?= $e($csrfToken) ?>">
                    <label class="form-label" for="note-<?= (int)$x['id'] ?>">Nota (obrigatória para recusar)</label>
                    <input type="text" id="note-<?= (int)$x['id'] ?>" name="validation_note" class="form-control" maxlength="500">
                    <div class="ex-actions">
                      <button type="submit" name="decision" value="approve" class="btn btn-primary">Aprovar</button>
                      <button type="submit" name="decision" value="reject" class="btn btn-outline">Recusar</button>
                    </div>
                  </form>
                <?php else: ?><span class="ex-muted">—</span><?php endif; ?>
              </td>
              <?php endif; ?>
            </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    <?php else: ?>
      <p class="ex-muted">Nenhum exame da clínica ainda.</p>
    <?php endif; ?>

    <h3 class="ex-h3">Propor exame</h3>
    <?php if ($error): ?><div class="op-message op-message--error ex-msg ex-msg-error" role="alert"><strong>Não foi possível enviar.</strong> <?= $e($error) ?></div><?php endif; ?>
    <?php if ($eligibilityError): ?><div class="op-message op-message--error ex-msg ex-msg-error" role="alert"><?= $e($eligibilityError) ?> <a href="/configuracoes">Abrir Configurações</a></div><?php endif; ?>
    <form method="POST" action="/exames/catalogo/personalizados" class="ex-form-grid"<?= $error ? ' data-start-dirty' : '' ?>>
      <input type="hidden" name="_csrf_token" value="<?= $e($csrfToken) ?>">
      <div class="form-group ex-span2"><label class="form-label" for="ce-name">Nome do exame</label>
        <input type="text" id="ce-name" name="canonical_name" class="form-control" required minlength="3" maxlength="255" value="<?= $e($old['canonical_name'] ?? '') ?>"></div>
      <div class="form-group"><label class="form-label" for="ce-cat">Categoria</label>
        <input type="text" id="ce-cat" name="category" class="form-control" required maxlength="100" value="<?= $e($old['category'] ?? '') ?>" placeholder="Ex.: Micronutrientes e Vitaminas"></div>
      <div class="form-group"><label class="form-label" for="ce-sample">Material</label>
        <input type="text" id="ce-sample" name="sample_type" class="form-control" required maxlength="100" value="<?= $e($old['sample_type'] ?? '') ?>" placeholder="Ex.: Soro"></div>
      <div class="form-group"><label class="form-label" for="ce-fast">Jejum (horas, opcional)</label>
        <input type="number" id="ce-fast" name="fasting_hours" class="form-control" min="0" max="24" value="<?= $e($old['fasting_hours'] ?? '') ?>"></div>
      <div class="form-group"><label class="form-label" for="ce-syn">Sinônimos (separados por vírgula)</label>
        <input type="text" id="ce-syn" name="synonyms" class="form-control" maxlength="1000" value="<?= $e($old['synonyms'] ?? '') ?>"></div>
      <div class="form-group ex-span2"><label class="form-label" for="ce-ind">Pertinência para o acompanhamento nutricional</label>
        <textarea id="ce-ind" name="nutritional_indication" class="form-control" rows="3" required minlength="15" maxlength="2000"><?= $e($old['nutritional_indication'] ?? '') ?></textarea></div>
      <div class="form-group"><label class="form-label" for="ce-sys">Sistema de código (opcional)</label>
        <select id="ce-sys" name="standard_code_system" class="form-control">
          <?php foreach (['' => 'Sem código', 'TUSS' => 'TUSS', 'LOINC' => 'LOINC', 'OUTRO' => 'Outro'] as $k => $lbl): ?>
            <option value="<?= $e($k) ?>"<?= ($old['standard_code_system'] ?? '') === $k ? ' selected' : '' ?>><?= $e($lbl) ?></option>
          <?php endforeach; ?>
        </select></div>
      <div class="form-group"><label class="form-label" for="ce-code">Código (opcional)</label>
        <input type="text" id="ce-code" name="standard_code" class="form-control" maxlength="40" value="<?= $e($old['standard_code'] ?? '') ?>"></div>
      <label class="ex-check ex-span2"><input type="checkbox" name="declaration" value="1" required<?= !empty($old['declaration']) ? ' checked' : '' ?>>
        <span>Confirmo que é um exame laboratorial necessário ao acompanhamento nutricional (Lei 8.234/1991, art. 4º, VIII; Res. CFN 306/2003).</span></label>
      <div class="ex-actions ex-span2"><button type="submit" class="btn btn-primary"<?= $eligibilityError ? ' disabled' : '' ?>>Enviar para validação</button></div>
    </form>
  </section>
</div>
