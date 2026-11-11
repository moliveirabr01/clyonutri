<?php /* NI2-663: console editorial da Clyo Academy. Entradas: $ready, $articles, $submitted, $publications, $sheets, $handouts, $handoutsReady (v54.23), $flag, $flash, $old, $operatorId, $csrfToken. */
use ClyoNutri\Domain\Academy\AcademyArticleService as A;
use ClyoNutri\Domain\Academy\AcademyPublishingService as P;
use ClyoNutri\Domain\Academy\AcademyEvidenceDeskService as E;
$e = static fn($v): string => htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
// v54.24: situação editorial como badge do admin (texto continua visível).
$badge = static fn(string $st): string => ['published' => 'badge--success', 'draft' => 'badge--warning', 'submitted' => 'badge--warning', 'in_review' => 'badge--warning', 'rejected' => 'badge--danger', 'archived' => 'badge--info'][$st] ?? 'badge--info';
$o = static fn(string $k, string $d = ''): string => htmlspecialchars((string)($old[$k] ?? $d), ENT_QUOTES, 'UTF-8');
$sel = static function (string $name, array $opts, string $cur) use ($e): string {
    $h = '<select name="' . $e($name) . '" required><option value="">Escolha…</option>';
    foreach ($opts as $k => $l) {
        $h .= '<option value="' . $e($k) . '"' . ($k === $cur ? ' selected' : '') . '>' . $e($l) . '</option>';
    }
    return $h . '</select>';
};
?>
<div class="admin-block">
  <h2 class="admin-heading">Clyo Academy</h2>
  <p class="admin-hint admin-lead">Tudo o que as clínicas leem na Clyo Academy sai daqui: acervo de artigos, fichas de leitura crítica e publicações da equipe. As clínicas só pesquisam, leem e favoritam; só aparece para elas o que estiver publicado. Cada artigo precisa de DOI ou PMID, link oficial e data de conferência na fonte. Fichas e publicações são aprovadas por uma pessoa diferente de quem escreveu.</p>
  <p><a class="btn btn--primary btn--sm" href="/admin/academy/publicacoes/nova" data-testid="academy-new-publication">Nova publicação</a><?php if (!empty($handoutsReady)): ?> <a class="btn btn--secondary btn--sm" href="/admin/academy/laminas/nova" data-testid="academy-new-handout">Nova lâmina</a><?php endif; ?></p>
  <p class="admin-small" data-testid="academy-flag">Chave global: <strong><?= $flag ? 'ligada' : 'desligada' ?></strong> (Configurações globais › feature_flag_academy)</p>
</div>
<?php if ($flash): ?><div class="admin-alert admin-alert--<?= $flash['type'] === 'error' ? 'error' : 'success' ?>" role="<?= $flash['type'] === 'error' ? 'alert' : 'status' ?>" data-testid="academy-admin-flash"><?= $e($flash['text']) ?></div><?php endif; ?>
<?php if (!$ready): ?>
  <div class="admin-card admin-card--pad">As migrações 82 e 84 da Academy ainda não foram aplicadas neste banco.</div>
<?php else: ?>

<div class="admin-card admin-card--pad" data-testid="academy-publication-queue">
  <h3 class="admin-section-title">Publicações aguardando revisão (<?= count($submitted) ?>)</h3>
  <?php if (!$submitted): ?><p>Nenhuma publicação enviada.</p><?php endif; ?>
  <?php foreach ($submitted as $p): $own = (int)$p['author_user_id'] === (int)$operatorId; ?>
    <details class="admin-divided" data-testid="academy-submitted" data-id="<?= (int)$p['id'] ?>">
      <summary><strong><?= $e($p['title']) ?></strong> · <?= $e(P::categoryLabel($p['category'])) ?> · <?= $e($p['author_name']) ?> (<?= $e($p['author_crn']) ?>)<?= (int)$p['clinic_id'] > 0 ? ' · enviada pela clínica #' . (int)$p['clinic_id'] : ' · equipe Clyo Nutri' ?></summary>
      <p><strong>Resumo:</strong> <?= nl2br($e($p['summary'])) ?></p>
      <div class="admin-pre admin-pre--box"><?= $e($p['body_markdown']) ?></div>
      <p class="admin-pre"><strong>Referências:</strong> <?= $e($p['references_text']) ?></p>
      <p class="admin-small"><strong>Conflitos:</strong> <?= $e($p['conflict_of_interest_disclosure']) ?></p>
      <?php if ($own): ?>
        <p data-testid="academy-own-publication">Você escreveu esta publicação: a revisão precisa ser feita por outra pessoa.</p>
      <?php else: ?>
      <form method="POST" action="/admin/academy/publicacao/<?= (int)$p['id'] ?>/publicacao-revisar" class="admin-stack admin-stack--sm">
        <input type="hidden" name="_csrf_token" value="<?= $e($csrfToken) ?>">
        <label>Parecer editorial (obrigatório para devolver)<br><textarea name="editorial_notes" rows="2"></textarea></label>
        <div class="admin-actions">
          <button class="btn btn--primary btn--sm" type="submit" name="review_action" value="approve">Aprovar e publicar</button>
          <button class="btn btn--secondary btn--sm" type="submit" name="review_action" value="reject">Devolver para ajuste</button>
        </div>
      </form>
      <?php endif; ?>
    </details>
  <?php endforeach; ?>
