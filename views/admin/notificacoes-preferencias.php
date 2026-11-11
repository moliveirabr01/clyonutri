<?php
/** v34 — Preferências de notificação da equipe. Entradas: $prefs, $mail (available, reason), $groups, $receives, $csrfToken. */
$e = static fn($v): string => htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
$mailOk = !empty($mail['available']);
$byGroup = [];
foreach ($prefs as $p) {
    $byGroup[$p['group']][] = $p;
}
?>
<style>
  .np-table { width: 100%; border-collapse: collapse; font-size: .88rem; }
  .np-table th { text-align: left; font-size: .75rem; text-transform: uppercase; letter-spacing: .04em; color: var(--admin-gray-600); padding: .6rem 1rem; background: var(--admin-gray-50); border-bottom: 1px solid var(--admin-gray-200); }
  .np-table td { padding: .7rem 1rem; border-bottom: 1px solid var(--admin-gray-200); vertical-align: middle; }
  .np-table th.np-col, .np-table td.np-col { width: 130px; text-align: center; }
  .np-check { display: inline-flex; align-items: center; justify-content: center; min-width: 44px; min-height: 44px; }
  .np-check input { width: 20px; height: 20px; }
  .np-group { font-size: 1rem; font-weight: 700; color: var(--admin-navy-900); margin: 0; padding: 1rem 1rem .25rem; }
  @media (max-width: 600px) { .np-table th.np-col, .np-table td.np-col { width: 84px; } .np-table th, .np-table td { padding: .55rem .5rem; } }
</style>

<div style="margin-bottom: 1.25rem;">
  <h2 style="font-size: 1.5rem; font-weight: 700; color: var(--admin-navy-900); margin: 0 0 .25rem;">Preferências de notificação</h2>
  <p style="font-size: .88rem; color: var(--admin-gray-500); margin: 0;">Escolha quais avisos aparecem na sua central. Tudo vem ligado por padrão.</p>
</div>

<?php if (!$receives): ?>
  <div class="admin-card"><div class="admin-card__body">Seu perfil não recebe notificações da plataforma. Por padrão, só o super admin recebe.</div></div>
<?php else: ?>
<form method="POST" action="/admin/notificacoes/preferencias">
  <input type="hidden" name="_csrf_token" value="<?= $e($csrfToken ?? '') ?>">

  <div class="admin-card" style="margin-bottom: 1rem;">
    <div class="admin-card__body" style="font-size: .86rem; line-height: 1.55; color: var(--admin-gray-700);">
      <strong>Aviso por e-mail.</strong>
      <?php if ($mailOk): ?>
        Marque "Também por e-mail" nos avisos em que quiser um e-mail curto, com o título e um link para a central, sem nomes nem dados de clínicas. O envio depende do servidor de e-mail da plataforma.
      <?php else: ?>
        Indisponível por enquanto: <?= $e($mail['reason'] ?? 'o servidor de e-mail não está configurado.') ?> As caixas ficam desabilitadas até a configuração. Os avisos continuam aparecendo na central.
      <?php endif; ?>
    </div>
  </div>

  <?php foreach ($byGroup as $groupKey => $rows): ?>
  <div class="admin-card" style="margin-bottom: 1rem; overflow-x: auto;">
    <h3 class="np-group"><?= $e($groups[$groupKey] ?? $groupKey) ?></h3>
    <table class="np-table">
      <thead>
        <tr>
          <th scope="col">Aviso</th>
          <th scope="col" class="np-col">Na central</th>
          <th scope="col" class="np-col">Também por e-mail</th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($rows as $r): $idApp = 'app-' . md5($r['type']); $idMail = 'mail-' . md5($r['type']); ?>
        <tr>
          <td><?= $e($r['label']) ?></td>
          <td class="np-col">
            <label class="np-check"><input type="checkbox" id="<?= $e($idApp) ?>" name="app[<?= $e($r['type']) ?>]" value="1" <?= $r['app'] ? 'checked' : '' ?> aria-label="Mostrar na central: <?= $e($r['label']) ?>"></label>
          </td>
          <td class="np-col">
            <label class="np-check"><input type="checkbox" id="<?= $e($idMail) ?>" name="email[<?= $e($r['type']) ?>]" value="1" <?= ($mailOk && $r['email']) ? 'checked' : '' ?> <?= $mailOk ? '' : 'disabled' ?> aria-label="Também por e-mail: <?= $e($r['label']) ?><?= $mailOk ? '' : ' (indisponível: e-mail não configurado)' ?>"></label>
          </td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
  <?php endforeach; ?>

  <div style="display: flex; gap: .75rem; flex-wrap: wrap;">
    <button type="submit" class="btn btn--primary">Salvar preferências</button>
    <a href="/admin/notificacoes" class="btn btn--secondary">Voltar às notificações</a>
  </div>
  <p style="font-size: .78rem; color: var(--admin-gray-500); margin-top: .75rem;">Um aviso desligado deixa de ser criado para você. O e-mail só vale para avisos que continuam ligados na central.</p>
</form>
<?php endif; ?>
