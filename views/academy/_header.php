<?php
declare(strict_types=1);

/** @var string $subTab */
use ClyoNutri\Application\View\Icon;

$currentTab = $subTab ?? 'library';
$academyTabs = [
    'library' => ['/academy', 'book-open', 'Biblioteca científica', null],
    'handouts' => ['/academy/laminas', 'file-text', 'Lâminas para pacientes', 'academy-tab-handouts'],
    'evidence' => ['/academy/evidencias', 'clipboard-list', 'Evidence Desk (fichas PICO)', null],
    'collection' => ['/academy/colecao', 'bookmark', 'Minha coleção', null],
    'publications' => ['/academy/publicacoes', 'pen-line', 'Publicações e sínteses', null],
];
if (empty($handoutsTab)) { // v54.23: aba só com a migração 85 aplicada
    unset($academyTabs['handouts']);
}
?>
<link rel="stylesheet" href="/assets/css/academy.css?v=v54-26">
<header class="academy-head">
  <p class="academy-head__eyebrow">
    <span class="badge badge-info">Clyo Academy</span>
    <span>Literatura científica e prática clínica baseada em evidências</span>
  </p>
  <h1>Clyo Academy</h1>
  <p class="academy-head__lead">Pesquise artigos, fichas de leitura crítica, lâminas para pacientes e publicações da equipe Clyo Nutri, e guarde o que for útil na sua coleção.</p>

  <nav class="academy-tabs" aria-label="Navegação da Academy">
    <ul>
      <?php foreach ($academyTabs as $tk => [$href, $icon, $label, $testid]): ?>
      <li><a href="<?= $href ?>"<?= $testid ? ' data-testid="' . $testid . '"' : '' ?><?= $currentTab === $tk ? ' aria-current="page"' : '' ?>><?= Icon::svg($icon, 18) ?><span><?= $label ?></span></a></li>
      <?php endforeach; ?>
    </ul>
  </nav>
</header>

<script src="/assets/js/academy.js?v=v54-24" defer></script>
<?php if (!empty($flash)): $flashError = $flash['type'] === 'error'; ?>
  <div role="<?= $flashError ? 'alert' : 'status' ?>" data-testid="academy-flash" class="op-message op-message--<?= $flashError ? 'error' : 'success' ?> academy-flash"><?= htmlspecialchars($flash['message']) ?></div>
<?php endif; ?>
