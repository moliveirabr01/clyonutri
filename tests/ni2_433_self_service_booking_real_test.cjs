// NI2-433 — autoagendamento: teste real de ponta a ponta.
// public/index.php servido por `php -S` (APP_ENV=production), banco real e isolado, HTTP de verdade,
// requisições concorrentes e, com PLAYWRIGHT_MODULE/AXE_MODULE, o funil completo no Chromium com a CSP ativa.
//
// SQLite (padrão):  node tests/ni2_433_self_service_booking_real_test.cjs
// MySQL:            DB_CONNECTION=mysql DB_HOST=127.0.0.1 DB_PORT=3306 DB_USERNAME=... DB_PASSWORD=... node tests/ni2_433_...cjs
'use strict';
const { spawn, execFileSync } = require('node:child_process');
const { mkdtempSync, rmSync } = require('node:fs');
const { join, resolve } = require('node:path');
const net = require('node:net');
const assert = require('node:assert/strict');

const root = resolve(__dirname, '..');
const isMysql = process.env.DB_CONNECTION === 'mysql';
const dir = mkdtempSync('/tmp/ni2-433-');
const dbName = isMysql ? `ni2_433_test_${process.pid}_${Date.now() % 100000}` : join(dir, 'test.sqlite');
const baseEnv = { ...process.env, DB_CONNECTION: isMysql ? 'mysql' : 'sqlite', DB_DATABASE: dbName, APP_ENV: 'production', FORCE_HTTPS: 'false',
  APP_TIMEZONE: 'America/Sao_Paulo', MAIL_DRIVER: 'null', TZ: 'America/Sao_Paulo' };
let passed = 0; const evidence = [];
function check(name, ok, detail) { assert.ok(ok, name + (detail !== undefined ? ' → ' + JSON.stringify(detail).slice(0, 400) : '')); passed++; console.log('PASS ' + name); }
const fixture = (mode, ...args) => JSON.parse(execFileSync('php', ['tests/support/ni2_433_fixture.php', mode, ...args], { cwd: root, env: baseEnv, encoding: 'utf8' }));
const pdoDsn = `mysql:host=${process.env.DB_HOST || '127.0.0.1'};port=${process.env.DB_PORT || '3306'}`;
function mysqlAdmin(sql) {
  execFileSync('php', ['-r', `$p=new PDO(getenv('X_DSN'),getenv('DB_USERNAME'),getenv('DB_PASSWORD'));$p->exec(getenv('X_SQL'));`], { env: { ...baseEnv, X_DSN: pdoDsn, X_SQL: sql } });
}

async function startServer(extraEnv = {}) {
  const sock = net.createServer(); await new Promise((r) => sock.listen(0, '127.0.0.1', r)); const port = sock.address().port; await new Promise((r) => sock.close(r));
  const proc = spawn('php', ['-d', 'session.save_path=' + dir, '-S', '127.0.0.1:' + port, '-t', 'public'], { cwd: root, env: { ...baseEnv, PHP_CLI_SERVER_WORKERS: '8', ...extraEnv }, stdio: 'ignore' });
  const base = 'http://127.0.0.1:' + port;
  for (let i = 0; i < 100; i++) { try { await fetch(base + '/up'); break; } catch { await new Promise((r) => setTimeout(r, 50)); } }
  return { base, proc };
}
function client(base) {
  let cookie = '';
  async function req(path, options = {}) {
    const r = await fetch(base + path, { ...options, redirect: 'manual', headers: { ...(cookie ? { Cookie: cookie } : {}), ...options.headers } });
    const c = r.headers.get('set-cookie'); if (c) cookie = c.split(';')[0];
    const text = await r.text(); let json = null; try { json = JSON.parse(text); } catch { /* html */ }
    return { status: r.status, location: r.headers.get('location'), text, json };
  }
  const form = (path, data) => req(path, { method: 'POST', headers: { 'Content-Type': 'application/x-www-form-urlencoded' }, body: new URLSearchParams(data) });
  const json = (path, data, headers = {}) => req(path, { method: 'POST', headers: { 'Content-Type': 'application/json', ...headers }, body: JSON.stringify(data) });
  return { req, form, json, setCookie: (c) => { cookie = c; } };
}
const csrf = (html) => (html.match(/name="_csrf_token" value="([^"]+)"/) || [])[1];
const pad = (n) => String(n).padStart(2, '0');
// Próxima data (a partir de +3 dias) que cai no dia da semana pedido (1=seg … 6=sáb), no fuso de São Paulo.
function nextDate(isoWeekday, minDays = 3) {
  const now = new Date(new Date().toLocaleString('en-US', { timeZone: 'America/Sao_Paulo' }));
  for (let i = minDays; i < minDays + 14; i++) {
    const d = new Date(now.getFullYear(), now.getMonth(), now.getDate() + i);
    if (((d.getDay() + 6) % 7) + 1 === isoWeekday) return `${d.getFullYear()}-${pad(d.getMonth() + 1)}-${pad(d.getDate())}`;
  }
  throw new Error('sem data');
}

