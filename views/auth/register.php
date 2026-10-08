<!DOCTYPE html>
<html lang="pt-BR">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Cadastre-se — ClyoNutri</title>
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&family=Playfair+Display:wght@500;600&display=swap" rel="stylesheet">
  <link rel="stylesheet" href="/assets/css/clinical-botanical.css">
  <style>
    .auth-wrapper {
      min-height: 100vh;
      display: flex;
      align-items: center;
      justify-content: center;
      padding: var(--space-6);
      background: linear-gradient(135deg, #FAF7F2 0%, #F4F5F6 100%);
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
  </style>
</head>
<body>
  <div class="auth-wrapper">
    <div class="auth-card">
      <div class="auth-logo">
        <svg width="26" height="26" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="color: var(--brand-copper-500);"><path d="M12 2a10 10 0 1 0 10 10A10 10 0 0 0 12 2zm1 14.93V18a1 1 0 0 1-2 0v-1.07A6 6 0 0 1 7 11V6a1 1 0 0 1 1-1h8a1 1 0 0 1 1 1v5a6 6 0 0 1-4 5.93zM9 7v4a3 3 0 0 0 6 0V7z"/></svg>
        <span>Clyo</span>Nutri
      </div>

      <p class="auth-subtitle">Crie seu consultório e comece a atender com o ClyoNutri II</p>

      <?php if (!empty($error)): ?>
        <div class="alert-error">
          <?= htmlspecialchars($error) ?>
        </div>
      <?php endif; ?>

      <form method="POST" action="/cadastro">
        <input type="hidden" name="_csrf_token" value="<?= htmlspecialchars($csrfToken) ?>">

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
              <option value="CRN-6">CRN-6 (PE/AL/PB/RN/CE/PI/MA)</option>
              <option value="CRN-7">CRN-7 (PA/AM/AP/AC/RO/RR)</option>
              <option value="CRN-8">CRN-8 (PR)</option>
              <option value="CRN-9">CRN-9 (MG)</option>
              <option value="CRN-10">CRN-10 (SC)</option>
            </select>
          </div>
        </div>

        <div class="form-group">
          <label class="form-label" for="email">E-mail profissional de acesso *</label>
          <input type="email" id="email" name="email" class="form-control" placeholder="seu.email@consultorio.com.br" required value="<?= htmlspecialchars($old['email'] ?? '') ?>">
        </div>

        <div class="form-group">
          <label class="form-label" for="password">Criar senha de acesso (mínimo 8 dígitos) *</label>
          <input type="password" id="password" name="password" class="form-control" placeholder="••••••••••••" minlength="8" required>
        </div>

        <button type="submit" class="btn btn-primary" style="width: 100%; margin-top: var(--space-4);">
          <span>Criar Consultório & Ativar Onboarding</span>
          <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="5" y1="12" x2="19" y2="12"/><polyline points="12 5 19 12 12 19"/></svg>
        </button>
      </form>

      <div class="auth-footer">
        Já possui conta? <a href="/login">Fazer login</a>
      </div>
    </div>
  </div>
</body>
</html>
