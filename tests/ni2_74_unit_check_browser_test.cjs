// NI2-74 — conferência da unidade em navegador real (CSP ativa): peso da medida, medidas do alimento no seletor e
// confirmação obrigatória do peso estimado no plano (item e edição) e na receita; acessibilidade (axe) e celular.
//   PLAYWRIGHT_MODULE=… AXE_MODULE=… CHROMIUM_PATH=… node tests/ni2_74_unit_check_browser_test.cjs
'use strict';
const { spawn, execFileSync } = require('node:child_process');
const { mkdtempSync, mkdirSync, writeFileSync, rmSync } = require('node:fs');
const { join, resolve } = require('node:path');
const pw = require(process.env.PLAYWRIGHT_MODULE || 'playwright');
const axePath = require.resolve(process.env.AXE_MODULE || 'axe-core');
const root = resolve(process.env.APP_ROOT || join(__dirname, '..'));
const dir = mkdtempSync('/tmp/ni2-73-');
const ev = join(root, 'docs/evidencias/ni2-74'); mkdirSync(ev, { recursive: true });
const env = { ...process.env, DB_CONNECTION: 'sqlite', DB_DATABASE: join(dir, 't.sqlite'), APP_ENV: 'production', FORCE_HTTPS: 'false', APP_TIMEZONE: 'America/Sao_Paulo', TZ: 'America/Sao_Paulo', MAIL_DRIVER: 'null' };
const fx = (...a) => execFileSync('php', [join(root, 'tests/support/ni2_73_fixture.php'), ...a.map(String)], { cwd: root, env, encoding: 'utf8' });
const sql = (q) => JSON.parse(fx('sql', q));
const out = { checks: [] };
const ck = (n, ok, d) => { out.checks.push({ n, ok: !!ok, d: ok ? undefined : d }); console.log((ok ? 'PASS ' : 'FAIL ') + n + (ok ? '' : ' → ' + String(JSON.stringify(d)).slice(0, 400))); };

