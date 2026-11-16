<!DOCTYPE html>
<html lang="pt-BR">
<head>
  <link rel="icon" type="image/svg+xml" href="/assets/clyonutri-icon.svg?v=botanical-v54-9">
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title><?= htmlspecialchars($pageTitle ?? 'Política de Privacidade & LGPD — Clyo Nutri') ?></title>
  <meta name="description" content="<?= htmlspecialchars($metaDescription ?? 'Diretrizes de proteção de dados, privacidade e segurança da informação em conformidade com a LGPD.') ?>">
  <link rel="canonical" href="<?= htmlspecialchars($canonicalUrl ?? \ClyoNutri\Core\Security\ContactInfo::url('/privacidade')) ?>">
  <link rel="preload" href="/assets/fonts/inter-normal-latin.woff2" as="font" type="font/woff2" crossorigin>
  <link rel="stylesheet" href="/assets/css/fonts.css?v=v54-66">
  <link rel="stylesheet" href="/assets/css/landing-premium.css?v=botanical-v54-9">
  <style>
    .legal-page {
      padding: 60px 0 100px;
      max-width: 800px;
      margin: 0 auto;
    }
    .legal-content h1 {
      font-family: var(--font-editorial);
      font-size: 2.5rem;
      color: var(--brand-navy-900);
      margin-bottom: 8px;
    }
    .legal-updated {
      font-size: 0.875rem;
      color: var(--neutral-gray-500);
      margin-bottom: 40px;
    }
    .legal-content h2 {
      font-size: 1.35rem;
      font-weight: 700;
      color: var(--brand-navy-900);
      margin-top: 36px;
      margin-bottom: 12px;
    }
    .legal-content p, .legal-content li {
      font-size: 1rem;
      color: var(--neutral-gray-700);
      line-height: 1.7;
      margin-bottom: 14px;
    }
    .legal-content ul {
      padding-left: 24px;
      margin-bottom: 20px;
    }
    .back-nav {
      margin-bottom: 24px;
      display: inline-flex;
      align-items: center;
      gap: 8px;
      color: var(--brand-copper-600);
      font-weight: 600;
      font-size: 0.9375rem;
    }
    .badge-lgpd {
      display: inline-flex;
      align-items: center;
      gap: 6px;
      background: #E3F4F2;
      color: #0A7075;
      font-size: 0.8125rem;
      font-weight: 600;
      padding: 4px 10px;
      border-radius: 4px;
      margin-bottom: 16px;
    }
  </style>
