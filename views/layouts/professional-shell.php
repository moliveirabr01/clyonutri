<!DOCTYPE html>
<html lang="pt-BR">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title><?= htmlspecialchars($title ?? 'ClyoNutri') ?> — Sistema do Nutricionista</title>
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&family=Playfair+Display:wght@500;600&display=swap" rel="stylesheet">
  <link rel="stylesheet" href="/assets/css/clinical-botanical.css">
</head>
<body>
  <div class="app-container">
    <!-- Sidebar Navegação Profissional -->
    <aside class="app-sidebar">
      <div class="app-sidebar__logo">
        <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="color: var(--brand-copper-500);"><path d="M12 2a10 10 0 1 0 10 10A10 10 0 0 0 12 2zm1 14.93V18a1 1 0 0 1-2 0v-1.07A6 6 0 0 1 7 11V6a1 1 0 0 1 1-1h8a1 1 0 0 1 1 1v5a6 6 0 0 1-4 5.93zM9 7v4a3 3 0 0 0 6 0V7z"/></svg>
        <span>Clyo</span>Nutri
      </div>
      <nav class="app-nav">
        <a href="/dashboard" class="app-nav__item <?= ($activeNav ?? '') === 'dashboard' ? 'active' : '' ?>">
          <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><rect width="7" height="9" x="3" y="3" rx="1"/><rect width="7" height="5" x="14" y="3" rx="1"/><rect width="7" height="9" x="14" y="12" rx="1"/><rect width="7" height="5" x="3" y="16" rx="1"/></svg>
          <span>Dashboard</span>
        </a>
        <a href="/agenda" class="app-nav__item <?= ($activeNav ?? '') === 'agenda' ? 'active' : '' ?>">
          <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><rect width="18" height="18" x="3" y="4" rx="2" ry="2"/><line x1="16" x2="16" y1="2" y2="6"/><line x1="8" x2="8" y1="2" y2="6"/><line x1="3" x2="21" y1="10" y2="10"/></svg>
          <span>Agenda</span>
        </a>
        <a href="/pacientes" class="app-nav__item <?= ($activeNav ?? '') === 'pacientes' ? 'active' : '' ?>">
          <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M22 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/></svg>
          <span>Pacientes</span>
        </a>
        <a href="/alimentos" class="app-nav__item <?= ($activeNav ?? '') === 'alimentos' ? 'active' : '' ?>">
          <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M12 2C6.5 2 2 6.5 2 12c0 3.5 1.8 6.6 4.6 8.3L7 22h10l.4-1.7C20.2 18.6 22 15.5 22 12c0-5.5-4.5-10-10-10z"/><path d="M12 6a4 4 0 0 0-4 4"/><line x1="12" y1="2" x2="12" y2="6"/></svg>
          <span>Base de Alimentos</span>
        </a>
        <a href="/planos" class="app-nav__item <?= ($activeNav ?? '') === 'planos' ? 'active' : '' ?>">
          <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M14.5 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V7.5L14.5 2z"/><polyline points="14 2 14 8 20 8"/><line x1="16" y1="13" x2="8" y2="13"/><line x1="16" y1="17" x2="8" y2="17"/><line x1="10" y1="9" x2="8" y2="9"/></svg>
          <span>Planos Alimentares</span>
        </a>
        <a href="/financeiro" class="app-nav__item <?= ($activeNav ?? '') === 'financeiro' ? 'active' : '' ?>">
          <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><rect width="20" height="14" x="2" y="5" rx="2"/><line x1="2" x2="22" y1="10" y2="10"/></svg>
          <span>Financeiro</span>
        </a>
        <a href="/configuracoes" class="app-nav__item <?= ($activeNav ?? '') === 'configuracoes' ? 'active' : '' ?>">
          <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M12.22 2h-.44a2 2 0 0 0-2 2v.18a2 2 0 0 1-1 1.73l-.43.25a2 2 0 0 1-2 0l-.15-.08a2 2 0 0 0-2.73.73l-.22.38a2 2 0 0 0 .73 2.73l.15.1a2 2 0 0 1 1 1.72v.51a2 2 0 0 1-1 1.74l-.15.09a2 2 0 0 0-.73 2.73l.22.38a2 2 0 0 0 2.73.73l.15-.08a2 2 0 0 1 2 0l.43.25a2 2 0 0 1 1 1.73V20a2 2 0 0 0 2 2h.44a2 2 0 0 0 2-2v-.18a2 2 0 0 1 1-1.73l.43-.25a2 2 0 0 1 2 0l.15.08a2 2 0 0 0 2.73-.73l.22-.39a2 2 0 0 0-.73-2.73l-.15-.08a2 2 0 0 1-1-1.74v-.5a2 2 0 0 1 1-1.74l.15-.09a2 2 0 0 0 .73-2.73l-.22-.38a2 2 0 0 0-2.73-.73l-.15.08a2 2 0 0 1-2 0l-.43-.25a2 2 0 0 1-1-1.73V4a2 2 0 0 0-2-2z"/><circle cx="12" cy="12" r="3"/></svg>
          <span>Configurações</span>
        </a>
        <a href="/ajuda" class="app-nav__item <?= ($activeNav ?? '') === 'ajuda' ? 'active' : '' ?>">
          <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><path d="M9.09 9a3 3 0 0 1 5.83 1c0 2-3 3-3 3"/><line x1="12" y1="17" x2="12.01" y2="17"/></svg>
          <span>Central de Ajuda</span>
        </a>
        <?php if (!empty($_SESSION['user_role']) && in_array($_SESSION['user_role'], ['admin', 'operator', 'support_agent'], true)): ?>
          <a href="/suporte/fila" class="app-nav__item <?= ($activeNav ?? '') === 'service_desk' ? 'active' : '' ?>">
            <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M4 4h16c1.1 0 2 .9 2 2v12c0 1.1-.9 2-2 2H4c-1.1 0-2-.9-2-2V6c0-1.1.9-2 2-2z"/><polyline points="22,6 12,13 2,6"/></svg>
            <span>Service Desk</span>
          </a>
        <?php endif; ?>
      </nav>
      <div style="font-size: 0.8rem; color: #64748B; margin-top: auto; display: flex; align-items: center; gap: 6px;">
        <span style="display: inline-block; width: 6px; height: 6px; border-radius: 50%; background-color: var(--semantic-success);"></span>
        v1.0.0 • Multi-tenant
      </div>
    </aside>

    <!-- Main Content Area -->
    <main class="app-main">
      <header class="app-header">
        <div class="app-header__tenant">
          <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" style="color: var(--brand-copper-600);"><path d="M19 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h11l5 5v11a2 2 0 0 1-2 2z"/><polyline points="17 21 17 13 7 13 7 21"/><polyline points="7 3 7 8 15 8"/></svg>
          <span><?= htmlspecialchars($clinicName ?? 'Clínica Principal') ?></span>
        </div>
        <div class="app-header__user">
          <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" style="color: var(--neutral-gray-500);"><circle cx="12" cy="8" r="5"/><path d="M20 21a8 8 0 0 0-16 0"/></svg>
          <span>Olá, <strong><?= htmlspecialchars($userName ?? 'Nutricionista') ?></strong></span>
          <a href="/logout" style="color: var(--semantic-error); text-decoration: none; font-size: 0.85rem; margin-left: 1rem; display: inline-flex; align-items: center; gap: 4px;">
            <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"/><polyline points="16 17 21 12 16 7"/><line x1="21" y1="12" x2="9" y2="12"/></svg>
            Sair
          </a>
        </div>
      </header>

      <section class="app-content">
        <?= $content ?? '' ?>
      </section>
    </main>
  </div>
</body>
</html>
