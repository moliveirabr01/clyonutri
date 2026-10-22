// NI2-96 — check-in diário no app da paciente (build web do Expo) e painel de métricas da plataforma, contra o servidor real.
// Pré-requisito: build web em mobile/dist (cd mobile && npx expo export --platform web --output-dir dist).
//   PLAYWRIGHT_MODULE=… AXE_MODULE=… CHROMIUM_PATH=… node tests/ni2_96_app_web_real_test.cjs
'use strict';
const { spawn, execFileSync } = require('node:child_process');
const { mkdtempSync, mkdirSync, writeFileSync, rmSync } = require('node:fs');
const { join, resolve } = require('node:path');
const pw = require(process.env.PLAYWRIGHT_MODULE || 'playwright');
const axePath = require.resolve(process.env.AXE_MODULE || 'axe-core');
const root = resolve(__dirname, '..');
const dir = mkdtempSync('/tmp/ni2-96-');
const ev = join(root, 'docs/evidencias/ni2-96'); mkdirSync(ev, { recursive: true });
const env = { ...process.env, DB_CONNECTION: 'sqlite', DB_DATABASE: join(dir, 't.sqlite'), APP_ENV: 'production', FORCE_HTTPS: 'false',
  APP_TIMEZONE: 'America/Sao_Paulo', TZ: 'America/Sao_Paulo', APP_KEY: 'ni2-96-chave-de-teste-ficticia-0001', MAIL_DRIVER: 'null' };
const fx = (...args) => execFileSync('php', [join(root, 'tests/support/ni2_96_fixture.php'), ...args.map(String)], { cwd: root, env, encoding: 'utf8', stdio: ['ignore', 'pipe', 'pipe'] });
const sql = (q) => JSON.parse(fx('sql', q));
const out = { checks: [] };
const ck = (n, ok, d) => { out.checks.push({ n, ok: !!ok, d: ok ? undefined : d }); console.log((ok ? 'PASS ' : 'FAIL ') + n + (ok ? '' : ' → ' + String(JSON.stringify(d)).slice(0, 300))); };

