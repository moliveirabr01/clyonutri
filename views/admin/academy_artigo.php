<?php /* NI2-663: artigo do acervo no console — metadados, sinalização, fichas e vínculos. Entradas: $article, $sheets, $links, $protocols, $flash, $old, $operatorId, $csrfToken. */
use ClyoNutri\Domain\Academy\AcademyArticleService as A;
use ClyoNutri\Domain\Academy\AcademyEvidenceDeskService as E;
$e = static fn($v): string => htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
$o = static fn(string $k): string => htmlspecialchars((string)($old[$k] ?? ''), ENT_QUOTES, 'UTF-8');
$aid = (int)$article['id'];
$status = (string)($article['status'] ?? 'published');
$sel = static function (string $name, array $opts, string $cur) use ($e): string {
    $h = '<select name="' . $e($name) . '" required><option value="">Escolha…</option>';
    foreach ($opts as $k => $l) {
        $h .= '<option value="' . $e($k) . '"' . ($k === $cur ? ' selected' : '') . '>' . $e($l) . '</option>';
    }
    return $h . '</select>';
};
$v = static fn(string $k): string => (string)($old[$k] ?? ($k === 'retrieved_at' ? substr((string)($article[$k] ?? ''), 0, 10) : ($article[$k] ?? '')));
?>
<p><a href="/admin/academy">← Clyo Academy</a></p>
<?php if ($flash): ?><div class="admin-alert admin-alert--<?= $flash['type'] === 'error' ? 'error' : 'success' ?>" role="<?= $flash['type'] === 'error' ? 'alert' : 'status' ?>" data-testid="academy-admin-flash"><?= $e($flash['text']) ?></div><?php endif; ?>
<div class="admin-card admin-card--pad" data-testid="academy-article">
  <h2 class="admin-page-title"><?= $e($article['title']) ?></h2>
  <p data-testid="academy-article-status" data-status="<?= $e($status) ?>">Situação: <strong><?= $e(A::STATUS[$status] ?? $status) ?></strong><?= $status === 'published' ? ' · visível para as clínicas' : ' · as clínicas não veem este artigo' ?></p>
  <p><?= $e($article['authors']) ?><br><em><?= $e($article['journal']) ?></em> (<?= (int)$article['publication_year'] ?>) · <?= $e(A::studyDesignLabel($article['study_design'])) ?></p>
  <p class="admin-small">
    <?= $article['doi'] ? 'DOI <a href="https://doi.org/' . $e($article['doi']) . '" rel="noopener noreferrer" target="_blank">' . $e($article['doi']) . '</a> · ' : '' ?>
    <?= $article['pmid'] ? 'PMID ' . $e($article['pmid']) . ' · ' : '' ?>
    Fonte: <?= $e(A::SOURCES[$article['source']] ?? $article['source']) ?> · conferido em <?= $article['retrieved_at'] ? $e(date('d/m/Y', strtotime((string)$article['retrieved_at']))) : 'não informado' ?>
    · cadastrado em <?= $e(date('d/m/Y', strtotime((string)$article['created_at']))) ?>
  </p>
  <form method="POST" action="/admin/academy/artigo/<?= $aid ?>/retratacao" class="admin-inline-form">
    <input type="hidden" name="_csrf_token" value="<?= $e($csrfToken) ?>">
    <label>Sinalização da fonte<br><select name="retraction_status"><?php foreach (A::RETRACTION as $k => $l): ?><option value="<?= $e($k) ?>"<?= $article['retraction_status'] === $k ? ' selected' : '' ?>><?= $e($l) ?></option><?php endforeach; ?></select></label>
    <button class="btn btn--secondary btn--sm" type="submit">Atualizar sinalização</button>
  </form>
</div>

