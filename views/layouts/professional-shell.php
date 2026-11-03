<?php
$professionalViewContext=\ClyoNutri\Core\Auth\ProfessionalViewContext::resolve();
$clinicName=$professionalViewContext['clinicName'];$userName=$professionalViewContext['userName'];
// NI2-616: no topo, o nome completo aparece uma vez (consultório); a saudação usa só o primeiro nome.
$shellFirstName=(static function(?string $n):string{$n=trim((string)$n);if($n==='')return 'Nutricionista';$first=preg_split('/\s+/u',$n)[0];return mb_convert_case(mb_strtolower($first,'UTF-8'),MB_CASE_TITLE,'UTF-8');})($userName??null);
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
  <meta name="csrf-token" content="<?= htmlspecialchars(\ClyoNutri\Core\Security\Csrf::token(), ENT_QUOTES) ?>">
  <link rel="icon" type="image/svg+xml" href="/assets/clyonutri-icon.svg?v=botanical-v54-9">
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title><?= htmlspecialchars($title ?? 'Clyo Nutri') ?> — Sistema do Nutricionista</title>
  <link rel="preload" href="/assets/fonts/inter-normal-latin.woff2" as="font" type="font/woff2" crossorigin>
  <link rel="stylesheet" href="/assets/css/fonts.css?v=botanical-v54-9">
  <link rel="stylesheet" href="/assets/css/clinical-botanical.css?v=botanical-v54-9">
  <link rel="stylesheet" href="/assets/css/clinical-journeys.css?v=v54-17">
