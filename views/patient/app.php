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
  <title>Portal do Paciente — ClyoNutri</title>
  <link rel="stylesheet" href="/assets/css/design-system.css">
  <style>
    :root {
      /* Clinical Botanical (NI2-25) com o contraste da NI2-140 (A8): copper-600 só em texto e link, ação em verde. Os nomes --brand-* são legados e ficam só por
         compatibilidade; o valor é a paleta oficial. */
      --botanical-forest-950: #0A2A10;
      --botanical-forest-900: #0F3E17;
      --botanical-green-600: #39734A;
      --botanical-green-700: #2F6140;
      --botanical-sage-200: #D3E5D5;
      --botanical-sage-100: #E5F0E5;
      --botanical-cream-50: #FAF9F4;
      --botanical-copper-600: #B04A1B;
      --brand-navy-950: var(--botanical-forest-950);
      --brand-navy-900: var(--botanical-forest-900);
      --brand-copper-600: var(--botanical-copper-600);
      --brand-copper-500: #EF8F4F;
      --neutral-50: var(--botanical-cream-50);
      --brand-muted: #667085;
      --neutral-100: #F1F5F9;
      --neutral-200: #E2E8F0;
      --neutral-600: #475569;
      --neutral-900: var(--botanical-forest-900);
    }
    body {
      margin: 0;
      padding: 0;
      font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Helvetica, Arial, sans-serif;
      background: var(--botanical-cream-50);
      color: #1F2A22;
      min-height: 100vh;
      display: flex;
      flex-direction: column;
    }
    .portal-header {
      background: #fff;
      border-bottom: 1px solid #e2e8f0;
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
      color: #0F3E17;
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
      border: 1px solid #E3E8DF;
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
      color: #334155;
      margin-bottom: 6px;
    }
    .form-control {
      width: 100%;
      padding: 10px 14px;
      border: 1px solid #858D9C;
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
      background: #fef2f2;
      border-left: 4px solid #ef4444;
      padding: 14px 16px;
      border-radius: 6px;
      color: #991b1b;
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
      color: #475569;
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
      background: #F3F7F1;
      border: 1px solid var(--botanical-sage-200);
      padding: 12px;
      border-radius: 8px;
      font-size: 0.85rem;
      color: #475569;
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
</head>
<body>

  <header class="portal-header">
    <a href="/app" class="portal-header__brand">
      <img src="/assets/clyonutri-icon.png" alt="" width="28" height="28" style="border-radius:6px;">
      <span>ClyoNutri</span>
    </a>
    <span class="portal-header__badge">Portal do Paciente</span>
  </header>

  <main class="portal-container">
    <div class="portal-card">

      <?php if (!empty($error)): ?>
        <div class="alert-error" role="alert">
          <strong>Atenção:</strong> <?= htmlspecialchars($error) ?>
        </div>
      <?php endif; ?>

      <?php if ($step === 'first_access' && !empty($preview)): ?>
        <!-- Tela de Confirmação do Primeiro Acesso -->
        <div style="text-align: center; margin-bottom: 24px;">
          <div style="width: 48px; height: 48px; border-radius: 50%; background: var(--botanical-sage-100); color: var(--botanical-green-600); display: flex; align-items: center; justify-content: center; margin: 0 auto 12px auto; font-size: 1.5rem;" aria-hidden="true">
            <?= htmlspecialchars(mb_substr((string)($preview['patient']['name'] ?? 'P'), 0, 1)) ?>
          </div>
          <h1 style="font-size: 1.4rem; font-weight: 700; color: #0F3E17; margin: 0 0 6px 0;">
            Olá, <?= htmlspecialchars($preview['patient']['name']) ?>!
          </h1>
          <p style="color: #64748b; font-size: 0.9rem; margin: 0; line-height: 1.5;">
            Seu nutricionista <strong><?= htmlspecialchars($preview['professional_name']) ?></strong> (<?= htmlspecialchars($preview['clinic_name']) ?>) preparou seu acesso exclusivo ao aplicativo.
          </p>
        </div>

        <form method="POST" action="/app/primeiro-acesso">
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
            <label style="display: flex; gap: 8px; align-items: flex-start; font-size: 0.85rem; color: #475569; cursor: pointer;">
              <input type="checkbox" name="terms_accepted" value="1" required style="margin-top: 3px;">
              <span>Li e concordo com os <a href="/termos" target="_blank" style="color: var(--brand-copper-600); text-decoration: underline;">Termos de Uso<span class="sr-only"> (abre em nova aba)</span></a> e a <a href="/privacidade" target="_blank" style="color: var(--brand-copper-600); text-decoration: underline;">Política de Privacidade<span class="sr-only"> (abre em nova aba)</span></a> do ClyoNutri.</span>
            </label>
          </div>

          <button type="submit" class="btn-submit">
            Ativar meu acesso e entrar
          </button>
        </form>

        <div style="text-align: center; margin-top: 20px;">
          <a href="/app" style="color: #475569; font-size: 0.85rem;">Voltar ao início</a>
        </div>

      <?php elseif ($step === 'activated'): ?>
        <!-- Tela de Sucesso da Ativação -->
        <div style="text-align: center; padding: 12px 0;">
          <div style="width: 56px; height: 56px; border-radius: 50%; background: #E3F4F2; color: #0A5F63; display: flex; align-items: center; justify-content: center; margin: 0 auto 16px auto; font-size: 2rem;" aria-hidden="true">
            ✓
          </div>
          <h1 style="font-size: 1.5rem; font-weight: 700; color: #0F3E17; margin: 0 0 8px 0;">
            Acesso ativado
          </h1>
          <p style="color: #475569; font-size: 0.95rem; margin: 0 0 24px 0; line-height: 1.5;">
            Parabéns, <strong><?= htmlspecialchars($patient['name'] ?? 'Paciente') ?></strong>! Sua senha foi criada e seu perfil está pronto para uso.
          </p>

          <div style="background: #F3F7F1; border: 1px solid var(--botanical-sage-200); border-radius: 12px; padding: 20px; text-align: left; margin-bottom: 24px;">
            <h3 style="font-size: 0.9rem; font-weight: 700; color: #0F3E17; margin: 0 0 8px 0;">O que você pode fazer agora:</h3>
            <ul style="margin: 0; padding-left: 20px; font-size: 0.85rem; color: #475569; line-height: 1.6;">
              <li>Acessar seu plano alimentar e prescrições em tempo real.</li>
              <li>Registrar fotos das suas refeições no diário alimentar.</li>
              <li>Acompanhar sua meta de hidratação diária.</li>
              <li>Entrar na sala de teleconsulta com 1 clique na data agendada.</li>
            </ul>
          </div>

          <div style="display: flex; flex-direction: column; gap: 10px;">
            <a href="/app" class="btn-submit" style="display: block; text-decoration: none; text-align: center;">
              Ir para o meu portal
            </a>
            <div style="font-size: 0.8rem; color: var(--brand-muted); margin-top: 8px;">
              Dica: adicione esta página à tela de início do seu celular para abrir como aplicativo a qualquer momento.
            </div>
          </div>
        </div>

      <?php elseif ($step === 'logged_in'): ?>
        <!-- Painel Rápido do Paciente Autenticado -->
        <div style="text-align: center; margin-bottom: 24px;">
          <h1 style="font-size: 1.4rem; font-weight: 700; color: #0F3E17; margin: 0 0 6px 0;">
            Área do Paciente
          </h1>
          <p style="color: #64748b; font-size: 0.9rem; margin: 0;">
            Acesso conectado ao ClyoNutri
          </p>
        </div>

        <div class="feature-list" style="margin-bottom: 24px;">
          <div class="feature-item">
            <strong style="color: #0F3E17; display: block; margin-bottom: 4px;">Plano alimentar</strong>
            Consulte suas refeições prescritas, gramas e opções de substituição.
          </div>
          <div class="feature-item">
            <strong style="color: #0F3E17; display: block; margin-bottom: 4px;">Hidratação e diário</strong>
            Registre água e acompanhe seu consumo ao longo do dia.
          </div>
          <div class="feature-item">
            <strong style="color: #0F3E17; display: block; margin-bottom: 4px;">Teleconsultas</strong>
            Acesse a sala virtual sem instalar nada.
          </div>
          <div class="feature-item">
            <strong style="color: #0F3E17; display: block; margin-bottom: 4px;">Chat com o nutricionista</strong>
            Envie dúvidas e compartilhe sua rotina.
          </div>
        </div>

        <div style="text-align: center;">
          <a href="/app" style="color: var(--brand-copper-600); font-size: 0.85rem; font-weight: 600;">Sessão ativa · Atualizar página</a>
        </div>

      <?php else: ?>
        <!-- Tela Inicial: Abas Primeiro Acesso e Login -->
        <div style="text-align: center; margin-bottom: 20px;">
          <h1 style="font-size: 1.35rem; font-weight: 700; color: #0F3E17; margin: 0 0 6px 0;">
            Acompanhe seu plano no ClyoNutri
          </h1>
          <p style="color: #64748b; font-size: 0.875rem; margin: 0;">
            Acesse seu plano alimentar, diário e consultas de qualquer dispositivo.
          </p>
        </div>

        <div class="tabs-nav" role="tablist" aria-label="Como você quer entrar">
          <button type="button" class="tab-btn" role="tab" id="tab-btn-first" aria-controls="tab-first" aria-selected="true">
            Primeiro acesso
          </button>
          <button type="button" class="tab-btn" role="tab" id="tab-btn-login" aria-controls="tab-login" aria-selected="false" tabindex="-1">
            Já tenho cadastro
          </button>
        </div>

        <!-- Aba 1: Primeiro Acesso com Código -->
        <div id="tab-first" class="tab-content" role="tabpanel" aria-labelledby="tab-btn-first">
          <form method="GET" action="/app">
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

        <!-- Aba 2: Login com Credenciais -->
        <div id="tab-login" class="tab-content" role="tabpanel" aria-labelledby="tab-btn-login" hidden>
          <form method="POST" action="/app/login">
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
          <div style="text-align: center; margin-top: 14px;">
            <a href="/recuperar-senha" style="font-size: 0.8rem; color: var(--brand-copper-600);">
              Esqueci minha senha
            </a>
          </div>
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
    <div>&copy; <?= date('Y') ?> ClyoNutri • Acompanhamento nutricional.</div>
    <div style="margin-top: 4px;">
      <a href="/termos" style="color: #64748b; text-decoration: none;">Termos de Uso</a> •
      <a href="/privacidade" style="color: #64748b; text-decoration: none;">Política de Privacidade</a>
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
    })();
  </script>
</body>
</html>
