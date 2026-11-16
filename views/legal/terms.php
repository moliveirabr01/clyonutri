<?php
/*
 * RASCUNHO PARA REVISÃO DE UM ADVOGADO.
 * A versão 1.1.1 dos Termos assume regras comerciais que ainda não foram confirmadas
 * (teste de 14 dias sem cartão, renovação automática, reembolso de 100% em 7 dias,
 * valores e ciclos dos planos). Só deve valer para clientes depois da revisão jurídica.
 * Por isso o reaceite obrigatório (NI2-449) vem desligado: LEGAL_REACCEPTANCE_ENABLED=true só depois da revisão.
 */
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
  <link rel="icon" type="image/svg+xml" href="/assets/clyonutri-icon.svg?v=botanical-v54-9">
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title><?= htmlspecialchars($pageTitle ?? 'Termos de Uso — Clyo Nutri') ?></title>
  <meta name="description" content="<?= htmlspecialchars($metaDescription ?? 'Termos e Condições Gerais de Contratação e Uso da plataforma Clyo Nutri.') ?>">
  <link rel="canonical" href="<?= htmlspecialchars($canonicalUrl ?? \ClyoNutri\Core\Security\ContactInfo::url('/termos')) ?>">
  <link rel="preload" href="/assets/fonts/inter-normal-latin.woff2" as="font" type="font/woff2" crossorigin>
  <link rel="stylesheet" href="/assets/css/fonts.css?v=botanical-v54-9">
  <link rel="stylesheet" href="/assets/css/landing-premium.css?v=botanical-v54-9">
  <style>
    .legal-page {
      padding: 60px 0 100px;
      max-width: 820px;
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
      margin-bottom: 24px;
    }
    .legal-notice {
      background: #F8FAFC;
      border-left: 4px solid var(--brand-copper-500);
      padding: 14px 18px;
      border-radius: 6px;
      font-size: 0.9rem;
      color: var(--neutral-gray-700);
      margin-bottom: 32px;
      line-height: 1.6;
    }
    .legal-content h2 {
      font-size: 1.35rem;
      font-weight: 700;
      color: var(--brand-navy-900);
      margin-top: 36px;
      margin-bottom: 12px;
      padding-top: 8px;
    }
    .legal-content p, .legal-content li {
      font-size: 1rem;
      color: var(--neutral-gray-700);
      line-height: 1.75;
      margin-bottom: 14px;
    }
    .legal-content ul, .legal-content ol {
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
      text-decoration: none;
    }
    .back-nav:hover {
      text-decoration: underline;
    }
    .version-history {
      background: var(--neutral-gray-50);
      border: 1px solid var(--neutral-gray-200);
      border-radius: 8px;
      padding: 1.25rem;
      margin-top: 40px;
    }
    .version-history h3 {
      font-size: 1.1rem;
      color: var(--brand-navy-900);
      margin-top: 0;
      margin-bottom: 10px;
    }
  </style>
