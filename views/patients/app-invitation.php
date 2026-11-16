<?php
/** @var array|null $invitation */
/** @var array $patient */
/** @var string|null $error */
/** @var int $patientId */
/** @var string $directLink */
/** @var string $portalBaseUrl */
$nonce = \ClyoNutri\Core\Http\SecurityHeaders::nonce();
$patientName = $patient['name'] ?? 'Paciente';
$code = $invitation['invitation_code'] ?? '';

// v21: texto neutro em gênero, assinado por quem envia (sem "seu nutricionista").
$patientFirst = trim(explode(' ', trim((string)$patientName))[0] ?? '');
$senderName = trim((string)($userName ?? ''));
$whatsappMsg = urlencode(
    "Oi" . ($patientFirst !== '' ? ", {$patientFirst}" : '') . "! " . ($senderName !== '' ? "Aqui é {$senderName}. " : '') .
    "Liberei seu acesso ao Clyo Nutri, onde você vê seu plano alimentar e registra seu diário.\n\n" .
    "Para entrar e criar sua senha, toque no link:\n{$directLink}\n\n" .
    "Se preferir, abra {$portalBaseUrl} e digite o código {$code}.\n\n" .
    "O link vale por 48 horas."
);
$whatsappUrl = "https://api.whatsapp.com/send?text={$whatsappMsg}" . (!empty($patient['phone']) ? '&phone=' . preg_replace('/\D/', '', $patient['phone']) : '');
?>