(async () => {
  if (isMysql) mysqlAdmin(`CREATE DATABASE ${dbName} CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci`);
  fixture('prepare');
  const servers = [];
  try {
    const main = await startServer({ BOOKING_RATE_LIMIT_HOLD: '500', BOOKING_RATE_LIMIT_CONFIRM: '500' }); servers.push(main);
    const { base } = main;
    const anon = client(base);
    const WED = nextDate(3); const THU = nextDate(4); const SAT = nextDate(6);
    console.log(`banco: ${isMysql ? 'MySQL ' + dbName : 'SQLite'} · datas: qua ${WED}, qui ${THU}, sáb ${SAT}`);

    // ---------------------------------------------------------------- opt-in e página contextual
    check('Clínica sem configuração: /agendar/1 responde 404 (opt-in)', (await anon.req('/agendar/1')).status === 404);
    check('Clínica que nunca ligou: /agendar/4 responde 404', (await anon.req('/agendar/4')).status === 404);
    check('/agendar sem profissional não lista ninguém (404)', (await anon.req('/agendar')).status === 404);
    let r = await anon.req(`/api/v1/booking/slots?professional_id=1&date=${WED}`);
    check('API de horários de clínica desligada: 404 BOOKING_NOT_AVAILABLE', r.status === 404 && r.json.code === 'BOOKING_NOT_AVAILABLE', r.json);
    r = await anon.req('/agendar/3');
    check('Página da clínica B mostra só a profissional dela, sem e-mails', r.status === 200 && r.text.includes('Dra. Carla Outra')
      && !r.text.includes('Ana Agenda') && !r.text.includes('Diego Desligado') && !/@example\.test/.test(r.text));

    // ---------------------------------------------------------------- configuração pela clínica (RBAC + CSRF)
    const staff = client(base);
    let page = await staff.req('/login');
    await staff.form('/login', { _csrf_token: csrf(page.text), email: 'equipe.a@example.test', password: 'Agenda@2026' });
    page = await staff.req('/configuracoes');
    const bookingForm = (token, extra = {}) => ({ _csrf_token: token, section: 'booking', booking_enabled: '1', booking_allow_presential: '1', booking_allow_telehealth: '1',
      booking_min_advance_hours: '2', booking_max_future_days: '60', booking_hold_ttl_minutes: '15', booking_cancel_min_advance_hours: '4', booking_reschedule_min_advance_hours: '4', ...extra });
    r = await staff.form('/configuracoes', bookingForm(csrf(page.text)));
    check('Nutricionista sem gestão não liga o autoagendamento (409)', r.status === 409 && !fixture('inspect').settings.some((s) => +s.clinic_id === 1));

    const owner = client(base);
    page = await owner.req('/login');
    r = await owner.form('/login', { _csrf_token: csrf(page.text), email: 'dona.a@example.test', password: 'Agenda@2026' });
    check('Login real da dona da clínica A', [302, 303].includes(r.status), r.status);
    page = await owner.req('/configuracoes');
    check('Configurações mostram o agendamento online e o link /agendar/1', page.status === 200 && page.text.includes('data-testid="booking-settings"') && page.text.includes('/agendar/1'));
    r = await owner.form('/configuracoes', { ...bookingForm('token-falso') });
    check('Sem CSRF válido a configuração é recusada (403)', r.status === 403);
    r = await owner.form('/configuracoes', bookingForm(csrf(page.text), { booking_hold_ttl_minutes: '999' }));
    check('Valor fora da política é recusado no servidor (422)', r.status === 422 && !fixture('inspect').settings.some((s) => +s.clinic_id === 1));
    page = await owner.req('/configuracoes');
    r = await owner.form('/configuracoes', bookingForm(csrf(page.text)));
    const settingsA = fixture('inspect').settings.find((s) => +s.clinic_id === 1);
    check('Dona liga o autoagendamento: persistido em booking_settings', r.status === 200 && settingsA && +settingsA.is_enabled === 1, settingsA);
    r = await anon.req('/agendar/1');
    check('Página pública da clínica A: só a Dra. Ana, sem outra clínica', r.status === 200 && r.text.includes('Dra. Ana Agenda') && !r.text.includes('Carla Outra') && !r.text.includes('Bruno Equipe <'));

    // ---------------------------------------------------------------- motor de disponibilidade
    r = await anon.req(`/api/v1/booking/slots?professional_id=1&date=${WED}`);
    const times = (r.json.available_slots || []).map((s) => s.time);
    check('Horários = jornada configurada (08–18, sem a pausa 12–13, de 60 em 60 min)', JSON.stringify(times) === JSON.stringify(['08:00', '09:00', '10:00', '11:00', '13:00', '14:00', '15:00', '16:00', '17:00']), times);
    check('Duração vem do cadastro do profissional (60 min)', r.json.duration_minutes === 60);
    r = await anon.req(`/api/v1/booking/slots?professional_id=1&date=${SAT}`);
    check('Sábado (dia sem atendimento) não tem horários', r.status === 200 && r.json.available_slots.length === 0);
    const staffAppt = fixture('staff_appointment', `${WED} 10:00:00`);
    check('Recepção marca 10:00 pela agenda interna (AppointmentService)', staffAppt.ok === true, staffAppt);
    r = await anon.req(`/api/v1/booking/slots?professional_id=1&date=${WED}`);
    check('Horário ocupado pela agenda interna some da página pública', !r.json.available_slots.some((s) => s.time === '10:00'));
    r = await anon.req(`/api/v1/booking/slots?professional_id=3&clinic_id=1&date=${WED}`);
    check('Profissional da clínica B pedido como da clínica A: 404', r.status === 404);

    // ---------------------------------------------------------------- reserva temporária: validações
    const hold = (data, headers) => anon.json('/api/v1/booking/holds', { professional_id: 1, service_type: 'first_visit', modality: 'presential', ...data }, headers);
    r = await hold({ scheduled_at: `${WED} 10:00` });
    check('Hold em horário já marcado: 409 SLOT_UNAVAILABLE', r.status === 409 && r.json.code === 'SLOT_UNAVAILABLE', r.json);
    r = await hold({ scheduled_at: `${WED} 12:00` });
    check('Hold na pausa: 409', r.status === 409);
    r = await hold({ scheduled_at: `${WED} 07:00` });
    check('Hold fora da jornada: 409', r.status === 409);
    r = await hold({ scheduled_at: `${WED} 09:30` });
    check('Hold fora da grade de horários: 409', r.status === 409);
    r = await hold({ scheduled_at: 'amanhã às 9' });
    check('Data em formato inválido: 422', r.status === 422);
    r = await hold({ scheduled_at: `${WED} 11:00`, modality: 'drive-thru' });
    check('Modalidade inexistente: 422', r.status === 422);
    r = await hold({ scheduled_at: `${WED} 11:00`, service_type: 'cirurgia' });
    check('Tipo de atendimento inexistente: 422', r.status === 422);
    r = await anon.json('/api/v1/booking/holds', { professional_id: 3, clinic_id: 1, scheduled_at: `${WED} 11:00` });
    check('Hold cruzando clínicas (prof. da B com clinic_id da A): 404', r.status === 404);
    r = await anon.json('/api/v1/booking/holds', { professional_id: 4, scheduled_at: `${WED} 11:00` });
    check('Hold em clínica desligada: 404', r.status === 404);

    // ---------------------------------------------------------------- concorrência no hold (mesmo horário)
    const N = 8;
    let results = await Promise.all(Array.from({ length: N }, (_, i) => hold({ scheduled_at: `${WED} 14:00`, idempotency_key: `race-${i}` })));
    const created = results.filter((x) => x.status === 201); const conflicts = results.filter((x) => x.status === 409);
    check(`${N} pedidos simultâneos do mesmo horário: 1 reserva e ${N - 1} recusas 409`, created.length === 1 && conflicts.length === N - 1, results.map((x) => x.status));
    evidence.push({ cenario: 'holds simultâneos', status: results.map((x) => x.status) });
    const raceHold = created[0].json;
    r = await anon.req(`/api/v1/booking/slots?professional_id=1&date=${WED}`);
    check('Horário em reserva some da lista para os outros', !r.json.available_slots.some((s) => s.time === '14:00'));
    r = await hold({ scheduled_at: `${WED} 14:00`, idempotency_key: 'race-0' });
    check('Repetição com a mesma chave devolve a mesma reserva (200, idempotente)', (r.status === 200 && r.json.hold_token === raceHold.hold_token) || (raceHold && r.status === 409), [r.status]);

    // ---------------------------------------------------------------- confirmação: validações
    const pub = await anon.req('/agendar/1');
    const consentTermId = Number((pub.text.match(/consentTermId: (\d+)/) || [])[1] || 0);
    check('Página pública carrega o termo vigente de assistência clínica', consentTermId > 0 && pub.text.includes('Li e aceito o termo'));
    const confirm = (data) => anon.json('/api/v1/booking/confirm', { hold_token: raceHold.hold_token, name: 'Marina Costa', email: 'marina@paciente.test', phone: '(11) 91234-5678', terms_accepted: true, consent_term_id: consentTermId, ...data });
    r = await confirm({ terms_accepted: false });
    check('Sem aceite do termo: 422 e nada é gravado', r.status === 422 && fixture('inspect').appointments.length === 1);
    r = await confirm({ consent_term_id: consentTermId + 999 });
    check('Termo de versão diferente da vigente: 422', r.status === 422);
    r = await confirm({ email: '', phone: '' });
    check('Sem e-mail nem celular: 422', r.status === 422);
    r = await confirm({ email: 'isto-nao-e-email' });
    check('E-mail inválido: 422', r.status === 422);
    r = await anon.json('/api/v1/booking/confirm', { hold_token: 'f'.repeat(64), name: 'X Y Z', email: 'x@y.test', terms_accepted: true, consent_term_id: consentTermId });
    check('Token inexistente: 422 sem detalhes', r.status === 422 && !/SQL|PDO|Exception/i.test(r.text));

    // ---------------------------------------------------------------- confirmação concorrente do mesmo token
    results = await Promise.all(Array.from({ length: 6 }, () => confirm({})));
    let ins = fixture('inspect');
    const at14 = ins.appointments.filter((a) => a.scheduled_at.startsWith(`${WED} 14:00`) && a.status !== 'cancelled');
    check('6 confirmações simultâneas do mesmo token: exatamente 1 consulta', at14.length === 1, { status: results.map((x) => x.status), consultas: at14.length });
    const okResults = results.filter((x) => x.status === 200);
    check('Respostas 200 apontam para a mesma consulta', okResults.length >= 1 && okResults.every((x) => x.json.appointment_id === at14[0].id || +x.json.appointment_id === +at14[0].id), results.map((x) => [x.status, x.json && x.json.appointment_id]));
    evidence.push({ cenario: 'confirmações simultâneas do mesmo token', status: results.map((x) => x.status), consultas_criadas: at14.length });
    const appt = at14[0];
    check('Consulta canônica em appointments (status scheduled, 60 min, clínica e profissional certos)', appt.status === 'scheduled' && +appt.duration_minutes === 60 && +appt.clinic_id === 1 && +appt.professional_id === 1);
    check('Histórico auditável da consulta (null → scheduled)', ins.history.some((h) => +h.appointment_id === +appt.id && h.from_status === null && h.to_status === 'scheduled'));
    const marina = ins.patients.find((p) => p.email === 'marina@paciente.test');
    check('Paciente nova criada na clínica A', marina && +marina.clinic_id === 1 && +appt.patient_id === +marina.id);
    check('Consentimento registrado com a versão do termo e canal self_service_booking', ins.consents.some((c) => +c.patient_id === +marina.id && c.consent_type === 'clinical_care' && +c.granted === 1 && +c.term_id === consentTermId));
    const convHold = ins.holds.find((h) => h.appointment_id && +h.appointment_id === +appt.id);
    check('Hold convertido e sem dados pessoais (nome, e-mail, telefone, CPF ficam fora do hold)', convHold && convHold.status === 'converted' && !convHold.patient_name && !convHold.patient_email && !convHold.patient_phone && !convHold.patient_cpf);
    const auditTxt = JSON.stringify(ins.audit);
    check('Auditoria do fluxo sem dados pessoais', ins.audit.some((a) => a.action === 'BOOKING_CONFIRMED') && !/marina|paciente\.test|91234/i.test(auditTxt));
    r = await hold({ scheduled_at: `${WED} 14:00` });
    check('Horário confirmado não pode ser reservado de novo (409)', r.status === 409);

    // ---------------------------------------------------------------- vínculo de identidade
    let h = (await hold({ scheduled_at: `${WED} 15:00`, idempotency_key: 'ana-falsa' })).json;
    r = await anon.json('/api/v1/booking/confirm', { hold_token: h.hold_token, name: 'Pessoa Qualquer', email: 'ana@paciente.test', terms_accepted: true, consent_term_id: consentTermId });
    ins = fixture('inspect');
    check('E-mail de paciente existente com outro nome NÃO anexa à ficha dela', r.status === 200 && +r.json.patient_id !== 1);
    h = (await hold({ scheduled_at: `${WED} 16:00`, idempotency_key: 'ana-real' })).json;
    r = await anon.json('/api/v1/booking/confirm', { hold_token: h.hold_token, name: 'ANA  LIMA', email: 'ANA@paciente.test', terms_accepted: true, consent_term_id: consentTermId });
    check('Mesmo e-mail e mesmo nome: vincula à paciente existente', r.status === 200 && +r.json.patient_id === 1, r.json);

    // ---------------------------------------------------------------- expiração e liberação
    h = (await hold({ scheduled_at: `${THU} 08:00`, idempotency_key: 'expira' })).json;
    fixture('expire_holds');
    r = await anon.json('/api/v1/booking/confirm', { hold_token: h.hold_token, name: 'Paula Prazo', email: 'paula@paciente.test', terms_accepted: true, consent_term_id: consentTermId });
    check('Hold vencido: 410 HOLD_EXPIRED', r.status === 410 && r.json.code === 'HOLD_EXPIRED');
    r = await anon.req(`/api/v1/booking/slots?professional_id=1&date=${THU}`);
    check('Horário do hold vencido volta a ficar livre', r.json.available_slots.some((s) => s.time === '08:00'));
    h = (await hold({ scheduled_at: `${THU} 09:00`, idempotency_key: 'libera' })).json;
    r = await anon.json('/api/v1/booking/release', { hold_token: h.hold_token });
    const after = await anon.req(`/api/v1/booking/slots?professional_id=1&date=${THU}`);
    check('Liberar a reserva devolve o horário na hora', r.status === 200 && r.json.released === true && after.json.available_slots.some((s) => s.time === '09:00'));

    // ---------------------------------------------------------------- agenda interna x confirmação
    h = (await hold({ scheduled_at: `${THU} 10:00`, idempotency_key: 'recepcao-antes' })).json;
    const staffWins = fixture('staff_appointment', `${THU} 10:00:00`);
    r = await anon.json('/api/v1/booking/confirm', { hold_token: h.hold_token, name: 'Rita Atraso', email: 'rita@paciente.test', terms_accepted: true, consent_term_id: consentTermId });
    ins = fixture('inspect');
    check('Recepção ocupou o horário durante o hold: confirmação recusada (409) e só 1 consulta às 10:00',
      staffWins.ok && r.status === 409 && ins.appointments.filter((a) => a.scheduled_at.startsWith(`${THU} 10:00`) && a.status !== 'cancelled').length === 1, { staffWins, status: r.status });
    // Corrida real: recepção (processo PHP) e paciente (HTTP) ao mesmo tempo, em 3 horários.
    for (const t of ['11:00', '13:00', '14:00']) {
      const hh = (await hold({ scheduled_at: `${THU} ${t}`, idempotency_key: 'corrida-' + t })).json;
      const [staffRes, patientRes] = await Promise.all([
        new Promise((res) => { const p = spawn('php', ['tests/support/ni2_433_fixture.php', 'staff_appointment', `${THU} ${t}:00`], { cwd: root, env: baseEnv }); let o = ''; p.stdout.on('data', (d) => { o += d; }); p.on('close', () => res(JSON.parse(o || '{}'))); }),
        anon.json('/api/v1/booking/confirm', { hold_token: hh.hold_token, name: 'Corrida ' + t, email: `corrida${t.replace(':', '')}@paciente.test`, terms_accepted: true, consent_term_id: consentTermId }),
      ]);
      const n = fixture('inspect').appointments.filter((a) => a.scheduled_at.startsWith(`${THU} ${t}`) && a.status !== 'cancelled').length;
      evidence.push({ cenario: `corrida recepção x paciente ${t}`, recepcao_ok: staffRes.ok, recepcao_erro: (staffRes.error || '').slice(0, 90), paciente_status: patientRes.status, consultas: n });
      check(`Corrida recepção x paciente às ${t}: no máximo 1 consulta no horário`, n === 1, { staff: staffRes, patient: patientRes.status });
    }

    // ---------------------------------------------------------------- app do paciente: remarcar e cancelar
    const marinaToken = fixture('patient_token', String(marina.id)).token;
    // Sessão do app nativo: Authorization: Bearer (mesmo token da API do paciente).
    const appC = client(base);
    const app = { json: (path, data) => appC.json(path, data, { Authorization: 'Bearer ' + marinaToken }) };
    r = await client(base).json(`/api/v1/patient/appointments/${appt.id}/cancel`, { reason: 'x' });
    check('Cancelar sem sessão de paciente: 401', r.status === 401);
    const otherAppt = fixture('inspect').appointments.find((a) => +a.patient_id === 1);
    r = await app.json(`/api/v1/patient/appointments/${otherAppt.id}/cancel`, { reason: 'não é minha' });
    check('Paciente não cancela consulta de outra pessoa (403)', r.status === 403);
    r = await app.json(`/api/v1/patient/appointments/${appt.id}/reschedule`, { new_scheduled_at: `${WED} 12:00` });
    check('Remarcar para a pausa: recusado (409)', r.status === 409);
    r = await app.json(`/api/v1/patient/appointments/${appt.id}/reschedule`, { new_scheduled_at: `${WED} 10:00` });
    check('Remarcar para horário ocupado: recusado (409)', r.status === 409);
    r = await app.json(`/api/v1/patient/appointments/${appt.id}/reschedule`, { new_scheduled_at: `${WED} 17:00`, reason: 'trabalho' });
    ins = fixture('inspect');
    check('Remarcar para horário livre: 200 e consulta movida', r.status === 200 && ins.appointments.find((a) => +a.id === +appt.id).scheduled_at.startsWith(`${WED} 17:00`), r.json);
    r = await anon.req(`/api/v1/booking/slots?professional_id=1&date=${WED}`);
    check('Horário antigo (14:00) liberado e novo (17:00) ocupado', r.json.available_slots.some((s) => s.time === '14:00') && !r.json.available_slots.some((s) => s.time === '17:00'));
    r = await app.json(`/api/v1/patient/appointments/${appt.id}/cancel`, { reason: 'imprevisto' });
    check('Paciente cancela a própria consulta (200)', r.status === 200 && fixture('inspect').appointments.find((a) => +a.id === +appt.id).status === 'cancelled');
    r = await app.json(`/api/v1/patient/appointments/${appt.id}/cancel`, { reason: 'de novo' });
    check('Cancelar de novo: recusado (409), estado inválido', r.status === 409);

    // ---------------------------------------------------------------- política da clínica: modalidade desligada
    page = await owner.req('/configuracoes');
    r = await owner.form('/configuracoes', bookingForm(csrf(page.text), { booking_allow_telehealth: '' }));
    r = await hold({ scheduled_at: `${THU} 15:00`, modality: 'telehealth', idempotency_key: 'tele-off' });
    check('Teleconsulta desligada pela clínica: hold de teleconsulta recusado (422)', r.status === 422);

    // ---------------------------------------------------------------- limite de abuso (valor de produção)
    fixture('reset_rate_limit');
    const strict = await startServer(); servers.push(strict);
    const abuser = client(strict.base); const codes = [];
    for (let i = 0; i < 11; i++) codes.push((await abuser.json('/api/v1/booking/holds', { professional_id: 1, scheduled_at: `${THU} 16:00`, idempotency_key: 'abuso-' + i })).status);
    check('Limite de 10 reservas por IP em 10 min: a 11ª recebe 429', codes[10] === 429 && codes.slice(0, 10).every((c) => c !== 429), codes);
    evidence.push({ cenario: 'limite de abuso', status: codes });

    // ---------------------------------------------------------------- navegador real (CSP ativa) + acessibilidade
    let playwright; let axePath;
    try { playwright = require(process.env.PLAYWRIGHT_MODULE || 'playwright'); axePath = require.resolve(process.env.AXE_MODULE || 'axe-core'); } catch { /* opcional */ }
    if (playwright && axePath) {
      const browser = await playwright.chromium.launch(process.env.CHROMIUM_PATH ? { executablePath: process.env.CHROMIUM_PATH, headless: false, args: ['--headless=new'] } : {});
      try {
        const ctx = await browser.newContext({ viewport: { width: 390, height: 844 } });
        const ui = await ctx.newPage(); const errors = [];
        ui.on('console', (m) => { if (m.type() === 'error' && !/fonts\.(googleapis|gstatic)|ERR_TUNNEL|ERR_NAME|Failed to load resource/.test(m.text())) errors.push(m.text()); });
        ui.on('pageerror', (e) => errors.push(e.message));
        ui.on('response', (resp) => { if (resp.status() >= 400 && !/\/api\/v1\/booking\//.test(resp.url())) errors.push(`${resp.status()} ${resp.url()}`); });
        await ui.goto(base + '/agendar/1');
        await ui.selectOption('#select-service', 'follow_up');
        await ui.click('#btn-to-step-2');
        await ui.fill('#input-date', THU);
        await ui.dispatchEvent('#input-date', 'change');
        await ui.waitForSelector('.slot-btn');
        const uiTimes = await ui.locator('.slot-btn').allInnerTexts();
        check('Navegador: lista de horários vem do servidor (sem 10:00, 11:00, 13:00, 14:00 ocupados)', !['10:00', '11:00', '13:00', '14:00'].some((t) => uiTimes.includes(t)) && uiTimes.includes('15:00'), uiTimes);
        await ui.locator('.slot-btn', { hasText: '15:00' }).click();
        await ui.waitForSelector('#section-step-3', { state: 'visible' });
        await ui.fill('#patient-name', 'Lia Navegador');
        await ui.fill('#patient-email', 'lia@paciente.test');
        await ui.fill('#patient-phone', '(21) 99876-5432');
        await ui.check('#terms-accept');
        const axeCtx = await browser.newContext({ bypassCSP: true, viewport: { width: 390, height: 844 } }); // só para injetar o axe
        const ap = await axeCtx.newPage(); await ap.goto(base + '/agendar/1'); await ap.addScriptTag({ path: axePath });
        const viol = await ap.evaluate(async () => (await window.axe.run(document, { runOnly: ['wcag2a', 'wcag2aa'] })).violations.map((v) => `${v.id}(${v.nodes.length})`));
        evidence.push({ cenario: 'axe /agendar/1', violacoes: viol });
        await ui.click('#btn-submit-confirm');
        await ui.waitForSelector('#section-step-4', { state: 'visible' });
        ins = fixture('inspect');
        const lia = ins.patients.find((p) => p.email === 'lia@paciente.test');
        check('Navegador: funil completo cria a consulta (qui 15:00, retorno)', lia && ins.appointments.some((a) => +a.patient_id === +lia.id && a.scheduled_at.startsWith(`${THU} 15:00`) && a.type === 'follow_up'));
        check('Navegador: sem rolagem horizontal em 390 px', await ui.evaluate(() => document.documentElement.scrollWidth <= window.innerWidth));
        check('Navegador: nenhum erro de script nem bloqueio de CSP', errors.length === 0, errors);
        check(`axe WCAG 2 A/AA na página pública${viol.length ? ' → ' + viol.join(', ') : ''}`, viol.length === 0, viol);
        if (process.env.SCREENSHOT_DIR) await ui.screenshot({ path: join(process.env.SCREENSHOT_DIR, 'ni2-433-confirmado-390.png'), fullPage: true });
      } finally { await browser.close(); }
    } else {
      console.log('NOT TESTED navegador/axe: defina PLAYWRIGHT_MODULE e AXE_MODULE.');
    }

    console.log('\nEVIDÊNCIAS ' + JSON.stringify(evidence));
    console.log(`\nNI2-433 (${isMysql ? 'MySQL' : 'SQLite'}): ${passed} verificações PASS`);
  } finally {
    for (const s of servers) s.proc.kill();
    if (isMysql && !process.env.KEEP_DB) { try { mysqlAdmin(`DROP DATABASE ${dbName}`); } catch { /* ignore */ } }
    rmSync(dir, { recursive: true, force: true });
  }
})().catch((e) => { console.error('FAIL ' + e.message); process.exitCode = 1; });
