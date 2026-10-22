// NI2-137 — próxima consulta da paciente e ações permitidas (confirmar, cancelar, pedir remarcação): teste real por HTTP.
// public/index.php em `php -S` (APP_ENV=production), banco isolado (SQLite ou MySQL), dados fictícios
// (tests/support/ni2_137_fixture.php), logins reais: paciente pela API (Bearer e cookie web), equipe pelo formulário com CSRF.
// Datas relativas a agora (o teste não depende do dia em que roda).
//   node tests/ni2_137_patient_next_appointment_actions_real_test.cjs   [RESULTS_JSON=…]
//   DB_CONNECTION=mysql DB_HOST=… DB_PORT=… DB_USERNAME=… DB_PASSWORD=… node tests/ni2_137_…cjs
'use strict';
const { spawn, execFileSync } = require('node:child_process');
const { mkdtempSync, rmSync, writeFileSync } = require('node:fs');
const { join, resolve } = require('node:path');
const net = require('node:net');

const root = resolve(process.env.APP_ROOT || join(__dirname, '..'));
const dir = mkdtempSync('/tmp/ni2-137-');
const useMysql = process.env.DB_CONNECTION === 'mysql';
const mysqlDb = 'ni2_137_test_' + Math.random().toString(36).slice(2, 10);
const env = { ...process.env, DB_CONNECTION: useMysql ? 'mysql' : 'sqlite', DB_DATABASE: useMysql ? mysqlDb : join(dir, 'test.sqlite'), NI2_FIXTURE_DIR: dir,
  APP_ENV: 'production', FORCE_HTTPS: 'false', APP_TIMEZONE: 'America/Sao_Paulo', MAIL_DRIVER: 'null', TZ: 'America/Sao_Paulo', APP_URL: 'http://app.example.test', APP_KEY: 'ni2-137-chave-de-teste-ficticia-0001' };
const mysqlAdmin = (sqlText) => execFileSync('php', ['-r', '$p=new PDO("mysql:host=".getenv("DB_HOST").";port=".getenv("DB_PORT"),getenv("DB_USERNAME"),getenv("DB_PASSWORD"));$p->exec($argv[1]);', sqlText], { env });
const results = [];
function check(crit, name, ok, detail) {
  results.push({ crit, name, ok: !!ok, detail: ok ? null : (detail === undefined ? null : detail) });
  console.log(`${ok ? 'PASS' : 'FAIL'} [${crit}] ${name}${!ok && detail !== undefined ? ' → ' + JSON.stringify(detail).slice(0, 500) : ''}`);
}
const fx = (args) => execFileSync('php', [join(root, 'tests/support/ni2_137_fixture.php'), ...args.map(String)], { cwd: root, env, encoding: 'utf8', stdio: ['ignore', 'pipe', 'pipe'] });
const sql = (q) => JSON.parse(fx(['sql', q]));
const one = (q) => { const r = sql(q)[0]; return r ? Object.values(r)[0] : null; };
const appt = (id, patient, clinic, prof, status, hours, type) => JSON.parse(fx(['appt', id, patient, clinic, prof, status, hours, type]));
const settings = (o) => JSON.parse(fx(['settings', JSON.stringify(o)]));
const localNow = () => execFileSync('php', ['-r', 'date_default_timezone_set("America/Sao_Paulo"); echo date("Y-m-d H:i:s");'], { encoding: 'utf8' });
const addMin = (sqlDt, min) => execFileSync('php', ['-r', 'date_default_timezone_set("America/Sao_Paulo"); echo (new DateTimeImmutable($argv[1]))->modify($argv[2] . " minutes")->format("Y-m-d H:i:s");', sqlDt, String(min)], { encoding: 'utf8' });
const NEXT_KEYS = ['id', 'scheduled_at', 'scheduled_at_iso', 'ends_at_iso', 'timezone', 'timezone_label', 'duration_minutes', 'status', 'status_label', 'modality',
  'modality_label', 'is_online', 'professional_name', 'clinic_name', 'clinic_phone', 'location', 'meeting_url', 'video_opens_at_iso', 'video_status', 'allowed_actions', 'change_request'];

