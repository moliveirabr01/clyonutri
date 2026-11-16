<?php
/**
 * Vitrine pública de nutricionistas com link online e autoatendimento habilitados (/nutricionistas).
 *
 * @var array<int, array<string, mixed>> $professionals
 * @var string $query
 * @var string $topic
 * @var string $timezoneName
 */
$e = static fn($v) => htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
$topics = \ClyoNutri\Application\Controllers\BookingController::DIRECTORY_TOPICS;
?><!DOCTYPE html>
<html lang="pt-BR">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Encontre seu nutricionista online — Clyo Nutri</title>
  <meta name="description" content="Encontre nutricionistas com registro profissional ativo e agende sua consulta online ou presencial no Clyo Nutri.">
  <link rel="icon" type="image/svg+xml" href="/assets/clyonutri-icon.svg?v=botanical-v54-9">
  <link rel="preload" href="/assets/fonts/inter-normal-latin.woff2" as="font" type="font/woff2" crossorigin>
  <link rel="stylesheet" href="/assets/css/fonts.css?v=botanical-v54-9">
  <link rel="stylesheet" href="/assets/css/clinical-botanical.css?v=botanical-v54-9">
  <link rel="stylesheet" href="/assets/css/operational-ui.css?v=v54-63">
  <link rel="stylesheet" href="/assets/css/ds-components.css?v=v54-47">
  <link rel="stylesheet" href="/assets/css/ds-utilities.css?v=v54-47">
  <link rel="stylesheet" href="/assets/css/ds-pages.css?v=v54-53">
