// NI2-73 — seletor de alimentos do plano alimentar em navegador real (CSP ativa): termos, porção e origem no
// resultado, contagem "Mostrando X de Y", carregar mais, escolha por teclado e acessibilidade (axe).
//   PLAYWRIGHT_MODULE=… AXE_MODULE=… CHROMIUM_PATH=… node tests/ni2_73_picker_browser_test.cjs
'use strict';
const { spawn, execFileSync } = require('node:child_process');
const { mkdtempSync, mkdirSync, writeFileSync, rmSync } = require('node:fs');
const { join, resolve } = require('node:path');
const pw = require(process.env.PLAYWRIGHT_MODULE || 'playwright');
const axePath = require.resolve(process.env.AXE_MODULE || 'axe-core');
const root = resolve(__dirname, '..');
const dir = mkdtempSync('/tmp/ni2-73-');
const ev = join(root, 'docs/evidencias/ni2-73'); mkdirSync(ev, { recursive: true });
const env = { ...process.env, DB_CONNECTION: 'sqlite', DB_DATABASE: join(dir, 't.sqlite'), APP_ENV: 'production', FORCE_HTTPS: 'false', APP_TIMEZONE: 'America/Sao_Paulo', TZ: 'America/Sao_Paulo', MAIL_DRIVER: 'null' };
const fx = (...a) => execFileSync('php', [join(root, 'tests/support/ni2_73_fixture.php'), ...a.map(String)], { cwd: root, env, encoding: 'utf8' });
const out = { checks: [] };
const ck = (n, ok, d) => { out.checks.push({ n, ok: !!ok, d: ok ? undefined : d }); console.log((ok ? 'PASS ' : 'FAIL ') + n + (ok ? '' : ' → ' + String(JSON.stringify(d)).slice(0, 300))); };

