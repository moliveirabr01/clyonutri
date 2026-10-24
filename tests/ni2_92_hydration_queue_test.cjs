// NI2-92 (A6/A7) — fila offline de água do app: lógica pura + reenvio real contra o servidor.
//   cd mobile && npm run test:hydration      (compila api.ts e hydrationQueue.ts em mobile/.test-build)
'use strict';
const { spawn, execFileSync } = require('node:child_process');
const { mkdtempSync, rmSync } = require('node:fs');
const { join, resolve } = require('node:path');
const net = require('node:net');
const { HydrationQueue, memoryStorage, isRetryable, newHydrationKey } = require('../mobile/.test-build/hydrationQueue.js');
const { ApiService, ApiError, setApiBaseUrl, setAuthToken } = require('../mobile/.test-build/api.js');

let pass = 0, fail = 0;
const check = (name, ok, detail) => { if (ok) pass++; else fail++; console.log(`${ok ? 'PASS' : 'FAIL'} ${name}${!ok && detail !== undefined ? ' → ' + JSON.stringify(detail).slice(0, 300) : ''}`); };
const err = (status, kind, code) => Object.assign(new Error('x'), { status, kind, code });

(async () => {
  // ---------------- lógica pura
  check('chave casa com o formato do servidor', /^[A-Za-z0-9._:-]{8,64}$/.test(newHydrationKey(123456789)));
  check('chaves de toques diferentes são diferentes', newHydrationKey(1) !== newHydrationKey(1));
  check('retentáveis: offline, timeout, 401, 429, 5xx', [err(0, 'offline'), err(408, 'timeout'), err(401, 'auth'), err(429, 'server'), err(503, 'server')].every(isRetryable));
  check('definitivos: 409, 422, 404', ![err(409, 'server'), err(422, 'server'), err(404, 'server')].some(isRetryable));

  const sentKeys = []; let mode = 'offline';
  const q = new HydrationQueue(memoryStorage(), async (item) => {
    sentKeys.push(item.key);
    if (mode === 'offline') throw err(0, 'offline');
    if (mode === 'limit' && item.amountMl === 500) throw err(422, 'server', 'HYDRATION_DAILY_LIMIT');
    return { id: sentKeys.length, total_water_today: 1 };
  }, 7);
  const a = q.enqueue(250); q.enqueue(500);
  let out = await q.flush();
  check('offline: nada sai da fila, para no primeiro erro', out.remaining.length === 2 && out.sent.length === 0 && sentKeys.length === 1);
  check('offline: tentativa contada no item', q.items()[0].attempts === 1);
  out = await q.flush();
  check('reenvio usa a MESMA chave', sentKeys[0] === sentKeys[1] && sentKeys[1] === a.key);
  mode = 'limit';
  out = await q.flush();
  check('volta a rede: 250 enviado; 500 recusado em definitivo (422) sai da fila com erro', out.sent.length === 1 && out.rejected.length === 1 && out.remaining.length === 0 && out.rejected[0].error.code === 'HYDRATION_DAILY_LIMIT');
  mode = 'ok';
  const slowSends = [];
  const q2 = new HydrationQueue(memoryStorage(), (item) => new Promise((r) => { slowSends.push(item.key); setTimeout(() => r({ id: 1, total_water_today: 250 }), 30); }), 7);
  q2.enqueue(250);
  const [f1, f2] = await Promise.all([q2.flush(), q2.flush()]);
  check('flush concorrente (timer + online + toque) envia uma vez só', slowSends.length === 1 && f1.sent.length === 1, slowSends);
  let stored = null;
  const persistent = { read: () => stored, write: (v) => { stored = v; }, persistent: true };
  const q3 = new HydrationQueue(persistent, async () => { throw err(0, 'offline'); }, 9);
  const k3 = q3.enqueue(300).key; await q3.flush();
  const q3b = new HydrationQueue(persistent, async (item) => ({ id: 5, key: item.key, total_water_today: 300 }), 9);
  check('fila persistida sobrevive a recarregar (mesma chave)', q3b.items().length === 1 && q3b.items()[0].key === k3);
  out = await q3b.flush();
  check('após recarregar, envia e limpa o armazenamento', out.sent.length === 1 && stored === null);
  stored = '{corrompido'; check('armazenamento corrompido não quebra a tela', new HydrationQueue(persistent, async () => ({}), 9).items().length === 0);

  // ---------------- reenvio real contra o servidor (servidor recebe, resposta "se perde", app reenvia)
  const root = resolve(join(__dirname, '..'));
  const dir = mkdtempSync('/tmp/ni2-92-');
  const env = { ...process.env, DB_CONNECTION: 'sqlite', DB_DATABASE: join(dir, 'test.sqlite'), APP_ENV: 'production', FORCE_HTTPS: 'false', APP_TIMEZONE: 'America/Sao_Paulo', MAIL_DRIVER: 'null', TZ: 'America/Sao_Paulo' };
  const php = (args) => execFileSync('php', [join(root, 'tests/support/ni2_92_fixture.php'), ...args], { cwd: root, env, encoding: 'utf8' });
  const count = () => Number(Object.values(JSON.parse(php(['sql', 'SELECT COUNT(*) AS n FROM hydration_logs WHERE patient_id = 1']))[0])[0]);
  php(['prepare']);
  const sock = net.createServer(); await new Promise((r) => sock.listen(0, '127.0.0.1', r)); const port = sock.address().port; await new Promise((r) => sock.close(r));
  const server = spawn('php', ['-d', 'display_errors=0', '-S', '127.0.0.1:' + port, '-t', 'public'], { cwd: root, env, stdio: 'ignore' });
  const B = 'http://127.0.0.1:' + port;
  for (let i = 0; i < 100; i++) { try { await fetch(B + '/up'); break; } catch { await new Promise((r) => setTimeout(r, 50)); } }
  try {
    setApiBaseUrl('http://127.0.0.1:9'); // porta fechada = sem rede
    const login = await fetch(B + '/api/v1/auth/patient-login', { method: 'POST', headers: { 'Content-Type': 'application/json', Accept: 'application/json' }, body: JSON.stringify({ email: 'ana92@example.test', password: 'PacienteTeste@2026' }) }).then((r) => r.json());
    setAuthToken(login.token);
    let lose = false;
    const sender = async (item) => {
      const r = await ApiService.logHydration(item.amountMl, { idempotencyKey: item.key, loggedAt: item.attempts > 0 ? item.loggedAt : undefined });
      if (lose) { lose = false; throw new ApiError('tempo esgotado', 408, 'timeout'); } // servidor gravou, resposta perdida
      return r;
    };
    const real = new HydrationQueue(memoryStorage(), sender, 1);
    real.enqueue(250);
    out = await real.flush();
    check('sem rede: ApiError offline, item segue na fila', out.remaining.length === 1 && out.lastRetryableError?.kind === 'offline' && count() === 0, out.lastRetryableError);
    setApiBaseUrl(B); lose = true;
    out = await real.flush();
    check('rede volta, servidor grava, resposta se perde: item continua pendente', out.remaining.length === 1 && count() === 1);
    out = await real.flush();
    check('reenvio com a mesma chave: replay, total 250, ainda 1 linha no banco', out.sent.length === 1 && out.sent[0].result.idempotent_replay === true && out.sent[0].result.total_water_today === 250 && count() === 1, out.sent[0]?.result);
    real.enqueue(500); real.enqueue(500);
    out = await real.flush();
    check('dois toques distintos = dois registros', out.sent.length === 2 && count() === 3 && out.sent[1].result.total_water_today === 1250);
    const h = await ApiService.getHydrationHistory(null, 7);
    check('cliente: histórico do dia com 3 itens e 1250 ml', h.days[0]?.entries.length === 3 && h.days[0].total_ml === 1250, h.days[0]);
    const upd = await ApiService.updateHydration(h.days[0].entries[0].id, { amount_ml: 400 });
    check('cliente: corrigir 500 → 400', upd.amount_ml === 400 && upd.total_water_today === 1150);
    const del = await ApiService.deleteHydration(h.days[0].entries[0].id);
    check('cliente: excluir', del.deleted === true && del.total_water_today === 750);
    try { await ApiService.logHydration(0, {}); check('cliente: 0 ml recusado', false); } catch (e) { check('cliente: 0 ml recusado com 422 e mensagem', e instanceof ApiError && e.status === 422 && !isRetryable(e) && /1 e 5000/.test(e.message), e.message); }
  } finally {
    server.kill(); rmSync(dir, { recursive: true, force: true });
  }
  console.log(`\nNI2-92 fila: ${pass} PASS, ${fail} FAIL`);
  process.exit(fail ? 1 : 0);
})().catch((e) => { console.error(e); process.exit(1); });
