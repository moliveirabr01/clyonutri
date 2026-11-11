<?php
/**
 * v33 — Ficha do usuário no console da plataforma.
 * Entradas: $target, $memberships, $actions, $history, $canSeeHistory, $selfView, $isClinical,
 *           $lastLoginAt, $llAvailable, $llTrackingStart, $csrfToken.
 */
use ClyoNutri\Domain\Admin\UserAdministration as UA;

$e = static fn($v): string => htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
$status = (string)$target['status'];
$statusBadge = ['active' => 'active', 'suspended' => 'pending', 'blocked' => 'suspended', 'invited' => 'info'][$status] ?? 'info';
$allowed = array_column($actions, 'action');
$actionInfo = [
    'suspender' => ['Pausa administrativa. O usuário não entra enquanto estiver suspenso; a senha continua a mesma e a reativação é direta.', 'btn--secondary'],
    'reativar' => ['Tira da pausa. O usuário volta a entrar com a senha de sempre.', 'btn--primary'],
    'bloquear' => ['Corte de segurança. Sessões encerradas na hora; só o super admin libera, e o usuário precisa criar uma nova senha.', 'btn--danger'],
    'liberar' => ['Tira do bloqueio, invalida a senha antiga e emite um link para criar uma nova senha.', 'btn--primary'],
    'redefinir-senha' => ['Invalida a senha atual, encerra as sessões e emite um link de uso único (60 minutos) para criar uma nova senha.', 'btn--secondary'],
    'reenviar-convite' => ['Emite um novo convite (72 horas); o anterior deixa de valer.', 'btn--primary'],
    'revogar-sessoes' => ['Desconecta todas as sessões e acessos de app agora. A senha não muda.', 'btn--danger'],
];
$llInfo = \ClyoNutri\Core\Auth\LastLoginPresenter::describe($lastLoginAt ?? null, $target['created_at'] ?? null, $llTrackingStart ?? null, !empty($llAvailable));
$llWho = null;
?>
<style>
  .ud-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(320px, 1fr)); gap: 1.25rem; align-items: start; }
  .ud-dl { display: grid; grid-template-columns: 9.5rem 1fr; gap: .55rem 1rem; margin: 0; font-size: .88rem; }
  .ud-dl dt { color: var(--admin-gray-500); }
  .ud-dl dd { margin: 0; color: var(--admin-gray-900); word-break: break-word; }
  .ud-action { display: flex; gap: 1rem; align-items: center; justify-content: space-between; padding: .75rem 0; border-top: 1px solid var(--admin-gray-200); }
  .ud-action:first-of-type { border-top: 0; }
  .ud-action p { margin: 0; font-size: .82rem; color: var(--admin-gray-600); max-width: 34rem; }
  .ud-note { font-size: .84rem; color: var(--admin-gray-700); background: var(--admin-gray-50); border: 1px solid var(--admin-gray-200); border-radius: 6px; padding: .75rem 1rem; margin: 0 0 1rem; }
  .ud-note--danger { background: var(--admin-danger-bg); border-color: #fecaca; color: #7f1d1d; }
  .ud-note--warn { background: #FFF8E6; border-color: #F3D9A0; color: #6B4A06; }
  @media (max-width: 600px) { .ud-dl { grid-template-columns: 1fr; gap: .1rem; } .ud-dl dd { margin-bottom: .55rem; } .ud-action { flex-direction: column; align-items: flex-start; } }
</style>

<div style="margin-bottom: 1.25rem; display: flex; align-items: flex-start; justify-content: space-between; flex-wrap: wrap; gap: 1rem;">
  <div>
    <h2 style="font-size: 1.5rem; font-weight: 700; color: var(--admin-navy-900); margin: 0 0 .35rem;"><?= $e($target['name']) ?></h2>
    <p style="font-size: .88rem; color: var(--admin-gray-500); margin: 0 0 .5rem;"><?= $e($target['email']) ?></p>
    <span class="badge badge--info"><?= $e(UA::roleLabel((string)$target['role'])) ?></span>
    <span class="badge badge--<?= $statusBadge ?>" data-status="<?= $e($status) ?>"><?= $e(UA::statusLabel($status)) ?></span>
  </div>
  <a href="/admin/usuarios" class="btn btn--secondary btn--sm">← Voltar à lista</a>
</div>

<?php if ($status === 'blocked'): ?>
  <p class="ud-note ud-note--danger"><strong>Bloqueado.</strong> O acesso foi cortado por segurança. Só o super admin libera, e a pessoa precisa criar uma nova senha para voltar.</p>
<?php elseif ($status === 'suspended'): ?>
  <p class="ud-note ud-note--warn"><strong>Suspenso.</strong> Pausa administrativa: o acesso volta direto com "Reativar", com a mesma senha.</p>
<?php elseif ($status === 'invited'): ?>
  <p class="ud-note"><strong>Convite pendente.</strong> A pessoa ainda não criou a primeira senha. Quem tem permissão pode reenviar o convite.</p>
<?php endif; ?>

<div class="ud-grid">
  <div class="admin-card">
    <div class="admin-card__header"><h3 class="admin-card__title">Dados da conta</h3></div>
    <div class="admin-card__body">
      <dl class="ud-dl">
        <dt>ID</dt><dd><code>#<?= (int)$target['id'] ?></code></dd>
        <dt>Perfil global</dt><dd><?= $e(UA::roleLabel((string)$target['role'])) ?></dd>
        <dt>Status</dt><dd><?= $e(UA::statusLabel($status)) ?></dd>
        <dt>Cadastrado em</dt><dd><?= $e(date('d/m/Y', strtotime((string)$target['created_at']))) ?></dd>
        <dt>Último login</dt><dd><?php require __DIR__ . '/_ultimo_login.php'; ?></dd>
        <dt>Versão auth</dt><dd><span title="Sobe a cada encerramento de sessões, bloqueio ou troca de senha">v<?= (int)$target['auth_version'] ?></span></dd>
        <dt><?= count($memberships) === 1 ? 'Clínica' : 'Clínicas' ?></dt>
        <dd>
          <?php if (empty($memberships)): ?>Sem clínica<?php endif; ?>
          <?php foreach ($memberships as $m): ?>
            <div><?= $e($m['clinic_name']) ?> <small style="color: var(--admin-gray-500);">(<?= $e(UA::roleLabel((string)$m['member_role'])) ?><?= $m['clinic_status'] !== 'active' ? ', clínica ' . $e($m['clinic_status']) : '' ?>)</small></div>
          <?php endforeach; ?>
        </dd>
      </dl>
      <?php if ($isClinical): ?>
        <p class="ud-note" style="margin: 1rem 0 0;">Conta de clínica: as ações valem só para o login desta pessoa. Pacientes, prontuários e demais dados da clínica não são alterados nem apagados.</p>
      <?php endif; ?>
    </div>
  </div>

  <div class="admin-card">
    <div class="admin-card__header"><h3 class="admin-card__title">Ações</h3></div>
    <div class="admin-card__body">
      <?php if ($selfView): ?>
        <p class="ud-note">Esta é a sua conta. Por segurança, você não suspende, bloqueia nem redefine a senha de si mesmo por aqui. Para trocar a sua senha, use "Esqueci minha senha" no login.</p>
      <?php endif; ?>
      <?php if (empty($actions)): ?>
        <p style="margin: 0; font-size: .88rem; color: var(--admin-gray-600);">Nenhuma ação disponível para você neste usuário. Isso acontece quando o seu perfil não gere usuários, quando o usuário tem nível igual ou superior ao seu, ou quando o estado atual não permite nenhuma ação.</p>
      <?php else: ?>
        <?php foreach ($actionInfo as $key => [$desc, $btn]): ?>
          <?php if (in_array($key, $allowed, true)): ?>
            <div class="ud-action">
              <p><?= $e($desc) ?></p>
              <a class="btn <?= $btn ?> btn--sm" href="/admin/usuarios/<?= (int)$target['id'] ?>/acao/<?= $e($key) ?>" data-action="<?= $e($key) ?>"><?= $e(UA::actionLabel($key)) ?></a>
            </div>
          <?php endif; ?>
        <?php endforeach; ?>
      <?php endif; ?>
      <p style="margin: 1rem 0 0; font-size: .78rem; color: var(--admin-gray-500);"><strong>Suspenso x bloqueado:</strong> suspender é uma pausa administrativa (reativação direta). Bloquear é um corte por segurança: só o super admin libera e a pessoa cria uma nova senha.</p>
    </div>
  </div>
</div>

<div class="admin-card">
  <div class="admin-card__header"><h3 class="admin-card__title">Histórico administrativo</h3></div>
  <?php if (!$canSeeHistory): ?>
    <div class="admin-card__body"><p style="margin: 0; font-size: .88rem; color: var(--admin-gray-600);">O histórico fica visível para perfis com acesso à auditoria.</p></div>
  <?php elseif (empty($history)): ?>
    <div class="admin-card__body"><p style="margin: 0; font-size: .88rem; color: var(--admin-gray-600);">Nenhuma ação administrativa registrada para este usuário.</p></div>
  <?php else: ?>
    <div class="admin-table-wrapper">
      <table class="admin-table">
        <thead><tr><th>Quando</th><th>Ação</th><th>Quem fez</th><th>Motivo e detalhes</th><th>IP</th></tr></thead>
        <tbody>
          <?php foreach ($history as $h): $d = $h['detail']; ?>
            <tr>
              <td style="white-space: nowrap;"><?= $e(date('d/m/Y H:i', strtotime((string)$h['created_at']))) ?></td>
              <td><strong><?= $e($h['label']) ?></strong></td>
              <td><?= $e($h['operator_name'] ?? 'Sistema') ?></td>
              <td>
                <?php if (!empty($d['reason'])): ?><div><?= $e($d['reason']) ?></div><?php endif; ?>
                <?php if (!empty($d['previous_status']) && !empty($d['new_status'])): ?>
                  <small style="color: var(--admin-gray-500);"><?= $e(UA::statusLabel((string)$d['previous_status'])) ?> → <?= $e(UA::statusLabel((string)$d['new_status'])) ?></small>
                <?php endif; ?>
                <?php if (!empty($d['mode'])): ?>
                  <small style="color: var(--admin-gray-500);"><?= $d['mode'] === 'email_sent' ? 'Link enviado por e-mail' : 'Link mostrado uma vez ao super admin' ?></small>
                <?php endif; ?>
                <?php if (!empty($d['account_status'])): ?>
                  <small style="color: var(--admin-gray-500);">Conta <?= $e(mb_strtolower(UA::statusLabel((string)$d['account_status']))) ?></small>
                <?php endif; ?>
              </td>
              <td><small><?= $e($h['ip_address'] ?? '') ?></small></td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  <?php endif; ?>
</div>