(async () => {
  fx('prepare');
  const plan = JSON.parse(fx('draft-plan')).plan_id;
  const PORT = 21000 + Math.floor(Math.random() * 2000);
  const srv = spawn('php', ['-d', 'session.save_path=' + dir, '-S', '127.0.0.1:' + PORT, '-t', 'public'], { cwd: root, env, stdio: 'ignore' });
  await new Promise((r) => setTimeout(r, 1000));
  const B = 'http://127.0.0.1:' + PORT;
  const br = await pw.chromium.launch({ ...(process.env.CHROMIUM_PATH ? { executablePath: process.env.CHROMIUM_PATH, headless: false, args: ['--headless=new'] } : {}) });
  try {
    const ctx = await br.newContext({ viewport: { width: 1280, height: 900 }, locale: 'pt-BR', bypassCSP: false });
    const p = await ctx.newPage(); const errs = []; p.on('pageerror', (e) => errs.push(e.message));
    await p.goto(B + '/login');
    await p.locator('input[name="email"]').fill('nutri1@example.test'); await p.locator('input[name="password"]').fill('Equipe@2026');
    await Promise.all([p.waitForNavigation(), p.locator('form[action="/login"] button[type="submit"]').first().click()]);
    await p.goto(B + '/planos/' + plan);
    await p.locator('button', { hasText: '+ Alimento' }).first().click(); await p.waitForTimeout(300);
    const dlg = p.locator('#modal-add-item');
    await p.fill('#modal-add-item [data-food-query]', 'arroz integral'); await p.waitForTimeout(1200);
    let res = await dlg.locator('.food-search-results').innerText();
    ck('Busca por termos no seletor: "arroz integral" traz "Arroz, integral, cozido" e "…cru"', /Arroz, integral, cozido/.test(res) && /Arroz, integral, cru/.test(res), res.slice(0, 200));
    ck('Cada resultado mostra origem (Catálogo TACO e nº TACO) e a porção ("valores por 100 g" quando não há medida)', /Catálogo TACO/.test(res) && /TACO 4ª ed\. nº \d+/.test(res) && /Sem medida caseira cadastrada \(valores por 100 g\)/.test(res), res.slice(0, 400));
    await p.fill('#modal-add-item [data-food-query]', 'pão de queijo'); await p.waitForTimeout(1200);
    res = await dlg.locator('.food-search-results').innerText();
    ck('Alimento da clínica com medidas caseiras: "Porção: 1 unidade = 40 g · 1 unidade pequena = 20 g…"', /Personalizado da sua clínica/.test(res) && /Porção: 1 unidade = 40 g/.test(res) && /1 unidade pequena = 20 g/.test(res), res.slice(0, 300));
    await dlg.locator('.food-search-results button.food-result').first().scrollIntoViewIfNeeded();
    await p.screenshot({ path: join(ev, 'seletor-porcao-1280.png') });
    await p.fill('#modal-add-item [data-food-query]', 'a'); await p.waitForTimeout(1200);
    let status = await dlg.locator('[role=status]').innerText();
    ck('Contagem honesta com paginação: "Mostrando 25 de N alimentos"', /Mostrando 25 de \d+ alimentos/.test(status), status);
    await dlg.locator('[data-food-next]').click(); await p.waitForTimeout(1200);
    status = await dlg.locator('[role=status]').innerText();
    ck('"Carregar mais" acrescenta a página 2 (50 de N)', /Mostrando 50 de \d+|alimentos encontrados/.test(status) && await dlg.locator('.food-search-results button.food-result').count() >= 40, status);
    await p.fill('#modal-add-item [data-food-query]', 'frango peito grelhado'); await p.waitForTimeout(1200);
    await p.locator('#modal-add-item [data-food-query]').focus();
    await p.keyboard.press('ArrowDown'); await p.keyboard.press('Enter'); await p.waitForTimeout(200);
    const chosen = await dlg.innerText();
    ck('Escolha por teclado (seta + Enter) seleciona o primeiro resultado', /Selecionado: Frango, peito, sem pele, grelhado/.test(chosen) && (await p.locator('#item-food-select').inputValue()) !== '', chosen.slice(0, 200));
    await p.fill('#modal-add-item [data-food-query]', 'zzzz inexistente'); await p.waitForTimeout(1200);
    status = await dlg.locator('[role=status]').innerText();
    ck('Sem resultado: orienta a tentar outro nome ou menos palavras', /Nenhum alimento encontrado\. Tente outro nome ou menos palavras/.test(status), status);
    await p.fill('#modal-add-item [data-food-query]', 'feijao carioca'); await p.waitForTimeout(1200);
    await p.addScriptTag({ path: axePath }).catch(() => {});
    let v = await p.evaluate(async () => window.axe ? (await window.axe.run('#modal-add-item', { runOnly: ['wcag2a', 'wcag2aa'] })).violations.map((x) => x.id + ':' + x.nodes.length) : ['axe-indisponivel']);
    if (v[0] === 'axe-indisponivel') {
      // CSP bloqueia a injeção do axe: repete numa aba com bypassCSP (só para medir).
      const c2 = await br.newContext({ viewport: { width: 1280, height: 900 }, bypassCSP: true, storageState: await ctx.storageState() });
      const p2 = await c2.newPage(); await p2.goto(B + '/planos/' + plan);
      await p2.locator('button', { hasText: '+ Alimento' }).first().click(); await p2.waitForTimeout(300);
      await p2.fill('#modal-add-item [data-food-query]', 'feijao carioca'); await p2.waitForTimeout(1200);
      await p2.addScriptTag({ path: axePath });
      v = await p2.evaluate(async () => (await window.axe.run('#modal-add-item', { runOnly: ['wcag2a', 'wcag2aa'] })).violations.map((x) => x.id + ':' + x.nodes.length));
      await c2.close();
    }
    ck('Diálogo de busca sem violações WCAG 2 A/AA (axe)', v.length === 0, v);
    ck('Sem erro de script', errs.length === 0, errs);
  } catch (e) {
    ck('Execução sem exceção', false, e.stack);
  } finally {
    await br.close(); srv.kill();
    writeFileSync(join(ev, 'seletor-resultados.json'), JSON.stringify(out, null, 1));
    rmSync(dir, { recursive: true, force: true });
  }
  const ok = out.checks.filter((c) => c.ok).length;
  console.log(`\nNI2-73 seletor (navegador): ${ok} PASS, ${out.checks.length - ok} FAIL`);
  if (ok !== out.checks.length) process.exitCode = 1;
})();
