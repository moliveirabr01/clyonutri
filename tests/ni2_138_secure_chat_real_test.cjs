// NI2-138 — canal seguro de mensagens paciente ↔ nutricionista: teste real por HTTP.
// public/index.php em `php -S` (APP_ENV=production, 4 workers para requisições paralelas), banco isolado (SQLite ou MySQL),
// dados fictícios (tests/support/ni2_138_fixture.php), logins reais (paciente pela API; equipe pelo formulário com CSRF).
// E-mail pelo driver de log (MAIL_DRIVER=log): confere o que sairia, sem servidor SMTP.
//   node tests/ni2_138_secure_chat_real_test.cjs   [RESULTS_JSON=…]
//   DB_CONNECTION=mysql DB_HOST=… DB_PORT=… DB_USERNAME=… DB_PASSWORD=… node tests/ni2_138_secure_chat_real_test.cjs
'use strict';
const { spawn, execFileSync } = require('node:child_process');
const { mkdtempSync, rmSync, writeFileSync, readFileSync, existsSync, utimesSync, readdirSync } = require('node:fs');
const { join, resolve } = require('node:path');
const crypto = require('node:crypto');
const net = require('node:net');

const root = resolve(process.env.APP_ROOT || join(__dirname, '..'));
const dir = mkdtempSync('/tmp/ni2-138-');
const useMysql = process.env.DB_CONNECTION === 'mysql';
const mysqlDb = 'ni2_138_test_' + Math.random().toString(36).slice(2, 10);
const APP_KEY = 'ni2-138-chave-de-teste-ficticia-0001';
const mailLog = join(dir, 'mail.log');
const storage = join(root, 'storage/app/chat_attachments');
const env = { ...process.env, DB_CONNECTION: useMysql ? 'mysql' : 'sqlite', DB_DATABASE: useMysql ? mysqlDb : join(dir, 'test.sqlite'), NI2_FIXTURE_DIR: dir,
  APP_ENV: 'production', FORCE_HTTPS: 'false', APP_TIMEZONE: 'America/Sao_Paulo', TZ: 'America/Sao_Paulo', MAIL_DRIVER: 'log', MAIL_LOG_PATH: mailLog,
  APP_KEY, APP_URL: 'http://app.example.test' };
const mysqlAdmin = (sqlText) => execFileSync('php', ['-r', '$p=new PDO("mysql:host=".getenv("DB_HOST").";port=".getenv("DB_PORT"),getenv("DB_USERNAME"),getenv("DB_PASSWORD"));$p->exec($argv[1]);', sqlText], { env });
const results = [];
function check(crit, name, ok, detail) {
  results.push({ crit, name, ok: !!ok, detail: ok ? null : (detail === undefined ? null : detail) });
  console.log(`${ok ? 'PASS' : 'FAIL'} [${crit}] ${name}${!ok && detail !== undefined ? ' → ' + JSON.stringify(detail).slice(0, 500) : ''}`);
}
const fx = (...args) => execFileSync('php', [join(root, 'tests/support/ni2_138_fixture.php'), ...args.map(String)], { cwd: root, env, encoding: 'utf8', stdio: ['ignore', 'pipe', 'pipe'] });
const sql = (q) => JSON.parse(fx('sql', q));
const one = (q) => { const r = sql(q)[0]; return r ? Object.values(r)[0] : null; };
const PNG = Buffer.from('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNk+M9QDwADhgGAWjR9awAAAABJRU5ErkJggg==', 'base64');
const PDF = Buffer.from('%PDF-1.4\n1 0 obj<</Type/Catalog/Pages 2 0 R>>endobj 2 0 obj<</Type/Pages/Kids[3 0 R]/Count 1>>endobj 3 0 obj<</Type/Page/Parent 2 0 R/MediaBox[0 0 10 10]>>endobj\ntrailer<</Root 1 0 R>>\n%%EOF\n');
const MSG_KEYS = ['attachment', 'conversation_id', 'created_at', 'delivered_at', 'has_attachment', 'id', 'message', 'read_at', 'sender_type', 'status'];
const XSS = '<img src=x onerror="window.__xss=1"><a href="https://exemplo.invalid/remarcar">Remarcar aqui</a>';

