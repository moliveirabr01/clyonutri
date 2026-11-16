<?php
// v54.62 (NI2-854 / UX-015): 7 itens de 1º nível (5 nucleares de Atendimento + 2 grupos recolhíveis: Biblioteca nutricional ▾ e Gestão ▾).
$navActor = \ClyoNutri\Core\Auth\AuthContext::get()?->toArray();
$navAllowed = static fn(string $url): bool => $navActor === null || \ClyoNutri\Core\Security\ClinicalAuthorization::allowsWeb($navActor, $url, 'GET');
$navNoticeCount = $navAllowed('/avisos') ? \ClyoNutri\Application\Controllers\NotificationsController::unreadForCurrentUser() : 0;
$navGroups = [
    'Atendimento' => [
        ['Início', '/dashboard', 'dashboard'],
        ['Agenda', '/agenda', 'agenda'],
        ['Pacientes', '/pacientes', 'pacientes'],
        ['Planos alimentares', '/planos', 'planos'],
        ['Conversas', '/chat', 'chat'],
    ],
    'Biblioteca nutricional' => [
        ['Base de alimentos', '/alimentos', 'alimentos'],
        ['Catálogo de exames', '/exames', 'exames'],
        ['Receitas', '/receitas', 'receitas'],
        ['Materiais', '/materiais', 'materiais'],
        ['Modelos de plano', '/planos/modelos', 'modelos'],
    ],
    'Gestão' => [
        ['Financeiro', '/financeiro', 'financeiro'],
        ['Indicadores', '/indicadores', 'indicadores'],
        ['Assinatura', '/configuracoes/assinatura', 'assinatura'],
        ['Avisos', '/avisos', 'avisos'],
        ['Pedidos LGPD', '/privacidade/pedidos', 'privacidade'],
        ['Configurações', '/configuracoes', 'configuracoes'],
        ['Central de ajuda', '/ajuda', 'ajuda'],
    ],
];
if (in_array($navActor['member_role'] ?? '', ['owner', 'admin'], true) && \ClyoNutri\Core\Security\ClinicalAuthorization::isClinical($navActor)) {
    $navGroups['Gestão'][] = ['Equipe', '/configuracoes/equipe', 'equipe'];
}
if (\ClyoNutri\Application\Controllers\AcademyController::enabled()) $navGroups['Gestão'][] = ['Clyo Academy', '/academy', 'academy'];
if (in_array($_SESSION['user_role'] ?? '', ['admin','operator','support_agent'], true)) $navGroups['Gestão'][] = ['Service desk', '/suporte/fila', 'service_desk'];
$navIcons = [
    '/dashboard' => '<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><rect width="7" height="7" x="3" y="3" rx="1.5"/><rect width="7" height="7" x="14" y="3" rx="1.5"/><rect width="7" height="7" x="14" y="14" rx="1.5"/><rect width="7" height="7" x="3" y="14" rx="1.5"/></svg>',
    '/agenda' => '<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><rect width="18" height="18" x="3" y="4" rx="2" ry="2"/><line x1="16" x2="16" y1="2" y2="6"/><line x1="8" x2="8" y1="2" y2="6"/><line x1="3" x2="21" y1="10" y2="10"/><path d="M8 14h.01"/><path d="M12 14h.01"/><path d="M16 14h.01"/><path d="M8 18h.01"/><path d="M12 18h.01"/><path d="M16 18h.01"/></svg>',
    '/pacientes' => '<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M22 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/></svg>',
    '/chat' => '<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M7.9 20A9 9 0 1 0 4 16.1L2 22Z"/></svg>',
    '/alimentos' => '<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M12 20.94c1.5 0 2.75 1.06 4 1.06 3 0 6-8 6-12.22A4.91 4.91 0 0 0 17 5c-2.22 0-4 1.44-5 2-1-.56-2.78-2-5-2a4.9 4.9 0 0 0-5 4.78C2 14 5 22 8 22c1.25 0 2.5-1.06 4-1.06Z"/><path d="M10 2c1 .5 2 2 2 5"/></svg>',
    '/planos' => '<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><rect width="8" height="4" x="8" y="2" rx="1" ry="1"/><path d="M16 4h2a2 2 0 0 1 2 2v14a2 2 0 0 1-2 2H6a2 2 0 0 1-2-2V6a2 2 0 0 1 2-2h2"/><path d="M12 11h4"/><path d="M12 16h4"/><path d="M8 11h.01"/><path d="M8 16h.01"/></svg>',
    '/planos/modelos' => '<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><rect width="8" height="4" x="8" y="2" rx="1" ry="1"/><path d="M16 4h2a2 2 0 0 1 2 2v14a2 2 0 0 1-2 2H6a2 2 0 0 1-2-2V6a2 2 0 0 1 2-2h2"/></svg>',
    '/exames' => '<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M10 2v7.527a2 2 0 0 1-.211.896L4.72 20.55a1 1 0 0 0 .9 1.45h12.76a1 1 0 0 0 .9-1.45l-5.069-10.127A2 2 0 0 1 14 9.527V2"/><path d="M8.5 2h7"/><path d="M7 16h10"/></svg>',
    '/receitas' => '<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M6 13.87A4 4 0 0 1 7.41 6a5.11 5.11 0 0 1 1.05-1.54 5 5 0 0 1 7.08 0A5.11 5.11 0 0 1 16.59 6 4 4 0 0 1 18 13.87V21H6Z"/><line x1="6" y1="17" x2="18" y2="17"/></svg>',
    '/materiais' => '<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><path d="M14 2v6h6"/><path d="M9 13h6"/><path d="M9 17h4"/></svg>',
    '/financeiro' => '<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M19 7V4a1 1 0 0 0-1-1H5a2 2 0 0 0 0 4h15a1 1 0 0 1 1 1v4h-3a2 2 0 0 0 0 4h3a1 1 0 0 0 1-1v-2a1 1 0 0 0-1-1"/><path d="M3 5v14a2 2 0 0 0 2 2h15a1 1 0 0 0 1-1v-4"/></svg>',
    '/indicadores' => '<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><line x1="18" y1="20" x2="18" y2="10"/><line x1="12" y1="20" x2="12" y2="4"/><line x1="6" y1="20" x2="6" y2="14"/></svg>',
    '/configuracoes/assinatura' => '<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><rect width="20" height="14" x="2" y="5" rx="2"/><line x1="2" x2="22" y1="10" y2="10"/></svg>',
    '/avisos' => '<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M6 8a6 6 0 0 1 12 0c0 7 3 9 3 9H3s3-2 3-9"/><path d="M10.3 21a1.94 1.94 0 0 0 3.4 0"/></svg>',
    '/configuracoes' => '<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M12.22 2h-.44a2 2 0 0 0-2 2v.18a2 2 0 0 1-1 1.73l-.43.25a2 2 0 0 1-2 0l-.15-.08a2 2 0 0 0-2.73.73l-.22.38a2 2 0 0 0 .73 2.73l.15.1a2 2 0 0 1 1 1.72v.51a2 2 0 0 1-1 1.74l-.15.09a2 2 0 0 0-.73 2.73l.22.38a2 2 0 0 0 2.73.73l.15-.08a2 2 0 0 1 2 0l.43.25a2 2 0 0 1 1 1.73V20a2 2 0 0 0 2 2h.44a2 2 0 0 0 2-2v-.18a2 2 0 0 1 1-1.73l.43-.25a2 2 0 0 1 2 0l.15.08a2 2 0 0 0 2.73-.73l.22-.39a2 2 0 0 0-.73-2.73l-.15-.08a2 2 0 0 1-1-1.74v-.5a2 2 0 0 1 1-1.74l.15-.09a2 2 0 0 0 .73-2.73l-.22-.38a2 2 0 0 0-2.73-.73l-.15.08a2 2 0 0 1-2 0l-.43-.25a2 2 0 0 1-1-1.73V4a2 2 0 0 0-2-2z"/><circle cx="12" cy="12" r="3"/></svg>',
    '/privacidade/pedidos' => '<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M20 13c0 5-3.5 7.5-7.66 8.95a1 1 0 0 1-.67-.01C7.5 20.5 4 18 4 13V6a1 1 0 0 1 1-1c2 0 4.5-1.2 6.24-2.72a1.17 1.17 0 0 1 1.52 0C14.51 3.81 17 5 19 5a1 1 0 0 1 1 1z"/><path d="m9 12 2 2 4-4"/></svg>',
    '/academy' => '<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M21.42 10.922a1 1 0 0 0-.019-1.838L12.83 5.18a2 2 0 0 0-1.66 0L2.6 9.08a1 1 0 0 0 0 1.832l8.57 3.908a2 2 0 0 0 1.66 0z"/><path d="M22 10v6"/><path d="M6 12.5V16a6 3 0 0 0 12 0v-3.5"/></svg>',
    '/ajuda' => '<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="12" cy="12" r="10"/><path d="M9.09 9a3 3 0 0 1 5.83 1c0 2-3 3-3 3"/><line x1="12" y1="17" x2="12.01" y2="17"/></svg>',
    '/suporte/fila' => '<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M4 4h16c1.1 0 2 .9 2 2v12c0 1.1-.9 2-2 2H4c-1.1 0-2-.9-2-2V6c0-1.1.9-2 2-2z"/><polyline points="22,6 12,13 2,6"/></svg>',
];
$navE = static fn($s): string => htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
$reqPath = (string)parse_url($_SERVER['REQUEST_URI'] ?? '', PHP_URL_PATH);
$navActive = ($activeNav ?? '');
if ($navActive === 'configuracoes' && str_starts_with($reqPath, '/configuracoes/equipe')) {
    $navActive = 'equipe';
} elseif (str_starts_with($reqPath, '/configuracoes/assinatura')) {
    $navActive = 'assinatura';
} elseif (str_starts_with($reqPath, '/planos/modelos')) {
    $navActive = 'modelos';
}
?>
<div class="cn58-drawer-heading"><span id="cn58-menu-title">Menu principal</span><button type="button" class="cn58-menu-close" data-nav-close aria-label="Fechar menu">×</button></div>
<nav class="cn58-nav-sections" aria-label="Navegação do consultório">
<?php foreach ($navGroups as $group => $links):
    $links = array_values(array_filter($links, static fn($link) => $navAllowed($link[1])));
    if (!$links) continue;
    $isCoreGroup = ($group === 'Atendimento');
    $hasActiveInGroup = false;
    foreach ($links as [, , $k]) {
        if ($navActive === $k) {
            $hasActiveInGroup = true;
            break;
        }
    }
    $groupTestId = $group === 'Biblioteca nutricional' ? 'nav-group-biblioteca' : ($group === 'Gestão' ? 'nav-group-gestao' : 'nav-group-atendimento');
