// NI2-73 — busca no catálogo de alimentos: teste real por HTTP com base representativa.
// public/index.php em `php -S` (APP_ENV=production), banco isolado (SQLite ou MySQL), TACO 4ª ed. completa (CSV do
// repositório) + alimentos sintéticos de outras clínicas (BULK_CLINICS × BULK_PER) + extras da própria clínica.
// Login real da nutricionista (formulário com CSRF). Mede o tempo de cada busca pelo HTTP (p50/p95).
//   node tests/ni2_73_food_search_real_test.cjs   [RESULTS_JSON=… PERF_JSON=… BULK_CLINICS=400 BULK_PER=50 BULK_OWN=300]
//   DB_CONNECTION=mysql DB_HOST=… DB_PORT=… DB_USERNAME=… DB_PASSWORD=… node tests/ni2_73_food_search_real_test.cjs
'use strict';
const { spawn, execFileSync } = require('node:child_process');
const { mkdtempSync, rmSync, writeFileSync } = require('node:fs');
const { join, resolve } = require('node:path');
const net = require('node:net');

const root = resolve(process.env.APP_ROOT || join(__dirname, '..'));
const dir = mkdtempSync('/tmp/ni2-73-');
const useMysql = process.env.DB_CONNECTION === 'mysql';
const mysqlDb = 'ni2_73_test_' + Math.random().toString(36).slice(2, 10);
const BULK = [Number(process.env.BULK_CLINICS || 400), Number(process.env.BULK_PER || 50), Number(process.env.BULK_OWN || 300)];
const P95_BUDGET_MS = Number(process.env.P95_BUDGET_MS || 300);
const env = { ...process.env, DB_CONNECTION: useMysql ? 'mysql' : 'sqlite', DB_DATABASE: useMysql ? mysqlDb : join(dir, 'test.sqlite'),
  APP_ENV: 'production', FORCE_HTTPS: 'false', APP_TIMEZONE: 'America/Sao_Paulo', TZ: 'America/Sao_Paulo', MAIL_DRIVER: 'null' };
const mysqlAdmin = (sqlText) => execFileSync('php', ['-r', '$p=new PDO("mysql:host=".getenv("DB_HOST").";port=".getenv("DB_PORT"),getenv("DB_USERNAME"),getenv("DB_PASSWORD"));$p->exec($argv[1]);', sqlText], { env });
const results = [];
function check(crit, name, ok, detail) {
  results.push({ crit, name, ok: !!ok, detail: ok ? null : (detail === undefined ? null : detail) });
  console.log(`${ok ? 'PASS' : 'FAIL'} [${crit}] ${name}${!ok && detail !== undefined ? ' → ' + JSON.stringify(detail).slice(0, 500) : ''}`);
}
const fx = (...args) => execFileSync('php', [join(root, 'tests/support/ni2_73_fixture.php'), ...args.map(String)], { cwd: root, env, encoding: 'utf8', stdio: ['ignore', 'pipe', 'pipe'], maxBuffer: 1 << 26 });
const sql = (q) => JSON.parse(fx('sql', q));
const pct = (arr, p) => { const s = [...arr].sort((a, b) => a - b); return s[Math.min(s.length - 1, Math.ceil(p / 100 * s.length) - 1)]; };

