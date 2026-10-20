// NI2-412 — UX-01 (NI2-413, navegação da agenda) e UX-02 (NI2-414, alterações pendentes) em navegador real.
// public/index.php em `php -S`, SQLite isolado com dados fictícios, login real, Chromium com a CSP ativa.
//
//   PLAYWRIGHT_MODULE=… CHROMIUM_PATH=… node tests/ni2_412_ux01_ux02_real_test.cjs
//   Baseline (árvore antiga, registra falhas sem abortar): BASELINE=1 APP_ROOT=/caminho/da/arvore node …
'use strict';
const { spawn, execFileSync } = require('node:child_process');
const { mkdtempSync, rmSync, writeFileSync } = require('node:fs');
const { join, resolve } = require('node:path');
const net = require('node:net');

const root = resolve(process.env.APP_ROOT || join(__dirname, '..'));
const baseline = process.env.BASELINE === '1';
const playwright = require(process.env.PLAYWRIGHT_MODULE || 'playwright');
const dir = mkdtempSync('/tmp/ni2-412-');
const env = { ...process.env, DB_CONNECTION: 'sqlite', DB_DATABASE: join(dir, 'test.sqlite'), APP_ENV: 'production', FORCE_HTTPS: 'false',
  APP_TIMEZONE: 'America/Sao_Paulo', MAIL_DRIVER: 'null', TZ: 'America/Sao_Paulo' };
const results = [];
function check(story, name, ok, detail) {
  results.push({ story, name, ok: !!ok, detail: detail === undefined ? null : detail });
  console.log(`${ok ? 'PASS' : 'FAIL'} [${story}] ${name}${!ok && detail !== undefined ? ' → ' + JSON.stringify(detail).slice(0, 300) : ''}`);
  if (!ok && !baseline) throw new Error(`[${story}] ${name}`);
}
const fixture = (mode) => JSON.parse(execFileSync('php', [join(root, 'tests/support/ni2_412_fixture.php'), mode], { cwd: root, env, encoding: 'utf8' }));
const todaySP = () => { const d = new Date(new Date().toLocaleString('en-US', { timeZone: 'America/Sao_Paulo' })); return d; };
const ddmm = (d) => `${String(d.getDate()).padStart(2, '0')}/${String(d.getMonth() + 1).padStart(2, '0')}`;