?>
  <?php if ($isCoreGroup): ?>
  <div class="cn58-nav-group" data-nav-group="<?= $navE($group) ?>" data-testid="<?= $navE($groupTestId) ?>">
    <p class="app-nav__group-label"><?= $navE($group) ?></p>
    <?php foreach ($links as [$label, $url, $key]): ?>
    <a href="<?= $navE($url) ?>" class="app-nav__item<?= $navActive === $key ? ' active' : '' ?>"<?= $navActive === $key ? ' aria-current="page"' : '' ?> data-nav-top-item="<?= $navE($key) ?>">
      <?= $navIcons[$url] ?? $navIcons['/pacientes'] ?><span><?= $navE($label) ?></span>
    </a>
    <?php endforeach; ?>
  </div>
  <?php else: ?>
  <details class="cn58-nav-group cn58-nav-collapsible" data-nav-group="<?= $navE($group) ?>" data-testid="<?= $navE($groupTestId) ?>" data-nav-top-item="<?= $navE($group) ?>"<?= $hasActiveInGroup ? ' open' : '' ?>>
    <summary class="app-nav__group-label app-nav__group-toggle">
      <span><?= $navE($group) ?></span>
      <span class="app-nav__chevron" aria-hidden="true">▾</span>
    </summary>
    <?php foreach ($links as [$label, $url, $key]): ?>
    <a href="<?= $navE($url) ?>" class="app-nav__item<?= $navActive === $key ? ' active' : '' ?>"<?= $navActive === $key ? ' aria-current="page"' : '' ?><?= $key === 'materiais' ? ' data-testid="nav-materiais"' : '' ?>>
      <?= $navIcons[$url] ?? $navIcons['/pacientes'] ?><span><?= $navE($label) ?></span>
      <?php if ($key === 'avisos' && $navNoticeCount > 0): ?><span class="badge" data-testid="nav-notices-count" aria-label="<?= $navNoticeCount ?> aviso<?= $navNoticeCount === 1 ? '' : 's' ?> não lido<?= $navNoticeCount === 1 ? '' : 's' ?>"><?= $navNoticeCount ?></span><?php endif; ?>
    </a>
    <?php endforeach; ?>
  </details>
  <?php endif; ?>
<?php endforeach; ?>
</nav>
<div class="cn58-nav-account">
  <p class="cn58-mobile-identity"><?= $navE($clinicName) ?><br><span><?= $navE($shellFirstName) ?></span></p>
  <form method="post" action="/logout"><input type="hidden" name="_csrf_token" value="<?= $navE(\ClyoNutri\Core\Security\Csrf::token()) ?>"><button type="submit" class="cn58-nav-logout">Sair do consultório</button></form>
</div>