(async () => {
  fx('prepare');
  const plan = JSON.parse(fx('draft-plan')).plan_id;
  const meal = Number(sql(`SELECT id FROM meal_plan_meals WHERE meal_plan_id = ${plan}`)[0].id);
  const PORT = 25000 + Math.floor(Math.random() * 2000);
  const srv = spawn('php', ['-d', 'display_errors=0', '-d', 'session.save_path=' + dir, '-S', '127.0.0.1:' + PORT, '-t', 'public'], { cwd: root, env, stdio: 'ignore' });
  await new Promise((r) => setTimeout(r, 1000));
  const B = 'http://127.0.0.1:' + PORT;
  const br = await pw.chromium.launch({ ...(process.env.CHROMIUM_PATH ? { executablePath: process.env.CHROMIUM_PATH, headless: false, args: ['--headless=new'] } : {}) });
  const last = () => sql(`SELECT id, amount, measure_unit, weight_grams, weight_is_estimated FROM meal_plan_items WHERE meal_id = ${meal} ORDER BY id DESC LIMIT 1`)[0];
  try {
    const ctx = await br.newContext({ viewport: { width: 1280, height: 900 }, locale: 'pt-BR' });
    const p = await ctx.newPage(); const errs = []; p.on('pageerror', (e) => errs.push(e.message));
    await p.goto(B + '/login');
    await p.locator('input[name="email"]').fill('nutri1@example.test'); await p.locator('input[name="password"]').fill('Equipe@2026');
    await Promise.all([p.waitForNavigation(), p.locator('form[action="/login"] button[type="submit"]').first().click()]);

    const openAdd = async () => { await p.goto(B + '/planos/' + plan); await p.locator('button', { hasText: '+ Alimento' }).first().click(); await p.waitForTimeout(300); return p.locator('#modal-add-item'); };
    const pick = async (dlg, q, name) => { await dlg.locator('[data-food-query]').fill(q); await p.waitForTimeout(900); await dlg.locator('button.food-result', { hasText: name }).first().click(); await p.waitForTimeout(500); };

    // 1. Medida genérica: mostra o peso padrão e exige a confirmação
    let dlg = await openAdd();
    await pick(dlg, 'arroz integral cozido', 'Arroz, integral, cozido');
    await dlg.locator('input[name="amount"]').fill('0.5');
    await dlg.locator('select[name="measure_unit"]').selectOption('xícara'); await p.waitForTimeout(700);
    let check = dlg.locator('[data-unit-check]');
    let text = await check.innerText();
    const confirm = dlg.locator('input[name="confirm_estimated"]');
    ck('Arroz + xícara: aviso com o peso padrão (150 g) e caixa "Confirmo o peso estimado" visível e obrigatória',
      /«Arroz, integral, cozido»/.test(text) && /150 g/.test(text) && await confirm.isVisible() && await confirm.evaluate((e) => e.required), text);
    ck('Sem marcar, o formulário não envia', await dlg.locator('form').evaluate((f) => !f.checkValidity()));
    await dlg.locator('[data-unit-check]').scrollIntoViewIfNeeded();
    await p.screenshot({ path: join(ev, 'confirmacao-peso-estimado-1280.png') });
    await confirm.check();
    await Promise.all([p.waitForNavigation(), dlg.locator('button[type="submit"]').click()]);
    let row = last();
    ck('Marcando a caixa: item salvo com 0,5 xícara = 75 g estimados', Number(row.amount) === 0.5 && Number(row.weight_grams) === 75 && Number(row.weight_is_estimated) === 1, row);
    ck('Plano mostra "0,5 xícara (~75g, peso estimado)"', /0,5 xícara\s*\(~75g, peso estimado\)/.test(await p.locator('body').innerText()));

    // 2. Medidas do próprio alimento no seletor, sem confirmação
    dlg = await openAdd();
    await pick(dlg, 'pão de queijo casa', 'Pão de queijo da casa');
    const groups = await dlg.locator('select[name="measure_unit"] optgroup[data-unit-food] option').allInnerTexts();
    ck('Seletor ganha o grupo "Medidas deste alimento" com as medidas cadastradas', groups.some((t) => /unidade pequena \(20 g\)/.test(t)) && groups.some((t) => /unidade \(40 g\)/.test(t)), groups);
    await dlg.locator('input[name="amount"]').fill('2');
    await dlg.locator('select[name="measure_unit"]').selectOption('unidade pequena'); await p.waitForTimeout(700);
    text = await dlg.locator('[data-unit-check]').innerText();
    ck('"2 unidade pequena" mostra "= 40 g (medida cadastrada para o alimento)" e não pede confirmação', /= 40 g \(medida cadastrada para o alimento\)/.test(text) && !(await dlg.locator('input[name="confirm_estimated"]').isVisible()), text);
    await Promise.all([p.waitForNavigation(), dlg.locator('button[type="submit"]').click()]);
    row = last();
    ck('Salvo com 40 g exatos', Number(row.weight_grams) === 40 && Number(row.weight_is_estimated) === 0 && row.measure_unit === 'unidade pequena', row);

    // 3. Edição: a mesma conferência no campo livre
    const xic = Number(sql(`SELECT id FROM meal_plan_items WHERE meal_id = ${meal} AND measure_unit = 'xícara'`)[0].id);
    await p.goto(B + '/planos/' + plan);
    const det = p.locator(`[data-testid="edit-plan-item-${xic}"]`); await det.locator('summary').click(); await p.waitForTimeout(800);
    text = await det.locator('[data-unit-check]').innerText();
    ck('Editar item com xícara: aviso e confirmação aparecem já ao abrir', /150 g/.test(text) && await det.locator('input[name="confirm_estimated"]').isVisible(), text);
    await det.locator('input[name="measure_unit"]').fill('g'); await det.locator('input[name="amount"]').fill('80'); await p.waitForTimeout(800);
    ck('Trocando para "80 g" o aviso some e a confirmação deixa de ser exigida', !(await det.locator('input[name="confirm_estimated"]').isVisible()) && await det.locator('form').evaluate((f) => f.checkValidity()));

    // 4. Receita
    await p.goto(B + '/receitas/nova'); await p.waitForTimeout(400);
    const rowR = p.locator('.recipe-ingredient-row').first();
    await rowR.locator('[data-food-query]').fill('arroz integral cozido'); await p.waitForTimeout(900);
    await rowR.locator('button.food-result', { hasText: 'Arroz, integral, cozido' }).first().click(); await p.waitForTimeout(500);
    await rowR.locator('select[name$="[measure_unit]"]').selectOption('xícara'); await rowR.locator('input[name$="[amount]"]').fill('2'); await p.waitForTimeout(800);
    text = await rowR.locator('[data-unit-check]').innerText();
    ck('Receita: ingrediente com xícara pede confirmação (aviso do servidor com o alimento e 150 g por xícara)', /«Arroz, integral, cozido»/.test(text) && /150 g/.test(text) && await rowR.locator('input[name$="[confirm_estimated]"]').evaluate((e) => e.required && e.offsetParent !== null), text);
    await rowR.locator('select[name$="[measure_unit]"]').selectOption('g'); await p.waitForTimeout(800);
    ck('Receita: em gramas a confirmação some e deixa de ser exigida', !(await rowR.locator('input[name$="[confirm_estimated]"]').isVisible()) && /= 2 g/.test(await rowR.locator('[data-unit-check]').innerText()));
    await rowR.locator('select[name$="[measure_unit]"]').selectOption('xícara'); await p.waitForTimeout(800);
    await p.fill('input[name="title"]', 'Receita NI2-74 navegador');
    for (const loc of await p.locator('form[action="/receitas"] [required]').all()) {
      if (await loc.evaluate((e) => (e.tagName === 'INPUT' && e.type === 'text' || e.tagName === 'TEXTAREA') && !e.value)) await loc.fill('Misture.');
    }
    ck('Receita: sem marcar a confirmação o formulário não envia', await p.evaluate(() => !document.querySelector('form[action="/receitas"]').checkValidity()));
    await rowR.locator('input[name$="[confirm_estimated]"]').check();
    await rowR.scrollIntoViewIfNeeded(); await p.screenshot({ path: join(ev, 'receita-confirmacao-1280.png') });
    await Promise.all([p.waitForNavigation(), p.locator('form[action="/receitas"] button[type="submit"]').click()]);
    const body = await p.locator('body').innerText();
    ck('Receita salva; a página avisa "Peso estimado" e mostra sódio "Não disponível no catálogo"', /Peso estimado em 1 ingrediente/.test(body) && /Não disponível no catálogo/.test(body), body.slice(0, 200));

    // 5. Acessibilidade e celular
    const c2 = await br.newContext({ viewport: { width: 1280, height: 900 }, bypassCSP: true, storageState: await ctx.storageState() });
    const p2 = await c2.newPage(); await p2.goto(B + '/planos/' + plan);
    await p2.locator('button', { hasText: '+ Alimento' }).first().click(); await p2.waitForTimeout(300);
    await p2.fill('#modal-add-item [data-food-query]', 'arroz integral cozido'); await p2.waitForTimeout(900);
    await p2.locator('#modal-add-item button.food-result', { hasText: 'Arroz, integral, cozido' }).first().click(); await p2.waitForTimeout(400);
    await p2.locator('#modal-add-item select[name="measure_unit"]').selectOption('xícara'); await p2.waitForTimeout(700);
    await p2.addScriptTag({ path: axePath });
    const v = await p2.evaluate(async () => (await window.axe.run('#modal-add-item', { runOnly: ['wcag2a', 'wcag2aa'] })).violations.map((x) => x.id + ':' + x.nodes.length));
    ck('Diálogo com o aviso de peso estimado sem violações WCAG 2 A/AA (axe)', v.length === 0, v);
    await c2.close();
    const c3 = await br.newContext({ viewport: { width: 390, height: 844 }, storageState: await ctx.storageState() });
    const p3 = await c3.newPage(); await p3.goto(B + '/planos/' + plan); await p3.waitForTimeout(400);
    const over = await p3.evaluate(() => document.documentElement.scrollWidth - document.documentElement.clientWidth);
    ck('Plano no celular (390 px) sem rolagem horizontal', over <= 0, over);
    await c3.close();
    ck('Sem erro de script', errs.length === 0, errs);
  } catch (e) {
    ck('Execução sem exceção', false, e.stack);
  } finally {
    await br.close(); srv.kill();
    writeFileSync(join(ev, 'navegador-resultados.json'), JSON.stringify(out, null, 1));
    rmSync(dir, { recursive: true, force: true });
  }
  const ok = out.checks.filter((c) => c.ok).length;
  console.log(`\nNI2-74 conferência de unidade (navegador): ${ok} PASS, ${out.checks.length - ok} FAIL`);
  if (ok !== out.checks.length) process.exitCode = 1;
})();
