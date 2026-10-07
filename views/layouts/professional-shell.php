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
        <span>Clyo</span>Nutri
      </div>
      <nav class="app-nav">
        <a href="/dashboard" class="app-nav__item <?= ($activeNav ?? '') === 'dashboard' ? 'active' : '' ?>">
          📊 Dashboard
        </a>
        <a href="/agenda" class="app-nav__item <?= ($activeNav ?? '') === 'agenda' ? 'active' : '' ?>">
          📅 Agenda
        </a>
        <a href="/pacientes" class="app-nav__item <?= ($activeNav ?? '') === 'pacientes' ? 'active' : '' ?>">
          👥 Pacientes
        </a>
        <a href="/alimentos" class="app-nav__item <?= ($activeNav ?? '') === 'alimentos' ? 'active' : '' ?>">
          🥗 Base de Alimentos
        </a>
        <a href="/planos" class="app-nav__item <?= ($activeNav ?? '') === 'planos' ? 'active' : '' ?>">
          📝 Planos Alimentares
        </a>
        <a href="/financeiro" class="app-nav__item <?= ($activeNav ?? '') === 'financeiro' ? 'active' : '' ?>">
          💳 Financeiro
        </a>
        <a href="/configuracoes" class="app-nav__item <?= ($activeNav ?? '') === 'configuracoes' ? 'active' : '' ?>">
          ⚙️ Configurações
        </a>
      </nav>
      <div style="font-size: 0.8rem; color: #64748B; margin-top: auto;">
        Versão 1.0.0 • Multi-tenant
      </div>
    </aside>

    <!-- Main Content Area -->
    <main class="app-main">
      <header class="app-header">
        <div class="app-header__tenant">
          🏥 <span><?= htmlspecialchars($clinicName ?? 'Clínica Principal') ?></span>
        </div>
        <div class="app-header__user">
          <span>Olá, <strong><?= htmlspecialchars($userName ?? 'Nutricionista') ?></strong></span>
          <a href="/logout" style="color: var(--semantic-error); text-decoration: none; font-size: 0.85rem; margin-left: 1rem;">Sair</a>
        </div>
      </header>

      <section class="app-content">
        <?= $content ?? '' ?>
      </section>
    </main>
  </div>
</body>
</html>
