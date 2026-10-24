// NI2-24 — isolamento entre clínicas e IDOR: teste real por HTTP sobre TODAS as rotas com id.
// public/index.php em `php -S` (APP_ENV=production), banco isolado (SQLite ou MySQL), dados fictícios
// (tests/support/ni2_24_fixture.php). A dona da clínica 2 (web e API do profissional) e a paciente Carla (API da
// paciente) usam ids da clínica 1. Para cada pedido confere: nenhum dado da clínica 1 na resposta, nenhuma
// alteração nas tabelas da clínica 1, nenhum 5xx, mesma resposta que um id inexistente (sem oráculo), mensagem
// sem detalhe interno e registro na auditoria. Falha também se uma rota nova com id não estiver coberta.
//   node tests/ni2_24_cross_tenant_routes_real_test.cjs   [RESULTS_JSON=…]
//   DB_CONNECTION=mysql DB_HOST=… DB_PORT=… DB_USERNAME=… DB_PASSWORD=… node tests/ni2_24_cross_tenant_routes_real_test.cjs
'use strict';
const { spawn, execFileSync } = require('node:child_process');
const { mkdtempSync, rmSync, writeFileSync, readFileSync } = require('node:fs');
const { join, resolve } = require('node:path');
const net = require('node:net');

const root = resolve(process.env.APP_ROOT || join(__dirname, '..'));
const dir = mkdtempSync('/tmp/ni2-24-');
const useMysql = process.env.DB_CONNECTION === 'mysql';
const mysqlDb = 'ni2_24_test_' + Math.random().toString(36).slice(2, 10);
const env = { ...process.env, DB_CONNECTION: useMysql ? 'mysql' : 'sqlite', DB_DATABASE: useMysql ? mysqlDb : join(dir, 'test.sqlite'),
  APP_ENV: 'production', FORCE_HTTPS: 'false', APP_TIMEZONE: 'America/Sao_Paulo', TZ: 'America/Sao_Paulo', MAIL_DRIVER: 'null' };
const mysqlAdmin = (sqlText) => execFileSync('php', ['-r', '$p=new PDO("mysql:host=".getenv("DB_HOST").";port=".getenv("DB_PORT"),getenv("DB_USERNAME"),getenv("DB_PASSWORD"));$p->exec($argv[1]);', sqlText], { env });
const results = [];
function check(crit, name, ok, detail) {
  results.push({ crit, name, ok: !!ok, detail: ok ? null : (detail === undefined ? null : detail) });
  console.log(`${ok ? 'PASS' : 'FAIL'} [${crit}] ${name}${!ok && detail !== undefined ? ' → ' + JSON.stringify(detail).slice(0, 700) : ''}`);
}
const fx = (...a) => execFileSync('php', [join(root, 'tests/support/ni2_24_fixture.php'), ...a.map(String)], { cwd: root, env, encoding: 'utf8', stdio: ['ignore', 'pipe', 'pipe'] });
const sql = (q) => JSON.parse(fx('sql', q));
const MARK = /SEGREDO-C1|Ana Fict|Bruno Fict|ana@example\.test|bruno@example\.test|Plano fictício da Ana/;
const INTERNAL = /403 Forbidden|n[aã]o pertence|ID \d+ /;

// Rotas com parâmetro que NÃO usam id sequencial de recurso de clínica (segredo/token, público por desenho ou console da plataforma).
const EXCLUDED = {
  '/agendar/{professionalId}': 'agendamento público por profissional (NI2-433)',
  '/ajuda/artigo/{slug}': 'artigo público da central de ajuda', '/ajuda/categoria/{slug}': 'categoria pública da central de ajuda',
  '/api/v1/chat/anexo/{token}': 'link assinado HMAC', '/pre-consulta/{token}': 'token da pré-consulta', '/recuperar-senha/{token}': 'token de recuperação',
  '/teleconsulta/sala-espera/{token}': 'token da paciente', '/teleconsulta/sala-espera/{token}/status': 'token da paciente', '/teleconsulta/sala-espera/{token}/{acao}': 'token da paciente',
  '/teleconsulta/sala/{token}': 'token da paciente', '/teleconsulta/sala/{token}/midia': 'token da paciente', '/teleconsulta/sinal/{room}': 'credencial da sala (cabeçalho)',
  '/admin/clinicas/{id}/status': 'console da plataforma', '/admin/usuarios/{id}/status': 'console da plataforma', '/admin/usuarios/{id}/revogar-sessoes': 'console da plataforma',
};

