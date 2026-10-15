<div style="margin-bottom: 1.5rem; display: flex; align-items: center; justify-content: space-between; flex-wrap: wrap; gap: 1rem;">
  <div>
    <h2 style="font-size: 1.5rem; font-weight: 700; color: var(--admin-navy-900);">Configurações Globais & Feature Flags</h2>
    <p style="font-size: 0.88rem; color: var(--admin-gray-500);">Limites operacionais, SLAs e parâmetros de segurança persistidos da plataforma SaaS (NI2-407 / AUD-05).</p>
  </div>
</div>

<div class="admin-card">
  <div class="admin-card__header">
    <h3 class="admin-card__title">Parâmetros Operacionais da Plataforma</h3>
  </div>
  <div class="admin-card__body">
    <form action="/admin/configuracoes" method="POST">
      <input type="hidden" name="_csrf_token" value="<?= htmlspecialchars($csrfToken ?? '') ?>">
      
      <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(260px, 1fr)); gap: 1.5rem; margin-bottom: 1.5rem;">
        <div>
          <label for="cfg1-app_name" style="display: block; font-size: 0.82rem; font-weight: 600; color: var(--admin-gray-700); margin-bottom: 0.35rem;">Nome da Plataforma SaaS</label>
          <input id="cfg1-app_name" type="text" name="app_name" value="<?= htmlspecialchars($settings['app_name'] ?? 'ClyoNutri II') ?>" style="width: 100%; padding: 0.5rem 0.75rem; border: 1px solid var(--admin-gray-300); border-radius: 6px; font-size: 0.85rem; background-color: var(--admin-gray-50);" readonly>
        </div>
        <div>
          <label for="cfg2-app_timezone" style="display: block; font-size: 0.82rem; font-weight: 600; color: var(--admin-gray-700); margin-bottom: 0.35rem;">Fuso Horário Operacional</label>
          <input id="cfg2-app_timezone" type="text" name="app_timezone" value="<?= htmlspecialchars(date_default_timezone_get()) ?>" style="width: 100%; padding: 0.5rem 0.75rem; border: 1px solid var(--admin-gray-300); border-radius: 6px; font-size: 0.85rem; background-color: var(--admin-gray-50);" readonly>
        </div>
        <div>
          <label for="cfg3-session_timeout_minutes" style="display: block; font-size: 0.82rem; font-weight: 600; color: var(--admin-gray-700); margin-bottom: 0.35rem;">Timeout de Inatividade de Sessão (minutos)</label>
          <input id="cfg3-session_timeout_minutes" type="number" name="session_timeout_minutes" min="15" max="1440" value="<?= htmlspecialchars((string)($settings['session_timeout_minutes'] ?? '120')) ?>" style="width: 100%; padding: 0.5rem 0.75rem; border: 1px solid var(--admin-gray-300); border-radius: 6px; font-size: 0.85rem;" required>
        </div>
        <div>
          <label for="cfg4-support_sla_p1_hours" style="display: block; font-size: 0.82rem; font-weight: 600; color: var(--admin-gray-700); margin-bottom: 0.35rem;">SLA Chamados Críticos P1 (horas)</label>
          <input id="cfg4-support_sla_p1_hours" type="number" name="support_sla_p1_hours" min="1" max="48" value="<?= htmlspecialchars((string)($settings['support_sla_p1_hours'] ?? '4')) ?>" style="width: 100%; padding: 0.5rem 0.75rem; border: 1px solid var(--admin-gray-300); border-radius: 6px; font-size: 0.85rem;" required>
        </div>
        <div>
          <label for="cfg5-support_sla_p2_hours" style="display: block; font-size: 0.82rem; font-weight: 600; color: var(--admin-gray-700); margin-bottom: 0.35rem;">SLA Chamados Padrão P2 (horas)</label>
          <input id="cfg5-support_sla_p2_hours" type="number" name="support_sla_p2_hours" min="2" max="168" value="<?= htmlspecialchars((string)($settings['support_sla_p2_hours'] ?? '24')) ?>" style="width: 100%; padding: 0.5rem 0.75rem; border: 1px solid var(--admin-gray-300); border-radius: 6px; font-size: 0.85rem;" required>
        </div>
        <div>
          <label for="cfg6-maintenance_mode" style="display: block; font-size: 0.82rem; font-weight: 600; color: var(--admin-gray-700); margin-bottom: 0.35rem;">Modo de Manutenção da Plataforma</label>
          <select id="cfg6-maintenance_mode" name="maintenance_mode" style="width: 100%; padding: 0.5rem 0.75rem; border: 1px solid var(--admin-gray-300); border-radius: 6px; font-size: 0.85rem;">
            <option value="0" <?= ($settings['maintenance_mode'] ?? '0') === '0' ? 'selected' : '' ?>>Inativo (Operação Normal)</option>
            <option value="1" <?= ($settings['maintenance_mode'] ?? '0') === '1' ? 'selected' : '' ?>>Ativo (Manutenção Agendada)</option>
          </select>
        </div>
      </div>

      <h4 style="font-size: 0.95rem; font-weight: 600; color: var(--admin-navy-900); margin-bottom: 0.75rem; border-top: 1px solid var(--admin-gray-200); padding-top: 1rem;">
        Feature Flags da Plataforma (NI2-411 / AUD-04)
      </h4>
      <p style="font-size: 0.82rem; color: var(--admin-gray-500); margin-bottom: 1rem;">
        Habilite ou suspenda módulos globais em tempo de execução sem necessidade de redeploy da aplicação.
      </p>
      <div style="display: flex; flex-direction: column; gap: 1rem; margin-bottom: 1.5rem;">
        <?php $canManage = \ClyoNutri\Core\Security\PlatformPolicy::can($operatorRole ?? '', 'settings.manage'); ?>

        <div style="display: flex; align-items: center; justify-content: space-between; padding: 0.75rem 1rem; background-color: var(--admin-gray-50); border: 1px solid var(--admin-gray-200); border-radius: 6px;">
          <label style="display: flex; align-items: center; gap: 0.75rem; font-size: 0.85rem; color: var(--admin-gray-800); cursor: pointer; flex: 1;">
            <?php if ($canManage): ?>
              <input type="hidden" name="feature_flag_telehealth" value="0">
            <?php endif; ?>
            <input type="checkbox" name="feature_flag_telehealth" value="1" <?= ($settings['feature_flag_telehealth'] ?? '1') === '1' ? 'checked' : '' ?> <?= !$canManage ? 'disabled' : '' ?>>
            <div>
              <strong>Módulo de Teleconsulta &amp; Consultório Online (NI2-221)</strong>
              <div style="font-size: 0.78rem; color: var(--admin-gray-500);">Habilita salas seguras de vídeo e links de atendimento remoto nos consultórios.</div>
            </div>
          </label>
          <span class="badge <?= ($settings['feature_flag_telehealth'] ?? '1') === '1' ? 'badge--success' : 'badge--suspended' ?>">
            <?= ($settings['feature_flag_telehealth'] ?? '1') === '1' ? 'Habilitado' : 'Suspenso' ?>
          </span>
        </div>

        <div style="display: flex; align-items: center; justify-content: space-between; padding: 0.75rem 1rem; background-color: var(--admin-gray-50); border: 1px solid var(--admin-gray-200); border-radius: 6px;">
          <label style="display: flex; align-items: center; gap: 0.75rem; font-size: 0.85rem; color: var(--admin-gray-800); cursor: pointer; flex: 1;">
            <?php if ($canManage): ?>
              <input type="hidden" name="feature_flag_chat" value="0">
            <?php endif; ?>
            <input type="checkbox" name="feature_flag_chat" value="1" <?= ($settings['feature_flag_chat'] ?? '1') === '1' ? 'checked' : '' ?> <?= !$canManage ? 'disabled' : '' ?>>
            <div>
              <strong>Canal Seguro de Mensagens &amp; Chat em Tempo Real (NI2-323 / NI2-281)</strong>
              <div style="font-size: 0.78rem; color: var(--admin-gray-500);">Mensageria criptografada entre nutricionista e paciente pelo portal e app mobile.</div>
            </div>
          </label>
          <span class="badge <?= ($settings['feature_flag_chat'] ?? '1') === '1' ? 'badge--success' : 'badge--suspended' ?>">
            <?= ($settings['feature_flag_chat'] ?? '1') === '1' ? 'Habilitado' : 'Suspenso' ?>
          </span>
        </div>

        <div style="display: flex; align-items: center; justify-content: space-between; padding: 0.75rem 1rem; background-color: var(--admin-gray-50); border: 1px solid var(--admin-gray-200); border-radius: 6px;">
          <label style="display: flex; align-items: center; gap: 0.75rem; font-size: 0.85rem; color: var(--admin-gray-800); cursor: pointer; flex: 1;">
            <?php if ($canManage): ?>
              <input type="hidden" name="feature_flag_audit_immutable" value="0">
            <?php endif; ?>
            <input type="checkbox" name="feature_flag_audit_immutable" value="1" <?= ($settings['feature_flag_audit_immutable'] ?? '1') === '1' ? 'checked' : '' ?> <?= !$canManage ? 'disabled' : '' ?>>
            <div>
              <strong>Auditoria Imutável em WAL (NI2-358 / AUD-05)</strong>
              <div style="font-size: 0.78rem; color: var(--admin-gray-500);">Trilha compulsória de auditoria gravando usuário, IP e diff de mutações.</div>
            </div>
          </label>
          <span class="badge <?= ($settings['feature_flag_audit_immutable'] ?? '1') === '1' ? 'badge--success' : 'badge--suspended' ?>">
            <?= ($settings['feature_flag_audit_immutable'] ?? '1') === '1' ? 'Habilitado' : 'Suspenso' ?>
          </span>
        </div>
      </div>

      <div style="padding-top: 1rem; border-top: 1px solid var(--admin-gray-200); display: flex; justify-content: space-between; align-items: center;">
        <?php if (\ClyoNutri\Core\Security\PlatformPolicy::can($operatorRole ?? '', 'settings.manage')): ?>
          <span style="font-size: 0.8rem; color: var(--admin-gray-500);">Alterações serão persistidas e auditadas com autor, antes e depois.</span>
          <button type="submit" class="btn btn--primary">Salvar Parâmetros</button>
        <?php else: ?>
          <span style="font-size: 0.85rem; color: var(--admin-danger); font-weight: 500;">
            Aviso: Seu perfil (<?= htmlspecialchars($operatorRole ?? '') ?>) possui permissão apenas de leitura para estas configurações.
          </span>
          <button type="button" class="btn btn--secondary" disabled style="opacity: 0.6; cursor: not-allowed;">Salvar Parâmetros (Sem Permissão)</button>
        <?php endif; ?>
      </div>
    </form>
  </div>
</div>