</div>

<div class="admin-card admin-card--pad" data-testid="academy-publications">
  <h3 class="admin-section-title">Publicações (<?= count($publications) ?>)</h3>
  <?php if (!$publications): ?><p>Nenhuma publicação fora da fila de revisão.</p><?php else: ?>
  <div class="admin-table-wrapper">
    <table class="admin-table">
      <thead><tr><th>Título</th><th>Assinatura</th><th>Situação</th><th>Atualizada em</th><th></th></tr></thead>
      <tbody>
      <?php foreach ($publications as $p): ?>
        <tr data-testid="academy-publication-row" data-id="<?= (int)$p['id'] ?>" data-status="<?= $e($p['status']) ?>">
          <td><strong><?= $e($p['title']) ?></strong><br><?= $e(P::categoryLabel($p['category'])) ?><?= (int)$p['clinic_id'] > 0 ? ' · enviada pela clínica #' . (int)$p['clinic_id'] . ' (versão antiga)' : '' ?></td>
          <td><?= $e($p['author_name']) ?> (<?= $e($p['author_crn']) ?>)</td>
          <td><span class="badge <?= $badge((string)$p['status']) ?>"><?= $e(P::STATUS[$p['status']] ?? $p['status']) ?></span></td>
          <td><?= $p['updated_at'] ? $e(date('d/m/Y H:i', strtotime((string)$p['updated_at']))) : '' ?></td>
          <td><a class="btn btn--secondary btn--sm" href="/admin/academy/publicacoes/<?= (int)$p['id'] ?>">Abrir</a></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
  <?php endif; ?>
</div>

<div class="admin-card admin-card--pad" data-testid="academy-handouts">
  <h3 class="admin-section-title">Lâminas para pacientes (<?= count($handouts ?? []) ?>)</h3>
  <?php if (empty($handoutsReady)): ?>
    <p>A migração 85 ainda não foi aplicada neste banco. Depois dela, as lâminas aparecem aqui.</p>
  <?php elseif (!$handouts): ?>
    <p>Nenhuma lâmina cadastrada. Use "Nova lâmina" ou a importação em lote (bin/importar_laminas_academy.php), que cria tudo como rascunho.</p>
  <?php else: ?>
  <div class="admin-table-wrapper">
    <table class="admin-table">
      <thead><tr><th>Título</th><th>Endereço</th><th>Situação</th><th>PDF</th><th>Atualizada em</th><th></th></tr></thead>
      <tbody>
      <?php foreach ($handouts as $h): ?>
        <tr data-testid="academy-handout-row" data-id="<?= (int)$h['id'] ?>" data-slug="<?= $e($h['slug']) ?>" data-status="<?= $e($h['status']) ?>">
          <td><strong><?= $e($h['title']) ?></strong><?= empty($h['preview_storage_name']) ? '<br><span class="admin-small">sem prévia</span>' : '' ?></td>
          <td><?= $e($h['slug']) ?></td>
          <td><span class="badge <?= $badge((string)$h['status']) ?>"><?= $e(\ClyoNutri\Domain\Academy\AcademyHandoutService::STATUS[$h['status']] ?? $h['status']) ?></span></td>
          <td><?= $e(\ClyoNutri\Domain\Academy\AcademyHandoutService::humanSize((int)$h['size_bytes'])) ?></td>
          <td><?= $h['updated_at'] ? $e(date('d/m/Y H:i', strtotime((string)$h['updated_at']))) : '' ?></td>
          <td><a class="btn btn--secondary btn--sm" href="/admin/academy/laminas/<?= (int)$h['id'] ?>">Abrir</a></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
  <?php endif; ?>
