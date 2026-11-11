<!DOCTYPE html>
<html lang="pt-BR">
<head>
  <link rel="icon" type="image/svg+xml" href="/assets/clyonutri-icon.svg?v=botanical-v54-9">
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Cadastre-se · Clyo Nutri</title>
  <link rel="preload" href="/assets/fonts/inter-normal-latin.woff2" as="font" type="font/woff2" crossorigin>
  <link rel="stylesheet" href="/assets/css/fonts.css?v=botanical-v54-9">
  <link rel="stylesheet" href="/assets/css/clinical-botanical.css?v=botanical-v54-9">
  <style>
    .auth-wrapper {
      min-height: 100vh;
      display: flex;
      align-items: center;
      justify-content: center;
      padding: var(--space-6);
      background: linear-gradient(135deg, var(--botanical-cream-50, #FAF9F4) 0%, var(--botanical-cream-50, #FAF9F4) 100%);
    }
    .auth-card {
      width: 100%;
      max-width: 520px;
      background: var(--neutral-white);
      border-radius: var(--radius-card);
      border: var(--border-default);
      box-shadow: var(--shadow-md);
      padding: var(--space-8);
    }
    .auth-logo {
      display: flex;
      align-items: center;
      justify-content: center;
      gap: var(--space-2);
      margin-bottom: var(--space-4);
      font-size: 1.6rem;
      font-weight: 700;
      color: var(--brand-navy-900);
    }
    .auth-logo span {
      color: var(--brand-copper-500);
    }
    .auth-subtitle {
      text-align: center;
      color: var(--neutral-gray-500);
      font-size: 0.95rem;
      margin-bottom: var(--space-6);
    }
    .alert-error {
      background-color: var(--semantic-error-light);
      color: var(--semantic-error);
      padding: var(--space-3) var(--space-4);
      border-radius: var(--radius-input);
      font-size: 0.9rem;
      margin-bottom: var(--space-5);
      border: 1px solid rgba(180, 35, 24, 0.2);
    }
    .auth-footer {
      margin-top: var(--space-6);
      text-align: center;
      font-size: 0.875rem;
      color: var(--neutral-gray-500);
    }
    .auth-footer a {
      color: var(--brand-copper-600);
      text-decoration: none;
      font-weight: 600;
    }
    .auth-footer a:hover {
      text-decoration: underline;
    }
  
    .password-field { position: relative; }
    .password-field input { padding-right: 88px; }
    .password-toggle { position: absolute; right: 6px; top: 50%; transform: translateY(-50%); min-height: 36px; min-width: 72px; border: 1px solid var(--border-input, #828B9B); background: #fff; border-radius: 6px; font-size: 0.8rem; font-weight: 600; cursor: pointer; color: var(--brand-navy-900, #0F3E17); }
    .password-toggle:focus-visible { outline: 3px solid var(--brand-copper-600, #B04A1B); outline-offset: 2px; }
    .sr-only { position: absolute; width: 1px; height: 1px; padding: 0; margin: -1px; overflow: hidden; clip: rect(0,0,0,0); white-space: nowrap; border: 0; }
  </style>
<link rel="stylesheet" href="/assets/css/operational-ui.css?v=v54-30">
</head>
<body>
  <div class="auth-wrapper">
    <div class="auth-card">
      <div class="auth-logo">
        <img src="/assets/clyonutri-logo-light.svg?v=botanical-v54-9" alt="Clyo Nutri" width="260" height="64" style="display: block; width: 100%; max-width: 260px; height: auto;">
      </div>

      <p class="auth-subtitle">Crie seu consultório no Clyo Nutri</p>

      <?php if (!empty($error)): ?>
        <div class="alert-error">
          <?= htmlspecialchars($error) ?>
        </div>
      <?php endif; ?>

      <?php if (!empty($selectedPlan)): ?>
        <div class="alert-info" style="background-color: var(--botanical-cream-100, #F3F1E7); color: var(--botanical-forest-900, #0F3E17); padding: var(--space-3) var(--space-4); border-radius: var(--radius-input); font-size: 0.9rem; margin-bottom: var(--space-5); border: 1px solid rgba(15, 62, 23, 0.15);">
          Plano selecionado: <strong><?= htmlspecialchars($selectedPlan) ?></strong> · 14 dias de teste gratuito
        </div>
      <?php endif; ?>

      <form method="POST" action="/cadastro">
        <input type="hidden" name="_csrf_token" value="<?= htmlspecialchars($csrfToken) ?>">
        <?php if (!empty($selectedPlan)): ?>
          <input type="hidden" name="plano" value="<?= htmlspecialchars($selectedPlan) ?>">
        <?php endif; ?>
        <?php /* NI2-479: campo-armadilha. Fica fora da tela e da ordem do Tab; gente não preenche, robô de formulário sim.
                 Nome sem significado para o preenchimento automático do navegador não confundir com um campo real. */ ?>
        <div class="cn-extra" aria-hidden="true" style="position: absolute; left: -10000px; top: auto; width: 1px; height: 1px; overflow: hidden;">
          <label for="cn_campo_extra">Deixe este campo em branco</label>
          <input type="text" id="cn_campo_extra" name="<?= \ClyoNutri\Application\Controllers\AuthController::REGISTER_TRAP_FIELD ?>" tabindex="-1" autocomplete="off" value="">
        </div>

        <div class="form-group">
          <label class="form-label" for="name">Nome completo do nutricionista *</label>
          <input type="text" id="name" name="name" class="form-control" placeholder="Dra. Juliana Mendes" required autofocus value="<?= htmlspecialchars($old['name'] ?? '') ?>">
        </div>

        <div class="form-group">
          <label class="form-label" for="clinic_name">Nome do seu consultório / clínica *</label>
          <input type="text" id="clinic_name" name="clinic_name" class="form-control" placeholder="Clínica Nutrição Integrada" required value="<?= htmlspecialchars($old['clinic_name'] ?? '') ?>">
        </div>

        <div style="display: grid; grid-template-columns: 2fr 1fr; gap: var(--space-4);">
          <div class="form-group">
            <label class="form-label" for="crn">Número do CRN *</label>
            <input type="text" id="crn" name="crn" class="form-control" placeholder="12345" required value="<?= htmlspecialchars($old['crn'] ?? '') ?>">
          </div>
          <div class="form-group">
            <label class="form-label" for="crn_region">Região *</label>
            <select id="crn_region" name="crn_region" class="form-control" required>
              <option value="CRN-3" selected>CRN-3 (SP/MS)</option>
              <option value="CRN-1">CRN-1 (DF/GO/MT/TO)</option>
              <option value="CRN-2">CRN-2 (RS)</option>
              <option value="CRN-4">CRN-4 (RJ/ES)</option>
              <option value="CRN-5">CRN-5 (BA/SE)</option>
              <option value="CRN-6">CRN-6 (PE/AL/PB/RN)</option>
              <option value="CRN-7">CRN-7 (PA/AM/AP/AC/RO/RR)</option>
              <option value="CRN-8">CRN-8 (PR)</option>
              <option value="CRN-9">CRN-9 (MG)</option>
              <option value="CRN-10">CRN-10 (SC)</option>
              <option value="CRN-11">CRN-11 (CE/MA/PI)</option>
            </select>
          </div>
        </div>

        <div class="form-group">
          <label class="form-label" for="email">E-mail profissional de acesso *</label>
          <input type="email" id="email" name="email" class="form-control" placeholder="seu.email@consultorio.com.br" autocomplete="username" inputmode="email" autocapitalize="none" spellcheck="false" required value="<?= htmlspecialchars($old['email'] ?? '') ?>">
        </div>

        <div class="form-group">
          <label class="form-label" for="password">Criar senha de acesso (mínimo de 8 caracteres) *</label>
          <input type="password" id="password" name="password" class="form-control" placeholder="••••••••••••" minlength="8" autocomplete="new-password" data-password-toggle required>
        </div>

        <div class="form-group" style="margin-top: var(--space-4);">
          <label for="terms_accepted" class="tap-target" style="display: flex; align-items: flex-start; justify-content: flex-start; gap: 12px; min-height: 44px; font-size: 0.875rem; color: var(--neutral-gray-700); cursor: pointer; padding: 4px 0;">
            <input type="checkbox" id="terms_accepted" name="terms_accepted" value="1" required style="width: 20px; height: 20px; min-width: 20px; min-height: 20px; margin-top: 2px; accent-color: var(--botanical-green-600); cursor: pointer;">
            <span>
              Li e concordo com os <a href="/termos" target="_blank" rel="noopener" style="color: var(--brand-copper-600); text-decoration: underline;">Termos de Uso<span class="sr-only"> (abre em nova aba)</span></a> (versão <?= htmlspecialchars(\ClyoNutri\Domain\Legal\LegalDocuments::TERMS_VERSION) ?>) e a <a href="/privacidade" target="_blank" rel="noopener" style="color: var(--brand-copper-600); text-decoration: underline;">Política de Privacidade<span class="sr-only"> (abre em nova aba)</span></a> (versão <?= htmlspecialchars(\ClyoNutri\Domain\Legal\LegalDocuments::PRIVACY_VERSION) ?>).
            </span>
          </label>
          <?php /* NI2-449: versões que a pessoa viu; o servidor recusa se mudaram antes do envio. */ ?>
          <input type="hidden" name="terms_version" value="<?= htmlspecialchars(\ClyoNutri\Domain\Legal\LegalDocuments::TERMS_VERSION) ?>">
          <input type="hidden" name="privacy_version" value="<?= htmlspecialchars(\ClyoNutri\Domain\Legal\LegalDocuments::PRIVACY_VERSION) ?>">
        </div>

        <button type="submit" class="btn btn-primary" style="width: 100%; margin-top: var(--space-4);">
          <span>Criar consultório e ativar onboarding</span>
          <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="5" y1="12" x2="19" y2="12"/><polyline points="12 5 19 12 12 19"/></svg>
        </button>
      </form>

      <div class="auth-footer">
        Já possui conta? <a href="/login">Fazer login</a>
      </div>
    </div>
  </div>
  <script src="/assets/js/validacao-ptbr.js" defer></script>
<script src="/assets/js/password-toggle.js" defer></script>
</body>
</html>
