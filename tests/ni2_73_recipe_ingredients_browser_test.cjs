// NI2-73 (code review M1) — ingredientes da receita pela busca do catálogo, em navegador real (CSP ativa).
// Antes: <select> com os 300 primeiros alimentos em ordem alfabética (Tomate, Uva, Ovo e os da clínica ficavam fora).
//   PLAYWRIGHT_MODULE=… AXE_MODULE=… CHROMIUM_PATH=… node tests/ni2_73_recipe_ingredients_browser_test.cjs
'use strict';
const { spawn, execFileSync } = require('node:child_process');
const { mkdtempSync, mkdirSync, writeFileSync, rmSync } = require('node:fs');
const { join, resolve } = require('node:path');
const pw = require(process.env.PLAYWRIGHT_MODULE || 'playwright');
const axePath = require.resolve(process.env.AXE_MODULE || 'axe-core');
const root = resolve(process.env.APP_ROOT || join(__dirname, '..'));
const dir = mkdtempSync('/tmp/ni2-73-');
const ev = join(root, 'docs/evidencias/ni2-73'); mkdirSync(ev, { recursive: true });
const env = { ...process.env, DB_CONNECTION: 'sqlite', DB_DATABASE: join(dir, 't.sqlite'), APP_ENV: 'production', FORCE_HTTPS: 'false', APP_TIMEZONE: 'America/Sao_Paulo', TZ: 'America/Sao_Paulo', MAIL_DRIVER: 'null' };
const fx = (...a) => execFileSync('php', [join(root, 'tests/support/ni2_73_fixture.php'), ...a.map(String)], { cwd: root, env, encoding: 'utf8' });
const sql = (q) => JSON.parse(fx('sql', q));
const out = { checks: [] };
const ck = (n, ok, d) => { out.checks.push({ n, ok: !!ok, d: ok ? undefined : d }); console.log((ok ? 'PASS ' : 'FAIL ') + n + (ok ? '' : ' → ' + String(JSON.stringify(d)).slice(0, 400))); };

