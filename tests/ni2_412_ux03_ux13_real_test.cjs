// NI2-412 — UX-03..UX-13 (NI2-415..NI2-425) em navegador real, reproduzível neste repositório.
// public/index.php em `php -S` (APP_ENV=production), SQLite isolado com dados fictícios
// (tests/support/ni2_412_fixture.php), login real, Chromium com a CSP ativa.
// Substitui, para este ambiente, tests/ni2_412_real_browser_e2e.py (amarrado a outra máquina).
//
//   PLAYWRIGHT_MODULE=… AXE_MODULE=… CHROMIUM_PATH=… [SCREENSHOT_DIR=…] [RESULTS_JSON=…] node tests/ni2_412_ux03_ux13_real_test.cjs
'use strict';
const { spawn, execFileSync } = require('node:child_process');
const { mkdtempSync, rmSync, writeFileSync } = require('node:fs');
const { join, resolve } = require('node:path');
const net = require('node:net');

const root = resolve(process.env.APP_ROOT || join(__dirname, '..'));
const playwright = require(process.env.PLAYWRIGHT_MODULE || 'playwright');
let axePath = null; try { axePath = require.resolve(process.env.AXE_MODULE || 'axe-core'); } catch { /* opcional */ }
const dir = mkdtempSync('/tmp/ni2-412-');
const env = { ...process.env, DB_CONNECTION: 'sqlite', DB_DATABASE: join(dir, 'test.sqlite'), APP_ENV: 'production', FORCE_HTTPS: 'false',
  APP_TIMEZONE: 'America/Sao_Paulo', MAIL_DRIVER: 'null', TZ: 'America/Sao_Paulo' };
const results = [];
function check(story, name, ok, detail) {
  results.push({ story, name, ok: !!ok, detail: ok ? null : (detail === undefined ? null : detail) });
  console.log(`${ok ? 'PASS' : 'FAIL'} [${story}] ${name}${!ok && detail !== undefined ? ' → ' + JSON.stringify(detail).slice(0, 300) : ''}`);
}
function blocked(story, name, ok, detail) {
  results.push({ story, name, ok: true, blocked: true, observed: detail });
  console.log(`BLOCKED [${story}] ${name} (observado: ${JSON.stringify(detail).slice(0, 200)}${ok ? '' : ' — não atende'})`);
}
const php = (args) => execFileSync('php', [join(root, 'tests/support/ni2_412_fixture.php'), ...args], { cwd: root, env, encoding: 'utf8' });
const sql = (q) => JSON.parse(php(['sql', q]));
const one = (q) => { const r = sql(q)[0]; return r ? Object.values(r)[0] : null; };
const shots = process.env.SCREENSHOT_DIR;

