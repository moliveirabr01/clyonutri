<?php
/** @var string $step */
/** @var array|null $preview */
/** @var string|null $error */
/** @var array|null $patient */
/** @var string $code */

$codeVal = $code ?? ($_POST['invitation_code'] ?? ($_GET['code'] ?? ''));
$nonce = \ClyoNutri\Core\Http\SecurityHeaders::nonce();
?><!DOCTYPE html>
<html lang="pt-BR">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Portal do Paciente · Clyo Nutri</title>
  <link rel="stylesheet" href="/assets/css/fonts.css?v=v54-66">
  <style>
    :root {
      /* Clinical Botanical (NI2-25) com o contraste da NI2-140 (A8): copper-600 só em texto e link, ação em verde. Os nomes --brand-* são legados e ficam só por
         compatibilidade; o valor é a paleta oficial. */
      --botanical-forest-950: var(--botanical-forest-950, #0A2A10);
      --botanical-forest-900: var(--botanical-forest-900, #0F3E17);
      --botanical-green-600: var(--botanical-green-600, #39734A);
      --botanical-green-700: var(--botanical-green-700, #2F6140);
      --botanical-sage-200: var(--botanical-sage-200, #D3E5D5);
      --botanical-sage-100: var(--botanical-sage-100, #E5F0E5);
      --botanical-cream-50: var(--botanical-cream-50, #FAF9F4);
      --botanical-copper-600: var(--botanical-copper-600, #B04A1B);
      --brand-navy-950: var(--botanical-forest-950);
      --brand-navy-900: var(--botanical-forest-900);
      --brand-copper-600: var(--botanical-copper-600);
      --brand-copper-500: var(--botanical-copper-500, #EF8F4F);
      --neutral-50: var(--botanical-cream-50);
      --brand-muted: var(--neutral-gray-500, #667085);
      --neutral-100: var(--neutral-gray-100, #F4F5F6);
      --neutral-200: var(--neutral-gray-200, #E5E7EB);
      --neutral-600: var(--neutral-gray-600, #475467);
      --neutral-900: var(--botanical-forest-900);
    }
    body {
      margin: 0;
      padding: 0;
      font-family: "Inter", -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Helvetica, Arial, sans-serif;
      background: var(--botanical-cream-50);
      color: var(--botanical-forest-900, #0F3E17);
      min-height: 100vh;
      display: flex;
      flex-direction: column;
    }
    .portal-header {
      background: #fff;
      border-bottom: 1px solid var(--neutral-gray-200, #E5E7EB);
      padding: 16px 24px;
      display: flex;
      justify-content: space-between;
      align-items: center;
    }
    .portal-header__brand {
      display: flex;
      align-items: center;
      gap: 12px;
      text-decoration: none;
      color: var(--botanical-forest-900, #0F3E17);
      font-weight: 700;
      font-size: 1.1rem;
    }
    .portal-header__badge {
      font-size: 0.75rem;
      background: var(--botanical-sage-100);
      color: var(--botanical-forest-900);
      padding: 2px 8px;
      border-radius: 9999px;
      font-weight: 600;
    }
    .portal-container {
      max-width: 600px;
      width: 100%;
      margin: 32px auto;
      padding: 0 16px;
      box-sizing: border-box;
      flex: 1;
    }
    .portal-card {
      background: #fff;
      border: 1px solid var(--botanical-sage-200, #D3E5D5);
      border-radius: 16px;
      padding: 32px;
      box-shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.05), 0 2px 4px -2px rgba(0, 0, 0, 0.05);
    }
    .portal-footer {
      text-align: center;
      padding: 24px 16px;
      color: var(--brand-muted);
      font-size: 0.8rem;
      border-top: 1px solid var(--botanical-sage-200);
      background: #fff;
    }
    .form-group {
      margin-bottom: 20px;
    }
    .form-label {
      display: block;
      font-size: 0.875rem;
      font-weight: 600;
      color: var(--neutral-gray-700, #344054);
      margin-bottom: 6px;
    }
    .form-control {
      width: 100%;
      padding: 10px 14px;
      border: 1px solid var(--border-input, #828B9B);
      border-radius: 8px;
      font-size: 0.95rem;
      box-sizing: border-box;
      transition: border-color 0.15s ease;
    }
    .form-control:focus {
      outline: none;
      border-color: var(--botanical-green-600);
      box-shadow: 0 0 0 3px rgba(57, 115, 74, 0.35);
    }
    /* Foco de teclado sempre visível (WCAG 2.4.7). */
    a:focus-visible,
    button:focus-visible,
    input[type="checkbox"]:focus-visible {
      outline: 3px solid var(--botanical-green-600);
      outline-offset: 2px;
    }
    .help-text {
      font-size: 0.75rem;
      color: var(--brand-muted);
      display: block;
      margin-top: 4px;
    }
    .btn-submit {
      width: 100%;
      padding: 12px;
      background: var(--botanical-green-600);
      color: #fff;
      border: none;
      border-radius: 8px;
      font-size: 1rem;
      font-weight: 600;
      cursor: pointer;
      transition: background 0.15s ease;
    }
    .btn-submit:hover {
      background: var(--botanical-green-700);
    }
    .alert-error {
      background: var(--semantic-error-light, #FEE2E2);
      border-left: 4px solid var(--semantic-error, #B42318);
      padding: 14px 16px;
      border-radius: 6px;
      color: var(--semantic-error-strong, #912018);
      font-size: 0.9rem;
      margin-bottom: 20px;
    }
    .tabs-nav {
      display: flex;
      border-bottom: 2px solid var(--botanical-sage-200);
      margin-bottom: 24px;
    }
    .tab-btn {
      flex: 1;
      padding: 12px;
      text-align: center;
      background: none;
      border: none;
      font-weight: 600;
      font-size: 0.95rem;
      color: var(--neutral-gray-600, #475467);
      cursor: pointer;
      min-height: 44px;
      border-bottom: 2px solid transparent;
      margin-bottom: -2px;
    }
    .tab-btn[aria-selected="true"] {
      color: var(--botanical-forest-900);
      border-bottom-color: var(--botanical-green-600);
    }
    .tab-content[hidden] {
      display: none;
    }
    .feature-list {
      display: grid;
      grid-template-columns: repeat(2, 1fr);
      gap: 12px;
      margin-top: 24px;
    }
    .feature-item {
      background: var(--botanical-sage-100, #E5F0E5);
      border: 1px solid var(--botanical-sage-200);
      padding: 12px;
      border-radius: 8px;
      font-size: 0.85rem;
      color: var(--neutral-gray-600, #475467);
    }
    .sr-only {
      position: absolute;
      width: 1px;
      height: 1px;
      padding: 0;
      margin: -1px;
      overflow: hidden;
      clip: rect(0, 0, 0, 0);
      white-space: nowrap;
      border: 0;
    }
    @media (max-width: 480px) {
      .portal-card {
        padding: 20px;
      }
      .feature-list {
        grid-template-columns: 1fr;
      }
    }
  </style>
<script src="/assets/js/validacao-ptbr.js" defer></script>
<link rel="stylesheet" href="/assets/css/operational-ui.css?v=v54-66">
</head>
<body>

  <header class="portal-header">
    <a href="/paciente/app" class="portal-header__brand">
      <img src="/assets/clyonutri-icon.png" alt="" width="28" height="28" style="border-radius:6px;">
      <span>Clyo Nutri</span>
    </a>
    <span class="portal-header__badge">Portal do Paciente</span>
  </header>

  <main class="portal-container">
    <div class="portal-card">

      <?php if (!empty($error)): ?>
        <div class="op-message op-message--error alert-error" role="alert">
          <strong>Atenção:</strong> <?= htmlspecialchars($error) ?>
        </div>
      <?php endif; ?>

      <?php if ($step === 'first_access' && !empty($preview)): ?>
        <!-- Tela de Confirmação do Primeiro Acesso -->
        <div style="text-align: center; margin-bottom: 24px;">
          <div style="width: 48px; height: 48px; border-radius: 50%; background: var(--botanical-sage-100); color: var(--botanical-green-600); display: flex; align-items: center; justify-content: center; margin: 0 auto 12px auto; font-size: 1.5rem;" aria-hidden="true">
            <?= htmlspecialchars(mb_substr((string)($preview['patient']['name'] ?? 'P'), 0, 1)) ?>
          </div>
          <h1 style="font-size: 1.4rem; font-weight: 700; color: var(--botanical-forest-900, #0F3E17); margin: 0 0 6px 0;">
            Olá, <?= htmlspecialchars($preview['patient']['name']) ?>!
          </h1>
          <p style="color: var(--neutral-gray-500, #667085); font-size: 0.9rem; margin: 0; line-height: 1.5;">
            Seu nutricionista <strong><?= htmlspecialchars($preview['professional_name']) ?></strong> (<?= htmlspecialchars($preview['clinic_name']) ?>) preparou seu acesso exclusivo ao aplicativo.
          </p>
        </div>

        <form method="POST" action="/paciente/app/primeiro-acesso">
          <input type="hidden" name="_csrf_token" value="<?= htmlspecialchars(\ClyoNutri\Core\Security\Csrf::token()) ?>">
          <input type="hidden" name="invitation_code" value="<?= htmlspecialchars((string)$codeVal) ?>">

          <div class="form-group">
            <label class="form-label" for="fa-name">Nome completo</label>
            <input class="form-control" id="fa-name" type="text" name="name" required value="<?= htmlspecialchars((string)($preview['patient']['name'] ?? '')) ?>">
          </div>

          <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 12px;">
            <div class="form-group">
              <label class="form-label" for="fa-email">E-mail</label>
              <input class="form-control" id="fa-email" type="email" name="email" required value="<?= htmlspecialchars((string)($preview['patient']['email'] ?? '')) ?>" placeholder="seu@email.com">
            </div>
            <div class="form-group">
              <label class="form-label" for="fa-phone">Celular / WhatsApp</label>
              <input class="form-control" id="fa-phone" type="tel" name="phone" required value="<?= htmlspecialchars((string)($preview['patient']['phone'] ?? '')) ?>" placeholder="(11) 99999-9999">
            </div>
          </div>

          <div class="form-group">
            <label class="form-label" for="fa-password">Crie uma senha</label>
            <input class="form-control" id="fa-password" type="password" name="password" required minlength="8" maxlength="72" placeholder="Mínimo de 8 caracteres" aria-describedby="fa-password-help">
            <span class="help-text" id="fa-password-help">Use no mínimo 8 caracteres (letras, números ou símbolos).</span>
          </div>

          <div class="form-group">
            <label class="form-label" for="fa-confirm">Confirme a senha</label>
            <input class="form-control" id="fa-confirm" type="password" name="password_confirmation" required minlength="8" maxlength="72" placeholder="Repita a senha digitada">
          </div>

          <div style="margin-bottom: 24px;">
            <label style="display: flex; gap: 8px; align-items: flex-start; font-size: 0.85rem; color: var(--neutral-gray-600, #475467); cursor: pointer;">
              <input type="checkbox" name="terms_accepted" value="1" required style="margin-top: 3px;">
              <span>Li e concordo com os <a href="/termos" target="_blank" style="color: var(--brand-copper-600); text-decoration: underline;">Termos de Uso<span class="sr-only"> (abre em nova aba)</span></a> (versão <?= htmlspecialchars(\ClyoNutri\Domain\Legal\LegalDocuments::TERMS_VERSION) ?>) e a <a href="/privacidade" target="_blank" style="color: var(--brand-copper-600); text-decoration: underline;">Política de Privacidade<span class="sr-only"> (abre em nova aba)</span></a> (versão <?= htmlspecialchars(\ClyoNutri\Domain\Legal\LegalDocuments::PRIVACY_VERSION) ?>) do Clyo Nutri.</span>
            </label>
          </div>

          <button type="submit" class="btn-submit">
            Ativar meu acesso e entrar
          </button>
        </form>

        <div style="text-align: center; margin-top: 20px;">
          <a href="/paciente/app" style="color: var(--neutral-gray-600, #475467); font-size: 0.85rem;">Voltar ao início</a>
        </div>

      <?php elseif ($step === 'activated'): ?>
        <!-- Tela de Sucesso da Ativação -->
        <div style="text-align: center; padding: 12px 0;">
          <div style="width: 56px; height: 56px; border-radius: 50%; background: var(--semantic-success-light, #E3F4F2); color: var(--semantic-success-text, #0A5F63); display: flex; align-items: center; justify-content: center; margin: 0 auto 16px auto; font-size: 2rem;" aria-hidden="true">
            ✓
          </div>
          <h1 style="font-size: 1.5rem; font-weight: 700; color: var(--botanical-forest-900, #0F3E17); margin: 0 0 8px 0;">
            Acesso ativado
          </h1>
          <p style="color: var(--neutral-gray-600, #475467); font-size: 0.95rem; margin: 0 0 24px 0; line-height: 1.5;">
            Tudo certo, <strong><?= htmlspecialchars($patient['name'] ?? 'Paciente') ?></strong>. Sua senha foi criada e você já pode usar o app.
          </p>

          <div style="background: var(--botanical-sage-100, #E5F0E5); border: 1px solid var(--botanical-sage-200); border-radius: 12px; padding: 20px; text-align: left; margin-bottom: 24px;">
            <h3 style="font-size: 0.9rem; font-weight: 700; color: var(--botanical-forest-900, #0F3E17); margin: 0 0 8px 0;">O que você pode fazer agora:</h3>
            <ul style="margin: 0; padding-left: 20px; font-size: 0.85rem; color: var(--neutral-gray-600, #475467); line-height: 1.6;">
              <li>Acessar seu plano alimentar e prescrições em tempo real.</li>
              <li>Registrar fotos das suas refeições no diário alimentar.</li>
              <li>Acompanhar sua meta de hidratação diária.</li>
              <li>Entrar na sala de teleconsulta com 1 clique na data agendada.</li>
            </ul>
          </div>

          <div style="display: flex; flex-direction: column; gap: 10px;">
            <a href="/paciente/app" class="btn-submit" style="display: block; text-decoration: none; text-align: center;">
              Ir para o meu portal
            </a>
            <div style="font-size: 0.8rem; color: var(--brand-muted); margin-top: 8px;">
              Dica: adicione esta página à tela de início do seu celular para abrir como aplicativo a qualquer momento.
            </div>
          </div>
        </div>

      <?php elseif ($step === 'accept_terms'): ?>
        <?php /* NI2-449: aceite da versão vigente dos Termos e da Política antes de seguir */ ?>
        <div style="text-align: center; margin-bottom: 20px;">
          <h1 style="font-size: 1.35rem; font-weight: 700; color: var(--botanical-forest-900, #0F3E17); margin: 0 0 6px 0;">Antes de continuar</h1>
          <p style="color: var(--neutral-gray-600, #475467); font-size: 0.9rem; margin: 0;">
            Os textos abaixo têm versão nova ou ainda não temos registro do seu aceite. Leia e confirme para seguir.
          </p>
        </div>
        <ul style="list-style: none; padding: 0; margin: 0 0 20px;">
          <?php foreach (($pendingLegal ?? []) as $legalDoc): ?>
            <li style="border: 1px solid var(--border-input, #828B9B); border-radius: 8px; padding: 12px 14px; margin-bottom: 10px;">
              <a href="<?= htmlspecialchars(\ClyoNutri\Domain\Legal\LegalDocuments::url($legalDoc)) ?>" target="_blank" rel="noopener" style="color: var(--brand-copper-600); font-weight: 600; text-decoration: underline;"><?= htmlspecialchars(\ClyoNutri\Domain\Legal\LegalDocuments::title($legalDoc)) ?><span class="sr-only"> (abre em nova aba)</span></a>
              <span style="display: block; font-size: 0.85rem; color: var(--neutral-gray-500, #667085); margin-top: 4px;">Versão <?= htmlspecialchars(\ClyoNutri\Domain\Legal\LegalDocuments::currentVersion($legalDoc)) ?>, de <?= htmlspecialchars(\ClyoNutri\Domain\Legal\LegalDocuments::date($legalDoc)) ?>.</span>
            </li>
          <?php endforeach; ?>
        </ul>
        <form method="POST" action="/paciente/app/aceite-termos">
          <input type="hidden" name="_csrf_token" value="<?= htmlspecialchars(\ClyoNutri\Core\Security\Csrf::token()) ?>">
          <?php foreach (($pendingLegal ?? []) as $legalDoc): ?>
            <input type="hidden" name="<?= htmlspecialchars($legalDoc) ?>_version" value="<?= htmlspecialchars(\ClyoNutri\Domain\Legal\LegalDocuments::currentVersion($legalDoc)) ?>">
          <?php endforeach; ?>
          <div style="margin-bottom: 20px;">
            <label for="legal-accepted" style="display: flex; gap: 10px; align-items: flex-start; min-height: 44px; font-size: 0.9rem; color: var(--neutral-gray-600, #475467); cursor: pointer;">
              <input type="checkbox" id="legal-accepted" name="accepted" value="1" required style="width: 20px; height: 20px; min-width: 20px; margin-top: 2px;">
              <span>Li e aceito os textos acima.</span>
            </label>
          </div>
          <button type="submit" class="btn-submit">Li e aceito</button>
        </form>

      <?php elseif ($step === 'logged_in'): ?>
        <?php
        // v54.57 (UX-102): área logada com dados reais da sessão do portal. Sem sessão (logo após o login pela API
        // antiga), mostra só os atalhos.
        $portal = $portal ?? null;
        $fmtDate = static function (?string $iso): string {
            if (!$iso) return '';
            try { $d = new DateTimeImmutable($iso); $d = $d->setTimezone(new DateTimeZone(date_default_timezone_get())); return $d->format('d/m/Y \à\s H:i'); } catch (\Throwable $e) { return ''; }
        };
        ?>
        <link rel="stylesheet" href="/assets/css/patient-portal-v54-57.css?v=v54-57">
        <div class="pp-head">
          <h1 class="pp-title">Olá<?= $portal ? ', ' . htmlspecialchars(explode(' ', trim($portal['patient_name']))[0]) : '' ?></h1>
          <p class="pp-muted"><?= $portal ? 'Seu acompanhamento com ' . htmlspecialchars($portal['clinic_name']) : 'Acesso conectado ao Clyo Nutri' ?></p>
        </div>
        <?php if (!empty($portalNotice)): ?><div class="op-message op-message--info" role="status" data-testid="portal-notice"><?= htmlspecialchars($portalNotice) ?></div><?php endif; ?>
        <nav class="pp-cards" aria-label="Seu acompanhamento" data-testid="portal-cards">
          <a class="pp-card" href="/paciente/plano" data-testid="portal-card-plano">
            <strong>Plano alimentar</strong>
            <?php if ($portal && $portal['plan']): ?>
              <span>Versão <?= (int)($portal['plan']['version_number'] ?? 1) ?><?php $pub = $fmtDate($portal['plan']['published_at'] ?? null); if ($pub): ?>, publicada em <?= htmlspecialchars($pub) ?><?php endif; ?> · <?= count($portal['plan']['meals'] ?? []) ?> refeições</span>
            <?php else: ?>
              <span>Seu nutricionista ainda não publicou um plano.</span>
            <?php endif; ?>
          </a>
          <a class="pp-card" href="/paciente/consultas" data-testid="portal-card-consultas">
            <strong>Minhas consultas</strong>
            <?php if ($portal && $portal['next']): ?>
              <span>Próxima: <?= htmlspecialchars($fmtDate($portal['next']['scheduled_at_iso'] ?? null)) ?> · <?= htmlspecialchars((string)($portal['next']['modality_label'] ?? '')) ?> · <?= htmlspecialchars((string)($portal['next']['status_label'] ?? '')) ?>. Confirmar, reagendar ou cancelar.</span>
            <?php else: ?>
              <span>Nenhuma consulta marcada. Veja o histórico e agende.</span>
            <?php endif; ?>
          </a>
          <?php if ($portal && !empty($portal['next']['meeting_url'])): ?>
          <a class="pp-card" href="<?= htmlspecialchars((string)$portal['next']['meeting_url']) ?>" data-testid="portal-card-tele">
            <strong>Entrar na teleconsulta</strong><span>A sala da sua próxima consulta online já está aberta.</span>
          </a>
          <?php endif; ?>
          <?php if(!empty($portal['manipulados_available'])): ?>
          <a class="pp-card" href="/paciente/prescricoes"><strong>Prescrições</strong><span>Manipulados e suplementação, com o PDF assinado.</span></a>
          <?php endif; ?>
          <a class="pp-card" href="/paciente/materiais" data-testid="patient-materials-link"><strong>Materiais</strong><span>Orientações, receitas e listas enviadas para você.</span></a>
        </nav>
        <?php if ($portal): ?>
        <section class="pp-water" id="agua" aria-labelledby="pp-water-title" data-testid="portal-agua">
          <h2 id="pp-water-title">Água de hoje</h2>
          <p><strong data-testid="portal-agua-total"><?= number_format((int)$portal['water_today'], 0, ',', '.') ?> ml</strong><?php if ($portal['water_goal']): ?> de <?= number_format((int)$portal['water_goal'], 0, ',', '.') ?> ml (meta do seu nutricionista)<?php endif; ?></p>
          <form method="post" action="/paciente/hidratacao" class="pp-water__form">
            <input type="hidden" name="_csrf_token" value="<?= htmlspecialchars(\ClyoNutri\Core\Security\Csrf::token()) ?>">
            <input type="hidden" name="idempotency_key" value="<?= bin2hex(random_bytes(16)) ?>">
            <?php foreach ([200, 300, 500] as $ml): ?>
              <button type="submit" name="amount_ml" value="<?= $ml ?>" class="btn-submit pp-water__btn">Registrar <?= $ml ?> ml</button>
            <?php endforeach; ?>
          </form>
        </section>
        <form method="post" action="/paciente/app/sair" class="pp-logout">
          <input type="hidden" name="_csrf_token" value="<?= htmlspecialchars(\ClyoNutri\Core\Security\Csrf::token()) ?>">
          <button type="submit" class="pp-link-btn" data-testid="portal-sair">Sair do portal</button>
        </form>
        <?php endif; ?>

      <?php else: ?>
        <!-- Tela Inicial: Abas Primeiro Acesso e Login -->
        <div style="text-align: center; margin-bottom: 20px;">
          <h1 style="font-size: 1.35rem; font-weight: 700; color: var(--botanical-forest-900, #0F3E17); margin: 0 0 6px 0;">
            Acompanhe seu plano no Clyo Nutri
          </h1>
          <p style="color: var(--neutral-gray-500, #667085); font-size: 0.875rem; margin: 0;">
            Acesse seu plano alimentar, diário e consultas de qualquer dispositivo.
          </p>
        </div>

        <div class="tabs-nav" role="tablist" aria-label="Como você quer entrar">
          <button type="button" class="tab-btn" role="tab" id="tab-btn-login" aria-controls="tab-login" aria-selected="true">
            Entrar
          </button>
          <button type="button" class="tab-btn" role="tab" id="tab-btn-first" aria-controls="tab-first" aria-selected="false" tabindex="-1">
            Primeiro acesso
          </button>
        </div>

        <!-- Aba 1: Login com Credenciais (padrão NI2-890) -->
        <div id="tab-login" class="tab-content" role="tabpanel" aria-labelledby="tab-btn-login">
          <form method="POST" action="/paciente/app/login">
            <input type="hidden" name="_csrf_token" value="<?= htmlspecialchars(\ClyoNutri\Core\Security\Csrf::token()) ?>">
            <div class="form-group">
              <label class="form-label" for="login-input">E-mail ou celular</label>
              <input class="form-control" id="login-input" type="text" name="login" required placeholder="Digite seus dados de acesso">
            </div>
            <div class="form-group">
              <label class="form-label" for="login-pass">Senha</label>
              <input class="form-control" id="login-pass" type="password" name="password" required placeholder="Sua senha cadastrada">
            </div>
            <button type="submit" class="btn-submit">
              Entrar no portal
            </button>
          </form>
          <div style="text-align: center; margin-top: 14px; display: flex; flex-direction: column; gap: 6px;">
            <a href="#tab-first" data-patient-first-access style="font-size: 0.85rem; color: var(--botanical-green-700); font-weight: 600;">
              É seu primeiro acesso? Crie sua senha
            </a>
            <a href="/paciente/recuperar-senha" style="font-size: 0.8rem; color: var(--brand-copper-600);">
              Esqueci minha senha
            </a>
          </div>
        </div>

        <!-- Aba 2: Primeiro Acesso com Código -->
        <div id="tab-first" class="tab-content" role="tabpanel" aria-labelledby="tab-btn-first" hidden>
          <form method="GET" action="/paciente/app">
            <div class="form-group">
              <label class="form-label" for="code-input">Código do convite</label>
              <input class="form-control" id="code-input" type="text" name="code" required placeholder="Cole o código recebido do seu nutricionista" value="<?= htmlspecialchars((string)$codeVal) ?>" style="font-family: monospace; font-size: 0.85rem;" aria-describedby="code-help">
              <span class="help-text" id="code-help">
                O nutricionista gera este código privativo para você. Válido por 48 horas.
              </span>
            </div>
            <button type="submit" class="btn-submit">
              Continuar com o convite
            </button>
          </form>
        </div>

        <!-- Destaques -->
        <div class="feature-list">
          <div class="feature-item">
            <strong>Web e celular:</strong> funciona direto no navegador, sem instalar nada.
          </div>
          <div class="feature-item">
            <strong>Seu plano:</strong> alimentos, quantidades e substituições.
          </div>
        </div>

      <?php endif; ?>

    </div>
  </main>

  <footer class="portal-footer">
    <div>&copy; <?= date('Y') ?> Clyo Nutri • Acompanhamento nutricional.</div>
    <div style="margin-top: 4px;">
      <a href="/termos" style="color: var(--neutral-gray-500, #667085); text-decoration: none;">Termos de Uso</a> •
      <a href="/privacidade" style="color: var(--neutral-gray-500, #667085); text-decoration: none;">Política de Privacidade</a>
    </div>
  </footer>

  <script nonce="<?= $nonce ?>">
    // NI2-140 (A8): abas no padrão WAI-ARIA (aria-selected, tabpanel, setas, Home e End).
    (function () {
      var tabs = Array.prototype.slice.call(document.querySelectorAll('[role="tab"]'));
      if (!tabs.length) return;
      function select(tab, focus) {
        tabs.forEach(function (t) {
          var on = t === tab;
          t.setAttribute('aria-selected', on ? 'true' : 'false');
          t.tabIndex = on ? 0 : -1;
          var panel = document.getElementById(t.getAttribute('aria-controls'));
          if (panel) panel.hidden = !on;
        });
        if (focus) tab.focus();
      }
      tabs.forEach(function (tab, i) {
        tab.addEventListener('click', function () { select(tab, false); });
        tab.addEventListener('keydown', function (e) {
          var next = null;
          if (e.key === 'ArrowRight') next = tabs[(i + 1) % tabs.length];
          else if (e.key === 'ArrowLeft') next = tabs[(i - 1 + tabs.length) % tabs.length];
          else if (e.key === 'Home') next = tabs[0];
          else if (e.key === 'End') next = tabs[tabs.length - 1];
          if (next) { e.preventDefault(); select(next, true); }
        });
      });
      document.querySelectorAll('[data-patient-first-access]').forEach(function (link) {
        link.addEventListener('click', function (e) {
          var firstTab = document.getElementById('tab-btn-first');
          if (!firstTab) return;
          e.preventDefault();
          select(firstTab, true);
        });
      });
    })();
  </script>
</body>
</html>