(async () => {
  if (useMysql) mysqlAdmin(`CREATE DATABASE ${mysqlDb} CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci`);
  const prep = JSON.parse(fx('prepare'));
  const sock = net.createServer(); await new Promise((r) => sock.listen(0, '127.0.0.1', r)); const port = sock.address().port; await new Promise((r) => sock.close(r));
  const server = spawn('php', ['-d', 'display_errors=0', '-d', 'session.save_path=' + dir, '-S', '127.0.0.1:' + port, '-t', 'public'], { cwd: root, env, stdio: 'ignore' });
  const B = 'http://127.0.0.1:' + port;
  for (let i = 0; i < 100; i++) { try { await fetch(B + '/up'); break; } catch { await new Promise((r) => setTimeout(r, 50)); } }
  const webUser = () => {
    let cookie = '';
    const req = async (path) => {
      const t0 = performance.now();
      const res = await fetch(B + path, { redirect: 'manual', headers: { Cookie: cookie, Accept: 'application/json' } });
      for (const c of (res.headers.getSetCookie ? res.headers.getSetCookie() : [])) { const kv = c.split(';')[0]; if (kv.startsWith('PHPSESSID=')) cookie = kv; }
      const text = await res.text(); let json = null; try { json = JSON.parse(text); } catch { /* */ }
      return { status: res.status, text, json, ms: performance.now() - t0, location: res.headers.get('location') || '' };
    };
    const login = async (email) => {
      const p = await req('/login'); const t = (p.text.match(/name="_csrf_token" value="([^"]+)"/) || [])[1];
      const res = await fetch(B + '/login', { method: 'POST', redirect: 'manual', headers: { Cookie: cookie, 'Content-Type': 'application/x-www-form-urlencoded' }, body: new URLSearchParams({ _csrf_token: t, email, password: 'Equipe@2026' }) });
      for (const c of (res.headers.getSetCookie ? res.headers.getSetCookie() : [])) { const kv = c.split(';')[0]; if (kv.startsWith('PHPSESSID=')) cookie = kv; }
      return res.status;
    };
    return { req, login };
  };
  const perf = {};
  try {
    const nutri = webUser(); await nutri.login('equipe@example.test');
    const s = async (q, page = 1) => nutri.req('/catalogo/alimentos/busca?q=' + encodeURIComponent(q) + '&page=' + page);
    const names = (r) => (r.json && r.json.data ? r.json.data.map((x) => x.name) : []);
    check('base', `TACO 4ª ed. importada do CSV (${prep.taco.inserted} alimentos)`, prep.taco.inserted >= 590, prep.taco);

    // ------------------------------------------------------------------ Nome e termos
    let r = await s('arroz integral');
    check('termos', 'Dois termos fora da ordem e da pontuação da TACO: "arroz integral" acha "Arroz, integral, cozido" e "…cru"', r.status === 200 && names(r).includes('Arroz, integral, cozido') && names(r).includes('Arroz, integral, cru'), names(r));
    r = await s('cozido arroz integral');
    check('termos', 'Ordem invertida ("cozido arroz integral") acha o mesmo alimento', names(r)[0] === 'Arroz, integral, cozido', names(r));
    r = await s('FEIJAO   carioca');
    check('termos', 'Sem acento, maiúsculas e espaços extras ("FEIJAO   carioca")', names(r).includes('Feijão, carioca, cozido'), names(r));
    r = await s('pão francês');
    check('termos', '"pão francês" acha "Pão, trigo, francês" (termos não precisam estar juntos)', names(r)[0] === 'Pão, trigo, francês', names(r));
    r = await s('ovos');
    check('termos', 'Plural simples ("ovos") acha "Ovo, …"', names(r).length > 0 && names(r).every((n) => /ovo/i.test(n)) && /^Ovo,/.test(names(r)[0]), names(r).slice(0, 4));
    r = await s('frango peito grelhado');
    check('termos', 'Três termos restringem ("frango peito grelhado" → só o peito grelhado)', names(r).length >= 1 && names(r)[0] === 'Frango, peito, sem pele, grelhado' && names(r).every((n) => /grelhad/i.test(n)), names(r));
    r = await s('queijo casa');
    check('termos', 'Alimento da própria clínica entra na busca ("queijo casa" → "Pão de queijo da casa")', names(r).includes('Pão de queijo da casa'), names(r));

    // ------------------------------------------------------------------ Ordem (regra visível, sem ranking opaco)
    r = await s('maçã');
    check('ordem', '"maçã": os nomes que começam com a palavra vêm antes de "Macarrão" e "Banana, maçã"', /^Maçã,/.test(names(r)[0]) && names(r).findIndex((n) => /^Macarr/.test(n)) > names(r).findIndex((n) => /^Maçã/.test(n)) && names(r).indexOf('Banana, maçã, crua') > names(r).findIndex((n) => /^Macarr/.test(n)), names(r).slice(0, 8));
    r = await s('leite integral');
    check('ordem', '"leite integral": o que começa com "Leite" vem antes de "Canjica, com leite integral"', /^Leite,/.test(names(r)[0]), names(r).slice(0, 4));
    r = await s('fran');
    check('autocomplete', 'Começo de palavra ("fran") já traz Frango primeiro', /^Frango/.test(names(r)[0]), names(r).slice(0, 4));
    r = await s('');
    check('autocomplete', 'Campo vazio lista o catálogo (25 por página, em ordem alfabética)', r.status === 200 && r.json.data.length === 25 && r.json.has_more === true, r.json && r.json.data.length);

    // ------------------------------------------------------------------ Paginação
    const p1 = await s('a', 1), p2 = await s('a', 2);
    const ids1 = p1.json.data.map((x) => x.id), ids2 = p2.json.data.map((x) => x.id);
    check('paginação', 'Página 1 com 25, has_more e total; página 2 sem repetir', ids1.length === 25 && p1.json.has_more && p1.json.total > 50 && ids2.length === 25 && !ids2.some((i) => ids1.includes(i)), { t: p1.json.total, n2: ids2.length });
    const totalA = p1.json.total; const pages = Math.ceil(totalA / 25);
    if (pages <= 100) {
      const last = await s('a', pages);
      check('paginação', 'Última página: has_more=false', last.json.has_more === false && last.json.data.length === totalA - (pages - 1) * 25, { pages, n: last.json.data.length });
    }
    const bad = [await nutri.req('/catalogo/alimentos/busca?q=a&page=101'), await nutri.req('/catalogo/alimentos/busca?q=a&page=0'), await nutri.req('/catalogo/alimentos/busca?q[]=x'), await s('x'.repeat(81))];
    check('paginação', 'Página fora de 1–100, busca em lista e busca com mais de 80 caracteres → 422', bad.every((x) => x.status === 422), bad.map((x) => x.status));
    r = await s('100% _ !');
    check('paginação', 'Caracteres especiais (%, _, !) não viram curinga nem erro; número é termo ("100")', r.status === 200 && r.json.data.every((x) => /100/.test(x.name)), [r.status, names(r).slice(0, 3)]);
    r = await s('_');
    check('paginação', '"_" sozinho não é curinga: lista o catálogo em ordem (sem erro)', r.status === 200 && r.json.data.length === 25, r.status);

    // ------------------------------------------------------------------ Porção e origem visíveis
    r = await s('pão de queijo casa');
    const pao = r.json.data.find((x) => x.name === 'Pão de queijo da casa');
    check('porção', 'Alimento com medidas: porção de referência e medidas caseiras no resultado', pao && /Porção: 1 unidade = 40 g/.test(pao.portion.label) && /1 unidade pequena = 20 g/.test(pao.portion.label) && pao.portion.household_measures.length === 2, pao && pao.portion);
    check('origem', 'Alimento da clínica identificado (is_custom, "Cadastro da clínica nº …")', pao && pao.is_custom === true && /^Cadastro da clínica nº \d+$/.test(pao.reference), pao && pao.reference);
    r = await s('arroz integral cozido');
    const arroz = r.json.data[0];
    check('porção', 'Alimento TACO sem medida: diz que os valores são por 100 g (não inventa porção)', arroz.portion.label === 'Sem medida caseira cadastrada (valores por 100 g)' && arroz.portion.per === '100 g', arroz.portion);
    check('origem', 'Alimento TACO com origem e número oficial ("TACO 4ª ed. nº 1")', arroz.source === 'TACO' && arroz.reference === 'TACO 4ª ed. nº 1' && arroz.taco_code === 1, arroz.reference);

    // ------------------------------------------------------------------ Isolamento e acesso
    r = await s('bolo exclusivo');
    check('isolamento', 'Alimento personalizado de outra clínica não aparece', r.status === 200 && r.json.data.length === 0 && r.json.total === 0, names(r));
    const recep = webUser(); await recep.login('recepcao@example.test');
    r = await recep.req('/catalogo/alimentos/busca?q=arroz');
    check('acesso', 'Recepção não usa o catálogo (403)', r.status === 403, r.status);
    r = await webUser().req('/catalogo/alimentos/busca?q=arroz');
    check('acesso', 'Sem login → 401 (pedido JSON) sem dados', r.status === 401 && !/Arroz/.test(r.text), [r.status, r.location]);

    // ------------------------------------------------------------------ Linhas novas pelo SQL manual
    fx('raw-insert', 'Pitaya, polpa, crua');
    r = await s('pitaya');
    check('índice', 'Alimento inserido direto no banco (como no SQL manual da TACO) aparece na próxima busca', names(r).includes('Pitaya, polpa, crua'), names(r));
    check('índice', 'Nenhum alimento sem texto de busca depois da sincronização', Number(Object.values(sql('SELECT COUNT(*) AS n FROM foods WHERE search_text IS NULL')[0])[0]) === 0);

    // ------------------------------------------------------------------ Base de alimentos (/alimentos)
    let page = await nutri.req('/alimentos?q=' + encodeURIComponent('arroz integral'));
    check('base', '/alimentos com termos responde (antes: 500 no SQLite) e lista os dois arrozes integrais', page.status === 200 && /Arroz, integral, cozido/.test(page.text) && /Arroz, integral, cru/.test(page.text), page.status);
    page = await nutri.req('/alimentos?q=' + encodeURIComponent('leguminosas'));
    check('base', '/alimentos busca também pela categoria ("leguminosas")', page.status === 200 && /Feijão, carioca, cozido/.test(page.text), page.status);

    // ------------------------------------------------------------------ Desempenho com base representativa
    const bulk = JSON.parse(fx('bulk', ...BULK));
    check('desempenho', `Base representativa carregada: ${bulk.foods_total} alimentos (${BULK[0]} clínicas × ${BULK[1]} + ${BULK[2]} da própria clínica + TACO)`, bulk.foods_total >= 590 + BULK[0] * BULK[1] + BULK[2]);
    await s('aquecimento');
    const queries = ['arroz', 'arroz integral', 'feijao carioca cozido', 'frango peito', 'maçã', 'leite', 'pao', 'banana prata', 'queijo minas', 'ovo cozido',
      'a', 'ca', 'car', 'carne bovina', 'peixe', 'tomate', 'azeite', 'batata doce', 'iogurte natural', 'cenoura crua', 'x', 'zz', 'abacate', 'mamao papaia', 'castanha', 'aveia flocos', 'suco laranja', 'cafe', 'acucar', 'farinha mandioca'];
    const times = [], pageTimes = [];
    for (const q of queries) { const x = await s(q); if (x.status !== 200) throw new Error('busca falhou: ' + q + ' ' + x.status); times.push(x.ms); }
    for (const q of queries) { const x = await s(q); times.push(x.ms); }
    for (const q of ['arroz', 'carne bovina', 'a', 'leguminosas', 'pao frances', 'x']) { const x = await nutri.req('/alimentos?q=' + encodeURIComponent(q)); if (x.status !== 200) throw new Error('/alimentos falhou ' + q); pageTimes.push(x.ms); }
    perf.search = { n: times.length, p50_ms: +pct(times, 50).toFixed(1), p95_ms: +pct(times, 95).toFixed(1), max_ms: +Math.max(...times).toFixed(1) };
    perf.page = { n: pageTimes.length, p50_ms: +pct(pageTimes, 50).toFixed(1), p95_ms: +pct(pageTimes, 95).toFixed(1), max_ms: +Math.max(...pageTimes).toFixed(1) };
    perf.foods_total = bulk.foods_total; perf.driver = useMysql ? 'mysql' : 'sqlite'; perf.budget_p95_ms = P95_BUDGET_MS;
    console.log('DESEMPENHO', JSON.stringify(perf));
    check('desempenho', `Busca do seletor (60 buscas, HTTP): p95 ${perf.search.p95_ms} ms ≤ ${P95_BUDGET_MS} ms`, perf.search.p95_ms <= P95_BUDGET_MS, perf.search);
    check('desempenho', `Base de alimentos (/alimentos): p95 ${perf.page.p95_ms} ms ≤ ${P95_BUDGET_MS * 2} ms`, perf.page.p95_ms <= P95_BUDGET_MS * 2, perf.page);
    r = await s('arroz integral');
    check('desempenho', 'Com a base grande, alimentos de outras clínicas continuam fora (total só TACO + própria clínica)', r.json.data.every((x) => !x.is_custom || x.reference.startsWith('Cadastro da clínica')) && r.json.total < 40, r.json.total);
  } catch (e) {
    check('erro', 'Execução sem exceção', false, e.stack);
  } finally {
    server.kill();
    if (useMysql) { try { mysqlAdmin(`DROP DATABASE ${mysqlDb}`); } catch { /* */ } }
    rmSync(dir, { recursive: true, force: true });
  }
  const pass = results.filter((x) => x.ok).length;
  console.log(`\nNI2-73 (${useMysql ? 'MySQL' : 'SQLite'}): ${pass} PASS, ${results.length - pass} FAIL`);
  if (process.env.RESULTS_JSON) writeFileSync(process.env.RESULTS_JSON, JSON.stringify({ driver: useMysql ? 'mysql' : 'sqlite', pass, fail: results.length - pass, perf, results }, null, 1));
  if (pass !== results.length) process.exitCode = 1;
})();