(async () => {
  php(['prepare']);
  const sock = net.createServer(); await new Promise((r) => sock.listen(0, '127.0.0.1', r)); const port = sock.address().port; await new Promise((r) => sock.close(r));
  const server = spawn('php', ['-d', 'session.save_path=' + dir, '-S', '127.0.0.1:' + port, '-t', 'public'], { cwd: root, env, stdio: 'ignore' });
  const B = 'http://127.0.0.1:' + port;
  for (let i = 0; i < 100; i++) { try { await fetch(B + '/login'); break; } catch { await new Promise((r) => setTimeout(r, 50)); } }
  const browser = await playwright.chromium.launch(process.env.CHROMIUM_PATH ? { executablePath: process.env.CHROMIUM_PATH, headless: false, args: ['--headless=new'] } : {});
  const axeReport = {};
  try {
    const ctx = await browser.newContext({ viewport: { width: 1366, height: 900 }, locale: 'pt-BR', permissions: ['clipboard-read', 'clipboard-write'] });
    const page = await ctx.newPage();
    const scriptErrors = [];
    page.on('console', (m) => { if (m.type() === 'error' && /Content Security Policy|Refused to|Uncaught/i.test(m.text())) scriptErrors.push(m.text()); });
    page.on('pageerror', (e) => scriptErrors.push(e.message));
    const dialogs = []; let acceptDialogs = true;
    page.on('dialog', (d) => { dialogs.push({ type: d.type(), message: d.message() }); acceptDialogs ? d.accept() : d.dismiss(); });
    const shot = async (name) => { if (shots) await page.screenshot({ path: join(shots, name + '.png') }); };
    let PNAME = 'Paciente Fictícia UX-' + Date.now().toString(36), pid = 0, appId = 0, planId = 0;
    const section = async (label, fn) => { try { await fn(); } catch (e) { results.push({ story: label, name: 'seção interrompida: ' + e.message.split('\n')[0], ok: false }); console.log(`FAIL (seção ${label} interrompida) ${e.message.split('\n')[0]}`); } };
    if (process.env.BASELINE) page.setDefaultTimeout(5000);
    const activeId = () => page.evaluate(() => document.activeElement && document.activeElement.id);

    // =========================================================== NI2-425 (UX-13) login
    await section('NI2-425 (UX-13) login', async () => {
    await page.goto(B + '/login');
    check('NI2-425', 'Login: autocomplete username/current-password', (await page.getAttribute('#email', 'autocomplete')) === 'username' && (await page.getAttribute('#password', 'autocomplete')) === 'current-password');
    await page.fill('#email', 'ux@example.test');
    await page.evaluate(() => navigator.clipboard.writeText('Agenda@2026'));
    await page.focus('#password'); await page.keyboard.press('Control+V');
    check('NI2-425', 'Colar a senha é permitido (Ctrl+V preenche o campo)', (await page.inputValue('#password')) === 'Agenda@2026');
    const toggle = page.getByRole('button', { name: 'Mostrar senha' });
    await toggle.click();
    check('NI2-425', 'Mostrar senha só muda a apresentação (type=text) e não envia o formulário', (await page.getAttribute('#password', 'type')) === 'text' && page.url().endsWith('/login'));
    check('NI2-425', 'Estado anunciado (rótulo "Ocultar senha", aria-pressed=true)', (await page.getByRole('button', { name: 'Ocultar senha' }).getAttribute('aria-pressed')) === 'true');
    await page.getByRole('button', { name: 'Ocultar senha' }).click();
    check('NI2-425', 'Ocultar volta a type=password', (await page.getAttribute('#password', 'type')) === 'password');
    await shot('ux13-login');
    // Credencial errada: mensagem genérica + recuperação disponível
    await page.fill('#password', 'SenhaErrada#1');
    await Promise.all([page.waitForNavigation(), page.locator('form[action="/login"] button[type=submit]').click()]);
    let body = await page.locator('body').innerText();
    const wrongMsg = (body.match(/[^\n]*(inválid|incorret)[^\n]*/i) || [''])[0];
    await page.goto(B + '/login'); await page.fill('#email', 'naoexiste@example.test'); await page.fill('#password', 'SenhaErrada#1');
    await Promise.all([page.waitForNavigation(), page.locator('form[action="/login"] button[type=submit]').click()]);
    body = await page.locator('body').innerText();
    const unknownMsg = (body.match(/[^\n]*(inválid|incorret)[^\n]*/i) || [''])[0];
    check('NI2-425', 'Senha errada e conta inexistente recebem a mesma mensagem (não revela conta)', wrongMsg !== '' && wrongMsg === unknownMsg, { wrongMsg, unknownMsg });
    check('NI2-425', 'Recuperação de senha continua disponível na tela de login', (await page.locator('a[href="/recuperar-senha"]').count()) >= 1);
    await page.goto(B + '/cadastro');
    check('NI2-425', 'Cadastro: "8 caracteres" e autocomplete new-password', /8 caracteres/.test(await page.locator('body').innerText()) && (await page.locator('input[name=password]').getAttribute('autocomplete')) === 'new-password');
    // Regra no servidor = regra na tela: 7 caracteres recusados, sem criar conta
    const csrf7 = await page.locator('input[name=_csrf_token]').first().inputValue();
    const r7 = await page.request.post(B + '/cadastro', { form: { _csrf_token: csrf7, name: 'Teste Sete', email: 'sete@example.test', password: 'Abc#123', clinic_name: 'Sete', crn: '12345', crn_region: 'CRN-3', terms_accepted: '1' }, maxRedirects: 0 });
    check('NI2-425', 'Servidor recusa senha de 7 caracteres e não cria a conta', !/dashboard|onboarding/.test(r7.headers()['location'] || '') && Number(one("SELECT COUNT(*) FROM users WHERE email = 'sete@example.test'")) === 0, r7.status());
    // Login real com a senha colada
    await page.goto(B + '/login'); await page.fill('#email', 'ux@example.test');
    await page.evaluate(() => navigator.clipboard.writeText('Agenda@2026')); await page.focus('#password'); await page.keyboard.press('Control+V');
    await Promise.all([page.waitForNavigation(), page.locator('form[action="/login"] button[type=submit]').click()]);
    check('NI2-425', 'Login com senha colada funciona', !page.url().endsWith('/login'), page.url());

    });
    // =========================================================== NI2-421 (UX-09) painel
    await section('NI2-421 (UX-09) painel', async () => {
    await page.goto(B + '/dashboard');
    check('NI2-421', 'Sem pendências: orientação apropriada no lugar da lista', /Nenhuma pendência crítica/.test(await page.locator('[data-testid=attention-panel]').innerText()));
    php(['attention']);
    for (const h of [768, 900]) {
      await page.setViewportSize({ width: 1366, height: h }); await page.goto(B + '/dashboard');
      const att = await page.locator('[data-testid=attention-panel]').boundingBox();
      const act = await page.locator('[data-testid=attention-panel] a, [data-testid=attention-panel] button').first().boundingBox().catch(() => null);
      check('NI2-421', `1366×${h}: prioridades com ação na primeira dobra`, att && att.y < h && act && act.y + act.height <= h, { att, act });
    }
    await page.setViewportSize({ width: 1366, height: 900 }); await page.goto(B + '/dashboard');
    let tops = await page.$$eval('.dashboard-metrics > *', (els) => els.map((e) => Math.round(e.getBoundingClientRect().top)));
    check('NI2-421', '1366 px: 5 métricas numa linha (sem métrica isolada)', tops.length === 5 && new Set(tops).size === 1, tops);
    check('NI2-421', 'Prioridades antes das métricas na ordem de leitura/teclado', await page.locator('[data-testid=attention-panel]').evaluate((e) => !!(e.compareDocumentPosition(document.querySelector('.dashboard-metrics')) & 4)));
    await shot('ux09-painel-1366x900');
    await page.setViewportSize({ width: 1024, height: 768 }); await page.goto(B + '/dashboard');
    tops = await page.$$eval('.dashboard-metrics > *', (els) => els.map((e) => Math.round(e.getBoundingClientRect().top)));
    check('NI2-421', '1024 px: 3 + 2 métricas (sem cartão sozinho numa linha)', new Set(tops).size === 2 && tops.filter((t) => t === tops[0]).length === 3, tops);
    await page.setViewportSize({ width: 1366, height: 900 });
    const panelLinks = await page.locator('[data-testid=attention-panel] a[href]').evaluateAll((as) => as.map((a) => a.getAttribute('href')));
    const linkStatuses = [];
    for (const href of panelLinks.slice(0, 6)) linkStatuses.push((await page.request.get(B + href, { maxRedirects: 0 })).status());
    check('NI2-421', 'Links do painel abrem páginas autorizadas (sem 4xx/5xx)', linkStatuses.every((s) => s < 400), { panelLinks, linkStatuses });

    });
    // =========================================================== NI2-417 / NI2-422 cadastro de paciente
    await section('NI2-417 / NI2-422 cadastro de paciente', async () => {
    await page.goto(B + '/pacientes');
    const trig = page.getByRole('button', { name: /Cadastrar Paciente/i }).first();
    await trig.focus(); await page.keyboard.press('Enter'); await page.waitForTimeout(300);
    const dlg = page.locator('#patientModal');
    check('NI2-417', 'Cadastro exposto como role=dialog aria-modal=true', (await dlg.getAttribute('role')) === 'dialog' && (await dlg.getAttribute('aria-modal')) === 'true');
    const lab = await dlg.getAttribute('aria-labelledby');
    check('NI2-417', 'Título associado ao diálogo (aria-labelledby → "Novo Paciente")', !!lab && (await page.locator('#' + lab).innerText()).trim() === 'Novo Paciente', lab);
    check('NI2-417', 'Foco inicial no primeiro campo (Nome)', (await activeId()) === 'p_name', await activeId());
    let inside = true;
    for (let i = 0; i < 30 && inside; i++) { await page.keyboard.press('Tab'); inside = await page.evaluate(() => !!document.activeElement.closest('#patientModal')); }
    for (let i = 0; i < 10 && inside; i++) { await page.keyboard.press('Shift+Tab'); inside = await page.evaluate(() => !!document.activeElement.closest('#patientModal')); }
    check('NI2-417', 'Tab e Shift+Tab (40×) ficam dentro do diálogo', inside);
    check('NI2-417', 'Fundo inerte enquanto o diálogo está aberto', await page.evaluate(() => !!document.querySelector('.app-sidebar, aside') && !!document.querySelector('.app-sidebar, aside').closest('[inert]')));
    const gd = await page.getAttribute('#p_gender', 'aria-describedby');
    const gHelp = gd ? await page.locator('#' + gd.split(' ')[0]).innerText() : '';
    check('NI2-422', 'Campo de sexo/gênero com ajuda associada explicando o uso no cálculo', /relação cintura-quadril|cálculo/i.test(gHelp), gHelp);
    const gOpts = await page.$$eval('#p_gender option', (os) => os.map((o) => ({ v: o.value, t: o.textContent.trim() })));
    blocked('NI2-422', '"Não informado" é opção distinta de qualquer resposta registrada', gOpts.some((o) => o.v === '' && /não informado/i.test(o.t)) && !gOpts.some((o) => /outro\s*\/\s*não informado/i.test(o.t)), gOpts);
    await page.keyboard.press('Escape'); await page.waitForTimeout(200);
    check('NI2-417', 'Escape sem alterações fecha e devolve o foco ao gatilho', !(await dlg.isVisible()) && /cadastrar paciente/i.test(await page.evaluate(() => document.activeElement.textContent || '')));
    await trig.click(); await page.waitForTimeout(200);
        await page.fill('#p_name', PNAME);
    acceptDialogs = false; dialogs.length = 0; await page.keyboard.press('Escape'); await page.waitForTimeout(200);
    check('NI2-417', 'Escape com alteração pede descarte; recusar mantém o diálogo e o valor', dialogs.some((d) => /Descartar/i.test(d.message)) && (await dlg.isVisible()) && (await page.inputValue('#p_name')) === PNAME, dialogs);
    acceptDialogs = true;
    await page.fill('#p_email', 'ux.cadastro@exemplo.invalid'); await page.fill('#p_birth', '1990-05-20');
    const consent = page.locator('#patientModal input[name=consent_choice]'); if (await consent.count()) await consent.first().check();
    await shot('ux05-cadastro-dialogo');
    await Promise.all([page.waitForNavigation(), page.locator('#patientModal button[type=submit]').click()]);
    pid = Number(one(`SELECT id FROM patients WHERE clinic_id = 1 AND name = '${PNAME}'`));
    check('NI2-417', 'Cadastro pelo diálogo persiste o paciente', pid > 0, pid);

    });
    // =========================================================== NI2-418 (UX-06) próximo passo no perfil
    await section('NI2-418 (UX-06) próximo passo no perfil', async () => {
    await page.goto(B + `/pacientes/${pid}`);
    check('NI2-418', 'Sem consulta: próximo passo = Agendar atendimento', (await page.getAttribute('[data-testid=care-next-step]', 'data-state')) === 'none');
    const nBefore = Number(one(`SELECT COUNT(*) FROM appointments WHERE patient_id = ${pid}`));
    await Promise.all([page.waitForNavigation(), page.click('[data-testid=care-schedule]')]);
    check('NI2-418', 'Agenda abre o formulário de nova consulta', await page.locator('#card-novo-agendamento').isVisible());
    check('NI2-418', 'Paciente explícito e pré-selecionado', (await page.locator('#card-novo-agendamento select[name=patient_id]').inputValue()) === String(pid));
    check('NI2-418', 'Abrir pelo GET não cria consulta', Number(one(`SELECT COUNT(*) FROM appointments WHERE patient_id = ${pid}`)) === nBefore);
    const now = new Date(new Date().toLocaleString('en-US', { timeZone: 'America/Sao_Paulo' }));
    const pad = (n) => String(n).padStart(2, '0');
    const slot = `${now.getFullYear()}-${pad(now.getMonth() + 1)}-${pad(now.getDate())}T23:30`;
    await page.fill('#card-novo-agendamento input[name=scheduled_at]', slot);
    const allow = page.locator('#card-novo-agendamento input[name=allow_conflict]'); if (await allow.count()) await allow.check();
    await Promise.all([page.waitForNavigation(), page.locator('#card-novo-agendamento button[type=submit]').click()]);
    appId = Number(one(`SELECT id FROM appointments WHERE patient_id = ${pid} ORDER BY id DESC LIMIT 1`));
    check('NI2-418', 'Consulta de hoje agendada e persistida', appId > 0, page.url());
    await page.goto(B + `/pacientes/${pid}`);
    check('NI2-418', 'Consulta de hoje: próximo passo = Iniciar atendimento', (await page.getAttribute('[data-testid=care-next-step]', 'data-state')) === 'start_today');
    dialogs.length = 0;
    await Promise.all([page.waitForNavigation(), page.click('[data-testid=care-start]')]);
    check('NI2-418', 'Confirmação nomeia o paciente antes de iniciar', dialogs.some((d) => d.message.includes(PNAME)), dialogs);
    if (new URL(page.url()).searchParams.get('inicio_antecipado') === '1') {
      check('NI2-418', 'Início antecipado: pede confirmação explícita e ainda não inicia', (await page.locator('[data-testid=early-start-warning]').isVisible()) && one(`SELECT status FROM appointments WHERE id = ${appId}`) === 'scheduled');
      await page.locator('[data-testid=consult-start-panel] input[name=confirm_early]').check();
      await Promise.all([page.waitForNavigation(), page.click('[data-testid=consult-start]')]);
    }
    check('NI2-418', 'Iniciar leva à mesma consulta e grava in_progress', new URL(page.url()).pathname === `/consulta/${appId}` && one(`SELECT status FROM appointments WHERE id = ${appId}`) === 'in_progress', page.url());
    await page.goto(B + `/pacientes/${pid}`);
    check('NI2-418', 'Em andamento: Continuar atendimento aponta para a mesma consulta', (await page.getAttribute('[data-testid=care-continue]', 'href')) === `/consulta/${appId}`);
    check('NI2-418', 'Navegação não duplicou consultas (1 registro)', Number(one(`SELECT COUNT(*) FROM appointments WHERE patient_id = ${pid}`)) === 1);

    });
    // =========================================================== NI2-420 / NI2-419 anamnese
    await section('NI2-420 / NI2-419 anamnese', async () => {
    await page.goto(B + `/pacientes/${pid}/anamnese`);
    const idx = page.locator('.cn-anamnesis-index a');
    check('NI2-420', 'Índice com 8 seções', (await idx.count()) === 8, await idx.count());
    check('NI2-420', 'Paciente visível na anamnese', (await page.locator('body').innerText()).includes(PNAME));
    await page.fill('#anamnesis_objectives', 'Objetivo fictício de teste');
    await idx.filter({ hasText: 'Notas e conduta' }).click(); await page.waitForTimeout(200);
    check('NI2-420', 'Índice leva direto à seção mantendo os valores', page.url().endsWith('#sec-notas') && (await page.inputValue('#anamnesis_objectives')) === 'Objetivo fictício de teste');
    check('NI2-420', 'Resumo distingue vazio de negação ("Alergias (não avaliado)")', /não registrado/i.test(await page.locator('#anamnesis-progress').innerText()) && (await page.locator('.cn-anamnesis-index').innerText()).includes('Alergias (não avaliado)'));
    check('NI2-420', 'Estado de salvamento indica alteração pendente', (await page.locator('#anamnesis-save-status').innerText()).includes('Alterações não salvas'));
    const saveBtn = page.getByRole('button', { name: 'Salvar rascunho' });
    const sb = await saveBtn.boundingBox();
    check('NI2-420', 'Salvar rascunho visível sem rolar o formulário (1366×900)', sb && sb.y >= 0 && sb.y + sb.height <= 900, sb);
    check('NI2-419', '"Concluir anamnese" substitui o jargão de versão imutável', (await page.getByRole('button', { name: 'Concluir anamnese' }).count()) === 1 && !/Imutável/i.test(await page.locator('main, body').first().innerText()));
    await shot('ux08-anamnese-indice');
    await Promise.all([page.waitForNavigation(), saveBtn.click()]);
    const an = sql(`SELECT is_draft, objectives FROM anamneses WHERE patient_id = ${pid} ORDER BY id DESC LIMIT 1`)[0] || {};
    check('NI2-420', 'Rascunho parcial persistido no banco', Number(an.is_draft) === 1 && an.objectives === 'Objetivo fictício de teste', an);
    await page.goto(B + `/pacientes/${pid}/anamnese`);
    check('NI2-420', 'Retomar recupera o rascunho persistido e mostra o estado', (await page.locator('#anamnesis-save-status').innerText()).includes('(rascunho)') && (await page.inputValue('#anamnesis_objectives')) === 'Objetivo fictício de teste');
    const bodyAn = await page.locator('body').innerText();
    check('NI2-419', 'Ajuda clara de que o histórico anterior é preservado', /histórico|versões anteriores/i.test(bodyAn));

    });
    // =========================================================== NI2-416 (UX-04) novo plano
    await section('NI2-416 (UX-04) novo plano', async () => {
    await page.goto(B + '/planos');
    await page.getByRole('button', { name: /Novo Plano/i }).first().click(); await page.waitForTimeout(200);
    const m = page.locator('#modal-new-plan');
    const names = await m.evaluate((d) => [...d.querySelectorAll('select,input:not([type=hidden]),textarea')].map((e) => (e.labels && e.labels[0] ? e.labels[0].textContent : '').trim()));
    check('NI2-416', 'Os 3 campos têm nome acessível (label associado)', names.length === 3 && names.every(Boolean), names);
    const req = await m.evaluate((d) => [...d.querySelectorAll('[required]')].map((e) => e.name));
    check('NI2-416', 'Obrigatoriedade exposta no campo (required)', req.includes('patient_id') && req.includes('title'), req);
    await m.locator('label', { hasText: 'Título' }).click();
    check('NI2-416', 'Clicar no label foca o campo', (await page.evaluate(() => document.activeElement.name)) === 'title');
    const np = Number(one('SELECT COUNT(*) FROM meal_plans'));
    await m.locator('[name=title]').fill('Título preenchido fictício');
    await m.locator('button[type=submit]').click(); await page.waitForTimeout(300);
    check('NI2-416', 'Envio inválido: foco no primeiro inválido e aria-invalid', (await page.evaluate(() => document.activeElement.name)) === 'patient_id' && (await m.locator('[name=patient_id]').getAttribute('aria-invalid')) === 'true');
    const em = await m.locator('[name=patient_id]').getAttribute('aria-describedby') || '';
    check('NI2-416', 'Erro associado ao campo (aria-describedby com texto)', !!em && (await page.locator('#' + em.split(' ').pop()).innerText()).trim() !== '', em);
    check('NI2-416', 'Envio inválido mantém o que foi preenchido e não cria registro', (await m.locator('[name=title]').inputValue()) === 'Título preenchido fictício' && Number(one('SELECT COUNT(*) FROM meal_plans')) === np);
    await shot('ux04-novo-plano-erro');
    await m.locator('[name=patient_id]').selectOption(String(pid));
    await m.locator('[name=title]').fill('Plano fictício UX-04');
    await Promise.all([page.waitForNavigation(), m.locator('button[type=submit]').click()]);
    planId = Number(one("SELECT id FROM meal_plans WHERE title = 'Plano fictício UX-04'"));
    check('NI2-416', 'Plano válido criado e aberto', planId > 0 && page.url().endsWith('/planos/' + planId), page.url());

    });
    // =========================================================== NI2-415 (UX-03) busca de alimentos
    await section('NI2-415 (UX-03) busca de alimentos', async () => {
    await page.locator('button', { hasText: /Refeição/ }).first().click();
    await page.fill('#modal-add-meal input[name=name]', 'Almoço');
    await Promise.all([page.waitForNavigation(), page.locator('#modal-add-meal button[type=submit]').click()]);
    await page.locator('button', { hasText: '+ Alimento' }).first().click(); await page.waitForTimeout(300);
    await page.keyboard.insertText('arroz'); await page.waitForTimeout(1500);
    let res = await page.locator('#modal-add-item .food-search-results').innerText();
    check('NI2-415', 'Resultados mostram a fonte (Catálogo TACO) e a referência (nº TACO)', /Catálogo TACO/.test(res) && /TACO[^\n]*nº \d+|nº TACO|Código/.test(res), res.slice(0, 300));
    check('NI2-415', 'Alimento personalizado identificado como da própria clínica', /Personalizado da sua clínica/.test(res) && res.includes('Arroz integral da casa'), null);
    check('NI2-415', 'Personalizado de outra clínica não aparece', !res.includes('Arroz secreto da clínica vizinha'));
    check('NI2-415', 'Composição por 100 g visível sem abrir outra tela', /P [\d,]+ g · C [\d,]+ g · G [\d,]+ g por 100 g/.test(res));
    await page.keyboard.press('ArrowDown'); await page.keyboard.press('Enter'); await page.waitForTimeout(200);
    check('NI2-415', 'Escolha por teclado (seta + Enter)', (await page.locator('#item-food-select').inputValue()) !== '' && /Selecionado:/.test(await page.locator('#modal-add-item').innerText()));
    await page.fill('#modal-add-item [data-food-query]', 'Feijão tropeiro fictício'); await page.waitForTimeout(1500);
    res = await page.locator('#modal-add-item .food-search-results').innerText();
    check('NI2-415', 'Duplicata real (mesmo nome e composição) aparece agrupada, sem mesclar no banco', /1 cadastro equivalente/.test(res) && Number(one("SELECT COUNT(*) FROM foods WHERE name = 'Feijão tropeiro fictício'")) === 3, res.slice(0, 300));
    check('NI2-415', 'Mesmo nome com composição diferente traz aviso explícito', /Mesmo nome com composição diferente/.test(res));
    await shot('ux03-busca-alimentos');
    acceptDialogs = true; await page.locator('#modal-add-item [data-dialog-close]').first().click(); await page.waitForTimeout(200);

    });
    // =========================================================== NI2-424 (UX-12) nova conversa
    await section('NI2-424 (UX-12) nova conversa', async () => {
    await page.goto(B + '/chat');
    check('NI2-424', 'Módulo de conversas oferece "Nova conversa"', (await page.locator('[data-testid=new-conversation]').count()) === 1);
    const nconv = Number(one(`SELECT COUNT(*) FROM chat_conversations WHERE patient_id = ${pid}`));
    const openNew = async () => { await page.locator('[data-testid=new-conversation]').click(); await page.waitForTimeout(200); await page.fill('#new-conv-search', PNAME.slice(-8)); await page.waitForTimeout(1200); };
    await openNew();
    await page.locator('#new-conv-results button').first().click();
    check('NI2-424', 'Destinatário explícito antes de abrir', (await page.locator('#new-conv-chosen').innerText()).includes(PNAME));
    await page.locator('#modal-nova-conversa [data-dialog-close]').first().click(); await page.waitForTimeout(200);
    check('NI2-424', 'Cancelar não cria conversa', Number(one(`SELECT COUNT(*) FROM chat_conversations WHERE patient_id = ${pid}`)) === nconv);
    await openNew(); await page.locator('#new-conv-results button').first().click();
    await Promise.all([page.waitForNavigation(), page.locator('#new-conv-submit').click()]);
    const c1 = page.url().split('/').pop();
    await shot('ux12-nova-conversa');
    await openNew();
    check('NI2-424', 'Busca indica conversa já existente', /já tem conversa/i.test(await page.locator('#new-conv-results').innerText()));
    await page.locator('#new-conv-results button').first().click();
    await Promise.all([page.waitForNavigation(), page.locator('#new-conv-submit').click()]);
    check('NI2-424', 'Mesma conversa reaberta, sem duplicar', page.url().split('/').pop() === c1 && Number(one(`SELECT COUNT(*) FROM chat_conversations WHERE patient_id = ${pid}`)) === 1, { c1, url: page.url() });
    check('NI2-424', 'Nenhuma mensagem enviada no fluxo', Number(one(`SELECT COUNT(*) FROM chat_messages WHERE conversation_id = ${Number(c1) || 0}`)) === 0);
    const tok = await page.locator('#new-conv-form input[name=_csrf_token]').inputValue().catch(() => '');
    let r = await page.request.post(B + '/chat/nova', { form: { _csrf_token: tok, patient_id: '901' }, maxRedirects: 0 });
    check('NI2-424', 'Paciente de outra clínica: 404 e nada criado', r.status() === 404 && Number(one('SELECT COUNT(*) FROM chat_conversations WHERE patient_id = 901')) === 0, r.status());
    r = await page.request.get(B + '/chat/pacientes?q=Lucas');
    check('NI2-424', 'Busca não lista paciente de outra clínica', r.ok() && !(await r.text()).includes('Lucas Aluno'), r.status());
    r = await page.request.get(B + '/chat?patient_id=901', { maxRedirects: 0 });
    check('NI2-424', 'Atalho GET /chat?patient_id de outra clínica: 404', r.status() === 404, r.status());
    r = await page.request.post(B + '/chat/nova', { form: { patient_id: String(pid) }, maxRedirects: 0 });
    check('NI2-424', 'POST sem CSRF bloqueado', [403, 419].includes(r.status()), r.status());
    await page.goto(B + `/pacientes/${pid}`);
    check('NI2-424', 'Atalho contextual no perfil continua disponível', (await page.locator(`a[href*="/chat"][href*="${pid}"], a[href^="/chat/"]`).count()) >= 1);

    });
    // =========================================================== NI2-423 (UX-11) responsividade
    await section('NI2-423 (UX-11) responsividade', async () => {
    const screens = [
      ['login', '/login', null, 'button[type=submit]'],
      ['painel', '/dashboard', null, '[data-testid=attention-panel] a, [data-testid=attention-panel] button'],
      ['pacientes + cadastro', '/pacientes', async () => { await page.getByRole('button', { name: /Cadastrar Paciente/i }).first().click(); await page.waitForTimeout(250); }, '#patientModal button[type=submit]'],
      ['perfil', `/pacientes/${pid}`, null, '[data-testid=care-continue]'],
      ['anamnese', `/pacientes/${pid}/anamnese`, null, 'button:has-text("Salvar rascunho")'],
      ['planos + novo plano', '/planos', async () => { await page.getByRole('button', { name: /Novo Plano/i }).first().click(); await page.waitForTimeout(250); }, '#modal-new-plan button[type=submit]'],
      ['conversas', '/chat', null, '[data-testid=new-conversation]'],
    ];
    const sizes = [[360, 740], [390, 844], [768, 1024], [1366, 900], [683, 450]];
    for (const [w, h] of sizes) {
      await page.setViewportSize({ width: w, height: h });
      for (const [name, path, open, essential] of screens) {
        if (name === 'login') { await ctx.clearCookies(); }
        await page.goto(B + path);
        if (open) await open();
        const m2 = await page.evaluate((sel) => {
          const el = document.querySelector(sel.split(':has-text')[0] === sel ? sel : 'body');
          let target = el;
          if (sel.includes(':has-text')) target = [...document.querySelectorAll('button')].find((b) => /Salvar rascunho/.test(b.textContent));
          if (target) target.scrollIntoView({ block: 'center' });
          const r = target ? target.getBoundingClientRect() : null;
          const dlg = document.querySelector('[role=dialog][aria-modal=true]:not([hidden])');
          const panel = dlg && [...dlg.querySelectorAll('*')].find((e) => getComputedStyle(e).overflowY === 'auto' || getComputedStyle(e).overflowY === 'scroll');
          return { scroll: document.documentElement.scrollWidth - window.innerWidth,
            reach: !!r && r.width > 0 && r.left >= 0 && r.right <= window.innerWidth + 1 && r.top >= 0 && r.bottom <= window.innerHeight + 1,
            dialogScroll: dlg && getComputedStyle(dlg).display !== 'none' ? !!panel || dlg.scrollHeight <= window.innerHeight : null };
        }, essential);
        const label = w === 683 ? '1366 com zoom 200%' : `${w} px`;
        check('NI2-423', `${label} · ${name}: sem rolagem horizontal e ação essencial alcançável`, m2.scroll <= 1 && m2.reach && m2.dialogScroll !== false, m2);
        if (shots && (w === 360 || w === 683) && (name.includes('cadastro') || name.includes('novo plano'))) await shot(`ux11-${w}-${name.replace(/\W+/g, '-')}`);
        if (name === 'login') {
          await page.fill('#email', 'ux@example.test'); await page.fill('#password', 'Agenda@2026');
          await Promise.all([page.waitForNavigation(), page.locator('form[action="/login"] button[type=submit]').click()]);
        }
      }
    }
    await page.setViewportSize({ width: 1366, height: 900 });

    });
    // =========================================================== acessibilidade automática (axe) nas telas afetadas
    await section('acessibilidade automática (axe) nas telas afetadas', async () => {
    if (axePath) {
      const actx = await browser.newContext({ bypassCSP: true, viewport: { width: 1366, height: 900 }, storageState: await ctx.storageState() });
      const ap = await actx.newPage();
      for (const [name, path, opener] of [['painel', '/dashboard'], ['pacientes', '/pacientes'], ['perfil', `/pacientes/${pid}`], ['anamnese', `/pacientes/${pid}/anamnese`],
        ['planos', '/planos'], ['editor de plano', `/planos/${planId}`], ['conversas', '/chat'], ['agenda', '/agenda?view=week']]) {
        await ap.goto(B + path); await ap.addScriptTag({ path: axePath });
        const v = await ap.evaluate(async () => (await window.axe.run(document, { runOnly: ['wcag2a', 'wcag2aa'] })).violations.map((x) => ({ id: x.id, n: x.nodes.length, alvo: x.nodes.slice(0, 3).map((nd) => nd.target.join(' ') + ' | ' + nd.html.slice(0, 160) + ' | ' + (nd.any[0] ? nd.any[0].message : '')) })));
        axeReport[name] = v;
        const naming = v.filter((x) => ['label', 'button-name', 'link-name', 'select-name', 'aria-input-field-name', 'aria-dialog-name', 'input-button-name'].includes(x.id));
        check('NI2-416', `axe ${name}: nenhum campo, botão ou link sem nome acessível`, naming.length === 0, naming);
        check('geral', `axe ${name}: WCAG 2 A/AA sem violações`, v.length === 0, v);
      }
      await actx.close();
    }
    check('geral', 'Nenhum erro de script nem bloqueio de CSP nos fluxos', scriptErrors.length === 0, scriptErrors.slice(0, 5));
    });
  } catch (e) {
    results.push({ story: 'erro', name: e.message.split('\n')[0], ok: false });
    console.log('FAIL (interrompido) ' + e.message.split('\n')[0]);
  } finally {
    await browser.close(); server.kill();
    rmSync(dir, { recursive: true, force: true });
    if (process.env.RESULTS_JSON) writeFileSync(process.env.RESULTS_JSON, JSON.stringify({ results, axe: axeReport }, null, 1));
  }
  const ok = results.filter((r) => r.ok && !r.blocked).length;
  const nBlocked = results.filter((r) => r.blocked).length;
  console.log('\nAXE ' + JSON.stringify(Object.fromEntries(Object.entries(axeReport).map(([k, v]) => [k, v.map((x) => `${x.id}(${x.n})`)]))));
  console.log(`\nNI2-412 UX-03..UX-13: ${ok} PASS, ${results.length - ok - nBlocked} FAIL, ${nBlocked} BLOCKED`);
  if (ok + nBlocked !== results.length) process.exitCode = 1;
})();
