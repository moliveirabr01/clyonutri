// NI2-74 — conversão de porções e cálculo nutricional: teste real por HTTP (php -S, login real, TACO completa).
// Plano (item, edição, substituição, duplicação), receita, tela que confere a unidade e avisos de valores incompletos.
//   node tests/ni2_74_portion_real_test.cjs     [RESULTS_JSON=…]
//   DB_CONNECTION=mysql DB_HOST=… DB_PORT=… DB_USERNAME=… DB_PASSWORD=… node tests/ni2_74_portion_real_test.cjs
'use strict';
const { spawn, execFileSync } = require('node:child_process');
const { mkdtempSync, rmSync, writeFileSync } = require('node:fs');
const { join, resolve } = require('node:path');
const net = require('node:net');

const root = resolve(process.env.APP_ROOT || join(__dirname, '..'));
const dir = mkdtempSync('/tmp/ni2-73-');
const useMysql = process.env.DB_CONNECTION === 'mysql';
const mysqlDb = 'ni2_73_test_' + Math.random().toString(36).slice(2, 10);
const env = { ...process.env, DB_CONNECTION: useMysql ? 'mysql' : 'sqlite', DB_DATABASE: useMysql ? mysqlDb : join(dir, 'test.sqlite'),
  APP_ENV: 'production', FORCE_HTTPS: 'false', APP_TIMEZONE: 'America/Sao_Paulo', TZ: 'America/Sao_Paulo', MAIL_DRIVER: 'null' };