(async () => {
  fx('prepare');
  const PORT = 19000 + Math.floor(Math.random() * 2000);
  const app = spawn('php', ['-d', 'session.save_path=' + dir, '-S', '127.0.0.1:' + PORT, '-t', 'mobile/dist', 'tests/support/ni2_294_ui_router.php'], { cwd: root, env, stdio: 'ignore' });
  const web = spawn('php', ['-d', 'session.save_path=' + dir, '-S', '127.0.0.1:' + (PORT + 1), '-t', 'public'], { cwd: root, env, stdio: 'ignore' });
  await new Promise((r) => setTimeout(r, 1000));
  const A = 'http://127.0.0.1:' + PORT, W = 'http://127.0.0.1:' + (PORT + 1);
  const br = await pw.chromium.launch({ ...(process.env.CHROMIUM_PATH ? { executablePath: process.env.CHROMIUM_PATH, headless: false, args: ['--headless=new'] } : {}) });
  const loginApp = async (p, email) => {
    await p.goto(A + '/');
    await p.getByPlaceholder('seuemail@exemplo.com').fill(email); await p.getByPlaceholder('Digite sua senha').fill('PacienteTeste@2026');
    await p.getByRole('button', { name: 'Entrar', exact: true }).click();
    await p.getByTestId('daily-checkin-card').waitFor({ timeout: 15000 });
  };
  const card = (p) => p.getByTestId('daily-checkin-card');
  try {
    // ------------------------------------------------------------------ paciente, 390 px
    const ctx = await br.newContext({ viewport: { width: 390, height: 844 }, locale: 'pt-BR', timezoneId: 'America/Sao_Paulo' });
    let p = await ctx.newPage(); const errs = []; p.on('pageerror', (e) => errs.push(e.message));
    await loginApp(p, 'ana@example.test');
    ck('Home mostra "Check-in de hoje" com a pergunta e as opções', await card(p).getByText('Quanto do plano você seguiu hoje?').isVisible() && await card(p).getByRole('radio', { name: 'Quase tudo do plano' }).isVisible());
    await card(p).getByRole('button', { name: 'Enviar check-in' }).click();
    await card(p).getByText('Escolha quanto do plano você seguiu hoje.').waitFor({ timeout: 5000 });
    ck('Enviar sem escolher: aviso dentro do card, nada gravado', Number(Object.values(sql('SELECT COUNT(*) AS n FROM checkins WHERE patient_id = 1')[0])[0]) === 0);
    await card(p).getByRole('radio', { name: 'Quase tudo do plano' }).click();
    await card(p).getByRole('radio', { name: 'Energia 4 de 5' }).click();
    await card(p).getByLabel('Observação para o nutricionista').fill('Jantar fora no sábado');
    await card(p).scrollIntoViewIfNeeded();
    await p.screenshot({ path: join(ev, 'app-checkin-preenchendo-390.png') });
    await p.addScriptTag({ path: axePath }).catch(() => {});
    let v = await p.evaluate(async () => window.axe ? (await window.axe.run('[data-testid="daily-checkin-card"]', { runOnly: ['wcag2a', 'wcag2aa'] })).violations.map((x) => x.id + ':' + x.nodes.length) : ['axe-indisponivel']);
    ck('Card de check-in sem violações WCAG 2 A/AA (axe), preenchendo', v.length === 0, v);
    // Falha de rede: o aviso fica no card e nada some.
    await p.route('**/api/v1/patient/checkin', (r) => r.abort());
    await card(p).getByRole('button', { name: 'Enviar check-in' }).click();
    await p.waitForTimeout(1500);
    const errText = await card(p).getByRole('alert').innerText().catch(() => '');
    ck('Falha de rede: erro dentro do card, escolhas mantidas, nada gravado', errText.length > 0 && await card(p).getByRole('radio', { name: 'Quase tudo do plano' }).getAttribute('aria-checked') === 'true'
      && Number(Object.values(sql('SELECT COUNT(*) AS n FROM checkins WHERE patient_id = 1')[0])[0]) === 0, errText);
    await p.unroute('**/api/v1/patient/checkin');
    await card(p).getByRole('button', { name: 'Enviar check-in' }).click();
    await card(p).getByText('Check-in de hoje enviado.').waitFor({ timeout: 8000 });
    let row = sql('SELECT meal_adherence_pct, energy_level, notes FROM checkins WHERE patient_id = 1');
    ck('Envio grava 75% / energia 4 / observação e o card mostra o resumo', row.length === 1 && Number(row[0].meal_adherence_pct) === 75 && Number(row[0].energy_level) === 4 && row[0].notes === 'Jantar fora no sábado'
      && await card(p).getByText('Quase tudo').isVisible(), row);
    await p.screenshot({ path: join(ev, 'app-checkin-enviado-390.png') });
    await card(p).getByRole('button', { name: 'Corrigir check-in de hoje' }).click();
    await card(p).getByRole('radio', { name: 'Metade do plano' }).click();
    await card(p).getByRole('button', { name: 'Salvar correção' }).click();
    await card(p).getByText('Check-in de hoje corrigido.').waitFor({ timeout: 8000 });
    row = sql('SELECT meal_adherence_pct FROM checkins WHERE patient_id = 1');
    ck('Correção no mesmo dia: uma linha, agora 50%', row.length === 1 && Number(row[0].meal_adherence_pct) === 50, row);
    // No web, a sessão do app fica só em memória: recarregar volta ao login (comportamento existente).
    await p.reload(); await loginApp(p, 'ana@example.test');
    ck('Depois de recarregar, o card mostra o check-in salvo (vem do servidor)', await card(p).getByText('Metade').isVisible() && await card(p).getByRole('button', { name: 'Corrigir check-in de hoje' }).isVisible());
    await p.addScriptTag({ path: axePath }).catch(() => {});
    v = await p.evaluate(async () => window.axe ? (await window.axe.run('[data-testid="daily-checkin-card"]', { runOnly: ['wcag2a', 'wcag2aa'] })).violations.map((x) => x.id + ':' + x.nodes.length) : ['axe-indisponivel']);
    ck('Card de check-in sem violações WCAG 2 A/AA (axe), enviado', v.length === 0, v);
    ck('Sem rolagem horizontal a 390 px', await p.evaluate(() => document.documentElement.scrollWidth <= innerWidth + 1));
    ck('Sem erro de script no app', errs.length === 0, errs);

    // Mais 4 pacientes registram água e check-in pela API (passam do corte mínimo de 5).
    for (const email of ['dora', 'elisa', 'fabi', 'gabi', 'helo']) {
      const t = (await (await fetch(W + '/api/v1/auth/patient-login', { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify({ email: email + '@example.test', password: 'PacienteTeste@2026' }) })).json()).token;
      for (const [path, body] of [['/api/v1/patient/hydration', { amount_ml: 250 }], ['/api/v1/patient/checkin', { meal_adherence_pct: 75 }]]) {
        await fetch(W + path, { method: 'POST', headers: { Authorization: 'Bearer ' + t, 'Content-Type': 'application/json' }, body: JSON.stringify(body) });
      }
    }

    // ------------------------------------------------------------------ painel da plataforma, 1280 px
    const actx = await br.newContext({ viewport: { width: 1280, height: 900 }, locale: 'pt-BR', bypassCSP: true }); // só para injetar o axe
    const a = await actx.newPage(); const aerrs = []; a.on('pageerror', (e) => aerrs.push(e.message));
    await a.goto(W + '/login');
    await a.locator('input[name="email"]').fill('plataforma@example.test'); await a.locator('input[name="password"]').fill('Plataforma@2026');
    await Promise.all([a.waitForNavigation(), a.locator('form[action="/login"] button[type="submit"]').first().click()]);
    await a.goto(W + '/admin/metricas-adesao?dias=30');
    const hyd = await a.getByTestId('am-kpi-hydration_logged').innerText();
    const chk = await a.getByTestId('am-kpi-checkin_submitted').innerText();
    const plan = await a.getByTestId('am-kpi-plan_viewed').innerText();
    ck('Painel mostra hidratação e check-in (5 e 6 pacientes) e "menos de 5" para plano visto', /5 no período/.test(hyd) && /6 no período/.test(chk) && /menos de 5/.test(plan), [hyd, chk, plan]);
    ck('Menu "Métricas de adesão" ativo e aviso de privacidade visível', await a.locator('a.admin-nav__item.active[href="/admin/metricas-adesao"]').count() === 1 && await a.getByTestId('am-privacy').isVisible());
    await a.screenshot({ path: join(ev, 'plataforma-metricas-adesao-1280.png'), fullPage: true });
    await a.addScriptTag({ path: axePath }).catch(() => {});
    v = await a.evaluate(async () => window.axe ? (await window.axe.run('#admin-main-content', { runOnly: ['wcag2a', 'wcag2aa'] })).violations.map((x) => x.id + ':' + x.nodes.length) : ['axe-indisponivel']);
    ck('Página de métricas sem violações WCAG 2 A/AA (axe, área de conteúdo)', v.length === 0, v);
    await a.goto(W + '/admin/configuracoes');
    const toggle = a.locator('input[type="checkbox"][name="analytics_adherence_enabled"]');
    ck('Configurações globais têm a chave "Métricas de adesão agregadas", ligada', await toggle.isChecked());
    await toggle.scrollIntoViewIfNeeded();
    await a.screenshot({ path: join(ev, 'plataforma-configuracoes-chave-1280.png') });
    ck('Sem erro de script no painel', aerrs.length === 0, aerrs);
  } catch (e) {
    ck('Execução sem exceção', false, e.stack);
  } finally {
    await br.close(); app.kill(); web.kill();
    writeFileSync(join(ev, 'app-web-resultados.json'), JSON.stringify(out, null, 1));
    rmSync(dir, { recursive: true, force: true });
  }
  const ok = out.checks.filter((c) => c.ok).length;
  console.log(`\nNI2-96 app e painel (web): ${ok} PASS, ${out.checks.length - ok} FAIL`);
  if (ok !== out.checks.length) process.exitCode = 1;
})();
