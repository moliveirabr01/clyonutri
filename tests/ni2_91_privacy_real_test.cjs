// NI2-91 — privacidade da paciente (consentimentos e pedidos LGPD) e fila de pedidos da clínica: teste real por HTTP.
// public/index.php em `php -S` (APP_ENV=production), SQLite isolado, dados fictícios (tests/support/ni2_91_fixture.php),
// logins reais (paciente pela API; equipe pelo formulário web com CSRF).
//   node tests/ni2_91_privacy_real_test.cjs   [RESULTS_JSON=…]
'use strict';
const { spawn, execFileSync } = require('node:child_process');
const { mkdtempSync, rmSync, writeFileSync } = require('node:fs');
const { join, resolve } = require('node:path');
const net = require('node:net');

const root = resolve(process.env.APP_ROOT || join(__dirname, '..'));
const dir = mkdtempSync('/tmp/ni2-91-');
// MySQL opcional: DB_CONNECTION=mysql DB_HOST DB_PORT DB_USERNAME DB_PASSWORD (banco ni2_91_test_* criado e apagado aqui).
const useMysql = process.env.DB_CONNECTION === 'mysql';
const mysqlDb = 'ni2_91_test_' + Math.random().toString(36).slice(2, 10);
const env = { ...process.env, DB_CONNECTION: useMysql ? 'mysql' : 'sqlite', DB_DATABASE: useMysql ? mysqlDb : join(dir, 'test.sqlite'), NI2_FIXTURE_DIR: dir,
  APP_ENV: 'production', FORCE_HTTPS: 'false', APP_TIMEZONE: 'America/Sao_Paulo', MAIL_DRIVER: 'null', TZ: 'America/Sao_Paulo' };
const mysqlAdmin = (sqlText) => execFileSync('php', ['-r', '$p=new PDO("mysql:host=".getenv("DB_HOST").";port=".getenv("DB_PORT"),getenv("DB_USERNAME"),getenv("DB_PASSWORD"));$p->exec($argv[1]);', sqlText], { env });
const results = [];
function check(crit, name, ok, detail) {
  results.push({ crit, name, ok: !!ok, detail: ok ? null : (detail === undefined ? null : detail) });
  console.log(`${ok ? 'PASS' : 'FAIL'} [${crit}] ${name}${!ok && detail !== undefined ? ' → ' + JSON.stringify(detail).slice(0, 400) : ''}`);
}
const php = (args) => execFileSync('php', [join(root, 'tests/support/ni2_91_fixture.php'), ...args], { cwd: root, env, encoding: 'utf8' });
const sql = (q) => JSON.parse(php(['sql', q]));
const one = (q) => { const r = sql(q)[0]; return r ? Object.values(r)[0] : null; };

