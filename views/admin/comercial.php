<div style="margin-bottom: 1.5rem; display: flex; align-items: center; justify-content: space-between; flex-wrap: wrap; gap: 1rem;">
  <div>
    <h2 style="font-size: 1.5rem; font-weight: 700; color: var(--admin-navy-900);">Governança Comercial & Catálogo de Planos</h2>
    <p style="font-size: 0.88rem; color: var(--admin-gray-500);">Gestão de precificação, regras de faturamento e limites operacionais dos pacotes de assinatura.</p>
  </div>
</div>

<div class="kpi-grid">
  <div class="kpi-card">
    <div class="kpi-card__title">MRR Contratado Real</div>
    <div class="kpi-card__value" style="color: var(--admin-navy-900);"><?= htmlspecialchars($mrrFormatado ?? 'R$ 0,00') ?></div>
    <div class="kpi-card__subtitle">Receita mensal recorrente contratada</div>
  </div>
  <div class="kpi-card">
    <div class="kpi-card__title">Ticket Médio Contratado</div>
    <div class="kpi-card__value"><?= htmlspecialchars($ticketMedio ?? 'R$ 0,00') ?></div>
    <div class="kpi-card__subtitle">Por consultório ativo pagante</div>
  </div>
  <div class="kpi-card">
    <div class="kpi-card__title">Assinaturas Ativas</div>
    <div class="kpi-card__value" style="color: var(--admin-success);"><?= (int)($activeSubscriptions ?? 0) ?></div>
    <div class="kpi-card__subtitle"><?= (int)($canceledSubscriptions ?? 0) ?> cancelamento(s) histórico(s)</div>
  </div>
  <div class="kpi-card">
    <div class="kpi-card__title">Planos Ativos</div>
    <div class="kpi-card__value"><?= (int)($activePlansCount ?? count($plans ?? [])) ?></div>
    <div class="kpi-card__subtitle">Catálogo comercial homologado</div>
  </div>
</div>

<div class="admin-card">
  <div class="admin-card__header">
    <h3 class="admin-card__title">Catálogo Oficial de Planos SaaS & Clientes</h3>
  </div>
  <?php if (!empty($plans)): ?>
    <div class="admin-table-wrapper">
      <table class="admin-table">
        <thead>
          <tr>
            <th>Código</th>
            <th>Nome do Plano</th>
            <th>Preço Mensal</th>
            <th>Limite Profissionais</th>
            <th>Limite Pacientes</th>
            <th>Armazenamento</th>
            <th>Assinantes Ativos</th>
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
                  <span style="color: var(--admin-success); font-weight: 500;">Pacientes Ilimitados</span>
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
                <strong><?= $subMap[$p['id']] ?? 0 ?></strong> consultório(s)
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
  <?php else: ?>
    <div style="padding: 2.5rem 1rem; text-align: center; color: var(--admin-gray-500);">
      <p style="font-size: 0.95rem; font-weight: 500; margin-bottom: 0.5rem;">Nenhum plano comercial cadastrado no catálogo.</p>
      <p style="font-size: 0.82rem;">Execute as migrações ou registre novos pacotes na tabela <code>saas_plans</code>.</p>
    </div>
  <?php endif; ?>
</div>