<link rel="stylesheet" href="/assets/css/operational-ui.css?v=v54-63">
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
      <h1>Termos e Condições Gerais de Uso</h1>
      <p class="legal-updated">Última atualização: <?= htmlspecialchars(\ClyoNutri\Domain\Legal\LegalDocuments::TERMS_DATE) ?> · Versão <?= htmlspecialchars(\ClyoNutri\Domain\Legal\LegalDocuments::TERMS_VERSION) ?></p>

      <div class="legal-notice">
        <strong>Transparência Contratual:</strong> Este documento estabelece os direitos, deveres e as condições comerciais aplicáveis à utilização da plataforma Clyo Nutri, incluindo planos, renovação recorrente, cancelamento em autosserviço, direito de arrependimento (CDC art. 49) e regras de reembolso.
      </div>

      <h2>1. Objeto e Escopo</h2>
      <p>O <strong>Clyo Nutri</strong> é uma plataforma de software como serviço (SaaS) voltada ao suporte da prática clínica, prescrição nutricional e gestão do consultório de nutricionistas devidamente habilitados perante os Conselhos Regionais de Nutricionistas (CRN).</p>
      <p>Ao criar uma conta, iniciar o período de avaliação ou contratar qualquer assinatura na plataforma, o profissional ou a entidade contratante adere de forma livre, consciente e integral a estes Termos e Condições de Uso e à nossa <a href="/privacidade" style="color: var(--brand-copper-600); text-decoration: underline;">Política de Privacidade</a>.</p>

      <h2 id="etica">2. Responsabilidade Profissional e Julgamento Clínico</h2>
      <p>O Clyo Nutri disponibiliza ferramentas de auxílio técnico, incluindo cálculos nutricionais automatizados fundamentados na Tabela Brasileira de Composição de Alimentos (TACO), organização de prontuários clínicos, triagem pré-consulta, teleconsulta integrada e aplicativo de acompanhamento do paciente. <strong>O software constitui exclusivamente uma ferramenta tecnológica de suporte operacional e organizativo.</strong></p>
      <p>A responsabilidade ética, técnica, diagnóstica e jurídica pela anamnese, avaliação nutricional, determinação de metas calóricas, prescrição de planos alimentares e eventuais encaminhamentos clínicos cabe privativamente e com exclusividade ao nutricionista responsável, em estrita observância à Lei Federal nº 8.234/1991 e às Resoluções do Conselho Federal de Nutricionistas (CFN).</p>

      <h2>3. Propriedade dos Dados Clínicos e Papéis sob a LGPD</h2>
      <p>Todos os dados cadastrais e de saúde dos pacientes inseridos na plataforma pertencem exclusivamente ao respectivo paciente e ao profissional ou clínica contratante, que assume a posição de <strong>Controlador dos Dados</strong> nos termos do Art. 5º, VI, da Lei Geral de Proteção de Dados (Lei nº 13.709/2018 - LGPD).</p>
      <p>O Clyo Nutri atua na qualidade de <strong>Operador de Dados</strong> (Art. 5º, VII, da LGPD), executando o tratamento de informações sob estritas instruções do Controlador, com isolamento multi-tenant de base de dados, conexão criptografada (HTTPS/TLS) e trilhas auditáveis de segurança.</p>

      <h2>4. Período de Avaliação Gratuita (Trial)</h2>
      <p>Novos profissionais têm direito a 14 (quatorze) dias corridos de teste gratuito das funcionalidades da plataforma, contados a partir da criação da conta, sem necessidade de cadastrar cartão de crédito ou meio de pagamento.</p>
      <p>Durante o período de avaliação, o usuário poderá explorar os recursos da plataforma. Ao término dos 14 dias, para continuar criando novos planos, registrando consultas e mantendo a edição ativa, o profissional poderá selecionar o plano comercial de sua preferência. Os dados já cadastrados permanecem íntegros e disponíveis para exportação.</p>

      <h2 id="preco">5. Planos e Condições Comerciais</h2>
      <p>Os serviços do Clyo Nutri são comercializados sob a modalidade de assinatura com base em planos desenhados para a escala de cada consultório ou clínica:</p>
      <ul>
        <li><strong>ClyoNutri Start:</strong> Voltado ao profissional autônomo em início de consultório, com suporte para 1 profissional e até 50 pacientes ativos.</li>
        <li><strong>ClyoNutri Profissional:</strong> Indicado para consultórios em expansão, contemplando até 3 profissionais, pacientes ilimitados e teleconsulta integrada.</li>
        <li><strong>ClyoNutri Clínica Elite:</strong> Solução para clínicas e equipes multidisciplinares com múltiplos profissionais, gestão corporativa e suporte prioritário.</li>
      </ul>
      <p>A descrição detalhada de recursos, valores atualizados e comparativo de capacidades de cada modalidade encontram-se permanentemente acessíveis em nossa <a href="/planos" style="color: var(--brand-copper-600); font-weight: 600; text-decoration: underline;">Página Oficial de Planos e Preços</a>. É facultado ao contratante optar pela cobrança em ciclo mensal recorrente ou pelo ciclo anual com desconto.</p>

      <h2 id="renovacao">6. Cobrança e Renovação Automática</h2>
      <p>A contratação é formalizada mediante pagamento eletrônico (cartão de crédito ou boleto bancário/PIX, conforme disponibilizado no gateway de pagamento). O faturamento ocorre de forma antecipada ao período de utilização.</p>
      <p>Para assegurar a continuidade do atendimento clínico e o acesso ininterrupto dos pacientes ao aplicativo, <strong>a assinatura é renovada automaticamente</strong> ao final de cada ciclo contratado (mensal ou anual), sendo lançada nova cobrança no meio de pagamento registrado, salvo se houver solicitação prévia de cancelamento por parte do contratante.</p>
      <p>Não há carência mínima obrigatória nem aplicação de multa rescisória para o cancelamento de planos mensais padrão.</p>

      <h2 id="cancelamento">7. Política de Cancelamento em Autosserviço</h2>
      <p>No Clyo Nutri, o contratante tem autonomia total sobre a gestão da sua assinatura. O cancelamento pode ser efetuado a qualquer momento em autosserviço:</p>
      <ol>
        <li>O titular da conta acessa o menu <strong>Configurações → Assinatura &amp; Faturamento</strong> (<code>/configuracoes/assinatura</code>).</li>
        <li>Clica no botão <strong>Confirmar Cancelamento da Assinatura</strong>. O preenchimento da pesquisa de saída é facultativo.</li>
        <li>O sistema gera de forma imediata um número de <strong>Protocolo de Cancelamento</strong> inequívoco para fins comprobatórios.</li>
      </ol>
      <p>Alternativamente, o cancelamento pode ser solicitado diretamente pelo canal de atendimento oficial através do e-mail <code><?= htmlspecialchars(\ClyoNutri\Core\Security\ContactInfo::supportEmail()) ?></code>.</p>
      <p>Com a confirmação do cancelamento, a renovação automática é imediatamente interrompida e nenhuma nova cobrança futura será realizada. O acesso ao consultório e às ferramentas contratadas permanece liberado até o término do ciclo já faturado.</p>

      <h2 id="arrependimento">8. Direito de Arrependimento Legal</h2>
      <p>Em estrito cumprimento ao <strong>Artigo 49 da Lei Federal nº 8.078/1990 (Código de Defesa do Consumidor - CDC)</strong> e ao Decreto Federal nº 7.962/2013 (Comércio Eletrônico), o contratante que contratar uma assinatura na modalidade a distância poderá exercer o seu <strong>direito de arrependimento no prazo de 7 (sete) dias corridos</strong> a contar da confirmação da primeira contratação ou do pagamento inicial da assinatura.</p>
      <p>Para exercer o direito de arrependimento, o contratante poderá utilizar o botão de cancelamento na Central de Assinatura (<code>/configuracoes/assinatura</code>) ou manifestar formalmente sua intenção através do e-mail de atendimento <code><?= htmlspecialchars(\ClyoNutri\Core\Security\ContactInfo::supportEmail()) ?></code>, indicando a solicitação de reembolso no prazo legal.</p>

      <h2 id="reembolso">9. Política de Reembolso</h2>
      <p>As regras de restituição de valores observam as seguintes diretrizes objetivas:</p>
      <ul>
        <li><strong>Cancelamento dentro do prazo de arrependimento (até 7 dias):</strong> Exercido o direito de arrependimento no prazo legal de 7 (sete) dias da primeira contratação, o contratante terá direito à <strong>restituição integral (100%)</strong> de todos os valores pagos. O estorno será processado junto à operadora do cartão de crédito ou transferido para conta bancária do titular no prazo de até 7 (sete) dias úteis após a solicitação.</li>
        <li><strong>Cancelamento após o prazo de 7 dias:</strong> Caso o cancelamento ocorra após transcorrido o prazo legal de 7 dias corridos, não haverá reembolso proporcional (pro-rata) dos valores correspondentes aos dias remanescentes do ciclo vigente. O contratante manterá acesso pleno a todos os recursos da plataforma até o encerramento do período mensal ou anual já pago, cessando as cobranças futuras.</li>
      </ul>

      <h2>10. Segurança, Credenciais e Integridade</h2>
      <p>O usuário compromete-se a manter a estrita confidencialidade de suas credenciais de acesso, não compartilhando usuários ou senhas com pessoas não autorizadas. Qualquer conduta com intuito de burlar mecanismos de controle, executar engenharia reversa, violar dados de outros inquilinos (tenants) ou comprometer a segurança da infraestrutura acarretará a rescisão imediata do contrato, sem prejuízo das reparações civis e medidas penais cabíveis.</p>

      <h2 id="responsabilidade">11. Limitação de Responsabilidade</h2>
      <p>O Clyo Nutri envidará os melhores esforços técnicos para assegurar elevada disponibilidade e integridade da plataforma. Contudo, a plataforma não se responsabiliza por interrupções temporárias decorrentes de manutenções programadas, falhas em operadoras de telecomunicação, ataques cibernéticos externos de força maior, ou condutas indevidas praticadas pelos próprios usuários.</p>
      <p>Em nenhuma hipótese a fornecedora responderá por danos indiretos, lucros cessantes, perdas financeiras decorrentes da gestão de negócios da clínica ou por eventuais decisões clínicas equivocadas tomadas pelos profissionais de saúde usuários.</p>

      <h2 id="foro">12. Legislação Aplicável e Foro de Eleição</h2>
      <p>Estes Termos de Uso são regidos e interpretados segundo a legislação da República Federativa do Brasil, em especial o Código de Defesa do Consumidor (Lei nº 8.078/1990), o Marco Civil da Internet (Lei nº 12.965/2014) e a Lei Geral de Proteção de Dados (Lei nº 13.709/2018).</p>
      <p>Para dirimir quaisquer controvérsias decorrentes deste contrato, as partes elegem preferencialmente o foro do domicílio do consumidor nas relações tuteladas pelo CDC. Nas contratações entre pessoas jurídicas ou relações corporativas empresariais, elege-se o foro da comarca da sede da empresa fornecedora, com expressa renúncia a qualquer outro, por mais privilegiado que seja.</p>

      <h2 id="contato">13. Atendimento e Notificações Oficiais</h2>
      <p>Para esclarecimento de dúvidas sobre estes Termos, solicitações financeiras, cancelamentos, reembolsos ou suporte operacional, utilize nosso canal oficial de comunicação:</p>
      <p>E-mail de suporte: <code><?= htmlspecialchars(\ClyoNutri\Core\Security\ContactInfo::supportEmail()) ?></code>.</p>

      <div class="version-history">
        <h3>Histórico de Revisões</h3>
        <ul>
          <li><strong>Versão 1.1.1 (26/10/2026):</strong> Correção: retirada a frase sobre criptografia dos dados armazenados, que a plataforma não faz.</li>
          <li><strong>Versão 1.1.0 (25/10/2026):</strong> Atualização de conformidade comercial (CDC e Decreto 7.962/2013). Inclusão detalhada dos planos, dinâmica de cobrança e renovação automática, autosserviço de cancelamento com protocolo, direito legal de arrependimento em 7 dias com reembolso integral, limitação de responsabilidade e disposições de foro.</li>
          <li><strong>Versão 1.0.0 (08/10/2026):</strong> Versão inaugural dos Termos de Uso da plataforma Clyo Nutri.</li>
        </ul>
      </div>
    </article>
  </main>

  <footer class="footer">
    <div class="container footer-bottom" style="border-top: none; padding-top: 0;">
      <p>© 2026 Clyo Nutri. Todos os direitos reservados.</p>
      <p><a href="/privacidade" style="color: var(--neutral-gray-500); text-decoration: underline;">Política de Privacidade</a></p>
    </div>
  </footer>
</body>
</html>
