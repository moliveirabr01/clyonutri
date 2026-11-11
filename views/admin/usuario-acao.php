<?php
/** v33 — Confirmação de uma ação sobre usuário (segunda etapa). Entradas: $target, $action, $formError, $reasonValue, $csrfToken. */
use ClyoNutri\Domain\Admin\UserAdministration as UA;

$e = static fn($v): string => htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
$info = [
    'suspender' => ['Suspender usuário', 'Pausa administrativa', ['O usuário não consegue entrar enquanto estiver suspenso.', 'As sessões ativas e os acessos de app são encerrados agora.', 'A senha continua a mesma. Para voltar, basta reativar.', 'Dados, pacientes e prontuários não são alterados.'], 'btn--secondary'],
    'reativar' => ['Reativar usuário', 'Reativação direta', ['O usuário volta a entrar com a senha de sempre.', 'Ele recebe um aviso de segurança por e-mail.'], 'btn--primary'],
    'bloquear' => ['Bloquear usuário', 'Corte de segurança', ['O acesso é cortado e todas as sessões e acessos de app são encerrados agora.', 'Convites e links de senha pendentes deixam de valer.', 'Só o super admin libera o acesso, e a pessoa precisa criar uma nova senha para voltar.', 'Dados, pacientes e prontuários não são alterados.'], 'btn--danger'],
    'liberar' => ['Liberar acesso', 'Fim do bloqueio', ['A senha antiga é invalidada: ninguém entra com ela.', 'Um link de uso único (60 minutos) é emitido para a pessoa criar uma nova senha.', 'O link vai por e-mail; se o servidor não entregar e-mail, ele aparece uma única vez para você copiar.'], 'btn--primary'],
    'redefinir-senha' => ['Redefinir senha', 'Nova senha por link de uso único', ['A senha atual é invalidada e as sessões e acessos de app são encerrados agora.', 'Você não vê nem define a senha: a pessoa cria a nova por um link de uso único (60 minutos).', 'O link vai por e-mail; se o servidor não entregar e-mail, ele aparece uma única vez para você copiar.'], 'btn--primary'],
    'reenviar-convite' => ['Reenviar convite', 'Novo convite de 72 horas', ['O convite anterior deixa de valer.', 'O link vai por e-mail; se o servidor não entregar e-mail, ele aparece uma única vez para você copiar.'], 'btn--primary'],
    'revogar-sessoes' => ['Encerrar sessões', 'Logout forçado', ['Todas as sessões e acessos de app do usuário são encerrados agora.', 'A senha e o status não mudam; a pessoa pode entrar de novo.'], 'btn--danger'],
];
[$heading, $kicker, $effects, $btnClass] = $info[$action];
$needsReason = UA::requiresReason($action);
?>
<div style="margin-bottom: 1.25rem;">
  <p style="margin: 0 0 .25rem; font-size: .8rem; font-weight: 600; letter-spacing: .04em; text-transform: uppercase; color: var(--admin-gray-500);"><?= $e($kicker) ?></p>
  <h2 style="font-size: 1.5rem; font-weight: 700; color: var(--admin-navy-900); margin: 0;"><?= $e($heading) ?></h2>
</div>

<div class="admin-card" style="max-width: 720px;">
  <div class="admin-card__body">
    <p style="margin: 0 0 1rem; font-size: .92rem;">
      Usuário: <strong><?= $e($target['name']) ?></strong> · <?= $e($target['email']) ?>
      <span class="badge badge--info"><?= $e(UA::roleLabel((string)$target['role'])) ?></span>
      <span class="badge badge--<?= ['active' => 'active', 'suspended' => 'pending', 'blocked' => 'suspended', 'invited' => 'info'][(string)$target['status']] ?? 'info' ?>"><?= $e(UA::statusLabel((string)$target['status'])) ?></span>
    </p>

    <p style="margin: 0 0 .4rem; font-size: .88rem; font-weight: 600;">O que acontece:</p>
    <ul style="margin: 0 0 1.25rem; padding-left: 1.2rem; font-size: .88rem; color: var(--admin-gray-700); line-height: 1.55;">
      <?php foreach ($effects as $line): ?><li><?= $e($line) ?></li><?php endforeach; ?>
    </ul>

    <?php if (!empty($formError)): ?>
      <div class="op-message op-message--error admin-alert admin-alert--error" role="alert"><?= $e($formError) ?></div>
    <?php endif; ?>

    <form action="/admin/usuarios/<?= (int)$target['id'] ?>/acao/<?= $e($action) ?>" method="POST" autocomplete="off">
      <input type="hidden" name="_csrf_token" value="<?= $e($csrfToken ?? '') ?>">
      <?php if ($needsReason): ?>
        <label for="reason" style="display: block; font-size: .88rem; font-weight: 600; margin-bottom: .35rem;">Motivo (obrigatório)</label>
        <textarea id="reason" name="reason" required minlength="<?= UA::REASON_MIN ?>" maxlength="<?= UA::REASON_MAX ?>" rows="3" class="form-control" style="width: 100%; padding: .55rem .75rem; border: 1px solid var(--border-input, #828B9B); border-radius: 6px; font-size: .88rem; font-family: inherit;" placeholder="Ex.: pedido do titular, suspeita de acesso indevido, chamado #123"><?= $e($reasonValue ?? '') ?></textarea>
        <p style="margin: .3rem 0 1rem; font-size: .78rem; color: var(--admin-gray-500);">De <?= UA::REASON_MIN ?> a <?= UA::REASON_MAX ?> caracteres. Fica registrado na auditoria com o seu nome. Não escreva senhas nem links.</p>
      <?php endif; ?>
      <label style="display: flex; gap: .55rem; align-items: flex-start; font-size: .88rem; margin-bottom: 1.25rem;">
        <input type="checkbox" name="confirmar" value="1" required style="margin-top: .2rem;">
        <span>Entendi o que acontece e quero continuar.</span>
      </label>
      <div style="display: flex; gap: .75rem; flex-wrap: wrap;">
        <button type="submit" class="btn <?= $btnClass ?>"><?= $e(UA::actionLabel($action)) ?></button>
        <a href="/admin/usuarios/<?= (int)$target['id'] ?>" class="btn btn--secondary">Cancelar</a>
      </div>
    </form>
  </div>
</div>
