<div style="margin-bottom: 1.5rem; display: flex; align-items: center; justify-content: space-between; flex-wrap: wrap; gap: 1rem;">
  <div>
    <h2 style="font-size: 1.5rem; font-weight: 700; color: var(--admin-navy-900);">Governança comercial e catálogo de planos</h2>
    <p style="font-size: 0.88rem; color: var(--admin-gray-500);">Gestão de precificação, regras de faturamento e limites operacionais dos pacotes de assinatura.</p>
  </div>
</div>

<div class="kpi-grid kpi-grid--4">
  <div class="kpi-card">
    <div class="kpi-card__title">MRR contratado real</div>
    <div class="kpi-card__value" style="color: var(--admin-navy-900);"><?= htmlspecialchars($mrrFormatado ?? 'R$ 0,00') ?></div>
    <div class="kpi-card__subtitle">Receita mensal recorrente contratada</div>
  </div>
  <div class="kpi-card">
    <div class="kpi-card__title">Ticket médio contratado</div>
    <div class="kpi-card__value"><?= htmlspecialchars($ticketMedio ?? 'R$ 0,00') ?></div>
    <div class="kpi-card__subtitle">Por consultório ativo pagante</div>
  </div>
  <div class="kpi-card kpi-card--link">
    <div class="kpi-card__title">Assinaturas ativas</div>
    <div class="kpi-card__value" style="color: var(--admin-success);"><a class="kpi-card__link" href="/admin/comercial?status=active#assinantes"><span class="kpi-card__sr">Assinaturas ativas: </span><?= (int)($activeSubscriptions ?? 0) ?></a></div>
    <div class="kpi-card__subtitle"><?= (int)($canceledSubscriptions ?? 0) ?> <?= \ClyoNutri\Application\View\UserText::count((int)($canceledSubscriptions ?? 0), 'cancelamento histórico', 'cancelamentos históricos') ?></div>
  </div>
  <div class="kpi-card">
    <div class="kpi-card__title">Planos ativos</div>
    <div class="kpi-card__value"><?= (int)($activePlansCount ?? count($plans ?? [])) ?></div>
    <div class="kpi-card__subtitle">Catálogo comercial homologado</div>
  </div>
</div>

<div class="admin-card">
  <div class="admin-card__header">
    <h3 class="admin-card__title">Catálogo oficial de planos SaaS e clientes</h3>
  </div>
  <?php if (!empty($plans)): ?>
    <div class="admin-table-wrapper">
      <table class="admin-table">
        <thead>
          <tr>
            <th>Código</th>
            <th>Nome do Plano</th>
            <th>Preço mensal</th>
            <th>Limite profissionais</th>
            <th>Limite pacientes</th>
            <th>Armazenamento</th>
            <th>Assinantes ativos</th>
            <th>Status</th>
          </tr>
        </thead>
        <tbody>
          <?php 
            $subMap = [];
            foreach ($subscribersByPlan ?? [] as $sp) {
                $subMap[$sp['id']] = (int)$sp['active_subscribers'];
            }
          ?>
          <?php foreach ($plans as $p): ?>
            <tr>
              <td><code><?= htmlspecialchars($p['code']) ?></code></td>
              <td><strong><?= htmlspecialchars($p['name']) ?></strong></td>
              <td>R$ <?= number_format(((int)$p['price_cents']) / 100, 2, ',', '.') ?> / mês</td>
              <td>
                <?php if ((int)$p['max_professionals'] === 0): ?>
                  <span style="color: var(--admin-navy-800); font-weight: 500;">Ilimitado (multi-nutricionista)</span>
                <?php elseif ((int)$p['max_professionals'] === 1): ?>
                  1 nutricionista
                <?php else: ?>
                  Até <?= (int)$p['max_professionals'] ?> profissionais
                <?php endif; ?>
              </td>
              <td>
                <?php if ((int)$p['max_patients'] === 0): ?>
                  <span style="color: var(--admin-success); font-weight: 500;">Pacientes ilimitados</span>
                <?php else: ?>
                  Até <?= (int)$p['max_patients'] ?> pacientes
                <?php endif; ?>
              </td>
              <td>
                <?php
                  $mb = (int)$p['storage_mb'];
                  if ($mb >= 1024) {
                      echo round($mb / 1024) . ' GB documentos';
                  } else {
                      echo $mb . ' MB documentos';
                  }
                ?>
              </td>
              <td>
                <?php $planSubs = (int)($subMap[$p['id']] ?? 0); ?>
                <?php if ($planSubs > 0): ?>
                  <a href="/admin/comercial?plano=<?= rawurlencode((string)$p['code']) ?>&amp;status=active#assinantes" aria-label="Ver os <?= $planSubs ?> <?= \ClyoNutri\Application\View\UserText::count($planSubs, 'consultório ativo', 'consultórios ativos') ?> do plano <?= htmlspecialchars((string)$p['code']) ?>" style="color: var(--admin-navy-900); text-decoration: underline; text-underline-offset: 2px;"><strong><?= $planSubs ?></strong></a> <?= \ClyoNutri\Application\View\UserText::count($planSubs, 'consultório', 'consultórios') ?>
                <?php else: ?>
                  <strong>0</strong> consultórios
                <?php endif; ?>
              </td>
              <td>
                <?php if (!empty($p['is_active'])): ?>
                  <span class="badge badge--success">Ativo</span>
                <?php else: ?>
                  <span class="badge badge--suspended">Inativo</span>
                <?php endif; ?>
              </td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  <?php else: ?>
    <div style="padding: 2.5rem 1rem; text-align: center; color: var(--admin-gray-500);">
      <p style="font-size: 0.95rem; font-weight: 500; margin-bottom: 0.5rem;">Nenhum plano comercial cadastrado no catálogo.</p>
      <p style="font-size: 0.82rem;">Execute as migrações ou registre novos pacotes na tabela <code>saas_plans</code>.</p>
    </div>
  <?php endif; ?>