<link rel="stylesheet" href="/assets/css/operational-ui.css?v=v54-66">
</head>
<body>
  <!-- Header -->
  <header class="header">
    <div class="container header__inner">
      <a href="/" class="logo" aria-label="Clyo Nutri Página Inicial"><img src="/assets/clyonutri-logo-light.svg?v=botanical-v54-9" alt="Clyo Nutri — Nutrição que transforma" width="260" height="64" style="display: block; width: 200px; max-width: 100%; height: auto;"></a>
      <div class="header__actions">
        <a href="/" class="btn-link">Voltar ao Início</a>
        <a href="/login" class="btn-secondary">Entrar</a>
      </div>
    </div>
  </header>

  <main class="container legal-page">
    <a href="/" class="back-nav">← Voltar para a página inicial</a>
    <article class="legal-content">
      <div class="badge-lgpd">
        <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/></svg>
        Conformidade com a Lei Federal nº 13.709/2018 (LGPD)
      </div>
      <h1>Política de Privacidade & Governança de Dados</h1>
      <p class="legal-updated">Última atualização: <?= htmlspecialchars(\ClyoNutri\Domain\Legal\LegalDocuments::PRIVACY_DATE) ?> · Versão <?= htmlspecialchars(\ClyoNutri\Domain\Legal\LegalDocuments::PRIVACY_VERSION) ?></p>

      <h2>1. Compromisso com a Privacidade</h2>
      <p>A privacidade e a proteção de dados pessoais e dados pessoais sensíveis de saúde são alicerces fundamentais da arquitetura do <strong>Clyo Nutri</strong>. Esta política detalha como coletamos, processamos, armazenamos e protegemos suas informações e as informações de seus pacientes.</p>

      <h2>2. Papéis e Responsabilidades (Controlador vs. Operador)</h2>
      <p>Nos termos do Art. 5º da LGPD:</p>
      <ul>
        <li><strong>O Nutricionista ou Clínica Contratante</strong> atua como <strong>Controlador</strong> dos dados cadastrais e de saúde dos seus pacientes, sendo responsável pela obtenção do consentimento ou enquadramento na base legal aplicável (tutela da saúde ou cumprimento de dever legal).</li>
        <li><strong>O Clyo Nutri</strong> atua como <strong>Operador</strong>, processando tais dados unicamente conforme as instruções do Controlador e para as finalidades estritas da prestação dos serviços contratados.</li>
      </ul>

      <h2>3. Dados Coletados e Finalidades</h2>
      <ul>
        <li><strong>Dados do Profissional:</strong> Nome completo, e-mail, número de registro profissional (CRN/Região), telefone de contato e credenciais de login. Finalidade: gestão de conta, autenticação segura e faturamento.</li>
        <li><strong>Dados dos Pacientes (inseridos pelo profissional):</strong> Nome, data de nascimento, gênero, contato, registros antropométricos, histórico clínico/anamnese e planos alimentares. Finalidade: suporte ao atendimento nutricional e acompanhamento longitudinal.</li>
        <li><strong>Métricas de uso do app (agregadas):</strong> contamos, por dia e por clínica, quantas vezes o plano foi visto, quantos check-ins foram enviados e quantos registros de água foram feitos, e quantas pacientes diferentes fizeram cada ação. Não guardamos nessas métricas o nome, o identificador da paciente, os valores registrados (água, adesão, observações) nem o conteúdo do plano. Para contar pacientes diferentes no dia usamos um código temporário que é apagado no dia seguinte. Números com menos de 5 pacientes não são exibidos. Finalidade: melhorar o produto. Base legal: legítimo interesse, com a coleta desligável pela plataforma.</li>
      </ul>

      <h2 id="seguranca">4. Medidas Técnicas e Segurança da Informação</h2>
      <p>Adotamos medidas técnicas e organizacionais proporcionais para proteger os dados sob nossa custódia:</p>
      <ul>
        <li><strong>Comunicação Criptografada:</strong> Os dados trafegam por conexão criptografada (HTTPS/TLS) entre o seu navegador e nossos servidores.</li>
        <li><strong>Armazenamento Seguro de Senhas:</strong> Senhas são armazenadas com hash unidirecional (bcrypt), nunca em texto legível.</li>
        <li><strong>Controle de Acesso por Consultório:</strong> Cada clínica só acessa os próprios dados, por controles de acesso da aplicação.</li>
        <li><strong>Trilha de Integridade e Auditoria:</strong> Registros clínicos e alterações contam com encadeamento de hashes SHA-256 para assegurar a rastreabilidade e autoria.</li>
      </ul>
      <p>Nenhum sistema conectado à internet é totalmente imune a incidentes; por isso, mantemos registros de auditoria das ações feitas no sistema.</p>

      <h2 id="cookies">5. Cookies</h2>
      <p>Usamos apenas cookies necessários para o funcionamento do serviço. Eles guardam a sua sessão e a segurança do acesso, e não servem para publicidade, para criar perfil de navegação nem para acompanhar você em outros sites. Por isso, o Clyo Nutri não pede consentimento para eles e não mostra um aviso de cookies. Se um dia passarmos a usar cookies de medição ou de terceiros, vamos avisar aqui e pedir a sua escolha antes de ativá-los.</p>
      <ul>
        <li><code>PHPSESSID</code>: mantém você conectado depois do login e protege os formulários contra uso indevido. Dura até 2 horas e é apagado ao sair da conta.</li>
        <li><code>clyonutri_mobile</code>: mantém a sessão do paciente no aplicativo aberto pelo navegador do celular. Vale só para a área da API do aplicativo e dura 30 dias a partir do login. Sair do aplicativo apaga o cookie antes disso.</li>
        <li><code>clyo_tele</code>: libera a entrada do paciente na sala de espera e na teleconsulta. Vale só nas páginas da teleconsulta e é apagado quando o navegador é fechado.</li>
      </ul>
      <p>Todos são gravados apenas em conexão segura (HTTPS) e não podem ser lidos por scripts da página. Você pode apagar os cookies pelo navegador quando quiser, mas o login, o aplicativo ou a teleconsulta deixam de funcionar até uma nova entrada.</p>

      <h2>6. Direitos dos Titulares de Dados</h2>
      <p>Garantimos pleno suporte ao exercício dos direitos previstos no Art. 18 da LGPD, incluindo: confirmação de tratamento, acesso aos dados, correção de dados incompletos ou inexatos, anonimização ou eliminação, e portabilidade dos dados clínicos mediante solicitação.</p>

      <h2>7. Encarregado de Proteção de Dados (DPO)</h2>
      <p>Para dúvidas, requisições de titulares ou notificações relativas à proteção de dados, nosso canal oficial de comunicação é: <code><?= htmlspecialchars(\ClyoNutri\Core\Security\ContactInfo::dpoEmail()) ?></code>.</p>
    </article>
  </main>

  <footer class="footer">
    <div class="container footer-bottom" style="border-top: none; padding-top: 0;">
      <p>© 2026 Clyo Nutri. Todos os direitos reservados.</p>
      <p><a href="/termos" style="color: var(--neutral-gray-500); text-decoration: underline;">Termos de Uso</a></p>
    </div>
  </footer>
</body>
</html>