</head>
<body class="nutri-directory-body">
  <a class="skip-link" href="#directory-main">Pular para a lista de nutricionistas</a>

  <header class="nutri-dir-banner">
    <div class="nutri-dir-banner__inner">
      <div class="nutri-dir-banner__top">
        <a href="/" class="nutri-dir-banner__logo" aria-label="Clyo Nutri — Página inicial">
          <img src="/assets/clyonutri-icon.svg?v=botanical-v54-9" alt="Clyo Nutri" width="28" height="28">
        </a>

        <form method="GET" action="/nutricionistas" class="nutri-dir-search" role="search">
          <?php if ($topic !== ''): ?>
            <input type="hidden" name="tema" value="<?= $e($topic) ?>">
          <?php endif; ?>
          <svg class="nutri-dir-search__icon" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="11" cy="11" r="8"/><path d="m21 21-4.3-4.3"/></svg>
          <label for="nutri-dir-q" class="sr-only">Buscar por objetivo, especialidade, nome ou CRN</label>
          <input
            type="search"
            id="nutri-dir-q"
            name="q"
            value="<?= $e($query) ?>"
            placeholder="Emagrecimento, hipertrofia, reeducação alimentar, nome ou CRN..."
            class="nutri-dir-search__input"
            autocomplete="off"
          >
          <button type="submit" class="nutri-dir-search__btn">Filtrar</button>
        </form>
      </div>

      <nav class="nutri-dir-banner__links" aria-label="Atalhos institucionais">
        <a href="/#produto" class="nutri-dir-banner__link">Como funciona?</a>
        <a href="/login" class="nutri-dir-banner__link nutri-dir-banner__link--muted">Sou nutricionista</a>
      </nav>
    </div>
  </header>

  <main id="directory-main" class="nutri-dir-main" tabindex="-1">
    <section class="nutri-dir-hero" aria-labelledby="directory-title">
      <h1 id="directory-title" class="nutri-dir-hero__title">Encontre seu nutricionista online</h1>
      <div class="nutri-dir-verified">
        <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M20 13c0 5-3.5 7.5-7.66 8.95a1 1 0 0 1-.67-.01C7.5 20.5 4 18 4 13V6a1 1 0 0 1 1-1c2 0 4.5-1.2 6.24-2.72a1.17 1.17 0 0 1 1.52 0C14.51 3.81 17 5 19 5a1 1 0 0 1 1 1z"/><path d="m9 12 2 2 4-4"/></svg>
        <span>Nutricionistas com registro profissional e autoatendimento ativo</span>
      </div>
      <p class="nutri-dir-tz">
        <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="12" cy="12" r="10"/><path d="M12 2a14.5 14.5 0 0 0 0 20 14.5 14.5 0 0 0 0-20"/><path d="M2 12h20"/></svg>
        <span>Horários do agendamento em <?= $e($timezoneName ?? 'America/Sao_Paulo') ?>. O fuso e a data são confirmados ao escolher o horário.</span>
      </p>
    </section>

    <section class="nutri-dir-topics" aria-labelledby="topics-heading">
      <h2 id="topics-heading" class="nutri-dir-topics__title">O que te trouxe aqui?</h2>
      <div class="nutri-dir-topics__list">
        <?php foreach ($topics as $tItem):
          $isActive = mb_strtolower($topic, 'UTF-8') === mb_strtolower($tItem, 'UTF-8');
          $hrefParams = [];
          if ($query !== '') {
              $hrefParams['q'] = $query;
          }
          if (!$isActive) {
              $hrefParams['tema'] = $tItem;
          }
          $chipHref = '/nutricionistas' . ($hrefParams ? '?' . http_build_query($hrefParams) : '');
        ?>
          <a href="<?= $e($chipHref) ?>" class="nutri-dir-chip<?= $isActive ? ' is-active' : '' ?>"<?= $isActive ? ' aria-current="true"' : '' ?>>
            <?= $e($tItem) ?>
          </a>
        <?php endforeach; ?>
        <?php if ($query !== '' || $topic !== ''): ?>
          <a href="/nutricionistas" class="nutri-dir-chip nutri-dir-chip--dashed">Limpar filtros</a>
        <?php endif; ?>
      </div>
    </section>

    <section class="nutri-dir-list" aria-label="Profissionais disponíveis para agendamento">
      <?php if (empty($professionals)): ?>
        <div class="nutri-dir-card nutri-dir-empty">
          <h3 class="nutri-dir-card__name">Nenhum nutricionista encontrado para este filtro</h3>
          <p class="nutri-dir-card__bio">Tente buscar por outro termo ou limpar os filtros selecionados para ver todos os profissionais com agendamento online ativo.</p>
          <div>
            <a href="/nutricionistas" class="nutri-dir-btn nutri-dir-btn--primary">Ver todos os nutricionistas</a>
          </div>
        </div>
      <?php else: ?>
        <?php foreach ($professionals as $prof):
          $tags = is_array($prof['tags'] ?? null) ? $prof['tags'] : [];
          $visibleTags = array_slice($tags, 0, 7);
          $extraTagsCount = max(0, count($tags) - count($visibleTags));
          $bio = trim((string)($prof['bio'] ?? '')) ?: 'Apresentação profissional ainda não confirmada. Consulte o profissional para conhecer sua atuação.';
          $bioPreview = mb_strlen($bio, 'UTF-8') > 230 ? rtrim(mb_substr($bio, 0, 230, 'UTF-8')) . '…' : $bio;
          $hasLongBio = mb_strlen($bio, 'UTF-8') > 230;
          $price = $prof['default_price'] ?? null;
          $duration = (int)($prof['duration_minutes'] ?? 60);
          $nextSlot = $prof['next_slot_label'] ?? null;
          $bookingUrl = (string)($prof['booking_url'] ?? '/agendar');
          $chatUrl = (string)($prof['whatsapp_url'] ?? '');
          $isExternalChat = !empty($prof['whatsapp_url']);
        ?>
          <article class="nutri-dir-card" data-testid="nutritionist-directory-card">
            <div class="nutri-dir-card__header">
              <div class="nutri-dir-card__avatar" aria-hidden="true">
                <span><?= $e($prof['initials'] ?? 'CN') ?></span>
              </div>
              <div class="nutri-dir-card__identity">
                <h3 class="nutri-dir-card__name"><?= $e($prof['name']) ?></h3>
                <p class="nutri-dir-card__crn">
                  <?= $e($prof['crn_display'] !== '' ? $prof['crn_display'] : 'Nutricionista · Registro ativo') ?>
                  <?php if (!empty($prof['city_state'])): ?>
                    · <?= $e($prof['city_state']) ?>
                  <?php endif; ?>
                </p>
              </div>
            </div>

            <?php if (!empty($visibleTags)): ?>
              <div class="nutri-dir-card__tags" aria-label="Áreas de atuação e modalidades">
                <?php foreach ($visibleTags as $tag): ?>
                  <span class="nutri-dir-tag"><?= $e($tag) ?></span>
                <?php endforeach; ?>
                <?php if ($extraTagsCount > 0): ?>
                  <span class="nutri-dir-tag">+<?= (int)$extraTagsCount ?></span>
                <?php endif; ?>
              </div>
            <?php endif; ?>

            <div>
              <p class="nutri-dir-card__bio"><?= $e($bioPreview) ?></p>
              <?php if ($hasLongBio): ?>
                <details class="nutri-dir-card__more">
                  <summary>ver mais</summary>
                  <p class="nutri-dir-card__bio u-mt-2"><?= $e($bio) ?></p>
                </details>
              <?php endif; ?>
            </div>

            <div class="nutri-dir-card__meta">
              <div>
                <span class="nutri-dir-card__price">
                  <?= ($price !== null && (float)$price > 0) ? 'R$ ' . number_format((float)$price, 2, ',', '.') : 'A combinar' ?>
                </span>
                <span class="nutri-dir-card__duration">· <?= $duration ?> min</span>
              </div>
              <?php if ($nextSlot !== null): ?>
                <span class="nutri-dir-card__next"><?= $e($nextSlot) ?></span>
              <?php else: ?>
                <span class="nutri-dir-card__next nutri-dir-card__next--muted">Consulte horários na agenda</span>
              <?php endif; ?>
            </div>

            <div class="nutri-dir-card__actions">
              <?php if ($isExternalChat): ?><a
                href="<?= $e($chatUrl) ?>"
                class="nutri-dir-btn nutri-dir-btn--secondary"
                <?= $isExternalChat ? 'target="_blank" rel="noopener noreferrer"' : '' ?>
              >
                <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M7.9 20A9 9 0 1 0 4 16.1L2 22Z"/></svg>
                <span>Conversar pelo WhatsApp (abre outro site)</span>
              </a>
              <?php endif; ?><a href="<?= $e($bookingUrl) ?>" class="nutri-dir-btn nutri-dir-btn--primary" data-testid="directory-book-now">
                <span>Agendar agora</span>
              </a>
            </div>
          </article>
        <?php endforeach; ?>
      <?php endif; ?>
    </section>
  </main>
</body>
</html>
