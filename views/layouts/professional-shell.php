<?php
$professionalViewContext=\ClyoNutri\Core\Auth\ProfessionalViewContext::resolve();
$clinicName=$professionalViewContext['clinicName'];$userName=$professionalViewContext['userName'];
// NI2-616 / NI2-855 (UX-007): no topo, o nome completo aparece uma vez (consultório); a saudação usa só o primeiro nome sem tratamento ("Dra. Helena" -> "Helena").
$shellFirstName = \ClyoNutri\Domain\Dashboard\DashboardOverview::firstName($userName ?? null);
if ($shellFirstName === '') {
    $shellFirstName = 'Nutricionista';
}
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
  <link rel="stylesheet" href="/assets/css/fonts.css?v=v54-66">
  <link rel="stylesheet" href="/assets/css/clinical-botanical.css?v=botanical-v54-9">
  <link rel="stylesheet" href="/assets/css/clinical-journeys.css?v=v54-47">
  <link rel="stylesheet" href="/assets/css/operational-ui.css?v=v54-66">
  <link rel="stylesheet" href="/assets/css/ds-components.css?v=v54-47">
  <link rel="stylesheet" href="/assets/css/ds-utilities.css?v=v54-47">
  <link rel="stylesheet" href="/assets/css/ds-pages.css?v=v54-53">
  <link rel="stylesheet" href="/assets/css/ux-v54-52.css?v=v54-54">
  <link rel="stylesheet" href="/assets/css/ux-v54-57.css?v=ni2-888">
  <?php if (!empty($dashboardV5456Styles)): ?><link rel="stylesheet" href="/assets/css/dashboard-v54-56.css?v=v54-56"><?php endif; ?>
  <link rel="stylesheet" href="/assets/css/workspace-v54-58.css?v=v54-66">
</head>
<?php $shellSessionTtl = 7200; try { $shellSessionTtl = \ClyoNutri\Core\Settings\PlatformSettings::getSessionTimeoutMinutes() * 60; } catch (\Throwable $e) { /* NI2-892: sem a configuração da plataforma vale o padrão de 2 h. */ } ?>
<body class="cn58" data-session-ttl="<?= (int)$shellSessionTtl ?>">
  <a class="skip-link skip-clinical-content" href="#clinical-main-content">Pular para o conteúdo principal</a>
  <div class="app-container">
    <!-- Sidebar Navegação Profissional -->
    <?php ob_start(); // v54.57 (UX-104): o menu só oferece destinos que o perfil pode abrir ?>
    <aside class="app-sidebar">
      <div class="app-sidebar__logo">
        <img src="/assets/clyonutri-logo-dark.svg?v=botanical-v54-9" alt="Clyo Nutri" width="260" height="64">
      </div>
      <button type="button" id="clinical-menu-toggle" aria-controls="clinical-navigation" aria-expanded="false">Menu</button>
      <div class="app-nav" id="clinical-navigation" tabindex="-1">
        <?php require __DIR__ . '/_professional_navigation.php'; ?>
      </div>
      <div class="app-sidebar__version">
        <span class="app-sidebar__status-dot" aria-hidden="true"></span>
        <?= htmlspecialchars(\ClyoNutri\Core\Release\BuildIdentity::UI_VERSION, ENT_QUOTES, 'UTF-8') ?>
        <?php $buildUser=\ClyoNutri\Core\Auth\AuthContext::get(); if ($buildUser && $buildUser->isClinical() && in_array($buildUser->getMemberRole(), ['owner','admin'], true)): ?>
          <a href="/configuracoes/versao" class="u-inline-flex u-items-center u-underline clinical-footer__version-link">Versão instalada</a>
        <?php endif; ?>
      </div>
      <button type="button" class="cn58-nav-backdrop" data-nav-backdrop aria-label="Fechar menu" tabindex="-1" hidden></button>
    </aside>
    <?= \ClyoNutri\Application\View\RoleNav::filter((string)ob_get_clean()) ?>

    <!-- Main Content Area -->
    <main class="app-main">
      <header class="app-header">
        <div class="app-header__tenant">
          <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" class="u-color-botanical-copper-600"><path d="M19 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h11l5 5v11a2 2 0 0 1-2 2z"/><polyline points="17 21 17 13 7 13 7 21"/><polyline points="7 3 7 8 15 8"/></svg>
          <span class="app-header__tenant-name" title="<?= htmlspecialchars($clinicName ?? 'Clínica principal', ENT_QUOTES) ?>"><?= htmlspecialchars($clinicName ?? 'Clínica principal') ?></span>
        </div>
        <div class="app-header__user">
          <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" class="u-color-neutral-gray-500"><circle cx="12" cy="8" r="5"/><path d="M20 21a8 8 0 0 0-16 0"/></svg>
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
        <?= \ClyoNutri\Application\View\RoleNav::unlinkForbidden(\ClyoNutri\Application\View\AttendanceContext::navigation($content ?? '')) // v54.57 (UX-104) ?>
      </section>
    </main>
  </div>
<?php $recovery=\ClyoNutri\Domain\Clinical\FormRecovery::forCurrentPage(); if($recovery): ?>
<script type="application/json" id="clinical-form-recovery"><?= json_encode($recovery,JSON_HEX_TAG|JSON_HEX_AMP|JSON_HEX_APOS|JSON_HEX_QUOT|JSON_THROW_ON_ERROR) ?></script>
<?php endif; ?>
<script src="/assets/js/validacao-ptbr.js" defer></script>
<script src="/assets/js/decimal-input.js?v=v54-63" defer></script>
<script src="/assets/js/form-local-draft.js?v=v54-67" defer></script>
<script src="/assets/js/clinical-journeys.js?v=v54-58" defer></script>
<script src="/assets/js/session-timeout.js?v=v54-30" defer></script>
<script src="/assets/js/avaliacao-confirmacoes.js?v=v54-63" defer></script>
<script src="/assets/js/clinical-context.js?v=v54-26" defer></script>
<script src="/assets/js/operational-ui.js?v=v54-64" defer></script>
<script src="/assets/js/workflow-drafts.js?v=v54-68" defer></script>
<script src="/assets/js/navigation-v54-58.js?v=v54-64" defer></script>
</body>
</html>
