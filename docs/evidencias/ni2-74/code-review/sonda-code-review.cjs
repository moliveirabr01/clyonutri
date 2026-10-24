// Code review NI2-74: sonda real por HTTP + chamadas diretas ao calculador (não faz parte do produto).
'use strict';
const { spawn, execFileSync } = require('node:child_process');
const { mkdtempSync } = require('node:fs');
const { join, resolve } = require('node:path');
const net = require('node:net');
const root = resolve(process.env.APP_ROOT);
const dir = mkdtempSync('/tmp/ni2-73-');
const useMysql = process.env.DB_CONNECTION === 'mysql';
const mysqlDb = 'ni2_73_test_' + Math.random().toString(36).slice(2, 10);
const env = { ...process.env, DB_CONNECTION: useMysql ? 'mysql' : 'sqlite', DB_DATABASE: useMysql ? mysqlDb : join(dir, 'test.sqlite'),
  APP_ENV: 'production', FORCE_HTTPS: 'false', APP_TIMEZONE: 'America/Sao_Paulo', TZ: 'America/Sao_Paulo', MAIL_DRIVER: 'null' };
const php = (code) => execFileSync('php', ['-r', 'require "' + root + '/vendor/autoload.php";' + code], { cwd: root, env, encoding: 'utf8' });
const fx = (...a) => execFileSync('php', [join(root, 'tests/support/ni2_73_fixture.php'), ...a.map(String)], { cwd: root, env, encoding: 'utf8', maxBuffer: 1 << 26 });
const sql = (q) => JSON.parse(fx('sql', q));
const out = { engine: useMysql ? 'mysql' : 'sqlite' };
(async () => {
  if (useMysql) php(`$p=new PDO("mysql:host=".getenv("DB_HOST").";port=".getenv("DB_PORT"),getenv("DB_USERNAME"),getenv("DB_PASSWORD"));$p->exec("CREATE DATABASE ${mysqlDb} CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");`);
  fx('prepare');
  const plan = JSON.parse(fx('draft-plan')).plan_id;
  const meal = Number(sql(`SELECT id FROM meal_plan_meals WHERE meal_plan_id = ${plan}`)[0].id);
  const food = (n) => Number(sql(`SELECT id FROM foods WHERE name = '${n}'`)[0].id);
  const F = { arroz: food('Arroz, integral, cozido'), oleo: food('Óleo, de soja'), pao: food('Pão de queijo da casa'), mel: food('Mel, de abelha'), leitecond: food('Leite, condensado') };
  out.foods = sql(`SELECT id, name, energy_kcal, fiber_g, nutrient_flags FROM foods WHERE id IN (${Object.values(F).join(',')})`);
  const s0 = net.createServer(); await new Promise((r) => s0.listen(0, '127.0.0.1', r)); const port = s0.address().port; await new Promise((r) => s0.close(r));
  const server = spawn('php', ['-d', 'display_errors=0', '-d', 'session.save_path=' + dir, '-S', '127.0.0.1:' + port, '-t', 'public'], { cwd: root, env, stdio: 'ignore' });
  const B = 'http://127.0.0.1:' + port;
  for (let i = 0; i < 100; i++) { try { await fetch(B + '/up'); break; } catch { await new Promise((r) => setTimeout(r, 50)); } }
  let cookie = '';
  const keep = (res) => { for (const c of res.headers.getSetCookie()) { const kv = c.split(';')[0]; if (kv.startsWith('PHPSESSID=')) cookie = kv; } };
  const get = async (p) => { const res = await fetch(B + p, { redirect: 'manual', headers: { Cookie: cookie } }); keep(res); return { status: res.status, text: await res.text() }; };
  const post = async (p, body) => { const res = await fetch(B + p, { method: 'POST', redirect: 'manual', headers: { Cookie: cookie, 'Content-Type': 'application/x-www-form-urlencoded' }, body: new URLSearchParams(body) }); keep(res); return { status: res.status, location: res.headers.get('location') }; };
  try {
    const lp = await get('/login'); const t = lp.text.match(/name="_csrf_token" value="([^"]+)"/)[1];
    await post('/login', { _csrf_token: t, email: 'nutri1@example.test', password: 'Equipe@2026' });
    const page = async () => (await get('/planos/' + plan)).text;
    let html = await page();
    const tok = () => html.match(/name="_csrf_token" value="([^"]+)"/)[1];
    const add = async (foodId, amount, unit) => {
      html = await page();
      await post(`/planos/${plan}/itens`, { _csrf_token: tok(), meal_id: String(meal), food_id: String(foodId), amount: String(amount), measure_unit: unit });
      html = await page();
      const err = (html.match(/Erro ao adicionar alimento:[^<]*/) || [null])[0];
      const row = sql(`SELECT amount, measure_unit, weight_grams, weight_is_estimated, energy_kcal, protein_g, fiber_g FROM meal_plan_items WHERE meal_id = ${meal} ORDER BY id DESC LIMIT 1`)[0];
      return { input: `${amount} ${unit}`, error: err, last_row: err ? null : row };
    };
    out.items = [];
    out.items.push(await add(F.arroz, 0.5, 'xícara'));
    out.items.push(await add(F.arroz, 1.5, 'colher de sopa'));
    out.items.push(await add(F.oleo, 15, 'ml'));
    out.items.push(await add(F.mel, 15, 'ml'));
    out.items.push(await add(F.arroz, 2, 'unidade média'));
    out.items.push(await add(F.arroz, 100, 'ml'));
    out.items.push(await add(F.pao, 1, 'unidade média'));
    out.items.push(await add(F.pao, 1, 'unidade'));
    out.items.push(await add(F.pao, 2, 'unidade pequena'));
    out.items.push(await add(F.leitecond, 30, 'g'));
    out.items.push(await add(F.arroz, 1, 'pitada'));
    out.items.push(await add(F.arroz, 2, 'colheres de sopa'));
    out.items.push(await add(F.arroz, 0, 'g'));
    out.items.push(await add(F.arroz, -5, 'g'));
    out.items.push(await add(F.arroz, 'abc', 'g'));
    out.items.push(await add(F.arroz, 33.333, 'g'));
    html = await page();
    // como a tela mostra as quantidades fracionadas
    out.display = [...html.matchAll(/<td style="padding: 12px 20px; font-weight: 600; color: #334155; width: 20%;">\s*([^<]*?)\s*(?:<span[^>]*>([^<]*)<\/span>)?\s*<\/td>/g)].map((m) => (m[1] + ' ' + (m[2] || '')).replace(/\s+/g, ' ').trim());
    out.plan_totals = sql(`SELECT daily_energy_kcal, protein_g, carbohydrate_g, lipid_g, fiber_g FROM meal_plans WHERE id = ${plan}`)[0];
    out.items_sum = sql(`SELECT SUM(energy_kcal) AS kcal, SUM(fiber_g) AS fib FROM meal_plan_items WHERE meal_id = ${meal}`)[0];
    out.fiber_warning_on_page = /fibra[^<]{0,80}(incomplet|não analisad|NA\b)/i.test(html);
    // Receita com óleo: sódio e peso estimado
    const rf = (await get('/receitas/nova')).text; const rt = rf.match(/name="csrf_token" value="([^"]+)"/)[1];
    const rp = await post('/receitas', { csrf_token: rt, title: 'Arroz sonda 74', meal_type: 'Almoço', servings: '2', instructions: 'x', 'steps[]': 'x', 'ingredients[0][food_id]': String(F.arroz), 'ingredients[0][amount]': '2', 'ingredients[0][measure_unit]': 'xícara de chá', 'ingredients[1][food_id]': String(F.oleo), 'ingredients[1][amount]': '1', 'ingredients[1][measure_unit]': 'colher de sopa' });
    const rid = Number(sql("SELECT id FROM recipes WHERE title = 'Arroz sonda 74'")[0]?.id || 0);
    const rhtml = rid ? (await get('/receitas/' + rid)).text : '';
    out.recipe = { post: rp.status, id: rid, row: rid ? sql(`SELECT energy_kcal_per_serving, sodium_mg_per_serving, fiber_g_per_serving FROM recipes WHERE id = ${rid}`)[0] : null,
      sodium_text: (rhtml.match(/Sódio<\/span>\s*<strong>([^<]*)/) || [])[1], mentions_estimated: /estimad/i.test(rhtml) };
  } finally {
    server.kill();
    if (useMysql) { try { php(`$p=new PDO("mysql:host=".getenv("DB_HOST").";port=".getenv("DB_PORT"),getenv("DB_USERNAME"),getenv("DB_PASSWORD"));$p->exec("DROP DATABASE ${mysqlDb}");`); } catch {} }
  }
  // Calculador direto: determinismo e arredondamento
  out.unit = JSON.parse(php(`
    $c = new \\ClyoNutri\\Domain\\Nutrition\\NutritionCalculator();
    $f = ['energy_kcal' => 0.03, 'protein_g' => 1.005, 'carbohydrate_g' => 2.675, 'lipid_g' => 0, 'fiber_g' => 0];
    $one = $c->calculateForPortion($f, 100, 'g');
    $tiny = $c->calculateMealTotals([['food'=>['energy_kcal'=>0.5],'amount'=>1,'measure_unit'=>'g'],['food'=>['energy_kcal'=>0.5],'amount'=>1,'measure_unit'=>'g'],['food'=>['energy_kcal'=>0.5],'amount'=>1,'measure_unit'=>'g']]);
    $runs = []; for ($i=0;$i<3;$i++) $runs[] = json_encode($c->calculateForPortion(['energy_kcal'=>124.3,'protein_g'=>2.59], 0.5, 'xícara'));
    $err = []; foreach (['pitada','colheres de sopa','Xícara de Chá','XÍCARA','xicara de chá', 'ml', 'NaN'] as $u) { try { $r = $c->calculateForPortion(['energy_kcal'=>100], 1, $u); $err[$u] = $r['weight_grams'].'g'.($r['weight_is_estimated']?' (estimado)':' (exato)'); } catch (\\Throwable $e) { $err[$u] = 'ERRO: '.$e->getMessage(); } }
    try { $c->calculateForPortion(['energy_kcal'=>100], INF, 'g'); $err['INF'] = 'aceito'; } catch (\\Throwable $e) { $err['INF'] = 'ERRO'; }
    echo json_encode(['round_100g' => $one, 'three_items_0_005kcal_each' => $tiny, 'same_input_3x_equal' => count(array_unique($runs)) === 1, 'units' => $err]);
  `));
  console.log(JSON.stringify(out, null, 1));
})();