<div class="u-maxw-wide u-my-0 u-mx-auto u-pb-10">
  <div class="u-mb-6 u-flex u-justify-between u-items-center u-wrap u-gap-3">
    <div>
      <div class="u-text-compact u-color-neutral-gray-500 u-mb-1">
        <a href="/pacientes/<?= (int)$patientId ?>" class="u-color-botanical-copper-600 u-no-underline">← Voltar ao perfil de <?= htmlspecialchars($patientName) ?></a>
      </div>
      <h1 class="u-text-h1 u-font-bold u-color-botanical-forest-900 u-m-0">Primeiro acesso ao app do paciente</h1>
      <p class="u-color-neutral-gray-500 u-mt-1 u-text-body">
        Gere e compartilhe as credenciais de primeiro acesso para <?= htmlspecialchars($patientName) ?> acompanhar seu plano alimentar e diário.
      </p>
    </div>
  </div>

  <?php if (!empty($error)): ?>
    <div class="card u-p-6 u-accent u-accent-semantic-error u-bg-neutral-white u-radius-card u-shadow-md u-mb-6">
      <div class="u-flex u-gap-4 u-items-start">
        <div class="ds-icon-badge ds-icon-badge--error u-text-h3">
          ×
        </div>
        <div>
          <h2 class="u-text-card-title u-font-semibold u-color-semantic-error-strong u-mt-0 u-mb-2 u-mx-0">Não foi possível gerar o convite</h2>
          <p class="u-color-neutral-gray-600 u-mt-0 u-mb-4 u-mx-0 u-text-body u-leading-body">
            <?= htmlspecialchars($error) ?>
          </p>
          <div class="u-flex u-gap-2 u-wrap">
            <a href="/pacientes/<?= (int)$patientId ?>" class="btn btn-secondary u-py-2 u-px-4 u-text-compact">Voltar ao perfil do paciente</a>
            <a href="/paciente/app" target="_blank" class="btn btn-outline u-py-2 u-px-4 u-text-compact">Acessar portal do paciente</a>
          </div>
        </div>
      </div>
    </div>
  <?php else: ?>

    <?php if (!empty($emailStatus)): ?>
      <?php
        $patientEmail = trim((string)($patient['email'] ?? ''));
        [$emailBg, $emailBorder, $emailColor, $emailText] = match ($emailStatus) {
            'sent' => ['success', '', '', 'Convite enviado por e-mail' . ($patientEmail !== '' ? ' para ' . htmlspecialchars($patientEmail) : '') . '. O link também está abaixo, caso precise enviar por outro canal.'],
            'logged' => ['info', '', '', 'Ambiente sem SMTP: o e-mail foi apenas registrado no log, não enviado de verdade. Use o link abaixo para enviar manualmente.'],
            'skipped' => ['warning', '', '', 'Não enviamos por e-mail (' . htmlspecialchars((string)$emailReason) . '). Use o link ou o botão de WhatsApp abaixo.'],
            default => ['error', '', '', 'O e-mail não pôde ser enviado (' . htmlspecialchars((string)$emailReason) . '). O convite é válido; envie o link abaixo manualmente.'],
        };
      ?>
      <div class="op-message op-message--<?= $emailBg ?> u-mb-5" role="<?= $emailBg === 'error' ? 'alert' : 'status' ?>" data-testid="invitation-email-status">
        <?= $emailText ?>
      </div>
    <?php endif; ?>

    <!-- Resumo do Paciente -->
    <div class="card u-py-5 u-px-6 u-bg-neutral-gray-50 u-border u-radius-card u-mb-6">
      <div class="u-flex u-justify-between u-items-center u-wrap u-gap-4">
        <div>
          <span class="u-text-caption u-font-bold u-uppercase u-color-neutral-gray-500 u-tracking-wide">Paciente</span>
          <div class="u-text-card-title u-font-bold u-color-botanical-forest-900"><?= htmlspecialchars($patientName) ?></div>
          <div class="u-text-compact u-color-neutral-gray-500 u-mt-0">
            <?= !empty($patient['email']) ? htmlspecialchars($patient['email']) : 'Sem e-mail cadastrado' ?>
            <?= !empty($patient['phone']) ? ' • ' . htmlspecialchars($patient['phone']) : '' ?>
          </div>
        </div>
        <div class="u-text-right">
          <span class="u-inline-block u-py-1 u-px-2 u-radius-pill u-bg-semantic-success-light u-color-semantic-success-text u-text-compact u-font-semibold">
            ✓ Convite Ativo (Válido por 48 horas)
          </span>
          <div class="u-text-caption u-color-neutral-gray-500 u-mt-1">
            <?php // v54.57 (UX-106): expiração gravada em UTC, mostrada no fuso do consultório ?>
            Válido até <?= htmlspecialchars(isset($invitation['expires_at']) ? \ClyoNutri\Core\I18n\LocalTime::fromUtc((string)$invitation['expires_at']) . ' (' . \ClyoNutri\Core\I18n\LocalTime::zoneLabel() . ')' : '48h') ?>
          </div>
        </div>
      </div>
    </div>

    <!-- Grid de Compartilhamento -->
    <div class="invitation-grid u-grid u-cols-aside u-gap-6 u-mb-6 u-items-start">
      <!-- Coluna Principal: Links e Botões -->
      <div class="u-flex u-flex-col u-gap-4">

        <!-- Link Direto (Recomendado) -->
        <div class="card u-p-5 u-bg-neutral-white u-border u-bc-neutral-gray-300 u-radius-card u-shadow-sm">
          <div class="u-flex u-justify-between u-items-center u-mb-2">
            <label for="direct-link-input" class="u-font-semibold u-text-compact u-color-botanical-forest-900">
              Link de ativação (mais fácil para o paciente)
            </label>
            <span class="u-text-caption u-bg-botanical-sage-100 u-color-botanical-forest-900 u-py-0 u-px-2 u-radius-input u-font-semibold">1 clique</span>
          </div>
          <p class="u-text-compact u-color-neutral-gray-500 u-mt-0 u-mb-2 u-mx-0">
            O paciente clica no link e vai direto para a tela de confirmação e cadastro de senha, com seus dados já carregados.
          </p>
          <div class="u-flex u-gap-2 u-items-center">
            <input id="direct-link-input" type="text" readonly value="<?= htmlspecialchars($directLink) ?>" class="u-flex-1 u-py-2 u-px-3 u-font-mono u-text-compact u-border u-bc-border-input u-radius-input u-bg-neutral-gray-50 u-color-neutral-gray-700" data-select-all>
            <button type="button" class="btn btn-primary u-py-2 u-px-4 u-text-compact u-nowrap" id="btn-copy-link">
              Copiar link
            </button>
          </div>
          <div class="u-flex u-gap-2 u-mt-3 u-wrap">
            <a href="<?= htmlspecialchars($whatsappUrl) ?>" target="_blank" rel="noopener noreferrer" class="btn btn-whatsapp">
              <span>Enviar via WhatsApp</span>
            </a>
            <a href="<?= htmlspecialchars($directLink) ?>" target="_blank" rel="noopener noreferrer" class="btn btn-secondary u-text-compact u-py-2 u-px-3 u-no-underline">
              ↗ Abrir e Conferir Tela
            </a>
          </div>
        </div>

        <!-- Código de Convite Manual -->
        <div class="card u-p-5 u-bg-neutral-white u-border u-bc-neutral-gray-300 u-radius-card u-shadow-sm">
          <div class="u-flex u-justify-between u-items-center u-mb-2">
            <label for="invitation-code" class="u-font-semibold u-text-compact u-color-botanical-forest-900">
              Código de convite individual
            </label>
            <span class="u-text-caption u-bg-neutral-gray-100 u-color-neutral-gray-600 u-py-0 u-px-2 u-radius-input u-font-semibold">Manual</span>
          </div>
          <p class="u-text-compact u-color-neutral-gray-500 u-mt-0 u-mb-2 u-mx-0">
            Caso o paciente acerte pelo portal geral <a href="/paciente/app" target="_blank" class="u-color-botanical-copper-600">clyonutri.com/paciente/app</a> ou pelo aplicativo instalado:
          </p>
          <div class="u-flex u-gap-2 u-items-center">
            <input id="invitation-code" type="text" readonly value="<?= htmlspecialchars($code) ?>" class="u-flex-1 u-py-2 u-px-3 u-font-mono u-text-compact u-border u-bc-border-input u-radius-input u-bg-neutral-gray-50 u-color-neutral-gray-700 u-tracking-wide" data-select-all>
            <button type="button" class="btn btn-secondary u-py-2 u-px-4 u-text-compact u-nowrap" id="btn-copy-code">
              Copiar código
            </button>
          </div>
        </div>

      </div>

      <!-- Coluna Lateral: QR Code Presencial -->
      <div class="card u-p-5 u-bg-neutral-white u-border u-bc-neutral-gray-300 u-radius-card u-text-center u-shadow-sm">
        <div class="u-font-semibold u-text-compact u-color-botanical-forest-900 u-mb-1">
          QR code presencial
        </div>
        <p class="u-text-caption u-color-neutral-gray-500 u-mt-0 u-mb-3 u-mx-0">
          Aponte a câmera do celular do paciente para ativar agora
        </p>
        <div id="app-qrcode" class="u-flex u-justify-center u-items-center u-minh-area u-my-0 u-mx-auto u-bg-neutral-white u-p-2 u-radius-input u-border u-bc-neutral-gray-100">
          <!-- Renderizado via qrcode.min.js -->
        </div>
        <div class="u-text-caption u-color-neutral-gray-400 u-mt-2">
          Escaneie para abrir o portal web ou baixar o app
        </div>
      </div>
    </div>

    <!-- Instruções para o Nutricionista e Paciente -->
    <div class="card u-p-6 u-bg-neutral-gray-50 u-border u-radius-card u-mb-6">
      <h3 class="u-text-body u-font-semibold u-color-botanical-forest-900 u-mt-0 u-mb-3 u-mx-0">
        Como funciona o primeiro acesso do paciente?
      </h3>
      <ol class="u-m-0 u-pl-5 u-color-neutral-gray-600 u-text-compact u-leading-body">
        <li class="u-mb-1">
          <strong>Envie o link ou código ao paciente:</strong> Utilize o botão de WhatsApp acima ou copie o link direto.
        </li>
        <li class="u-mb-1">
          <strong>O paciente abre o portal ou app:</strong> O link direciona para o portal seguro da clínica em qualquer dispositivo (navegador de celular, tablet ou computador).
        </li>
        <li class="u-mb-1">
          <strong>Confirmação de identidade:</strong> O paciente confere seu nome, telefone e e-mail e cadastra uma senha de 8 a 72 caracteres.
        </li>
        <li class="u-mb-0">
          <strong>Acesso imediato:</strong> Ao concluir, o paciente já visualiza o plano alimentar, diário de refeições, registro de água e sala de teleconsulta.
        </li>
      </ol>
      <div class="u-mt-4 u-pt-4 u-border-t u-text-compact u-color-neutral-gray-500">
        <strong>Segurança LGPD:</strong> O convite é de uso único e privado. Gerar outro convite invalida este anterior automaticamente.
      </div>
    </div>

    <!-- Ações Inferiores -->
    <div class="u-flex u-justify-between u-items-center u-wrap u-gap-3">
      <a class="btn btn-secondary" href="/pacientes/<?= (int)$patientId ?>">
        ← Concluir e Voltar ao Perfil
      </a>
      <form method="post" action="/pacientes/<?= (int)$patientId ?>/convite-app" class="u-m-0" data-confirm="Gerar um novo código invalidará o convite anterior. Deseja continuar?">
        <input type="hidden" name="_csrf_token" value="<?= htmlspecialchars(\ClyoNutri\Core\Security\Csrf::token()) ?>">
        <button class="btn btn-outline u-text-compact" type="submit">
          Gerar novo convite
        </button>
      </form>
    </div>

  <?php endif; ?>