(async () => {
  if (useMysql) mysqlAdmin(`CREATE DATABASE ${mysqlDb} CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci`);
  fx('prepare');
  const filesBefore = new Set(existsSync(storage) ? readdirSync(storage) : []);
  const sock = net.createServer(); await new Promise((r) => sock.listen(0, '127.0.0.1', r)); const port = sock.address().port; await new Promise((r) => sock.close(r));
  const server = spawn('php', ['-d', 'display_errors=0', '-d', 'session.save_path=' + dir, '-d', 'upload_max_filesize=20M', '-d', 'post_max_size=25M', '-S', '127.0.0.1:' + port, '-t', 'public'],
    { cwd: root, env: { ...env, PHP_CLI_SERVER_WORKERS: '4' }, stdio: 'ignore' });
  const B = 'http://127.0.0.1:' + port;
  for (let i = 0; i < 100; i++) { try { await fetch(B + '/up'); break; } catch { await new Promise((r) => setTimeout(r, 50)); } }
  const api = async (method, path, { token, body, form } = {}) => {
    const h = { Accept: 'application/json' };
    if (token) h.Authorization = 'Bearer ' + token;
    if (body !== undefined) h['Content-Type'] = 'application/json';
    const res = await fetch(B + path, { method, headers: h, body: form ?? (body !== undefined ? JSON.stringify(body) : undefined) });
    const buf = Buffer.from(await res.arrayBuffer()); const text = buf.toString('utf8');
    let json = null; try { json = JSON.parse(text); } catch { /* */ }
    return { status: res.status, text, json, buf, headers: res.headers };
  };
  const webUser = () => {
    let cookie = '';
    const req = async (method, path, form, accept) => {
      const res = await fetch(B + path, { method, redirect: 'manual', headers: { Cookie: cookie, ...(accept ? { Accept: accept } : {}), ...(form ? { 'Content-Type': 'application/x-www-form-urlencoded' } : {}) }, body: form ? new URLSearchParams(form).toString() : undefined });
      const set = res.headers.getSetCookie ? res.headers.getSetCookie() : [];
      for (const c of set) { const kv = c.split(';')[0]; if (kv.startsWith('PHPSESSID=')) cookie = kv; }
      const buf = Buffer.from(await res.arrayBuffer());
      return { status: res.status, location: res.headers.get('location') || '', text: buf.toString('utf8'), buf, headers: res.headers };
    };
    const token = async (path, name = '_csrf_token') => { const r = await req('GET', path); const m = r.text.match(new RegExp(`name="${name}" value="([^"]+)"`)); return m ? m[1] : ''; };
    const login = async (email) => { const t = await token('/login'); return req('POST', '/login', { _csrf_token: t, email, password: 'Equipe@2026' }); };
    return { req, token, login };
  };
  const pngForm = (fields, name = 'prato.png', bytes = PNG, type = 'image/png') => {
    const f = new FormData();
    for (const [k, v] of Object.entries(fields)) f.append(k, v);
    f.append('attachment', new Blob([bytes], { type }), name);
    return f;
  };
  let anaConv = 0;
  try {
    const tok = async (email) => (await api('POST', '/api/v1/auth/patient-login', { body: { email, password: 'PacienteTeste@2026' } })).json.token;
    const ana = await tok('ana@example.test'), bruno = await tok('bruno@example.test'), carla = await tok('carla@example.test');
    const equipe = webUser(); await equipe.login('equipe@example.test');      // Nutri Equipe, profissional 3
    const owner = webUser(); await owner.login('nutri1@example.test');        // Nutri Um, dona, profissional 1
    const recep = webUser(); await recep.login('recepcao@example.test');
    let r;

    // ------------------------------------------------------------------ A1: vínculo ativo
    r = await api('GET', '/api/v1/patient/chat/messages', { token: ana });
    check('A1', 'Sem vínculo: nenhuma conversa, aviso NO_ACTIVE_LINK e nenhum profissional escolhido pela paciente',
      r.status === 200 && r.json.conversation_id === null && r.json.access.code === 'NO_ACTIVE_LINK' && r.json.professional === null && Number(one('SELECT COUNT(*) FROM chat_conversations WHERE patient_id = 1')) === 0, r.text.slice(0, 300));
    r = await api('POST', '/api/v1/patient/chat/send', { token: ana, body: { message: 'Oi' } });
    check('A1', 'Sem vínculo: enviar → 403 NO_ACTIVE_LINK e nada gravado', r.status === 403 && r.json.code === 'NO_ACTIVE_LINK' && Number(one('SELECT COUNT(*) FROM chat_conversations WHERE patient_id = 1')) === 0, r.text);
    r = await equipe.req('GET', '/chat/pacientes?q=ana', null, 'application/json');
    const anaRow = JSON.parse(r.text).data.find((p) => p.id === 1);
    check('A1', 'Busca de "Nova conversa" marca a paciente sem vínculo', anaRow && anaRow.has_link === false, anaRow);
    let t = await equipe.token('/chat');
    r = await equipe.req('POST', '/chat/nova', { _csrf_token: t, patient_id: '1' });
    const flash = (await equipe.req('GET', '/chat')).text.match(/data-testid="chat-flash"[^>]*>([^<]*)/);
    check('A1', 'Web: nutricionista sem vínculo não abre conversa com a paciente', r.location === '/chat' && flash && /ainda não foi liberado/.test(flash[1]) && Number(one('SELECT COUNT(*) FROM chat_conversations WHERE patient_id = 1')) === 0, [r.location, flash && flash[1]]);
    t = await equipe.token('/pacientes/1');
    r = await equipe.req('POST', '/pacientes/1/chat/vinculo', { _csrf_token: t, professional_id: '1' });
    check('A1', 'Nutricionista não libera vínculo em nome de outro profissional', Number(one("SELECT COUNT(*) FROM patient_professional_links WHERE patient_id = 1 AND status = 'active'")) === 0);
    t = await recep.token('/agenda');
    r = await recep.req('POST', '/pacientes/1/chat/vinculo', { _csrf_token: t, professional_id: '3' });
    check('A1', 'Recepção não libera o chat (403)', r.status === 403 && Number(one("SELECT COUNT(*) FROM patient_professional_links WHERE patient_id = 1")) === 0, r.status);
    t = await equipe.token('/pacientes/1');
    r = await equipe.req('POST', '/pacientes/1/chat/vinculo', { _csrf_token: t, professional_id: '3' });
    let page = (await equipe.req('GET', '/pacientes/1')).text;
    check('A1', 'Nutricionista libera o próprio vínculo no perfil da paciente (auditado)', Number(one("SELECT COUNT(*) FROM patient_professional_links WHERE patient_id = 1 AND professional_id = 3 AND status = 'active'")) === 1
      && /Chat liberado/.test(page) && Number(one("SELECT COUNT(*) FROM audit_logs WHERE action = 'CHAT_LINK_ACTIVATED'")) === 1, page.match(/data-testid="chat-link-flash"[^>]*>(.{0,120})/));
    r = await api('GET', '/api/v1/patient/chat/messages', { token: ana });
    anaConv = r.json.conversation_id;
    check('A1', 'Com vínculo: conversa com o profissional vinculado (Nutri Equipe), pode enviar', r.status === 200 && anaConv > 0 && r.json.professional.id === 3 && r.json.access.can_send === true
      && Number(one(`SELECT professional_id FROM chat_conversations WHERE id = ${anaConv}`)) === 3, r.text.slice(0, 300));
    check('M6', 'Abertura da conversa auditada', Number(one(`SELECT COUNT(*) FROM audit_logs WHERE action = 'CHAT_CONVERSATION_OPENED' AND resource_id = '${anaConv}'`)) === 1);

    // ------------------------------------------------------------------ M4: tempo de resposta e aviso de urgência
    check('M4', 'Sem configuração: "responde quando possível, sem prazo garantido" + aviso 192', /sem prazo garantido|Não há prazo garantido/i.test(r.json.notice.response_time_text) && /192/.test(r.json.notice.emergency_text) && /não é para urgências/.test(r.json.notice.emergency_text), r.json.notice);
    t = await owner.token('/configuracoes');
    let s = await owner.req('POST', '/configuracoes', { _csrf_token: t, section: 'chat', chat_response_time: 'em até 2 dias úteis', chat_retention_years: '3' });
    check('M4', 'Prazo de guarda abaixo de 5 anos é recusado', /Prazo de guarda inválido/.test(s.text) && one('SELECT COUNT(*) FROM chat_clinic_settings') == 0);
    t = await owner.token('/configuracoes');
    s = await owner.req('POST', '/configuracoes', { _csrf_token: t, section: 'chat', chat_response_time: 'em até 2 dias úteis', chat_retention_years: '20' });
    r = await api('GET', '/api/v1/patient/chat/messages', { token: ana });
    check('M4', 'Dona configura "em até 2 dias úteis": a paciente vê como estimativa, não prazo', r.json.notice.response_time === 'em até 2 dias úteis' && /costuma responder em até 2 dias úteis\. Não é um prazo garantido/.test(r.json.notice.response_time_text), r.json.notice);
    t = await recep.token('/agenda');
    s = await recep.req('POST', '/configuracoes', { _csrf_token: t, section: 'chat', chat_response_time: 'no mesmo dia útil', chat_retention_years: '20' });
    check('M4', 'Recepção não altera as regras do chat', one('SELECT response_time_label FROM chat_clinic_settings WHERE clinic_id = 1') === 'em até 2 dias úteis');
    page = (await equipe.req('GET', `/chat/${anaConv}`)).text;
    check('M4', 'Tela do profissional sem "Online" nem "criptografado"; mostra o aviso que a paciente vê', !/>\s*Online\s*</.test(page) && !/Criptografado/i.test(page) && /data-testid="chat-notice"/.test(page) && /em até 2 dias úteis/.test(page));

    // ------------------------------------------------------------------ envio, idempotência (A6), limites (M2), campos (M5)
    const K1 = 'mob_' + Date.now() + '_a1';
    const before = new Date();
    r = await api('POST', '/api/v1/patient/chat/send', { token: ana, body: { message: 'Posso trocar o arroz por batata?', idempotency_key: K1 } });
    const m1 = r.json && r.json.message;
    check('envio', 'Paciente envia → 201 com a mensagem', r.status === 201 && m1 && m1.id > 0 && m1.status === 'sent', r.text);
    check('M5', 'Mensagem com lista fechada de campos (sem caminho, ids internos nem chave)', m1 && JSON.stringify(Object.keys(m1).filter((k) => !['is_duplicate', 'replayed'].includes(k)).sort()) === JSON.stringify(MSG_KEYS), m1 && Object.keys(m1));
    check('B5', 'Hora em ISO com o fuso do consultório (-03:00)', m1 && /^\d{4}-\d\d-\d\dT\d\d:\d\d:\d\d-03:00$/.test(m1.created_at) && Math.abs(Date.parse(m1.created_at) - before.getTime()) < 120000, m1 && m1.created_at);
    r = await api('POST', '/api/v1/patient/chat/send', { token: ana, body: { message: 'Posso trocar o arroz por batata?', idempotency_key: K1 } });
    check('A6', 'Retentativa com a mesma chave → 200, mesma mensagem (replayed), sem duplicar', r.status === 200 && r.json.message.id === m1.id && r.json.message.replayed === true
      && Number(one(`SELECT COUNT(*) FROM chat_messages WHERE conversation_id = ${anaConv} AND message LIKE 'Posso trocar%'`)) === 1, r.text);
    check('A6', 'Retentativa não gera nova notificação', Number(one(`SELECT COUNT(*) FROM chat_notifications_log WHERE message_id = ${m1.id} AND channel = 'in_app'`)) === 1);
    const K2 = 'mob_' + Date.now() + '_par';
    const par = await Promise.all(Array.from({ length: 8 }, () => api('POST', '/api/v1/patient/chat/send', { token: ana, body: { message: 'Envio paralelo', idempotency_key: K2 } })));
    const ids = new Set(par.map((x) => x.json && x.json.message && x.json.message.id));
    check('A6', '8 envios simultâneos com a mesma chave → 1 mensagem gravada (índice único)', par.every((x) => [200, 201].includes(x.status)) && ids.size === 1
      && Number(one(`SELECT COUNT(*) FROM chat_messages WHERE conversation_id = ${anaConv} AND message = 'Envio paralelo'`)) === 1, par.map((x) => x.status));
    r = await api('POST', '/api/v1/patient/chat/send', { token: ana, body: { message: 'x', idempotency_key: 'chave com espaço' } });
    check('A6', 'Chave de idempotência inválida → 422', r.status === 422 && r.json.code === 'VALIDATION_ERROR', r.text);
    r = await api('POST', '/api/v1/patient/chat/send', { token: ana, body: { message: 'a'.repeat(2001) } });
    check('M2', 'Mensagem de 2.001 caracteres → 422 MESSAGE_TOO_LONG', r.status === 422 && r.json.code === 'MESSAGE_TOO_LONG', r.text.slice(0, 200));
    r = await api('POST', '/api/v1/patient/chat/send', { token: ana, body: { message: 'a'.repeat(300 * 1024) } });
    check('M2', 'Mensagem de 300 KB → 422', r.status === 422, r.status);
    r = await api('POST', '/api/v1/patient/chat/send', { token: ana, body: { message: XSS, idempotency_key: 'mob_xss_1' } });
    const xssId = r.json && r.json.message && r.json.message.id;
    check('A4', 'Texto com HTML é guardado como texto (a tela é que escapa)', r.status === 201 && r.json.message.message === XSS, r.status);

    // ------------------------------------------------------------------ A4: tela do profissional escapa o HTML
    page = (await equipe.req('GET', `/chat/${anaConv}`)).text;
    check('A4', 'Render do servidor escapa a mensagem (nenhum <img onerror> no HTML)', page.includes('&lt;img src=x onerror=') && !page.includes('<img src=x onerror'), page.includes('<img src=x'));
    check('A4', 'Script da tela não usa innerHTML para mensagens', !/innerHTML/.test(page) && /textContent = m\.message/.test(page));

    // ------------------------------------------------------------------ A3: anexos
    r = await api('POST', '/api/v1/patient/chat/send', { token: ana, form: pngForm({ message: 'Foto do almoço', idempotency_key: 'mob_png_1' }) });
    const att = r.json && r.json.message && r.json.message.attachment;
    check('A3', 'Paciente envia foto (multipart) → 201 com anexo', r.status === 201 && att && att.mime_type === 'image/png' && att.name === 'prato.png', r.text.slice(0, 300));
    check('A3', 'Anexo gravado como da paciente e ligado à mensagem', one(`SELECT uploaded_by_type FROM chat_attachments WHERE id = ${att.id}`) === 'patient' && Number(one(`SELECT attachment_id FROM chat_messages WHERE id = ${r.json.message.id}`)) === att.id);
    const filesNow = readdirSync(storage).length;
    r = await api('POST', '/api/v1/patient/chat/send', { token: ana, form: pngForm({ message: 'x', idempotency_key: 'mob_php_1' }, 'foto.png', Buffer.from('<?php echo 1; ?>'), 'image/png') });
    check('A3', 'PHP disfarçado de PNG → 422 ATTACHMENT_INVALID e nenhum arquivo fica no disco', r.status === 422 && r.json.code === 'ATTACHMENT_INVALID' && readdirSync(storage).length === filesNow, r.text);
    r = await api('POST', '/api/v1/patient/chat/send', { token: ana, form: pngForm({ message: 'x', idempotency_key: 'mob_big_1' }, 'grande.pdf', Buffer.concat([PDF, Buffer.alloc(11 * 1024 * 1024)]), 'application/pdf') });
    check('A3', 'Arquivo de 11 MB → 422', r.status === 422 && r.json.code === 'ATTACHMENT_INVALID', r.text.slice(0, 200));
    r = await api('POST', '/api/v1/patient/chat/send', { token: ana, form: pngForm({ message: 'Exame', idempotency_key: 'mob_pdf_1' }, 'exame.pdf', PDF, 'application/pdf') });
    const pdfAtt = r.json && r.json.message && r.json.message.attachment;
    check('A3', 'PDF aceito', r.status === 201 && pdfAtt && pdfAtt.mime_type === 'application/pdf', r.text.slice(0, 200));
    // Link do profissional na web: id do anexo, não da mensagem
    page = (await equipe.req('GET', `/chat/${anaConv}`)).text;
    check('A3', 'Web do profissional: link do anexo usa o id do anexo', page.includes(`href="/chat/anexo/${att.id}"`), page.match(/\/chat\/anexo\/\d+/g));
    r = await equipe.req('GET', `/chat/anexo/${att.id}`);
    check('A3', 'Profissional baixa a foto da paciente (bytes iguais)', r.status === 200 && Buffer.compare(r.buf, PNG) === 0 && /inline/.test(r.headers.get('content-disposition') || ''), r.status);
    const msgIdOfAtt = Number(one(`SELECT message_id FROM chat_attachments WHERE id = ${att.id}`));
    r = await equipe.req('GET', `/chat/anexo/${msgIdOfAtt}`);
    check('A3', 'Link pelo id da mensagem (bug antigo) não abre outro arquivo', msgIdOfAtt !== att.id && r.status === 404, [msgIdOfAtt, att.id, r.status]);
    r = await owner.req('GET', `/chat/anexo/${att.id}`);
    check('A3', 'Profissional que não participa da conversa → 404', r.status === 404, r.status);
    r = await equipe.req('GET', `/chat/anexo/${pdfAtt.id}`);
    check('B1', 'PDF sai como arquivo (attachment) e isolado (CSP sandbox, nosniff)', r.status === 200 && /^attachment;/.test(r.headers.get('content-disposition') || '') && /sandbox/.test(r.headers.get('content-security-policy') || '') && r.headers.get('x-content-type-options') === 'nosniff', [r.headers.get('content-disposition'), r.headers.get('content-security-policy')]);
    // Paciente abre anexo pelo link curto
    r = await api('POST', `/api/v1/patient/chat/attachment/${att.id}/link`, { token: ana, body: {} });
    const link = r.json && r.json.url;
    check('A3', 'App pede link curto do anexo (2 min)', r.status === 200 && /^\/api\/v1\/chat\/anexo\/a-\d+-/.test(link) && r.json.expires_in === 120, r.text);
    r = await api('GET', link);
    check('A3', 'Link abre o anexo sem token de sessão', r.status === 200 && Buffer.compare(r.buf, PNG) === 0, r.status);
    r = await api('GET', link.replace(/.$/, (c) => (c === 'A' ? 'B' : 'A')));
    check('A3', 'Link adulterado → 404', r.status === 404, r.status);
    const exp = Math.floor(Date.now() / 1000) - 10;
    const body = `a-${att.id}-1-1-${exp}`;
    const key = crypto.createHmac('sha256', APP_KEY).update('clyonutri-chat-anexo-v1').digest();
    const sig = crypto.createHmac('sha256', key).update(body).digest('base64').replace(/\+/g, '-').replace(/\//g, '_').replace(/=+$/, '');
    r = await api('GET', `/api/v1/chat/anexo/${body}-${sig}`);
    check('A3', 'Link vencido → 404 "expirou"', r.status === 404 && /expirou/.test(r.json && r.json.error), r.text);
    r = await api('POST', `/api/v1/patient/chat/attachment/${att.id}/link`, { token: bruno, body: {} });
    check('A3', 'Outra paciente não pede link do anexo da Ana → 404', r.status === 404 && r.json.code === 'ATTACHMENT_NOT_FOUND', r.text);
    r = await api('GET', `/api/v1/patient/chat/attachment/${att.id}`, { token: ana });
    check('A3', 'Download com o token da sessão também funciona para a dona', r.status === 200 && Buffer.compare(r.buf, PNG) === 0, r.status);
    check('M6', 'Downloads auditados (sem conteúdo)', Number(one(`SELECT COUNT(*) FROM audit_logs WHERE action = 'CHAT_ATTACHMENT_DOWNLOADED' AND resource_id = '${att.id}'`)) >= 3);

    // ------------------------------------------------------------------ M5: resposta da API e erros
    r = await api('GET', '/api/v1/patient/chat/messages', { token: ana });
    check('M5', 'Polling sem caminho de arquivo, sender_id, clinic_id nem chave', r.status === 200 && !/attachment_path|sender_id|clinic_id|idempotency_key|storage\//.test(r.text) && r.json.messages.every((m) => JSON.stringify(Object.keys(m).sort()) === JSON.stringify(MSG_KEYS)), r.text.slice(0, 300));
    r = await api('POST', '/api/v1/patient/chat/send', { token: ana, body: { conversation_id: 31, message: 'Injetada' } });
    check('M5', 'Conversa de outra paciente → 404 sem ids nem texto interno', r.status === 404 && r.json.code === 'CONVERSATION_NOT_FOUND' && !/\d/.test(r.json.error), r.text);
    r = await api('GET', '/api/v1/patient/chat/messages', { token: carla });
    check('isolamento', 'Paciente de outra clínica não vê nada da Ana', r.status === 200 && !/arroz por batata|prato\.png/.test(r.text));

    // ------------------------------------------------------------------ M1: notificação NI2-12
    const nRows = sql(`SELECT channel, status, failure_reason, recipient_type, recipient_id, title, body_preview FROM chat_notifications_log WHERE message_id = ${m1.id} ORDER BY channel`);
    const byCh = Object.fromEntries(nRows.map((x) => [x.channel, x]));
    check('M1', 'Nova mensagem: no app (sent), e-mail (driver de log: logged), push bloqueado sem provedor', byCh.in_app && byCh.in_app.status === 'sent' && byCh.email && byCh.email.status === 'logged'
      && byCh.push && byCh.push.status === 'blocked' && byCh.push.failure_reason === 'push_provider_not_configured' && byCh.email.recipient_type === 'professional' && Number(byCh.email.recipient_id) === 3, nRows);
    const mail = existsSync(mailLog) ? readFileSync(mailLog, 'utf8') : '';
    check('M1', 'E-mail vai para o profissional, sem conteúdo, nome da paciente nem dado clínico', /To: .*<equipe@example\.test>/.test(mail) && !/arroz|batata|Ana Fict/.test(mail) && /192/.test(mail), mail.slice(0, 300));
    check('M1', 'Texto neutro (sem "Seu nutricionista"/"Seu paciente")', nRows.every((x) => !/Seu nutricionista|Seu paciente/.test(x.body_preview)));
    const n2 = sql(`SELECT channel, status, failure_reason FROM chat_notifications_log WHERE message_id = (SELECT MAX(id) FROM chat_messages WHERE conversation_id = ${anaConv}) AND channel = 'email'`)[0];
    check('M1', 'Segundo e-mail em menos de 30 min para a mesma conversa é pulado (recent_email)', n2 && n2.status === 'skipped' && n2.failure_reason === 'recent_email', n2);
    fx('prefs', 'patient', 1, 0, 0);
    t = await equipe.token(`/chat/${anaConv}`, 'csrf_token');
    r = await equipe.req('POST', `/chat/${anaConv}/enviar`, { csrf_token: t, message: 'Pode, sim. Mesma quantidade.', idempotency_key: 'web_resp_1' }, 'application/json');
    const resp = JSON.parse(r.text).message;
    const pn = Object.fromEntries(sql(`SELECT channel, status, failure_reason FROM chat_notifications_log WHERE message_id = ${resp.id}`).map((x) => [x.channel, x]));
    check('M1', 'Preferências da paciente (e-mail e push desligados) são respeitadas', pn.email && pn.email.status === 'skipped' && pn.email.failure_reason === 'preference_off' && pn.push.status === 'skipped' && pn.in_app.status === 'sent', pn);

    // ------------------------------------------------------------------ entrega e leitura
    r = await api('GET', '/api/v1/patient/chat/messages', { token: ana });
    await api('POST', '/api/v1/patient/chat/read', { token: ana, body: { conversation_id: anaConv } });
    check('status', 'Resposta do profissional: entregue ao abrir o app e lida depois do recibo', one(`SELECT status FROM chat_messages WHERE id = ${resp.id}`) === 'read' && one(`SELECT read_at FROM chat_messages WHERE id = ${resp.id}`) !== null);
    check('M6', 'Leitura auditada com contagem, sem conteúdo', Number(one("SELECT COUNT(*) FROM audit_logs WHERE action = 'CHAT_MESSAGES_READ'")) >= 1);
    const sentAudit = sql(`SELECT details FROM audit_logs WHERE action = 'CHAT_MESSAGE_SENT' AND resource_id = '${m1.id}'`)[0];
    check('M6', 'Envio auditado com remetente e conversa, sem o texto', sentAudit && JSON.parse(sentAudit.details).actor_type === 'patient' && !/arroz/.test(sentAudit.details), sentAudit);

    // ------------------------------------------------------------------ A2: vínculo inativo, conversa arquivada, profissional inativo
    const linkId = one("SELECT id FROM patient_professional_links WHERE patient_id = 1 AND professional_id = 3 AND status = 'active'");
    t = await equipe.token('/pacientes/1');
    r = await equipe.req('POST', `/pacientes/1/chat/vinculo/${linkId}/encerrar`, { _csrf_token: t, ended_reason: 'Alta do acompanhamento' });
    r = await api('GET', '/api/v1/patient/chat/messages', { token: ana });
    check('A2', 'Vínculo encerrado: histórico visível, envio bloqueado com motivo', r.json.conversation_id === anaConv && r.json.messages.length >= 3 && r.json.access.can_send === false && r.json.access.code === 'LINK_INACTIVE' && /encerrado/.test(r.json.access.reason), r.json.access);
    const cnt = Number(one(`SELECT COUNT(*) FROM chat_messages WHERE conversation_id = ${anaConv}`));
    r = await api('POST', '/api/v1/patient/chat/send', { token: ana, body: { message: 'Ainda posso falar?' } });
    check('A2', 'Paciente envia com vínculo encerrado → 403 LINK_INACTIVE e nada gravado', r.status === 403 && r.json.code === 'LINK_INACTIVE' && Number(one(`SELECT COUNT(*) FROM chat_messages WHERE conversation_id = ${anaConv}`)) === cnt, r.text);
    page = (await equipe.req('GET', `/chat/${anaConv}`)).text;
    t = (page.match(/name="csrf_token" value="([^"]+)"/) || [])[1] || (await equipe.token('/chat', '_csrf_token'));
    r = await equipe.req('POST', `/chat/${anaConv}/enviar`, { csrf_token: t, message: 'Teste' }, 'application/json');
    check('A2', 'Profissional também fica só com leitura (tela sem campo; envio 403)', /data-testid="chat-read-only"/.test(page) && !/id="chatSendForm"/.test(page) && r.status === 403, r.status);
    check('M6', 'Encerramento do vínculo auditado', Number(one("SELECT COUNT(*) FROM audit_logs WHERE action = 'CHAT_LINK_ENDED'")) === 1);
    fx('link', 1, 3);
    fx('conv-status', anaConv, 'archived');
    r = await api('POST', '/api/v1/patient/chat/send', { token: ana, body: { message: 'Arquivada?' } });
    check('A2', 'Conversa arquivada → 403 LINK_INACTIVE', r.status === 403 && r.json.code === 'LINK_INACTIVE', r.text);
    fx('conv-status', anaConv, 'active');
    fx('member-status', 3, 1, 'inactive');
    r = await api('POST', '/api/v1/patient/chat/send', { token: ana, body: { message: 'Profissional saiu?' } });
    check('A2', 'Profissional inativado na clínica → 403 com "não atende mais"', r.status === 403 && /não atende mais/.test(r.json.error), r.text);
    fx('member-status', 3, 1, 'active');
    r = await api('POST', '/api/v1/patient/chat/send', { token: ana, body: { message: 'Voltou a funcionar', idempotency_key: 'mob_back_1' } });
    check('A2', 'Vínculo, conversa e profissional ativos de novo → envio volta a funcionar', r.status === 201, r.text);

    // ------------------------------------------------------------------ M3: chave de desligar vale para a API
    fx('chat-flag', '0');
    r = await api('GET', '/api/v1/patient/chat/messages', { token: ana });
    const r2 = await api('POST', '/api/v1/patient/chat/send', { token: ana, body: { message: 'desligado' } });
    const proTok = (await api('POST', '/api/v1/auth/professional-login', { body: { email: 'equipe@example.test', password: 'Equipe@2026' } })).json;
    const r3 = await api('GET', '/api/v1/professional/chat/conversations', { token: proTok && proTok.token });
    check('M3', 'Chat desligado pela administração: API da paciente e do profissional → 503 CHAT_DISABLED', r.status === 503 && r.json.code === 'CHAT_DISABLED' && r2.status === 503 && (!proTok || !proTok.token || r3.status === 503), [r.text, r2.status, r3.status]);
    fx('chat-flag', '1');

    // ------------------------------------------------------------------ app do profissional
    if (proTok && proTok.token) {
      r = await api('GET', '/api/v1/professional/chat/conversations', { token: proTok.token });
      const c = r.json && r.json.conversations && r.json.conversations.find((x) => x.id === anaConv);
      check('API profissional', 'Lista de conversas com can_send e sem e-mail/telefone da paciente', r.status === 200 && c && c.can_send === true && !/patient_email|patient_phone/.test(r.text), r.text.slice(0, 200));
      r = await api('POST', `/api/v1/professional/chat/${anaConv}/send`, { token: proTok.token, body: { message: 'Pelo app', idempotency_key: 'prof_app_1' } });
      check('API profissional', 'Profissional envia pelo app → 201 com lista fechada', r.status === 201 && JSON.stringify(Object.keys(r.json.message).filter((k) => !['is_duplicate', 'replayed'].includes(k)).sort()) === JSON.stringify(MSG_KEYS), r.text.slice(0, 200));
      r = await api('GET', '/api/v1/professional/chat/31/messages', { token: proTok.token });
      check('API profissional', 'Conversa de outro profissional → 404 sem ids', r.status === 404 && r.json.code === 'CONVERSATION_NOT_FOUND', r.text);
    } else {
      check('API profissional', 'Login do profissional no app', false, proTok);
    }

    // ------------------------------------------------------------------ M2: limite de taxa (conversa do Bruno)
    fx('link', 2, 1);
    let limited = null;
    for (let i = 0; i < 25 && !limited; i++) {
      const x = await api('POST', '/api/v1/patient/chat/send', { token: bruno, body: { message: 'Msg ' + i, idempotency_key: 'rate_' + i } });
      if (x.status === 429) limited = { i, x };
    }
    check('M2', '21ª mensagem em 1 minuto → 429 RATE_LIMITED com Retry-After', limited && limited.i === 20 && limited.x.json.code === 'RATE_LIMITED' && Number(limited.x.headers.get('retry-after')) > 0 && limited.x.json.retry_after > 0, limited && { i: limited.i, body: limited.x.text });

    // ------------------------------------------------------------------ A5: guarda (retenção)
    fx('age-chat', anaConv, 21);
    const anaAttFiles = sql(`SELECT stored_name FROM chat_attachments WHERE conversation_id = ${anaConv}`).map((x) => x.stored_name);
    let rep = JSON.parse(fx('retention', '--dry-run'));
    check('A5', 'Simulação (--dry-run) conta o que passou de 20 anos sem apagar nada', rep.dry_run === true && rep.clinics['1'] && rep.clinics['1'].messages > 0 && rep.clinics['1'].attachments === anaAttFiles.length
      && Number(one(`SELECT COUNT(*) FROM chat_messages WHERE conversation_id = ${anaConv}`)) > 0, rep);
    const orphan = join(storage, 'chat_0_orfao_teste_ni2138.png');
    writeFileSync(orphan, PNG); const old = new Date(Date.now() - 3 * 86400000); utimesSync(orphan, old, old);
    rep = JSON.parse(fx('retention'));
    check('A5', 'Rotina apaga mensagens, anexos (linha e arquivo) e notificações vencidos', Number(one(`SELECT COUNT(*) FROM chat_messages WHERE conversation_id = ${anaConv}`)) === 0
      && Number(one(`SELECT COUNT(*) FROM chat_attachments WHERE conversation_id = ${anaConv}`)) === 0 && Number(one(`SELECT COUNT(*) FROM chat_notifications_log WHERE conversation_id = ${anaConv}`)) === 0
      && anaAttFiles.every((f) => !existsSync(join(storage, f))) && rep.clinics['1'].files === anaAttFiles.length, rep);
    check('A5', 'Conversa do Bruno (recente) fica intacta', Number(one('SELECT COUNT(*) FROM chat_messages WHERE conversation_id = 31')) >= 20);
    check('A5', 'Arquivo órfão com mais de 24 h é apagado', !existsSync(orphan) && rep.orphan_files >= 1, rep.orphan_files);
    check('A5', 'Expurgo auditado com contagens', Number(one("SELECT COUNT(*) FROM audit_logs WHERE action = 'CHAT_RETENTION_PURGED'")) === 1);
  } catch (e) {
    check('erro', 'Execução sem exceção', false, e.stack);
  } finally {
    server.kill();
    // Arquivos de anexo criados por este teste (o armazenamento é o da aplicação).
    try { for (const f of readdirSync(storage)) if (!filesBefore.has(f)) rmSync(join(storage, f), { force: true }); } catch { /* */ }
    if (useMysql) { try { mysqlAdmin(`DROP DATABASE ${mysqlDb}`); } catch { /* */ } }
    rmSync(dir, { recursive: true, force: true });
  }
  const pass = results.filter((x) => x.ok).length;
  console.log(`\nNI2-138 (${useMysql ? 'MySQL' : 'SQLite'}): ${pass} PASS, ${results.length - pass} FAIL`);
  if (process.env.RESULTS_JSON) writeFileSync(process.env.RESULTS_JSON, JSON.stringify({ driver: useMysql ? 'mysql' : 'sqlite', pass, fail: results.length - pass, results }, null, 1));
  if (pass !== results.length) process.exitCode = 1;
})();
