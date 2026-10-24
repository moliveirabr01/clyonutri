// NI2-140: acessibilidade e design system do app da paciente (build web do Expo) e da página /app (PHP), contra o servidor real.
// Cobre: idioma e título do documento, abas sem rótulo redundante, axe WCAG 2 A/AA em cada aba, cor do botão principal,
// erro e sucesso do registro de água na própria tela (sem Alert), 200% de zoom a 390 px com todas as abas alcançáveis,
// e o /app com abas WAI-ARIA navegáveis pelo teclado.
// Pré-requisito: build web em mobile/dist (cd mobile && npx expo export --platform web --output-dir dist).
//   PLAYWRIGHT_MODULE=… AXE_MODULE=… CHROMIUM_PATH=… node tests/ni2_140_app_web_real_test.cjs
//   No MariaDB/MySQL: acrescente DB_CONNECTION=mysql DB_HOST=… DB_PORT=… DB_USERNAME=… DB_PASSWORD=… (cria e apaga ni2_140_test_*).
'use strict';
const { spawn, execFileSync } = require('node:child_process');
const { mkdtempSync, mkdirSync, writeFileSync } = require('node:fs');
const { join, resolve } = require('node:path');
const net = require('node:net');
const pw = require(process.env.PLAYWRIGHT_MODULE || 'playwright');
const axePath = require.resolve(process.env.AXE_MODULE || 'axe-core');
const root = resolve(__dirname, '..');
const dir = mkdtempSync('/tmp/ni2-140-');
const ev = join(root, 'docs/evidencias/ni2-140'); mkdirSync(ev, { recursive: true });
const useMysql = process.env.DB_CONNECTION === 'mysql';
const mysqlDb = 'ni2_140_test_' + Math.random().toString(36).slice(2, 10);
const env = { ...process.env, DB_CONNECTION: useMysql ? 'mysql' : 'sqlite', DB_DATABASE: useMysql ? mysqlDb : join(dir, 't.sqlite'), NI2_FIXTURE_DIR: dir,
  APP_ENV: 'production', FORCE_HTTPS: 'false', APP_TIMEZONE: 'America/Sao_Paulo', TZ: 'America/Sao_Paulo', APP_KEY: 'ni2-140-chave-de-teste-ficticia-0001' };
const mysqlAdmin = (q) => execFileSync('php', ['-r', '$p=new PDO("mysql:host=".getenv("DB_HOST").";port=".getenv("DB_PORT"),getenv("DB_USERNAME"),getenv("DB_PASSWORD"));$p->exec($argv[1]);', q], { env });
const fx = (...args) => execFileSync('php', [join(root, 'tests/support/ni2_91_fixture.php'), ...args.map(String)], { cwd: root, env, encoding: 'utf8', stdio: ['ignore', 'pipe', 'pipe'] });
const one = (q) => { const r = JSON.parse(fx('sql', q))[0]; return r ? Object.values(r)[0] : null; };
const freePort = async () => { const s = net.createServer(); await new Promise((r) => s.listen(0, '127.0.0.1', r)); const p = s.address().port; await new Promise((r) => s.close(r)); return p; };
const EMOJI = /[\u{1F300}-\u{1FAFF}\u{2600}-\u{27BF}\u{2139}\u{21B3}]/u;
const TABS = ['Hoje', 'Plano', 'Diário', 'Chat', 'Evolução', 'Perfil'];
const results = [];
const ck = (n, ok, d) => { results.push({ n, ok: !!ok, d: ok ? undefined : d }); console.log((ok ? 'PASS ' : 'FAIL ') + n + (ok ? '' : ' → ' + String(JSON.stringify(d)).slice(0, 400))); };
const axe = async (p, sel) => { await p.addScriptTag({ path: axePath }).catch(() => {});
  return p.evaluate(async (s) => window.axe ? (await window.axe.run(s || document, { runOnly: ['wcag2a', 'wcag2aa', 'wcag21a', 'wcag21aa'] })).violations.map((x) => x.id + ':' + x.nodes.length) : ['axe-indisponivel'], sel); };