(async () => {
  if (useMysql) mysqlAdmin(`CREATE DATABASE ${mysqlDb} CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci`);
  fx(['prepare']);
  const sock = net.createServer(); await new Promise((r) => sock.listen(0, '127.0.0.1', r)); const port = sock.address().port; await new Promise((r) => sock.close(r));
  const server = spawn('php', ['-d', 'display_errors=0', '-d', 'session.save_path=' + dir, '-S', '127.0.0.1:' + port, '-t', 'public'], { cwd: root, env, stdio: 'ignore' });
  const B = 'http://127.0.0.1:' + port;
  for (let i = 0; i < 100; i++) { try { await fetch(B + '/up'); break; } catch { await new Promise((r) => setTimeout(r, 50)); } }
  const api = async (method, path, { token, body, cookie, origin, web } = {}) => {
    const h = { Accept: 'application/json' };
    if (token) h.Authorization = 'Bearer ' + token;
    if (cookie) h.Cookie = cookie;
    if (origin) h.Origin = origin;
    if (web) h['X-ClyoNutri-Web'] = '1';
    if (body !== undefined) h['Content-Type'] = 'application/json';
    const res = await fetch(B + path, { method, headers: h, body: body !== undefined ? JSON.stringify(body) : undefined });
    const text = await res.text(); let json = null; try { json = JSON.parse(text); } catch { /* */ }
    return { status: res.status, text, json, headers: res.headers };
  };
  const webUser = () => {
    let cookie = '';
    const req = async (method, path, form) => {
      const res = await fetch(B + path, { method, redirect: 'manual', headers: { Cookie: cookie, ...(form ? { 'Content-Type': 'application/x-www-form-urlencoded' } : {}) }, body: form ? new URLSearchParams(form).toString() : undefined });
      const set = res.headers.getSetCookie ? res.headers.getSetCookie() : [];
      for (const c of set) { const kv = c.split(';')[0]; if (kv.startsWith('PHPSESSID=')) cookie = kv; }
      return { status: res.status, location: res.headers.get('location') || '', text: await res.text() };
    };
    const csrf = async (path) => { const r = await req('GET', path); const m = r.text.match(/name="_csrf_token" value="([^"]+)"/); return m ? m[1] : ''; };
    const login = async (email) => { const t = await csrf('/login'); return req('POST', '/login', { _csrf_token: t, email, password: 'Equipe@2026' }); };
    return { req, csrf, login };
  };
  const decide = async (user, requestId, action, extra = {}) => {
    const t = await user.csrf('/agenda/pedidos-remarcacao');
    const r = await user.req('POST', `/agenda/pedidos-remarcacao/${requestId}/${action}`, { _csrf_token: t, ...extra });
    const page = await user.req('GET', '/agenda/pedidos-remarcacao');
    return { post: r, page: page.text };
  };
  try {
    const tok = async (email) => (await api('POST', '/api/v1/auth/patient-login', { body: { email, password: 'PacienteTeste@2026' } })).json.token;
    const ana = await tok('ana@example.test'), bruno = await tok('bruno@example.test'), carla = await tok('carla@example.test');
    const home = async (t = ana) => (await api('GET', '/api/v1/patient/home', { token: t })).json.next_appointment;
    let r, n, h, au, ad, d, page, t, s, threw, a201;
    try {

    // ------------------------------------------------------------------ card (M1, M2, M5) e padrões (A1)
    a201 = appt(201, 1, 1, 1, 'scheduled', 48, 'follow_up');
    appt(202, 2, 1, 1, 'scheduled', 50, 'follow_up');   // Bruno, mesma clínica e profissional
    appt(203, 3, 2, 2, 'scheduled', 48, 'follow_up');   // Carla, outra clínica
    r = await api('GET', '/api/v1/patient/home', { token: ana });
    n = r.json && r.json.next_appointment;
    check('card', 'GET /home traz a próxima consulta da Ana (201)', r.status === 200 && n && n.id === 201, r.text.slice(0, 300));
    check('card M5', 'Lista fechada de campos (sem notes, type, professional_id, created_at, updated_at, patient_id, clinic_id)',
      n && JSON.stringify(Object.keys(n).sort()) === JSON.stringify([...NEXT_KEYS].sort()), n && Object.keys(n));
    check('card M5', 'Observação interna da agenda não sai para a paciente', !/Nota interna/.test(r.text));
    const oa = require(join(root, 'docs/api/openapi_v1.json'));
    check('B4', 'OpenAPI: NextAppointment documenta exatamente os campos devolvidos', JSON.stringify([...(oa.components.schemas.NextAppointment?.required || [])].sort()) === JSON.stringify([...NEXT_KEYS].sort())
      && ['/patient/appointments/{id}/confirm', '/patient/appointments/{id}/cancel', '/patient/appointments/{id}/reschedule-options', '/patient/appointments/{id}/reschedule-requests', '/patient/reschedule-requests/{id}/cancel'].every((x) => oa.paths[x]));
    check('card M1', 'scheduled_at_iso com offset -03:00 e igual à hora local gravada', n && n.scheduled_at_iso === a201.scheduled_at.replace(' ', 'T') + '-03:00', n && [n.scheduled_at_iso, a201.scheduled_at]);
    check('card M1', 'Fuso informado: America/Sao_Paulo, "horário de Brasília"', n && n.timezone === 'America/Sao_Paulo' && n.timezone_label === 'horário de Brasília');
    check('card M2', 'Presencial aparece como "Presencial" com o endereço do consultório', n && n.modality === 'presencial' && n.modality_label === 'Presencial' && /Rua Fictícia, 100/.test(n.location || ''), n && [n.modality, n.location]);
    check('card A1', 'Padrão da clínica: confirmar liberado; cancelar e remarcar bloqueados com motivo', n && n?.allowed_actions?.confirm?.allowed === true
      && n?.allowed_actions?.cancel?.allowed === false && /fale com o consultório/i.test(n?.allowed_actions?.cancel?.reason || '')
      && n?.allowed_actions?.reschedule?.allowed === false && !!n?.allowed_actions?.reschedule?.reason, n && n.allowed_actions);
    settings({ patient_show_address: false });
    n = await home();
    check('card M2', 'Clínica desliga o endereço: location = null', n.location === null, n.location);
    settings({ patient_show_address: true });
    r = await api('GET', '/api/v1/patient/home', { token: bruno });
    check('card', 'Bruno vê só a própria consulta (202)', r.json.next_appointment && r.json.next_appointment.id === 202);

    // ------------------------------------------------------------------ ações desligadas (A1)
    r = await api('POST', '/api/v1/patient/appointments/201/cancel', { token: ana, body: { reason: 'teste' } });
    check('A1', 'Cancelar com a ação desligada → 403 ACTION_NOT_ALLOWED', r.status === 403 && r.json.code === 'ACTION_NOT_ALLOWED', r.text);
    check('A1', 'Autoagendamento público desligado (is_enabled = 0) e cancelamento não passou', one("SELECT status FROM appointments WHERE id = 201") === 'scheduled'
      && Number(one("SELECT COUNT(*) FROM booking_settings WHERE clinic_id = 1 AND is_enabled = 1")) === 0);
    r = await api('GET', '/api/v1/patient/appointments/201/reschedule-options', { token: ana });
    check('A1', 'Horários para remarcar com a ação desligada → 403', r.status === 403 && r.json.code === 'ACTION_NOT_ALLOWED', r.text);
    r = await api('POST', '/api/v1/patient/appointments/201/reschedule-requests', { token: ana, body: { requested_scheduled_at: addMin(a201.scheduled_at, 24 * 60) } });
    check('A1', 'Pedido de remarcação com a ação desligada → 403', r.status === 403 && r.json.code === 'ACTION_NOT_ALLOWED', r.text);
    r = await api('POST', '/api/v1/patient/appointments/201/reschedule', { token: ana, body: { new_scheduled_at: addMin(a201.scheduled_at, 24 * 60) } });
    check('A1', 'Rota antiga /reschedule também bloqueada (403) e a consulta não se move', r.status === 403 && one("SELECT scheduled_at FROM appointments WHERE id = 201") === a201.scheduled_at, [r.status, r.text]);
    settings({ patient_can_confirm: false });
    n = await home();
    r = await api('POST', '/api/v1/patient/appointments/201/confirm', { token: ana });
    check('A1', 'Clínica desliga a confirmação: allowed_actions.confirm=false e POST → 403', n?.allowed_actions?.confirm?.allowed === false && r.status === 403 && r.json.code === 'ACTION_NOT_ALLOWED', [n.allowed_actions.confirm, r.text]);
    settings({ patient_can_confirm: true });

    // ------------------------------------------------------------------ confirmar (A4, M4, M7, B1)
    r = await api('POST', '/api/v1/patient/appointments/201/confirm');
    check('auth', 'Sem token → 401', r.status === 401);
    r = await api('POST', '/api/v1/patient/appointments/201/confirm', { token: bruno });
    check('B1', 'Bruno confirma a consulta da Ana → 404 "Consulta não encontrada." sem ids', r.status === 404 && r.json.code === 'APPOINTMENT_NOT_FOUND' && !/\d/.test(r.json.error), r.text);
    r = await api('POST', '/api/v1/patient/appointment/confirm', { token: carla, body: { appointment_id: 201 } });
    check('B1', 'Carla (outra clínica) pela rota antiga → 404 sem ids', r.status === 404 && !/\d/.test(r.json.error), r.text);
    check('B1', 'Nada mudou na consulta da Ana', one("SELECT status FROM appointments WHERE id = 201") === 'scheduled');
    const beforeConfirm = localNow();
    r = await api('POST', '/api/v1/patient/appointments/201/confirm', { token: ana });
    check('M4', 'Ana confirma → 200 success:true, status confirmed', r.status === 200 && r.json.success === true && r.json.status === 'confirmed' && one("SELECT status FROM appointments WHERE id = 201") === 'confirmed', r.text);
    h = sql("SELECT from_status, to_status, changed_by_user_id, changed_by_patient_id, notes, created_at FROM appointment_status_history WHERE appointment_id = 201 ORDER BY id DESC")[0];
    check('M7', 'Histórico: scheduled → confirmed, autora = paciente 1, sem usuário', h && h.from_status === 'scheduled' && h.to_status === 'confirmed' && Number(h.changed_by_patient_id) === 1 && h.changed_by_user_id === null && /paciente \(app\)/.test(h.notes), h);
    check('M7', 'Histórico no fuso da aplicação (não UTC)', h && h.created_at >= beforeConfirm.slice(0, 16) && h.created_at <= addMin(localNow(), 1), [h && h.created_at, beforeConfirm]);
    au = sql("SELECT user_id, resource, details FROM audit_logs WHERE action = 'APPOINTMENT_CONFIRMED_BY_PATIENT' AND resource_id = '201'")[0];
    ad = au ? JSON.parse(au.details) : {};
    check('M7', 'Auditoria: recurso appointment, actor_type patient, actor_id 1, canal app, de/para', au && au.resource === 'appointment' && ad.actor_type === 'patient' && ad.actor_id === 1 && ad.channel === 'app' && ad.from_status === 'scheduled' && ad.to_status === 'confirmed', au);
    r = await api('POST', '/api/v1/patient/appointments/201/confirm', { token: ana });
    check('A4', 'Confirmar de novo → 409 INVALID_STATE com current_status (antes: 200 success:false)', r.status === 409 && r.json.code === 'INVALID_STATE' && r.json.current_status === 'confirmed', r.text);
    r = await api('POST', '/api/v1/patient/appointment/confirm', { token: ana, body: { appointment_id: 201 } });
    check('A4', 'Rota antiga também responde 409 (nunca 200 com success:false)', r.status === 409 && r.json.code === 'INVALID_STATE', r.text);
    appt(205, 1, 1, 1, 'scheduled', -48, 'follow_up');
    r = await api('POST', '/api/v1/patient/appointments/205/confirm', { token: ana });
    check('M4', 'Consulta de 2 dias atrás não pode ser confirmada → 409 "já passou"', r.status === 409 && /já passou/.test(r.json.error) && one("SELECT status FROM appointments WHERE id = 205") === 'scheduled', r.text);
    fx(['staff-status', 205, 'cancelled']);
    r = await api('POST', '/api/v1/patient/appointments/205/confirm', { token: ana });
    check('A4', 'Consulta cancelada pela clínica: confirmar → 409 com current_status cancelled', r.status === 409 && r.json.current_status === 'cancelled', r.text);
    r = await api('POST', '/api/v1/patient/appointments/999999/confirm', { token: ana });
    check('B1', 'Consulta inexistente → 404', r.status === 404 && r.json.code === 'APPOINTMENT_NOT_FOUND');

    } catch (e) { check('erro', 'Seção sem exceção', false, e.stack.split('\n').slice(0, 2).join(' ')); }
    try {
    // ------------------------------------------------------------------ cancelar (C1, B2, B3)
    settings({ patient_can_cancel: true, cancel_min_advance_hours: 4 });
    threw = false; try { settings({ cancel_min_advance_hours: 0 }); } catch { threw = true; }
    check('B3', 'Antecedência de 0 hora para cancelar é recusada no servidor', threw && Number(one("SELECT cancel_min_advance_hours FROM booking_settings WHERE clinic_id = 1")) === 4);
    appt(206, 1, 1, 1, 'scheduled', 30, 'online');
    fx(['tele', 206]);
    check('C1', 'Antes: sessão de teleconsulta ativa e bilhete do portal emitido', one("SELECT status FROM teleconsultation_sessions WHERE appointment_id = 206") === 'scheduled' && JSON.parse(fx(['portal-ticket', 1, 206])).ticket === true);
    r = await api('POST', '/api/v1/patient/appointments/206/cancel', { token: ana, body: { reason: 'x'.repeat(301) } });
    check('cancelar', 'Motivo acima de 300 caracteres → 422', r.status === 422 && r.json.code === 'VALIDATION_ERROR', r.text);
    r = await api('POST', '/api/v1/patient/appointments/206/cancel', { token: bruno, body: {} });
    check('B1', 'Bruno cancela a consulta da Ana → 404 e nada muda', r.status === 404 && one("SELECT status FROM appointments WHERE id = 206") === 'scheduled');
    r = await api('POST', '/api/v1/patient/appointments/206/cancel', { token: ana, body: { reason: 'Imprevisto no trabalho' } });
    check('cancelar', 'Ana cancela → 200, consulta cancelada', r.status === 200 && r.json.success === true && one("SELECT status FROM appointments WHERE id = 206") === 'cancelled', r.text);
    const sess = sql("SELECT status, invite_revoked_at_utc FROM teleconsultation_sessions WHERE appointment_id = 206")[0];
    check('C1', 'Teleconsulta cancelada junto e convite revogado', sess.status === 'cancelled' && !!sess.invite_revoked_at_utc, sess);
    check('C1', 'Depois do cancelamento o portal não emite mais bilhete', JSON.parse(fx(['portal-ticket', 1, 206])).ticket === false);
    h = sql("SELECT from_status, to_status, changed_by_patient_id, notes FROM appointment_status_history WHERE appointment_id = 206 ORDER BY id DESC")[0];
    check('M7', 'Histórico do cancelamento com a paciente e o motivo', h.from_status === 'scheduled' && h.to_status === 'cancelled' && Number(h.changed_by_patient_id) === 1 && /Imprevisto no trabalho/.test(h.notes), h);
    au = sql("SELECT details FROM audit_logs WHERE action = 'APPOINTMENT_CANCELLED_BY_PATIENT' AND resource_id = '206'")[0];
    ad = au ? JSON.parse(au.details) : {};
    check('M7', 'Auditoria do cancelamento (paciente, canal, de/para, horário, sem o texto do motivo)', ad.actor_type === 'patient' && ad.from_status === 'scheduled' && ad.to_status === 'cancelled' && !!ad.scheduled_at && ad.reason_given === true && !/Imprevisto/.test(au.details), au);
    r = await api('POST', '/api/v1/patient/appointments/206/cancel', { token: ana, body: {} });
    check('cancelar', 'Cancelar de novo → 409 INVALID_STATE', r.status === 409 && r.json.code === 'INVALID_STATE', r.text);
    appt(207, 1, 1, 1, 'scheduled', 2, 'follow_up');
    r = await api('POST', '/api/v1/patient/appointments/207/cancel', { token: ana, body: {} });
    check('cancelar', 'Dentro das 4 horas antes → 422 POLICY_VIOLATION com o prazo na mensagem', r.status === 422 && r.json.code === 'POLICY_VIOLATION' && /4 horas/.test(r.json.error) && one("SELECT status FROM appointments WHERE id = 207") === 'scheduled', r.text);
    fx(['del', 207]);
    appt(208, 1, 1, 1, 'scheduled', 30, 'follow_up');
    fx(['staff-status', 208, 'in_progress']);
    r = await api('POST', '/api/v1/patient/appointments/208/cancel', { token: ana, body: {} });
    check('B2', 'Consulta em andamento não pode ser cancelada pela paciente → 409', r.status === 409 && r.json.code === 'INVALID_STATE' && one("SELECT status FROM appointments WHERE id = 208") === 'in_progress', r.text);
    fx(['del', 208]);

    } catch (e) { check('erro', 'Seção sem exceção', false, e.stack.split('\n').slice(0, 2).join(' ')); }
    try {
    // ------------------------------------------------------------------ vídeo só na janela (M3)
    appt(211, 1, 1, 1, 'scheduled', 24, 'online'); fx(['tele', 211]);
    n = await home();
    check('M3', 'Teleconsulta amanhã: sem meeting_url, video_status aguardando_horario e video_opens_at 15 min antes',
      n.id === 211 && n.meeting_url === null && n.video_status === 'aguardando_horario' && n.video_opens_at_iso === addMin(n.scheduled_at, -15).replace(' ', 'T') + '-03:00' && n.modality_label === 'Teleconsulta (online)' && n.location === null,
      n && [n.id, n.meeting_url, n.video_status, n.video_opens_at_iso]);
    appt(212, 1, 1, 1, 'scheduled', 0.1, 'online'); fx(['tele', 212]);
    n = await home();
    check('M3', 'Dentro da janela: meeting_url com bilhete da sala de espera', n.id === 212 && /^http:\/\/app\.example\.test\/teleconsulta\/sala-espera\//.test(n.meeting_url || '') && n.video_status === 'sala_disponivel', n && [n.id, n.meeting_url, n.video_status]);
    fx(['del', 211]); fx(['del', 212]);

    } catch (e) { check('erro', 'Seção sem exceção', false, e.stack.split('\n').slice(0, 2).join(' ')); }
    try {
    // ------------------------------------------------------------------ pedido de remarcação (A3, C1)
    settings({ patient_can_request_reschedule: true, reschedule_min_advance_hours: 4 });
    n = await home();
    check('A3', 'Remarcação liberada aparece em allowed_actions (201, confirmada)', n.id === 201 && n?.allowed_actions?.reschedule?.allowed === true, n.allowed_actions);
    r = await api('GET', '/api/v1/patient/appointments/201/reschedule-options', { token: ana });
    const slots = r.json && r.json.days ? r.json.days.flatMap((d) => d.slots) : [];
    const minStart = addMin(localNow(), 4 * 60);
    const b202 = one("SELECT scheduled_at FROM appointments WHERE id = 202");
    check('A1', 'Horários livres mesmo com o autoagendamento público desligado', r.status === 200 && slots.length > 0, r.text.slice(0, 200));
    check('A3', 'Horários: dentro da jornada (07:00–22:00), ≥ 4 h à frente, sem o horário atual', slots.every((s) => s.scheduled_at.slice(11, 16) >= '07:00' && addMin(s.scheduled_at, 60).slice(11, 16) <= '22:00' && s.scheduled_at >= minStart && s.scheduled_at !== a201.scheduled_at) && slots.every((s) => /-03:00$/.test(s.scheduled_at_iso)), slots.slice(0, 3));
    check('A3', 'Horários não colidem com a consulta do Bruno', slots.every((s) => !(s.scheduled_at < addMin(b202, 60) && addMin(s.scheduled_at, 60) > b202)), b202);
    r = await api('POST', '/api/v1/patient/appointments/201/reschedule-requests', { token: ana, body: { requested_scheduled_at: 'amanhã' } });
    check('A3', 'Horário malformado → 422', r.status === 422 && r.json.code === 'VALIDATION_ERROR', r.text);
    const notOffered = addMin(a201.scheduled_at.slice(0, 10) + ' 03:00:00', 24 * 60);
    r = await api('POST', '/api/v1/patient/appointments/201/reschedule-requests', { token: ana, body: { requested_scheduled_at: notOffered } });
    check('A3', 'Horário fora da disponibilidade → 409 SLOT_CONFLICT', r.status === 409 && r.json.code === 'SLOT_CONFLICT', r.text);
    const X = slots[Math.min(5, slots.length - 1)].scheduled_at;
    r = await api('POST', '/api/v1/patient/appointments/201/reschedule-requests', { token: ana, body: { requested_scheduled_at: X, reason: 'Viagem a trabalho' } });
    const req1 = r.json && r.json.request;
    check('A3', 'Pedido válido → 202 com pedido pendente', r.status === 202 && req1 && req1.status === 'pending' && req1.requested_scheduled_at_iso === X.replace(' ', 'T') + '-03:00', r.text);
    check('A3', 'Consulta não se move nem muda de status enquanto o pedido está pendente', one("SELECT scheduled_at FROM appointments WHERE id = 201") === a201.scheduled_at && one("SELECT status FROM appointments WHERE id = 201") === 'confirmed');
    au = sql("SELECT details FROM audit_logs WHERE action = 'APPOINTMENT_RESCHEDULE_REQUESTED' AND resource_id = '201'")[0];
    ad = au ? JSON.parse(au.details) : {};
    check('M7', 'Auditoria do pedido com horário de/para e canal', ad.actor_type === 'patient' && ad.from_scheduled_at === a201.scheduled_at && ad.to_scheduled_at === X && ad.channel === 'app', au);
    r = await api('POST', '/api/v1/patient/appointments/201/reschedule-requests', { token: ana, body: { requested_scheduled_at: slots[0].scheduled_at } });
    check('A3', 'Segundo pedido com um pendente → 409 REQUEST_PENDING', r.status === 409 && r.json.code === 'REQUEST_PENDING', r.text);
    n = await home();
    check('A3', 'Card mostra o pedido pendente e bloqueia novo pedido com o motivo', n.change_request && n.change_request.status === 'pending' && n?.allowed_actions?.reschedule?.allowed === false && /aguardando/.test(n?.allowed_actions?.reschedule?.reason), [n.change_request, n.allowed_actions.reschedule]);

    // equipe: fila, banner na agenda, aceitar
    const nutri2 = webUser(); await nutri2.login('nutri2@example.test');
    d = await decide(nutri2, req1.id, 'aceitar');
    check('equipe', 'Equipe de outra clínica não acha o pedido e nada muda', /Pedido não encontrado/.test(d.page) && one(`SELECT status FROM appointment_change_requests WHERE id = ${req1.id}`) === 'pending', d.page.match(/data-testid="change-request-flash"[^<]*>([^<]*)/));
    const recepOnly = webUser(); await recepOnly.login('recepcao@example.test');
    page = (await recepOnly.req('GET', '/agenda')).text;
    check('equipe', 'Recepção vê o aviso na agenda, sem link (não remarca consultas)', /1 pedido de remarcação/.test(page) && /O nutricionista responde/.test(page) && !/href="\/agenda\/pedidos-remarcacao"/.test(page));
    let rr = await recepOnly.req('GET', '/agenda/pedidos-remarcacao');
    const rt = await recepOnly.csrf('/agenda');
    const rp = await recepOnly.req('POST', `/agenda/pedidos-remarcacao/${req1.id}/aceitar`, { _csrf_token: rt });
    check('equipe', 'Recepção não abre a fila nem aceita (403), como no reagendamento da agenda', rr.status === 403 && rp.status === 403 && one(`SELECT status FROM appointment_change_requests WHERE id = ${req1.id}`) === 'pending', [rr.status, rp.status]);
    const equipe = webUser(); await equipe.login('equipe@example.test');   // nutricionista da equipe
    page = (await equipe.req('GET', '/agenda')).text;
    check('equipe', 'Nutricionista vê o aviso na agenda com link para responder', /1 pedido de remarcação/.test(page) && /href="\/agenda\/pedidos-remarcacao"/.test(page));
    page = (await equipe.req('GET', '/dashboard')).text;
    check('equipe', 'Painel "Atenção" do dashboard lista o pedido de remarcação com link', /Pedido de remarcação/.test(page) && /1 paciente pediu outro horário pelo app/.test(page) && /href="\/agenda\/pedidos-remarcacao"/.test(page), page.match(/remarca[^<]{0,80}/g));
    page = (await equipe.req('GET', '/agenda/pedidos-remarcacao')).text;
    check('equipe', 'Fila mostra a Ana, o horário atual, o pedido e o motivo', /Ana Fictícia/.test(page) && /Viagem a trabalho/.test(page) && page.includes(X.slice(8, 10) + '/' + X.slice(5, 7) + '/' + X.slice(0, 4) + ' às ' + X.slice(11, 16)), page.replace(/\s+/g, ' ').slice(-3000));
    appt(209, 1, 1, 1, 'scheduled', 60, 'online'); fx(['tele', 209]);
    const tBefore = sql("SELECT join_opens_at_utc, invite_revoked_at_utc FROM teleconsultation_sessions WHERE appointment_id = 209")[0];
    d = await decide(equipe, req1.id, 'aceitar');
    check('A3', 'Nutricionista da equipe aceita → consulta vai para o horário pedido', one("SELECT scheduled_at FROM appointments WHERE id = 201") === X, [one("SELECT scheduled_at FROM appointments WHERE id = 201"), d.page.match(/change-request-flash"[^>]*>([^<]*)/)]);
    check('A3', 'Consulta confirmada volta para "agendada" (a paciente confirmou o horário antigo)', one("SELECT status FROM appointments WHERE id = 201") === 'scheduled');
    const hist = sql("SELECT from_status, to_status, changed_by_user_id, notes FROM appointment_status_history WHERE appointment_id = 201 ORDER BY id");
    check('A3', 'Histórico: remarcação (com o motivo da paciente) e confirmed → scheduled, pela nutricionista', hist.some((x) => /reagendada de/.test(x.notes) && /Viagem a trabalho/.test(x.notes) && Number(x.changed_by_user_id) === 3) && hist.some((x) => x.from_status === 'confirmed' && x.to_status === 'scheduled' && Number(x.changed_by_user_id) === 3), hist);
    check('A3', 'Pedido marcado como aceito, por quem e quando', one(`SELECT status FROM appointment_change_requests WHERE id = ${req1.id}`) === 'accepted' && Number(one(`SELECT decided_by_user_id FROM appointment_change_requests WHERE id = ${req1.id}`)) === 3);
    check('equipe', 'Mensagem pede nova confirmação da paciente', /a paciente precisa confirmar o novo horário/.test(d.page));
    n = await home();
    check('A3', 'Card: novo horário, pedido aceito e confirmar liberado de novo', n.id === 201 && n.scheduled_at === X && n.change_request.status === 'accepted' && n?.allowed_actions?.confirm?.allowed === true, [n.scheduled_at, n.change_request && n.change_request.status]);

    // C1 na remarcação: teleconsulta recalcula a janela e revoga o convite
    r = await api('GET', '/api/v1/patient/appointments/209/reschedule-options', { token: ana });
    const s209 = r.json.days.flatMap((x) => x.slots).filter((s) => s.scheduled_at > addMin(localNow(), 26 * 60))[0].scheduled_at;
    r = await api('POST', '/api/v1/patient/appointments/209/reschedule-requests', { token: ana, body: { requested_scheduled_at: s209 } });
    const req209 = r.json.request;
    d = await decide(equipe, req209.id, 'aceitar');
    const tAfter = sql("SELECT status, join_opens_at_utc, invite_revoked_at_utc FROM teleconsultation_sessions WHERE appointment_id = 209")[0];
    const expectedOpensUtc = execFileSync('php', ['-r', '$d=new DateTimeImmutable($argv[1], new DateTimeZone("America/Sao_Paulo")); echo $d->modify("-15 minutes")->setTimezone(new DateTimeZone("UTC"))->format("Y-m-d H:i:s");', s209], { encoding: 'utf8' });
    check('C1', 'Teleconsulta remarcada: janela recalculada para o novo horário e convite antigo revogado',
      one("SELECT scheduled_at FROM appointments WHERE id = 209") === s209 && tAfter.join_opens_at_utc === expectedOpensUtc && tAfter.join_opens_at_utc !== tBefore.join_opens_at_utc && !!tAfter.invite_revoked_at_utc && !tBefore.invite_revoked_at_utc,
      { tBefore, tAfter, expectedOpensUtc });
    check('equipe', 'Mensagem lembra de enviar novo convite da teleconsulta', /novo convite da teleconsulta/.test(d.page));

    // recusar
    r = await api('GET', '/api/v1/patient/appointments/201/reschedule-options', { token: ana });
    const Y = r.json.days.flatMap((x) => x.slots)[2].scheduled_at;
    r = await api('POST', '/api/v1/patient/appointments/201/reschedule-requests', { token: ana, body: { requested_scheduled_at: Y } });
    const req2 = r.json.request;
    d = await decide(equipe, req2.id, 'recusar', { decision_note: 'Esse horário não dá; me chame no chat' });
    n = await home();
    check('A3', 'Recusar: consulta fica no horário atual e a paciente vê a resposta', one("SELECT scheduled_at FROM appointments WHERE id = 201") === X && n.change_request.status === 'declined' && n.change_request.decision_note === 'Esse horário não dá; me chame no chat', n.change_request);
    d = await decide(equipe, req2.id, 'aceitar');
    check('A3', 'Pedido já recusado não pode ser aceito depois', /já foi respondido/.test(d.page) && one("SELECT scheduled_at FROM appointments WHERE id = 201") === X);

    // desistir
    r = await api('POST', '/api/v1/patient/appointments/201/reschedule-requests', { token: ana, body: { requested_scheduled_at: Y } });
    const req3 = r.json.request;
    r = await api('POST', `/api/v1/patient/reschedule-requests/${req3.id}/cancel`, { token: bruno });
    check('B1', 'Bruno não retira o pedido da Ana → 404', r.status === 404 && r.json.code === 'REQUEST_NOT_FOUND', r.text);
    r = await api('POST', `/api/v1/patient/reschedule-requests/${req3.id}/cancel`, { token: ana });
    check('A3', 'Ana desiste do pedido → 200, pedido encerrado', r.status === 200 && r.json.request.status === 'cancelled', r.text);
    r = await api('POST', `/api/v1/patient/reschedule-requests/${req3.id}/cancel`, { token: ana });
    check('A3', 'Desistir de novo → 409', r.status === 409 && r.json.code === 'INVALID_STATE', r.text);

    // conflito na hora de aceitar
    r = await api('POST', '/api/v1/patient/appointments/201/reschedule-requests', { token: ana, body: { requested_scheduled_at: Y } });
    const req4 = r.json.request;
    const hoursToY = (Date.parse(Y.replace(' ', 'T') + '-03:00') - Date.now()) / 3600000;
    appt(213, 2, 1, 1, 'scheduled', hoursToY, 'follow_up');   // a agenda marcou o Bruno nesse horário
    d = await decide(equipe, req4.id, 'aceitar');
    check('A3', 'Horário ocupado quando a equipe aceita: erro claro, consulta e pedido intactos', /Horário ocupado na agenda/.test(d.page) && one("SELECT scheduled_at FROM appointments WHERE id = 201") === X && one(`SELECT status FROM appointment_change_requests WHERE id = ${req4.id}`) === 'pending', d.page.match(/change-request-flash"[^>]*>([^<]*)/));
    // vencimento
    fx(['age-request', req4.id]);
    page = (await equipe.req('GET', '/agenda/pedidos-remarcacao')).text;
    check('A3', 'Pedido cujo horário passou some da fila e fica "expirado"', one(`SELECT status FROM appointment_change_requests WHERE id = ${req4.id}`) === 'expired' && /Nenhum pedido aguardando resposta/.test(page));
    fx(['del', 213]);

    // cancelar a consulta encerra pedido pendente
    r = await api('POST', '/api/v1/patient/appointments/201/reschedule-requests', { token: ana, body: { requested_scheduled_at: Y } });
    const req5 = r.json.request;
    r = await api('POST', '/api/v1/patient/appointments/201/cancel', { token: ana, body: {} });
    check('A3', 'Cancelar a consulta encerra o pedido pendente', r.status === 200 && one(`SELECT status FROM appointment_change_requests WHERE id = ${req5.id}`) === 'cancelled', r.text);

    // rota antiga /reschedule: agora pedido (202), não move
    r = await api('POST', '/api/v1/patient/appointments/209/reschedule', { token: ana, body: { new_scheduled_at: (await api('GET', '/api/v1/patient/appointments/209/reschedule-options', { token: ana })).json.days.flatMap((x) => x.slots)[1].scheduled_at } });
    check('A3', 'Rota antiga /reschedule registra pedido (202) e não move a consulta', r.status === 202 && r.json.request.status === 'pending' && one("SELECT scheduled_at FROM appointments WHERE id = 209") === s209, r.text);

    } catch (e) { check('erro', 'Seção sem exceção', false, e.stack.split('\n').slice(0, 2).join(' ')); }
    try {
    // ------------------------------------------------------------------ portal web (cookie) e CSRF
    // Em produção o cookie HttpOnly só é emitido em HTTPS; o php -S é HTTP, então o teste monta o mesmo cookie
    // (o valor do cookie é o próprio token opaco da sessão da paciente).
    const webCookie = 'clyonutri_mobile=' + bruno;
    r = await api('POST', '/api/v1/patient/appointments/202/confirm', { cookie: webCookie, origin: 'https://evil.example', web: true });
    check('CSRF', 'Portal web: origem estranha → 403 e nada muda', r.status === 403 && one("SELECT status FROM appointments WHERE id = 202") === 'scheduled', r.status);
    r = await api('POST', '/api/v1/patient/appointments/202/confirm', { cookie: webCookie, origin: B });
    check('CSRF', 'Portal web: sem o cabeçalho do app → 403', r.status === 403);
    r = await api('POST', '/api/v1/patient/appointments/202/confirm', { cookie: webCookie, origin: B, web: true });
    au = sql("SELECT details FROM audit_logs WHERE action = 'APPOINTMENT_CONFIRMED_BY_PATIENT' AND resource_id = '202'")[0];
    check('M7', 'Portal web confirma (200) e a auditoria registra canal portal_web', !!webCookie && r.status === 200 && au && JSON.parse(au.details).channel === 'portal_web', [r.status, au]);

    } catch (e) { check('erro', 'Seção sem exceção', false, e.stack.split('\n').slice(0, 2).join(' ')); }
    try {
    // ------------------------------------------------------------------ Configurações (A1, B3) pela tela
    const owner = webUser(); await owner.login('nutri1@example.test');
    t = await owner.csrf('/configuracoes');
    s = await owner.req('POST', '/configuracoes', { _csrf_token: t, section: 'patient_actions', patient_can_confirm: '1', booking_cancel_min_advance_hours: '0', booking_reschedule_min_advance_hours: '4' });
    check('B3', 'Tela de Configurações recusa 0 hora para cancelar', /Valor inválido em cancel_min_advance_hours: use de 1 a 168/.test(s.text) && Number(one("SELECT patient_can_cancel FROM booking_settings WHERE clinic_id = 1")) === 1, s.text.match(/role="alert"[^>]*>[^<]*/));
    t = await owner.csrf('/configuracoes');
    s = await owner.req('POST', '/configuracoes', { _csrf_token: t, section: 'patient_actions', patient_can_confirm: '1', booking_cancel_min_advance_hours: '6', booking_reschedule_min_advance_hours: '12' });
    const bs = sql("SELECT patient_can_confirm, patient_can_cancel, patient_can_request_reschedule, patient_show_address, cancel_min_advance_hours, reschedule_min_advance_hours FROM booking_settings WHERE clinic_id = 1")[0];
    check('A1', 'Dona salva pela tela: só confirmar ligado, prazos 6 h e 12 h', Number(bs.patient_can_confirm) === 1 && Number(bs.patient_can_cancel) === 0 && Number(bs.patient_can_request_reschedule) === 0 && Number(bs.patient_show_address) === 0 && Number(bs.cancel_min_advance_hours) === 6 && Number(bs.reschedule_min_advance_hours) === 12, bs);
    check('A1', 'Alteração auditada com antes e depois', Number(one("SELECT COUNT(*) FROM audit_logs WHERE action = 'PATIENT_APPOINTMENT_ACTIONS_UPDATED'")) >= 1);
    const recepOnly = webUser(); await recepOnly.login('recepcao@example.test');
    t = await recepOnly.csrf('/configuracoes');
    s = await recepOnly.req('POST', '/configuracoes', { _csrf_token: t, section: 'patient_actions', patient_can_cancel: '1', booking_cancel_min_advance_hours: '1', booking_reschedule_min_advance_hours: '1' });
    check('A1', 'Recepção não altera as regras (a clínica é quem decide)', Number(one("SELECT patient_can_cancel FROM booking_settings WHERE clinic_id = 1")) === 0, s.text.match(/role="alert"[^>]*>[^<]*/));
    n = await home(bruno);
    check('A1', 'Card do Bruno reflete as regras salvas (cancelar e remarcar bloqueados)', n?.allowed_actions?.cancel?.allowed === false && n?.allowed_actions?.reschedule?.allowed === false);
    } catch (e) { check('erro', 'Seção sem exceção', false, e.stack.split('\n').slice(0, 2).join(' ')); }
  } catch (e) {
    check('erro', 'Execução sem exceção', false, e.stack);
  } finally {
    server.kill();
    if (useMysql) { try { mysqlAdmin(`DROP DATABASE ${mysqlDb}`); } catch { /* */ } }
    rmSync(dir, { recursive: true, force: true });
  }
  const pass = results.filter((x) => x.ok).length;
  console.log(`\nNI2-137 (${useMysql ? 'MySQL' : 'SQLite'}): ${pass} PASS, ${results.length - pass} FAIL`);
  if (process.env.RESULTS_JSON) writeFileSync(process.env.RESULTS_JSON, JSON.stringify({ driver: useMysql ? 'mysql' : 'sqlite', pass, fail: results.length - pass, results }, null, 1));
  if (pass !== results.length) process.exitCode = 1;
})();
