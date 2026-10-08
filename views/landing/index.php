<!DOCTYPE html>
<html lang="pt-BR">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title><?= htmlspecialchars($pageTitle ?? 'ClyoNutri II — O Sistema Operacional do Nutricionista Clínico') ?></title>
  <meta name="description" content="<?= htmlspecialchars($metaDescription ?? 'Plataforma completa para nutricionistas clínicos: gestão de pacientes, anamnese, base TACO, prescrição estruturada de planos alimentares e aplicativo conectado.') ?>">
  <link rel="canonical" href="<?= htmlspecialchars($canonicalUrl ?? 'https://clyonutri.com.br/') ?>">
  
  <!-- Open Graph / Meta -->
  <meta property="og:type" content="website">
  <meta property="og:title" content="ClyoNutri II — Precisão Biomédica e Cuidado Clínico">
  <meta property="og:description" content="Gestão completa do consultório, cálculo nutricional instantâneo com base TACO oficial e aplicativo conectado para o paciente.">
  <meta property="og:url" content="https://clyonutri.com.br/">
  <meta property="og:locale" content="pt_BR">
  
  <!-- Typography Preconnect -->
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&family=Playfair+Display:ital,wght@0,600;0,700;1,400&display=swap" rel="stylesheet">
  
  <link rel="stylesheet" href="/assets/css/landing-premium.css">
