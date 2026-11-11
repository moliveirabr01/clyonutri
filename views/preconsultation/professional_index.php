<?php
use ClyoNutri\Domain\PreConsultation\QuestionBank;

$statusLabel = ['draft' => 'Em edição', 'sent' => 'Enviado', 'in_progress' => 'Paciente respondendo', 'submitted' => 'Respondido', 'reviewed' => 'Revisado', 'revoked' => 'Revogado'];
$pid = (int)$patient['id'];
?>
<nav aria-label="Navegação" style="margin-bottom: var(--space-3); font-size: 0.9rem;"><a href="/pacientes/<?= $pid ?>">← Perfil do paciente</a></nav>
<h1 style="font-size: 1.5rem; font-weight: 700; color: var(--brand-navy-900); margin: 0 0 4px;">Pré-consulta</h1>
<p style="color: var(--neutral-gray-500); margin: 0 0 var(--space-4);"><?= $e($patient['name']) ?> · Questionário enviado antes da consulta. O sistema só sugere perguntas: você revisa tudo e nada gera diagnóstico automático.</p>

<?php if ($flash): ?>
  <div role="<?= $flash['type'] === 'error' ? 'alert' : 'status' ?>" class="card" style="border-left: 4px solid <?= $flash['type'] === 'error' ? 'var(--semantic-error, #B42318)' : 'var(--semantic-success)' ?>; margin-bottom: var(--space-4);"><?= $e($flash['message']) ?></div>
<?php endif; ?>

<?php if ($canEdit): ?>
<section class="card" style="margin-bottom: var(--space-5);" aria-labelledby="pc-new">
  <h2 id="pc-new" style="font-size: 1.15rem; margin: 0 0 12px;">Nova pré-consulta</h2>
  <form method="POST" action="/pacientes/<?= $pid ?>/pre-consulta">
    <input type="hidden" name="_csrf_token" value="<?= $e($csrfToken) ?>">
    <fieldset style="border: 0; padding: 0; margin: 0 0 12px;">
      <legend class="form-label" style="font-weight: 600;">Objetivos declarados pelo paciente</legend>
      <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(240px, 1fr)); gap: 12px;">
        <?php foreach (QuestionBank::CATEGORIES as $cat => $catLabel): ?>
          <div>
            <p style="margin: 0 0 6px; font-weight: 600; color: var(--neutral-gray-600, #475467);"><?= $e($catLabel) ?></p>
            <?php foreach (QuestionBank::OBJECTIVES as $code => $o): if ($o['category'] !== $cat) continue; ?>
              <label style="display: flex; gap: 8px; align-items: flex-start; margin-bottom: 6px;">
                <input type="checkbox" name="objectives[]" value="<?= $e($code) ?>" style="margin-top: 3px;"> <span><?= $e($o['label']) ?></span>
              </label>
            <?php endforeach; ?>
          </div>
        <?php endforeach; ?>
      </div>
    </fieldset>
    <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(240px, 1fr)); gap: 12px; margin-bottom: 12px;">
      <div class="form-group">
        <label class="form-label" for="pc-appointment">Consulta agendada (opcional)</label>
        <select class="form-control" id="pc-appointment" name="appointment_id" aria-describedby="pc-appointment-help">
          <option value="">Sem vínculo</option>
          <?php foreach ($appointments as $a): ?><option value="<?= (int)$a['id'] ?>"><?= $e(date('d/m/Y \à\s H:i', strtotime((string)$a['scheduled_at']))) ?></option><?php endforeach; ?>
        </select>
        <small id="pc-appointment-help" style="color: var(--neutral-gray-500);">Com a consulta vinculada, o link expira no horário dela.</small>
      </div>
      <fieldset class="form-group" style="border: 0; padding: 0; margin: 0;">
        <legend class="form-label">Como montar o questionário</legend>
        <label style="display: flex; gap: 8px;"><input type="radio" name="mode" value="suggest" checked> Usar a sugestão pelos objetivos</label>
        <label style="display: flex; gap: 8px;"><input type="radio" name="mode" value="blank"> Ignorar a sugestão (só perguntas gerais)</label>
      </fieldset>
    </div>
    <button type="submit" class="btn btn-primary">Criar proposta</button>
  </form>
</section>
<?php endif; ?>

<section class="card" aria-labelledby="pc-list">
  <h2 id="pc-list" style="font-size: 1.15rem; margin: 0 0 12px;">Questionários</h2>
  <?php if (!$list): ?>
    <p style="color: var(--neutral-gray-500);">Nenhuma pré-consulta criada para este paciente.</p>
  <?php else: ?>
    <div class="table-responsive">
      <table class="table" style="width: 100%;">
        <thead><tr><th scope="col">Criado em</th><th scope="col">Objetivos</th><th scope="col">Perguntas</th><th scope="col">Situação</th><th scope="col"><span class="sr-only">Ações</span></th></tr></thead>
        <tbody>
          <?php foreach ($list as $q): ?>
            <tr>
              <td><?= $e(date('d/m/Y H:i', strtotime((string)$q['created_at']))) ?></td>
              <td><?= $e(implode(', ', array_map(fn($o) => QuestionBank::OBJECTIVES[$o]['label'] ?? $o, $q['objectives'])) ?: 'Somente perguntas gerais') ?></td>
              <td class="tabular-nums"><?= count($q['questions']) ?></td>
              <td><?= $e($q['is_expired'] ? 'Link expirado' : ($statusLabel[$q['status']] ?? $q['status'])) ?><?= !empty($q['imported_anamnesis_id']) ? ' · importado' : '' ?></td>
              <td><a class="btn btn-sm btn-outline" href="/pacientes/<?= $pid ?>/pre-consulta/<?= (int)$q['id'] ?>">Abrir</a></td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  <?php endif; ?>
</section>
