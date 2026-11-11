<?php
$areas=\ClyoNutri\Application\View\SettingsNavigation::current();
$cfgTabs=[];
foreach($areas as $area)$cfgTabs[]=[$area['key'],$area['url'],$area['label']];
foreach($areas as $area)foreach($area['tasks'] as $task)if($task['url']==='/configuracoes/protocolos')$cfgTabs[]=['protocolos',$task['url'],$task['label']];
$navEscape=static fn($v)=>htmlspecialchars((string)$v,ENT_QUOTES,'UTF-8');
$currentLabel='Área atual';foreach($cfgTabs as [$key,$url,$label])if(($activeTab??'')===$key)$currentLabel=$label;
?>
<nav class="op-breadcrumb" aria-label="Navegação de configurações"><a href="/configuracoes">Configurações</a><span aria-hidden="true"> / </span><span aria-current="page"><?= $navEscape($currentLabel) ?></span></nav>
<details class="op-settings-switch"><summary>Trocar área de configuração</summary><nav class="cn-settings-nav-tabs" aria-label="Áreas de configurações">
<?php foreach($cfgTabs as [$key,$url,$label]): ?><a href="<?= $navEscape($url) ?>" data-testid="tab-<?= $navEscape($key) ?>" <?= ($activeTab??'')===$key?'aria-current="page"':'' ?>><?= $navEscape($label) ?></a><?php endforeach; ?>
</nav></details>