</head>
<body>
  <!-- Acessibilidade: Skip Link -->
  <a href="#main-content" class="skip-link">Pular para o conteúdo principal</a>

  <!-- =========================================================================
       1. HEADER PREMIUM
       ========================================================================= -->
  <header class="header" role="banner">
    <div class="container header__inner">
      <a href="/" class="logo" aria-label="ClyoNutri II Página Inicial">
        <svg width="26" height="26" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
          <path d="M12 2L2 7l10 5 10-5-10-5zM2 17l10 5 10-5M2 12l10 5 10-5"/>
        </svg>
        <span><span class="logo__brand-clyo">Clyo</span>Nutri <small style="font-size: 0.65em; font-weight: 500; color: var(--neutral-gray-500);">II</small></span>
      </a>

      <!-- Navegação Desktop -->
      <nav class="nav" aria-label="Navegação principal">
        <a href="#produto" class="nav__link">Produto</a>
        <a href="#recursos" class="nav__link">Recursos</a>
        <a href="#experiencia-paciente" class="nav__link">App do Paciente</a>
        <a href="#diferenciais" class="nav__link">Segurança & Precisão</a>
        <a href="#precos" class="nav__link">Planos</a>
      </nav>

      <!-- Ações de Autenticação / Início -->
      <div class="header__actions">
        <?php if (!empty($isLoggedIn)): ?>
          <span style="font-size: 0.875rem; color: var(--neutral-gray-500); display: none; @media(min-width: 900px){display:inline;}">
            Olá, <?= htmlspecialchars($userName ?? 'Nutricionista') ?>
          </span>
          <a href="/dashboard" class="btn-primary" aria-label="Acessar o Painel Clínico">
            Ir para o Dashboard
            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="M5 12h14M12 5l7 7-7 7"/></svg>
          </a>
        <?php else: ?>
          <a href="/login" class="btn-link">Entrar</a>
          <a href="/cadastro" class="btn-primary" aria-label="Criar conta no ClyoNutri">Começar Agora</a>
        <?php endif; ?>

        <!-- Botão Mobile Toggle -->
        <button type="button" class="mobile-toggle" id="mobile-toggle-btn" aria-label="Abrir menu de navegação" aria-expanded="false" aria-controls="mobile-drawer-menu">
          <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
            <line x1="3" y1="12" x2="21" y2="12"></line>
            <line x1="3" y1="6" x2="21" y2="6"></line>
            <line x1="3" y1="18" x2="21" y2="18"></line>
          </svg>
        </button>
      </div>
    </div>
  </header>

  <!-- Mobile Drawer Menu -->
  <div class="mobile-drawer" id="mobile-drawer-menu" role="dialog" aria-modal="true" aria-label="Menu móvel">
    <nav style="display: flex; flex-direction: column;" aria-label="Navegação móvel">
      <a href="#produto" class="mobile-nav-link">Produto</a>
      <a href="#recursos" class="mobile-nav-link">Recursos</a>
      <a href="#experiencia-paciente" class="mobile-nav-link">App do Paciente</a>
      <a href="#diferenciais" class="mobile-nav-link">Segurança & Precisão</a>
      <a href="#precos" class="mobile-nav-link">Planos & Acesso</a>
      <a href="#faq" class="mobile-nav-link">Perguntas Frequentes</a>
    </nav>
    <div style="margin-top: 24px; display: flex; flex-direction: column; gap: 12px;">
      <?php if (!empty($isLoggedIn)): ?>
        <a href="/dashboard" class="btn-primary" style="width: 100%;">Acessar Dashboard</a>
      <?php else: ?>
        <a href="/cadastro" class="btn-primary" style="width: 100%;">Criar Conta Gratuita</a>
        <a href="/login" class="btn-secondary" style="width: 100%;">Entrar na Plataforma</a>
      <?php endif; ?>
    </div>
  </div>

  <main id="main-content">
    <!-- =========================================================================
         2. HERO DE ALTO IMPACTO
         ========================================================================= -->
    <section class="hero" aria-labelledby="hero-title">
      <div class="container">
        <div class="hero__badge">
          <span class="hero__badge-dot" aria-hidden="true"></span>
          <span>Plataforma Clínica de Alta Precisão · Versão 1.0</span>
        </div>

        <h1 id="hero-title" class="hero__headline">
          Sua nutrição merece uma plataforma à altura.
        </h1>

        <p class="hero__subheadline">
          Organize seu consultório, acompanhe seus pacientes e transforme sua rotina profissional em uma experiência mais ágil, cientificamente exata e estritamente ética.
        </p>

        <div class="hero__cta-group">
          <?php if (!empty($isLoggedIn)): ?>
            <a href="/dashboard" class="btn-primary" style="font-size: 1.0625rem; padding: 12px 28px;">
              Acessar Meu Painel
              <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M5 12h14M12 5l7 7-7 7"/></svg>
            </a>
          <?php else: ?>
            <a href="/cadastro" class="btn-primary" style="font-size: 1.0625rem; padding: 12px 28px;">
              Começar Gratuitamente
              <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M5 12h14M12 5l7 7-7 7"/></svg>
            </a>
            <a href="#recursos" class="btn-secondary" style="font-size: 1.0625rem; padding: 12px 24px;">
              Explorar Recursos
            </a>
          <?php endif; ?>
        </div>

        <div class="hero__guarantees" aria-label="Garantias e conformidade">
          <div class="hero__guarantee-item">
            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><polyline points="20 6 9 17 4 12"/></svg>
            <span>14 dias de teste completo</span>
          </div>
          <div class="hero__guarantee-item">
            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><polyline points="20 6 9 17 4 12"/></svg>
            <span>Sem cartão de crédito no início</span>
          </div>
          <div class="hero__guarantee-item">
            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><polyline points="20 6 9 17 4 12"/></svg>
            <span>Conformidade com LGPD & CFN</span>
          </div>
        </div>

        <!-- Protagonista Visual do Hero: UI Real do Sistema -->
        <div class="hero__product-wrapper" role="region" aria-label="Prévia da interface clínica do ClyoNutri II">
          <div class="window-chrome">
            <div class="window-dots" aria-hidden="true">
              <span class="window-dot"></span>
              <span class="window-dot"></span>
              <span class="window-dot"></span>
            </div>
            <div class="window-address">
              <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><rect x="3" y="11" width="18" height="11" rx="2" ry="2"/><path d="M7 11V7a5 5 0 0 1 10 0v4"/></svg>
              <span>app.clyonutri.com.br/dashboard</span>
            </div>
          </div>

          <!-- Conteúdo da Interface Clínica -->
          <div class="hero-dashboard-preview">
            <!-- Barra superior simulada da clínica -->
            <div style="display: flex; justify-content: space-between; align-items: center; padding-bottom: 16px; border-bottom: 1px solid #E2E8F0; margin-bottom: 20px;">
              <div>
                <h3 style="font-size: 1.125rem; font-weight: 700; color: #0F2747; margin-bottom: 2px;">Dra. Camila Mendes</h3>
                <p style="font-size: 0.75rem; color: #64748B;">Consultório Nutricional · CRN-3 12345 · São Paulo/SP</p>
              </div>
              <div style="display: flex; gap: 8px;">
                <span style="display: inline-flex; align-items: center; gap: 6px; background-color: #EBF3FA; color: #0F2747; padding: 4px 10px; border-radius: 6px; font-size: 0.75rem; font-weight: 600;">
                  <span style="width: 6px; height: 6px; border-radius: 50%; background-color: #18794E;"></span> Sistema Operando
                </span>
              </div>
            </div>

            <!-- Métricas Reais do Dashboard -->
            <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(180px, 1fr)); gap: 16px; margin-bottom: 20px;">
              <div style="background: #FFFFFF; border: 1px solid #E2E8F0; border-radius: 10px; padding: 16px;">
                <p style="font-size: 0.75rem; text-transform: uppercase; font-weight: 600; color: #64748B; margin-bottom: 4px;">Pacientes Ativos</p>
                <p style="font-size: 1.75rem; font-weight: 700; color: #0F2747;">48</p>
                <p style="font-size: 0.75rem; color: #18794E; margin-top: 4px;">+4 novos este mês</p>
              </div>
              <div style="background: #FFFFFF; border: 1px solid #E2E8F0; border-radius: 10px; padding: 16px;">
                <p style="font-size: 0.75rem; text-transform: uppercase; font-weight: 600; color: #64748B; margin-bottom: 4px;">Consultas Hoje</p>
                <p style="font-size: 1.75rem; font-weight: 700; color: #0F2747;">6</p>
                <p style="font-size: 0.75rem; color: #E67A3F; margin-top: 4px;">2 retornos confirmados</p>
              </div>
              <div style="background: #FFFFFF; border: 1px solid #E2E8F0; border-radius: 10px; padding: 16px;">
                <p style="font-size: 0.75rem; text-transform: uppercase; font-weight: 600; color: #64748B; margin-bottom: 4px;">Adesão Média</p>
                <p style="font-size: 1.75rem; font-weight: 700; color: #0F2747;">94%</p>
                <p style="font-size: 0.75rem; color: #18794E; margin-top: 4px;">Baseada no diário móvel</p>
              </div>
              <div style="background: #FFFFFF; border: 1px solid #E2E8F0; border-radius: 10px; padding: 16px;">
                <p style="font-size: 0.75rem; text-transform: uppercase; font-weight: 600; color: #64748B; margin-bottom: 4px;">Recebíveis do Mês</p>
                <p style="font-size: 1.75rem; font-weight: 700; color: #0F2747;">R$ 14.800</p>
                <p style="font-size: 0.75rem; color: #64748B; margin-top: 4px;">100% conciliado</p>
              </div>
            </div>

            <!-- Divisão: Próximos Atendimentos & Última Prescrição -->
            <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(280px, 1fr)); gap: 16px;">
              <!-- Agenda do Dia -->
              <div style="background: #FFFFFF; border: 1px solid #E2E8F0; border-radius: 10px; padding: 16px;">
                <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 12px;">
                  <h4 style="font-size: 0.875rem; font-weight: 700; color: #0F2747;">Agenda de Hoje</h4>
                  <span style="font-size: 0.75rem; color: #64748B;">08 de Outubro</span>
                </div>
                <div style="display: flex; flex-direction: column; gap: 10px;">
                  <div style="display: flex; align-items: center; justify-content: space-between; padding: 8px 12px; background: #F8FAFC; border-radius: 6px; border-left: 3px solid #18794E;">
                    <div>
                      <p style="font-size: 0.8125rem; font-weight: 600; color: #0F2747;">09:00 · Mariana Souza</p>
                      <p style="font-size: 0.75rem; color: #64748B;">Reavaliação Antropométrica</p>
                    </div>
                    <span style="font-size: 0.6875rem; background: #E8F5E9; color: #18794E; padding: 2px 8px; border-radius: 4px; font-weight: 600;">Confirmado</span>
                  </div>
                  <div style="display: flex; align-items: center; justify-content: space-between; padding: 8px 12px; background: #F8FAFC; border-radius: 6px; border-left: 3px solid #E67A3F;">
                    <div>
                      <p style="font-size: 0.8125rem; font-weight: 600; color: #0F2747;">10:30 · Carlos Eduardo</p>
                      <p style="font-size: 0.75rem; color: #64748B;">Primeira Consulta / Anamnese</p>
                    </div>
                    <span style="font-size: 0.6875rem; background: #FFF4ED; color: #B94F1F; padding: 2px 8px; border-radius: 4px; font-weight: 600;">Em espera</span>
                  </div>
                  <div style="display: flex; align-items: center; justify-content: space-between; padding: 8px 12px; background: #F8FAFC; border-radius: 6px; border-left: 3px solid #64748B;">
                    <div>
                      <p style="font-size: 0.8125rem; font-weight: 600; color: #0F2747;">14:00 · Beatriz Lima</p>
                      <p style="font-size: 0.75rem; color: #64748B;">Ajuste de Plano TACO</p>
                    </div>
                    <span style="font-size: 0.6875rem; background: #F1F5F9; color: #475569; padding: 2px 8px; border-radius: 4px; font-weight: 600;">Agendado</span>
                  </div>
                </div>
              </div>

              <!-- Prescrição Ativa TACO -->
              <div style="background: #FFFFFF; border: 1px solid #E2E8F0; border-radius: 10px; padding: 16px;">
                <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 12px;">
                  <h4 style="font-size: 0.875rem; font-weight: 700; color: #0F2747;">Prescrição Canônica (TACO)</h4>
                  <span style="font-size: 0.6875rem; background: #EBF3FA; color: #0F2747; padding: 2px 6px; border-radius: 4px; font-weight: 600;">Meta: 1.840 kcal</span>
                </div>
                <div style="display: flex; gap: 8px; margin-bottom: 12px;">
                  <div style="flex: 1; background: #F8FAFC; padding: 8px; border-radius: 6px; text-align: center;">
                    <span style="font-size: 0.6875rem; color: #64748B; display: block;">Proteínas</span>
                    <strong style="font-size: 0.875rem; color: #0F2747;">115g</strong> <span style="font-size: 0.6875rem; color: #64748B;">(25%)</span>
                  </div>
                  <div style="flex: 1; background: #F8FAFC; padding: 8px; border-radius: 6px; text-align: center;">
                    <span style="font-size: 0.6875rem; color: #64748B; display: block;">Carboidratos</span>
                    <strong style="font-size: 0.875rem; color: #0F2747;">220g</strong> <span style="font-size: 0.6875rem; color: #64748B;">(48%)</span>
                  </div>
                  <div style="flex: 1; background: #F8FAFC; padding: 8px; border-radius: 6px; text-align: center;">
                    <span style="font-size: 0.6875rem; color: #64748B; display: block;">Lipídeos</span>
                    <strong style="font-size: 0.875rem; color: #0F2747;">55g</strong> <span style="font-size: 0.6875rem; color: #64748B;">(27%)</span>
                  </div>
                </div>
                <div style="font-size: 0.75rem; color: #64748B; display: flex; align-items: center; gap: 6px; border-top: 1px dashed #E2E8F0; padding-top: 8px;">
                  <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="#18794E" stroke-width="2.5"><polyline points="20 6 9 17 4 12"/></svg>
                  <span>Cálculos auditáveis e conformes com a Tabela TACO / UNICAMP</span>
                </div>
              </div>
            </div>
          </div>
        </div>
      </div>
    </section>

    <!-- =========================================================================
         3. O PROBLEMA DO NUTRICIONISTA
         ========================================================================= -->
    <section class="section-padding" style="background-color: var(--neutral-white);" aria-labelledby="problem-title">
      <div class="container">
        <span class="section-tag">O Desafio Clínico</span>
        <h2 id="problem-title" class="section-title">
          Seu tempo deveria estar com seus pacientes. Não com a burocracia.
        </h2>
        <p class="section-subtitle">
          Nutricionistas perdem em média 12 horas por semana organizando arquivos dispersos, refazendo cálculos manuais e tentando manter os pacientes motivados após a consulta.
        </p>

        <div class="problem-grid">
          <!-- Card 1 -->
          <article class="problem-card">
            <div class="problem-card__icon" aria-hidden="true">
              <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round">
                <rect x="2" y="2" width="20" height="8" rx="2" ry="2"/>
                <rect x="2" y="14" width="20" height="8" rx="2" ry="2"/>
                <line x1="6" y1="6" x2="6.01" y2="6"/>
                <line x1="6" y1="18" x2="6.01" y2="18"/>
              </svg>
            </div>
            <h3 class="problem-card__title">Dados dispersos e retrabalho</h3>
            <p class="problem-card__desc">
              Anamneses em fichas de papel, tabelas avulsas no Excel e mensagens soltas no WhatsApp geram perda de histórico e insegurança jurídica na guarda dos dados.
            </p>
          </article>

          <!-- Card 2 -->
          <article class="problem-card">
            <div class="problem-card__icon" aria-hidden="true">
              <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round">
                <rect x="4" y="2" width="16" height="20" rx="2"/>
                <line x1="8" y1="6" x2="16" y2="6"/>
                <line x1="16" y1="14" x2="16" y2="18"/>
                <path d="M16 10h.01"/>
                <path d="M12 10h.01"/>
                <path d="M8 10h.01"/>
                <path d="M12 14h.01"/>
                <path d="M8 14h.01"/>
                <path d="M12 18h.01"/>
                <path d="M8 18h.01"/>
              </svg>
            </div>
            <h3 class="problem-card__title">Cálculos nutricionais exaustivos</h3>
            <p class="problem-card__desc">
              Calcular gramaturas de macronutrientes, micronutrientes e equivalentes sem uma base científica oficial consome horas preciosas de atendimento e abre espaço para erros.
            </p>
          </article>

          <!-- Card 3 -->
          <article class="problem-card">
            <div class="problem-card__icon" aria-hidden="true">
              <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round">
                <path d="M18 6L6 18M6 6l12 12"/>
              </svg>
            </div>
            <h3 class="problem-card__title">Abandono e desengajamento</h3>
            <p class="problem-card__desc">
              O paciente sai do consultório com um PDF estático de difícil leitura, perde a motivação nas duas primeiras semanas e não comparece à consulta de retorno.
            </p>
          </article>
        </div>
      </div>
    </section>

    <!-- =========================================================================
         4. SOLUÇÃO INTEGRADA (3 PILARES)
         ========================================================================= -->
    <section class="section-padding" id="recursos" aria-labelledby="solution-title">
      <div class="container">
        <span class="section-tag">A Solução ClyoNutri</span>
        <h2 id="solution-title" class="section-title">
          Três pilares integrados para transformar a sua rotina clínica.
        </h2>
        <p class="section-subtitle">
          Criamos uma arquitetura pensada para eliminar atritos, assegurar a conformidade ética e colocar você no controle de cada etapa do cuidado nutricional.
        </p>

        <div class="pillars-grid">
          <!-- Pilar 1 -->
          <article class="pillar-card">
            <p class="pillar-card__step">Pilar 01 · Gestão</p>
            <h3 class="pillar-card__title">Organize</h3>
            <p class="pillar-card__desc">
              Centralize prontuários eletrônicos com histórico antropométrico unificado, agenda de atendimentos e controle financeiro integrado com estrito isolamento por clínica.
            </p>
            <ul class="pillar-card__features">
              <li>
                <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><polyline points="20 6 9 17 4 12"/></svg>
                <span>Prontuário eletrônico unificado e seguro</span>
              </li>
              <li>
                <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><polyline points="20 6 9 17 4 12"/></svg>
                <span>Agenda sincronizada com confirmação</span>
              </li>
              <li>
                <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><polyline points="20 6 9 17 4 12"/></svg>
                <span>Isolamento multitenant conforme a LGPD</span>
              </li>
            </ul>
          </article>

          <!-- Pilar 2 -->
          <article class="pillar-card">
            <p class="pillar-card__step">Pilar 02 · Precisão</p>
            <h3 class="pillar-card__title">Prescreva</h3>
            <p class="pillar-card__desc">
              Construa planos alimentares por refeição com a base oficial TACO/IBGE. A cada grama ajustada, o sistema recalcula instantaneamente energia e macronutrientes.
            </p>
            <ul class="pillar-card__features">
              <li>
                <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><polyline points="20 6 9 17 4 12"/></svg>
                <span>Base TACO canônica oficial integrada</span>
              </li>
              <li>
                <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><polyline points="20 6 9 17 4 12"/></svg>
                <span>Cálculo automático de calorias e macros</span>
              </li>
              <li>
                <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><polyline points="20 6 9 17 4 12"/></svg>
                <span>Substituições inteligentes pré-aprovadas</span>
              </li>
            </ul>
          </article>

          <!-- Pilar 3 -->
          <article class="pillar-card">
            <p class="pillar-card__step">Pilar 03 · Continuidade</p>
            <h3 class="pillar-card__title">Acompanhe</h3>
            <p class="pillar-card__desc">
              Conecte o paciente a um aplicativo móvel simples onde ele consulta o plano do dia, registra o consumo de água e anota refeições sem complexidade.
            </p>
            <ul class="pillar-card__features">
              <li>
                <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><polyline points="20 6 9 17 4 12"/></svg>
                <span>Aplicativo do paciente disponível em tempo real</span>
              </li>
              <li>
                <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><polyline points="20 6 9 17 4 12"/></svg>
                <span>Acompanhamento diário de hidratação</span>
              </li>
              <li>
                <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><polyline points="20 6 9 17 4 12"/></svg>
                <span>Fim do esquecimento e aumento da retenção</span>
              </li>
            </ul>
          </article>
        </div>
      </div>
    </section>

    <!-- =========================================================================
         5. SHOWCASE DO PRODUTO (ABAS INTERATIVAS ACESSÍVEIS)
         ========================================================================= -->
    <section class="section-padding" id="produto" style="background-color: var(--neutral-white);" aria-labelledby="showcase-title">
      <div class="container">
        <div style="text-align: center;">
          <span class="section-tag">Visão do Produto</span>
          <h2 id="showcase-title" class="section-title">
            Cada tela desenhada para a velocidade do seu atendimento.
          </h2>
          <p class="section-subtitle" style="margin-left: auto; margin-right: auto;">
            Sem poluição visual, sem menus labirínticos. Tudo o que você precisa está a um clique de distância.
          </p>
        </div>

        <!-- Abas com navegação por teclado ARIA -->
        <div class="showcase-tabs" role="tablist" aria-label="Recursos da plataforma ClyoNutri II">
          <button type="button" class="showcase-tab" role="tab" id="tab-perfil" aria-selected="true" aria-controls="panel-perfil" tabindex="0">
            Perfil 360 do Paciente
          </button>
          <button type="button" class="showcase-tab" role="tab" id="tab-prescricao" aria-selected="false" aria-controls="panel-prescricao" tabindex="-1">
            Prescrição Canônica TACO
          </button>
          <button type="button" class="showcase-tab" role="tab" id="tab-agenda" aria-selected="false" aria-controls="panel-agenda" tabindex="-1">
            Agenda & Financeiro
          </button>
          <button type="button" class="showcase-tab" role="tab" id="tab-app" aria-selected="false" aria-controls="panel-app" tabindex="-1">
            App do Paciente (Beta)
          </button>
        </div>

        <!-- Painel 1: Perfil 360 -->
        <div class="showcase-panel" role="tabpanel" id="panel-perfil" aria-labelledby="tab-perfil" aria-hidden="false">
          <div class="showcase-display">
            <div class="showcase-content-grid">
              <div class="showcase-narrative">
                <h3>Visão longitudinal completa em uma única tela.</h3>
                <p>
                  Acesse em segundos o histórico antropométrico, anamnese inicial, evolução de medidas e prontuário com trilha de integridade criptográfica SHA-256.
                </p>
                <div style="display: flex; flex-direction: column; gap: 8px;">
                  <div style="display: flex; align-items: center; gap: 8px; font-size: 0.875rem; color: #1F2937;">
                    <span style="width: 8px; height: 8px; border-radius: 50%; background-color: #E67A3F;"></span>
                    <span>Gráficos evolutivos de peso, IMC e circunferências</span>
                  </div>
                  <div style="display: flex; align-items: center; gap: 8px; font-size: 0.875rem; color: #1F2937;">
                    <span style="width: 8px; height: 8px; border-radius: 50%; background-color: #E67A3F;"></span>
                    <span>Anamnese clínica estruturada e editável</span>
                  </div>
                  <div style="display: flex; align-items: center; gap: 8px; font-size: 0.875rem; color: #1F2937;">
                    <span style="width: 8px; height: 8px; border-radius: 50%; background-color: #E67A3F;"></span>
                    <span>Assinatura de integridade clínica em cada consulta</span>
                  </div>
                </div>
              </div>
              <div class="showcase-ui-frame">
                <!-- UI Preview Card do Perfil 360 -->
                <div style="width: 100%; max-width: 460px; background: #FFFFFF; border: 1px solid #E2E8F0; border-radius: 12px; box-shadow: 0 4px 6px -1px rgba(0,0,0,0.05); padding: 20px;">
                  <div style="display: flex; align-items: center; justify-content: space-between; border-bottom: 1px solid #F1F5F9; padding-bottom: 12px; margin-bottom: 16px;">
                    <div>
                      <h4 style="font-size: 1.125rem; font-weight: 700; color: #0F2747;">Mariana Souza</h4>
                      <p style="font-size: 0.75rem; color: #64748B;">29 anos · Hipertrofia & Composição Corporal</p>
                    </div>
                    <span style="background: #E8F5E9; color: #18794E; font-size: 0.6875rem; font-weight: 700; padding: 4px 8px; border-radius: 4px;">Ativa</span>
                  </div>
                  <div style="display: grid; grid-template-columns: repeat(3, 1fr); gap: 8px; margin-bottom: 16px;">
                    <div style="background: #F8FAFC; padding: 8px; border-radius: 6px; text-align: center;">
                      <span style="font-size: 0.6875rem; color: #64748B; display: block;">Peso Atual</span>
                      <strong style="font-size: 0.9375rem; color: #0F2747;">62.4 kg</strong>
                    </div>
                    <div style="background: #F8FAFC; padding: 8px; border-radius: 6px; text-align: center;">
                      <span style="font-size: 0.6875rem; color: #64748B; display: block;">Evolução</span>
                      <strong style="font-size: 0.9375rem; color: #18794E;">-2.1 kg</strong>
                    </div>
                    <div style="background: #F8FAFC; padding: 8px; border-radius: 6px; text-align: center;">
                      <span style="font-size: 0.6875rem; color: #64748B; display: block;">IMC</span>
                      <strong style="font-size: 0.9375rem; color: #0F2747;">22.4</strong>
                    </div>
                  </div>
                  <!-- Simulação de Evolução Gráfica -->
                  <div style="background: #F8FAFC; border-radius: 8px; padding: 12px; margin-bottom: 12px;">
                    <span style="font-size: 0.75rem; font-weight: 600; color: #0F2747; display: block; margin-bottom: 8px;">Curva Antropométrica (Últimos 3 meses)</span>
                    <div style="display: flex; align-items: flex-end; gap: 8px; height: 60px; padding-top: 8px;">
                      <div style="flex: 1; background: #CBD5E1; height: 85%; border-radius: 4px; position: relative;"><span style="position: absolute; top: -16px; font-size: 0.625rem; left: 2px;">64.5</span></div>
                      <div style="flex: 1; background: #94A3B8; height: 75%; border-radius: 4px; position: relative;"><span style="position: absolute; top: -16px; font-size: 0.625rem; left: 2px;">63.2</span></div>
                      <div style="flex: 1; background: #E67A3F; height: 65%; border-radius: 4px; position: relative;"><span style="position: absolute; top: -16px; font-size: 0.625rem; left: 2px; font-weight: bold; color: #B94F1F;">62.4</span></div>
                    </div>
                  </div>
                  <div style="display: flex; align-items: center; justify-content: space-between; font-size: 0.6875rem; color: #64748B;">
                    <span>Hash SHA-256 de integridade:</span>
                    <code>e3b0c442...98b7</code>
                  </div>
                </div>
              </div>
            </div>
          </div>
        </div>

        <!-- Painel 2: Prescrição TACO -->
        <div class="showcase-panel" role="tabpanel" id="panel-prescricao" aria-labelledby="tab-prescricao" aria-hidden="true">
          <div class="showcase-display">
            <div class="showcase-content-grid">
              <div class="showcase-narrative">
                <h3>Prescrição ágil com a base canônica TACO / UNICAMP.</h3>
                <p>
                  Busque alimentos catalogados com rigor científico. Digite a porção em gramas e veja o valor energético total e a distribuição de macronutrientes responderem em tempo real.
                </p>
                <div style="display: flex; flex-direction: column; gap: 8px;">
                  <div style="display: flex; align-items: center; gap: 8px; font-size: 0.875rem; color: #1F2937;">
                    <span style="width: 8px; height: 8px; border-radius: 50%; background-color: #E67A3F;"></span>
                    <span>Tabela Oficial com mais de 500 itens validados</span>
                  </div>
                  <div style="display: flex; align-items: center; gap: 8px; font-size: 0.875rem; color: #1F2937;">
                    <span style="width: 8px; height: 8px; border-radius: 50%; background-color: #E67A3F;"></span>
                    <span>Agrupamento por horários e nomes de refeições</span>
                  </div>
                  <div style="display: flex; align-items: center; gap: 8px; font-size: 0.875rem; color: #1F2937;">
                    <span style="width: 8px; height: 8px; border-radius: 50%; background-color: #E67A3F;"></span>
                    <span>Substitutos equivalentes com cálculo instantâneo</span>
                  </div>
                </div>
              </div>
              <div class="showcase-ui-frame">
                <!-- UI Preview da Prescrição -->
                <div style="width: 100%; max-width: 460px; background: #FFFFFF; border: 1px solid #E2E8F0; border-radius: 12px; box-shadow: 0 4px 6px -1px rgba(0,0,0,0.05); padding: 20px;">
                  <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 12px;">
                    <div>
                      <h4 style="font-size: 1rem; font-weight: 700; color: #0F2747;">Almoço · 12:30</h4>
                      <p style="font-size: 0.6875rem; color: #64748B;">Meta da Refeição: ~580 kcal</p>
                    </div>
                    <span style="font-size: 0.75rem; background: #FFF4ED; color: #B94F1F; font-weight: 700; padding: 4px 8px; border-radius: 4px;">568 kcal calculadas</span>
                  </div>
                  <div style="display: flex; flex-direction: column; gap: 8px; margin-bottom: 16px;">
                    <div style="display: flex; justify-content: space-between; align-items: center; padding: 8px 10px; background: #F8FAFC; border-radius: 6px; font-size: 0.8125rem;">
                      <div>
                        <strong>Arroz integral cozido</strong> <span style="color: #64748B;">(150g)</span>
                      </div>
                      <span style="color: #0F2747; font-weight: 600;">186 kcal</span>
                    </div>
                    <div style="display: flex; justify-content: space-between; align-items: center; padding: 8px 10px; background: #F8FAFC; border-radius: 6px; font-size: 0.8125rem;">
                      <div>
                        <strong>Feijão preto cozido</strong> <span style="color: #64748B;">(100g)</span>
                      </div>
                      <span style="color: #0F2747; font-weight: 600;">77 kcal</span>
                    </div>
                    <div style="display: flex; justify-content: space-between; align-items: center; padding: 8px 10px; background: #F8FAFC; border-radius: 6px; font-size: 0.8125rem;">
                      <div>
                        <strong>Filé de frango grelhado</strong> <span style="color: #64748B;">(120g)</span>
                      </div>
                      <span style="color: #0F2747; font-weight: 600;">191 kcal</span>
                    </div>
                    <div style="display: flex; justify-content: space-between; align-items: center; padding: 8px 10px; background: #F8FAFC; border-radius: 6px; font-size: 0.8125rem;">
                      <div>
                        <strong>Azeite de oliva extra virgem</strong> <span style="color: #64748B;">(10ml)</span>
                      </div>
                      <span style="color: #0F2747; font-weight: 600;">88 kcal</span>
                    </div>
                  </div>
                  <div style="border-top: 1px solid #F1F5F9; padding-top: 10px; display: flex; justify-content: space-between; font-size: 0.75rem;">
                    <span>PTN: <strong>38.4g</strong></span>
                    <span>CHO: <strong>44.2g</strong></span>
                    <span>LIP: <strong>14.6g</strong></span>
                  </div>
                </div>
              </div>
            </div>
          </div>
        </div>

        <!-- Painel 3: Agenda & Consultório -->
        <div class="showcase-panel" role="tabpanel" id="panel-agenda" aria-labelledby="tab-agenda" aria-hidden="true">
          <div class="showcase-display">
            <div class="showcase-content-grid">
              <div class="showcase-narrative">
                <h3>Controle do consultório na palma da sua mão.</h3>
                <p>
                  Gerencie horários de atendimento, confirmações de presença e fluxo de recebimentos sem precisar de um software financeiro paralelo ou planilhas desorganizadas.
                </p>
                <div style="display: flex; flex-direction: column; gap: 8px;">
                  <div style="display: flex; align-items: center; gap: 8px; font-size: 0.875rem; color: #1F2937;">
                    <span style="width: 8px; height: 8px; border-radius: 50%; background-color: #E67A3F;"></span>
                    <span>Visão diária, semanal e mensal de compromissos</span>
                  </div>
                  <div style="display: flex; align-items: center; gap: 8px; font-size: 0.875rem; color: #1F2937;">
                    <span style="width: 8px; height: 8px; border-radius: 50%; background-color: #E67A3F;"></span>
                    <span>Status de confirmação em tempo real</span>
                  </div>
                  <div style="display: flex; align-items: center; gap: 8px; font-size: 0.875rem; color: #1F2937;">
                    <span style="width: 8px; height: 8px; border-radius: 50%; background-color: #E67A3F;"></span>
                    <span>Controle de recebíveis e histórico financeiro</span>
                  </div>
                </div>
              </div>
              <div class="showcase-ui-frame">
                <!-- UI Preview da Agenda -->
                <div style="width: 100%; max-width: 460px; background: #FFFFFF; border: 1px solid #E2E8F0; border-radius: 12px; box-shadow: 0 4px 6px -1px rgba(0,0,0,0.05); padding: 20px;">
                  <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 16px;">
                    <div>
                      <h4 style="font-size: 1rem; font-weight: 700; color: #0F2747;">Quinta-feira, 08 de Outubro</h4>
                      <p style="font-size: 0.75rem; color: #64748B;">4 atendimentos agendados</p>
                    </div>
                    <span style="font-size: 0.75rem; background: #E8F5E9; color: #18794E; font-weight: 600; padding: 4px 8px; border-radius: 4px;">R$ 1.200 Previsto</span>
                  </div>
                  <div style="display: flex; flex-direction: column; gap: 10px;">
                    <div style="display: flex; align-items: center; gap: 12px; padding: 10px; background: #F8FAFC; border-radius: 8px; border-left: 4px solid #18794E;">
                      <span style="font-size: 0.8125rem; font-weight: 700; color: #0F2747;">09:00</span>
                      <div style="flex: 1;">
                        <p style="font-size: 0.8125rem; font-weight: 600; color: #0F2747;">Mariana Souza</p>
                        <p style="font-size: 0.6875rem; color: #64748B;">Consulta Retorno · R$ 250</p>
                      </div>
                      <span style="font-size: 0.6875rem; background: #E8F5E9; color: #18794E; padding: 2px 6px; border-radius: 4px;">Concluída</span>
                    </div>
                    <div style="display: flex; align-items: center; gap: 12px; padding: 10px; background: #F8FAFC; border-radius: 8px; border-left: 4px solid #E67A3F;">
                      <span style="font-size: 0.8125rem; font-weight: 700; color: #0F2747;">10:30</span>
                      <div style="flex: 1;">
                        <p style="font-size: 0.8125rem; font-weight: 600; color: #0F2747;">Carlos Eduardo</p>
                        <p style="font-size: 0.6875rem; color: #64748B;">Primeira Consulta · R$ 350</p>
                      </div>
                      <span style="font-size: 0.6875rem; background: #FFF4ED; color: #B94F1F; padding: 2px 6px; border-radius: 4px;">Em Atendimento</span>
                    </div>
                    <div style="display: flex; align-items: center; gap: 12px; padding: 10px; background: #F8FAFC; border-radius: 8px; border-left: 4px solid #64748B;">
                      <span style="font-size: 0.8125rem; font-weight: 700; color: #0F2747;">14:00</span>
                      <div style="flex: 1;">
                        <p style="font-size: 0.8125rem; font-weight: 600; color: #0F2747;">Beatriz Lima</p>
                        <p style="font-size: 0.6875rem; color: #64748B;">Ajuste Nutricional · R$ 300</p>
                      </div>
                      <span style="font-size: 0.6875rem; background: #F1F5F9; color: #475569; padding: 2px 6px; border-radius: 4px;">Confirmada</span>
                    </div>
                  </div>
                </div>
              </div>
            </div>
          </div>
        </div>

        <!-- Painel 4: App do Paciente -->
        <div class="showcase-panel" role="tabpanel" id="panel-app" aria-labelledby="tab-app" aria-hidden="true">
          <div class="showcase-display">
            <div class="showcase-content-grid">
              <div class="showcase-narrative">
                <h3>O cuidado nutricional na rotina diária do paciente.</h3>
                <p>
                  O paciente recebe o plano diretamente no smartphone, visualiza os horários das refeições prescritas, registra sua ingestão hídrica e acompanha sua própria evolução.
                </p>
                <div style="display: flex; flex-direction: column; gap: 8px;">
                  <div style="display: flex; align-items: center; gap: 8px; font-size: 0.875rem; color: #1F2937;">
                    <span style="width: 8px; height: 8px; border-radius: 50%; background-color: #E67A3F;"></span>
                    <span>Acesso autenticado e seguro ao plano do dia</span>
                  </div>
                  <div style="display: flex; align-items: center; gap: 8px; font-size: 0.875rem; color: #1F2937;">
                    <span style="width: 8px; height: 8px; border-radius: 50%; background-color: #E67A3F;"></span>
                    <span>Rastreador de hidratação com meta personalizada</span>
                  </div>
                  <div style="display: flex; align-items: center; gap: 8px; font-size: 0.875rem; color: #1F2937;">
                    <span style="width: 8px; height: 8px; border-radius: 50%; background-color: #E67A3F;"></span>
                    <span>Checklist de refeições para engajamento contínuo</span>
                  </div>
                </div>
              </div>
              <div class="showcase-ui-frame">
                <!-- UI Preview Móvel do Paciente -->
                <div style="width: 100%; max-width: 320px; background: #FFFFFF; border: 4px solid #0F2747; border-radius: 24px; box-shadow: 0 10px 25px -5px rgba(0,0,0,0.1); padding: 16px;">
                  <div style="text-align: center; border-bottom: 1px solid #F1F5F9; padding-bottom: 10px; margin-bottom: 12px;">
                    <p style="font-size: 0.6875rem; color: #64748B;">ClyoNutri Paciente</p>
                    <p style="font-size: 0.875rem; font-weight: 700; color: #0F2747;">Olá, Mariana!</p>
                  </div>
                  <!-- Card Hidratação -->
                  <div style="background: #EBF3FA; border-radius: 8px; padding: 10px; margin-bottom: 12px;">
                    <div style="display: flex; justify-content: space-between; font-size: 0.75rem; font-weight: 600; color: #0F2747; margin-bottom: 6px;">
                      <span>Água ingerida</span>
                      <span>1.750 / 2.500 ml</span>
                    </div>
                    <div style="background: #CBD5E1; height: 6px; border-radius: 3px; overflow: hidden;">
                      <div style="background: #175CD3; width: 70%; height: 100%;"></div>
                    </div>
                  </div>
                  <!-- Próxima Refeição -->
                  <div style="background: #FAF7F2; border: 1px solid #E5E7EB; border-radius: 8px; padding: 10px;">
                    <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 4px;">
                      <span style="font-size: 0.75rem; font-weight: 700; color: #0F2747;">Lanche da Tarde</span>
                      <span style="font-size: 0.6875rem; color: #E67A3F; font-weight: 600;">16:00</span>
                    </div>
                    <p style="font-size: 0.75rem; color: #475569; line-height: 1.4;">1 Iogurte natural desnatado (160g) + 20g castanhas-do-pará</p>
                  </div>
                </div>
              </div>
            </div>
          </div>
        </div>
      </div>
    </section>

    <!-- =========================================================================
         6. EXPERIÊNCIA DO PACIENTE
         ========================================================================= -->
    <section class="section-padding" id="experiencia-paciente" aria-labelledby="patient-exp-title">
      <div class="container">
        <div class="patient-experience-grid">
          <div>
            <span class="section-tag">Engajamento Clínico</span>
            <h2 id="patient-exp-title" class="section-title">
              O plano nutricional que o seu paciente realmente consegue seguir.
            </h2>
            <p class="section-subtitle">
              Adesão não é apenas força de vontade: é facilidade de acesso. Quando o paciente tem o plano claro no bolso, a taxa de sucesso da intervenção nutricional se multiplica.
            </p>

            <div style="margin-top: 32px; display: flex; flex-direction: column; gap: 16px;">
              <div style="display: flex; gap: 14px; align-items: flex-start;">
                <div style="width: 28px; height: 28px; border-radius: 50%; background: #E8F5E9; color: #18794E; display: flex; align-items: center; justify-content: center; flex-shrink: 0;"><svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><polyline points="20 6 9 17 4 12"/></svg></div>
                <div>
                  <h4 style="font-size: 1.0625rem; font-weight: 600; color: #0F2747; margin-bottom: 2px;">Substituições claras e sem ansiedade</h4>
                  <p style="font-size: 0.9375rem; color: #64748B;">O paciente sabe exatamente o que pode trocar caso não encontre um ingrediente em casa.</p>
                </div>
              </div>

              <div style="display: flex; gap: 14px; align-items: flex-start;">
                <div style="width: 28px; height: 28px; border-radius: 50%; background: #E8F5E9; color: #18794E; display: flex; align-items: center; justify-content: center; flex-shrink: 0;"><svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><polyline points="20 6 9 17 4 12"/></svg></div>
                <div>
                  <h4 style="font-size: 1.0625rem; font-weight: 600; color: #0F2747; margin-bottom: 2px;">Lembrete de hidratação sem apps adicionais</h4>
                  <p style="font-size: 0.9375rem; color: #64748B;">Meta calculada de acordo com o peso corporal e registro intuitivo com um toque.</p>
                </div>
              </div>

              <div style="display: flex; gap: 14px; align-items: flex-start;">
                <div style="width: 28px; height: 28px; border-radius: 50%; background: #E8F5E9; color: #18794E; display: flex; align-items: center; justify-content: center; flex-shrink: 0;"><svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><polyline points="20 6 9 17 4 12"/></svg></div>
                <div>
                  <h4 style="font-size: 1.0625rem; font-weight: 600; color: #0F2747; margin-bottom: 2px;">Retorno motivado e com dados reais</h4>
                  <p style="font-size: 0.9375rem; color: #64748B;">Na consulta seguinte, você sabe exatamente o que foi cumprido em vez de depender de lembranças vagas.</p>
                </div>
              </div>
            </div>
          </div>

          <!-- Mockup Fiel do App -->
          <div class="app-screen-mockup" role="img" aria-label="Simulação do App do Paciente no Smartphone">
            <div class="app-screen-header">
              <div class="app-screen-notch"></div>
            </div>
            <div style="padding: 10px;">
              <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 16px;">
                <span style="font-weight: 700; font-size: 0.875rem; color: #0F2747;">ClyoNutri Mobile</span>
                <span style="font-size: 0.6875rem; background: #FFF4ED; color: #B94F1F; padding: 2px 6px; border-radius: 4px; font-weight: 600;">Beta</span>
              </div>
              <div style="background: #FFFFFF; border-radius: 12px; padding: 14px; border: 1px solid #E2E8F0; margin-bottom: 12px;">
                <p style="font-size: 0.75rem; color: #64748B; margin-bottom: 2px;">Prescrito por:</p>
                <p style="font-size: 0.875rem; font-weight: 700; color: #0F2747;">Dra. Camila Mendes</p>
              </div>
              <div style="background: #FFFFFF; border-radius: 12px; padding: 14px; border: 1px solid #E2E8F0; margin-bottom: 12px;">
                <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 6px;">
                  <span style="font-size: 0.75rem; font-weight: 700; color: #0F2747;">Meta de Água</span>
                  <span style="font-size: 0.75rem; color: #175CD3; font-weight: 700;">2.000 / 2.500 ml</span>
                </div>
                <div style="background: #E2E8F0; height: 8px; border-radius: 4px; overflow: hidden; margin-bottom: 8px;">
                  <div style="background: #175CD3; width: 80%; height: 100%;"></div>
                </div>
                <button type="button" style="width: 100%; background: #EBF3FA; border: none; color: #0F2747; font-size: 0.75rem; font-weight: 600; padding: 6px; border-radius: 6px; cursor: pointer;">
                  + 250 ml (Registrar Copo)
                </button>
              </div>
              <div style="background: #FFFFFF; border-radius: 12px; padding: 14px; border: 1px solid #E2E8F0;">
                <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 4px;">
                  <span style="font-size: 0.8125rem; font-weight: 700; color: #0F2747;">Café da Manhã</span>
                  <span style="font-size: 0.6875rem; color: #18794E; font-weight: 600; display: inline-flex; align-items: center; gap: 4px;"><svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><polyline points="20 6 9 17 4 12"/></svg> Concluído</span>
                </div>
                <p style="font-size: 0.6875rem; color: #64748B;">2 ovos mexidos (100g) · 1 fatia pão integral (50g) · 1 fatia mamão (120g)</p>
              </div>
            </div>
          </div>
        </div>
      </div>
    </section>

    <!-- =========================================================================
         7. COMO FUNCIONA (3 PASSOS)
         ========================================================================= -->
    <section class="section-padding" id="como-funciona" style="background-color: var(--neutral-white);" aria-labelledby="steps-title">
      <div class="container">
        <div style="text-align: center;">
          <span class="section-tag">Fluxo de Trabalho</span>
          <h2 id="steps-title" class="section-title">
            Do primeiro cadastro à evolução contínua em 3 passos.
          </h2>
          <p class="section-subtitle" style="margin-left: auto; margin-right: auto;">
            Sem curvas de aprendizado complicadas. A plataforma foi construída para você começar a atender no mesmo dia.
          </p>
        </div>

        <div class="steps-grid">
          <article class="step-card">
            <div class="step-number" aria-hidden="true">1</div>
            <h3 class="step-title">Configure seu consultório</h3>
            <p class="step-desc">
              Crie sua conta em menos de 2 minutos. Insira seus dados profissionais (CRN, endereço de atendimento e preferências) e acesse imediatamente o painel seguro.
            </p>
          </article>

          <article class="step-card">
            <div class="step-number" aria-hidden="true">2</div>
            <h3 class="step-title">Atenda e prescreva</h3>
            <p class="step-desc">
              Cadastre seu paciente, registre a anamnese e utilize o banco TACO para prescrever dietas personalizadas com cálculos automáticos de energia e macronutrientes.
            </p>
          </article>

          <article class="step-card">
            <div class="step-number" aria-hidden="true">3</div>
            <h3 class="step-title">Acompanhe a evolução</h3>
            <p class="step-desc">
              Libere o acesso ao aplicativo móvel do paciente, acompanhe o engajamento diário e mantenha a linha do tempo clínica com proteção de integridade criptográfica.
            </p>
          </article>
        </div>
      </div>
    </section>

    <!-- =========================================================================
         8. DIFERENCIAIS TÉCNICOS E DE SEGURANÇA
         ========================================================================= -->
    <section class="section-padding" id="diferenciais" aria-labelledby="diff-title">
      <div class="container">
        <span class="section-tag">Engenharia e Confiabilidade</span>
        <h2 id="diff-title" class="section-title">
          Construído sobre fundamentos científicos e padrões de segurança de alto nível.
        </h2>
        <p class="section-subtitle">
          Nada de aproximações amadoras. O ClyoNutri II combina rigor biomédico com as melhores práticas de engenharia de software e privacidade.
        </p>

        <div class="diff-grid">
          <!-- Diferencial 1 -->
          <article class="diff-card">
            <div class="diff-card__icon" aria-hidden="true">
              <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="M12 2v20M17 5H9.5a3.5 3.5 0 0 0 0 7h5a3.5 3.5 0 0 1 0 7H6"/></svg>
            </div>
            <h3 class="diff-card__title">Base Canônica TACO / UNICAMP</h3>
            <p class="diff-card__desc">
              Diga adeus a alimentos com calorias fictícias. Todas as informações nutricionais seguem os padrões de 100g da Tabela Brasileira de Composição de Alimentos, validados por centros de pesquisa federais.
            </p>
          </article>

          <!-- Diferencial 2 -->
          <article class="diff-card">
            <div class="diff-card__icon" aria-hidden="true">
              <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/></svg>
            </div>
            <h3 class="diff-card__title">Integridade com Hash SHA-256</h3>
            <p class="diff-card__desc">
              Cada anotação no prontuário do paciente gera um carimbo criptográfico imutável. Garantia plena de autoria e inviolabilidade histórica para sua segurança jurídica profissional.
            </p>
          </article>

          <!-- Diferencial 3 -->
          <article class="diff-card">
            <div class="diff-card__icon" aria-hidden="true">
              <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><rect x="3" y="11" width="18" height="11" rx="2" ry="2"/><path d="M7 11V7a5 5 0 0 1 10 0v4"/></svg>
            </div>
            <h3 class="diff-card__title">Isolamento Multitenant & LGPD</h3>
            <p class="diff-card__desc">
              Os dados dos seus pacientes pertencem unicamente a você. Nossa arquitetura garante separação lógica rigorosa entre clínicas, senhas criptografadas com Bcrypt e trânsito 100% seguro em TLS.
            </p>
          </article>
        </div>
      </div>
    </section>

    <!-- =========================================================================
         9. FILOSOFIA & MANIFESTO CLÍNICO
         ========================================================================= -->
    <section class="container" aria-label="Manifesto clínico">
      <div class="philosophy-box">
        <blockquote class="philosophy-quote">
          «Acreditamos que a tecnologia deve ser invisível na consulta: ela deve trabalhar em silêncio para que a empatia, a escuta ativa e o julgamento clínico do nutricionista ocupem o centro do palco.»
        </blockquote>
        <p class="philosophy-author">
          Princípio Editorial ClyoNutri II · Precision Meets Care
        </p>
      </div>
    </section>

    <!-- =========================================================================
         10. PREÇOS / TESTE TRANSPARENTE
         ========================================================================= -->
    <section class="section-padding" id="precos" aria-labelledby="pricing-title">
      <div class="container">
        <div style="text-align: center;">
          <span class="section-tag">Acesso Transparente</span>
          <h2 id="pricing-title" class="section-title">
            Experimente sem amarras. Decida com base em resultados.
          </h2>
          <p class="section-subtitle" style="margin-left: auto; margin-right: auto;">
            Acesso integral a todas as ferramentas disponíveis durante 14 dias para você comprovar a evolução do seu consultório na prática.
          </p>
        </div>

        <div class="pricing-card-wrapper">
          <div class="pricing-card">
            <span class="pricing-badge">Período de Avaliação Completo</span>
            <h3 class="pricing-title">14 Dias de Acesso Irrestrito</h3>
            <div style="margin-bottom: 20px;">
              <span style="font-size: 2.5rem; font-weight: 700; color: #0F2747;">R$ 0</span>
              <span style="font-size: 1rem; color: #64748B;"> / durante o período de testes</span>
            </div>
            <p class="pricing-desc">
              Sem necessidade de dados de cartão de crédito. Você cria sua conta, atende seus pacientes e testa todas as ferramentas em ambiente de produção real.
            </p>

            <ul class="pricing-features">
              <li>
                <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><polyline points="20 6 9 17 4 12"/></svg>
                <span>Cadastro e gestão de pacientes sem limites</span>
              </li>
              <li>
                <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><polyline points="20 6 9 17 4 12"/></svg>
                <span>Prescrição de planos alimentares com base TACO oficial</span>
              </li>
              <li>
                <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><polyline points="20 6 9 17 4 12"/></svg>
                <span>Prontuário eletrônico com integridade criptográfica</span>
              </li>
              <li>
                <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><polyline points="20 6 9 17 4 12"/></svg>
                <span>Agenda sincronizada e controle financeiro integrado</span>
              </li>
              <li>
                <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><polyline points="20 6 9 17 4 12"/></svg>
                <span>Aplicativo do paciente disponível (Beta)</span>
              </li>
              <li>
                <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><polyline points="20 6 9 17 4 12"/></svg>
                <span>Suporte técnico e exportação total de dados a qualquer momento</span>
              </li>
            </ul>

            <a href="/cadastro" class="btn-primary" style="width: 100%; max-width: 360px; font-size: 1.0625rem; padding: 14px 28px;">
              Criar Minha Conta Gratuita
            </a>
            <p style="font-size: 0.8125rem; color: #64748B; margin-top: 12px;">
              Sem pegadinhas. Cancele ou exporte seus dados quando quiser.
            </p>
          </div>
        </div>
      </div>
    </section>

    <!-- =========================================================================
         11. FAQ ACCORDION ACESSÍVEL
         ========================================================================= -->
    <section class="section-padding" id="faq" style="background-color: var(--neutral-white);" aria-labelledby="faq-title">
      <div class="container">
        <div style="text-align: center;">
          <span class="section-tag">Perguntas Frequentes</span>
          <h2 id="faq-title" class="section-title">
            Tudo o que você precisa saber antes de começar.
          </h2>
          <p class="section-subtitle" style="margin-left: auto; margin-right: auto;">
            Respostas diretas e transparentes sobre a plataforma e seu funcionamento.
          </p>
        </div>

        <div class="faq-list" role="region" aria-label="Perguntas Frequentes sobre o ClyoNutri II">
          <!-- FAQ 1 -->
          <div class="faq-item">
            <button type="button" class="faq-trigger" aria-expanded="false" aria-controls="faq-ans-1" id="faq-btn-1">
              <span>Preciso cadastrar cartão de crédito para iniciar o teste?</span>
              <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><polyline points="6 9 12 15 18 9"/></svg>
            </button>
            <div class="faq-content" id="faq-ans-1" role="region" aria-labelledby="faq-btn-1">
              Não. O cadastro de teste é 100% livre de cartão de crédito. Você cria seu usuário com e-mail, nome e dados de atendimento e tem 14 dias para explorar todos os recursos em seu consultório real.
            </div>
          </div>

          <!-- FAQ 2 -->
          <div class="faq-item">
            <button type="button" class="faq-trigger" aria-expanded="false" aria-controls="faq-ans-2" id="faq-btn-2">
              <span>Como funciona o cálculo nutricional das refeições?</span>
              <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><polyline points="6 9 12 15 18 9"/></svg>
            </button>
            <div class="faq-content" id="faq-ans-2" role="region" aria-labelledby="faq-btn-2">
              Utilizamos a Tabela Brasileira de Composição de Alimentos (TACO/UNICAMP). O cálculo é realizado proporcionalmente à porção em gramas que você definir, totalizando energia (kcal), proteínas, carboidratos e lipídeos na hora, tanto por alimento quanto por refeição e no plano completo.
            </div>
          </div>

          <!-- FAQ 3 -->
          <div class="faq-item">
            <button type="button" class="faq-trigger" aria-expanded="false" aria-controls="faq-ans-3" id="faq-btn-3">
              <span>Os dados dos meus pacientes estão em conformidade com a LGPD?</span>
              <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><polyline points="6 9 12 15 18 9"/></svg>
            </button>
            <div class="faq-content" id="faq-ans-3" role="region" aria-labelledby="faq-btn-3">
              Sim. A privacidade é estrutural na plataforma: cada clínica tem seu próprio espaço de dados isolado no banco (multitenant), prevenindo qualquer acesso cruzado. Além disso, as comunicações são criptografadas e mantemos trilha imutável SHA-256 para comprovar a integridade dos registros.
            </div>
          </div>

          <!-- FAQ 4 -->
          <div class="faq-item">
            <button type="button" class="faq-trigger" aria-expanded="false" aria-controls="faq-ans-4" id="faq-btn-4">
              <span>O paciente paga alguma coisa para usar o aplicativo?</span>
              <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><polyline points="6 9 12 15 18 9"/></svg>
            </button>
            <div class="faq-content" id="faq-ans-4" role="region" aria-labelledby="faq-btn-4">
              Não. O aplicativo móvel é totalmente gratuito para os pacientes atendidos pelos nutricionistas cadastrados no ClyoNutri II. O acesso ao plano é concedido diretamente por você.
            </div>
          </div>

          <!-- FAQ 5 -->
          <div class="faq-item">
            <button type="button" class="faq-trigger" aria-expanded="false" aria-controls="faq-ans-5" id="faq-btn-5">
              <span>Posso exportar meus dados se decidir sair da plataforma?</span>
              <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><polyline points="6 9 12 15 18 9"/></svg>
            </button>
            <div class="faq-content" id="faq-ans-5" role="region" aria-labelledby="faq-btn-5">
              Sim. Garantimos o princípio da portabilidade de dados. Todas as informações clínicas e cadastrais são de sua titularidade e podem ser exportadas a qualquer momento.
            </div>
          </div>

          <!-- FAQ 6 -->
          <div class="faq-item">
            <button type="button" class="faq-trigger" aria-expanded="false" aria-controls="faq-ans-6" id="faq-btn-6">
              <span>O sistema funciona no computador, tablet e celular?</span>
              <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><polyline points="6 9 12 15 18 9"/></svg>
            </button>
            <div class="faq-content" id="faq-ans-6" role="region" aria-labelledby="faq-btn-6">
              Sim. O ClyoNutri II foi projetado com design totalmente responsivo, funcionando perfeitamente em telas amplas de consultório (desktops e notebooks), tablets para atendimento móvel e smartphones.
            </div>
          </div>
        </div>
      </div>
    </section>

    <!-- =========================================================================
         12. CTA FINAL DE ALTA CONVERSÃO
         ========================================================================= -->
    <section class="container" aria-labelledby="final-cta-title">
      <div class="final-cta">
        <h2 id="final-cta-title" class="final-cta__title">
          Mais tempo para cuidar. Mais clareza para evoluir.
        </h2>
        <p class="final-cta__desc">
          Junte-se a nutricionistas que transformaram a rotina do consultório com precisão científica, processos organizados e design de excelência.
        </p>
        <div style="display: flex; justify-content: center; gap: 16px; flex-wrap: wrap;">
          <?php if (!empty($isLoggedIn)): ?>
            <a href="/dashboard" class="btn-primary" style="font-size: 1.0625rem; padding: 14px 32px;">
              Acessar Meu Dashboard
            </a>
          <?php else: ?>
            <a href="/cadastro" class="btn-primary" style="font-size: 1.0625rem; padding: 14px 32px;">
              Começar Agora Gratuitamente
            </a>
            <a href="/login" class="btn-secondary" style="color: #FFFFFF; border-color: rgba(255,255,255,0.4); font-size: 1.0625rem; padding: 14px 28px;">
              Já tenho conta · Entrar
            </a>
          <?php endif; ?>
        </div>
        <p style="font-size: 0.8125rem; color: #94A3B8; margin-top: 16px;">
          Teste completo por 14 dias · Sem necessidade de cartão
        </p>
      </div>
    </section>
  </main>

  <!-- =========================================================================
       13. RODAPÉ INSTITUCIONAL
       ========================================================================= -->
  <footer class="footer" role="contentinfo">
    <div class="container">
      <div class="footer-grid">
        <!-- Coluna 1: Marca & Manifesto -->
        <div class="footer-col">
          <a href="/" class="logo" style="margin-bottom: 12px; display: inline-flex;" aria-label="ClyoNutri Página Inicial">
            <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
              <path d="M12 2L2 7l10 5 10-5-10-5zM2 17l10 5 10-5M2 12l10 5 10-5"/>
            </svg>
            <span><span class="logo__brand-clyo">Clyo</span>Nutri <small style="font-size: 0.65em; font-weight: 500; color: var(--neutral-gray-500);">II</small></span>
          </a>
          <p style="font-size: 0.875rem; color: #64748B; line-height: 1.6; max-width: 320px; margin-bottom: 16px;">
            O sistema operacional do nutricionista clínico. Unindo a precisão da ciência à sensibilidade do cuidado humanizado.
          </p>
          <div style="display: inline-flex; align-items: center; gap: 6px; font-size: 0.75rem; color: #18794E; background: #E8F5E9; padding: 4px 8px; border-radius: 4px;">
            <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/></svg>
            <span>Conformidade estrita com a LGPD</span>
          </div>
        </div>

        <!-- Coluna 2: Produto -->
        <div class="footer-col">
          <h4>Produto</h4>
          <ul class="footer-links">
            <li><a href="#produto">Perfil 360 do Paciente</a></li>
            <li><a href="#produto">Prescrição Canônica TACO</a></li>
            <li><a href="#produto">Agenda & Consultório</a></li>
            <li><a href="#experiencia-paciente">App do Paciente (Beta)</a></li>
            <li><a href="/up" target="_blank" rel="noopener">Status do Sistema (/up)</a></li>
          </ul>
        </div>

        <!-- Coluna 3: Governança & Legal -->
        <div class="footer-col">
          <h4>Legal & Ética</h4>
          <ul class="footer-links">
            <li><a href="/termos">Termos de Uso</a></li>
            <li><a href="/privacidade">Política de Privacidade</a></li>
            <li><a href="/privacidade#seguranca">Governança LGPD</a></li>
            <li><a href="/termos#etica">Código de Ética CFN</a></li>
          </ul>
        </div>

        <!-- Coluna 4: Acesso -->
        <div class="footer-col">
          <h4>Acesso</h4>
          <ul class="footer-links">
            <li><a href="/cadastro">Criar Conta Gratuita</a></li>
            <li><a href="/login">Acessar Plataforma</a></li>
            <li><a href="/dashboard">Painel Clínico</a></li>
          </ul>
        </div>
      </div>

      <div class="footer-bottom">
        <p>© 2026 ClyoNutri II. Todos os direitos reservados. Feito com rigor para nutricionistas que cuidam com excelência.</p>
        <p>Versão 1.0.0 · Produção</p>
      </div>
    </div>
  </footer>

  <!-- =========================================================================
       SCRIPT DE INTERATIVIDADE LEVE & ACESSÍVEL (Vanilla JS)
       ========================================================================= -->
  <script>
    document.addEventListener('DOMContentLoaded', function() {
      // 1. Mobile Drawer Toggle
      var toggleBtn = document.getElementById('mobile-toggle-btn');
      var drawer = document.getElementById('mobile-drawer-menu');
      if (toggleBtn && drawer) {
        toggleBtn.addEventListener('click', function() {
          var isOpen = toggleBtn.getAttribute('aria-expanded') === 'true';
          toggleBtn.setAttribute('aria-expanded', !isOpen);
          drawer.setAttribute('data-open', !isOpen ? 'true' : 'false');
        });

        // Fechar ao clicar em links do menu móvel
        var mobileLinks = drawer.querySelectorAll('.mobile-nav-link, a');
        mobileLinks.forEach(function(link) {
          link.addEventListener('click', function() {
            toggleBtn.setAttribute('aria-expanded', 'false');
            drawer.setAttribute('data-open', 'false');
          });
        });
      }

      // 2. Showcase Accessible Tabs
      var tabs = document.querySelectorAll('.showcase-tab');
      var panels = document.querySelectorAll('.showcase-panel');

      function switchTab(newTab) {
        tabs.forEach(function(tab) {
          tab.setAttribute('aria-selected', 'false');
          tab.setAttribute('tabindex', '-1');
        });
        panels.forEach(function(panel) {
          panel.setAttribute('aria-hidden', 'true');
        });

        newTab.setAttribute('aria-selected', 'true');
        newTab.setAttribute('tabindex', '0');
        newTab.focus();

        var panelId = newTab.getAttribute('aria-controls');
        var targetPanel = document.getElementById(panelId);
        if (targetPanel) {
          targetPanel.setAttribute('aria-hidden', 'false');
        }
      }

      tabs.forEach(function(tab, index) {
        tab.addEventListener('click', function() {
          switchTab(tab);
        });

        // Navegação por teclado ARIA (setas)
        tab.addEventListener('keydown', function(e) {
          var targetIndex = -1;
          if (e.key === 'ArrowRight') {
            targetIndex = (index + 1) % tabs.length;
          } else if (e.key === 'ArrowLeft') {
            targetIndex = (index - 1 + tabs.length) % tabs.length;
          } else if (e.key === 'Home') {
            targetIndex = 0;
          } else if (e.key === 'End') {
            targetIndex = tabs.length - 1;
          }

          if (targetIndex !== -1) {
            e.preventDefault();
            switchTab(tabs[targetIndex]);
          }
        });
      });

      // 3. FAQ Accordion Toggle
      var faqButtons = document.querySelectorAll('.faq-trigger');
      faqButtons.forEach(function(btn) {
        btn.addEventListener('click', function() {
          var isExpanded = btn.getAttribute('aria-expanded') === 'true';
          var contentId = btn.getAttribute('aria-controls');
          var content = document.getElementById(contentId);

          btn.setAttribute('aria-expanded', !isExpanded);
          if (content) {
            content.setAttribute('data-open', !isExpanded ? 'true' : 'false');
          }
        });
      });
    });
  </script>
</body>
</html>
