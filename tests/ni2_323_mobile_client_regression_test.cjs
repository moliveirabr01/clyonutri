const assert = require('node:assert/strict');
const fs = require('node:fs');
const os = require('node:os');
const path = require('node:path');
const http = require('node:http');
const vm = require('node:vm');
const ts = require('../mobile/node_modules/typescript');
const root = path.resolve(__dirname, '..');
const dir = fs.mkdtempSync(path.join(os.tmpdir(), 'ni2-323-client-'));
const compile = source => ts.transpileModule(source, { compilerOptions: { module: ts.ModuleKind.CommonJS, target: ts.ScriptTarget.ES2022 } }).outputText;
let server;
(async () => {
  const apiFile = path.join(dir, 'api.cjs');
  fs.writeFileSync(apiFile, compile(fs.readFileSync(path.join(root, 'mobile/src/services/api.ts'), 'utf8')));
  const api = require(apiFile);
  server = http.createServer((req, res) => {
    res.writeHead(200, { 'Content-Type': 'application/json' });
    res.write('{"data":'); // Headers succeed; body deliberately never finishes.
  });
  await new Promise(resolve => server.listen(0, '127.0.0.1', resolve));
  api.setApiBaseUrl('http://127.0.0.1:' + server.address().port);
  const originalTimeout = global.setTimeout;
  global.setTimeout = (fn, ms, ...args) => originalTimeout(fn, ms === 15000 ? 100 : ms, ...args);
  try {
    await assert.rejects(api.ApiService.getRecipes(), error => error.kind === 'timeout');
  } finally { global.setTimeout = originalTimeout; }

  // Exercise the production write handler with a failed response and a later retry.
  const screen = fs.readFileSync(path.join(root, 'mobile/src/screens/PatientHomeScreen.tsx'), 'utf8');
  const handler = screen.slice(screen.indexOf('  const mealWriteBusy ='), screen.indexOf('  // Tela de Carregamento inicial'));
  const calls = [];
  let succeed = false, now = 100000, complete;
  class Clock extends Date { constructor(...args) { super(...(args.length ? args : [now])); } static now() { return now; } }
  const context = {
    Date: Clock, Math, useRef: current => ({ current }), setRegisteringMeal() {},
    setDiaryEntries() {}, setMealError() {}, setMealFeedback() {}, setMealDraft() {}, displayDateTime: () => "data de teste", Alert: { alert() {} },
    ApiService: { async logMeal(entry) { calls.push(entry); if (!succeed) throw Error('response lost'); return { id: calls.length }; } },
  };
  vm.createContext(context);
  vm.runInContext(compile(handler + '\nglobalThis.register = handleRegisterMeal;'), context);
  await context.register('Almoço', 'Descrição original');
  now += 61000; succeed = true;
  await context.register('Almoço', 'Texto modificado');
  assert.equal(calls[0].idempotency_key, calls[1].idempotency_key);
  assert.equal(calls[0].description, calls[1].description);
  assert.equal(calls[0].consumed_at, calls[1].consumed_at);
  await context.register('Almoço', 'Novo consumo');
  assert.notEqual(calls[1].idempotency_key, calls[2].idempotency_key);
  context.ApiService.logMeal = entry => { calls.push(entry); return new Promise(resolve => { complete = resolve; }); };
  const first = context.register('Jantar');
  const before = calls.length;
  await context.register('Jantar');
  assert.equal(calls.length, before);
  complete({ id: 20 }); await first;
  console.log('PASS timeout during body, stable retry across minutes, distinct new consumption, synchronous double-touch guard.');
})().catch(error => { console.error(error); process.exitCode = 1; }).finally(() => {
  server?.closeAllConnections(); server?.close(); fs.rmSync(dir, { recursive: true, force: true });
});
