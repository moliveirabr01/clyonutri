<?php
/** NI2-606: Configurações > Equipe. Entradas: $members, $limit, $canManage, $roles, $flash, $old, $history, $csrfToken, $selfId. */
$e = static fn($v): string => htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
$activeTab = 'equipe';
$historyLabels = [
    'TEAM_MEMBER_INVITED' => 'Convite enviado', 'TEAM_INVITE_RESENT' => 'Convite reenviado', 'TEAM_MEMBER_ROLE_CHANGED' => 'Papel alterado',
    'TEAM_MEMBER_DEACTIVATED' => 'Acesso desativado', 'TEAM_MEMBER_REACTIVATED' => 'Acesso reativado', 'TEAM_INVITE_DELIVERY' => 'Entrega do convite',
];
$nameById = [];
foreach ($members as $m) { $nameById[(int)$m['user_id']] = $m['name']; }
?>
<h1>Configurações</h1>
<p>Gerencie seu cadastro, identificação profissional, horários de atendimento, equipe, senha e assinatura.</p>
<?php require __DIR__ . '/_tabs.php'; ?>

<?php if ($flash): ?>
  <div role="<?= $flash['type'] === 'error' ? 'alert' : 'status' ?>" class="card team-flash team-flash--<?= $e($flash['type']) ?>" data-testid="team-flash">
    <p class="u-m-0"><?= $e($flash['text']) ?></p>
    <?php if (!empty($flash['link'])): ?>
      <label class="form-label u-mt-2" for="team-invite-link">Link do convite (aparece só agora; vale até <?= $e($flash['expires_at'] ?? '') ?>)</label>
      <input class="form-control" id="team-invite-link" data-testid="team-invite-link" type="text" readonly value="<?= $e($flash['link']) ?>">
    <?php endif; ?>
  </div>
<?php endif; ?>

<section class="card" aria-labelledby="team-title" data-testid="team-list">
  <div class="card__header u-flex u-justify-between u-items-center u-wrap u-gap-2">
    <h2 id="team-title" class="u-m-0">Equipe da clínica</h2>
    <span class="badge <?= $limit['available'] ? 'badge-info' : 'badge-warning' ?>" data-testid="team-limit">
      Pessoas na equipe: <?= (int)$limit['used'] ?> <?= $limit['unlimited'] ? '(sem limite no plano)' : 'de ' . (int)$limit['max'] . ' do plano' ?><?= $limit['plan_name'] !== '' ? ' ' . $e($limit['plan_name']) : '' ?>
    </span>
  </div>
  <div class="table-responsive">
    <table class="table team-table">
      <thead><tr><th>Pessoa</th><th>Papel</th><th>Situação</th><?php if ($canManage): ?><th class="u-text-right">Ações</th><?php endif; ?></tr></thead>
      <tbody>
      <?php foreach ($members as $m): $uid = (int)$m['user_id']; $isOwner = $m['member_role'] === 'owner'; $isSelf = $uid === $selfId; ?>
        <tr data-testid="team-member" data-user="<?= $uid ?>" data-state="<?= $e($m['state']) ?>">
          <td>
            <strong><?= $e($m['name']) ?></strong><?= $isSelf ? ' <span class="badge badge-info">você</span>' : '' ?>
            <div class="u-text-compact u-color-neutral-gray-600"><?= $e($m['email']) ?><?= !empty($m['crn']) ? ' · ' . $e($m['crn_region'] . ' ' . $m['crn']) : '' ?></div>
          </td>
          <td><?= $e($m['role_label']) ?></td>
          <td><span class="badge <?= $m['state'] === 'active' ? 'badge-success' : ($m['state'] === 'invited' ? 'badge-warning' : 'badge-secondary') ?>"><?= $e($m['state_label']) ?></span></td>
          <?php if ($canManage): ?>
          <td class="u-text-right">
            <?php if (!$isOwner && !$isSelf): ?>
              <div class="team-actions">
                <?php if ($m['state'] !== 'inactive' && in_array($m['member_role'], ['nutritionist', 'admin'], true)): ?>
                  <form method="POST" action="/configuracoes/equipe/<?= $uid ?>/papel" class="team-inline">
                    <input type="hidden" name="_csrf_token" value="<?= $e($csrfToken) ?>">
                    <label class="visually-hidden" for="role-<?= $uid ?>">Papel de <?= $e($m['name']) ?></label>
                    <select class="form-control" id="role-<?= $uid ?>" name="role">
                      <option value="nutritionist" <?= $m['member_role'] === 'nutritionist' ? 'selected' : '' ?>>Nutricionista</option>
                      <option value="admin" <?= $m['member_role'] === 'admin' ? 'selected' : '' ?>>Nutricionista gestor(a)</option>
                    </select>
                    <button class="btn btn-secondary btn-sm" type="submit">Salvar papel</button>
                  </form>
                <?php endif; ?>
                <?php if ($m['state'] === 'invited'): ?>
                  <form method="POST" action="/configuracoes/equipe/<?= $uid ?>/reenviar" class="team-inline">
                    <input type="hidden" name="_csrf_token" value="<?= $e($csrfToken) ?>">
                    <button class="btn btn-secondary btn-sm" type="submit">Reenviar convite</button>
                  </form>
                <?php endif; ?>
                <?php if ($m['state'] === 'inactive'): ?>
                  <form method="POST" action="/configuracoes/equipe/<?= $uid ?>/reativar" class="team-inline">
                    <input type="hidden" name="_csrf_token" value="<?= $e($csrfToken) ?>">
                    <button class="btn btn-secondary btn-sm" type="submit" data-testid="team-reactivate">Reativar</button>
                  </form>
                <?php else: ?>
                  <form method="POST" action="/configuracoes/equipe/<?= $uid ?>/desativar" class="team-inline" data-confirm="Desativar o acesso de <?= $e($m['name']) ?>? A pessoa sai do sistema na hora; o histórico dela continua preservado.">
                    <input type="hidden" name="_csrf_token" value="<?= $e($csrfToken) ?>">
                    <button class="btn btn-danger btn-sm" type="submit" data-testid="team-deactivate">Desativar</button>
                  </form>
                <?php endif; ?>
              </div>
            <?php else: ?>
              <span class="u-text-compact u-color-neutral-gray-500"><?= $isOwner ? 'Titular' : 'Seu acesso' ?></span>
            <?php endif; ?>
          </td>
          <?php endif; ?>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
  <?php if (!$canManage): ?><p class="u-mt-3 u-mb-0 u-mx-0 u-text-compact">Somente o titular ou um nutricionista gestor convida e altera a equipe.</p><?php endif; ?>