</head>
<?php $shellSessionTtl = 7200; try { $shellSessionTtl = \ClyoNutri\Core\Settings\PlatformSettings::getSessionTimeoutMinutes() * 60; } catch (\Throwable $e) {} ?>
<body data-session-ttl="<?= (int)$shellSessionTtl ?>">
  <a class="skip-link skip-clinical-content" href="#clinical-main-content">Pular para o conteúdo principal</a>
  <div class="app-container">
    <!-- Sidebar Navegação Profissional -->
    <aside class="app-sidebar">
      <div class="app-sidebar__logo">
        <img src="/assets/clyonutri-logo-dark.svg?v=botanical-v54-9" alt="Clyo Nutri" width="260" height="64">
      </div>
      <button type="button" id="clinical-menu-toggle" aria-controls="clinical-navigation" aria-expanded="false">Menu</button>
      <nav class="clinical-mobile-priority" aria-label="Tarefas principais"><a href="/pacientes">Pacientes</a><a href="/agenda">Agenda</a><a href="/planos">Planos</a><a href="/chat">Conversas</a></nav>
      <nav class="app-nav" id="clinical-navigation" aria-label="Navegação do consultório">
        <a href="/dashboard" class="app-nav__item <?= ($activeNav ?? '') === 'dashboard' ? 'active' : '' ?>">
          <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><rect width="7" height="9" x="3" y="3" rx="1"/><rect width="7" height="5" x="14" y="3" rx="1"/><rect width="7" height="9" x="14" y="12" rx="1"/><rect width="7" height="5" x="3" y="16" rx="1"/></svg>
          <span>Visão geral</span>
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
          <span>Planos alimentares</span>
        </a>
        <a href="/exames" class="app-nav__item <?= ($activeNav ?? '') === 'exames' ? 'active' : '' ?>">
          <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M14.5 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V7.5L14.5 2z"/><polyline points="14 2 14 8 20 8"/><line x1="12" y1="18" x2="12" y2="12"/><line x1="9" y1="15" x2="15" y2="15"/></svg>
          <span>Exames</span>
        </a>
        <a href="/receitas" class="app-nav__item <?= ($activeNav ?? '') === 'receitas' ? 'active' : '' ?>">
          <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M6 13.87A4 4 0 0 1 7.41 6a5.11 5.11 0 0 1 1.05-1.54 5 5 0 0 1 7.08 0A5.11 5.11 0 0 1 16.59 6 4 4 0 0 1 18 13.87V21H6Z"/><line x1="6" y1="17" x2="18" y2="17"/></svg>
          <span>Receitas</span>
        </a>
        <a href="/chat" class="app-nav__item <?= ($activeNav ?? '') === 'chat' ? 'active' : '' ?>">
          <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"/></svg>
          <span>Conversas</span>
        </a>
        <?php $noticesUnread = \ClyoNutri\Application\Controllers\NotificationsController::unreadForCurrentUser(); ?>
        <a href="/avisos" class="app-nav__item <?= ($activeNav ?? '') === 'avisos' ? 'active' : '' ?>"<?= $noticesUnread > 0 ? ' aria-label="Avisos, ' . (int)$noticesUnread . ' novo' . ($noticesUnread > 1 ? 's' : '') . '"' : '' ?>>
          <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M6 8a6 6 0 0 1 12 0c0 7 3 9 3 9H3s3-2 3-9"/><path d="M10.3 21a1.94 1.94 0 0 0 3.4 0"/></svg>
          <span>Avisos<?php if ($noticesUnread > 0): ?> <strong class="badge" aria-hidden="true">(<?= $noticesUnread > 99 ? '99+' : (int)$noticesUnread ?>)</strong><?php endif; ?></span>
        </a>
        <a href="/financeiro" class="app-nav__item <?= ($activeNav ?? '') === 'financeiro' ? 'active' : '' ?>">
          <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><rect width="20" height="14" x="2" y="5" rx="2"/><line x1="2" x2="22" y1="10" y2="10"/></svg>
          <span>Financeiro</span>
        </a>
        <a href="/configuracoes" class="app-nav__item <?= ($activeNav ?? '') === 'configuracoes' ? 'active' : '' ?>">
          <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M12.22 2h-.44a2 2 0 0 0-2 2v.18a2 2 0 0 1-1 1.73l-.43.25a2 2 0 0 1-2 0l-.15-.08a2 2 0 0 0-2.73.73l-.22.38a2 2 0 0 0 .73 2.73l.15.1a2 2 0 0 1 1 1.72v.51a2 2 0 0 1-1 1.74l-.15.09a2 2 0 0 0-.73 2.73l.22.38a2 2 0 0 0 2.73.73l.15-.08a2 2 0 0 1 2 0l.43.25a2 2 0 0 1 1 1.73V20a2 2 0 0 0 2 2h.44a2 2 0 0 0 2-2v-.18a2 2 0 0 1 1-1.73l.43-.25a2 2 0 0 1 2 0l.15.08a2 2 0 0 0 2.73-.73l.22-.39a2 2 0 0 0-.73-2.73l-.15-.08a2 2 0 0 1-1-1.74v-.5a2 2 0 0 1 1-1.74l.15-.09a2 2 0 0 0 .73-2.73l-.22-.38a2 2 0 0 0-2.73-.73l-.15.08a2 2 0 0 1-2 0l-.43-.25a2 2 0 0 1-1-1.73V4a2 2 0 0 0-2-2z"/><circle cx="12" cy="12" r="3"/></svg>
          <span>Configurações</span>
        </a>
        <a href="/privacidade/pedidos" class="app-nav__item <?= ($activeNav ?? '') === 'privacidade' ? 'active' : '' ?>">
          <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><rect x="3" y="11" width="18" height="11" rx="2"/><path d="M7 11V7a5 5 0 0 1 10 0v4"/></svg>
          <span>Pedidos LGPD</span>
        </a>
        <a href="/academy" class="app-nav__item <?= ($activeNav ?? '') === 'academy' ? 'active' : '' ?>">
          <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M4 19.5v-15A2.5 2.5 0 0 1 6.5 2H20v20H6.5a2.5 2.5 0 0 1-2.5-2.5Z"/><path d="M6 6h10"/><path d="M6 10h10"/></svg>
          <span>Clyo Academy</span>
        </a>
        <a href="/ajuda" class="app-nav__item <?= ($activeNav ?? '') === 'ajuda' ? 'active' : '' ?>">
          <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><path d="M9.09 9a3 3 0 0 1 5.83 1c0 2-3 3-3 3"/><line x1="12" y1="17" x2="12.01" y2="17"/></svg>
          <span>Central de Ajuda</span>
        </a>
        <?php if (!empty($_SESSION['user_role']) && in_array($_SESSION['user_role'], ['admin', 'operator', 'support_agent'], true)): ?>
          <a href="/suporte/fila" class="app-nav__item <?= ($activeNav ?? '') === 'service_desk' ? 'active' : '' ?>">
            <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M4 4h16c1.1 0 2 .9 2 2v12c0 1.1-.9 2-2 2H4c-1.1 0-2-.9-2-2V6c0-1.1.9-2 2-2z"/><polyline points="22,6 12,13 2,6"/></svg>
            <span>Service desk</span>
          </a>
        <?php endif; ?>
        <div class="clinical-nav__mobile-user" style="margin-top: 16px; padding-top: 16px; border-top: 1px solid rgba(255,255,255,0.15); color: #fff; font-size: 0.85rem;">
          <div style="margin-bottom: 6px; font-weight: 600;"><?= htmlspecialchars($clinicName ?? 'Clínica principal') ?></div>
          <div style="margin-bottom: 12px; color: var(--neutral-gray-400, #98A2B3);">Olá, <?= htmlspecialchars($shellFirstName) ?></div>
          <form method="POST" action="/logout" style="margin: 0;">
            <input type="hidden" name="_csrf_token" value="<?= htmlspecialchars(\ClyoNutri\Core\Security\Csrf::token(), ENT_QUOTES, 'UTF-8') ?>">
            <button type="submit" class="btn btn-secondary btn-sm" style="color: #fff; border-color: rgba(255,255,255,0.3); background: transparent; width: 100%;">Sair</button>
          </form>
        </div>
      </nav>
      <div class="app-sidebar__version">
        <span class="app-sidebar__status-dot" aria-hidden="true"></span>
        v54.17
      </div>
    </aside>

    <!-- Main Content Area -->
    <main class="app-main">
      <header class="app-header">
        <div class="app-header__tenant">
          <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" style="color: var(--brand-copper-600);"><path d="M19 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h11l5 5v11a2 2 0 0 1-2 2z"/><polyline points="17 21 17 13 7 13 7 21"/><polyline points="7 3 7 8 15 8"/></svg>
          <span class="app-header__tenant-name" title="<?= htmlspecialchars($clinicName ?? 'Clínica principal', ENT_QUOTES) ?>"><?= htmlspecialchars($clinicName ?? 'Clínica principal') ?></span>
        </div>
        <div class="app-header__user">
          <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" style="color: var(--neutral-gray-500);"><circle cx="12" cy="8" r="5"/><path d="M20 21a8 8 0 0 0-16 0"/></svg>
          <span title="<?= htmlspecialchars($userName ?? 'Nutricionista', ENT_QUOTES) ?>">Olá, <strong><?= htmlspecialchars($shellFirstName) ?></strong></span>
          <form method="POST" action="/logout" class="logout-form" data-testid="logout-form">
            <input type="hidden" name="_csrf_token" value="<?= htmlspecialchars(\ClyoNutri\Core\Security\Csrf::token(), ENT_QUOTES, 'UTF-8') ?>">
            <button type="submit" class="app-header__logout">
              <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false"><path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"/><polyline points="16 17 21 12 16 7"/><line x1="21" y1="12" x2="9" y2="12"/></svg>
              Sair
            </button>
          </form>
        </div>
      </header>

      <section class="app-content" id="clinical-main-content" tabindex="-1">
        <?= $content ?? '' ?>
      </section>
    </main>
  </div>
<?php $recovery=\ClyoNutri\Domain\Clinical\FormRecovery::forCurrentPage(); if($recovery): ?>
<script type="application/json" id="clinical-form-recovery"><?= json_encode($recovery,JSON_HEX_TAG|JSON_HEX_AMP|JSON_HEX_APOS|JSON_HEX_QUOT|JSON_THROW_ON_ERROR) ?></script>
<?php endif; ?>
<script src="/assets/js/validacao-ptbr.js" defer></script>
<script src="/assets/js/clinical-journeys.js?v=v54-17" defer></script>
<script src="/assets/js/session-timeout.js?v=v54-10" defer></script>
<script src="/assets/js/avaliacao-confirmacoes.js" defer></script>
</body>
</html>
