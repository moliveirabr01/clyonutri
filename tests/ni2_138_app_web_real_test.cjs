// NI2-138 — chat no app da paciente (build web do Expo) e na tela web do profissional, contra o servidor real:
// aviso de tempo de resposta e de urgência, envio, reenvio depois de falha de rede sem duplicar, anexo (escolher, enviar,
// abrir), status Enviada/Lida, conversa só para leitura quando o vínculo acaba, sem vínculo, e HTML da paciente na tela
// do profissional mostrado como texto mesmo com a CSP desligada. Acessibilidade (axe) e sem rolagem horizontal.
// Pré-requisito: build web em mobile/dist (cd mobile && npx expo export --platform web --output-dir dist).
//   PLAYWRIGHT_MODULE=… AXE_MODULE=… CHROMIUM_PATH=… node tests/ni2_138_app_web_real_test.cjs
'use strict';
const { spawn, execFileSync } = require('node:child_process');
const { mkdtempSync, mkdirSync, writeFileSync, readdirSync, rmSync, existsSync } = require('node:fs');
const { join, resolve } = require('node:path');
const pw = require(process.env.PLAYWRIGHT_MODULE || 'playwright');
const axePath = require.resolve(process.env.AXE_MODULE || 'axe-core');
const root = resolve(__dirname, '..');
const dir = mkdtempSync('/tmp/ni2-138-');
const ev = join(root, 'docs/evidencias/ni2-138'); mkdirSync(ev, { recursive: true });
const storage = join(root, 'storage/app/chat_attachments');
const filesBefore = new Set(existsSync(storage) ? readdirSync(storage) : []);
const env = { ...process.env, DB_CONNECTION: 'sqlite', DB_DATABASE: join(dir, 't.sqlite'), NI2_FIXTURE_DIR: dir, APP_ENV: 'production', FORCE_HTTPS: 'false',
  APP_TIMEZONE: 'America/Sao_Paulo', TZ: 'America/Sao_Paulo', APP_KEY: 'ni2-138-chave-de-teste-ficticia-0001', MAIL_DRIVER: 'log', MAIL_LOG_PATH: join(dir, 'mail.log') };
const fx = (...args) => execFileSync('php', [join(root, 'tests/support/ni2_138_fixture.php'), ...args.map(String)], { cwd: root, env, encoding: 'utf8', stdio: ['ignore', 'pipe', 'pipe'] });
const one = (q) => { const r = JSON.parse(fx('sql', q))[0]; return r ? Object.values(r)[0] : null; };
const out = { checks: [] };
const ck = (n, ok, d) => { out.checks.push({ n, ok: !!ok, d: ok ? undefined : d }); console.log((ok ? 'PASS ' : 'FAIL ') + n + (ok ? '' : ' → ' + String(JSON.stringify(d)).slice(0, 300))); };
const PNG = Buffer.from('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNk+M9QDwADhgGAWjR9awAAAABJRU5ErkJggg==', 'base64');
const XSS = '<img src=x onerror="window.__xss=1"><b>negrito</b>';

