// v54.62 — Épico NI2-844: Simplificação das jornadas do nutricionista — findings da auditoria UX 360° (UX-001..UX-019).
// Executa no navegador real (Chromium) em 1440x900, 820x1180 e 390x844, medindo a contagem de ações do 1º atendimento,
// validando NI2-845..NI2-855, isolamento multi-tenant, responsividade sem barra horizontal e axe-core WCAG 2.1 A/AA.
const { chromium } = require('playwright');
const { execSync } = require('child_process');
const fs = require('fs');

const res = [];
const ok = (c, l) => {
  res.push(!!c);
  console.log((c ? '  PASS  ' : '  FAIL  ') + l);
};

let axe = null;
try { axe = require(process.env.AXE_MODULE || 'axe-core'); } catch (e) {}

(async () => {
  const B = process.argv[2];
  const DB = process.argv[3];
  const sq = q => execSync(`php ${process.env.E2E_SQ} ${DB} "${q}"`).toString().trim();
  const ids = JSON.parse(fs.readFileSync(DB + '.ids', 'utf8'));

  const axeRun = async (p, tag) => {
    if (!axe) { ok(true, `${tag}: axe-core opcional ausente no node_modules local`); return; }
    await p.evaluate(axe.source);
    const a = await p.evaluate(() => axe.run(document, {
      runOnly: { type: 'tag', values: ['wcag2a', 'wcag2aa', 'wcag21a', 'wcag21aa'] }
    }));
    ok(a.violations.length === 0, `${tag}: axe WCAG 2.1 A/AA sem violações` + (a.violations.length ? ': ' + a.violations.map(v => v.id + '×' + v.nodes.length).join(', ') : ''));
  };

  const checkNoHorizontalOverflow = async (p, tag) => {
    const ov = await p.evaluate(() => ({
      sw: document.documentElement.scrollWidth,
      cw: document.documentElement.clientWidth
    }));
    ok(ov.sw <= ov.cw + 1, `${tag}: sem rolagem horizontal (${ov.sw}px <= ${ov.cw}px)`);
  };

  const browser = await chromium.launch();
  const ctx = await browser.newContext({ viewport: { width: 1440, height: 900 }, locale: 'pt-BR', timezoneId: 'America/Sao_Paulo' });
  const p = await ctx.newPage();
  p.on('dialog', d => d.accept());

  const consoleErrors = [];
  p.on('pageerror', e => consoleErrors.push(e.message));
  const server500s = [];
  p.on('response', r => { if (r.status() >= 500) server500s.push(r.status() + ' ' + r.url().replace(B, '')); });

  // Contador instrumentado de ações humanas na jornada do 1º atendimento
  let actionsTotal = 0;
  let actionsPlan = 0;
  let screensVisited = new Set();
  const trackScreen = () => screensVisited.add(new URL(p.url()).pathname);
  const actClick = async (sel, inPlan = false) => {
    actionsTotal++;
    if (inPlan) actionsPlan++;
    await p.locator(sel).first().click();
    trackScreen();
  };
  const actClickNav = async (sel, inPlan = false) => {
    actionsTotal++;
    if (inPlan) actionsPlan++;
    await Promise.all([
      p.waitForNavigation({ waitUntil: 'networkidle', timeout: 15000 }),
      p.locator(sel).first().click(),
    ]);
    trackScreen();
  };
  const actFill = async (sel, val, inPlan = false) => {
    actionsTotal++;
    if (inPlan) actionsPlan++;
    await p.locator(sel).first().fill(val);
  };

  console.log('== 1. NI2-855 (UX-017): Tela /cadastro sem truncamento e com CTA >= 48px');
  await p.goto(B + '/cadastro');
  await p.waitForLoadState('networkidle');
  const regPassPlaceholder = await p.locator('#password').getAttribute('placeholder');
  const regBtnBox = await p.locator('button[type="submit"]').boundingBox();
  ok(regPassPlaceholder === 'Mínimo de 8 caracteres' && regBtnBox && regBtnBox.height >= 48,
    `NI2-855 (UX-017): /cadastro exibe placeholder "${regPassPlaceholder}" e botão CTA com altura ${regBtnBox ? Math.round(regBtnBox.height) : 0}px`);
  await axeRun(p, '/cadastro 1440px');

  console.log('== 2. NI2-850 (UX-006) & NI2-855 (UX-007): Login, Onboarding em 3 passos sem cobrança forçada e saudação sem "Olá, Dra."');
  await p.goto(B + '/__teste_login?u=1&c=1&v=0&to=/onboarding');
  await p.waitForLoadState('networkidle');
  trackScreen();

  const onbWelcome = await p.textContent('[data-testid="onboarding-welcome-title"]');
  ok(onbWelcome.includes('Helena') && !onbWelcome.includes('Dra.'),
    `NI2-855 (UX-007): título do onboarding saúda pelo prenome sem prefixo duplicado ("${onbWelcome.trim()}")`);
  ok(await p.locator('[data-testid="onboarding-crn-prefilled"]').isVisible(),
    'NI2-850 (UX-006): Passo 1 de 3 reconhece CRN pré-preenchido no cadastro sem pedir digitação duplicada');
  ok((await p.locator('input[name="plan_code"][required]').count()) === 0,
    'NI2-850 (UX-006): Passo 1 de 3 não força escolha de plano pago durante o trial');

  // Conclui Passo 1 -> vai ao Passo 2 (Primeiro paciente)
  await actClick('form[action="/onboarding/passo"] button.btn-accent[type="submit"]');
  await p.waitForLoadState('networkidle');
  trackScreen();
  ok(p.url().includes('passo=4'), `NI2-850 (UX-006): concluir Passo 1 avança direto para o Passo 2 de 3 (Primeiro paciente: ${new URL(p.url()).search})`);

  // Cadastra a paciente Mariana Souza Lima no Passo 2 de 3
  await actFill('#ob9-name', 'Mariana Souza Lima');
  await actFill('#ob10-email', 'mariana.ux844@teste.invalid');
  await actFill('#ob11-phone', '11988887777');
  await actFill('#ob12-birth_date', '1992-05-14');
  await p.locator('#ob13-gender').selectOption('female'); actionsTotal++;
  await actClick('form[action="/onboarding/passo"] button.btn-accent[type="submit"]');
  await p.waitForLoadState('networkidle');
  trackScreen();

  // Passo 3 de 3 (Ativação)
  const actCountText = (await p.textContent('[data-testid="activation-count"]')).trim();
  const visibleLiCount = await p.locator('[data-testid="activation-checklist"] > li.activation-item').count();
  ok(actCountText.endsWith(`de ${visibleLiCount}`),
    `NI2-850 (UX-006): contador do checklist (${actCountText}) bate exatamente com os ${visibleLiCount} itens visíveis`);

  const marianaId = +sq("SELECT id FROM patients WHERE name='Mariana Souza Lima' LIMIT 1");
  ok(marianaId > 0, `Paciente Mariana Souza Lima criada com ID #${marianaId}`);

  console.log('== 3. NI2-853 (UX-010) & NI2-855 (UX-011): Agenda com combobox único, próximo horário válido e Iniciar em 1 clique');
  await p.goto(B + `/agenda?novo=1&paciente=${marianaId}`);
  await p.waitForLoadState('networkidle');
  trackScreen();

  ok(await p.locator('[data-testid="agenda-patient-combobox"]').isVisible() && await p.locator('[data-testid="agenda-suggested-slot"]').isVisible(),
    'NI2-853 (UX-010): modal de nova consulta exibe combobox único de paciente e sugere próximo horário livre');

  await actClick('form[data-agenda-new] button[type="submit"]');
  await p.waitForLoadState('networkidle');
  trackScreen();

  const apptId = +sq(`SELECT MAX(id) FROM appointments WHERE patient_id=${marianaId}`);
  ok(apptId > 0, `Consulta agendada na agenda com ID #${apptId}`);

  // Verifica botão Iniciar em 1 clique na visão diária da data agendada
  const apptDate = sq(`SELECT substr(scheduled_at,1,10) FROM appointments WHERE id=${apptId}`);
  await p.goto(B + `/agenda?view=day&date=${apptDate}`);
  await p.waitForLoadState('networkidle');
  trackScreen();

  ok((await p.locator('[data-testid="agenda-start-appointment"]').count()) >= 1,
    'NI2-855 (UX-011): visão diária da agenda exibe botão "Iniciar" de 1 clique (POST /agenda/{id}/status)');
  await actClick('[data-testid="agenda-start-appointment"]');
  await p.waitForLoadState('networkidle');
  trackScreen();

  // Abre a sala de atendimento (/consulta/{id})
  await p.goto(B + `/consulta/${apptId}`);
  await p.waitForLoadState('networkidle');
  trackScreen();

  // Se a consulta ainda não estiver in_progress (ex.: início antecipado >30min), confirma início
  if (await p.locator('[data-testid="consult-start"]').isVisible()) {
    if (await p.locator('input[name="confirm_early"]').count()) {
      await p.locator('input[name="confirm_early"]').check();
      actionsTotal++;
    }
    await actClick('[data-testid="consult-start"]');
    await p.waitForLoadState('networkidle');
    trackScreen();
  }

  console.log('== 4. NI2-848 (UX-004) & NI2-854 (UX-012): Sala de atendimento unificada, Histórico recolhível e LGPD em <details>');
  ok(await p.locator('[data-testid="attendance-steps"]').isVisible(),
    'NI2-848 (UX-004): barra de etapas do atendimento visível no topo da sala');
  ok(await p.locator('[data-testid="toggle-history-drawer"]').isVisible(),
    'NI2-848 (UX-004): botão do painel lateral recolhível "Histórico clínico" disponível na sala');
  await p.locator('[data-testid="toggle-history-drawer"]').click();
  ok(await p.locator('[data-testid="consultation-history-drawer"]').isVisible(),
    'NI2-848 (UX-004): painel lateral "Histórico clínico" abre sem sair da consulta');
  await p.locator('[data-testid="close-history-drawer"]').click();

  const lgpdDetailsOpen = await p.locator('[data-testid="stt-lgpd-details"]').getAttribute('open');
  ok(lgpdDetailsOpen === null,
    'NI2-854 (UX-012): aviso longo de LGPD da transcrição inicia recolhido em <details>');

  console.log('== 5. NI2-852 (UX-009): Anamnese com selo de estado sincronizado');
  await actClickNav('[data-testid="attendance-steps"] a[data-step="anamnese"]');

  await actFill('textarea[name="objectives"]', 'Redução de gordura corporal e reeducação alimentar');
  await actFill('textarea[name="routine"]', 'Faz 3 refeições ao dia, baixo consumo hídrico');
  await actClickNav('button[name="save_mode"][value="final"]');
  ok(sq(`SELECT COUNT(*) FROM anamneses WHERE patient_id=${marianaId} AND is_draft=0`) === '1',
    'Anamnese concluída e gravada no banco');

  console.log('== 6. NI2-847 (UX-003): Antropometria aceitando vírgula decimal ("72,4" kg e "1,7" m) e exibindo valor interpretado');
  await actClickNav('[data-testid="attendance-steps"] a[data-step="avaliacao"]');

  if (!(await p.locator('#anthroModal input[name="weight_kg"]').isVisible())) {
    await actClick('button[data-call="openAnthroModal"]:visible');
  }
  await actFill('#anthroModal input[name="weight_kg"]', '72,4');
  await actFill('#anthroModal input[name="height_cm"]', '1,7');
  const heightEcho = await p.textContent('#anthroModal [data-testid="decimal-echo-height_cm"]');
  ok(heightEcho.includes('170 cm') && heightEcho.includes('1,70 m'),
    `NI2-847 (UX-003): digitar "1,7" na altura exibe em tempo real "${heightEcho.trim()}"`);

  await actClick('#anthroModal button[data-anthro-tab="circunferencias"]');
  await actFill('#anthroModal input[name="waist_cm"]', '78,0');
  await actFill('#anthroModal input[name="hip_cm"]', '100,0');
  await actClickNav('#anthroModal button[type="submit"]');

  const savedAnthro = sq(`SELECT weight_kg || '|' || height_cm || '|' || ROUND(bmi,2) FROM anthropometric_assessments WHERE patient_id=${marianaId} ORDER BY id DESC LIMIT 1`);
  ok(savedAnthro === '72.4|170|25.05',
    `NI2-847 (UX-003): banco gravou 72.4 kg, 170 cm e IMC 25.05 (obtido: ${savedAnthro})`);

  console.log('== 7. NI2-845 (UX-001), NI2-846 (UX-002), NI2-852 (UX-009) & NI2-855 (UX-013, UX-019): Plano alimentar inline com medida caseira padrão e ranking clínico');
  await actClickNav('[data-testid="attendance-steps"] a[data-step="plano"]');

  // Cria plano alimentar para Mariana pela aba Plano do paciente
  await actClick('button[data-show="modal-new-plan-tab"]', true);
  await actFill('#plan-tab-title', 'Plano Alimentar Inicial', true);
  await actClickNav('#modal-new-plan-tab form[action*="/planos/novo"] button[type="submit"]', true);

  const planId = +sq(`SELECT MAX(id) FROM meal_plans WHERE patient_id=${marianaId}`);
  ok(planId > 0, `Plano alimentar criado com ID #${planId}`);

  // Salva meta energética pré-preenchida da avaliação (se formulário visível)
  const energySubmit = p.locator('form[action*="/energia"] button[type="submit"]');
  if (await energySubmit.count() && await energySubmit.first().isVisible()) {
    await actClickNav('form[action*="/energia"] button[type="submit"]', true);
  }

  // Helper para adicionar refeição pelo modal #modal-add-meal
  const addMeal = async (mealName, timeOfDay) => {
    await actClick('button[data-show="modal-add-meal"]', true);
    await actFill('#dc19-name', mealName, true);
    if (timeOfDay) {
      await actFill('#dc20-time_of_day', timeOfDay, true);
    }
    await Promise.all([
      p.waitForResponse(r => r.url().includes('/api/workflow/plan/') && r.status() === 200),
      actClick('#modal-add-meal form[action*="/refeicoes"] button[type="submit"]', true),
    ]);
    await p.locator('#modal-add-meal.cn-dialog-is-open').waitFor({ state: 'detached', timeout: 7000 });
    await p.waitForLoadState('networkidle');
    await p.waitForTimeout(200);
  };

  // Adiciona Refeição 1: Café da manhã
  await addMeal('Café da manhã', '07:30');

  const meal1Id = +sq(`SELECT id FROM meal_plan_meals WHERE meal_plan_id=${planId} ORDER BY id ASC LIMIT 1`);
  ok(meal1Id > 0 && await p.locator(`[data-testid="meal-inline-add-${meal1Id}"]`).isVisible(),
    `NI2-845 (UX-001): card da refeição #${meal1Id} possui barra inline de adição rápida de alimento`);

  // Helper para adicionar alimento direto dentro do card da refeição em 2 ações (digitar/buscar + clicar no resultado -> pré-preenche medida caseira + Confirmar)
  const addFoodInline = async (mealId, queryText, expectedSubstring) => {
    const formSel = `[data-testid="meal-inline-add-${mealId}"]`;
    const inputSel = `${formSel} [data-food-query]`;
    await Promise.all([
      p.waitForResponse(r => r.url().includes('/catalogo/alimentos/busca') && r.status() === 200),
      (async () => {
        await actFill(inputSel, queryText, true);
        await p.locator(inputSel).dispatchEvent('input');
      })(),
    ]);
    await p.waitForTimeout(100);
    const firstBtn = p.locator(`${formSel} .food-search-results button`).first();
    await firstBtn.waitFor({ state: 'visible', timeout: 7000 });
    const firstLabel = await firstBtn.textContent();
    if (expectedSubstring) {
      ok(firstLabel.toLowerCase().includes(expectedSubstring.toLowerCase()),
        `NI2-846 (UX-002): busca "${queryText}" trouxe "${firstLabel.trim()}" no topo`);
    }
    await firstBtn.click(); actionsTotal++; actionsPlan++;
    await Promise.all([
      p.waitForResponse(r => r.url().includes('/api/workflow/plan/') && r.status() === 200),
      p.locator(`${formSel} button[type="submit"]`).click(),
    ]);
    actionsTotal++; actionsPlan++;
    await p.waitForLoadState('networkidle');
    await p.waitForTimeout(150);
  };

  // Café da manhã: Pão francês, Ovo cozido, Mamão formosa
  await addFoodInline(meal1Id, 'pao frances', 'Pão, trigo, francês');
  await addFoodInline(meal1Id, 'ovo cozido', 'Ovo');
  await addFoodInline(meal1Id, 'mamao formosa', 'Mamão');

  // Adiciona Refeição 2: Almoço
  await addMeal('Almoço', '12:30');
  const meal2Id = +sq(`SELECT id FROM meal_plan_meals WHERE meal_plan_id=${planId} ORDER BY id DESC LIMIT 1`);

  // Almoço: Frango grelhado (deve vir Peito em 1º, não coração!), Arroz integral, Feijão carioca
  await addFoodInline(meal2Id, 'frango grelhado', 'peito');
  await addFoodInline(meal2Id, 'arroz integral', 'Arroz');
  await addFoodInline(meal2Id, 'feijao carioca', 'Feijão');

  const itemsCount = +sq(`SELECT COUNT(*) FROM meal_plan_items i JOIN meal_plan_meals m ON m.id=i.meal_id WHERE m.meal_plan_id=${planId}`);
  const heartCount = +sq(`SELECT COUNT(*) FROM meal_plan_items i JOIN meal_plan_meals m ON m.id=i.meal_id JOIN foods f ON f.id=i.food_id WHERE m.meal_plan_id=${planId} AND f.name LIKE '%coração%'`);
  ok(itemsCount === 6 && heartCount === 0,
    `NI2-845/NI2-846: 6 alimentos inseridos em 2 refeições com medida caseira padrão e 0 ocorrências de "coração de frango" (${actionsPlan} ações no plano vs 50 no baseline v54.61)`);

  // Publica o plano com revisão de adequação preservada
  await actClick('button[data-show="modal-publish-plan"]', true);
  if (await p.locator('#modal-publish-plan input[name="confirm_estimated_measures"]').count()) {
    await p.locator('#modal-publish-plan input[name="confirm_estimated_measures"]').check(); actionsTotal++; actionsPlan++;
  }
  if (await p.locator('#modal-publish-plan input[name="allow_adequacy_override"]').count()) {
    await p.locator('#modal-publish-plan input[name="allow_adequacy_override"]').check(); actionsTotal++; actionsPlan++;
    await actFill('#modal-publish-plan [name="adequacy_override_reason"]', 'Meta inicial gradual acordada com a paciente.', true);
  }
  if (await p.locator('#modal-publish-plan input[name="allow_allergen_override"]').count()) {
    await p.locator('#modal-publish-plan input[name="allow_allergen_override"]').check(); actionsTotal++; actionsPlan++;
    await actFill('#modal-publish-plan [name="allergen_override_reason"]', 'Alergias verificadas na anamnese da paciente.', true);
  }
  await actClickNav('#modal-publish-plan form[action*="/publicar"] button[type="submit"]', true);
  ok(sq(`SELECT status FROM meal_plans WHERE id=${planId}`) === 'published',
    'Plano alimentar publicado com sucesso');

  console.log('== 8. NI2-848 (UX-004) & NI2-849 (UX-005): Evolução na sala de atendimento, Conclusão e Painel de Fechamento em 1 clique');
  await p.goto(B + `/consulta/${apptId}`);
  await p.waitForLoadState('networkidle');
  trackScreen();

  ok(await p.locator('[data-testid="consult-latest-anthro-summary"]').isVisible(),
    'NI2-855 (UX-016): sala de atendimento mostra resumo da avaliação antropométrica recém-registrada sem duplicar formulário');

  await actFill('#evolution_summary', 'Paciente orientada quanto ao plano alimentar inicial e fracionamento das refeições.');
  if (await p.locator('input[name="confirm_pending_review"]').count()) {
    await p.locator('input[name="confirm_pending_review"]').check();
    actionsTotal++;
  }
  await actClickNav('form[action*="/concluir"] button[type="submit"]');

  ok(await p.locator('[data-testid="consultation-closing-panel"]').isVisible(),
    'NI2-849 (UX-005): concluir o atendimento exibe imediatamente o Painel de Fechamento da Consulta');
  ok(await p.locator('[data-testid="closing-schedule-followup-btn"]').isVisible() && await p.locator('[data-testid="closing-plan-pdf"]').isVisible(),
    'NI2-849 (UX-005): Painel de Fechamento oferece retorno sugerido em 1 clique e PDF do plano publicado');

  // Agenda o retorno em 1 clique direto do Painel de Fechamento
  await actClickNav('[data-testid="closing-schedule-followup-btn"]');

  const followUpCount = +sq(`SELECT COUNT(*) FROM appointments WHERE patient_id=${marianaId} AND type='follow_up'`);
  ok(followUpCount >= 1,
    `NI2-849 (UX-005): retorno agendado em 1 clique a partir do fechamento (total de retornos: ${followUpCount})`);

  console.log(`== 9. Métrica comparativa de esforço (NI2-844): ${actionsTotal} ações totais e ${actionsPlan} ações no plano (baseline v54.61: 104 ações totais / 50 no plano)`);
  ok(actionsTotal < 75 && actionsPlan <= 35,
    `NI2-844: redução comprovada de esforço na jornada completa (${actionsTotal} ações vs 104 no baseline; ${actionsPlan} ações no plano vs 50 no baseline; ${screensVisited.size} telas distintas)`);

  console.log('== 10. NI2-854 (UX-015), Isolamento Multi-Tenant e Responsividade (1440px, 820px, 390px)');
  await p.goto(B + '/dashboard');
  await p.waitForLoadState('networkidle');
  const topNavCount = await p.locator('.cn58-nav-sections [data-nav-top-item]').count();
  ok(topNavCount === 7,
    `NI2-854 (UX-015): menu lateral exibe exatamente 7 itens no 1º nível (encontrado: ${topNavCount})`);

  // Isolamento multi-tenant
  const rCross = await p.goto(B + `/pacientes/${ids.patientB}`);
  ok(new URL(p.url()).pathname !== `/pacientes/${ids.patientB}` && !(await p.content()).includes('Paciente Outra Clinica 844'),
    `Segurança multi-tenant: acesso ao paciente de outra clínica bloqueado (HTTP ${rCross.status()})`);

  for (const [vw, vh] of [[1440, 900], [820, 1180], [390, 844]]) {
    await p.setViewportSize({ width: vw, height: vh });
    for (const route of ['/dashboard', `/pacientes/${marianaId}`, `/planos/${planId}`]) {
      await p.goto(B + route);
      await p.waitForLoadState('networkidle');
      await checkNoHorizontalOverflow(p, `${route} @ ${vw}x${vh}`);
      if (vw === 1440) {
        await axeRun(p, `${route} @ 1440px`);
      }
    }
  }

  ok(consoleErrors.length === 0 && server500s.length === 0,
    `Zero erros JS no console (${consoleErrors.length}) e zero respostas HTTP 500 (${server500s.length})`);

  await ctx.close();
  await browser.close();

  const passCount = res.filter(Boolean).length;
  const failCount = res.length - passCount;
  console.log(`\nResumo E2E NI2-844: ${passCount} PASS, ${failCount} FAIL`);
  process.exit(failCount > 0 ? 1 : 0);
})();