</div>

<div class="admin-card admin-card--pad" data-testid="academy-sheet-queue">
  <h3 class="admin-section-title">Fichas de leitura crítica em andamento (<?= count($sheets) ?>)</h3>
  <?php if (!$sheets): ?><p>Nenhuma ficha em rascunho ou em revisão.</p><?php endif; ?>
  <?php foreach ($sheets as $f): $mine = (int)$f['curator_user_id'] === (int)$operatorId; ?>
    <details class="admin-divided" data-testid="academy-sheet" data-id="<?= (int)$f['id'] ?>" data-status="<?= $e($f['status']) ?>">
      <summary><strong><?= $e($f['article_title']) ?></strong> · <?= $e(E::STATUS[$f['status']] ?? $f['status']) ?> · certeza <?= $e(E::CERTAINTY[$f['evidence_certainty']] ?? '?') ?> · curadoria <?= $e($f['curator_name']) ?> (<?= $e($f['curator_crn']) ?>)</summary>
      <dl class="admin-small">
        <?php foreach (['population' => 'População', 'intervention' => 'Intervenção', 'comparator' => 'Comparador', 'outcomes' => 'Desfechos', 'methodological_limitations' => 'Limitações', 'clinical_applicability' => 'Aplicabilidade', 'conflict_of_interest' => 'Financiamento e conflitos do estudo'] as $k => $l): ?>
          <dt><strong><?= $e($l) ?></strong></dt><dd class="admin-dd"><?= $e($f[$k] ?? '—') ?></dd>
        <?php endforeach; ?>
      </dl>
      <?php if ($f['status'] === 'draft' && $mine): ?>
        <form method="POST" action="/admin/academy/ficha/<?= (int)$f['id'] ?>/ficha-enviar"><input type="hidden" name="_csrf_token" value="<?= $e($csrfToken) ?>"><button class="btn btn--primary btn--sm" type="submit">Enviar para revisão</button></form>
      <?php elseif ($f['status'] === 'in_review' && $mine): ?>
        <p data-testid="academy-own-sheet">Você escreveu esta ficha: a aprovação precisa ser feita por outra pessoa.</p>
      <?php elseif ($f['status'] === 'in_review'): ?>
        <form method="POST" action="/admin/academy/ficha/<?= (int)$f['id'] ?>/ficha-aprovar" data-testid="academy-sheet-approve" class="admin-form admin-form--dense">
          <input type="hidden" name="_csrf_token" value="<?= $e($csrfToken) ?>">
          <label>Nutricionista aprovador(a)<br><input name="approver_name" required></label>
          <label>CRN com região<br><input name="approver_crn" required placeholder="CRN-3 12345"></label>
          <label class="admin-form__wide admin-actions"><input type="checkbox" name="confirm_review" value="1" required> Conferi a ficha com o artigo original (população, desfechos, limitações e certeza).</label>
          <div><button class="btn btn--primary btn--sm" type="submit">Aprovar e publicar ficha</button></div>
        </form>
      <?php endif; ?>
      <?php if ($f['status'] === 'in_review'): ?>
        <form method="POST" action="/admin/academy/ficha/<?= (int)$f['id'] ?>/ficha-devolver" class="admin-actions admin-mt">
          <input type="hidden" name="_csrf_token" value="<?= $e($csrfToken) ?>">
          <label class="visually-hidden" for="fd-<?= (int)$f['id'] ?>">Motivo</label>
          <input id="fd-<?= (int)$f['id'] ?>" name="reason" required minlength="5" placeholder="Motivo para devolver" class="admin-grow">
          <button class="btn btn--secondary btn--sm" type="submit">Devolver para rascunho</button>
        </form>
      <?php endif; ?>
    </details>
  <?php endforeach; ?>
</div>