<div class="admin-card admin-card--pad" data-testid="academy-article-publishing">
  <h3 class="admin-section-title">Publicação na biblioteca</h3>
  <?php if ($status !== 'published'): ?>
    <p class="admin-hint">Confira título, identificadores e link antes de publicar. Depois de publicado, o artigo aparece na busca de todas as clínicas.</p>
    <form method="POST" action="/admin/academy/artigo/<?= $aid ?>/artigo-publicar" data-testid="academy-article-publish">
      <input type="hidden" name="_csrf_token" value="<?= $e($csrfToken) ?>">
      <button class="btn btn--primary btn--sm" type="submit"><?= $status === 'archived' ? 'Publicar de novo' : 'Publicar na biblioteca' ?></button>
    </form>
  <?php endif; ?>
  <?php if ($status !== 'archived'): ?>
    <form method="POST" action="/admin/academy/artigo/<?= $aid ?>/artigo-arquivar" data-testid="academy-article-archive" class="admin-inline-form admin-mt">
      <input type="hidden" name="_csrf_token" value="<?= $e($csrfToken) ?>">
      <label>Motivo para arquivar<br><input name="reason" required minlength="5" maxlength="255" class="admin-field-reason"></label>
      <button class="btn btn--secondary btn--sm" type="submit">Arquivar</button>
    </form>
    <p class="admin-hint">Arquivar tira o artigo da busca e da coleção das clínicas. Os favoritos ficam guardados e voltam se o artigo for publicado de novo.</p>
  <?php endif; ?>
  <details<?= ($old && isset($old['title'])) ? ' open' : '' ?> class="admin-mt">
    <summary>Corrigir dados do artigo</summary>
    <form method="POST" action="/admin/academy/artigo/<?= $aid ?>/artigo-editar" data-testid="academy-article-edit" class="admin-form admin-mt">
      <input type="hidden" name="_csrf_token" value="<?= $e($csrfToken) ?>">
      <label class="admin-form__wide">Título<br><input name="title" required maxlength="500" value="<?= $e($v('title')) ?>"></label>
      <label class="admin-form__wide">Autores (separados por ";")<br><input name="authors" required value="<?= $e($v('authors')) ?>"></label>
      <label>Periódico<br><input name="journal" required maxlength="255" value="<?= $e($v('journal')) ?>"></label>
      <label>Ano<br><input name="publication_year" required type="number" min="1800" max="<?= (int)date('Y') + 1 ?>" value="<?= $e($v('publication_year')) ?>"></label>
      <label>DOI<br><input name="doi" value="<?= $e($v('doi')) ?>"></label>
      <label>PMID<br><input name="pmid" value="<?= $e($v('pmid')) ?>" inputmode="numeric"></label>
      <label class="admin-form__wide">URL oficial (https)<br><input name="url" required type="url" value="<?= $e($v('url')) ?>"></label>
      <label>Desenho do estudo<br><?= $sel('study_design', A::DESIGNS, $v('study_design')) ?></label>
      <label>Acesso<br><?= $sel('access_type', A::ACCESS, $v('access_type')) ?></label>
      <label>Fonte dos metadados<br><?= $sel('source', A::SOURCES, $v('source')) ?></label>
      <label>Conferido na fonte em<br><input name="retrieved_at" required type="date" max="<?= date('Y-m-d') ?>" value="<?= $e($v('retrieved_at')) ?>"></label>
      <label>Condições (etiquetas, separadas por vírgula)<br><input name="condition_tags" value="<?= $e($v('condition_tags')) ?>"></label>
      <label>Licença do resumo (se houver)<br><input name="license" maxlength="60" value="<?= $e($v('license')) ?>"></label>
      <label class="admin-form__wide">Resumo (só com licença que permita)<br><textarea name="abstract" rows="3"><?= $e($v('abstract')) ?></textarea></label>
      <div><button class="btn btn--primary btn--sm" type="submit">Salvar correções</button></div>
    </form>
  </details>
</div>