(async () => {
  const servers = [];
  let br = null;
  try {
    if (useMysql) mysqlAdmin(`CREATE DATABASE ${mysqlDb} CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci`);
    fx('prepare');
    const PORT = await freePort();
    servers.push(spawn('php', ['-S', '127.0.0.1:' + PORT, '-t', 'mobile/dist', 'tests/support/ni2_294_ui_router.php'], { cwd: root, env, stdio: 'ignore' }));
    const PORT2 = await freePort();
    servers.push(spawn('php', ['-d', 'session.save_path=' + dir, '-S', '127.0.0.1:' + PORT2, '-t', 'public'], { cwd: root, env, stdio: 'ignore' }));
    await new Promise((r) => setTimeout(r, 1000));
    br = await pw.chromium.launch({ ...(process.env.CHROMIUM_PATH ? { executablePath: process.env.CHROMIUM_PATH, headless: false, args: ['--headless=new'] } : {}) });
    const login = async (p) => {
      await p.goto('http://127.0.0.1:' + PORT + '/');
      await p.getByPlaceholder('seuemail@exemplo.com').fill('ana@example.test'); await p.getByPlaceholder('Digite sua senha').fill('PacienteTeste@2026');
      await p.getByRole('button', { name: 'Entrar', exact: true }).click();
      await p.getByRole('tab').first().waitFor({ timeout: 15000 });
      await p.waitForTimeout(600);
    };

    // ------------------------------------------------------------------ 390 px: documento, abas e axe
    let ctx = await br.newContext({ viewport: { width: 390, height: 844 }, locale: 'pt-BR', timezoneId: 'America/Sao_Paulo' });
    let p = await ctx.newPage(); const errs = []; p.on('pageerror', (e) => errs.push(e.message));
    await login(p);
    ck('M3: documento em pt-BR com título do produto', (await p.evaluate(() => document.documentElement.lang)) === 'pt-BR' && (await p.title()) === 'ClyoNutri · Paciente', [await p.evaluate(() => document.documentElement.lang), await p.title()]);
    ck('M3: conteúdo dentro de um landmark main', (await p.locator('main, [role="main"]').count()) >= 1);
    const tabNames = await p.getByRole('tab').evaluateAll((els) => els.map((e) => e.getAttribute('aria-label') || e.textContent.trim()));
    ck('B1: abas com o nome visível, sem "Aba …" no rótulo', JSON.stringify(tabNames) === JSON.stringify(TABS), tabNames);
    ck('A3: barra de abas dentro de um tablist rotulado', (await p.getByRole('tablist', { name: 'Seções do app' }).count()) === 1);
    const v = {};
    for (const tab of TABS) {
      await p.getByRole('tab', { name: tab, exact: true }).click();
      await p.waitForTimeout(700);
      ck(`Aba ${tab}: marcada como selecionada`, (await p.getByRole('tab', { name: tab, exact: true }).getAttribute('aria-selected')) === 'true');
      v[tab] = await axe(p);
      ck(`Aba ${tab}: sem violações WCAG 2.1 A/AA (axe)`, v[tab].length === 0, v[tab]);
      const body = await p.locator('body').innerText();
      ck(`M6: aba ${tab} sem emoji no texto`, !EMOJI.test(body), (body.match(EMOJI) || [])[0]);
      await p.screenshot({ path: join(ev, `app-${tab.normalize('NFD').replace(/[^A-Za-z]/g, '').toLowerCase()}-390.png`), fullPage: true });
    }
    await p.getByRole('tab', { name: 'Perfil', exact: true }).click();
    const logoutBg = await p.getByRole('button', { name: 'Encerrar sessão', exact: true }).evaluate((e) => getComputedStyle(e).backgroundColor);
    ck('A1: botão "Encerrar sessão" no cobre AA (#B94F1F), não no #E67A3F', logoutBg === 'rgb(185, 79, 31)', logoutBg);

    // A4: erro e sucesso do registro de água aparecem no card (no web o Alert.alert não mostra nada).
    // Integração com a NI2-92: sem rede o toque entra na fila ("aguardando envio") e é reenviado depois,
    // com a mesma chave, junto com o próximo toque (250 ml da fila + 500 ml = 750 ml, uma vez cada).
    await p.getByRole('tab', { name: 'Hoje', exact: true }).click();
    await p.waitForTimeout(500);
    const before = Number(one('SELECT COALESCE(SUM(amount_ml),0) AS s FROM hydration_logs WHERE patient_id = 1'));
    await p.route('**/api/v1/patient/hydration', (r) => r.abort('internetdisconnected'));
    await p.getByRole('button', { name: /250 ml/ }).click();
    await p.getByTestId('water-pending').waitFor({ timeout: 8000 });
    const pend = p.getByTestId('water-pending');
    const pendText = await pend.innerText();
    ck('A4: falha ao registrar água mostra o aviso de fila no card (aguardando envio, 250 ml)', /250 ml aguardando envio/.test(pendText) && (await pend.getAttribute('aria-live')) === 'polite', [pendText, await pend.getAttribute('aria-live')]);
    ck('A4: nada gravado quando a requisição falha', Number(one('SELECT COALESCE(SUM(amount_ml),0) AS s FROM hydration_logs WHERE patient_id = 1')) === before);
    await p.unroute('**/api/v1/patient/hydration');
    await p.waitForTimeout(1000);
    await p.getByRole('button', { name: /500 ml/ }).click();
    const fbLoc = p.getByTestId('water-feedback');
    await fbLoc.waitFor({ timeout: 10000 });
    await p.getByText(/ml registrados\. Total de hoje/).waitFor({ timeout: 8000 });
    const fb = p.getByTestId('water-feedback'); const fbText = await fb.innerText();
    const after = Number(one('SELECT COALESCE(SUM(amount_ml),0) AS s FROM hydration_logs WHERE patient_id = 1'));
    ck('A4: sucesso mostra "N ml registrados" e o total, sem role=alert', /\d+ ml registrados\. Total de hoje: [\d.]+ ml\./.test(fbText) && (await fb.getAttribute('role')) !== 'alert', fbText);
    ck('A4: banco recebeu os 250 ml da fila e os 500 ml, uma vez cada', after - before === 750, [before, after]);
    const hyd = await axe(p);
    ck('Hoje com aviso de água: sem violações WCAG 2.1 A/AA', hyd.length === 0, hyd);
    await p.screenshot({ path: join(ev, 'app-agua-feedback-390.png'), fullPage: true });
    ck('Sem rolagem horizontal a 390 px', await p.evaluate(() => document.documentElement.scrollWidth <= innerWidth + 1));
    ck('Sem erro de script (390 px)', errs.length === 0, errs);
    await ctx.close();

    // ------------------------------------------------------------------ A3: 200% de zoom em 390 px (195 px CSS)
    ctx = await br.newContext({ viewport: { width: 195, height: 422 }, deviceScaleFactor: 2, locale: 'pt-BR', timezoneId: 'America/Sao_Paulo' });
    p = await ctx.newPage(); const e2 = []; p.on('pageerror', (e) => e2.push(e.message));
    await login(p);
    await p.getByRole('tab', { name: 'Perfil', exact: true }).click();
    await p.getByText('Perfil e privacidade').first().waitFor({ timeout: 8000 });
    ck('A3: com 200% de zoom a aba Perfil (a última) é alcançável e abre', (await p.getByRole('tab', { name: 'Perfil', exact: true }).getAttribute('aria-selected')) === 'true');
    await p.getByRole('tab', { name: 'Evolução', exact: true }).click();
    await p.waitForTimeout(500);
    ck('A3: com 200% de zoom a aba Evolução também abre', (await p.getByRole('tab', { name: 'Evolução', exact: true }).getAttribute('aria-selected')) === 'true');
    ck('A3: a página não rola na horizontal (só a barra de abas)', await p.evaluate(() => document.documentElement.scrollWidth <= innerWidth + 1));
    await p.screenshot({ path: join(ev, 'app-zoom200-390.png'), fullPage: true });
    ck('Sem erro de script (zoom 200%)', e2.length === 0, e2);
    await ctx.close();

    // ------------------------------------------------------------------ A8: página /app (PHP)
    // bypassCSP só para injetar o axe; a página continua com o CSP do servidor para o resto.
    ctx = await br.newContext({ viewport: { width: 390, height: 844 }, locale: 'pt-BR', bypassCSP: true });
    p = await ctx.newPage(); const e3 = []; p.on('pageerror', (e) => e3.push(e.message));
    await p.goto('http://127.0.0.1:' + PORT2 + '/app');
    const tabs = p.getByRole('tab');
    ck('A8: /app com duas abas de verdade (role=tab) num tablist rotulado', (await tabs.count()) === 2 && (await p.getByRole('tablist', { name: 'Como você quer entrar' }).count()) === 1, await tabs.count());
    const va = await axe(p);
    ck('A8: /app sem violações WCAG 2.1 A/AA (axe), inclusive aria-required-children e contraste', va.length === 0, va);
    const vimg = await p.evaluate(async () => (await window.axe.run(document, { runOnly: ['image-redundant-alt'] })).violations.length);
    ck('A8: logo sem alt redundante ao lado do nome', vimg === 0, vimg);
    const submitBg = await p.locator('#tab-first .btn-submit').evaluate((e) => getComputedStyle(e).backgroundColor);
    // Integração com a NI2-25: o botão de ação do /app agora é o Clyo Green do Clinical Botanical (#39734A).
    ck('A8: botão principal no verde do DS Clinical Botanical (#39734A)', submitBg === 'rgb(57, 115, 74)', submitBg);
    await p.getByRole('tab', { name: 'Primeiro acesso' }).focus();
    await p.keyboard.press('ArrowRight');
    const st = await p.evaluate(() => ({ sel: document.getElementById('tab-btn-login').getAttribute('aria-selected'), focus: document.activeElement.id,
      loginHidden: document.getElementById('tab-login').hidden, firstHidden: document.getElementById('tab-first').hidden, tabindexFirst: document.getElementById('tab-btn-first').tabIndex }));
    ck('A8: seta para a direita seleciona e foca "Já tenho cadastro" e troca o painel', st.sel === 'true' && st.focus === 'tab-btn-login' && !st.loginHidden && st.firstHidden && st.tabindexFirst === -1, st);
    await p.keyboard.press('Home');
    ck('A8: Home volta para a primeira aba', await p.evaluate(() => document.activeElement.id === 'tab-btn-first' && document.getElementById('tab-btn-first').getAttribute('aria-selected') === 'true' && document.getElementById('tab-login').hidden));
    await p.keyboard.press('End');
    await p.keyboard.press('Tab');
    ck('A8: Tab sai da aba e entra no painel aberto (campo de login)', await p.evaluate(() => document.activeElement.id === 'login-input'));
    const outline = await p.evaluate(() => { const s = getComputedStyle(document.activeElement); return s.outlineStyle + ' ' + s.outlineWidth; });
    ck('B5: foco do teclado visível nos botões (:focus-visible)', await p.evaluate(() => { document.getElementById('tab-btn-first').focus({ focusVisible: true }); const s = getComputedStyle(document.activeElement); return parseFloat(s.outlineWidth) >= 2 && s.outlineStyle !== 'none'; }) || /solid 3px/.test(outline), outline);
    const body = await p.locator('body').innerText();
    ck('A8/M6: /app sem emoji e sem Caixa De Título nos rótulos', !EMOJI.test(body) && !/Primeiro Acesso|Já Tenho Cadastro|Código do Convite|Entrar no Portal/.test(body), (body.match(EMOJI) || [])[0]);
    await p.locator('#login-input').focus(); // foto com o foco no campo, como fica depois do Tab
    await p.screenshot({ path: join(ev, 'portal-app-390.png'), fullPage: true });
    ck('Sem erro de script (/app)', e3.length === 0, e3);
    await ctx.close();
  } catch (e) {
    ck('Execução sem exceção', false, String(e && e.stack || e));
  } finally {
    if (br) await br.close().catch(() => {});
    for (const s of servers) s.kill();
    if (useMysql) { try { mysqlAdmin(`DROP DATABASE ${mysqlDb}`); } catch { /* */ } }
  }
  const pass = results.filter((r) => r.ok).length;
  console.log(`\nNI2-140 (${useMysql ? 'MySQL' : 'SQLite'}): ${pass} PASS, ${results.length - pass} FAIL`);
  if (process.env.RESULTS_JSON) writeFileSync(process.env.RESULTS_JSON, JSON.stringify({ driver: useMysql ? 'mysql' : 'sqlite', pass, fail: results.length - pass, results }, null, 1));
  process.exit(results.length > 0 && pass === results.length ? 0 : 1);
})();
