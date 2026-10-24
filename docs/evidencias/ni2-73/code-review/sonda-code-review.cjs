// Code review NI2-73: sondas reais por HTTP (não faz parte do produto).
'use strict';
const { spawn, execFileSync } = require('node:child_process');
const { mkdtempSync, writeFileSync } = require('node:fs');
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
const out = { engine: useMysql ? 'mysql' : 'sqlite' };
(async () => {
  if (useMysql) php(`$p=new PDO("mysql:host=".getenv("DB_HOST").";port=".getenv("DB_PORT"),getenv("DB_USERNAME"),getenv("DB_PASSWORD"));$p->exec("CREATE DATABASE ${mysqlDb} CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");`);
  JSON.parse(fx('prepare'));
  const s0 = net.createServer(); await new Promise((r) => s0.listen(0, '127.0.0.1', r)); const port = s0.address().port; await new Promise((r) => s0.close(r));
  const server = spawn('php', ['-d', 'display_errors=0', '-d', 'session.save_path=' + dir, '-S', '127.0.0.1:' + port, '-t', 'public'], { cwd: root, env, stdio: 'ignore' });
  const B = 'http://127.0.0.1:' + port;
  for (let i = 0; i < 100; i++) { try { await fetch(B + '/up'); break; } catch { await new Promise((r) => setTimeout(r, 50)); } }
  let cookie = '';
  const keep = (res) => { for (const c of res.headers.getSetCookie()) { const kv = c.split(';')[0]; if (kv.startsWith('PHPSESSID=')) cookie = kv; } };
  const req = async (p) => { const t0 = performance.now(); const res = await fetch(B + p, { redirect: 'manual', headers: { Cookie: cookie } }); keep(res); const text = await res.text(); let json = null; try { json = JSON.parse(text); } catch {} return { status: res.status, text, json, ms: Math.round(performance.now() - t0) }; };
  try {
    const lp = await req('/login'); const t = lp.text.match(/name="_csrf_token" value="([^"]+)"/)[1];
    const lr = await fetch(B + '/login', { method: 'POST', redirect: 'manual', headers: { Cookie: cookie, 'Content-Type': 'application/x-www-form-urlencoded' }, body: new URLSearchParams({ _csrf_token: t, email: 'equipe@example.test', password: 'Equipe@2026' }) }); keep(lr);
    const s = async (q) => req('/catalogo/alimentos/busca?q=' + encodeURIComponent(q) + '&page=1');
    // A. Receitas
    const visible = JSON.parse(fx('sql', 'SELECT COUNT(*) AS n FROM foods WHERE clinic_id IS NULL OR clinic_id = 1'))[0].n;
    const rf = await req('/receitas/nova');
    const m = rf.text.match(/const foodOptions = (\[.*?\]);/s); const opts = m ? JSON.parse(m[1]) : [];
    const names = opts.map((o) => o.name);
    out.recipe = { status: rf.status, visible_for_clinic: Number(visible), options: opts.length, last_option: names[names.length - 1],
      has: Object.fromEntries(['Uva, Itália, crua', 'Tomate, com semente, cru', 'Pão de queijo da casa', 'Ovo, de galinha, inteiro, cozido/10minutos', 'Arroz, integral, cozido'].map((n) => [n, names.includes(n)])) };
    // C. Plurais irregulares
    out.plurals = {};
    for (const [q, want] of [['feijões', /^Feijão/], ['pães', /^Pão/], ['limões', /^Limão/], ['melões', /^Melão/], ['ovos', /^Ovo/], ['maçãs', /^Maçã/]]) {
      const r = await s(q); const n = (r.json?.data || []).map((x) => x.name); out.plurals[q] = { total: r.json?.total, top: n[0] || null, ok: n.some((x) => want.test(x)) };
    }
    // D. Ordem
    out.order = {};
    for (const q of ['maca', 'pao', 'acai', 'leite', 'queijo', 'cha']) { const r = await s(q); out.order[q] = (r.json?.data || []).slice(0, 12).map((x) => x.name); }
    // E. Custo do sync no primeiro pedido depois de SQL manual
    const N = Number(process.env.RAW_N || 5000);
    php(`$db=\\ClyoNutri\\Core\\Database\\Connection::getInstance();$db->beginTransaction();$st=$db->prepare("INSERT INTO foods (clinic_id,name,category,source,energy_kcal,protein_g,carbohydrate_g,lipid_g,fiber_g,serving_size_g,serving_unit,is_custom) VALUES (NULL,?,'Outros','TACO',50,1,1,1,0,100,'g',0)");for($i=0;$i<${N};$i++)$st->execute(["Alimento manual $i, cru"]);$db->commit();`);
    const first = await s('arroz'); const second = await s('arroz');
    out.sync = { raw_rows: N, first_ms: first.ms, second_ms: second.ms, first_status: first.status,
      still_null: Number(JSON.parse(fx('sql', 'SELECT COUNT(*) AS n FROM foods WHERE search_text IS NULL'))[0].n) };
  } finally {
    server.kill();
    if (useMysql) { try { php(`$p=new PDO("mysql:host=".getenv("DB_HOST").";port=".getenv("DB_PORT"),getenv("DB_USERNAME"),getenv("DB_PASSWORD"));$p->exec("DROP DATABASE ${mysqlDb}");`); } catch {} }
  }
  console.log(JSON.stringify(out, null, 1));
})();
