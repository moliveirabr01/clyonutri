<div style="max-width: 1040px; margin: 0 auto;">
  <div style="display: flex; align-items: center; justify-content: space-between; flex-wrap: wrap; gap: 1rem; margin-bottom: var(--space-4);">
    <div>
      <h1 style="font-size: 1.75rem; font-weight: 700; color: var(--brand-navy-900); margin: 0 0 4px 0;">Central de Assinatura &amp; Contrato</h1>
      <p style="color: var(--neutral-gray-500); margin: 0; font-size: 0.95rem;">Transparência total sobre seu plano SaaS, limites contratados, faturas e cancelamento em autosserviço.</p>
    </div>
    <div style="display: flex; gap: 8px;">
      <a href="/configuracoes" class="btn btn-secondary" style="text-decoration: none;">← Voltar às Configurações</a>
    </div>
  </div>

  <!-- Navegação por abas em Configurações -->
  <div style="display: flex; border-bottom: 2px solid var(--neutral-gray-200); margin-bottom: var(--space-6); gap: 1.5rem;">
    <a href="/configuracoes" style="padding: 10px 4px; text-decoration: none; color: var(--neutral-gray-600); font-weight: 500; border-bottom: 2px solid transparent; margin-bottom: -2px;">
      Perfil e Atendimento
    </a>
    <a href="/configuracoes/assinatura" style="padding: 10px 4px; text-decoration: none; color: var(--brand-copper-600); font-weight: 600; border-bottom: 2px solid var(--brand-copper-600); margin-bottom: -2px;">
      Assinatura &amp; Faturamento
    </a>
  </div>

  <?php if (!empty($flashSuccess)): ?>
    <div role="status" style="background: #E3F4F2; border-left: 4px solid var(--semantic-success); padding: 14px 18px; border-radius: 6px; margin-bottom: var(--space-6); color: #0A7075; font-weight: 500;">
      <?= htmlspecialchars($flashSuccess) ?>
    </div>
  <?php endif; ?>

  <?php if (!empty($flashError)): ?>
    <div role="alert" style="background: #FEF2F2; border-left: 4px solid var(--semantic-error); padding: 14px 18px; border-radius: 6px; margin-bottom: var(--space-6); color: #B91C1C; font-weight: 500;">
      <?= htmlspecialchars($flashError) ?>
    </div>
  <?php endif; ?>

  <?php if ($subscription['status'] === 'trialing'): ?>
    <!-- Banner do Período de Avaliação (NI2-450) -->
    <div role="status" style="background: #FFFBEB; border-left: 4px solid var(--brand-copper-500); padding: 16px 20px; border-radius: 6px; margin-bottom: var(--space-6); color: #92400E;">
      <div style="font-weight: 700; font-size: 1rem; margin-bottom: 4px; display: flex; align-items: center; justify-content: space-between; flex-wrap: wrap; gap: 8px;">
        <span style="display: inline-flex; align-items: center; gap: 6px;">
          <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></svg>
          Período de Avaliação Gratuita (14 dias)
        </span>
        <span style="background: #FDE68A; color: #78350F; font-size: 0.8rem; font-weight: 700; padding: 4px 10px; border-radius: 999px;">
          <?= (int)$subscription['days_left_in_trial'] ?> dia(s) restante(s)
        </span>
      </div>
      <p style="margin: 0; font-size: 0.92rem; line-height: 1.55; color: #78350F;">
        Você está usufruindo do teste gratuito com todos os recursos do plano <strong><?= htmlspecialchars($subscription['plan_name']) ?></strong> liberados até <strong><?= !empty($subscription['trial_ends_at']) ? (new \DateTimeImmutable($subscription['trial_ends_at']))->format('d/m/Y') : '' ?></strong>, sem necessidade de cartão de crédito. Nenhuma cobrança é realizada. Ao término dos 14 dias, para continuar criando novos planos e registrando consultas, você poderá escolher o plano de sua preferência. Todos os seus dados cadastrados continuam seguros e disponíveis para exportação.
      </p>
    </div>
  <?php elseif ($subscription['status'] === 'expired'): ?>
    <!-- Banner de Período de Teste Expirado -->
    <div role="alert" style="background: #FEF2F2; border-left: 4px solid var(--semantic-error); padding: 16px 20px; border-radius: 6px; margin-bottom: var(--space-6); color: #991B1B;">
      <div style="font-weight: 700; font-size: 1rem; margin-bottom: 4px;">
        Seu período de teste de 14 dias terminou
      </div>
      <p style="margin: 0; font-size: 0.92rem; line-height: 1.55; color: #7F1D1D;">
        Seus dados, pacientes e prontuários estão salvos e preservados. Para voltar a prescrever novos planos, registrar evoluções e agendar consultas, selecione seu plano comercial de preferência na página de planos.
      </p>
      <div style="margin-top: 10px;">
        <a href="/planos" class="btn btn-primary" style="text-decoration: none; display: inline-block; padding: 8px 16px; font-size: 0.875rem;">Conhecer e Assinar Planos</a>
      </div>
    </div>
  <?php endif; ?>

  <!-- Visão Geral do Contrato Vigente -->
  <div class="card" style="margin-bottom: var(--space-6); border-top: 4px solid <?= $subscription['status'] === 'canceled' ? '#E11D48' : ($subscription['status'] === 'trialing' ? 'var(--brand-copper-500)' : 'var(--brand-botanical-500)') ?>;">
    <div style="display: flex; justify-content: space-between; align-items: flex-start; flex-wrap: wrap; gap: 1rem; margin-bottom: var(--space-4);">
      <div>
        <span style="font-size: 0.8rem; text-transform: uppercase; letter-spacing: 0.05em; font-weight: 700; color: var(--neutral-gray-500);">Plano <?= $subscription['status'] === 'trialing' ? 'em Degustação' : 'Contratado' ?></span>
        <h2 style="font-size: 1.4rem; font-weight: 700; color: var(--brand-navy-900); margin: 4px 0;">
          <?= htmlspecialchars($subscription['plan_name']) ?> (<code><?= htmlspecialchars($subscription['plan_code']) ?></code>)
        </h2>
        <p style="margin: 0; color: var(--neutral-gray-600); font-size: 0.9rem;">
          <?= $subscription['status'] === 'trialing' ? 'Avaliação gratuita de 14 dias sem cartão de crédito cadastrado.' : 'Cobrança ' . ($subscription['billing_cycle'] === 'monthly' ? 'mensal recorrente' : 'anual') . ' sem fidelidade.' ?>
        </p>
      </div>
      <div>
        <?php if ($subscription['status'] === 'canceled'): ?>
          <span style="display: inline-flex; align-items: center; gap: 6px; background: #FFE4E6; color: #9F1239; padding: 6px 12px; border-radius: 999px; font-weight: 600; font-size: 0.85rem;">
            ● Assinatura Cancelada
          </span>
        <?php elseif ($subscription['status'] === 'trialing'): ?>
          <span style="display: inline-flex; align-items: center; gap: 6px; background: #FEF3C7; color: #92400E; padding: 6px 12px; border-radius: 999px; font-weight: 600; font-size: 0.85rem;">
            ● Período de Avaliação (Trial)
          </span>
        <?php elseif ($subscription['status'] === 'expired'): ?>
          <span style="display: inline-flex; align-items: center; gap: 6px; background: #FEE2E2; color: #991B1B; padding: 6px 12px; border-radius: 999px; font-weight: 600; font-size: 0.85rem;">
            ● Teste Expirado
          </span>
        <?php else: ?>
          <span style="display: inline-flex; align-items: center; gap: 6px; background: #E3F4F2; color: #0A5F63; padding: 6px 12px; border-radius: 999px; font-weight: 600; font-size: 0.85rem;">
            ● Assinatura Ativa
          </span>
        <?php endif; ?>
      </div>
    </div>

    <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 1.5rem; background: var(--neutral-gray-50); padding: 1.25rem; border-radius: 8px; border: 1px solid var(--neutral-gray-200);">
      <div>
        <div style="font-size: 0.8rem; color: var(--neutral-gray-500); font-weight: 600;">VALOR DA RECORRÊNCIA</div>
        <?php if ($subscription['status'] === 'trialing'): ?>
          <div style="font-size: 1.35rem; font-weight: 700; color: var(--brand-navy-900); margin-top: 2px;">
            R$ 0,00 <span style="font-size: 0.85rem; font-weight: 400; color: var(--neutral-gray-500);">(Período de Teste)</span>
          </div>
          <div style="font-size: 0.75rem; color: var(--neutral-gray-500); margin-top: 2px;">
            R$ <?= number_format($subscription['price_cents'] / 100, 2, ',', '.') ?>/mês caso decida assinar
          </div>
        <?php else: ?>
          <div style="font-size: 1.35rem; font-weight: 700; color: var(--brand-navy-900); margin-top: 2px;">
            R$ <?= number_format($subscription['price_cents'] / 100, 2, ',', '.') ?> <span style="font-size: 0.85rem; font-weight: 400; color: var(--neutral-gray-500);">/ mês</span>
          </div>
        <?php endif; ?>
      </div>
      <div>
        <div style="font-size: 0.8rem; color: var(--neutral-gray-500); font-weight: 600;">VIGÊNCIA DO CICLO ATUAL</div>
        <div style="font-size: 1.1rem; font-weight: 600; color: var(--brand-navy-900); margin-top: 4px;">
          <?= $subscription['status'] === 'trialing' ? 'Teste até ' : 'Até ' ?><?= (new \DateTimeImmutable($subscription['trial_ends_at'] ?? $subscription['current_period_end']))->format('d/m/Y') ?>
        </div>
      </div>
      <div>
        <div style="font-size: 0.8rem; color: var(--neutral-gray-500); font-weight: 600;">DATA DE INÍCIO</div>
        <div style="font-size: 1.1rem; font-weight: 600; color: var(--brand-navy-900); margin-top: 4px;">
          <?= (new \DateTimeImmutable($subscription['started_at']))->format('d/m/Y') ?>
        </div>
      </div>
      <div>
        <div style="font-size: 0.8rem; color: var(--neutral-gray-500); font-weight: 600;">TERMOS &amp; CONTRATO</div>
        <div style="margin-top: 6px;">
          <a href="/termos" target="_blank" rel="noopener noreferrer" style="color: var(--brand-copper-600); text-decoration: underline; font-size: 0.9rem; font-weight: 500;">
            Ver termos vigentes ↗
          </a>
        </div>
      </div>
    </div>
  </div>

  <!-- Consumo de Recursos vs Limites Contratados -->
  <div class="card" style="margin-bottom: var(--space-6);">
    <h2 style="font-size: 1.25rem; font-weight: 700; color: var(--brand-navy-900); margin-bottom: var(--space-4);">
      Consumo Operacional &amp; Limites do Plano
    </h2>

    <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(280px, 1fr)); gap: 1.5rem;">
      <!-- Profissionais -->
      <div style="border: 1px solid var(--neutral-gray-200); border-radius: 8px; padding: 1.25rem;">
        <div style="display: flex; justify-content: space-between; margin-bottom: 8px;">
          <strong style="color: var(--brand-navy-900);">Profissionais no Consultório</strong>
          <span style="font-size: 0.9rem; color: var(--neutral-gray-600);">
            <?= (int)$subscription['usage']['active_professionals'] ?> / <?= $subscription['max_professionals'] > 0 ? (int)$subscription['max_professionals'] : 'Ilimitado' ?>
          </span>
        </div>
        <div style="background: var(--neutral-gray-200); border-radius: 999px; height: 8px; overflow: hidden; margin-bottom: 8px;">
          <?php 
            $profPct = $subscription['max_professionals'] > 0 ? min(100, round(($subscription['usage']['active_professionals'] / $subscription['max_professionals']) * 100)) : 20;
          ?>
          <div style="background: var(--brand-botanical-500); width: <?= $profPct ?>%; height: 100%;"></div>
        </div>
        <p style="font-size: 0.8rem; color: var(--neutral-gray-500); margin: 0;">Equipe clínica com acesso ativo.</p>
      </div>

      <!-- Pacientes -->
      <div style="border: 1px solid var(--neutral-gray-200); border-radius: 8px; padding: 1.25rem;">
        <div style="display: flex; justify-content: space-between; margin-bottom: 8px;">
          <strong style="color: var(--brand-navy-900);">Pacientes Ativos</strong>
          <span style="font-size: 0.9rem; color: var(--neutral-gray-600);">
            <?= (int)$subscription['usage']['active_patients'] ?> / <?= $subscription['max_patients'] > 0 ? (int)$subscription['max_patients'] : 'Ilimitado' ?>
          </span>
        </div>
        <div style="background: var(--neutral-gray-200); border-radius: 999px; height: 8px; overflow: hidden; margin-bottom: 8px;">
          <?php 
            $patPct = $subscription['max_patients'] > 0 ? min(100, round(($subscription['usage']['active_patients'] / $subscription['max_patients']) * 100)) : 15;
          ?>
          <div style="background: var(--brand-copper-500); width: <?= $patPct ?>%; height: 100%;"></div>
        </div>
        <p style="font-size: 0.8rem; color: var(--neutral-gray-500); margin: 0;">Pacientes cadastrados com prontuário.</p>
      </div>

      <!-- Armazenamento -->
      <div style="border: 1px solid var(--neutral-gray-200); border-radius: 8px; padding: 1.25rem;">
        <div style="display: flex; justify-content: space-between; margin-bottom: 8px;">
          <strong style="color: var(--brand-navy-900);">Armazenamento de Arquivos</strong>
          <span style="font-size: 0.9rem; color: var(--neutral-gray-600);">
            <?= $subscription['usage']['storage_mb'] ?> MB / <?= (int)$subscription['storage_mb'] ?> MB
          </span>
        </div>
        <div style="background: var(--neutral-gray-200); border-radius: 999px; height: 8px; overflow: hidden; margin-bottom: 8px;">
          <?php 
            $storPct = min(100, round(($subscription['usage']['storage_mb'] / max(1, $subscription['storage_mb'])) * 100));
          ?>
          <div style="background: var(--brand-navy-500); width: <?= max(5, $storPct) ?>%; height: 100%;"></div>
        </div>
        <p style="font-size: 0.8rem; color: var(--neutral-gray-500); margin: 0;">Exames, laudos e documentos clínicos.</p>
      </div>
    </div>
  </div>

  <!-- Histórico de Faturas & Conciliação Financeira -->
  <div class="card" style="margin-bottom: var(--space-6);">
    <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: var(--space-4); flex-wrap: wrap; gap: 8px;">
      <h2 style="font-size: 1.25rem; font-weight: 700; color: var(--brand-navy-900); margin: 0;">
        Histórico de Faturas &amp; Cobrança
      </h2>
      <a href="/ajuda" style="font-size: 0.85rem; color: var(--brand-copper-600); text-decoration: underline;">
        Precisa contestar uma cobrança? Fale com o suporte ↗
      </a>
    </div>

    <?php if (empty($subscription['invoices'])): ?>
      <p style="color: var(--neutral-gray-500); font-size: 0.9rem; margin: 0;">
        <?= $subscription['status'] === 'trialing' ? 'Nenhuma fatura emitida. O período de avaliação de 14 dias não gera cobranças nem faturas fictícias.' : 'Nenhuma fatura emitida até o momento.' ?>
      </p>
    <?php else: ?>
      <div style="overflow-x: auto;">
        <table class="table" style="width: 100%; border-collapse: collapse; font-size: 0.9rem;">
          <thead>
            <tr style="border-bottom: 2px solid var(--neutral-gray-200); text-align: left;">
              <th style="padding: 10px 8px;">Fatura</th>
              <th style="padding: 10px 8px;">Vencimento</th>
              <th style="padding: 10px 8px;">Data do Pagamento</th>
              <th style="padding: 10px 8px;">Valor</th>
              <th style="padding: 10px 8px;">Forma</th>
              <th style="padding: 10px 8px;">Status</th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($subscription['invoices'] as $inv): ?>
              <tr style="border-bottom: 1px solid var(--neutral-gray-200);">
                <td style="padding: 12px 8px;"><code><?= htmlspecialchars($inv['invoice_number']) ?></code></td>
                <td style="padding: 12px 8px;"><?= (new \DateTimeImmutable($inv['due_date']))->format('d/m/Y') ?></td>
                <td style="padding: 12px 8px;"><?= !empty($inv['paid_at']) ? (new \DateTimeImmutable($inv['paid_at']))->format('d/m/Y') : '—' ?></td>
                <td style="padding: 12px 8px; font-weight: 600;">R$ <?= number_format($inv['amount_cents'] / 100, 2, ',', '.') ?></td>
                <td style="padding: 12px 8px;"><?= htmlspecialchars($inv['payment_method'] === 'credit_card' ? 'Cartão de Crédito' : $inv['payment_method']) ?></td>
                <td style="padding: 12px 8px;">
                  <?php if ($inv['status'] === 'paid'): ?>
                    <span style="background: #E3F4F2; color: #0A5F63; padding: 4px 8px; border-radius: 4px; font-size: 0.8rem; font-weight: 600;">Paga</span>
                  <?php elseif ($inv['status'] === 'void'): ?>
                    <span style="background: #F1F5F9; color: #64748B; padding: 4px 8px; border-radius: 4px; font-size: 0.8rem; font-weight: 600;">Anulada</span>
                  <?php else: ?>
                    <span style="background: #FEF3C7; color: #92400E; padding: 4px 8px; border-radius: 4px; font-size: 0.8rem; font-weight: 600;"><?= htmlspecialchars(ucfirst($inv['status'])) ?></span>
                  <?php endif; ?>
                </td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    <?php endif; ?>
  </div>

  <!-- Seção de Cancelamento Autosserviço Sem Barreiras (TRUST-02 & TRUST-11) -->
  <div class="card" style="border: 1px solid <?= $subscription['status'] === 'canceled' ? '#FDA4AF' : 'var(--neutral-gray-300)' ?>; background: <?= $subscription['status'] === 'canceled' ? '#FFF1F2' : '#FFFFFF' ?>;">
    <?php if ($subscription['status'] === 'canceled'): ?>
      <div style="padding: 8px 0;">
        <div style="display: flex; align-items: center; gap: 8px; margin-bottom: 8px;">
          <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="#E11D48" stroke-width="2"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg>
          <h2 style="font-size: 1.25rem; font-weight: 700; color: #9F1239; margin: 0;">Assinatura Cancelada</h2>
        </div>
        <p style="color: #4C0519; font-size: 0.95rem; margin-bottom: 12px;">
          Sua solicitação de cancelamento foi processada com sucesso em autosserviço. Não haverá novas renovações ou cobranças no seu cartão.
        </p>
        <div style="background: #FFFFFF; border: 1px solid #FECDD3; border-radius: 8px; padding: 1rem; max-width: 600px;">
          <div style="margin-bottom: 6px;">
            <strong style="color: var(--brand-navy-900);">Protocolo de Cancelamento:</strong>
            <code style="font-size: 1.05rem; font-weight: 700; color: #BE123C;"><?= htmlspecialchars($subscription['cancellation_protocol'] ?? '—') ?></code>
          </div>
          <div style="margin-bottom: 6px;">
            <strong style="color: var(--brand-navy-900);">Data da Efetivação:</strong>
            <span><?= !empty($subscription['canceled_at']) ? (new \DateTimeImmutable($subscription['canceled_at']))->format('d/m/Y H:i') : '—' ?></span>
          </div>
          <div>
            <strong style="color: var(--brand-navy-900);">Acesso liberado até:</strong>
            <span><?= (new \DateTimeImmutable($subscription['current_period_end']))->format('d/m/Y') ?> (fim do ciclo faturado)</span>
          </div>
        </div>
      </div>
    <?php else: ?>
      <h2 style="font-size: 1.25rem; font-weight: 700; color: var(--brand-navy-900); margin-bottom: 4px;">
        Cancelamento da Assinatura em Autosserviço
      </h2>
      <p style="color: var(--neutral-gray-600); font-size: 0.9rem; margin-bottom: var(--space-4);">
        Aqui no ClyoNutri você tem total autonomia sobre seu contrato. Sem obrigatoriedade de ligar ou conversar com atendentes no chat.
      </p>

      <div style="background: #F8FAFC; border: 1px solid #E2E8F0; border-radius: 8px; padding: 1rem; margin-bottom: var(--space-4); font-size: 0.9rem;">
        <h3 style="font-size: 0.95rem; font-weight: 600; color: var(--brand-navy-900); margin: 0 0 6px 0;">O que acontece ao cancelar:</h3>
        <ul style="margin: 0; padding-left: 20px; color: var(--neutral-gray-700); line-height: 1.5;">
          <li>A renovação automática é interrompida imediatamente.</li>
          <li>Nenhuma cobrança futura será realizada no seu cartão.</li>
          <li>Você e sua equipe mantêm acesso completo a todos os recursos até <strong><?= (new \DateTimeImmutable($subscription['current_period_end']))->format('d/m/Y') ?></strong> (fim do ciclo vigente).</li>
          <li>Seus prontuários, planos e dados clínicos permanecem guardados em conformidade com a LGPD e o CFN.</li>
          <li>Você recebe na hora um número oficial de protocolo de cancelamento.</li>
        </ul>
      </div>

      <form method="POST" action="/configuracoes/assinatura/cancelar" data-confirm="Tem certeza de que deseja cancelar a assinatura do consultório? O acesso permanecerá até o fim do ciclo pago.">
        <input type="hidden" name="_csrf_token" value="<?= \ClyoNutri\Core\Security\Csrf::token() ?>">

        <fieldset style="border: 1px solid var(--neutral-gray-200); border-radius: 8px; padding: 1.25rem; margin-bottom: var(--space-4);">
          <legend style="padding: 0 8px; font-weight: 600; font-size: 0.9rem; color: var(--brand-navy-900);">
            Pesquisa de Saída <span style="font-weight: 400; font-size: 0.8rem; color: var(--neutral-gray-500);">(100% Opcional)</span>
          </legend>
          <p style="font-size: 0.85rem; color: var(--neutral-gray-600); margin-top: 0; margin-bottom: 12px;">
            Sua opinião nos ajuda a evoluir. Você pode deixar os campos abaixo em branco se preferir; o cancelamento será concluído da mesma forma.
          </p>

          <div class="form-group" style="margin-bottom: 12px;">
            <label class="form-label" for="cancel-reason" style="font-size: 0.85rem;">Qual o principal motivo da sua saída? (Opcional)</label>
            <select class="form-control" id="cancel-reason" name="reason_category">
              <option value="">— Não desejo informar —</option>
              <?php foreach ($reasons as $code => $label): ?>
                <option value="<?= htmlspecialchars($code) ?>"><?= htmlspecialchars($label) ?></option>
              <?php endforeach; ?>
            </select>
          </div>

          <div class="form-group" style="margin-bottom: 0;">
            <label class="form-label" for="cancel-feedback" style="font-size: 0.85rem;">Comentários ou sugestões para melhorarmos (Opcional):</label>
            <textarea class="form-control" id="cancel-feedback" name="feedback" rows="3" maxlength="1000" placeholder="Compartilhe o que faltou para o ClyoNutri atender plenamente seu dia a dia..."></textarea>
          </div>
        </fieldset>

        <div style="display: flex; align-items: center; justify-content: space-between; flex-wrap: wrap; gap: 1rem;">
          <span style="font-size: 0.85rem; color: var(--neutral-gray-500);">
            Apenas titulares e administradores do consultório podem executar esta ação.
          </span>
          <button type="submit" class="btn" style="background: #E11D48; color: #FFFFFF; border: none; font-weight: 600; padding: 10px 20px; border-radius: 6px; cursor: pointer;">
            Confirmar Cancelamento da Assinatura
          </button>
        </div>
      </form>
    <?php endif; ?>
  </div>
</div>
