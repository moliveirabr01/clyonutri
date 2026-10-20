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

$whatsappMsg = urlencode(
    "Olá, {$patientName}! Seu nutricionista preparou seu acesso exclusivo ao ClyoNutri. " .
    "Acesse o link a seguir para ativar seu primeiro acesso e criar sua senha:\n\n" .
    "{$directLink}\n\n" .
    "Ou acesse {$portalBaseUrl} e use o seu código de convite:\n{$code}\n\n" .
    "(Link válido por 48 horas)"
);
$whatsappUrl = "https://api.whatsapp.com/send?text={$whatsappMsg}" . (!empty($patient['phone']) ? '&phone=' . preg_replace('/\D/', '', $patient['phone']) : '');
?>

<div style="max-width: 840px; margin: 0 auto; padding-bottom: 40px;">
  <div style="margin-bottom: var(--space-6); display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 12px;">
    <div>
      <div style="font-size: 0.85rem; color: var(--neutral-gray-500); margin-bottom: 4px;">
        <a href="/pacientes/<?= (int)$patientId ?>" style="color: var(--brand-copper-600); text-decoration: none;">← Voltar ao perfil de <?= htmlspecialchars($patientName) ?></a>
      </div>
      <h1 style="font-size: 1.75rem; font-weight: 700; color: var(--brand-navy-900); margin: 0;">Primeiro Acesso ao App do Paciente</h1>
      <p style="color: var(--neutral-gray-500); margin-top: 4px; font-size: 0.95rem;">
        Gere e compartilhe as credenciais de primeiro acesso para <?= htmlspecialchars($patientName) ?> acompanhar seu plano alimentar e diário.
      </p>
    </div>
  </div>

  <?php if (!empty($error)): ?>
    <div class="card" style="padding: 24px; border-left: 4px solid #ef4444; background: #fff; border-radius: 12px; box-shadow: 0 4px 6px -1px rgba(0,0,0,0.05); margin-bottom: 24px;">
      <div style="display: flex; gap: 16px; align-items: flex-start;">
        <div style="width: 40px; height: 40px; border-radius: 50%; background: #fee2e2; display: flex; align-items: center; justify-content: center; color: #b91c1c; font-size: 1.25rem; flex-shrink: 0;">
          ✕
        </div>
        <div>
          <h2 style="font-size: 1.1rem; font-weight: 600; color: #991b1b; margin: 0 0 8px 0;">Não foi possível gerar o convite</h2>
          <p style="color: #4b5563; margin: 0 0 16px 0; font-size: 0.95rem; line-height: 1.5;">
            <?= htmlspecialchars($error) ?>
          </p>
          <div style="display: flex; gap: 10px; flex-wrap: wrap;">
            <a href="/pacientes/<?= (int)$patientId ?>" class="btn btn-secondary" style="padding: 8px 16px; font-size: 0.875rem;">Voltar ao perfil do paciente</a>
            <a href="/app" target="_blank" class="btn btn-outline" style="padding: 8px 16px; font-size: 0.875rem;">Acessar Portal do Paciente</a>
          </div>
        </div>
      </div>
    </div>
  <?php else: ?>

    <!-- Resumo do Paciente -->
    <div class="card" style="padding: 20px 24px; background: #f8fafc; border: 1px solid #e2e8f0; border-radius: 12px; margin-bottom: 24px;">
      <div style="display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 16px;">
        <div>
          <span style="font-size: 0.75rem; font-weight: 700; text-transform: uppercase; color: #64748b; letter-spacing: 0.05em;">Paciente</span>
          <div style="font-size: 1.15rem; font-weight: 700; color: var(--brand-navy-900);"><?= htmlspecialchars($patientName) ?></div>
          <div style="font-size: 0.85rem; color: #64748b; margin-top: 2px;">
            <?= !empty($patient['email']) ? htmlspecialchars($patient['email']) : 'Sem e-mail cadastrado' ?>
            <?= !empty($patient['phone']) ? ' • ' . htmlspecialchars($patient['phone']) : '' ?>
          </div>
        </div>
        <div style="text-align: right;">
          <span style="display: inline-block; padding: 4px 10px; border-radius: 9999px; background: #dcfce7; color: #166534; font-size: 0.8rem; font-weight: 600;">
            ✓ Convite Ativo (Válido por 48 horas)
          </span>
          <div style="font-size: 0.75rem; color: #64748b; margin-top: 4px;">
            Expira em: <?= htmlspecialchars($invitation['expires_at'] ?? '48h') ?>
          </div>
        </div>
      </div>
    </div>

    <!-- Grid de Compartilhamento -->
    <div style="display: grid; grid-template-columns: 1fr 240px; gap: 24px; margin-bottom: 24px; align-items: start;" class="invitation-grid">
      <!-- Coluna Principal: Links e Botões -->
      <div style="display: flex; flex-direction: column; gap: 16px;">

        <!-- Link Direto (Recomendado) -->
        <div class="card" style="padding: 20px; background: #fff; border: 1px solid #cbd5e1; border-radius: 12px; box-shadow: 0 2px 4px rgba(0,0,0,0.02);">
          <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 8px;">
            <label for="direct-link-input" style="font-weight: 600; font-size: 0.9rem; color: var(--brand-navy-900);">
              🔗 Link Direto de Ativação (Recomendado)
            </label>
            <span style="font-size: 0.75rem; background: #e0f2fe; color: #0369a1; padding: 2px 8px; border-radius: 4px; font-weight: 600;">1 clique</span>
          </div>
          <p style="font-size: 0.825rem; color: #64748b; margin: 0 0 10px 0;">
            O paciente clica no link e vai direto para a tela de confirmação e cadastro de senha, com seus dados já carregados.
          </p>
          <div style="display: flex; gap: 8px; align-items: center;">
            <input id="direct-link-input" type="text" readonly value="<?= htmlspecialchars($directLink) ?>" style="flex: 1; padding: 10px 12px; font-family: monospace; font-size: 0.85rem; border: 1px solid #cbd5e1; border-radius: 6px; background: #f8fafc; color: #334155;" data-select-all>
            <button type="button" class="btn btn-primary" id="btn-copy-link" style="padding: 10px 16px; font-size: 0.85rem; white-space: nowrap;">
              📋 Copiar Link
            </button>
          </div>
          <div style="display: flex; gap: 10px; margin-top: 12px; flex-wrap: wrap;">
            <a href="<?= htmlspecialchars($whatsappUrl) ?>" target="_blank" rel="noopener noreferrer" class="btn" style="background: #25D366; color: #fff; border: none; font-size: 0.85rem; padding: 8px 14px; text-decoration: none; border-radius: 6px; display: inline-flex; align-items: center; gap: 6px; font-weight: 600;">
              <span>💬 Enviar via WhatsApp</span>
            </a>
            <a href="<?= htmlspecialchars($directLink) ?>" target="_blank" rel="noopener noreferrer" class="btn btn-secondary" style="font-size: 0.85rem; padding: 8px 14px; text-decoration: none;">
              ↗ Abrir e Conferir Tela
            </a>
          </div>
        </div>

        <!-- Código de Convite Manual -->
        <div class="card" style="padding: 20px; background: #fff; border: 1px solid #cbd5e1; border-radius: 12px; box-shadow: 0 2px 4px rgba(0,0,0,0.02);">
          <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 8px;">
            <label for="invitation-code" style="font-weight: 600; font-size: 0.9rem; color: var(--brand-navy-900);">
              🔑 Código de Convite Individual
            </label>
            <span style="font-size: 0.75rem; background: #f1f5f9; color: #475569; padding: 2px 8px; border-radius: 4px; font-weight: 600;">Manual</span>
          </div>
          <p style="font-size: 0.825rem; color: #64748b; margin: 0 0 10px 0;">
            Caso o paciente acerte pelo portal geral <a href="/app" target="_blank" style="color: var(--brand-copper-600);">clyonutri.com/app</a> ou pelo aplicativo instalado:
          </p>
          <div style="display: flex; gap: 8px; align-items: center;">
            <input id="invitation-code" type="text" readonly value="<?= htmlspecialchars($code) ?>" style="flex: 1; padding: 10px 12px; font-family: monospace; font-size: 0.85rem; border: 1px solid #cbd5e1; border-radius: 6px; background: #f8fafc; color: #334155; letter-spacing: 0.05em;" data-select-all>
            <button type="button" class="btn btn-secondary" id="btn-copy-code" style="padding: 10px 16px; font-size: 0.85rem; white-space: nowrap;">
              📋 Copiar Código
            </button>
          </div>
        </div>

      </div>

      <!-- Coluna Lateral: QR Code Presencial -->
      <div class="card" style="padding: 20px; background: #fff; border: 1px solid #cbd5e1; border-radius: 12px; text-align: center; box-shadow: 0 2px 4px rgba(0,0,0,0.02);">
        <div style="font-weight: 600; font-size: 0.875rem; color: var(--brand-navy-900); margin-bottom: 4px;">
          QR Code Presencial
        </div>
        <p style="font-size: 0.75rem; color: #64748b; margin: 0 0 12px 0;">
          Aponte a câmera do celular do paciente para ativar agora
        </p>
        <div id="app-qrcode" style="display: flex; justify-content: center; align-items: center; min-height: 160px; margin: 0 auto; background: #fff; padding: 8px; border-radius: 8px; border: 1px solid #f1f5f9;">
          <!-- Renderizado via qrcode.min.js -->
        </div>
        <div style="font-size: 0.75rem; color: #94a3b8; margin-top: 10px;">
          Escaneie para abrir o portal web ou baixar o app
        </div>
      </div>
    </div>

    <!-- Instruções para o Nutricionista e Paciente -->
    <div class="card" style="padding: 24px; background: #f8fafc; border: 1px solid #e2e8f0; border-radius: 12px; margin-bottom: 24px;">
      <h3 style="font-size: 1rem; font-weight: 600; color: var(--brand-navy-900); margin: 0 0 12px 0;">
        Como funciona o Primeiro Acesso do Paciente?
      </h3>
      <ol style="margin: 0; padding-left: 20px; color: #475569; font-size: 0.875rem; line-height: 1.6;">
        <li style="margin-bottom: 6px;">
          <strong>Envie o link ou código ao paciente:</strong> Utilize o botão de WhatsApp acima ou copie o link direto.
        </li>
        <li style="margin-bottom: 6px;">
          <strong>O paciente abre o portal ou app:</strong> O link direciona para o portal seguro da clínica em qualquer dispositivo (navegador de celular, tablet ou computador).
        </li>
        <li style="margin-bottom: 6px;">
          <strong>Confirmação de identidade:</strong> O paciente confere seu nome, telefone e e-mail e cadastra uma senha de 8 a 72 caracteres.
        </li>
        <li style="margin-bottom: 0;">
          <strong>Acesso imediato:</strong> Ao concluir, o paciente já visualiza o plano alimentar, diário de refeições, registro de água e sala de teleconsulta.
        </li>
      </ol>
      <div style="margin-top: 16px; padding-top: 16px; border-top: 1px solid #e2e8f0; font-size: 0.8rem; color: #64748b;">
        🔒 <strong>Segurança LGPD:</strong> O convite é de uso único e privado. Gerar outro convite invalida este anterior automaticamente.
      </div>
    </div>

    <!-- Ações Inferiores -->
    <div style="display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 12px;">
      <a class="btn btn-secondary" href="/pacientes/<?= (int)$patientId ?>">
        ← Concluir e Voltar ao Perfil
      </a>
      <form method="post" action="/pacientes/<?= (int)$patientId ?>/convite-app" style="margin:0" data-confirm="Gerar um novo código invalidará o convite anterior. Deseja continuar?">
        <input type="hidden" name="_csrf_token" value="<?= htmlspecialchars(\ClyoNutri\Core\Security\Csrf::token()) ?>">
        <button class="btn btn-outline" type="submit" style="font-size: 0.85rem;">
          🔄 Gerar Novo Convite
        </button>
      </form>
    </div>

  <?php endif; ?>
</div>

<style>
@media (max-width: 680px) {
  .invitation-grid {
    grid-template-columns: 1fr !important;
  }
}
</style>

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
      colorDark: "#0f172a",
      colorLight: "#ffffff",
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
    var originalBg = btn.style.background;
    btn.textContent = '✓ Copiado!';
    btn.style.background = '#10b981';
    btn.style.color = '#fff';
    setTimeout(function() {
      btn.textContent = originalText;
      btn.style.background = originalBg;
      btn.style.color = '';
    }, 2000);
  }

  setupCopyButton('btn-copy-link', 'direct-link-input', '📋 Copiar Link');
  setupCopyButton('btn-copy-code', 'invitation-code', '📋 Copiar Código');
})();
</script>
