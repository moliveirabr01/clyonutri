<?php
/**
 * Célula "Último login" do backoffice (usuários, clínicas, busca).
 *
 * Entradas: $llInfo  (resultado de LastLoginPresenter::describe)
 *           $llWho   (opcional) nome da pessoa do último login da clínica; nunca IP nem user agent.
 * Tempo relativo em destaque + data absoluta (dd/mm/aaaa HH:MM, fuso da aplicação) em texto pequeno.
 */
$llInfo = $llInfo ?? ['state' => 'unavailable', 'rel' => '', 'abs' => '', 'iso' => '', 'note' => '', 'tz' => ''];
$llWho = isset($llWho) && is_string($llWho) && $llWho !== '' ? $llWho : null;
?>
<?php if (($llInfo['state'] ?? '') === 'seen'): ?>
  <time datetime="<?= htmlspecialchars((string)$llInfo['iso']) ?>" title="<?= htmlspecialchars($llInfo['abs'] . ' (' . $llInfo['tz'] . ')') ?>" style="font-weight: 600; color: var(--admin-navy-900);"><?= htmlspecialchars((string)$llInfo['rel']) ?></time>
  <br><small style="color: var(--admin-gray-500);"><?= htmlspecialchars((string)$llInfo['abs']) ?></small>
  <?php if ($llWho !== null): ?>
    <br><small style="color: var(--admin-gray-500);">por <?= htmlspecialchars($llWho) ?></small>
  <?php endif; ?>
<?php else: ?>
  <span style="color: var(--admin-gray-500);"><?= htmlspecialchars((string)($llInfo['rel'] ?? '')) ?></span>
  <?php if (($llInfo['note'] ?? '') !== ''): ?>
    <br><small style="color: var(--admin-gray-500);"><?= htmlspecialchars((string)$llInfo['note']) ?></small>
  <?php endif; ?>
<?php endif; ?>
