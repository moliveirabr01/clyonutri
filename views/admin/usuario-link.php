<?php
/** v33 — Link de senha mostrado UMA vez (servidor sem e-mail ou envio falhou). Entrada: $result. */
$e = static fn($v): string => htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
$target = $result['target'];
$invite = in_array((string)($result['action'] ?? ''), ['criar', 'reenviar-convite'], true);
?>
<div style="margin-bottom: 1.25rem;">
  <h2 style="font-size: 1.5rem; font-weight: 700; color: var(--admin-navy-900); margin: 0;"><?= $invite ? 'Convite criado' : 'Link para criar a nova senha' ?></h2>
</div>

<div class="op-message op-message--info admin-alert admin-alert--success" role="status"><?= $e($result['message']) ?></div>

<div class="admin-card" style="max-width: 760px;">
  <div class="admin-card__body">
    <p style="margin: 0 0 .5rem; font-size: .9rem;">Envie este link para <strong><?= $e($target['name']) ?></strong> (<?= $e($target['email']) ?>) por um canal seguro.</p>
    <p style="margin: 0 0 1rem; padding: .7rem .9rem; border-radius: 6px; background: #FFF8E6; border: 1px solid #F3D9A0; color: #6B4A06; font-size: .84rem;">
      <strong>Este link aparece uma única vez.</strong> Ele não fica guardado: se você sair desta tela, não dá para ver de novo. Para um novo link, repita a ação na ficha do usuário (o anterior deixa de valer).
    </p>
    <label for="link-unico" style="display: block; font-size: .88rem; font-weight: 600; margin-bottom: .35rem;">Link de uso único</label>
    <input type="text" id="link-unico" readonly value="<?= $e($result['link']) ?>" data-select-all autocomplete="off" spellcheck="false" style="width: 100%; padding: .6rem .75rem; border: 1px solid var(--border-input, #828B9B); border-radius: 6px; font-size: .84rem; font-family: ui-monospace, SFMono-Regular, Menlo, monospace; background: #fafbfc;">
    <p style="margin: .45rem 0 1rem; font-size: .8rem; color: var(--admin-gray-600);">
      Vale até <strong><?= $e($result['expires_at']) ?></strong> (horário de Brasília) e só funciona uma vez. Clique no campo para selecionar tudo e copie.
      <?= $e($result['delivery_note'] ?? '') ?>
    </p>
    <p style="margin: 0 0 1.25rem; font-size: .8rem; color: var(--admin-gray-600);">A pessoa define a própria senha<?= $invite ? ' no primeiro acesso' : '' ?>. Você nunca vê nem digita a senha.</p>
    <a href="/admin/usuarios/<?= (int)$target['id'] ?>" class="btn btn--primary">Ir para a ficha do usuário</a>
    <a href="/admin/usuarios" class="btn btn--secondary">Voltar à lista</a>
  </div>
</div>