</div>


<script src="/assets/js/qrcode.min.js"></script>
<script nonce="<?= $nonce ?>">
(function() {
  // Renderizar QR Code
  var qrContainer = document.getElementById('app-qrcode');
  var directUrl = <?= json_encode($directLink) ?>;
  if (qrContainer && typeof QRCode !== 'undefined' && directUrl) {
    new QRCode(qrContainer, {
      text: directUrl,
      width: 160,
      height: 160,
      // Canvas não entende var(); lê os tokens já resolvidos do design system.
      colorDark: getComputedStyle(document.documentElement).getPropertyValue('--neutral-ink-900').trim() || 'black',
      colorLight: getComputedStyle(document.documentElement).getPropertyValue('--neutral-white').trim() || 'white',
      correctLevel: QRCode.CorrectLevel.M
    });
  }

  // Feedback para botões de copiar
  function setupCopyButton(btnId, inputId, label) {
    var btn = document.getElementById(btnId);
    var input = document.getElementById(inputId);
    if (!btn || !input) return;
    btn.addEventListener('click', function() {
      input.select();
      if (navigator.clipboard && navigator.clipboard.writeText) {
        navigator.clipboard.writeText(input.value).then(function() {
          showCopied(btn, label);
        }).catch(function() {
          document.execCommand('copy');
          showCopied(btn, label);
        });
      } else {
        document.execCommand('copy');
        showCopied(btn, label);
      }
    });
  }

  function showCopied(btn, originalText) {
    btn.textContent = 'Copiado!';
    btn.classList.add('btn-success-solid');
    setTimeout(function() {
      btn.textContent = originalText;
      btn.classList.remove('btn-success-solid');
    }, 2000);
  }

  setupCopyButton('btn-copy-link', 'direct-link-input', 'Copiar Link');
  setupCopyButton('btn-copy-code', 'invitation-code', 'Copiar Código');
})();
</script>
