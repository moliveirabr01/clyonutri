<?php
/**
 * NI2-449: tela de aceite dos Termos de Uso e da Política de Privacidade vigentes.
 *
 * Aparece para quem entra no sistema sem ter aceitado a versão atual de algum dos dois textos
 * (versão nova publicada ou cadastro anterior ao registro de aceites). Sair do sistema continua possível.
 *
 * @var list<string> $pending   documentos pendentes ('terms', 'privacy')
 * @var array        $last      último aceite de cada documento: ['version' => ..., 'accepted_at' => ...]
 * @var string       $csrfToken
 * @var string|null  $error
 */
use ClyoNutri\Domain\Legal\LegalDocuments;

$fmtDate = static function (string $dt): string {
    $ts = strtotime($dt);
    return $ts ? date('d/m/Y', $ts) : $dt;
};
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
  <link rel="icon" type="image/svg+xml" href="/assets/clyonutri-icon.svg?v=botanical-v54-9">
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <meta name="robots" content="noindex">
  <title>Termos atualizados · Clyo Nutri</title>
  <link rel="preload" href="/assets/fonts/inter-normal-latin.woff2" as="font" type="font/woff2" crossorigin>
  <link rel="stylesheet" href="/assets/css/fonts.css?v=botanical-v54-9">
  <link rel="stylesheet" href="/assets/css/clinical-botanical.css?v=botanical-v54-9">
  <style>
    .acc-wrapper { min-height: 100vh; display: flex; align-items: center; justify-content: center; padding: var(--space-6); background: #FAF9F4; }
    .acc-card { width: 100%; max-width: 560px; background: var(--neutral-white); border-radius: var(--radius-card); border: var(--border-default); box-shadow: var(--shadow-md); padding: var(--space-8); }
    .acc-card h1 { font-size: 1.35rem; margin: 0 0 var(--space-2); color: var(--botanical-forest-900); }
    .acc-intro { color: var(--neutral-gray-700); font-size: 0.95rem; margin-bottom: var(--space-5); }
    .acc-docs { list-style: none; padding: 0; margin: 0 0 var(--space-5); }
    .acc-docs li { border: 1px solid var(--border-input, #828B9B); border-radius: var(--radius-input); padding: var(--space-3) var(--space-4); margin-bottom: var(--space-3); }
    .acc-docs a { color: var(--brand-copper-600); font-weight: 600; text-decoration: underline; }
    .acc-meta { display: block; font-size: 0.85rem; color: var(--neutral-gray-500); margin-top: 4px; }
    .acc-check { display: flex; align-items: flex-start; gap: 12px; min-height: 44px; font-size: 0.9rem; color: var(--neutral-gray-700); cursor: pointer; padding: 4px 0; }
    .acc-check input { width: 20px; height: 20px; min-width: 20px; margin-top: 2px; accent-color: var(--botanical-green-600); }
    .acc-error { background: #FEF2F2; border-left: 4px solid #DC2626; color: #B91C1C; padding: 12px 16px; border-radius: 6px; margin-bottom: var(--space-4); font-size: 0.9rem; }
    .acc-logout { margin-top: var(--space-5); padding-top: var(--space-4); border-top: var(--border-default); font-size: 0.85rem; color: var(--neutral-gray-500); }
    .acc-logout button { background: none; border: none; padding: 0; color: var(--neutral-gray-700); text-decoration: underline; cursor: pointer; font: inherit; min-height: 24px; }
    .sr-only { position: absolute; width: 1px; height: 1px; padding: 0; margin: -1px; overflow: hidden; clip: rect(0,0,0,0); white-space: nowrap; border: 0; }
  </style>
<link rel="stylesheet" href="/assets/css/operational-ui.css?v=v54-63">
</head>
<body>
  <main class="acc-wrapper" id="conteudo">
    <div class="acc-card">
      <img src="/assets/clyonutri-logo-light.svg?v=botanical-v54-9" alt="Clyo Nutri" width="200" height="49" style="display: block; margin-bottom: var(--space-5); height: auto;">

      <h1>Antes de continuar</h1>
      <p class="acc-intro">
        <?php if ($last === []): ?>
          Ainda não temos registro do seu aceite dos textos abaixo. Leia e confirme para seguir usando o Clyo Nutri.
        <?php else: ?>
          Publicamos uma versão nova dos textos abaixo. Leia e confirme para seguir usando o Clyo Nutri.
        <?php endif; ?>
      </p>

      <?php if (!empty($error)): ?>
        <div class="op-message op-message--error acc-error" id="acc-error" role="alert" tabindex="-1"><?= htmlspecialchars($error) ?></div>
      <?php endif; ?>

      <ul class="acc-docs">
        <?php foreach ($pending as $doc): ?>
          <li>
            <a href="<?= htmlspecialchars(LegalDocuments::url($doc)) ?>" target="_blank" rel="noopener"><?= htmlspecialchars(LegalDocuments::title($doc)) ?><span class="sr-only"> (abre em nova aba)</span></a>
            <span class="acc-meta">
              Versão <?= htmlspecialchars(LegalDocuments::currentVersion($doc)) ?>, de <?= htmlspecialchars(LegalDocuments::date($doc)) ?>.
              <?php if (isset($last[$doc])): ?>
                Você aceitou a versão <?= htmlspecialchars($last[$doc]['version']) ?> em <?= htmlspecialchars($fmtDate($last[$doc]['accepted_at'])) ?>.
              <?php endif; ?>
            </span>
          </li>
        <?php endforeach; ?>
      </ul>

      <form method="POST" action="/aceite-termos">
        <input type="hidden" name="_csrf_token" value="<?= htmlspecialchars($csrfToken) ?>">
        <?php foreach ($pending as $doc): ?>
          <input type="hidden" name="<?= htmlspecialchars($doc) ?>_version" value="<?= htmlspecialchars(LegalDocuments::currentVersion($doc)) ?>">
        <?php endforeach; ?>
        <label class="acc-check" for="acc-accepted">
          <input type="checkbox" id="acc-accepted" name="accepted" value="1" required>
          <span>
            Li e aceito
            <?php
            $parts = array_map(static fn (string $d): string => (($d === LegalDocuments::TERMS) ? 'os ' : 'a ') . LegalDocuments::title($d) . ' (versão ' . LegalDocuments::currentVersion($d) . ')', $pending);
            echo htmlspecialchars(implode(' e ', $parts));
            ?>.
          </span>
        </label>
        <button type="submit" class="btn btn-primary" style="width: 100%; margin-top: var(--space-4);">Li e aceito</button>
      </form>

      <div class="acc-logout">
        <form method="POST" action="/logout" style="display: inline;">
          <input type="hidden" name="_csrf_token" value="<?= htmlspecialchars($csrfToken) ?>">
          Não quer aceitar agora? <button type="submit">Sair do sistema</button>
        </form>
      </div>
    </div>
  </main>
  <?php if (!empty($error)): ?>
  <script nonce="<?= \ClyoNutri\Core\Http\SecurityHeaders::nonce() ?>">
    document.getElementById('acc-error').focus();
  </script>
  <?php endif; ?>
</body>
</html>