(async () => {
  fx('prepare');
  const PORT = 23000 + Math.floor(Math.random() * 2000);
  const srv = spawn('php', ['-d', 'display_errors=0', '-d', 'session.save_path=' + dir, '-S', '127.0.0.1:' + PORT, '-t', 'public'], { cwd: root, env, stdio: 'ignore' });
  await new Promise((r) => setTimeout(r, 1000));
  const B = 'http://127.0.0.1:' + PORT;
  const br = await pw.chromium.launch({ ...(process.env.CHROMIUM_PATH ? { executablePath: process.env.CHROMIUM_PATH, headless: false, args: ['--headless=new'] } : {}) });
  const login = async (ctx, email) => {
    const p = await ctx.newPage(); await p.goto(B + '/login');
    await p.locator('input[name="email"]').fill(email); await p.locator('input[name="password"]').fill('Equipe@2026');
    await Promise.all([p.waitForNavigation(), p.locator('form[action="/login"] button[type="submit"]').first().click()]);
    return p;
  };
  const pick = async (p, row, query, name) => {
    const box = p.locator('.recipe-ingredient-row').nth(row);
    await box.locator('[data-food-query]').fill(query); await p.waitForTimeout(900);
    const btn = box.locator('button.food-result', { hasText: name }).first();
    await btn.click(); await p.waitForTimeout(100);
    return box;
  };
  try {
    const visible = Number(sql('SELECT COUNT(*) AS n FROM foods WHERE clinic_id IS NULL OR clinic_id = 1')[0].n);
    const ctx = await br.newContext({ viewport: { width: 1280, height: 900 }, locale: 'pt-BR' });
    const p = await login(ctx, 'nutri1@example.test'); const errs = []; p.on('pageerror', (e) => errs.push(e.message));
    const html = await (await ctx.request.get(B + '/receitas/nova')).text();
    ck('Formulário não carrega mais a lista fixa de 300 alimentos', !/foodOptions/.test(html) && (html.match(/<option value="\d+"/g) || []).length === 0, (html.match(/<option value="\d+"/g) || []).length);
    await p.goto(B + '/receitas/nova'); await p.waitForTimeout(400);
    ck('Primeira linha de ingrediente já vem com a busca', await p.locator('.recipe-ingredient-row [data-food-query]').count() === 1);

    // Alimentos que ficavam fora da lista antiga (depois de "Frango, coxa…") e um da própria clínica
    let box = await pick(p, 0, 'tomate com semente', 'Tomate, com semente, cru');
    ck('Busca acha "Tomate, com semente, cru" (estava fora dos 300)', /Selecionado: Tomate, com semente, cru/.test(await box.innerText()));
    await p.locator('[data-call="addIngredientRow"]').click();
    await p.locator('.recipe-ingredient-row').nth(1).locator('[data-food-query]').focus(); await p.waitForTimeout(900);
    const st0 = await p.locator('.recipe-ingredient-row').nth(1).locator('[role=status]').innerText();
    ck(`Catálogo inteiro acessível na receita: campo vazio mostra "Mostrando 25 de ${visible}" (todos os alimentos da clínica)`, st0.includes(`Mostrando 25 de ${visible} alimentos`), st0);
    box = await pick(p, 1, 'uva italia', 'Uva, Itália, crua');
    ck('Segunda linha (criada depois) também busca: "Uva, Itália, crua"', /Selecionado: Uva, Itália, crua/.test(await box.innerText()));
    await p.locator('[data-call="addIngredientRow"]').click();
    box = await pick(p, 2, 'pão de queijo casa', 'Pão de queijo da casa');
    ck('Alimento da própria clínica aparece, com origem e porção', /Selecionado: Pão de queijo da casa/.test(await box.innerText()) && /Personalizado da sua clínica/.test(await box.innerText()) && /1 unidade = 40 g/.test(await box.innerText()));
    await box.locator('input[type=number]').fill('2'); await box.locator('select[name$="[measure_unit]"]').selectOption('unidade');
    await p.locator('[data-call="addIngredientRow"]').click();
    await p.locator('.recipe-ingredient-row').nth(3).locator('[data-food-query]').fill('ovo'); await p.waitForTimeout(900);
    const valid = await p.evaluate(() => document.querySelector('form[action="/receitas"]').checkValidity());
    ck('Linha com busca digitada mas sem alimento escolhido impede salvar', valid === false);
    await p.locator('.recipe-ingredient-row').nth(3).locator('[data-remove-parent]').click();
    ck('Remover a linha tira o ingrediente', await p.locator('.recipe-ingredient-row').count() === 3);
    await p.fill('input[name="title"]', 'Salada NI2-73');
    for (const loc of await p.locator('form[action="/receitas"] [required]').all()) {
      if (await loc.evaluate((e) => (e.tagName === 'INPUT' && e.type === 'text' || e.tagName === 'TEXTAREA') && !e.value)) await loc.fill('Misture tudo.');
    }
    await p.setViewportSize({ width: 1280, height: 900 });
    await p.locator('.recipe-ingredient-row').first().scrollIntoViewIfNeeded();
    await p.screenshot({ path: join(ev, 'receita-ingredientes-1280.png') });
    await Promise.all([p.waitForNavigation(), p.locator('form[action="/receitas"] button[type="submit"]').click()]);
    const saved = JSON.parse(fx('recipe'));
    const ing = saved.ingredients.map((i) => i.name);
    ck('Receita salva com os três ingredientes escolhidos pela busca', saved.recipe && saved.recipe.title === 'Salada NI2-73' && ['Tomate, com semente, cru', 'Uva, Itália, crua', 'Pão de queijo da casa'].every((n) => ing.includes(n)) && ing.length === 3, saved);
    const pq = saved.ingredients.find((i) => i.name === 'Pão de queijo da casa');
    ck('Quantidade e medida do ingrediente salvas (2 unidade)', pq && Number(pq.amount) === 2 && pq.measure_unit === 'unidade', pq);

    // XSS: nome hostil vindo do catálogo vira texto (nenhum diálogo, nenhum elemento injetado)
    const evil = 'Ataque `${alert(1)}` </script><img src=x onerror=alert(2)> "aspas"';
    fx('raw-insert', evil);
    let dialogs = 0; p.on('dialog', async (d) => { dialogs++; await d.dismiss(); });
    await p.goto(B + '/receitas/nova'); await p.waitForTimeout(300);
    await p.locator('.recipe-ingredient-row [data-food-query]').first().fill('ataque'); await p.waitForTimeout(900);
    const xbtn = p.locator('.recipe-ingredient-row button.food-result').first();
    const shown = await xbtn.locator('strong').innerText(); await xbtn.click(); await p.waitForTimeout(200);
    ck('XSS: nome hostil aparece como texto, sem alert e sem <img> injetado', shown === evil && dialogs === 0 && await p.locator('#ingredientsContainer img').count() === 0, { shown, dialogs });

    // Negativo: alimento de outra clínica enviado direto no POST
    const bolo = Number(sql("SELECT id FROM foods WHERE name = 'Bolo exclusivo da clínica dois'")[0].id);
    const before = Number(sql('SELECT COUNT(*) AS n FROM recipes')[0].n);
    const form = await (await ctx.request.get(B + '/receitas/nova')).text();
    const token = (form.match(/name="csrf_token" value="([^"]+)"/) || [])[1];
    const r = await ctx.request.post(B + '/receitas', { form: { csrf_token: token, title: 'Intrusa', meal_type: 'Almoço', servings: '1', instructions: 'x', 'steps[]': 'x', 'ingredients[0][food_id]': String(bolo), 'ingredients[0][amount]': '100', 'ingredients[0][measure_unit]': 'g' }, maxRedirects: 0 });
    const after = Number(sql('SELECT COUNT(*) AS n FROM recipes')[0].n);
    ck('Alimento de outra clínica no POST é recusado e nada é salvo', after === before, { status: r.status(), before, after });

    // Recepção: o catálogo é só de nutricionistas; a tela diz isso em vez de "tente novamente"
    const c3 = await br.newContext({ viewport: { width: 1280, height: 900 } });
    const pr = await login(c3, 'recepcao@example.test');
    const rr = await pr.goto(B + '/receitas/nova'); await pr.waitForTimeout(400);
    if (await pr.locator('[data-food-query]').count()) {
      await pr.locator('[data-food-query]').first().focus(); await pr.waitForTimeout(900);
      const st = await pr.locator('.recipe-ingredient-row [role=status]').first().innerText();
      ck('Recepção vê que o perfil não acessa o catálogo', /Seu perfil não tem acesso ao catálogo de alimentos/.test(st), st);
    } else {
      ck('Recepção não abre o formulário de receita', !/ingredientsContainer/.test(await pr.content()), { status: rr && rr.status(), url: pr.url() });
    }
    await c3.close();

    // Celular: sem rolagem horizontal
    const c4 = await br.newContext({ viewport: { width: 390, height: 844 }, storageState: await ctx.storageState() });
    const pm = await c4.newPage(); await pm.goto(B + '/receitas/nova'); await pm.waitForTimeout(400);
    await pm.locator('[data-food-query]').first().fill('arroz integral'); await pm.waitForTimeout(900);
    const overflow = await pm.evaluate(() => document.documentElement.scrollWidth - document.documentElement.clientWidth);
    await pm.locator('.recipe-ingredient-row').first().scrollIntoViewIfNeeded();
    await pm.screenshot({ path: join(ev, 'receita-ingredientes-390.png') });
    ck('Celular (390 px): linha do ingrediente sem rolagem horizontal', overflow <= 0, overflow);
    await c4.close();

    // Acessibilidade (axe precisa de bypassCSP só para ser injetado)
    const c2 = await br.newContext({ viewport: { width: 1280, height: 900 }, bypassCSP: true, storageState: await ctx.storageState() });
    const p2 = await c2.newPage(); await p2.goto(B + '/receitas/nova'); await p2.waitForTimeout(400);
    await p2.locator('[data-food-query]').first().fill('feijao carioca'); await p2.waitForTimeout(900);
    await p2.addScriptTag({ path: axePath });
    const v = await p2.evaluate(async () => (await window.axe.run('#ingredientsContainer', { runOnly: ['wcag2a', 'wcag2aa'] })).violations.map((x) => x.id + ':' + x.nodes.length));
    ck('Linhas de ingrediente sem violações WCAG 2 A/AA (axe)', v.length === 0, v);
    await c2.close();
    ck('Sem erro de script', errs.length === 0, errs);
  } catch (e) {
    ck('Execução sem exceção', false, e.stack);
  } finally {
    await br.close(); srv.kill();
    writeFileSync(join(ev, 'receita-ingredientes-resultados.json'), JSON.stringify(out, null, 1));
    rmSync(dir, { recursive: true, force: true });
  }
  const ok = out.checks.filter((c) => c.ok).length;
  console.log(`\nNI2-73 receitas (navegador): ${ok} PASS, ${out.checks.length - ok} FAIL`);
  if (ok !== out.checks.length) process.exitCode = 1;
})();