(async () => {
  fixture('prepare');
  const sock = net.createServer(); await new Promise((r) => sock.listen(0, '127.0.0.1', r)); const port = sock.address().port; await new Promise((r) => sock.close(r));
  const server = spawn('php', ['-d', 'session.save_path=' + dir, '-S', '127.0.0.1:' + port, '-t', 'public'], { cwd: root, env, stdio: 'ignore' });
  const B = 'http://127.0.0.1:' + port;
  for (let i = 0; i < 100; i++) { try { await fetch(B + '/login'); break; } catch { await new Promise((r) => setTimeout(r, 50)); } }
  const browser = await playwright.chromium.launch(process.env.CHROMIUM_PATH ? { executablePath: process.env.CHROMIUM_PATH, headless: false, args: ['--headless=new'] } : {});
  const shots = process.env.SCREENSHOT_DIR;
  try {
    const ctx = await browser.newContext({ viewport: { width: 1366, height: 900 }, locale: 'pt-BR' });
    const page = await ctx.newPage();
    const cspErrors = [];
    page.on('console', (m) => { if (m.type() === 'error' && /Content Security Policy|Refused to/i.test(m.text())) cspErrors.push(m.text()); });
    page.on('pageerror', (e) => cspErrors.push(e.message));
    const dialogs = []; let acceptDialogs = true;
    page.on('dialog', (d) => { dialogs.push({ type: d.type(), message: d.message() }); acceptDialogs ? d.accept() : d.dismiss(); });

    await page.goto(B + '/login');
    await page.fill('input[name=email]', 'ux@example.test');
    await page.fill('input[name=password]', 'Agenda@2026');
    await Promise.all([page.waitForNavigation(), page.locator('form[action="/login"] button[type=submit]').click()]);
    check('setup', 'Login real da nutricionista fictícia', !page.url().endsWith('/login'), page.url());

    // ====================================================================== NI2-413 (UX-01)
    const weekTitle = async () => (await page.locator('h3', { hasText: 'Semana de' }).innerText()).trim();
    const dayTitle = async () => (await page.locator('h3', { hasText: 'Consultas do Dia' }).innerText()).trim();
    const pageText = async () => page.locator('main, body').first().innerText();
    // Na árvore antiga (baseline) as setas não têm nome acessível: cai para a seta visual (← / → / Hoje)
    // só para medir o comportamento de navegação; a falta de nome é registrada à parte.
    const linkByName = (re) => {
      const named = page.getByRole('link', { name: re });
      if (!baseline) return named;
      const src = re.source;
      const arrow = /anterior/i.test(src) ? '←' : /Próxim/i.test(src) ? '→' : 'Hoje';
      return { click: async () => ((await named.count()) ? named.first() : page.locator('a', { hasText: arrow }).first()).click(),
        count: async () => named.count(), getAttribute: async (a) => ((await named.count()) ? named.first().getAttribute(a) : null) };
    };

    await page.goto(B + '/agenda?view=week&date=2026-10-14');
    check('NI2-413', 'Semana de 14/10/2026 = 12/10 a 18/10/2026', (await weekTitle()).includes('12/10 a 18/10/2026'), await weekTitle());
    let txt = await pageText();
    const weekBox = async () => page.locator('h3', { hasText: 'Semana de' }).locator('xpath=ancestor::div[contains(concat(" ",normalize-space(@class)," ")," card ")][1]').innerText();
    txt = await weekBox();
    check('NI2-413', 'Semana 12–18/10 mostra a consulta de 14/10 (09:00) e não a de 21/10 (10:00)', /\b09:00\b/.test(txt) && !/\b10:00\b/.test(txt), txt.slice(0, 200));
    const next = linkByName(/^Próxima semana/);
    check('NI2-413', 'Link "Próxima semana" tem nome acessível com direção e período', (await next.count()) === 1 && /19 de outubro de 2026 a 25 de outubro de 2026/.test(await next.getAttribute('aria-label') || ''), await next.getAttribute('aria-label').catch(() => null));
    // Teclado: Tab até o link e Enter
    await page.focus('body');
    let reached = false;
    for (let i = 0; i < 60 && !reached; i++) {
      await page.keyboard.press('Tab');
      reached = await page.evaluate(() => /^Próxima semana/.test(document.activeElement && document.activeElement.getAttribute('aria-label') || ''));
    }
    check('NI2-413', 'Teclado: "Próxima semana" é alcançável com Tab', reached);
    if (reached) await Promise.all([page.waitForNavigation(), page.keyboard.press('Enter')]); else await Promise.all([page.waitForNavigation(), next.click()]);
    check('NI2-413', 'Um Enter em "Próxima semana" muda início e fim em exatamente 7 dias (19/10 a 25/10/2026)', (await weekTitle()).includes('19/10 a 25/10/2026'), await weekTitle());
    txt = await weekBox();
    check('NI2-413', 'Conteúdo da semana muda junto (aparece 21/10 10:00, some 14/10 09:00)', /\b10:00\b/.test(txt) && !/\b09:00\b/.test(txt), txt.slice(0, 200));
    if (shots) await page.screenshot({ path: join(shots, `ux01-semana-${baseline ? 'antes' : 'depois'}.png`) });
    await Promise.all([page.waitForNavigation(), linkByName(/^Semana anterior/).click()]);
    check('NI2-413', '"Semana anterior" volta exatamente 7 dias', (await weekTitle()).includes('12/10 a 18/10/2026'), await weekTitle());
    for (const anchor of ['2026-10-12', '2026-10-15', '2026-10-18']) {
      await page.goto(B + `/agenda?view=week&date=${anchor}`);
      await Promise.all([page.waitForNavigation(), linkByName(/^Próxima semana/).click()]);
      check('NI2-413', `De qualquer dia da semana (${anchor}) um clique leva a 19/10–25/10`, (await weekTitle()).includes('19/10 a 25/10/2026'), await weekTitle());
    }
    await page.goto(B + '/agenda?view=week&date=2026-12-30');
    await Promise.all([page.waitForNavigation(), linkByName(/^Próxima semana/).click()]);
    check('NI2-413', 'Virada de ano na semanal (28/12–03/01 → 04/01–10/01/2027)', (await weekTitle()).includes('04/01 a 10/01/2027'), await weekTitle());
    await page.goto(B + '/agenda?view=day&date=2026-12-31');
    const nextDay = linkByName(/^Próximo dia/);
    check('NI2-413', 'Diária: link "Próximo dia" com nome acessível e data', (await nextDay.count()) === 1 && /1 de janeiro de 2027/.test(await nextDay.getAttribute('aria-label') || ''), null);
    await Promise.all([page.waitForNavigation(), nextDay.click()]);
    check('NI2-413', 'Diária anda 1 dia na virada de ano (31/12 → 01/01/2027)', (await dayTitle()).includes('01/01/2027'), await dayTitle());
    await page.goto(B + '/agenda?view=day&date=2026-03-01');
    await Promise.all([page.waitForNavigation(), linkByName(/^Dia anterior/).click()]);
    check('NI2-413', 'Diária volta 1 dia na virada de mês (01/03 → 28/02/2026)', (await dayTitle()).includes('28/02/2026'), await dayTitle());
    await page.goto(B + '/agenda?view=day&date=2028-03-01');
    await Promise.all([page.waitForNavigation(), linkByName(/^Dia anterior/).click()]);
    check('NI2-413', 'Ano bissexto (01/03/2028 → 29/02/2028)', (await dayTitle()).includes('29/02/2028'), await dayTitle());
    await page.goto(B + '/agenda?view=week&date=2027-03-03');
    const hoje = linkByName(/^Hoje/);
    await Promise.all([page.waitForNavigation(), hoje.click()]);
    const t = todaySP(); const mon = new Date(t.getFullYear(), t.getMonth(), t.getDate() - ((t.getDay() + 6) % 7)); const sun = new Date(mon.getFullYear(), mon.getMonth(), mon.getDate() + 6);
    check('NI2-413', `"Hoje" volta à semana vigente (${ddmm(mon)} a ${ddmm(sun)})`, (await weekTitle()).includes(`${ddmm(mon)} a ${ddmm(sun)}`), await weekTitle());
    check('NI2-413', '"Hoje" marca o período vigente (aria-current)', (await page.locator('a[aria-current="date"]').count()) === 1);
    const status = await page.locator('#agenda-period').innerText().catch(() => '');
    check('NI2-413', 'Leitor de tela recebe o período exibido (role=status)', /^Semana de \d+ de \S+ de \d{4} a \d+ de \S+ de \d{4}$/.test(status.trim()), status);

    // Viewports e zoom 200% (1366 px com zoom 200% ≈ 683 px de largura CSS)
    for (const [w, h, label] of [[360, 780, '360'], [390, 844, '390'], [768, 1024, '768'], [1366, 900, '1366'], [683, 450, '1366 com zoom 200%']]) {
      await page.setViewportSize({ width: w, height: h });
      await page.goto(B + '/agenda?view=week&date=2026-10-14');
      const m = await page.evaluate(() => {
        const vis = (el) => { if (!el) return false; const r = el.getBoundingClientRect(); return r.width > 0 && r.height > 0 && r.left >= 0 && r.right <= window.innerWidth + 1; };
        const links = [...document.querySelectorAll('a[aria-label]')].filter((a) => /^(Semana anterior|Próxima semana|Hoje)/.test(a.getAttribute('aria-label')));
        return { scroll: document.documentElement.scrollWidth - window.innerWidth, visiveis: links.filter(vis).length, alvos: links.map((a) => Math.round(Math.min(a.getBoundingClientRect().width, a.getBoundingClientRect().height))) };
      });
      check('NI2-413', `${label} px: anterior/Hoje/próxima visíveis, alvo ≥ 24 px e sem rolagem horizontal`, m.visiveis === 3 && m.alvos.every((x) => x >= 24) && m.scroll <= 1, m);
      if (shots && (w === 360 || w === 683)) await page.screenshot({ path: join(shots, `ux01-agenda-${w}-${baseline ? 'antes' : 'depois'}.png`) });
    }
    await page.setViewportSize({ width: 1366, height: 900 });

    // ====================================================================== NI2-414 (UX-02)
    // 1) Anamnese alterada sem salvar: avisa ao sair e permite permanecer sem perder o texto.
    await page.goto(B + '/pacientes/1/anamnese');
    await page.fill('#anamnesis_objectives', 'Objetivo fictício pendente');
    acceptDialogs = false; dialogs.length = 0;
    await page.goto(B + '/dashboard', { timeout: 3000 }).catch(() => {});
    await page.waitForTimeout(300);
    check('NI2-414', 'Anamnese alterada: ao sair, o navegador avisa (beforeunload)', dialogs.some((d) => d.type === 'beforeunload'), dialogs);
    check('NI2-414', 'Permanecer mantém a página e o texto digitado', page.url().includes('/anamnese') && (await page.inputValue('#anamnesis_objectives')) === 'Objetivo fictício pendente');
    acceptDialogs = true;
    await page.evaluate(() => window.ClyoForms && window.ClyoForms.dirtyForms().forEach((f) => f.reset()));

    // 2) Plano: buscar alimento e cancelar não deixa pendência nem aviso.
    await page.goto(B + '/planos');
    const csrf = await page.locator('form[action="/planos/novo"] input[name=_csrf_token]').getAttribute('value');
    const created = await page.request.post(B + '/planos/novo', { form: { _csrf_token: csrf, patient_id: '1', title: 'Plano fictício UX-02' }, maxRedirects: 0 });
    const planPath = (created.headers().location || '').replace(/^https?:\/\/[^/]+/, '');
    await page.goto(B + planPath);
    await page.locator('button', { hasText: /Refeição/ }).first().click();
    await page.fill('#modal-add-meal input[name=name]', 'Almoço');
    await Promise.all([page.waitForNavigation(), page.locator('#modal-add-meal button[type=submit]').click()]);
    const openFood = async () => { await page.locator('button', { hasText: '+ Alimento' }).first().click(); await page.waitForTimeout(300); };
    await openFood();
    await page.keyboard.insertText('arroz'); await page.waitForTimeout(1200);
    dialogs.length = 0;
    await page.locator('#modal-add-item [data-dialog-close]').first().click(); await page.waitForTimeout(200);
    check('NI2-414', 'Buscar alimento e Cancelar: fecha sem pedir confirmação', dialogs.length === 0 && !(await page.locator('#modal-add-item').isVisible()), dialogs);
    check('NI2-414', 'Buscar e Cancelar: nenhum formulário fica pendente', (await page.evaluate(() => window.ClyoForms ? window.ClyoForms.dirtyForms().length : -1)) === 0);
    dialogs.length = 0;
    await Promise.all([page.waitForNavigation(), page.goto(B + '/dashboard')]).catch(() => {});
    check('NI2-414', 'Sair depois de buscar/cancelar não gera aviso', !dialogs.some((d) => d.type === 'beforeunload') && page.url().endsWith('/dashboard'), dialogs);

    // 3) Escolher alimento e Cancelar: pede descarte; aceitar fecha e limpa só aquele formulário.
    await page.goto(B + planPath);
    await page.evaluate(() => window.ClyoDialog && window.ClyoDialog.open('modal-add-meal'));
    await page.waitForTimeout(200);
    await page.fill('#modal-add-meal input[name=name]', 'Refeição pendente fictícia');
    await page.evaluate(() => { document.getElementById('modal-add-meal').style.display = 'none'; });
    await openFood();
    await page.keyboard.insertText('arroz'); await page.waitForTimeout(1200);
    await page.keyboard.press('ArrowDown'); await page.keyboard.press('Enter'); await page.waitForTimeout(200);
    const chosen = await page.evaluate(() => ({ sel: (document.getElementById('item-food-select') || {}).value || '', txt: (document.getElementById('modal-add-item') || {}).innerText || '', dirty: window.ClyoForms ? window.ClyoForms.dirtyForms().map((f) => f.getAttribute('action') || f.id) : null }));
    check('NI2-415', 'Escolha de alimento por teclado (seta + Enter) preenche a seleção', chosen.sel !== '' && /Selecionado/.test(chosen.txt), { sel: chosen.sel, dirty: chosen.dirty });
    dialogs.length = 0; acceptDialogs = true;
    await page.locator('#modal-add-item [data-dialog-close]').first().click(); await page.waitForTimeout(200);
    check('NI2-414', 'Alimento escolhido + Cancelar: pede confirmação de descarte', dialogs.some((d) => /Descartar/i.test(d.message)), dialogs);
    check('NI2-414', 'Descartar a inclusão de alimento não apaga o outro formulário pendente',
      (await page.inputValue('#modal-add-meal input[name=name]')) === 'Refeição pendente fictícia' && (await page.evaluate(() => window.ClyoForms.dirtyForms().length)) === 1);
    // 4) Enviar um formulário com outro pendente: o navegador avisa antes de sair (o outro não some calado);
    //    confirmando, o envio grava de verdade.
    await openFood();
    await page.keyboard.insertText('arroz'); await page.waitForTimeout(1200);
    await page.keyboard.press('ArrowDown'); await page.keyboard.press('Enter'); await page.waitForTimeout(200);
    await page.fill('#modal-add-item input[name=amount]', '100').catch(() => {});
    dialogs.length = 0; acceptDialogs = true;
    await Promise.all([page.waitForNavigation({ timeout: 8000 }).catch(() => {}), page.locator('#modal-add-item button[type=submit]').click()]);
    check('NI2-414', 'Enviar um formulário com outro ainda pendente: aviso de saída antes de perder o outro', dialogs.some((d) => d.type === 'beforeunload'), dialogs);
    check('NI2-414', 'Confirmando, o alimento enviado é gravado (1 item no plano)', fixture('inspect').items === 1, fixture('inspect').items);
    check('NI2-414', 'Nenhum erro de script nem bloqueio de CSP durante os fluxos', cspErrors.length === 0, cspErrors);
    const ins = fixture('inspect');
    check('NI2-414', 'Só o envio confirmado gravou item (cancelamentos não gravaram nada)', ins.items === 1, ins.items);
  } catch (e) {
    if (!baseline) throw e;
    results.push({ story: 'erro', name: e.message.split('\n')[0], ok: false });
    console.log('FAIL (interrompido) ' + e.message.split('\n')[0]);
  } finally {
    await browser.close(); server.kill();
    rmSync(dir, { recursive: true, force: true });
    if (process.env.RESULTS_JSON) writeFileSync(process.env.RESULTS_JSON, JSON.stringify(results, null, 1));
  }
  const ok = results.filter((r) => r.ok).length;
  console.log(`\nNI2-412 UX-01/UX-02 (${baseline ? 'BASELINE' : 'atual'}): ${ok}/${results.length} PASS`);
})().catch((e) => { console.error('FAIL ' + e.message); process.exitCode = 1; });
