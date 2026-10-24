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
      --brand-navy-950: #0a1120;
      --brand-navy-900: #0f172a;
      --brand-copper-600: #c2410c;
      --brand-copper-500: #ea580c;
      --neutral-50: #f8fafc;
      --neutral-100: #f1f5f9;
      --neutral-200: #e2e8f0;
      --neutral-600: #475569;
      --neutral-900: #0f172a;
    }
    body {
      margin: 0;
      padding: 0;
      font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Helvetica, Arial, sans-serif;
      background: #f8fafc;
      color: #1e293b;
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
      color: #0f172a;
      font-weight: 700;
      font-size: 1.1rem;
    }
    .portal-header__badge {
      font-size: 0.75rem;
      background: #fed7aa;
      color: #9a3412;
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
      border: 1px solid #e2e8f0;
      border-radius: 16px;
      padding: 32px;
      box-shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.05), 0 2px 4px -2px rgba(0, 0, 0, 0.05);
    }
    .portal-footer {
      text-align: center;
      padding: 24px 16px;
      color: #94a3b8;
      font-size: 0.8rem;
      border-top: 1px solid #f1f5f9;
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
      border: 1px solid #cbd5e1;
      border-radius: 8px;
      font-size: 0.95rem;
      box-sizing: border-box;
      transition: border-color 0.15s ease;
    }
    .form-control:focus {
      outline: none;
      border-color: #c2410c;
      box-shadow: 0 0 0 3px rgba(194, 65, 12, 0.15);
    }
    .btn-submit {
      width: 100%;
      padding: 12px;
      background: #ea580c;
      color: #fff;
      border: none;
      border-radius: 8px;
      font-size: 1rem;
      font-weight: 600;
      cursor: pointer;
      transition: background 0.15s ease;
    }
    .btn-submit:hover {
      background: #c2410c;
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
      border-bottom: 2px solid #e2e8f0;
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
      color: #64748b;
      cursor: pointer;
      border-bottom: 2px solid transparent;
      margin-bottom: -2px;
    }
    .tab-btn.active {
      color: #c2410c;
      border-bottom-color: #c2410c;
    }
    .feature-list {
      display: grid;
      grid-template-columns: repeat(2, 1fr);
      gap: 12px;
      margin-top: 24px;
    }
    .feature-item {
      background: #f8fafc;
      border: 1px solid #e2e8f0;
      padding: 12px;
      border-radius: 8px;
      font-size: 0.85rem;
      color: #475569;
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
      <img src="/assets/clyonutri-icon.png" alt="ClyoNutri" width="28" height="28" style="border-radius:6px;">
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
          <div style="width: 48px; height: 48px; border-radius: 50%; background: #ffedd5; color: #c2410c; display: flex; align-items: center; justify-content: center; margin: 0 auto 12px auto; font-size: 1.5rem;">
            👋
          </div>
          <h1 style="font-size: 1.4rem; font-weight: 700; color: #0f172a; margin: 0 0 6px 0;">
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
            <label class="form-label" for="fa-name">Nome Completo</label>
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
            <label class="form-label" for="fa-password">Criar uma Senha de Acesso</label>
            <input class="form-control" id="fa-password" type="password" name="password" required minlength="8" maxlength="72" placeholder="Mínimo de 8 caracteres">
            <span style="font-size: 0.75rem; color: #94a3b8; display: block; margin-top: 4px;">Use no mínimo 8 caracteres (letras, números ou símbolos).</span>
          </div>

          <div class="form-group">
            <label class="form-label" for="fa-confirm">Confirmar Senha</label>
            <input class="form-control" id="fa-confirm" type="password" name="password_confirmation" required minlength="8" maxlength="72" placeholder="Repita a senha digitada">
          </div>

          <div style="margin-bottom: 24px;">
            <label style="display: flex; gap: 8px; align-items: flex-start; font-size: 0.85rem; color: #475569; cursor: pointer;">
              <input type="checkbox" name="terms_accepted" value="1" required style="margin-top: 3px;">
              <span>Li e concordo com os <a href="/termos" target="_blank" style="color:#c2410c;">Termos de Uso</a> e a <a href="/privacidade" target="_blank" style="color:#c2410c;">Política de Privacidade</a> do ClyoNutri.</span>
            </label>
          </div>

          <button type="submit" class="btn-submit">
            Ativar Meu Acesso e Entrar
          </button>
        </form>

        <div style="text-align: center; margin-top: 20px;">
          <a href="/app" style="color: #64748b; font-size: 0.85rem; text-decoration: none;">← Voltar ao início</a>
        </div>

      <?php elseif ($step === 'activated'): ?>
        <!-- Tela de Sucesso da Ativação -->
        <div style="text-align: center; padding: 12px 0;">
          <div style="width: 56px; height: 56px; border-radius: 50%; background: #dcfce7; color: #166534; display: flex; align-items: center; justify-content: center; margin: 0 auto 16px auto; font-size: 2rem;">
            ✓
          </div>
          <h1 style="font-size: 1.5rem; font-weight: 700; color: #0f172a; margin: 0 0 8px 0;">
            Acesso Ativado com Sucesso!
          </h1>
          <p style="color: #475569; font-size: 0.95rem; margin: 0 0 24px 0; line-height: 1.5;">
            Parabéns, <strong><?= htmlspecialchars($patient['name'] ?? 'Paciente') ?></strong>! Sua senha foi criada e seu perfil está pronto para uso.
          </p>

          <div style="background: #f8fafc; border: 1px solid #e2e8f0; border-radius: 12px; padding: 20px; text-align: left; margin-bottom: 24px;">
            <h3 style="font-size: 0.9rem; font-weight: 700; color: #0f172a; margin: 0 0 8px 0;">O que você pode fazer agora:</h3>
            <ul style="margin: 0; padding-left: 20px; font-size: 0.85rem; color: #475569; line-height: 1.6;">
              <li>Acessar seu plano alimentar e prescrições em tempo real.</li>
              <li>Registrar fotos das suas refeições no diário alimentar.</li>
              <li>Acompanhar sua meta de hidratação diária.</li>
              <li>Entrar na sala de teleconsulta com 1 clique na data agendada.</li>
            </ul>
          </div>

          <div style="display: flex; flex-direction: column; gap: 10px;">
            <a href="/app" class="btn-submit" style="display: block; text-decoration: none; text-align: center;">
              Ir para o Meu Portal
            </a>
            <div style="font-size: 0.8rem; color: #94a3b8; margin-top: 8px;">
              Dica: adicione esta página à tela de início do seu celular para abrir como aplicativo a qualquer momento.
            </div>
          </div>
        </div>

      <?php elseif ($step === 'logged_in'): ?>
        <!-- Painel Rápido do Paciente Autenticado -->
        <div style="text-align: center; margin-bottom: 24px;">
          <h1 style="font-size: 1.4rem; font-weight: 700; color: #0f172a; margin: 0 0 6px 0;">
            Área do Paciente
          </h1>
          <p style="color: #64748b; font-size: 0.9rem; margin: 0;">
            Acesso conectado ao ClyoNutri
          </p>
        </div>

        <div class="feature-list" style="margin-bottom: 24px;">
          <div class="feature-item">
            <strong style="color: #0f172a; display: block; margin-bottom: 4px;">🥗 Plano Alimentar</strong>
            Consulte suas refeições prescritas, gramas e opções de substituição.
          </div>
          <div class="feature-item">
            <strong style="color: #0f172a; display: block; margin-bottom: 4px;">💧 Hidratação & Diário</strong>
            Registre água e acompanhe seu consumo ao longo do dia.
          </div>
          <div class="feature-item">
            <strong style="color: #0f172a; display: block; margin-bottom: 4px;">📹 Teleconsultas</strong>
            Acesse a sala virtual sem instalar nada.
          </div>
          <div class="feature-item">
            <strong style="color: #0f172a; display: block; margin-bottom: 4px;">💬 Chat com Nutricionista</strong>
            Envie dúvidas e compartilhe sua rotina.
          </div>
        </div>

        <div style="text-align: center;">
          <a href="/app" style="color: #c2410c; font-size: 0.85rem; text-decoration: none; font-weight: 600;">Sessão ativa • Atualizar página</a>
        </div>

      <?php else: ?>
        <!-- Tela Inicial: Abas Primeiro Acesso e Login -->
        <div style="text-align: center; margin-bottom: 20px;">
          <h1 style="font-size: 1.35rem; font-weight: 700; color: #0f172a; margin: 0 0 6px 0;">
            Acompanhe seu Plano no ClyoNutri
          </h1>
          <p style="color: #64748b; font-size: 0.875rem; margin: 0;">
            Acesse seu plano alimentar, diário e consultas de qualquer dispositivo.
          </p>
        </div>

        <div class="tabs-nav" role="tablist">
          <button type="button" class="tab-btn active" id="tab-btn-first" data-target="tab-first">
            Primeiro Acesso
          </button>
          <button type="button" class="tab-btn" id="tab-btn-login" data-target="tab-login">
            Já Tenho Cadastro
          </button>
        </div>

        <!-- Aba 1: Primeiro Acesso com Código -->
        <div id="tab-first" class="tab-content">
          <form method="GET" action="/app">
            <div class="form-group">
              <label class="form-label" for="code-input">Código do Convite</label>
              <input class="form-control" id="code-input" type="text" name="code" required placeholder="Cole o código recebido do seu nutricionista" value="<?= htmlspecialchars((string)$codeVal) ?>" style="font-family: monospace; font-size: 0.85rem;">
              <span style="font-size: 0.75rem; color: #94a3b8; display: block; margin-top: 4px;">
                O nutricionista gera este código privativo para você. Válido por 48 horas.
              </span>
            </div>
            <button type="submit" class="btn-submit">
              Continuar com Convite
            </button>
          </form>
        </div>

        <!-- Aba 2: Login com Credenciais -->
        <div id="tab-login" class="tab-content" style="display: none;">
          <form method="POST" action="/app/login">
            <input type="hidden" name="_csrf_token" value="<?= htmlspecialchars(\ClyoNutri\Core\Security\Csrf::token()) ?>">
            <div class="form-group">
              <label class="form-label" for="login-input">E-mail, Celular ou CPF</label>
              <input class="form-control" id="login-input" type="text" name="login" required placeholder="Digite seus dados de acesso">
            </div>
            <div class="form-group">
              <label class="form-label" for="login-pass">Sua Senha</label>
              <input class="form-control" id="login-pass" type="password" name="password" required placeholder="Sua senha cadastrada">
            </div>
            <button type="submit" class="btn-submit">
              Entrar no Portal
            </button>
          </form>
          <div style="text-align: center; margin-top: 14px;">
            <a href="/recuperar-senha" style="font-size: 0.8rem; color: #c2410c; text-decoration: none;">
              Esqueci minha senha
            </a>
          </div>
        </div>

        <!-- Destaques -->
        <div class="feature-list">
          <div class="feature-item">
            📱 <strong>Web & Celular:</strong> Funciona direto no navegador, sem ocupar espaço.
          </div>
          <div class="feature-item">
            🥗 <strong>Plano Dinâmico:</strong> Alimentos, quantidades em gramas e substituições.
          </div>
        </div>

      <?php endif; ?>

    </div>
  </main>

  <footer class="portal-footer">
    <div>&copy; <?= date('Y') ?> ClyoNutri • A plataforma de alta performance para nutrição clínica.</div>
    <div style="margin-top: 4px;">
      <a href="/termos" style="color: #64748b; text-decoration: none;">Termos de Uso</a> •
      <a href="/privacidade" style="color: #64748b; text-decoration: none;">Política de Privacidade</a>
    </div>
  </footer>

  <script nonce="<?= $nonce ?>">
    document.querySelectorAll('.tab-btn').forEach(function(btn) {
      btn.addEventListener('click', function() {
        document.querySelectorAll('.tab-btn').forEach(function(b) { b.classList.remove('active'); });
        document.querySelectorAll('.tab-content').forEach(function(c) { c.style.display = 'none'; });
        btn.classList.add('active');
        var targetId = btn.getAttribute('data-target');
        var targetEl = document.getElementById(targetId);
        if (targetEl) targetEl.style.display = 'block';
      });
    });
  </script>
</body>
</html>