(async () => {
  fx('prepare');
  fx('link', 1, 3);
  fx('chat-settings', 'em até 2 dias úteis', 20);
  const PORT = 18000 + Math.floor(Math.random() * 2000);
  const app = spawn('php', ['-d', 'session.save_path=' + dir, '-S', '127.0.0.1:' + PORT, '-t', 'mobile/dist', 'tests/support/ni2_294_ui_router.php'], { cwd: root, env, stdio: 'ignore' });
  // Tela do profissional com a CSP DESLIGADA: prova que o escape não depende da CSP.
  const web = spawn('php', ['-d', 'session.save_path=' + dir, '-S', '127.0.0.1:' + (PORT + 1), '-t', 'public'], { cwd: root, env: { ...env, CSP_MODE: 'off' }, stdio: 'ignore' });
  await new Promise((r) => setTimeout(r, 1000));
  const A = 'http://127.0.0.1:' + PORT, W = 'http://127.0.0.1:' + (PORT + 1);
  const br = await pw.chromium.launch({ ...(process.env.CHROMIUM_PATH ? { executablePath: process.env.CHROMIUM_PATH, headless: false, args: ['--headless=new'] } : {}) });
  const loginApp = async (p, email) => {
    await p.goto(A + '/');
    await p.getByPlaceholder('seuemail@exemplo.com').fill(email); await p.getByPlaceholder('Digite sua senha').fill('PacienteTeste@2026');
    await p.getByRole('button', { name: 'Entrar', exact: true }).click();
    await p.getByText('Chat', { exact: true }).last().waitFor({ timeout: 15000 });
    await p.getByText('Chat', { exact: true }).last().click();
    await p.waitForTimeout(1500);
  };
  const chat = (p) => p.getByTestId('patient-chat');
  try {
    // ------------------------------------------------------------------ paciente, 390 px
    let ctx = await br.newContext({ viewport: { width: 390, height: 844 }, locale: 'pt-BR', timezoneId: 'America/Sao_Paulo' });
    let p = await ctx.newPage(); const errs = []; p.on('pageerror', (e) => errs.push(e.message));
    await loginApp(p, 'ana@example.test');
    let text = await chat(p).innerText();
    ck('Cabeçalho com o profissional vinculado (Nutri Equipe), sem "Online"', /Nutri Equipe/.test(text) && !/Online|Conectado ao consultório|tempo real/.test(text), text.slice(0, 200));
    ck('Aviso acima do campo: "costuma responder em até 2 dias úteis", sem prazo garantido, e urgência 192', /costuma responder em até 2 dias úteis\. Não é um prazo garantido/.test(text) && /ligue 192/.test(text), text);
    await chat(p).getByPlaceholder('Escreva sua mensagem').fill('Posso trocar o arroz por batata?');
    await chat(p).getByRole('button', { name: 'Enviar mensagem' }).click();
    await chat(p).getByText('Posso trocar o arroz por batata?').waitFor({ timeout: 8000 });
    await p.waitForTimeout(800);
    ck('Mensagem enviada aparece com "Enviada"', /Posso trocar o arroz por batata\?[\s\S]*Enviada/.test(await chat(p).innerText()) && Number(one("SELECT COUNT(*) FROM chat_messages WHERE message = 'Posso trocar o arroz por batata?'")) === 1);

    // A6: o servidor grava, mas a resposta não chega (rede caiu). Reenviar não duplica.
    let dropOnce = true;
    await p.route('**/api/v1/patient/chat/send', async (route) => {
      if (dropOnce) { dropOnce = false; await route.fetch(); return route.abort('connectionreset'); }
      return route.continue();
    });
    await chat(p).getByPlaceholder('Escreva sua mensagem').fill('Mensagem com a rede caindo');
    await chat(p).getByRole('button', { name: 'Enviar mensagem' }).click();
    await chat(p).getByTestId('pending-failed').waitFor({ timeout: 8000 });
    text = await chat(p).innerText();
    ck('Falha de rede: mensagem fica como "Não enviada" com "Tentar de novo", dentro da tela', /Não enviada/.test(text) && /Tentar de novo/.test(text) && /Sem conexão|conectar/i.test(text), text.slice(-300));
    await p.screenshot({ path: join(ev, 'app-chat-falha-rede-390.png'), fullPage: true });
    await chat(p).getByRole('button', { name: 'Tentar de novo' }).first().click();
    await p.waitForTimeout(1500);
    ck('Tentar de novo usa a mesma chave: uma única mensagem no banco', Number(one("SELECT COUNT(*) FROM chat_messages WHERE message = 'Mensagem com a rede caindo'")) === 1
      && (await chat(p).getByTestId('pending-failed').count()) === 0 && (await chat(p).getByText('Mensagem com a rede caindo').count()) === 1);
    await p.unroute('**/api/v1/patient/chat/send');

    // A3: anexo
    const pick = p.waitForEvent('filechooser');
    await chat(p).getByRole('button', { name: /Anexar arquivo/ }).click();
    const chooser = await pick;
    await chooser.setFiles({ name: 'prato.png', mimeType: 'image/png', buffer: PNG });
    await chat(p).getByText('Anexo: prato.png').waitFor({ timeout: 5000 });
    await chat(p).getByPlaceholder('Escreva sua mensagem').fill('Foto do almoço');
    await chat(p).getByRole('button', { name: 'Enviar mensagem' }).click();
    await chat(p).getByRole('link', { name: 'Abrir anexo prato.png' }).waitFor({ timeout: 8000 });
    const attId = Number(one("SELECT id FROM chat_attachments WHERE original_name = 'prato.png' AND uploaded_by_type = 'patient'"));
    ck('Paciente anexa foto pelo app: gravada como dela e mostrada na conversa', attId > 0);
    const popupP = ctx.waitForEvent('page');
    await chat(p).getByRole('link', { name: 'Abrir anexo prato.png' }).click();
    const popup = await popupP;
    await popup.waitForLoadState().catch(() => {});
    const popupUrl = popup.url();
    const shown = await popup.evaluate(() => { const img = document.querySelector('img'); return img ? { w: img.naturalWidth, h: img.naturalHeight } : null; }).catch(() => null);
    ck('Abrir anexo: nova aba com o link curto mostra a imagem enviada', /\/api\/v1\/chat\/anexo\/a-\d+-/.test(popupUrl) && shown && shown.w === 1 && shown.h === 1, [popupUrl, shown]);
    await popup.close();
    await p.screenshot({ path: join(ev, 'app-chat-390.png'), fullPage: true });

    // Resposta do profissional com HTML: aparece como texto; a mensagem da paciente vira "Lida".
    const prof = await (await fetch(A + '/api/v1/auth/professional-login', { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify({ email: 'equipe@example.test', password: 'Equipe@2026' }) })).json();
    const convId = Number(one('SELECT id FROM chat_conversations WHERE patient_id = 1'));
    await fetch(A + `/api/v1/professional/chat/${convId}/messages`, { headers: { Authorization: 'Bearer ' + prof.token } });
    await fetch(A + `/api/v1/professional/chat/${convId}/read`, { method: 'POST', headers: { Authorization: 'Bearer ' + prof.token } });
    await fetch(A + `/api/v1/professional/chat/${convId}/send`, { method: 'POST', headers: { Authorization: 'Bearer ' + prof.token, 'Content-Type': 'application/json' }, body: JSON.stringify({ message: 'Pode, sim. ' + XSS, idempotency_key: 'prof_web_1' }) });
    await chat(p).getByText(/Pode, sim\./).waitFor({ timeout: 10000 });
    await p.waitForTimeout(3500);
    text = await chat(p).innerText();
    ck('App mostra HTML do profissional como texto (sem executar)', text.includes('<img src=x onerror=') && (await p.evaluate(() => window.__xss)) === undefined);
    ck('Mensagens da paciente lidas pelo profissional aparecem como "Lida"', /Posso trocar o arroz por batata\?[\s\S]*?Lida/.test(text), text.slice(0, 400));
    await p.addScriptTag({ path: axePath }).catch(() => {});
    let v = await p.evaluate(async () => window.axe ? (await window.axe.run('[data-testid="patient-chat"]', { runOnly: ['wcag2a', 'wcag2aa'] })).violations.map((x) => x.id + ':' + x.nodes.length) : ['axe-indisponivel']);
    ck('Chat da paciente sem violações WCAG 2 A/AA (axe)', v.length === 0, v);
    ck('Sem rolagem horizontal a 390 px', await p.evaluate(() => document.documentElement.scrollWidth <= innerWidth + 1));

    // A2: vínculo encerrado → só leitura
    fx('end-link', 1, 3);
    await p.waitForTimeout(4500);
    text = await chat(p).innerText();
    ck('Vínculo encerrado: campo some, histórico fica e o motivo aparece', (await chat(p).getByPlaceholder('Escreva sua mensagem').count()) === 0 && /foi encerrado/.test(text) && /Posso trocar o arroz/.test(text), text.slice(-200));
    await p.screenshot({ path: join(ev, 'app-chat-somente-leitura-390.png'), fullPage: true });
    ck('Sem erro de script (Ana)', errs.length === 0, errs);
    await ctx.close();

    // Bruno sem vínculo
    ctx = await br.newContext({ viewport: { width: 1024, height: 900 }, locale: 'pt-BR', timezoneId: 'America/Sao_Paulo' });
    p = await ctx.newPage(); const e2 = []; p.on('pageerror', (e) => e2.push(e.message));
    await loginApp(p, 'bruno@example.test');
    text = await chat(p).innerText();
    ck('Conversa antiga sem vínculo (criada pela regra anterior): histórico só para leitura', /Mensagem privada para o Bruno/.test(text) && /não enviar novas/.test(text) && (await chat(p).getByPlaceholder('Escreva sua mensagem').count()) === 0, text);
    fx('drop-conv', 31);
    await loginApp(p, 'bruno@example.test'); // sessão do app web fica só em memória: entra de novo
    text = await chat(p).innerText();
    ck('Sem vínculo: "Seu consultório ainda não liberou o chat" e nenhum campo de envio', /ainda não liberou o chat/.test(text) && (await chat(p).getByPlaceholder('Escreva sua mensagem').count()) === 0, text);
    await p.screenshot({ path: join(ev, 'app-chat-sem-vinculo-1024.png'), fullPage: true });
    ck('Sem erro de script (Bruno)', e2.length === 0, e2);
    await ctx.close();

    // ------------------------------------------------------------------ profissional na web, CSP desligada (A4)
    fx('link', 1, 3);
    ctx = await br.newContext({ viewport: { width: 1280, height: 900 }, locale: 'pt-BR', timezoneId: 'America/Sao_Paulo', bypassCSP: true });
    p = await ctx.newPage(); const e3 = []; p.on('pageerror', (e) => e3.push(e.message));
    const dialogs = []; p.on('dialog', (d) => { dialogs.push(d.message()); d.dismiss(); });
    await p.goto(W + '/login');
    await p.locator('input[name="email"]').fill('equipe@example.test'); await p.locator('input[name="password"]').fill('Equipe@2026');
    await Promise.all([p.waitForNavigation(), p.locator('form[action="/login"] button[type="submit"]').first().click()]);
    const ana = (await (await fetch(A + '/api/v1/auth/patient-login', { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify({ email: 'ana@example.test', password: 'PacienteTeste@2026' }) })).json()).token;
    await fetch(A + '/api/v1/patient/chat/send', { method: 'POST', headers: { Authorization: 'Bearer ' + ana, 'Content-Type': 'application/json' }, body: JSON.stringify({ message: XSS, idempotency_key: 'mob_xss_render' }) });
    await p.goto(W + `/chat/${convId}`);
    ck('Tela do profissional (render do servidor, CSP off): HTML como texto, nenhum <img>, nada executado',
      (await p.locator('.chat-bubble img').count()) === 0 && (await p.locator('.chat-bubble b').count()) === 0 && (await p.evaluate(() => window.__xss)) === undefined && /<img src=x onerror=/.test(await p.locator('#chatMessagesContainer').innerText()));
    await fetch(A + '/api/v1/patient/chat/send', { method: 'POST', headers: { Authorization: 'Bearer ' + ana, 'Content-Type': 'application/json' }, body: JSON.stringify({ message: 'Nova: ' + XSS, idempotency_key: 'mob_xss_poll' }) });
    await p.locator('#chatMessagesContainer').getByText(/^Nova: <img/).waitFor({ timeout: 10000 });
    ck('Mensagem nova chega pelo polling e continua como texto (sem innerHTML)', (await p.locator('.chat-bubble img').count()) === 0 && (await p.evaluate(() => window.__xss)) === undefined && dialogs.length === 0);
    ck('Link do anexo na web usa o id do anexo', (await p.locator(`a[href="/chat/anexo/${attId}"]`).count()) === 1);
    const ptext = await p.locator('.conv-main-panel').innerText();
    ck('Sem "Online" nem "criptografado"; aviso do que a paciente vê', !/Online|Criptografado/i.test(ptext) && /em até 2 dias úteis/.test(ptext) && /192/.test(ptext), ptext.slice(0, 300));
    await p.screenshot({ path: join(ev, 'consultorio-chat-1280.png'), fullPage: true });
    await p.addScriptTag({ path: axePath }).catch(() => {});
    v = await p.evaluate(async () => window.axe ? (await window.axe.run('.chat-layout', { runOnly: ['wcag2a', 'wcag2aa'] })).violations.map((x) => x.id + ':' + x.nodes.length) : ['axe-indisponivel']);
    out.axe_profissional = v;
    ck('Chat do profissional sem violações WCAG 2 A/AA (axe)', v.length === 0, v);
    // Perfil da paciente: vínculo do chat
    await p.goto(W + '/pacientes/1');
    const sect = p.getByTestId('chat-link');
    await sect.scrollIntoViewIfNeeded();
    await sect.screenshot({ path: join(ev, 'consultorio-perfil-vinculo-chat.png') });
    ck('Perfil da paciente mostra o vínculo do chat, histórico e botão de encerrar', /Chat liberado/.test(await sect.innerText()) && /Encerrado/.test(await sect.innerText()) && (await sect.getByRole('button', { name: 'Encerrar chat' }).count()) === 1);
    ck('Sem erro de script (profissional)', e3.length === 0, e3);
    await ctx.close();
  } catch (e) {
    ck('Execução sem exceção', false, e.stack);
  } finally {
    await br.close(); app.kill(); web.kill();
    try { for (const f of readdirSync(storage)) if (!filesBefore.has(f)) rmSync(join(storage, f), { force: true }); } catch { /* */ }
    rmSync(dir, { recursive: true, force: true });
  }
  const ok = out.checks.filter((c) => c.ok).length;
  console.log(`\nNI2-138 app (web): ${ok} PASS, ${out.checks.length - ok} FAIL`);
  writeFileSync(join(ev, 'app-web-resultados.json'), JSON.stringify(out, null, 1));
  if (ok !== out.checks.length) process.exitCode = 1;
})().catch((e) => { console.log('ERRO', e.stack); process.exit(1); });