<div class="admin-card admin-card--pad">
  <h3 class="admin-section-title">Fichas deste artigo</h3>
  <?php if (!$sheets): ?><p>Nenhuma ficha.</p><?php endif; ?>
  <ul>
    <?php foreach ($sheets as $f): ?>
      <li data-testid="academy-article-sheet" data-status="<?= $e($f['status']) ?>"><?= $e(E::STATUS[$f['status']] ?? $f['status']) ?> · certeza <?= $e(E::CERTAINTY[$f['evidence_certainty']] ?? '?') ?> · <?= $e($f['curator_name']) ?> (<?= $e($f['curator_crn']) ?>)<?= $f['approved_by_name'] ? ' · aprovada por ' . $e($f['approved_by_name']) . ' (' . $e($f['approved_by_crn']) . ')' : '' ?>
        <?php if ($f['status'] === 'published'): ?>
          <form method="POST" action="/admin/academy/ficha/<?= (int)$f['id'] ?>/ficha-devolver" class="admin-inline-form admin-inline-form--inline">
            <input type="hidden" name="_csrf_token" value="<?= $e($csrfToken) ?>">
            <label class="visually-hidden" for="fr-<?= (int)$f['id'] ?>">Motivo</label>
            <input id="fr-<?= (int)$f['id'] ?>" name="reason" required minlength="5" placeholder="Motivo para retirar">
            <button class="btn btn--secondary btn--sm" type="submit">Retirar ficha</button>
          </form>
        <?php endif; ?>
      </li>
    <?php endforeach; ?>
  </ul>
  <details<?= $old ? ' open' : '' ?>>
    <summary>Escrever nova ficha (rascunho)</summary>
    <p class="admin-hint">A certeza (GRADE) é avaliação de quem leu o artigo inteiro: não é deduzida do desenho do estudo. A ficha só aparece depois de aprovada por outra pessoa.</p>
    <form method="POST" action="/admin/academy/artigo/<?= $aid ?>/ficha" data-testid="academy-sheet-form" class="admin-form">
      <input type="hidden" name="_csrf_token" value="<?= $e($csrfToken) ?>">
      <label>Curador(a)<br><input name="curator_name" required value="<?= $o('curator_name') ?>"></label>
      <label>CRN com região<br><input name="curator_crn" required placeholder="CRN-3 12345" value="<?= $o('curator_crn') ?>"></label>
      <?php foreach (['population' => 'População', 'intervention' => 'Intervenção ou exposição', 'comparator' => 'Comparador', 'outcomes' => 'Desfechos (com números do artigo)', 'methodological_limitations' => 'Limitações e risco de viés', 'clinical_applicability' => 'Aplicabilidade e limites para a prática', 'conflict_of_interest' => 'Financiamento e conflitos do estudo (opcional)'] as $k => $l): ?>
        <label class="admin-form__wide"><?= $e($l) ?><br><textarea name="<?= $e($k) ?>" rows="2"<?= $k !== 'conflict_of_interest' ? ' required' : '' ?>><?= $o($k) ?></textarea></label>
      <?php endforeach; ?>
      <label>Certeza da evidência<br><select name="evidence_certainty" required><option value="">Escolha…</option><?php foreach (E::CERTAINTY as $k => $l): ?><option value="<?= $e($k) ?>"<?= ($old['evidence_certainty'] ?? '') === $k ? ' selected' : '' ?>><?= $e($l) ?></option><?php endforeach; ?></select></label>
      <div><button class="btn btn--primary btn--sm" type="submit">Salvar rascunho da ficha</button></div>
    </form>
  </details>
</div>

<div class="admin-card admin-card--pad">
  <h3 class="admin-section-title">Vínculos com protocolos da anamnese adaptativa</h3>
  <ul><?php foreach ($links as $l): ?><li><code><?= $e($l['protocol_code']) ?></code><?= $l['relevance_note'] ? ' · ' . $e($l['relevance_note']) : '' ?></li><?php endforeach; ?><?php if (!$links): ?><li>Nenhum vínculo.</li><?php endif; ?></ul>
  <?php if ($protocols): ?>
  <form method="POST" action="/admin/academy/artigo/<?= $aid ?>/vinculo" class="admin-inline-form">
    <input type="hidden" name="_csrf_token" value="<?= $e($csrfToken) ?>">
    <label>Protocolo<br><select name="protocol_code" required><?php foreach ($protocols as $code => $name): ?><option value="<?= $e($code) ?>"><?= $e($name) ?></option><?php endforeach; ?></select></label>
    <label>Nota (por que este artigo serve ao protocolo)<br><input name="relevance_note" maxlength="255" class="admin-field-reason"></label>
    <button class="btn btn--secondary btn--sm" type="submit">Vincular</button>
  </form>
  <?php else: ?><p>Nenhum protocolo no catálogo.</p><?php endif; ?>
</div>
