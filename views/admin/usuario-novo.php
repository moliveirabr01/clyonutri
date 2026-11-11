<?php
/** v33 — Novo usuário da equipe (suporte etc.). Entradas: $formError, $old, $csrfToken. */
use ClyoNutri\Domain\Admin\UserAdministration as UA;

$e = static fn($v): string => htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
$fieldStyle = 'width: 100%; padding: .55rem .75rem; border: 1px solid var(--border-input, #828B9B); border-radius: 6px; font-size: .9rem; font-family: inherit;';
?>
<div style="margin-bottom: 1.25rem;">
  <h2 style="font-size: 1.5rem; font-weight: 700; color: var(--admin-navy-900); margin: 0 0 .25rem;">Novo usuário da equipe</h2>
  <p style="font-size: .88rem; color: var(--admin-gray-500); margin: 0;">Crie uma pessoa para operar a plataforma, por exemplo no suporte. Ela define a própria senha por um convite de uso único.</p>
</div>

<div class="admin-card" style="max-width: 720px;">
  <div class="admin-card__body">
    <?php if (!empty($formError)): ?>
      <div class="op-message op-message--error admin-alert admin-alert--error" role="alert"><?= $e($formError) ?></div>
    <?php endif; ?>
    <form action="/admin/usuarios" method="POST" autocomplete="off">
      <input type="hidden" name="_csrf_token" value="<?= $e($csrfToken ?? '') ?>">

      <div style="margin-bottom: 1rem;">
        <label for="staff-name" style="display: block; font-size: .88rem; font-weight: 600; margin-bottom: .35rem;">Nome completo</label>
        <input type="text" id="staff-name" name="name" required minlength="2" maxlength="120" value="<?= $e($old['name'] ?? '') ?>" style="<?= $fieldStyle ?>" autocomplete="off">
      </div>
      <div style="margin-bottom: 1rem;">
        <label for="staff-email" style="display: block; font-size: .88rem; font-weight: 600; margin-bottom: .35rem;">E-mail de acesso</label>
        <input type="email" id="staff-email" name="email" required maxlength="190" value="<?= $e($old['email'] ?? '') ?>" style="<?= $fieldStyle ?>" autocomplete="off">
        <p style="margin: .3rem 0 0; font-size: .78rem; color: var(--admin-gray-500);">O convite vai para este e-mail. Não pode ser de um usuário já cadastrado.</p>
      </div>

      <fieldset style="border: 0; padding: 0; margin: 0 0 1rem;">
        <legend style="font-size: .88rem; font-weight: 600; margin-bottom: .45rem; padding: 0;">Perfil</legend>
        <?php foreach (UA::STAFF_ROLES as $r): ?>
          <label style="display: flex; gap: .6rem; align-items: flex-start; padding: .6rem .75rem; border: 1px solid var(--admin-gray-200); border-radius: 6px; margin-bottom: .45rem; cursor: pointer;">
            <input type="radio" name="role" value="<?= $e($r) ?>" required style="margin-top: .25rem;" <?= ($old['role'] ?? '') === $r ? 'checked' : '' ?>>
            <span>
              <strong style="font-size: .9rem;"><?= $e(UA::roleLabel($r)) ?></strong><br>
              <small style="color: var(--admin-gray-600);"><?= $e(UA::ROLE_DESCRIPTIONS[$r] ?? '') ?></small>
            </span>
          </label>
        <?php endforeach; ?>
        <p style="margin: .3rem 0 0; font-size: .78rem; color: var(--admin-gray-500);">O perfil de super admin não é oferecido aqui, por segurança: ele só é criado no servidor.</p>
      </fieldset>

      <div style="padding: .75rem 1rem; background: var(--admin-gray-50); border: 1px solid var(--admin-gray-200); border-radius: 6px; font-size: .82rem; color: var(--admin-gray-700); margin-bottom: 1rem; line-height: 1.5;">
        <strong>Como funciona o acesso:</strong> a pessoa recebe um link de uso único (válido por 72 horas) para criar a senha. A senha da equipe tem no mínimo 12 caracteres, com letras e números ou símbolos. Se o servidor não entregar e-mail, o link aparece uma única vez para você copiar. Contas da equipe não passam pelo aceite de termos de clínicas.
      </div>

      <label style="display: flex; gap: .55rem; align-items: flex-start; font-size: .88rem; margin-bottom: 1.25rem;">
        <input type="checkbox" name="confirmar" value="1" required style="margin-top: .2rem;">
        <span>Confirmo que esta pessoa deve ter acesso ao console da plataforma com o perfil escolhido.</span>
      </label>
      <div style="display: flex; gap: .75rem; flex-wrap: wrap;">
        <button type="submit" class="btn btn--primary">Criar usuário e gerar convite</button>
        <a href="/admin/usuarios" class="btn btn--secondary">Cancelar</a>
      </div>
    </form>
  </div>
</div>
