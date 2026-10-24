// /app (portal web do paciente) — CSRF de ponta a ponta com HTTP de verdade.
// Antes: os formulários de /app/login e /app/primeiro-acesso não tinham _csrf_token e o Router respondia 403 para todo paciente.
// public/index.php servido por `php -S` (APP_ENV=production, FORCE_HTTPS=false), banco real e isolado, dados sintéticos TESTE.
//
// SQLite (padrão):  node tests/app_csrf_real_test.cjs
// MySQL/MariaDB:    DB_CONNECTION=mysql DB_HOST=127.0.0.1 DB_PORT=3306 DB_USERNAME=... DB_PASSWORD=... node tests/app_csrf_real_test.cjs
'use strict';
const { spawn, execFileSync } = require('node:child_process');
const { mkdtempSync, rmSync, readdirSync, unlinkSync } = require('node:fs');
const { join, resolve } = require('node:path');
const net = require('node:net');
const assert = require('node:assert/strict');

const root = resolve(__dirname, '..');
const isMysql = process.env.DB_CONNECTION === 'mysql';
const dir = mkdtempSync('/tmp/app-csrf-');
const dbName = isMysql ? `app_csrf_test_${process.pid}_${Date.now() % 100000}` : join(dir, 'test.sqlite');
const baseEnv = { ...process.env, DB_CONNECTION: isMysql ? 'mysql' : 'sqlite', DB_DATABASE: dbName, APP_ENV: 'production', FORCE_HTTPS: 'false',
  APP_TIMEZONE: 'America/Sao_Paulo', MAIL_DRIVER: 'null', TZ: 'America/Sao_Paulo' };
let passed = 0;
function check(name, ok, detail) { assert.ok(ok, name + (detail !== undefined ? ' → ' + JSON.stringify(detail).slice(0, 400) : '')); passed++; console.log('PASS ' + name); }
const fixture = (mode, ...args) => JSON.parse(execFileSync('php', ['tests/support/app_csrf_fixture.php', mode, ...args], { cwd: root, env: baseEnv, encoding: 'utf8' }));
const pdoDsn = `mysql:host=${process.env.DB_HOST || '127.0.0.1'};port=${process.env.DB_PORT || '3306'}`;
function mysqlAdmin(sql) {
  execFileSync('php', ['-r', `$p=new PDO(getenv('X_DSN'),getenv('DB_USERNAME'),getenv('DB_PASSWORD'));$p->exec(getenv('X_SQL'));`], { env: { ...baseEnv, X_DSN: pdoDsn, X_SQL: sql } });
}
async function startServer() {
  const sock = net.createServer(); await new Promise((r) => sock.listen(0, '127.0.0.1', r)); const port = sock.address().port; await new Promise((r) => sock.close(r));
  const proc = spawn('php', ['-d', 'session.save_path=' + dir, '-S', '127.0.0.1:' + port, '-t', 'public'], { cwd: root, env: { ...baseEnv, PHP_CLI_SERVER_WORKERS: '4' }, stdio: 'ignore' });
  const base = 'http://127.0.0.1:' + port;
  for (let i = 0; i < 100; i++) { try { await fetch(base + '/up'); break; } catch { await new Promise((r) => setTimeout(r, 50)); } }
  return { base, proc };
}
// Cliente com cookie próprio (cada um é um "navegador"). `accept` simula fetch/curl ('*/*') ou navegador ('text/html').
function client(base, accept = '*/*') {
  let cookie = '';
  async function req(path, options = {}) {
    const r = await fetch(base + path, { ...options, redirect: 'manual', headers: { Accept: accept, ...(cookie ? { Cookie: cookie } : {}), ...options.headers } });
    const c = r.headers.get('set-cookie'); if (c) cookie = c.split(';')[0];
    const text = await r.text(); let json = null; try { json = JSON.parse(text); } catch { /* html */ }
    return { status: r.status, text, json, headers: r.headers };
  }
  const form = (path, data) => req(path, { method: 'POST', headers: { 'Content-Type': 'application/x-www-form-urlencoded' }, body: new URLSearchParams(data) });
  return { req, form, cookie: () => cookie, setCookie: (c) => { cookie = c; } };
}
const tokens = (html) => [...html.matchAll(/name="_csrf_token" value="([^"]+)"/g)].map((m) => m[1]);
// Caminho público do portal do paciente: /app nesta linha; no v20 é /paciente/app (com /app como alias).
const PORTAL = '/app';
const LOGADO = 'Sessão ativa';
const CRED_RUINS = 'Credenciais incorretas';