<div class="admin-card admin-block">
  <div class="admin-table-wrapper">
    <table class="admin-table" data-testid="academy-articles">
      <thead><tr><th>Artigo</th><th>Situação</th><th>Desenho</th><th>Identificadores</th><th>Ficha</th><th>Sinalização</th><th></th></tr></thead>
      <tbody>
      <?php if (!$articles): ?><tr><td colspan="7">Acervo vazio.</td></tr><?php endif; ?>
      <?php foreach ($articles as $a): ?>
        <tr data-testid="academy-article-row" data-id="<?= (int)$a['id'] ?>" data-status="<?= $e($a['status']) ?>">
          <td><strong><?= $e($a['title']) ?></strong><br><?= $e($a['journal']) ?> (<?= (int)$a['publication_year'] ?>)</td>
          <td><span class="badge <?= $badge((string)$a['status']) ?>"><?= $e(A::STATUS[$a['status']] ?? $a['status']) ?></span></td>
          <td><?= $e(A::studyDesignLabel($a['study_design'])) ?></td>
          <td><?= $a['doi'] ? 'DOI ' . $e($a['doi']) : '' ?><?= $a['pmid'] ? '<br>PMID ' . $e($a['pmid']) : '' ?></td>
          <td><?= (int)$a['has_evidence_sheet'] ? 'publicada' : '—' ?></td>
          <td><?= $e(A::RETRACTION[$a['retraction_status']] ?? $a['retraction_status']) ?></td>
          <td><a class="btn btn--secondary btn--sm" href="/admin/academy/artigos/<?= (int)$a['id'] ?>">Abrir</a></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>

<div class="admin-card admin-card--pad">
  <h3 class="admin-section-title">Cadastrar artigo no acervo</h3>
  <p class="admin-hint">O artigo entra como rascunho e só aparece para as clínicas depois de publicado na página dele. Copie os metadados da fonte (PubMed, Crossref ou site do periódico) e confira DOI/PMID antes de salvar. Autores separados por ";". O resumo só pode ser guardado se a licença permitir reproduzi-lo; sem licença, fica só o link.</p>
  <form method="POST" action="/admin/academy/artigo" data-testid="academy-article-form" class="admin-form">
    <input type="hidden" name="_csrf_token" value="<?= $e($csrfToken) ?>">
    <label class="admin-form__wide">Título<br><input name="title" required maxlength="500" value="<?= $o('title') ?>"></label>
    <label class="admin-form__wide">Autores (separados por ";")<br><input name="authors" required value="<?= $o('authors') ?>"></label>
    <label>Periódico<br><input name="journal" required maxlength="255" value="<?= $o('journal') ?>"></label>
    <label>Ano<br><input name="publication_year" required type="number" min="1800" max="<?= (int)date('Y') + 1 ?>" value="<?= $o('publication_year') ?>"></label>
    <label>DOI<br><input name="doi" value="<?= $o('doi') ?>" placeholder="10.xxxx/…"></label>
    <label>PMID<br><input name="pmid" value="<?= $o('pmid') ?>" inputmode="numeric"></label>
    <label class="admin-form__wide">URL oficial (https)<br><input name="url" required type="url" value="<?= $o('url') ?>" placeholder="https://doi.org/…"></label>
    <label>Desenho do estudo<br><?= $sel('study_design', A::DESIGNS, (string)($old['study_design'] ?? '')) ?></label>
    <label>Acesso<br><?= $sel('access_type', A::ACCESS, (string)($old['access_type'] ?? 'publisher_link')) ?></label>
    <label>Fonte dos metadados<br><?= $sel('source', A::SOURCES, (string)($old['source'] ?? '')) ?></label>
    <label>Conferido na fonte em<br><input name="retrieved_at" required type="date" max="<?= date('Y-m-d') ?>" value="<?= $o('retrieved_at', date('Y-m-d')) ?>"></label>
    <label>Condições (etiquetas, separadas por vírgula)<br><input name="condition_tags" value="<?= $o('condition_tags') ?>" placeholder="diabetes, hipertensao"></label>
    <label>Licença do resumo (se houver)<br><input name="license" maxlength="60" value="<?= $o('license') ?>" placeholder="CC BY 4.0"></label>
    <label class="admin-form__wide">Resumo (só com licença que permita)<br><textarea name="abstract" rows="3"><?= $o('abstract') ?></textarea></label>
    <div><button class="btn btn--primary btn--sm" type="submit">Cadastrar artigo</button></div>
  </form>
</div>
<?php endif; ?>
