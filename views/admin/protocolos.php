<?php /* NI2-635: catálogo de protocolos clínicos. Entradas: $rows (?array), $flag, $flash, $csrfToken. */
$e = static fn($v): string => htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); ?>
<div style="margin-bottom: 1.5rem;">
  <h2 style="font-size: 1.5rem; font-weight: 700; color: var(--admin-navy-900);">Protocolos clínicos</h2>
  <p style="font-size: 0.88rem; color: var(--admin-gray-500); max-width: 820px;">Módulos da anamnese adaptativa (NI2-635). Cada versão passa por rascunho, revisão e aprovação de um nutricionista habilitado diferente do autor. Nada aparece para clínicas e pacientes sem versão aprovada, sem a chave global ligada e sem a clínica ligar o protocolo.</p>
  <p style="font-size: 0.85rem;" data-testid="protocols-flag">Chave global: <strong><?= $flag ? 'ligada' : 'desligada' ?></strong> (Configurações globais › feature_flag_clinical_protocols)</p>
</div>
<?php if ($flash): ?><div class="admin-alert admin-alert--<?= $flash['type'] === 'error' ? 'danger' : 'success' ?>" role="<?= $flash['type'] === 'error' ? 'alert' : 'status' ?>" data-testid="protocol-flash" style="margin-bottom: 1rem; padding: .75rem 1rem; border-radius: 6px; background: <?= $flash['type'] === 'error' ? '#FEF3F2' : '#ECFDF3' ?>;"><?= $e($flash['text']) ?></div><?php endif; ?>
<?php if ($rows === null): ?>
  <div class="admin-card" style="padding: 1rem;">A migração 78 ainda não foi aplicada neste banco.</div>
<?php else: ?>
<div class="admin-card">
  <div class="admin-table-wrapper">
    <table class="admin-table" data-testid="protocols-table">
      <thead><tr><th>Protocolo</th><th>Tipo</th><th>Fase</th><th>Versão aprovada</th><th>Em revisão</th><th></th></tr></thead>
      <tbody>
      <?php if (!$rows): ?><tr><td colspan="6">Nenhum protocolo registrado.</td></tr><?php endif; ?>
      <?php foreach ($rows as $r): ?>
        <tr>
          <td><strong><?= $e($r['name']) ?></strong><br><code><?= $e($r['code']) ?></code></td>
          <td><?= $r['kind'] === 'profile' ? 'Perfil (não-diagnóstico)' : 'Condição' ?></td>
          <td><?= $e($r['catalog_phase']) ?><?= (int)$r['requires_specialist_review'] ? ' · especialista' : '' ?></td>
          <td><?= $r['approved_version'] ? 'v' . (int)$r['approved_version'] : '<span style="color: var(--admin-gray-500);">nenhuma</span>' ?></td>
          <td><?= (int)$r['in_review'] ?: '—' ?></td>
          <td><a class="btn btn--secondary btn--sm" href="/admin/protocolos/<?= (int)$r['id'] ?>">Abrir</a></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>
<div class="admin-card" style="margin-top: 1.5rem; padding: 1rem 1.25rem;">
  <h3 style="margin-top: 0;">Registrar protocolo</h3>
  <form method="POST" action="/admin/protocolos" data-testid="protocol-register" style="display: grid; gap: .75rem; grid-template-columns: repeat(auto-fit, minmax(180px, 1fr)); align-items: end;">
    <input type="hidden" name="_csrf_token" value="<?= $e($csrfToken) ?>">
    <label>Código<br><input name="code" required pattern="[a-z][a-z0-9_]{2,59}" placeholder="diabetes_t2" style="width: 100%;"></label>
    <label>Nome<br><input name="name" required maxlength="160" style="width: 100%;"></label>
    <label>Tipo<br><select name="kind" style="width: 100%;"><option value="condition">Condição</option><option value="profile">Perfil não-diagnóstico</option></select></label>
    <label>Fase<br><select name="phase" style="width: 100%;"><option>A</option><option>B</option><option value="C">C (exige especialista)</option></select></label>
    <label style="display: flex; gap: .5rem; align-items: center;"><input type="checkbox" name="specialist" value="1"> Exige revisão especializada</label>
    <button class="btn btn--primary btn--sm" type="submit">Registrar</button>
  </form>
</div>
<?php endif; ?>
