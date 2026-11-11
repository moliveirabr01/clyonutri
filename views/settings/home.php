<?php
$e=static fn($v)=>htmlspecialchars((string)$v,ENT_QUOTES,'UTF-8');
$areas=\ClyoNutri\Application\View\SettingsNavigation::current();
?>
<h1>O que você quer fazer?</h1>
<nav class="op-task-shortcuts" aria-label="Tarefas frequentes"><a class="op-button op-button--primary" href="/pacientes?novo=1">Cadastrar paciente</a><a class="op-button op-button--secondary" href="/planos">Encontrar plano alimentar</a><a class="op-button op-button--secondary" href="/configuracoes?tab=agenda&amp;secao=horarios">Configurar horários</a></nav>
<p>Escolha uma área ou busque uma tarefa. Cada formulário salva apenas a sua seção.</p>
<label for="settings-search">Buscar configuração ou tarefa</label>
<input id="settings-search" class="form-control" type="search" data-settings-search autocomplete="off" aria-controls="settings-results" placeholder="Ex.: senha, feriado, chat, CRN">
<p role="status" aria-live="polite" aria-atomic="true" data-settings-results></p>
<div id="settings-results">
<div class="op-category-grid" data-settings-categories>
<?php foreach($areas as $area): ?>
<section class="card" data-settings-category><h2><a href="<?= $e($area['url']) ?>"><?= $e($area['label']) ?></a></h2><p><?= $e($area['hint']) ?></p><details><summary>Ver tarefas de <?= $e($area['label']) ?></summary><ul><?php foreach($area['tasks'] as $task): ?><li><a href="<?= $e($task['url']) ?>"><?= $e($task['label']) ?></a></li><?php endforeach; ?></ul></details></section>
<?php endforeach; ?>
</div>
<ul class="op-search-results" data-settings-task-results hidden>
<?php foreach($areas as $area): foreach($area['tasks'] as $task): ?>
<li data-settings-task-result data-search-terms="<?= $e($task['label'].' '.$task['terms'].' '.$area['label']) ?>"><a href="<?= $e($task['url']) ?>"><?= $e($task['label']) ?></a><small><?= $e($area['label']) ?></small></li>
<?php endforeach; endforeach; ?>
</ul></div>
<script src="/assets/js/settings-search.js?v=v54-26" defer></script>
