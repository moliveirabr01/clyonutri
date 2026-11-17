<?php
/**
 * NI2-890: Tela própria de aceite de convite da equipe (/convite/{token}).
 * Não usa a expressão "Redefinir senha" e exige aceite dos Termos e Política de Privacidade.
 */
$e = static fn($v): string => htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
$clinicLabel = !empty($tokenData['clinic_name']) ? (string)$tokenData['clinic_name'] : 'a clínica';
$roleLabel = \ClyoNutri\Domain\Team\TeamService::roleLabel((string)($tokenData['member_role'] ?? $tokenData['user_role'] ?? ''));
$minLen = (int)($tokenData['password_min_length'] ?? 8);
?><!DOCTYPE html>
<html lang="pt-BR">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Aceitar convite · Clyo Nutri</title>
  <link rel="stylesheet" href="/assets/css/fonts.css?v=v54-66">
  <link rel="stylesheet" href="/assets/css/operational-ui.css?v=v54-66">
</head>
<body style="background: var(--botanical-cream-50, #FAF9F4); min-height: 100vh; display: flex; align-items: center; justify-content: center; padding: 24px; margin: 0;">
  <main class="card" style="max-width: 460px; width: 100%; padding: 28px;" data-testid="team-invite-page">
    <h1 class="u-text-h2 u-font-bold u-color-botanical-forest-900 u-mt-0 u-mb-2">Bem-vindo à equipe</h1>

    <?php if (!empty($error)): ?>
      <div class="op-message op-message--error u-mb-4" role="alert" data-testid="team-invite-error">
        <?= $e($error) ?>
      </div>
    <?php endif; ?>

    <?php if (!empty($linkBlocked)): ?>
      <a href="/login" class="btn btn-secondary" data-testid="team-invite-blocked">Ir para entrar</a>
    <?php elseif (empty($isValidToken)): ?>
      <p class="u-text-body u-color-neutral-gray-700 u-mb-4" data-testid="team-invite-invalid">
        Este convite é inválido, já foi usado ou expirou. Peça ao responsável pela clínica para enviar um novo convite.
      </p>
      <a href="/login" class="btn btn-secondary">Ir para entrar</a>
    <?php else: ?>
      <p class="u-text-body u-color-neutral-gray-700 u-mb-4" data-testid="team-invite-welcome">
        Você foi convidado para <strong><?= $e($clinicLabel) ?></strong> como <strong><?= $e($roleLabel) ?></strong>. Crie sua senha e aceite os termos para ativar seu acesso.
      </p>

      <form method="POST" action="/convite/<?= $e(rawurlencode($token)) ?>">
        <input type="hidden" name="_csrf_token" value="<?= $e($csrfToken) ?>">

        <div class="form-group u-mb-3">
          <label for="invite-password" class="form-label">Criar senha</label>
          <input id="invite-password" type="password" name="password" class="form-control" required minlength="<?= $minLen ?>" autocomplete="new-password" placeholder="Mínimo de <?= $minLen ?> caracteres">
        </div>

        <div class="form-group u-mb-4">
          <label for="invite-password-confirm" class="form-label">Confirmar senha</label>
          <input id="invite-password-confirm" type="password" name="password_confirmation" class="form-control" required minlength="<?= $minLen ?>" autocomplete="new-password" placeholder="Repita a senha">
        </div>

        <label class="u-flex u-items-start u-gap-2 u-mb-4 u-text-compact u-pointer">
          <input type="checkbox" name="terms_accepted" value="1" required class="ds-check">
          <span>Li e aceito os <a href="/termos" target="_blank" rel="noopener">Termos de Uso</a> e a <a href="/privacidade" target="_blank" rel="noopener">Política de Privacidade</a>.</span>
        </label>

        <button type="submit" class="btn btn-primary u-w-full" data-testid="team-invite-submit">Aceitar convite e criar senha</button>
      </form>
    <?php endif; ?>
  </main>
</body>
</html>