</div>

<?php
  $brl = static fn(int $cents): string => 'R$ ' . number_format($cents / 100, 2, ',', '.');
  $dmy = static function (?string $v): string {
      $t = ($v !== null && $v !== '') ? strtotime($v) : false;
      return $t === false ? '-' : date('d/m/Y', $t);
  };
  $statusLabels = \ClyoNutri\Domain\Subscription\CommercialSubscribers::STATUS_LABELS;
  $filtersActive = !empty($paginationQuery);
  $subRows = $subscriberRows ?? [];
?>
<div class="admin-card" id="assinantes">
  <div class="admin-card__header">
    <form action="/admin/comercial#assinantes" method="GET" style="display: flex; gap: 0.75rem; flex-wrap: wrap; align-items: center;">
      <h3 class="admin-card__title" style="margin-right: 0.5rem;">Assinantes e planos</h3>
      <select name="plano" aria-label="Filtrar por plano" style="padding: 0.45rem 0.75rem; border: 1px solid var(--border-input, #828B9B); border-radius: 6px; font-size: 0.85rem;">
        <option value="">Todos os planos</option>
        <?php foreach (($planOptions ?? []) as $po): ?>
          <option value="<?= htmlspecialchars($po['code']) ?>" <?= ($filterPlan ?? '') === $po['code'] ? 'selected' : '' ?>><?= htmlspecialchars($po['code'] . ' · ' . $po['name']) ?></option>
        <?php endforeach; ?>
      </select>
      <select name="status" aria-label="Filtrar por situação da assinatura" style="padding: 0.45rem 0.75rem; border: 1px solid var(--border-input, #828B9B); border-radius: 6px; font-size: 0.85rem;">
        <option value="">Todas as situações (ativas primeiro)</option>
        <?php foreach ($statusLabels as $stKey => $stLabel): ?>
          <option value="<?= htmlspecialchars($stKey) ?>" <?= ($filterStatus ?? '') === $stKey ? 'selected' : '' ?>><?= htmlspecialchars($stLabel) ?> (<?= (int)(($statusCounts ?? [])[$stKey] ?? 0) ?>)</option>
        <?php endforeach; ?>
      </select>
      <?php if (($pagination['per_page'] ?? 0) !== ($pagination['default_per_page'] ?? 0)): ?>
        <input type="hidden" name="per_page" value="<?= (int)$pagination['per_page'] ?>">
      <?php endif; ?>
      <button type="submit" class="btn btn--secondary btn--sm">Filtrar</button>
      <?php if ($filtersActive): ?>
        <a href="/admin/comercial#assinantes" class="btn btn--secondary btn--sm">Limpar</a>
      <?php endif; ?>
    </form>
    <div style="font-size: 0.85rem; color: var(--admin-gray-500); white-space: nowrap;">
      Total: <strong><?= (int)($pagination['total_records'] ?? 0) ?></strong> <?= (int)($pagination['total_records'] ?? 0) === 1 ? 'assinatura' : 'assinaturas' ?>
    </div>
  </div>

  <div class="admin-table-wrapper">
    <table class="admin-table">
      <thead>
        <tr>
          <th>Clínica (tenant)</th>
          <th>Plano</th>
          <th>Situação</th>
          <th>Valor mensal contratado</th>
          <th>Início</th>
          <th>Último login</th>
          <?php if (!empty($canActivatePlans)): ?><th>Ações</th><?php endif; ?>
        </tr>
      </thead>
      <tbody>
        <?php if (empty($subRows)): ?>
          <tr><td colspan="<?= !empty($canActivatePlans) ? 7 : 6 ?>" style="text-align: center; color: var(--admin-gray-400); padding: 2rem;">
            <?= $filtersActive ? 'Nenhuma assinatura com os filtros escolhidos.' : 'Nenhuma assinatura registrada ainda.' ?>
          </td></tr>
        <?php else: ?>
          <?php foreach ($subRows as $sr): ?>
            <?php
              $srStatus = (string)$sr['status'];
              $srIsActive = $srStatus === \ClyoNutri\Domain\Subscription\CommercialSubscribers::STATUS_ACTIVE;
              $srCycle = \ClyoNutri\Domain\Subscription\CommercialSubscribers::cycleLabel($sr['billing_cycle'] ?? null);
            ?>
            <tr>
              <td>
                <?php if (!empty($canOpenClinics) && ($sr['clinic_name'] ?? '') !== ''): ?>
                  <a href="/admin/clinicas?q=<?= rawurlencode((string)$sr['clinic_name']) ?>" style="font-weight: 600; color: var(--admin-navy-900);"><?= htmlspecialchars((string)$sr['clinic_name']) ?></a>
                <?php else: ?>
                  <span style="font-weight: 600; color: var(--admin-navy-900);"><?= htmlspecialchars((string)($sr['clinic_name'] ?? '') !== '' ? (string)$sr['clinic_name'] : 'Clínica removida') ?></span>
                <?php endif; ?>
                <?php if (($sr['clinic_status'] ?? '') === 'suspended'): ?>
                  <br><small style="color: var(--admin-danger);">Clínica suspensa</small>
                <?php endif; ?>
              </td>
              <td>
                <div style="font-weight: 600; color: var(--admin-navy-900);"><code><?= htmlspecialchars((string)($sr['plan_code'] ?? '-')) ?></code></div>
                <small style="color: var(--admin-gray-500);"><?= htmlspecialchars((string)($sr['plan_name'] ?? '')) ?></small>
              </td>
              <td>
                <span class="badge badge--<?= htmlspecialchars(\ClyoNutri\Domain\Subscription\CommercialSubscribers::statusBadge($srStatus)) ?>"><?= htmlspecialchars(\ClyoNutri\Domain\Subscription\CommercialSubscribers::statusLabel($srStatus)) ?></span>
                <?php if ($srStatus === 'trialing' && !empty($sr['trial_ends_at'])): ?>
                  <br><small style="color: var(--admin-gray-500);">teste até <?= htmlspecialchars($dmy($sr['trial_ends_at'])) ?></small>
                <?php elseif ($srStatus === 'canceled' && !empty($sr['canceled_at'])): ?>
                  <br><small style="color: var(--admin-gray-500);">cancelada em <?= htmlspecialchars($dmy($sr['canceled_at'])) ?></small>
                <?php endif; ?>
              </td>
              <td>
                <strong><?= htmlspecialchars($brl((int)$sr['monthly_cents'])) ?></strong> / mês
                <?php if ($srCycle !== 'mensal'): ?>
                  <br><small style="color: var(--admin-gray-500);">cobrança <?= htmlspecialchars($srCycle) ?>: <?= htmlspecialchars($brl((int)$sr['price_cents'])) ?></small>
                <?php endif; ?>
                <?php if (!$srIsActive): ?>
                  <br><small style="color: var(--admin-gray-500);">não entra no MRR</small>
                <?php endif; ?>
              </td>
              <td><?= htmlspecialchars($dmy($sr['started_at'] ?? null)) ?></td>
              <td>
                <?php
                  $llRow = ($llByClinic ?? [])[(int)$sr['clinic_id']] ?? null;
                  $llInfo = \ClyoNutri\Core\Auth\LastLoginPresenter::describe($llRow['at'] ?? null, $sr['clinic_created_at'] ?? null, $llTrackingStart ?? null, !empty($llAvailable));
                  $llWho = !empty($llShowWho) ? ($llRow['who'] ?? null) : null;
                  require __DIR__ . '/_ultimo_login.php';
                ?>
              </td>
              <?php if (!empty($canActivatePlans)): ?>
                <td>
                  <details>
                    <summary class="btn btn--secondary btn--sm" style="cursor: pointer; list-style: none;">Ativar / trocar plano</summary>
                    <form method="POST" action="/admin/comercial/ativar" style="margin-top: 0.5rem; display: flex; flex-direction: column; gap: 0.4rem; min-width: 210px;">
                      <input type="hidden" name="_csrf_token" value="<?= htmlspecialchars(\ClyoNutri\Core\Security\Csrf::token(), ENT_QUOTES) ?>">
                      <input type="hidden" name="clinic_id" value="<?= (int)$sr['clinic_id'] ?>">
                      <select name="plan_id" required aria-label="Plano alvo" style="padding: 0.35rem; border: 1px solid var(--border-input, #828B9B); border-radius: 6px; font-size: 0.8rem;">
                        <?php foreach (($plans ?? []) as $pOpt): ?>
                          <option value="<?= (int)$pOpt['id'] ?>" <?= (int)$pOpt['id'] === (int)($sr['plan_id'] ?? 0) ? 'selected' : '' ?>><?= htmlspecialchars((string)$pOpt['code'] . ' · ' . (string)$pOpt['name']) ?></option>
                        <?php endforeach; ?>
                      </select>
                      <input type="date" name="valid_until" required value="<?= date('Y-m-d', strtotime('+30 days')) ?>" aria-label="Válido até" style="padding: 0.35rem; border: 1px solid var(--border-input, #828B9B); border-radius: 6px; font-size: 0.8rem;">
                      <input type="text" name="reason" required maxlength="255" placeholder="Motivo obrigatório" aria-label="Motivo da ativação" style="padding: 0.35rem; border: 1px solid var(--border-input, #828B9B); border-radius: 6px; font-size: 0.8rem;">
                      <button type="submit" class="btn btn--primary btn--sm">Confirmar ativação</button>
                    </form>
                  </details>
                </td>
              <?php endif; ?>
            </tr>
          <?php endforeach; ?>
        <?php endif; ?>
      </tbody>
    </table>
  </div>
  <?php require __DIR__ . '/_pagination.php'; ?>
</div>

<?php if (!empty($activationRequests)): ?>
<div class="admin-card" id="pedidos-ativacao" style="margin-top: 1.5rem;">
  <div class="admin-card__header">
    <h3 class="admin-card__title">Pedidos de ativação pendentes (<?= count($activationRequests) ?>)</h3>
  </div>
  <div class="admin-table-wrapper">
    <table class="admin-table">
      <thead>
        <tr>
          <th>Data</th>
          <th>Clínica</th>
          <th>Plano solicitado</th>
          <th>Solicitante</th>
          <th>Observações</th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($activationRequests as $ar): ?>
          <tr>
            <td><?= htmlspecialchars($dmy($ar['created_at'] ?? null)) ?></td>
            <td><strong><?= htmlspecialchars((string)$ar['clinic_name']) ?></strong></td>
            <td><code><?= htmlspecialchars((string)$ar['plan_code']) ?></code> · <?= htmlspecialchars((string)$ar['plan_name']) ?></td>
            <td><?= htmlspecialchars((string)$ar['requester_name']) ?></td>
            <td><?= htmlspecialchars((string)($ar['notes'] ?? '—')) ?></td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>
<?php endif; ?>

<?php require __DIR__ . '/_comercial_trocas_pedidos.php'; ?>