(async () => {
  if (useMysql) mysqlAdmin(`CREATE DATABASE ${mysqlDb} CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci`);
  const ids = JSON.parse(fx('prepare')).ids;
  const food = sql('SELECT MIN(id) AS f FROM foods WHERE clinic_id IS NULL')[0].f;
  const sock = net.createServer(); await new Promise((r) => sock.listen(0, '127.0.0.1', r)); const port = sock.address().port; await new Promise((r) => sock.close(r));
  const server = spawn('php', ['-d', 'display_errors=0', '-d', 'session.save_path=' + dir, '-S', '127.0.0.1:' + port, '-t', 'public'], { cwd: root, env, stdio: 'ignore' });
  const B = 'http://127.0.0.1:' + port;
  for (let i = 0; i < 100; i++) { try { await fetch(B + '/up'); break; } catch { await new Promise((r) => setTimeout(r, 50)); } }
  const webUser = () => {
    let cookie = '', csrf = '';
    const req = async (m, p, form) => {
      const r = await fetch(B + p, { method: m, redirect: 'manual', headers: { Cookie: cookie, ...(csrf ? { 'X-CSRF-Token': csrf } : {}), ...(form ? { 'Content-Type': 'application/x-www-form-urlencoded' } : {}) }, body: form ? new URLSearchParams(form) : undefined });
      for (const c of (r.headers.getSetCookie ? r.headers.getSetCookie() : [])) { const kv = c.split(';')[0]; if (kv.startsWith('PHPSESSID=')) cookie = kv; }
      return { s: r.status, loc: r.headers.get('location') || '', t: await r.text() };
    };
    const login = async (email) => {
      const lp = await req('GET', '/login'); const t = lp.t.match(/name="_csrf_token" value="([^"]+)"/)[1];
      await req('POST', '/login', { _csrf_token: t, email, password: 'Equipe@2026' });
      const pg = await req('GET', '/pacientes'); csrf = (pg.t.match(/name="_csrf_token" value="([^"]+)"/) || [])[1] || t;
      return csrf;
    };
    return { req, login, get csrf() { return csrf; } };
  };
  const secCount = () => Number(sql("SELECT COUNT(*) AS n FROM audit_logs WHERE action LIKE 'SECURITY_CROSS_TENANT%'")[0].n);
  const snapshot = () => JSON.parse(fx('snapshot'));
  const diff = (a, b) => Object.keys(a).filter((k) => a[k] !== b[k]);
  try {
    const att = webUser(); const csrf = await att.login('nutri2@example.test');
    const soon = new Date(Date.now() + 5 * 86400e3).toISOString().slice(0, 16);
    const body = { _csrf_token: csrf, _csrf: csrf, status: 'cancelled', reason: 'teste', amount: '10', quantity: '10', measure_unit: 'g', unit: 'g', food_id: String(food), meal_id: String(ids.meal), item_id: String(ids.item),
      name: 'Ataque C2', title: 'Ataque C2', patient_id: '1', notes: 'ataque', scheduled_at: soon, date: soon.slice(0, 10), time: '10:00', value: '50', target_value: '50', metric_type: 'weight_kg', direction: 'decrease',
      message: 'ataque', content: 'ataque', description: 'ataque', amount_cents: '100', due_date: soon.slice(0, 10), payment_method: 'pix', weight_kg: '60', height_cm: '160', justification: 'ataque ataque ataque',
      confirm: '1', 'metric_types[]': 'weight_kg', recipe_id: String(ids.recipe), meal_plan_id: String(ids.plan), ended_reason: 'x', feedback: 'ataque' };
    const P = 1, MIX = 3;
    // [método, caminho, rótulo do padrão de rota coberto]
    const web = [
      ['GET', `/agenda/${ids.appointment}/reagendar`], ['GET', `/agenda?id=${ids.appointment}`], ['GET', `/consulta/${ids.appointment}`], ['GET', `/consulta/${ids.appointment}/rascunho`],
      ['GET', `/pacientes/${P}`], ['GET', `/pacientes/${P}/anamnese`], ['GET', `/pacientes/${P}/antropometria`], ['GET', `/pacientes/${MIX}/antropometria/comparar?a=${ids.assessment}&b=${ids.assessment}`],
      ['GET', `/pacientes/${P}/antropometria/dobras`], ['GET', `/pacientes/${P}/editar`], ['GET', `/pacientes/${P}/pre-consulta`], ['GET', `/pacientes/${MIX}/pre-consulta/${ids.questionnaire}`],
      ['GET', `/pacientes/${P}/prontuario`], ['GET', `/pacientes/${P}/prontuario/exportar`], ['GET', `/pacientes/${P}/prontuario/imprimir`], ['GET', `/pacientes/${P}/resumo-prescricao`],
      ['GET', `/planos/${ids.plan}`], ['GET', `/planos/${ids.plan}/imprimir`], ['GET', `/receitas/${ids.recipe}`], ['GET', `/catalogo/alimentos/${ids.food_custom}/conversao?amount=1&unit=g`], ['GET', `/receitas/${ids.recipe}/editar`], ['GET', `/receitas/${ids.recipe}/prescrever`],
      ['GET', `/chat/${ids.conversation}`], ['GET', `/chat/${ids.conversation}/poll`], ['GET', `/chat/anexo/${ids.attachment}`], ['GET', `/suporte/chamado/${ids.ticket}`], ['GET', '/ajuda/chamado/SUP-C1-9070'],
      ['GET', `/ajuda/anexo/${ids.ticket_attachment}`], ['GET', `/teleconsulta/${ids.tele_session}/atendimento`], ['GET', `/teleconsulta/${ids.tele_session}/estado`], ['GET', `/teleconsulta/${ids.tele_session}/rascunho`],
      ['GET', `/teleconsulta/sessao-agenda/${ids.appointment_tele}`],
      ['POST', `/agenda/pedidos-remarcacao/${ids.change_request}/aceitar`], ['POST', `/agenda/pedidos-remarcacao/${ids.change_request}/recusar`], ['POST', `/agenda/${ids.appointment}/editar`], ['POST', `/agenda/${ids.appointment}/status`],
      ['POST', `/consulta/${ids.appointment}/concluir`], ['POST', `/consulta/${ids.appointment}/rascunho`], ['POST', `/financeiro/recebiveis/${ids.receivable}/cancelar`], ['POST', `/financeiro/recebiveis/${ids.receivable}/pagar`],
      ['POST', `/pacientes/${P}/anamnese`], ['POST', `/pacientes/${P}/antropometria`], ['POST', `/pacientes/${P}/antropometria/dobras`], ['POST', `/pacientes/${MIX}/antropometria/dobras/${ids.skinfold}/corrigir`],
      ['POST', `/pacientes/${MIX}/antropometria/${ids.assessment}/compartilhar`], ['POST', `/pacientes/${P}/autorrelatos/permissoes`], ['POST', `/pacientes/${P}/chat/vinculo`], ['POST', `/pacientes/${P}/chat/vinculo/${ids.link}/encerrar`],
      ['POST', `/pacientes/${MIX}/chat/vinculo/${ids.link}/encerrar`], ['POST', `/pacientes/${P}/consentimento/retirar`], ['POST', `/pacientes/${P}/convite-app`], ['POST', `/pacientes/${P}/editar`], ['POST', `/pacientes/${P}/metas`],
      ['POST', `/pacientes/${MIX}/metas/${ids.goal}/encerrar`], ['POST', `/pacientes/${P}/pre-consulta`], ['POST', `/pacientes/${MIX}/pre-consulta/${ids.questionnaire}/enviar`], ['POST', `/pacientes/${MIX}/pre-consulta/${ids.questionnaire}/importar`],
      ['POST', `/pacientes/${MIX}/pre-consulta/${ids.questionnaire}/perguntas`], ['POST', `/pacientes/${MIX}/pre-consulta/${ids.questionnaire}/revisar`], ['POST', `/pacientes/${MIX}/pre-consulta/${ids.questionnaire}/revogar`],
      ['POST', `/pacientes/${P}/privacidade`], ['POST', `/pacientes/${P}/prontuario`], ['POST', `/pacientes/${MIX}/prontuario/${ids.record}/retificar`], ['POST', `/pacientes/${P}/status`],
      ['POST', `/planos/${ids.plan}/duplicar`], ['POST', `/planos/${ids.plan}/publicar`], ['POST', `/planos/${ids.plan}/refeicoes`], ['POST', '/planos/9500/itens'], ['POST', `/planos/9500/itens/${ids.item}/editar`],
      ['POST', `/planos/9500/itens/${ids.item}/remover`], ['POST', `/planos/9500/refeicoes/${ids.meal}/remover`], ['POST', '/planos/9500/substituicoes'], ['POST', `/planos/9500/substituicoes/${ids.sub}/remover`],
      ['POST', `/privacidade/pedidos/${ids.dsr}/anonimizar`], ['POST', `/privacidade/pedidos/${ids.dsr}/exportar`], ['POST', `/receitas/${ids.recipe}/duplicar`], ['POST', `/receitas/${ids.recipe}/editar`], ['POST', `/receitas/${ids.recipe}/prescrever`],
      ['POST', `/suporte/chamado/${ids.ticket}/responder`], ['POST', `/suporte/chamado/${ids.ticket}/status`], ['POST', '/ajuda/chamado/SUP-C1-9070/responder'], ['POST', '/ajuda/chamado/SUP-C1-9070/resolver'], ['POST', '/ajuda/chamado/SUP-C1-9070/csat'],
      ['POST', `/chat/${ids.conversation}/enviar`], ['POST', `/teleconsulta/${ids.tele_session}/admitir`], ['POST', `/teleconsulta/${ids.tele_session}/convite`], ['POST', `/teleconsulta/${ids.tele_session}/convite-email`],
      ['POST', `/teleconsulta/${ids.tele_session}/encerrar`], ['POST', `/teleconsulta/${ids.tele_session}/evoluir`], ['POST', `/teleconsulta/${ids.tele_session}/midia`], ['POST', `/teleconsulta/${ids.tele_session}/rascunho`],
      ['POST', `/teleconsulta/${ids.tele_session}/rejeitar`], ['POST', `/teleconsulta/sessao-agenda/${ids.appointment_tele}`], ['POST', '/agenda'], ['POST', '/financeiro/recebiveis'], ['POST', '/chat/nova'],
    ];
    const ptok = (await (await fetch(B + '/api/v1/auth/professional-login', { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify({ email: 'nutri2@example.test', password: 'Equipe@2026' }) })).json()).token;
    const ctok = (await (await fetch(B + '/api/v1/auth/patient-login', { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify({ email: 'carla@example.test', password: 'PacienteTeste@2026' }) })).json()).token;
    const apiReq = async (m, p, tok, b) => { const r = await fetch(B + p, { method: m, headers: { Authorization: 'Bearer ' + tok, 'Content-Type': 'application/json', Accept: 'application/json' }, body: b ? JSON.stringify(b) : undefined }); return { s: r.status, loc: '', t: await r.text() }; };
    const apis = [
      ['GET', `/api/v1/professional/chat/${ids.conversation}/messages`, ptok], ['POST', `/api/v1/professional/chat/${ids.conversation}/read`, ptok, {}], ['POST', `/api/v1/professional/chat/${ids.conversation}/send`, ptok, { message: 'ataque', idempotency_key: 'k1' }],
      ['POST', `/api/v1/professional/food-diary/${ids.diary}/feedback`, ptok, { feedback: 'ataque' }], ['POST', `/api/v1/professional/patients/${P}/app-invitation`, ptok, {}],
      ['GET', `/api/v1/patient/appointments/${ids.appointment}/reschedule-options`, ctok], ['POST', `/api/v1/patient/appointments/${ids.appointment}/confirm`, ctok, {}], ['POST', `/api/v1/patient/appointments/${ids.appointment}/cancel`, ctok, { reason: 'x' }],
      ['POST', `/api/v1/patient/appointments/${ids.appointment}/reschedule`, ctok, { requested_scheduled_at: soon }], ['POST', `/api/v1/patient/appointments/${ids.appointment}/reschedule-requests`, ctok, { requested_scheduled_at: soon, reason: 'x' }],
      ['POST', `/api/v1/patient/reschedule-requests/${ids.change_request}/cancel`, ctok, {}], ['GET', `/api/v1/patient/chat/attachment/${ids.attachment}`, ctok], ['POST', `/api/v1/patient/chat/attachment/${ids.attachment}/link`, ctok, {}],
      ['GET', `/api/v1/patient/recipes/${ids.recipe}`, ctok], ['POST', `/api/v1/patient/recipes/${ids.recipe}/favorite`, ctok, {}], ['POST', '/api/v1/patient/appointment/confirm', ctok, { appointment_id: ids.appointment }],
    ];
    const listings = ['/pacientes?q=Ana', '/chat/pacientes?q=Ana', '/planos?paciente=1', '/financeiro', '/privacidade/pedidos', '/agenda/pedidos-remarcacao', '/dashboard', '/catalogo/alimentos/busca?q=SEGREDO', '/alimentos?q=SEGREDO'];

    // ------------------------------------------------------------------ Cobertura: toda rota com id está no teste ou excluída com motivo
    const routeSrc = readFileSync(join(root, 'public/index.php'), 'utf8');
    const paramRoutes = [...new Set([...routeSrc.matchAll(/\$router->(get|post)\('([^']*\{[^']+)'/g)].map((m) => m[2]))];
    const tested = [...web.map((x) => x[1]), ...apis.map((x) => x[1])].map((p) => p.split('?')[0]);
    const toRe = (pat) => new RegExp('^' + pat.replace(/[.*+?^$()|[\]\\]/g, '\\$&').replace(/\\\{[^}]+\\\}|\{[^}]+\}/g, '[^/]+') + '$');
    const uncovered = paramRoutes.filter((r) => !EXCLUDED[r] && !tested.some((p) => toRe(r).test(p)));
    check('cobertura', `Todas as ${paramRoutes.length} rotas com parâmetro estão no teste (${paramRoutes.length - Object.keys(EXCLUDED).length}) ou excluídas com motivo (${Object.keys(EXCLUDED).length})`, uncovered.length === 0, uncovered);

    // ------------------------------------------------------------------ Execução dos ataques
    let snap = snapshot();
    const rows = [];
    const run = async (kind, m, p, fn, def) => {
      const before = secCount(); const r = await fn(); const after = secCount(); const now = snapshot(); const changed = diff(snap, now); snap = now;
      rows.push({ kind, def, m, p, s: r.s, loc: r.loc, leak: (r.t.match(MARK) || [])[0] || null, internal: (decodeURIComponent(r.loc).match(INTERNAL) || (r.s >= 400 ? r.t.slice(0, 400).match(INTERNAL) : null) || [])[0] || null, changed, audited: after > before });
    };
    for (const d of web) await run('web', d[0], d[1], () => att.req(d[0], d[1], d[0] === 'POST' ? body : undefined), d);
    for (const d of apis) await run('api', d[0], d[1], () => apiReq(d[0], d[1], d[2], d[3]), d);
    const leaks = rows.filter((r) => r.leak), writes = rows.filter((r) => r.changed.length), errors = rows.filter((r) => r.s >= 500), internals = rows.filter((r) => r.internal), unaudited = rows.filter((r) => !r.audited);
    check('leitura', `Nenhum dos ${rows.length} pedidos com id da clínica 1 mostra dado dela (nome, e-mail, marcador)`, leaks.length === 0, leaks.map((r) => `${r.m} ${r.p} → ${r.leak}`));
    check('escrita', `Nenhum dos ${rows.length} pedidos altera registro da clínica 1 (impressão digital de todas as tabelas dela, conferida pedido a pedido)`, writes.length === 0, writes.map((r) => `${r.m} ${r.p} → ${r.changed}`));
    check('resposta', 'Nenhum erro 5xx (antes: 500 ao encerrar vínculo do chat de paciente de outra clínica)', errors.length === 0, errors.map((r) => `${r.m} ${r.p} → ${r.s}`));
    check('resposta', 'Nenhuma mensagem com detalhe interno ("403 Forbidden", "não pertence", "ID n")', internals.length === 0, internals.map((r) => `${r.m} ${r.p} → ${r.internal}`));
    check('auditoria', `Toda tentativa com id de outra clínica fica na auditoria (${rows.length - unaudited.length}/${rows.length})`, unaudited.length === 0, unaudited.map((r) => `${r.m} ${r.p} → ${r.s}`));
    const ex = sql("SELECT details FROM audit_logs WHERE action = 'SECURITY_CROSS_TENANT_ACCESS_ATTEMPT' ORDER BY id DESC LIMIT 1")[0];
    const det = ex ? JSON.parse(ex.details) : {};
    check('auditoria', 'Registro com rota, método, status, autor e alvo (recurso, id, clínica dona), sem PII', ex && det.route && det.method && det.response_status && /^(user|patient):\d+$/.test(det.actor) && Array.isArray(det.targets) && det.targets[0].clinic_id === 1 && !MARK.test(ex.details), det);

    // ------------------------------------------------------------------ Oráculo de existência
    const ghost = (p) => p.replace(/\/(\d+)(?=\/|$|\?)/g, (m, d) => (d !== '3' && d !== '9500' ? '/99' + d.padStart(5, '0') : m)).replace(/SUP-C1-9070/, 'SUP-C9-0000').replace(/([?&](?:id|a|b))=(\d+)/g, (m, k, d) => `${k}=99${d.padStart(5, '0')}`);
    const norm = (r) => `${r.s} ${(r.loc || '').replace(/\d+/g, 'N')}`;
    const oracles = [];
    for (const r of rows) {
      const g = ghost(r.p); if (g === r.p) continue;
      const def = r.def;
      const x = r.kind === 'web' ? await att.req(def[0], g, def[0] === 'POST' ? body : undefined) : await apiReq(def[0], g, def[2], def[3]);
      if (norm(r) !== norm(x)) oracles.push(`${r.m} ${r.p} → ${norm(r)} | inexistente → ${norm(x)}`);
    }
    check('resposta', 'Id de outra clínica responde igual a id inexistente (sem oráculo de existência; antes: anamnese 403 × 404)', oracles.length === 0, oracles);

    // ------------------------------------------------------------------ Listagens e buscas
    const lst = [];
    for (const p of listings) { const r = await att.req('GET', p); lst.push({ p, s: r.s, leak: (r.t.match(MARK) || [])[0] || null }); }
    for (const [m, p] of [['GET', '/api/v1/professional/patients?search=Ana'], ['GET', '/api/v1/professional/food-diaries'], ['GET', '/api/v1/professional/chat/conversations'], ['GET', '/api/v1/professional/dashboard']]) { const r = await apiReq(m, p, ptok); lst.push({ p, s: r.s, leak: (r.t.match(MARK) || [])[0] || null }); }
    const r2 = await apiReq('GET', '/api/v1/patient/chat/messages?professional_id=1', ctok); lst.push({ p: 'paciente chat professional_id=1', s: r2.s, leak: (r2.t.match(MARK) || [])[0] || null });
    check('leitura', `Listagens e buscas (${lst.length}: pacientes, conversas, planos, financeiro, LGPD, remarcações, painel, catálogo, APIs) sem nada da clínica 1`, lst.every((x) => !x.leak && x.s < 500), lst.filter((x) => x.leak || x.s >= 500));

    // ------------------------------------------------------------------ Sem ruído: a própria clínica não gera alerta
    const own = webUser(); await own.login('nutri1@example.test');
    const before = secCount();
    for (const p of [`/pacientes/${P}`, `/planos/${ids.plan}`, `/agenda?id=${ids.appointment}`, `/chat/${ids.conversation}`, `/receitas/${ids.recipe}`]) await own.req('GET', p);
    check('auditoria', 'Acessos da própria clínica 1 aos mesmos ids não geram alerta de acesso cruzado', secCount() === before, secCount() - before);

    // ------------------------------------------------------------------ Estado final da clínica 1
    const st = sql(`SELECT (SELECT status FROM meal_plans WHERE id = ${ids.plan}) AS plano, (SELECT status FROM receivables WHERE id = ${ids.receivable}) AS cobranca, (SELECT status FROM appointments WHERE id = ${ids.appointment}) AS consulta,
      (SELECT status FROM appointment_change_requests WHERE id = ${ids.change_request}) AS pedido, (SELECT status FROM patient_professional_links WHERE id = ${ids.link}) AS vinculo, (SELECT COUNT(*) FROM meal_plan_items WHERE id = ${ids.item}) AS item,
      (SELECT COUNT(*) FROM chat_messages WHERE conversation_id = ${ids.conversation} AND message = 'ataque') AS msg, (SELECT status FROM patients WHERE id = 1) AS paciente`)[0];
    check('escrita', 'Estado final da clínica 1 intacto (plano rascunho, cobrança pendente, consulta agendada, pedido pendente, vínculo ativo, item existe, nenhuma mensagem do atacante)',
      st.plano === 'draft' && st.cobranca === 'pending' && st.consulta === 'scheduled' && st.pedido === 'pending' && st.vinculo === 'active' && Number(st.item) === 1 && Number(st.msg) === 0 && st.paciente === 'active', st);
    console.log(`(${rows.length} ataques, ${lst.length} listagens, ${paramRoutes.length} rotas com parâmetro)`);
  } catch (e) {
    check('erro', 'Execução sem exceção', false, e.stack);
  } finally {
    server.kill();
    if (useMysql) { try { mysqlAdmin(`DROP DATABASE ${mysqlDb}`); } catch { /* */ } }
    rmSync(dir, { recursive: true, force: true });
  }
  const pass = results.filter((x) => x.ok).length;
  console.log(`\nNI2-24 (${useMysql ? 'MySQL' : 'SQLite'}): ${pass} PASS, ${results.length - pass} FAIL`);
  if (process.env.RESULTS_JSON) writeFileSync(process.env.RESULTS_JSON, JSON.stringify({ driver: useMysql ? 'mysql' : 'sqlite', pass, fail: results.length - pass, results }, null, 1));
  if (pass !== results.length) process.exitCode = 1;
})();