(async () => {
  if (useMysql) mysqlAdmin(`CREATE DATABASE ${mysqlDb} CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci`);
  php(['prepare']);
  const sock = net.createServer(); await new Promise((r) => sock.listen(0, '127.0.0.1', r)); const port = sock.address().port; await new Promise((r) => sock.close(r));
  const server = spawn('php', ['-d', 'display_errors=0', '-d', 'session.save_path=' + dir, '-S', '127.0.0.1:' + port, '-t', 'public'], { cwd: root, env, stdio: 'ignore' });
  const B = 'http://127.0.0.1:' + port;
  for (let i = 0; i < 100; i++) { try { await fetch(B + '/up'); break; } catch { await new Promise((r) => setTimeout(r, 50)); } }
  const api = async (method, path, { token, body } = {}) => {
    const h = { Accept: 'application/json' }; if (token) h.Authorization = 'Bearer ' + token; if (body !== undefined) h['Content-Type'] = 'application/json';
    const res = await fetch(B + path, { method, headers: h, body: body !== undefined ? JSON.stringify(body) : undefined });
    const text = await res.text(); let json = null; try { json = JSON.parse(text); } catch { /* */ }
    return { status: res.status, text, json };
  };
  // Navegador mínimo: um cookie de sessão por usuário.
  const webUser = () => {
    let cookie = '';
    const req = async (method, path, form) => {
      const res = await fetch(B + path, { method, redirect: 'manual', headers: { Cookie: cookie, ...(form ? { 'Content-Type': 'application/x-www-form-urlencoded' } : {}) }, body: form ? new URLSearchParams(form).toString() : undefined });
      const set = res.headers.getSetCookie ? res.headers.getSetCookie() : [];
      for (const c of set) { const kv = c.split(';')[0]; if (kv.startsWith('PHPSESSID=')) cookie = kv; }
      return { status: res.status, location: res.headers.get('location') || '', text: await res.text(), headers: res.headers };
    };
    const csrf = async (path) => { const r = await req('GET', path); const m = r.text.match(/name="_csrf_token" value="([^"]+)"/); return m ? m[1] : ''; };
    const login = async (email) => { const t = await csrf('/login'); return req('POST', '/login', { _csrf_token: t, email, password: 'Equipe@2026' }); };
    return { req, csrf, login };
  };
  try {
    const tok = async (email) => (await api('POST', '/api/v1/auth/patient-login', { body: { email, password: 'PacienteTeste@2026' } })).json.token;
    const ana = await tok('ana@example.test'), bruno = await tok('bruno@example.test'), carla = await tok('carla@example.test');

    // ---------------------------------------------------------------- lado da paciente (C1)
    let r = await api('GET', '/api/v1/patient/privacy');
    check('paciente', 'Sem token: /patient/privacy → 401', r.status === 401);
    r = await api('GET', '/api/v1/patient/privacy', { token: ana });
    check('paciente', 'Ana vê o próprio consentimento (termo, versão, data)', r.status === 200 && r.json.consents.length === 1 && r.json.consents[0].granted === true && !!r.json.consents[0].term_version && !!r.json.consents[0].granted_at, r.text.slice(0, 300));
    check('paciente', 'Resposta não traz IP, user agent nem dados de outros pacientes', !/ip_address|user_agent|Bruno|Carla/.test(r.text), r.text.slice(0, 300));
    r = await api('POST', '/api/v1/patient/privacy-requests', { token: ana, body: { request_type: 'rectification' } });
    check('paciente', 'Tipo de pedido fora de export/deletion → 422', r.status === 422 && r.json && r.json.code === 'VALIDATION_ERROR', r.status);
    r = await api('POST', '/api/v1/patient/privacy-requests', { token: ana, body: { request_type: 'export', details: 'x'.repeat(1001) } });
    check('paciente', 'Comentário acima de 1000 caracteres → 422', r.status === 422, r.status);
    r = await api('POST', '/api/v1/patient/privacy-requests', { token: ana, body: { request_type: 'export' } });
    const anaReq = r.json && r.json.request;
    const days = anaReq ? (new Date(anaReq.deadline_at.replace(' ', 'T')) - new Date(anaReq.created_at.replace(' ', 'T'))) / 86400000 : 0;
    check('paciente', 'Ana pede cópia dos dados → 201 com protocolo e prazo de 15 dias', r.status === 201 && anaReq.status === 'pending' && Math.round(days) === 15, r.text.slice(0, 200));
    r = await api('POST', '/api/v1/patient/privacy-requests', { token: ana, body: { request_type: 'export' } });
    check('paciente', 'Pedido repetido não duplica (200, already_open, mesmo id)', r.status === 200 && r.json.request.already_open === true && r.json.request.id === anaReq.id && Number(one("SELECT COUNT(*) FROM data_subject_requests WHERE patient_id = 1")) === 1, r.text.slice(0, 200));
    const aud = sql(`SELECT details FROM audit_logs WHERE action = 'DATA_SUBJECT_REQUEST_OPENED' AND resource_id = '${anaReq.id}'`)[0];
    check('paciente', 'Abertura auditada com canal "patient_app"', aud && JSON.parse(aud.details).channel === 'patient_app', aud);
    r = await api('POST', '/api/v1/patient/privacy-requests', { token: bruno, body: { request_type: 'deletion' } });
    const brunoReq = r.json && r.json.request;
    check('paciente', 'Bruno pede exclusão → 201', r.status === 201 && brunoReq.request_type === 'deletion', r.status);
    r = await api('POST', '/api/v1/patient/privacy-requests', { token: carla, body: { request_type: 'export' } });
    const carlaReq = r.json && r.json.request;
    r = await api('GET', '/api/v1/patient/privacy', { token: ana });
    check('paciente', 'Ana só vê o próprio pedido', r.json.requests.length === 1 && r.json.requests[0].id === anaReq.id, r.json.requests);

    // ---------------------------------------------------------------- fila da clínica (A2, A5)
    const recep = webUser(); await recep.login('recepcao@example.test');
    r = await recep.req('GET', '/privacidade/pedidos');
    check('fila', 'Recepção não acessa a fila de pedidos (403)', r.status === 403, r.status);
    const anon = webUser();
    r = await anon.req('GET', '/privacidade/pedidos');
    check('fila', 'Sem login: redireciona para o login', r.status === 302 && r.location.includes('/login'), { status: r.status, loc: r.location });
    const equipe = webUser(); r = await equipe.login('equipe@example.test');
    r = await equipe.req('GET', '/privacidade/pedidos');
    check('fila', 'Nutricionista vê a fila com os pedidos da própria clínica', r.status === 200 && r.text.includes('Ana Fictícia') && r.text.includes('Bruno Fictício') && !r.text.includes('Carla'), r.status);
    check('fila', 'Nutricionista não vê o formulário de anonimizar', !r.text.includes('/anonimizar') && r.text.includes('Só a gestão do consultório'), null);
    let t = await equipe.csrf('/privacidade/pedidos');
    r = await equipe.req('POST', `/privacidade/pedidos/${brunoReq.id}/anonimizar`, { _csrf_token: t, confirm_word: 'ANONIMIZAR', confirm_irreversible: 'yes' });
    check('fila', 'Nutricionista tentando anonimizar por POST direto → 403 e nada muda', r.status === 403 && one(`SELECT status FROM patients WHERE id = 2`) === 'active', r.status);
    r = await equipe.req('POST', `/privacidade/pedidos/${anaReq.id}/exportar`, { _csrf_token: 'invalido' });
    check('fila', 'Exportar sem CSRF válido → 403', r.status === 403 && one(`SELECT status FROM data_subject_requests WHERE id = ${anaReq.id}`) === 'pending', r.status);
    t = await equipe.csrf('/privacidade/pedidos');
    r = await equipe.req('POST', `/privacidade/pedidos/${anaReq.id}/exportar`, { _csrf_token: t });
    let pkg = null; try { pkg = JSON.parse(r.text); } catch { /* */ }
    check('fila', 'Exportação gera o pacote (download JSON) e conclui o pedido', r.status === 200 && /attachment/.test(r.headers.get('content-disposition') || '') && pkg && one(`SELECT status FROM data_subject_requests WHERE id = ${anaReq.id}`) === 'completed', { status: r.status, body: r.text.slice(0, 200) });
    check('fila', 'Pedido registra quem concluiu (reviewed_by = nutricionista)', Number(one(`SELECT reviewed_by FROM data_subject_requests WHERE id = ${anaReq.id}`)) === 3);
    const expAud = sql("SELECT user_id, ip_address FROM audit_logs WHERE action = 'DATA_SUBJECT_EXPORT_COMPLETED'")[0];
    check('fila', 'Auditoria da exportação tem autor e IP', expAud && Number(expAud.user_id) === 3 && !!expAud.ip_address, expAud);
    // Portabilidade completa (A4)
    const txt = JSON.stringify(pkg || {});
    const missing = [['mensagem do chat', 'Mensagem fictícia da Ana'], ['autorrelato', '63.2'], ['plano publicado (versão)', 'meal_plan_versions'], ['pedidos LGPD', 'data_subject_requests'], ['receitas prescritas', 'recipe_prescriptions'], ['pré-consulta', 'preconsultation_questionnaires']].filter(([, n]) => !txt.includes(n)).map(([l]) => l);
    check('portabilidade', 'Pacote traz chat, autorrelatos, versão do plano, pedidos, receitas e pré-consulta', missing.length === 0, missing);
    check('portabilidade', 'Pacote não traz segredos (hash de senha, tokens, caminhos internos)', !/password_hash|access_pin|otp_code|token_hash|storage_path|file_path|"\/tmp\//.test(txt));
    check('portabilidade', 'Pacote traz checksum SHA-256', /^[a-f0-9]{64}$/.test((pkg || {}).checksum_sha256 || ''));
    t = await equipe.csrf('/privacidade/pedidos');
    r = await equipe.req('POST', `/privacidade/pedidos/${anaReq.id}/exportar`, { _csrf_token: t });
    check('fila', 'Pedido já concluído não é exportado de novo (volta à fila com aviso)', r.status === 302 && Number(one("SELECT COUNT(*) FROM audit_logs WHERE action = 'DATA_SUBJECT_EXPORT_COMPLETED'")) === 1, r.status);
    // Pedido de outra clínica
    const owner = webUser(); await owner.login('nutri1@example.test');
    t = await owner.csrf('/privacidade/pedidos');
    r = await owner.req('POST', `/privacidade/pedidos/${carlaReq.id}/exportar`, { _csrf_token: t });
    check('fila', 'Pedido de outra clínica: não é exportado e continua pendente', r.status === 302 && one(`SELECT status FROM data_subject_requests WHERE id = ${carlaReq.id}`) === 'pending', r.status);
    // Prazo vencido
    php(['age-request', String(brunoReq.id)]);
    r = await owner.req('GET', '/privacidade/pedidos');
    check('fila', 'Pedido com prazo vencido aparece destacado', r.text.includes('Prazo vencido') && /com prazo vencido/.test(r.text), null);
    // Anonimizar exige confirmação
    t = await owner.csrf('/privacidade/pedidos');
    r = await owner.req('POST', `/privacidade/pedidos/${brunoReq.id}/anonimizar`, { _csrf_token: t, confirm_word: 'sim', confirm_irreversible: 'yes' });
    check('fila', 'Anonimizar sem digitar a palavra de confirmação: nada muda', r.status === 302 && one('SELECT status FROM patients WHERE id = 2') === 'active', r.status);
    t = await owner.csrf('/privacidade/pedidos');
    r = await owner.req('POST', `/privacidade/pedidos/${brunoReq.id}/anonimizar`, { _csrf_token: t, confirm_word: 'ANONIMIZAR', confirm_irreversible: 'yes' });
    const req2 = sql(`SELECT status, reviewed_by, legal_justification FROM data_subject_requests WHERE id = ${brunoReq.id}`)[0];
    check('exclusão', 'Dona anonimiza: pedido "concluído com guarda legal" (não mais "rejeitado")', r.status === 302 && req2.status === 'completed_legal_hold' && Number(req2.reviewed_by) === 1, req2);
    const pb = sql('SELECT name, email, phone, password_hash, status FROM patients WHERE id = 2')[0];
    check('exclusão', 'Cadastro anonimizado e credencial apagada', pb.name === 'PACIENTE ANONIMIZADO' && pb.email === null && pb.phone === null && pb.password_hash === null && pb.status === 'anonymized', pb);
    check('exclusão', 'Prontuário preservado sob guarda legal', Number(one('SELECT COUNT(*) FROM clinical_records WHERE patient_id = 2')) === 1);
    r = await api('GET', '/api/v1/patient/home', { token: bruno });
    check('exclusão', 'Token do app do paciente anonimizado deixa de valer (401)', r.status === 401, r.status);
    const delAud = sql("SELECT user_id, details FROM audit_logs WHERE action = 'DATA_SUBJECT_DELETION_PROCESSED'")[0];
    check('exclusão', 'Auditoria da exclusão tem autor e lista o que ficou sob guarda', delAud && Number(delAud.user_id) === 1 && JSON.parse(delAud.details).held_tables.includes('clinical_records'), delAud);
    r = await owner.req('GET', '/privacidade/pedidos');
    check('exclusão', 'Fila mostra o pedido como concluído com guarda legal', r.text.includes('Concluído com guarda legal do prontuário'));
    t = await owner.csrf('/privacidade/pedidos');
    r = await owner.req('POST', `/privacidade/pedidos/${brunoReq.id}/anonimizar`, { _csrf_token: t, confirm_word: 'ANONIMIZAR', confirm_irreversible: 'yes' });
    check('exclusão', 'Processar de novo: recusado (já concluído) e só 1 registro de auditoria', Number(one("SELECT COUNT(*) FROM audit_logs WHERE action = 'DATA_SUBJECT_DELETION_PROCESSED'")) === 1, r.status);
    // Perfil do paciente (profissional) continua registrando pedidos e aponta para a fila
    r = await owner.req('GET', '/pacientes/1');
    check('fila', 'Perfil do paciente tem link para a fila de pedidos', r.status === 200 && r.text.includes('/privacidade/pedidos'), r.status);
  } catch (e) {
    results.push({ crit: 'erro', name: e.message.split('\n')[0], ok: false });
    console.log('FAIL (interrompido) ' + e.stack);
  } finally {
    server.kill(); rmSync(dir, { recursive: true, force: true });
    if (useMysql) { try { mysqlAdmin(`DROP DATABASE IF EXISTS ${mysqlDb}`); } catch { /* */ } }
    if (process.env.RESULTS_JSON) writeFileSync(process.env.RESULTS_JSON, JSON.stringify(results, null, 1));
  }
  const ok = results.filter((x) => x.ok).length;
  console.log(`\nNI2-91 (${useMysql ? 'MySQL' : 'SQLite'}): ${ok} PASS, ${results.length - ok} FAIL`);
  if (ok !== results.length) process.exitCode = 1;
})();
