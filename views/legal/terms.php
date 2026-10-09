<!DOCTYPE html>
<html lang="pt-BR">
<head>
  <link rel="icon" type="image/svg+xml" href="/assets/clyonutri-icon.svg">
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title><?= htmlspecialchars($pageTitle ?? 'Termos de Uso — ClyoNutri II') ?></title>
  <meta name="description" content="<?= htmlspecialchars($metaDescription ?? 'Termos e Condições de Uso da plataforma ClyoNutri II.') ?>">
  <link rel="canonical" href="<?= htmlspecialchars($canonicalUrl ?? 'https://clyonutri.com.br/termos') ?>">
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&family=Playfair+Display:ital,wght@0,600;0,700;1,400&display=swap" rel="stylesheet">
  <link rel="stylesheet" href="/assets/css/landing-premium.css">
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
  </style>
</head>
<body>
  <!-- Header -->
  <header class="header">
    <div class="container header__inner">
      <a href="/" class="logo" aria-label="ClyoNutri Página Inicial"><img src="/assets/clyonutri-logo-light.svg" alt="Clyo Nutri — Nutrição que transforma" width="260" height="64" style="display: block; width: 200px; max-width: 100%; height: auto;"></a>
      <div class="header__actions">
        <a href="/" class="btn-link">Voltar ao Início</a>
        <a href="/login" class="btn-secondary">Entrar</a>
      </div>
    </div>
  </header>

  <main class="container legal-page">
    <a href="/" class="back-nav">← Voltar para a página inicial</a>
    <article class="legal-content">
      <h1>Termos e Condições de Uso</h1>
      <p class="legal-updated">Última atualização: 08 de Outubro de 2026 · Versão 1.0.0</p>

      <h2>1. Objeto e Escopo</h2>
      <p>O <strong>ClyoNutri II</strong> é uma plataforma de software como serviço (SaaS) voltada ao suporte da prática clínica e gerencial de nutricionistas devidamente habilitados junto aos seus respectivos Conselhos Regionais de Nutricionistas (CRN).</p>
      <p>Ao criar uma conta ou utilizar os serviços do ClyoNutri II, o usuário concorda integralmente com estes Termos de Uso e com a nossa Política de Privacidade.</p>

      <h2 id="etica">2. Responsabilidade Profissional e Julgamento Clínico</h2>
      <p>O ClyoNutri II disponibiliza ferramentas de cálculo nutricional baseadas em tabelas canônicas (ex.: Tabela Brasileira de Composição de Alimentos - TACO), organização de prontuários e acompanhamento de adesão. <strong>O software constitui exclusivamente uma ferramenta de apoio técnico e operacional.</strong></p>
      <p>A responsabilidade ética, técnica e jurídica pela avaliação nutricional, diagnóstico, prescrição de planos alimentares e orientações clínicas cabe com exclusividade ao nutricionista responsável pelo atendimento, em estrita observância ao Código de Ética e de Conduta do Nutricionista (Resolução CFN).</p>

      <h2>3. Propriedade dos Dados Clínicos</h2>
      <p>Todos os dados clínicos de pacientes inseridos no sistema são de propriedade e responsabilidade do profissional ou da clínica contratante (Controlador dos Dados nos termos da Lei nº 13.709/2018 - LGPD). O ClyoNutri II atua como Operador de dados, aplicando rigorosos controles técnicos de segurança da informação, isolamento multitenant e trilha criptográfica de integridade.</p>

      <h2>4. Período de Avaliação e Assinatura</h2>
      <ul>
        <li><strong>Período de Avaliação Gratuita:</strong> Novos usuários têm direito a 14 (quatorze) dias de teste irrestrito das funcionalidades ativas da plataforma, sem exigência de cartão de crédito no momento do cadastro.</li>
        <li><strong>Continuidade do Serviço:</strong> Ao término do período de avaliação, o usuário poderá optar pela contratação do plano adequado ao seu perfil profissional para manter o acesso às ferramentas de edição e novo cadastro.</li>
        <li><strong>Portabilidade dos Dados:</strong> É garantido ao profissional o direito de exportar seus registros clínicos e dados de pacientes a qualquer tempo.</li>
      </ul>

      <h2>5. Segurança e Integridade do Sistema</h2>
      <p>O usuário compromete-se a manter em sigilo suas credenciais de acesso, não compartilhando senhas com terceiros não autorizados. Qualquer tentativa de violar a integridade, realizar engenharia reversa ou acessar dados de outros inquilinos (tenants) resultará na rescisão imediata do acesso e na adoção das medidas legais pertinentes.</p>

      <h2>6. Contato e Notificações</h2>
      <p>Para dúvidas sobre estes Termos de Uso ou suporte à plataforma, entre em contato através do canal de atendimento oficial: <code>suporte@clyonutri.com.br</code>.</p>
    </article>
  </main>

  <footer class="footer">
    <div class="container footer-bottom" style="border-top: none; padding-top: 0;">
      <p>© 2026 ClyoNutri II. Todos os direitos reservados.</p>
      <p><a href="/privacidade" style="color: var(--neutral-gray-500); text-decoration: underline;">Política de Privacidade</a></p>
    </div>
  </footer>
</body>
</html>
