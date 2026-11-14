<?php
/**
 * Aceite do paciente para a transcrição da consulta (NI2-830 / SEC-001). Página pública do paciente (link de uso único)
 * e lista do portal. Não mostra dado clínico: só clínica, profissional, primeiro nome e data.
 *
 * @var string $mode  'link' | 'portal'
 * @var string $screen ask|accepted|rejected|revoked|unavailable|professional_session|portal
 * @var string $csrf
 * @var array<string,string> $terms
 * @var string $termsVersion
 * @var string $termsSha
 * @var array|null $view
 * @var string|null $token
 * @var string|null $revoke_token
 * @var array|null $items
 * @var string|null $message
 */
$e = static fn($v) => htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
$when = static function (?string $utc): string {
    if (!$utc) return '';
    try { return (new DateTimeImmutable($utc, new DateTimeZone('UTC')))->setTimezone(new DateTimeZone('America/Sao_Paulo'))->format('d/m/Y H:i'); }
    catch (Throwable $e) { return ''; }
};
$appt = static function (?string $local): string {
    if (!$local) return '';
    $t = strtotime($local);
    return $t ? date('d/m/Y H:i', $t) : '';
};
$title = 'Transcrição da consulta';
?><!DOCTYPE html>
<html lang="pt-BR">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <meta name="robots" content="noindex, nofollow">
  <meta name="referrer" content="<?= (($mode ?? "") === "portal") ? "same-origin" : "no-referrer" ?>">
  <title><?= $e($title) ?> · Clyo Nutri</title>
  <link rel="stylesheet" href="/assets/css/fonts.css?v=botanical-v54-9">
  <link rel="stylesheet" href="/assets/css/clinical-botanical.css?v=botanical-v54-9">
  <link rel="stylesheet" href="/assets/css/teleconsulta.css?v=v54-27">
  <link rel="stylesheet" href="/assets/css/operational-ui.css?v=v54-30">
</head>
<body class="tc-page">
  <header class="tc-header"><p class="tc-brand">Clyo Nutri · Aceite do paciente</p></header>
  <main class="tc-narrow" id="consent-main" data-screen="<?= $e($screen) ?>">
<?php
$renderTerms = static function () use ($terms, $termsVersion, $e): void { ?>
    <section aria-labelledby="terms-title" data-testid="consent-terms">
      <h2 id="terms-title">O que você está autorizando <span>(texto <span data-testid="terms-version"><?= $e($termsVersion) ?></span>)</span></h2>
      <dl>
<?php foreach ($terms as $name => $body): ?>
        <dt><?= $e($name) ?></dt>
        <dd><?= $e($body) ?></dd>
<?php endforeach; ?>
      </dl>
    </section>
<?php };

if ($screen === 'professional_session'): ?>
    <div class="tc-card" data-testid="consent-professional-session">
      <h1>Abra este link no aparelho do paciente</h1>
      <div class="tc-alert" data-tone="warn" role="alert"><p>Este navegador está com uma sessão de profissional aberta. O aceite precisa ser feito pelo próprio paciente, no aparelho dele ou no portal do paciente com a senha dele. Nada foi registrado.</p></div>
    </div>
<?php elseif ($screen === 'unavailable'): ?>
    <div class="tc-card" data-testid="consent-unavailable">
      <h1>Link indisponível</h1>
      <div class="tc-alert" data-tone="warn" role="alert"><p><?= $e($message ?? 'Este link é inválido, já foi usado ou expirou. Peça um novo ao profissional. Nenhum aceite foi registrado.') ?></p></div>
    </div>
<?php elseif ($screen === 'ask' && !empty($view)): ?>
    <div class="tc-card">
      <h1>Transcrição da consulta: você autoriza?</h1>
      <p data-testid="consent-context">Olá, <strong><?= $e($view['patient_first_name']) ?></strong>. <?= $e($view['professional_name']) ?> (<?= $e($view['clinic_name']) ?>) pede a sua autorização para transcrever em texto a consulta de <?= $e($appt($view['scheduled_at'])) ?>.</p>
      <p>Este link vale até <?= $e($when($view['expires_at_utc'])) ?> e só pode ser usado uma vez. Nada é gravado enquanto você não aceitar.</p>
      <?php $renderTerms(); ?>
      <form method="post" action="/consentimento-transcricao/<?= $e($token) ?>" data-testid="consent-form">
        <input type="hidden" name="_csrf_token" value="<?= $e($csrf) ?>">
        <input type="hidden" name="terms_sha256" value="<?= $e($view['terms_sha256']) ?>">
        <div class="tc-controls">
          <button type="submit" name="decision" value="accept" class="tc-btn tc-btn-primary" data-testid="consent-accept">Aceito a transcrição desta consulta</button>
          <button type="submit" name="decision" value="reject" class="tc-btn" data-testid="consent-reject">Não aceito</button>
        </div>
      </form>
    </div>