(async () => {
  if (isMysql) mysqlAdmin(`CREATE DATABASE ${dbName} CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci`);
  const { invitation_code: code } = fixture('prepare');
  const srv = await startServer();
  try {
    const { base } = srv;
    console.log(`banco: ${isMysql ? 'MySQL ' + dbName : 'SQLite'}`);
    const tokenCount = () => fixture('count_tokens').tokens;

    // ---------------------------------------------------------------- primeira visita, sem sessão
    const visitor = client(base);
    let r = await visitor.req(PORTAL);
    check('GET /app sem sessão: 200 e cookie de sessão emitido', r.status === 200 && /PHPSESSID=/.test(visitor.cookie()), visitor.cookie());
    const t0 = tokens(r.text);
    check('GET /app: o formulário de login traz _csrf_token (64 hex)', t0.length === 1 && /^[a-f0-9]{64}$/.test(t0[0]), t0);
    check('GET /app não fica em cache (o token é da sessão)', /no-store/.test(r.headers.get('cache-control') || ''));
    check('Rótulo do login não promete CPF (a tabela patients não tem essa coluna)', r.text.includes('E-mail ou celular') && !r.text.includes('Celular ou CPF'));
    const t0b = tokens((await visitor.req(PORTAL)).text);
    check('Recarregar /app na mesma sessão mantém o mesmo token', t0b[0] === t0[0]);

    // ---------------------------------------------------------------- login válido
    const before = tokenCount();
    r = await visitor.form(PORTAL + '/login', { _csrf_token: t0[0], login: 'ana.teste@example.test', password: 'PacienteTeste@2026' });
    check('Login válido por e-mail com token: 200 e tela de sessão ativa', r.status === 200 && r.text.includes(LOGADO), r.status);
    check('Login válido cria exatamente 1 token de acesso do paciente', tokenCount() === before + 1);
    check('Resposta do login não traz o hash nem erro SQL', !/password_hash|SQLSTATE|no such column|Unknown column/i.test(r.text));
    r = await client(base).req(PORTAL); // outra sessão limpa
    const phoneClient = client(base); const tp = tokens((await phoneClient.req(PORTAL)).text)[0];
    r = await phoneClient.form(PORTAL + '/login', { _csrf_token: tp, login: '11988887777', password: 'PacienteTeste@2026' });
    check('Login válido por celular com token: 200 e sessão ativa', r.status === 200 && r.text.includes(LOGADO));
    const upper = client(base); const tu = tokens((await upper.req(PORTAL)).text)[0];
    r = await upper.form(PORTAL + '/login', { _csrf_token: tu, login: 'ANA.TESTE@EXAMPLE.TEST', password: 'PacienteTeste@2026' });
    check('E-mail em maiúsculas também entra (comparação sem diferenciar caixa)', r.status === 200 && r.text.includes(LOGADO));

    // ---------------------------------------------------------------- login inválido (token certo): mensagem, nunca 403/500
    const bad = client(base); const tb = tokens((await bad.req(PORTAL)).text)[0];
    const n = tokenCount();
    r = await bad.form(PORTAL + '/login', { _csrf_token: tb, login: 'ana.teste@example.test', password: 'senha-errada-123' });
    check('Senha errada com token: 200 e mensagem de credenciais incorretas', r.status === 200 && r.text.includes(CRED_RUINS) && !r.text.includes(LOGADO), r.status);
    r = await bad.form(PORTAL + '/login', { _csrf_token: tb, login: 'ninguem@example.test', password: 'PacienteTeste@2026' });
    check('Paciente inexistente: mesma mensagem (sem revelar se o e-mail existe)', r.status === 200 && r.text.includes(CRED_RUINS));
    r = await bad.form(PORTAL + '/login', { _csrf_token: tb, login: 'carla.teste@example.test', password: 'PacienteTeste@2026' });
    check('Paciente inativo não entra', r.status === 200 && r.text.includes(CRED_RUINS) && !r.text.includes(LOGADO));
    r = await bad.form(PORTAL + '/login', { _csrf_token: tb, login: 'bruno.teste@example.test', password: 'qualquer-coisa' });
    check('Paciente sem senha definida não entra por login', r.status === 200 && r.text.includes(CRED_RUINS));
    r = await bad.form(PORTAL + '/login', { _csrf_token: tb, login: '123.456.789-09', password: 'PacienteTeste@2026' });
    check('Digitar um CPF não derruba a tela (antes: 500 por patients.cpf inexistente)', r.status === 200 && r.text.includes(CRED_RUINS), r.status);
    r = await bad.form(PORTAL + '/login', { _csrf_token: tb, login: '', password: '' });
    check('Campos vazios: 200 e pedido de preenchimento em pt-BR', r.status === 200 && r.text.includes('Informe seu e-mail ou celular e sua senha.'));
    check('Nenhuma tentativa inválida criou token de acesso', tokenCount() === n);
    check('Depois do erro a página já traz um token para tentar de novo', tokens(r.text).length === 1 || (await bad.req(PORTAL)).text.includes('_csrf_token'));

    // ---------------------------------------------------------------- sem token / token errado continua rejeitado
    const atk = client(base); await atk.req(PORTAL);
    const n2 = tokenCount();
    r = await atk.form(PORTAL + '/login', { login: 'ana.teste@example.test', password: 'PacienteTeste@2026' });
    check('POST /app/login sem token: 403 (continua protegido)', r.status === 403 && r.json && /CSRF/.test(r.json.error), r.status);
    r = await atk.form(PORTAL + '/login', { _csrf_token: '', login: 'ana.teste@example.test', password: 'PacienteTeste@2026' });
    check('POST /app/login com token vazio: 403', r.status === 403);
    r = await atk.form(PORTAL + '/login', { _csrf_token: 'a'.repeat(64), login: 'ana.teste@example.test', password: 'PacienteTeste@2026' });
    check('POST /app/login com token inventado: 403', r.status === 403);
    r = await atk.form(PORTAL + '/login', { _csrf_token: tb, login: 'ana.teste@example.test', password: 'PacienteTeste@2026' });
    check('Token de OUTRA sessão não vale: 403', r.status === 403);
    const nocookie = client(base);
    r = await nocookie.form(PORTAL + '/login', { _csrf_token: tb, login: 'ana.teste@example.test', password: 'PacienteTeste@2026' });
    check('POST sem cookie de sessão (token roubado de outra aba): 403', r.status === 403);
    r = await atk.form(PORTAL + '/primeiro-acesso', { invitation_code: code, name: 'Bruno TESTE', email: 'bruno.teste@example.test', phone: '11977776666', password: 'NovaSenha@2026', password_confirmation: 'NovaSenha@2026', terms_accepted: '1' });
    check('POST /app/primeiro-acesso sem token: 403', r.status === 403);
    r = await atk.req(PORTAL + '/login', { method: 'POST', headers: { 'Content-Type': 'application/x-www-form-urlencoded', 'X-CSRF-Token': tokens((await atk.req(PORTAL)).text)[0] }, body: new URLSearchParams({ login: 'ana.teste@example.test', password: 'senha-errada-123' }) });
    check('O token no cabeçalho X-CSRF-Token (cliente fetch) também é aceito', r.status === 200 && r.text.includes(CRED_RUINS));
    check('As tentativas rejeitadas não criaram sessão de paciente', tokenCount() === n2);

    // ---------------------------------------------------------------- token vencido: mensagem amigável no navegador
    const br = client(base, 'text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8');
    const tOld = tokens((await br.req(PORTAL)).text)[0];
    for (const f of readdirSync(dir)) if (f.startsWith('sess_')) unlinkSync(join(dir, f)); // a sessão expirou no servidor
    r = await br.form(PORTAL + '/login', { _csrf_token: tOld, login: 'ana.teste@example.test', password: 'PacienteTeste@2026' });
    check('Sessão expirada no navegador: continua 403 (nada é processado)', r.status === 403);
    check('...mas com página em pt-BR, não JSON cru', /text\/html/.test(r.headers.get('content-type') || '') && r.text.includes('Sua sessão expirou') && r.json === null);
    check('...com link para voltar ao app do paciente', r.text.includes(`href="${PORTAL}"`) && r.text.includes('Voltar ao app do paciente'));
    check('...sem jargão técnico nem dados do formulário', !/CSRF|token/i.test(r.text) && !r.text.includes('PacienteTeste@2026'));
    check('...e sem criar sessão de paciente', tokenCount() === n2);
    r = await br.req(PORTAL); const tNew = tokens(r.text)[0];
    r = await br.form(PORTAL + '/login', { _csrf_token: tNew, login: 'ana.teste@example.test', password: 'PacienteTeste@2026' });
    check('Voltando ao app e entrando de novo funciona', r.status === 200 && r.text.includes(LOGADO));
    r = await atk.form(PORTAL + '/login', { login: 'x', password: 'y' });
    check('Cliente fetch (Accept */*) sem token continua recebendo JSON 403', r.status === 403 && r.json && typeof r.json.error === 'string');
    const ajax = client(base, 'text/html'); await ajax.req(PORTAL);
    r = await ajax.req(PORTAL + '/login', { method: 'POST', headers: { 'Content-Type': 'application/json', 'X-Requested-With': 'XMLHttpRequest' }, body: JSON.stringify({ login: 'x', password: 'y' }) });
    check('Requisição XHR/JSON sem token continua com JSON 403', r.status === 403 && r.json && typeof r.json.error === 'string');
    r = await client(base, 'text/html').form('/login', { email: 'nutri.teste@example.test', password: 'Equipe@2026' });
    check('Telas do profissional também ganham a mensagem amigável (link para o painel)', r.status === 403 && r.text.includes('Sua sessão expirou') && r.text.includes('href="/dashboard"'));

    // ---------------------------------------------------------------- primeiro acesso
    const first = client(base);
    r = await first.req(PORTAL + '?code=' + code);
    const tf = tokens(r.text);
    check('GET /app?code= mostra o primeiro acesso com o token no formulário', r.status === 200 && r.text.includes('Bruno TESTE') && tf.length === 1 && /^[a-f0-9]{64}$/.test(tf[0]), tf.length);
    r = await first.form(PORTAL + '/primeiro-acesso', { _csrf_token: tf[0], invitation_code: code, name: 'Bruno TESTE', email: 'bruno.teste@example.test', phone: '11977776666', password: 'NovaSenha@2026', password_confirmation: 'NovaSenha@2026' });
    check('Primeiro acesso sem aceitar os termos: 200 com aviso em pt-BR e convite preservado', r.status === 200 && r.text.includes('concordar com os Termos') && r.text.includes('Bruno TESTE'));
    r = await first.form(PORTAL + '/primeiro-acesso', { _csrf_token: tf[0], invitation_code: code, name: 'Bruno TESTE', email: 'bruno.teste@example.test', phone: '11977776666', password: 'curta', password_confirmation: 'curta', terms_accepted: '1' });
    check('Senha curta: 200 com mensagem, sem erro 403', r.status === 200 && r.text.includes('8 a 72'));
    const n3 = tokenCount();
    r = await first.form(PORTAL + '/primeiro-acesso', { _csrf_token: tf[0], invitation_code: code, name: 'Bruno TESTE', email: 'bruno.teste@example.test', phone: '11977776666', password: 'NovaSenha@2026', password_confirmation: 'NovaSenha@2026', terms_accepted: '1' });
    check('Primeiro acesso completo com token: 200 e tela de acesso criado', r.status === 200 && r.text.includes('O que você pode fazer agora'), r.status);
    check('Primeiro acesso cria o token de acesso do paciente', tokenCount() === n3 + 1);
    r = await first.form(PORTAL + '/primeiro-acesso', { _csrf_token: tf[0], invitation_code: code, name: 'Bruno TESTE', email: 'bruno.teste@example.test', phone: '11977776666', password: 'OutraSenha@2026', password_confirmation: 'OutraSenha@2026', terms_accepted: '1' });
    check('O mesmo convite não vale duas vezes (mensagem, sem 403/500)', r.status === 200 && r.text.includes('Convite inválido ou expirado'));
    const again = client(base); const ta = tokens((await again.req(PORTAL)).text)[0];
    r = await again.form(PORTAL + '/login', { _csrf_token: ta, login: 'bruno.teste@example.test', password: 'NovaSenha@2026' });
    check('Depois do primeiro acesso, o paciente entra pelo login com a senha nova', r.status === 200 && r.text.includes(LOGADO));
    r = await again.form(PORTAL + '/login', { _csrf_token: ta, login: 'bruno.teste@example.test', password: 'OutraSenha@2026' });
    check('A senha do segundo envio não foi gravada', r.status === 200 && r.text.includes(CRED_RUINS));

    console.log(`\napp CSRF (${isMysql ? 'MySQL' : 'SQLite'}): ${passed} PASS, 0 FAIL`);
  } finally {
    srv.proc.kill();
    if (isMysql) { try { mysqlAdmin(`DROP DATABASE IF EXISTS ${dbName}`); } catch { /* ignora */ } }
    rmSync(dir, { recursive: true, force: true });
  }
})().catch((e) => { console.error('FAIL', e.message); process.exit(1); });
