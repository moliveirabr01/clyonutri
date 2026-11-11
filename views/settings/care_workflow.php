<?php $e=static fn($v)=>htmlspecialchars((string)$v,ENT_QUOTES,'UTF-8'); ?>
<nav class="op-breadcrumb" aria-label="Navegação de configurações"><a href="/configuracoes">Configurações</a> / Etapas do atendimento</nav>
<h1>Etapas por tipo de consulta</h1><p>A gestão define o padrão deste consultório. O profissional pode ajustar cada atendimento e dispensar etapas com motivo. Nenhuma etapa bloqueia uma decisão clínica.</p>
<?php if($error): ?><?= \ClyoNutri\Application\View\OperationalUI::alert('Não foi possível salvar',$error,'critical') ?><?php endif; ?>
<?php if($saved): ?><?= \ClyoNutri\Application\View\OperationalUI::alert('Padrão salvo','O padrão foi persistido. Decisões individuais do atendimento prevalecem.','success') ?><?php endif; ?>
<?php foreach(\ClyoNutri\Domain\Appointment\CareWorkflowPolicy::TYPES as $key=>$label): $policy=$policies[$key]; ?>
<details class="card" <?= $error&&($_POST['type']??'')===$key?'open':'' ?>><summary><?= $e($label) ?></summary>
<form method="post" class="op-stack" action="/configuracoes/atendimento">
<input type="hidden" name="_csrf_token" value="<?= $e(\ClyoNutri\Core\Security\Csrf::token()) ?>"><input type="hidden" name="type" value="<?= $e($key) ?>"><input type="hidden" name="revision" value="<?= $e($policy['revision']) ?>"><input type="hidden" name="request_key" value="<?= bin2hex(random_bytes(16)) ?>">
<?php foreach(\ClyoNutri\Domain\Appointment\CareProgress::STEPS as $step=>$name): $current=$error&&($_POST['type']??'')===$key?($_POST['steps'][$step]??''):($policy['steps'][$step]??''); ?>
<label><?= $e($name) ?><select name="steps[<?= $e($step) ?>]" class="form-control" required><option value="">Definir aplicabilidade</option><option value="required" <?= $current==='required'?'selected':'' ?>>Necessária</option><option value="optional" <?= $current==='optional'?'selected':'' ?>>Opcional</option></select></label>
<?php endforeach; ?>
<button class="op-button op-button--primary" type="submit">Salvar padrão de <?= $e($label) ?></button></form></details>
<?php endforeach; ?>