<?php elseif ($screen === 'accepted'): ?>
    <div class="tc-card" data-testid="consent-accepted">
      <h1>Aceite registrado</h1>
      <p>Você autorizou a transcrição somente desta consulta, por até 6 horas. O profissional ainda precisa iniciar a captação.</p>
      <p>Para revogar agora, use o botão abaixo. Depois, você pode revogar no portal do paciente, em "Transcrição da consulta", ou avisar o profissional.</p>
      <form method="post" action="/consentimento-transcricao-revogar" data-testid="consent-revoke-form">
        <input type="hidden" name="_csrf_token" value="<?= $e($csrf) ?>">
        <input type="hidden" name="revoke_token" value="<?= $e($revoke_token ?? '') ?>">
        <div class="tc-controls"><button type="submit" class="tc-btn tc-btn-danger" data-testid="consent-revoke">Revogar agora</button></div>
      </form>
    </div>
<?php elseif ($screen === 'rejected'): ?>
    <div class="tc-card" data-testid="consent-rejected">
      <h1>Recusa registrada</h1>
      <p>A consulta segue normalmente, sem transcrição. Nada foi captado.</p>
    </div>
<?php elseif ($screen === 'revoked'): ?>
    <div class="tc-card" data-testid="consent-revoked">
      <h1>Aceite revogado</h1>
      <p>A captação foi interrompida e nenhum novo trecho será gravado nesta consulta.</p>
    </div>
<?php elseif ($screen === 'portal'): ?>
    <div class="tc-card">
      <h1>Transcrição da consulta</h1>
      <?php if (!empty($message)): ?><div class="tc-alert" data-tone="warn" role="status" data-testid="portal-consent-message"><p><?= $e($message) ?></p></div><?php endif; ?>
      <?php if (empty($items)): ?>
        <p data-testid="portal-consent-empty">Nenhum pedido de aceite aguardando você neste momento.</p>
      <?php endif; ?>
    </div>
<?php foreach (($items ?? []) as $it): ?>
    <div class="tc-card" data-testid="portal-consent-item" data-state="<?= $e($it['state']) ?>">
      <h2>Consulta de <?= $e($appt($it['scheduled_at'])) ?> · <?= $e($it['professional_name']) ?></h2>
<?php if ($it['state'] === 'pending'): ?>
      <p>Pedido válido até <?= $e($when($it['expires_at_utc'])) ?>.</p>
      <?php $renderTerms(); ?>
      <form method="post" action="/paciente/consentimento/<?= (int)$it['id'] ?>/decidir">
        <input type="hidden" name="_csrf_token" value="<?= $e($csrf) ?>">
        <input type="hidden" name="terms_sha256" value="<?= $e($it['terms_sha256']) ?>">
        <div class="tc-controls">
          <button type="submit" name="decision" value="accept" class="tc-btn tc-btn-primary" data-testid="portal-consent-accept">Aceito a transcrição desta consulta</button>
          <button type="submit" name="decision" value="reject" class="tc-btn" data-testid="portal-consent-reject">Não aceito</button>
        </div>
      </form>
<?php else: ?>
      <p data-testid="portal-consent-active">Você autorizou a transcrição desta consulta até <?= $e($when($it['valid_until_utc'])) ?>.</p>
      <form method="post" action="/paciente/consentimento/<?= (int)$it['id'] ?>/revogar">
        <input type="hidden" name="_csrf_token" value="<?= $e($csrf) ?>">
        <div class="tc-controls"><button type="submit" class="tc-btn tc-btn-danger" data-testid="portal-consent-revoke">Revogar aceite</button></div>
      </form>
<?php endif; ?>
    </div>
<?php endforeach; ?>
<?php endif; ?>
  </main>
</body>
</html>