const mysqlAdmin = (q) => execFileSync('php', ['-r', '$p=new PDO("mysql:host=".getenv("DB_HOST").";port=".getenv("DB_PORT"),getenv("DB_USERNAME"),getenv("DB_PASSWORD"));$p->exec($argv[1]);', q], { env });
const fx = (...a) => execFileSync('php', [join(root, 'tests/support/ni2_73_fixture.php'), ...a.map(String)], { cwd: root, env, encoding: 'utf8', maxBuffer: 1 << 26 });
const sql = (q) => JSON.parse(fx('sql', q));
const results = [];
const check = (crit, name, ok, detail) => { results.push({ crit, name, ok: !!ok, detail: ok ? null : detail ?? null }); console.log(`${ok ? 'PASS' : 'FAIL'} [${crit}] ${name}${ok ? '' : ' → ' + JSON.stringify(detail).slice(0, 400)}`); };
const decode = (s) => String(s || '').replace(/&#0?39;/g, "'").replace(/&quot;/g, '"').replace(/&amp;/g, '&').replace(/&lt;/g, '<').replace(/&gt;/g, '>');

(async () => {
  if (useMysql) mysqlAdmin(`CREATE DATABASE ${mysqlDb} CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci`);
  fx('prepare');
  const plan = JSON.parse(fx('draft-plan')).plan_id;
  const meal = Number(sql(`SELECT id FROM meal_plan_meals WHERE meal_plan_id = ${plan}`)[0].id);
  const id = (n) => Number(sql(`SELECT id FROM foods WHERE name = '${n}'`)[0].id);
  const F = { arroz: id('Arroz, integral, cozido'), oleo: id('Óleo, de soja'), pao: id('Pão de queijo da casa'), leitecond: id('Leite, condensado'), bolo: id('Bolo exclusivo da clínica dois') };
  const s0 = net.createServer(); await new Promise((r) => s0.listen(0, '127.0.0.1', r)); const port = s0.address().port; await new Promise((r) => s0.close(r));
  const server = spawn('php', ['-d', 'display_errors=0', '-d', 'session.save_path=' + dir, '-S', '127.0.0.1:' + port, '-t', 'public'], { cwd: root, env, stdio: 'ignore' });
  const B = 'http://127.0.0.1:' + port;
  for (let i = 0; i < 100; i++) { try { await fetch(B + '/up'); break; } catch { await new Promise((r) => setTimeout(r, 50)); } }
  const user = () => {
    let cookie = '';
    const keep = (res) => { for (const c of res.headers.getSetCookie()) { const kv = c.split(';')[0]; if (kv.startsWith('PHPSESSID=')) cookie = kv; } };
    const get = async (p, accept) => { const res = await fetch(B + p, { redirect: 'manual', headers: { Cookie: cookie, ...(accept ? { Accept: accept } : {}) } }); keep(res); const text = await res.text(); let json = null; try { json = JSON.parse(text); } catch { /* */ } return { status: res.status, text, json }; };
    const post = async (p, body) => { const res = await fetch(B + p, { method: 'POST', redirect: 'manual', headers: { Cookie: cookie, 'Content-Type': 'application/x-www-form-urlencoded' }, body: new URLSearchParams(body) }); keep(res); return { status: res.status, location: res.headers.get('location') || '', text: await res.text() }; };
    const login = async (email) => { const p = await get('/login'); const t = p.text.match(/name="_csrf_token" value="([^"]+)"/)[1]; return post('/login', { _csrf_token: t, email, password: 'Equipe@2026' }); };
    return { get, post, login };
  };
  try {
    const nutri = user(); await nutri.login('nutri1@example.test');
    let html = '';
    const page = async () => { html = (await nutri.get('/planos/' + plan)).text; return html; };
    const tok = () => html.match(/name="_csrf_token" value="([^"]+)"/)[1];
    const flash = () => decode((html.match(/Erro ao adicionar alimento:[^<]*|Erro ao cadastrar substituição:[^<]*/) || [''])[0]).trim();
    const count = () => Number(sql(`SELECT COUNT(*) AS n FROM meal_plan_items WHERE meal_id = ${meal}`)[0].n);
    const last = () => sql(`SELECT id, amount, measure_unit, weight_grams, weight_is_estimated, energy_kcal, fiber_g FROM meal_plan_items WHERE meal_id = ${meal} ORDER BY id DESC LIMIT 1`)[0];
    const add = async (food, amount, unit, confirm) => { await page(); const body = { _csrf_token: tok(), meal_id: String(meal), food_id: String(food), amount: String(amount), measure_unit: unit }; if (confirm) body.confirm_estimated = '1'; await nutri.post(`/planos/${plan}/itens`, body); await page(); return flash(); };

    // ------------------------------------------------ Incompatibilidade → erro explícito; confirmação → aceito
    let n0 = count();
    let msg = await add(F.arroz, 0.5, 'xícara');
    check('incompatível', 'Sem confirmação, "0,5 xícara" de arroz cozido não é salva e a tela explica (alimento, unidade, 150 g, medida cadastrada ou confirmar)',
      count() === n0 && /«Arroz, integral, cozido»/.test(msg) && /«xícara»/.test(msg) && /150 g/.test(msg) && /Confirmo o peso estimado/.test(msg), msg);
    msg = await add(F.arroz, 100, 'ml');
    check('incompatível', '"100 ml" de arroz cozido não vira 100 g exatos: pede confirmação citando a densidade', count() === n0 && /densidade da água/.test(msg), msg);
    msg = await add(F.oleo, 15, 'ml');
    check('incompatível', 'Óleo em ml sem medida cadastrada também pede confirmação', count() === n0 && /«Óleo, de soja»/.test(msg), msg);
    msg = await add(F.arroz, 1, 'pitada', true);
    check('incompatível', 'Unidade desconhecida continua recusada mesmo com a caixa marcada', count() === n0 && /«pitada» não reconhecida/.test(msg), msg);
    msg = await add(F.arroz, 0.5, 'xícara', true);
    let row = last();
    check('confirmação', 'Com "Confirmo o peso estimado": 0,5 xícara salva como 75 g estimados, 93 kcal', !msg && Number(row.weight_grams) === 75 && Number(row.weight_is_estimated) === 1 && Number(row.energy_kcal) === 93, { msg, row });

    // ------------------------------------------------ Medidas exatas do alimento
    msg = await add(F.pao, 1, 'unidade');
    row = last();
    check('porção do alimento', '"1 unidade" de pão de queijo usa a porção cadastrada (40 g, 134 kcal), sem pedir confirmação', !msg && Number(row.weight_grams) === 40 && Number(row.weight_is_estimated) === 0 && Number(row.energy_kcal) === 134, { msg, row });
    msg = await add(F.pao, 3, 'unidades pequenas');
    row = last();
    check('porção do alimento', '"3 unidades pequenas" (plural) casa com a medida cadastrada "unidade pequena" = 60 g', !msg && Number(row.weight_grams) === 60 && Number(row.weight_is_estimated) === 0, { msg, row });
    msg = await add(F.arroz, 33.333, 'g');
    row = last();
    check('precisão', '33,333 g gravado como 33,33 e calculado com 33,33 (41,33 kcal) nos dois bancos', !msg && Number(row.amount) === 33.33 && Number(row.weight_grams) === 33.33 && Number(row.energy_kcal) === 41.33, row);
    msg = await add(F.leitecond, 30, 'g');

    // ------------------------------------------------ Tela do plano
    await page();
    const shown = [...html.matchAll(/<td style="padding: 12px 20px; font-weight: 600; color: #334155; width: 20%;">\s*([^<]*?)\s*(?:<span[^>]*>([^<]*)<\/span>)?\s*<\/td>/g)].map((m) => (m[1] + ' ' + (m[2] || '')).replace(/\s+/g, ' ').trim());
    check('exibição', 'Quantidade como prescrita: "0,5 xícara (~75g, peso estimado)" (antes: "1 xícara")', shown.includes('0,5 xícara (~75g, peso estimado)'), shown);
    check('exibição', '"33,33 g" e "1 unidade (~40g)" sem arredondar para inteiro nem marcar estimado', shown.includes('33,33 g') && shown.includes('1 unidade (~40g)'), shown);
    check('incompleto', 'Totais avisam fibra sem análise do leite condensado ("Totais incompletos")', /data-testid="unknown-nutrients"[\s\S]{0,400}fibra para Leite, condensado/.test(html) && /data-testid="unknown-fiber_g"/.test(html), (html.match(/data-testid="unknown-nutrients"[\s\S]{0,300}/) || [''])[0]);
    const totals = sql(`SELECT daily_energy_kcal AS k FROM meal_plans WHERE id = ${plan}`)[0];
    const sum = sql(`SELECT SUM(energy_kcal) AS k FROM meal_plan_items WHERE meal_id = ${meal}`)[0];
    check('precisão', 'Total do plano = soma dos itens (2 casas)', Math.abs(Number(totals.k) - Math.round(Number(sum.k) * 100) / 100) < 1e-9, { totals, sum });
    const pr = await nutri.get(`/planos/${plan}/imprimir`);
    check('exibição', 'Impressão mostra "0,5 xícara (~75g, peso estimado)"', /0,5 xícara \(~75g, peso estimado\)/.test(pr.text), pr.status);

    // ------------------------------------------------ Edição e substituição
    const xic = Number(sql(`SELECT id FROM meal_plan_items WHERE meal_id = ${meal} AND measure_unit = 'xícara'`)[0].id);
    const rev = () => (html.match(new RegExp(`/itens/${xic}/editar"[\\s\\S]*?name="revision" value="([^"]+)"`)) || [])[1];
    await page();
    let r = await nutri.post(`/planos/${plan}/itens/${xic}/editar`, { _csrf_token: tok(), revision: rev(), amount: '1', measure_unit: 'xícara', notes: 'sem confirmar' });
    await page();
    let after = sql(`SELECT amount, notes FROM meal_plan_items WHERE id = ${xic}`)[0];
    check('edição', 'Editar item com medida genérica sem confirmar: nada muda e a tela explica', Number(after.amount) === 0.5 && after.notes !== 'sem confirmar' && /Confirmo o peso estimado/.test(decode(html)), after);
    r = await nutri.post(`/planos/${plan}/itens/${xic}/editar`, { _csrf_token: tok(), revision: rev(), amount: '1', measure_unit: 'xícara', notes: 'confirmado', confirm_estimated: '1' });
    after = sql(`SELECT amount, weight_grams, weight_is_estimated, notes FROM meal_plan_items WHERE id = ${xic}`)[0];
    check('edição', 'Com confirmação a edição grava 1 xícara = 150 g estimados', Number(after.amount) === 1 && Number(after.weight_grams) === 150 && Number(after.weight_is_estimated) === 1 && after.notes === 'confirmado', after);
    await page();
    const subN = () => Number(sql(`SELECT COUNT(*) AS n FROM meal_plan_substitutions WHERE item_id = ${xic}`)[0].n);
    await nutri.post(`/planos/${plan}/substituicoes`, { _csrf_token: tok(), item_id: String(xic), food_id: String(F.arroz), amount: '2', measure_unit: 'colher de sopa' });
    await page();
    check('substituição', 'Substituição com "colher de sopa" sem confirmação é recusada com explicação', subN() === 0 && /Erro ao cadastrar substituição: Para «Arroz, integral, cozido»/.test(decode(html)), decode((html.match(/Erro ao cadastrar[^<]*/) || [''])[0]));
    await nutri.post(`/planos/${plan}/substituicoes`, { _csrf_token: tok(), item_id: String(xic), food_id: String(F.arroz), amount: '2', measure_unit: 'colher de sopa', confirm_estimated: '1' });
    check('substituição', 'Com confirmação a substituição entra (30 g estimados)', subN() === 1 && Number(sql(`SELECT weight_grams AS g FROM meal_plan_substitutions WHERE item_id = ${xic}`)[0].g) === 30);

    // ------------------------------------------------ Duplicar mantém a confirmação da origem
    await page();
    const before = Number(sql('SELECT COUNT(*) AS n FROM meal_plans')[0].n);
    const dup = await nutri.post(`/planos/${plan}/duplicar`, { _csrf_token: tok() });
    const newPlan = Number((dup.location.match(/\/planos\/(\d+)/) || [])[1]);
    const est = newPlan ? sql(`SELECT COUNT(*) AS n FROM meal_plan_items i JOIN meal_plan_meals m ON m.id = i.meal_id WHERE m.meal_plan_id = ${newPlan} AND i.weight_is_estimated = 1`)[0].n : -1;
    check('duplicação', 'Duplicar o plano copia os itens já confirmados, ainda marcados como estimados', Number(sql('SELECT COUNT(*) AS n FROM meal_plans')[0].n) === before + 1 && newPlan !== plan && Number(est) === 1, { location: dup.location, est });

    // ------------------------------------------------ Tela que confere a unidade
    const conv = (food, amount, unit) => nutri.get(`/catalogo/alimentos/${food}/conversao?amount=${encodeURIComponent(amount)}&unit=${encodeURIComponent(unit)}`, 'application/json');
    r = await conv(F.arroz, 2, 'xícara');
    check('conferência', 'Conversão: arroz + xícara → precisa confirmar, 300 g, mensagem igual à do servidor', r.status === 200 && r.json.needs_confirmation === true && r.json.grams === 300 && /Confirmo o peso estimado/.test(r.json.message), r.json);
    r = await conv(F.pao, 2, 'unidade');
    check('conferência', 'Conversão: pão de queijo + unidade → 80 g exatos; lista as medidas do alimento', r.status === 200 && r.json.needs_confirmation === false && r.json.grams === 80 && r.json.source === 'food_serving' && r.json.measures.some((m) => m.name === 'unidade pequena' && m.grams === 20), r.json);
    r = await conv(F.leitecond, 1, 'pitada');
    check('conferência', 'Conversão: unidade desconhecida → ok=false com a mensagem; fibra sem análise informada', r.status === 200 && r.json.ok === false && /não reconhecida/.test(r.json.message) && r.json.unknown_nutrients.includes('fiber_g'), r.json);
    r = await conv(F.bolo, 1, 'g');
    check('conferência', 'Alimento de outra clínica → 404 sem dados', r.status === 404 && !/Bolo/.test(r.text), r.status);
    const recep = user(); await recep.login('recepcao@example.test');
    r = await recep.get(`/catalogo/alimentos/${F.arroz}/conversao?amount=1&unit=g`, 'application/json');
    check('conferência', 'Recepção → 403', r.status === 403, r.status);
    r = await user().get(`/catalogo/alimentos/${F.arroz}/conversao?amount=1&unit=g`, 'application/json');
    check('conferência', 'Sem login → 401', r.status === 401, r.status);
    r = await conv('abc', 1, 'g');
    check('conferência', 'Id inválido → 404/422 (sem 500)', r.status === 404 || r.status === 422, r.status);

    // ------------------------------------------------ Receita
    const rf = (await nutri.get('/receitas/nova')).text; const rt = rf.match(/name="csrf_token" value="([^"]+)"/)[1];
    const recipes = () => Number(sql('SELECT COUNT(*) AS n FROM recipes')[0].n);
    const rb = recipes();
    const base = { csrf_token: rt, title: 'Arroz NI2-74', meal_type: 'Almoço', servings: '2', instructions: 'x', 'steps[]': 'x',
      'ingredients[0][food_id]': String(F.arroz), 'ingredients[0][amount]': '2', 'ingredients[0][measure_unit]': 'xícara',
      'ingredients[1][food_id]': String(F.leitecond), 'ingredients[1][amount]': '30', 'ingredients[1][measure_unit]': 'g' };
    r = await nutri.post('/receitas', base);
    check('receita', 'Receita com "xícara" sem confirmação não é salva; erro cita o ingrediente 1 e o peso padrão', recipes() === rb && r.status === 400 && /Ingrediente 1: Para «Arroz, integral, cozido»/.test(decode(r.text)), [r.status, decode(r.text).slice(0, 200)]);
    r = await nutri.post('/receitas', { ...base, 'ingredients[0][confirm_estimated]': '1' });
    const rid = Number(sql("SELECT id FROM recipes WHERE title = 'Arroz NI2-74'")[0]?.id || 0);
    const rec = rid ? sql(`SELECT energy_kcal_per_serving AS k FROM recipes WHERE id = ${rid}`)[0] : null;
    check('receita', 'Com confirmação a receita é salva: (300 g arroz + 30 g leite condensado) ÷ 2 = 232,95 kcal por porção', rid > 0 && Number(rec.k) === 232.95, { status: r.status, rec });
    const rp = rid ? (await nutri.get('/receitas/' + rid)).text : '';
    check('receita', 'Página da receita: "peso estimado" no ingrediente e no aviso; sódio "Não disponível no catálogo" (antes "0,0 mg")',
      /data-testid="recipe-estimated"/.test(rp) && /Peso estimado<\/strong> em 1 ingrediente/.test(rp) && /data-testid="recipe-sodium"[^>]*>Não disponível no catálogo/.test(rp) && !/0,0 mg/.test(rp), rp.length);
    check('receita', 'Página da receita avisa fibra sem análise (leite condensado)', /Valores incompletos:<\/strong> sem análise na TACO de fibra \(Leite, condensado\)/.test(rp));
    check('receita', 'Quantidade do ingrediente como digitada ("2 xícara", "30 g")', />2 xícara</.test(rp) && />30 g</.test(rp));
  } catch (e) {
    check('erro', 'Execução sem exceção', false, e.stack);
  } finally {
    server.kill();
    if (useMysql) { try { mysqlAdmin(`DROP DATABASE ${mysqlDb}`); } catch { /* */ } }
    rmSync(dir, { recursive: true, force: true });
  }
  const pass = results.filter((x) => x.ok).length;
  console.log(`\nNI2-74 (${useMysql ? 'MySQL' : 'SQLite'}): ${pass} PASS, ${results.length - pass} FAIL`);
  if (process.env.RESULTS_JSON) writeFileSync(process.env.RESULTS_JSON, JSON.stringify({ driver: useMysql ? 'mysql' : 'sqlite', pass, fail: results.length - pass, results }, null, 1));
  if (pass !== results.length) process.exitCode = 1;
})();