</section>

<?php if ($canManage): ?>
<section class="card u-mt-6" aria-labelledby="team-invite-title">
  <h2 id="team-invite-title">Convidar para a equipe</h2>
  <?php if (!$limit['available']): ?>
    <div class="op-message op-message--error team-flash team-flash--warning" role="alert" data-testid="team-limit-reached">
      <p class="u-m-0">Seu plano permite <?= (int)$limit['max'] ?> pessoas na equipe e todas as vagas estão ocupadas (convites pendentes também contam). Desative alguém ou troque de plano para convidar.</p>
      <p class="u-mt-2 u-mb-0 u-mx-0"><a class="btn btn-primary btn-sm" href="/configuracoes/assinatura#plano" data-testid="team-upgrade">Trocar de plano</a></p>
    </div>
  <?php else: ?>
  <p>A pessoa recebe um link para criar a própria senha (vale 72 horas, uma vez só) e já conta no limite do plano. O papel define o que ela pode fazer: nutricionistas atendem e prescrevem; gestores também gerenciam equipe e agenda da clínica; a recepção cuida da agenda, sem acesso clínico.</p>
  <form method="POST" action="/configuracoes/equipe" data-testid="team-invite-form">
    <input type="hidden" name="_csrf_token" value="<?= $e($csrfToken) ?>">
    <div class="team-grid">
      <div class="form-group"><label class="form-label" for="team-name">Nome completo</label><input class="form-control" id="team-name" name="name" required maxlength="120" value="<?= $e($old['name'] ?? '') ?>"></div>
      <div class="form-group"><label class="form-label" for="team-email">E-mail</label><input class="form-control" id="team-email" name="email" type="email" required maxlength="190" value="<?= $e($old['email'] ?? '') ?>"></div>
      <div class="form-group"><label class="form-label" for="team-role">Papel</label>
        <select class="form-control" id="team-role" name="role" required>
          <?php foreach ($roles as $rk => $rl): ?><option value="<?= $e($rk) ?>" <?= ($old['role'] ?? 'nutritionist') === $rk ? 'selected' : '' ?>><?= $e($rl) ?></option><?php endforeach; ?>
        </select>
      </div>
      <div class="form-group"><label class="form-label" for="team-crn">CRN (nutricionistas)</label><input class="form-control" id="team-crn" name="crn" maxlength="20" placeholder="Ex.: 12345" value="<?= $e($old['crn'] ?? '') ?>"></div>
      <div class="form-group"><label class="form-label" for="team-crn-region">Região do CRN</label>
        <select class="form-control" id="team-crn-region" name="crn_region">
          <option value="">Selecione</option>
          <?php for ($i = 1; $i <= 11; $i++): $rv = 'CRN-' . $i; ?><option value="<?= $rv ?>" <?= ($old['crn_region'] ?? '') === $rv ? 'selected' : '' ?>><?= $rv ?></option><?php endfor; ?>
        </select>
      </div>
    </div>
    <button type="submit" class="btn btn-primary" data-testid="team-invite-submit">Enviar convite</button>
  </form>
  <?php endif; ?>
</section>

<?php if ($history): ?>
<section class="card u-mt-6" aria-labelledby="team-history-title">
  <h2 id="team-history-title">Histórico da equipe</h2>
  <ul class="team-history" data-testid="team-history">
    <?php foreach ($history as $h): $d = json_decode((string)$h['details'], true) ?: []; ?>
      <li><span><?= $e(date('d/m/Y H:i', (int)strtotime((string)$h['created_at']))) ?></span> · <?= $e($historyLabels[$h['action']] ?? $h['action']) ?> · <?= $e($nameById[(int)($d['target_user_id'] ?? 0)] ?? 'pessoa #' . (int)($d['target_user_id'] ?? 0)) ?><?= isset($d['new_role']) ? ' → ' . $e(\ClyoNutri\Domain\Team\TeamService::roleLabel((string)$d['new_role'])) : '' ?><?= isset($d['actor_user_id']) ? ' · por ' . $e($nameById[(int)$d['actor_user_id']] ?? 'gestor #' . (int)$d['actor_user_id']) : '' ?></li>
    <?php endforeach; ?>
  </ul>
</section>
<?php endif; ?>
<?php endif; ?>
