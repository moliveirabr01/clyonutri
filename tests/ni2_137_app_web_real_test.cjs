// NI2-137 — card da próxima consulta no app da paciente (build web do Expo) contra o servidor real:
// data com fuso, modalidade e local, confirmar, erro quando o servidor recusa, pedir outro horário, desistir,
// cancelar com motivo, ações escondidas quando a clínica não libera, acessibilidade (axe) e sem rolagem horizontal.
// Pré-requisito: build web em mobile/dist (cd mobile && npx expo export --platform web --output-dir dist).
//   PLAYWRIGHT_MODULE=… AXE_MODULE=… CHROMIUM_PATH=… node tests/ni2_137_app_web_real_test.cjs
'use strict';
const { spawn, execFileSync } = require('node:child_process');
const { mkdtempSync, mkdirSync, writeFileSync } = require('node:fs');
const { join, resolve } = require('node:path');
const pw = require(process.env.PLAYWRIGHT_MODULE || 'playwright');
const axePath = require.resolve(process.env.AXE_MODULE || 'axe-core');
const root = resolve(__dirname, '..');
const dir = mkdtempSync('/tmp/ni2-137-');
const ev = join(root, 'docs/evidencias/ni2-137'); mkdirSync(ev, { recursive: true });
const env = { ...process.env, DB_CONNECTION: 'sqlite', DB_DATABASE: join(dir, 't.sqlite'), NI2_FIXTURE_DIR: dir, APP_ENV: 'production', FORCE_HTTPS: 'false',
  APP_TIMEZONE: 'America/Sao_Paulo', TZ: 'America/Sao_Paulo', APP_KEY: 'ni2-137-chave-de-teste-ficticia-0001' };
const fx = (...args) => execFileSync('php', [join(root, 'tests/support/ni2_137_fixture.php'), ...args.map(String)], { cwd: root, env, encoding: 'utf8', stdio: ['ignore', 'pipe', 'pipe'] });
const one = (q) => { const r = JSON.parse(fx('sql', q))[0]; return r ? Object.values(r)[0] : null; };
// Rótulo esperado no card: "qua., 28/10 às 14:00" (horário do consultório).
const label = (sqlDt) => execFileSync('php', ['-r', '$d=new DateTimeImmutable($argv[1]); echo ["dom.","seg.","ter.","qua.","qui.","sex.","sáb."][(int)$d->format("w")] . ", " . $d->format("d/m") . " às " . $d->format("H:i");', sqlDt], { encoding: 'utf8' });
const out = { checks: [] };
const ck = (n, ok, d) => { out.checks.push({ n, ok: !!ok, d: ok ? undefined : d }); console.log((ok ? 'PASS ' : 'FAIL ') + n + (ok ? '' : ' → ' + String(JSON.stringify(d)).slice(0, 300))); };

(async () => {
  fx('prepare');
  const a300 = JSON.parse(fx('appt', 300, 1, 1, 1, 'scheduled', 30, 'follow_up'));
  const a301 = JSON.parse(fx('appt', 301, 1, 1, 1, 'scheduled', 50, 'follow_up'));
  JSON.parse(fx('appt', 302, 2, 1, 1, 'scheduled', 24, 'online')); fx('tele', 302);
  fx('settings', JSON.stringify({ patient_can_cancel: true, patient_can_request_reschedule: true }));
  const PORT = 18000 + Math.floor(Math.random() * 2000);
  const srv = spawn('php', ['-S', '127.0.0.1:' + PORT, '-t', 'mobile/dist', 'tests/support/ni2_294_ui_router.php'], { cwd: root, env, stdio: 'ignore' });
  await new Promise((r) => setTimeout(r, 900));
  let srv2 = null;
  const br = await pw.chromium.launch({ ...(process.env.CHROMIUM_PATH ? { executablePath: process.env.CHROMIUM_PATH, headless: false, args: ['--headless=new'] } : {}) });
  const login = async (p, email) => {
    await p.goto('http://127.0.0.1:' + PORT + '/');
    await p.getByPlaceholder('seuemail@exemplo.com').fill(email); await p.getByPlaceholder('Digite sua senha').fill('PacienteTeste@2026');
    await p.getByRole('button', { name: 'Entrar', exact: true }).click();
    await p.getByText('Próxima consulta', { exact: true }).waitFor({ timeout: 15000 });
    await p.waitForTimeout(500);
  };
  const card = (p) => p.getByTestId('next-appointment-card');
  try {
    // ------------------------------------------------------------------ 390 px: fluxo completo da Ana
    let ctx = await br.newContext({ viewport: { width: 390, height: 844 }, locale: 'pt-BR', timezoneId: 'America/Sao_Paulo' });
    let p = await ctx.newPage(); const errs = []; p.on('pageerror', (e) => errs.push(e.message));
    await login(p, 'ana@example.test');
    let text = await card(p).innerText();
    ck('Card: data no horário do consultório, com dia da semana e fuso escrito', text.includes(label(a300.scheduled_at)) && /horário de Brasília/.test(text), text);
    ck('Card: modalidade "Presencial" e endereço do consultório', /Presencial/.test(text) && /Local: Rua Fictícia, 100 - Sala 12/.test(text), text);
    ck('Card: sem emoji e sem Caixa De Título nos botões', !/[\u{1F300}-\u{1FAFF}]/u.test(text) && /Confirmar presença/.test(text) && !/Confirmar Presença/.test(text), text);
    ck('Card: botões liberados pela clínica (confirmar, pedir outro horário, cancelar)', /Confirmar presença/.test(text) && /Pedir outro horário/.test(text) && /Cancelar consulta/.test(text), text);
    ck('Card: nenhuma nota interna da agenda', !/Nota interna/.test(await p.locator('body').innerText()));
    await p.screenshot({ path: join(ev, 'app-card-390.png'), fullPage: true });
    await p.addScriptTag({ path: axePath }).catch(() => {});
    let v = await p.evaluate(async () => window.axe ? (await window.axe.run('[data-testid="next-appointment-card"]', { runOnly: ['wcag2a', 'wcag2aa'] })).violations.map((x) => x.id + ':' + x.nodes.length) : ['axe-indisponivel']);
    ck('Card sem violações WCAG 2 A/AA (axe)', v.length === 0, v);

    // A4: o consultório cancela enquanto a tela está aberta; confirmar mostra o erro do servidor (não "confirmado").
    fx('staff-status', 300, 'cancelled');
    await card(p).getByRole('button', { name: 'Confirmar presença na próxima consulta' }).click();
    await p.getByTestId('appointment-banner').waitFor({ timeout: 8000 });
    let banner = await p.getByTestId('appointment-banner').innerText();
    ck('Servidor recusa: aviso de erro dentro do card (role=alert), nada de "confirmada"', /Cancelada/.test(banner) && /não pode ser confirmada/.test(banner) && !/Presença confirmada/.test(banner) && !/Código de atendimento/.test(banner) && (await p.getByTestId('appointment-banner').getAttribute('role')) === 'alert', banner);
    ck('Banco: a consulta cancelada continua cancelada', one('SELECT status FROM appointments WHERE id = 300') === 'cancelled');
    await p.waitForTimeout(800);
    text = await card(p).innerText();
    ck('Card recarrega do servidor e passa para a próxima consulta válida (301)', text.includes(label(a301.scheduled_at)), text);

    // Confirmar com carregamento no botão e mensagem de sucesso
    await card(p).getByRole('button', { name: 'Confirmar presença na próxima consulta' }).click();
    await p.getByText('Presença confirmada.').waitFor({ timeout: 8000 });
    await p.waitForTimeout(600);
    text = await card(p).innerText();
    ck('Confirmar: mensagem "Presença confirmada." e botão some (status Confirmada)', /Presença confirmada\./.test(text) && /Confirmada/.test(text) && !/Confirmar presença\n/.test(text + '\n') && one('SELECT status FROM appointments WHERE id = 301') === 'confirmed', text);

    // Pedir outro horário
    await card(p).getByRole('button', { name: 'Pedir outro horário' }).click();
    await card(p).getByText('Escolha um horário livre').waitFor({ timeout: 8000 });
    await p.waitForTimeout(500);
    const radios = card(p).getByRole('radio');
    ck('Lista de horários livres como opções (radio) com rótulo completo', (await radios.count()) > 0 && /às \d\d:\d\d/.test(await radios.first().getAttribute('aria-label')), await radios.count());
    await radios.nth(1).click();
    ck('Horário escolhido anunciado como selecionado (aria-checked)', (await card(p).locator('[role="radio"][aria-checked="true"]').count()) === 1);
    await p.screenshot({ path: join(ev, 'app-remarcar-390.png'), fullPage: true });
    await card(p).getByPlaceholder('Ex.: viagem a trabalho').fill('Viagem a trabalho');
    await card(p).getByRole('button', { name: /^Pedir / }).click();
    await p.getByText(/Pedido enviado\./).waitFor({ timeout: 8000 });
    await p.waitForTimeout(600);
    text = await card(p).innerText();
    const reqRow = JSON.parse(fx('sql', 'SELECT id, status, reason, requested_scheduled_at FROM appointment_change_requests WHERE appointment_id = 301'))[0];
    ck('Pedido enviado: aviso, caixa "aguardando o consultório" e consulta no horário atual', /Pedido de remarcação: aguardando o consultório/.test(text) && text.includes(label(a301.scheduled_at)) && reqRow && reqRow.status === 'pending' && reqRow.reason === 'Viagem a trabalho', [text, reqRow]);
    ck('Com pedido pendente, "Pedir outro horário" some e o motivo aparece', !/Pedir outro horário/.test(text) && /aguardando resposta do consultório/.test(text), text);
    await p.screenshot({ path: join(ev, 'app-pedido-pendente-390.png'), fullPage: true });
    await card(p).getByRole('button', { name: 'Desistir do pedido' }).click();
    await p.getByText('Pedido de remarcação retirado.').waitFor({ timeout: 8000 });
    ck('Desistir do pedido: aviso e pedido encerrado no banco', one(`SELECT status FROM appointment_change_requests WHERE id = ${reqRow.id}`) === 'cancelled');

    // Cancelar com confirmação e motivo
    await p.waitForTimeout(600);
    await card(p).getByRole('button', { name: 'Cancelar consulta' }).click();
    await card(p).getByText(/Cancelar a consulta de .*\?/).waitFor();
    await card(p).getByPlaceholder('Ex.: imprevisto no trabalho').fill('Imprevisto no trabalho');
    await p.screenshot({ path: join(ev, 'app-cancelar-390.png'), fullPage: true });
    await card(p).getByRole('button', { name: 'Sim, cancelar consulta' }).click();
    await p.getByText(/Consulta cancelada\./).waitFor({ timeout: 8000 });
    await p.waitForTimeout(800);
    text = await card(p).innerText().catch(() => p.locator('body').innerText());
    const hist = JSON.parse(fx('sql', 'SELECT notes, changed_by_patient_id FROM appointment_status_history WHERE appointment_id = 301 ORDER BY id DESC'))[0];
    ck('Cancelar: aviso, card sem consulta e histórico com a paciente e o motivo', /Nenhuma consulta agendada/.test(await p.locator('body').innerText()) && one('SELECT status FROM appointments WHERE id = 301') === 'cancelled' && /Imprevisto no trabalho/.test(hist.notes) && Number(hist.changed_by_patient_id) === 1, [hist]);
    ck('Sem rolagem horizontal a 390 px', await p.evaluate(() => document.documentElement.scrollWidth <= innerWidth + 1));
    ck('Sem erro de script (Ana, 390 px)', errs.length === 0, errs);
    await ctx.close();

    // ------------------------------------------------------------------ padrão da clínica e teleconsulta: Bruno
    fx('settings', JSON.stringify({ patient_can_cancel: false, patient_can_request_reschedule: false }));
    for (const [w, h] of [[390, 844], [1024, 900]]) {
      ctx = await br.newContext({ viewport: { width: w, height: h }, locale: 'pt-BR', timezoneId: 'America/Sao_Paulo' });
      p = await ctx.newPage(); const e2 = []; p.on('pageerror', (e) => e2.push(e.message));
      await login(p, 'bruno@example.test');
      text = await card(p).innerText();
      if (w === 390) {
        ck('Padrão da clínica: só "Confirmar presença"; cancelar e pedir outro horário escondidos com o motivo', /Confirmar presença/.test(text) && !/Cancelar consulta/.test(text) && !/Pedir outro horário/.test(text) && /Para cancelar, fale com o consultório\./.test(text) && /Telefone do consultório: \(11\) 4000-0000/.test(text), text);
        ck('Teleconsulta fora da janela: sem botão de entrar; diz quando a sala abre', /Teleconsulta \(online\)/.test(text) && /A sala online abre/.test(text) && !/Entrar na teleconsulta/.test(text), text);
        await p.addScriptTag({ path: axePath }).catch(() => {});
        v = await p.evaluate(async () => window.axe ? (await window.axe.run('[data-testid="next-appointment-card"]', { runOnly: ['wcag2a', 'wcag2aa'] })).violations.map((x) => x.id + ':' + x.nodes.length) : ['axe-indisponivel']);
        ck('Card (teleconsulta, ações bloqueadas) sem violações WCAG 2 A/AA', v.length === 0, v);
      }
      await p.screenshot({ path: join(ev, `app-card-teleconsulta-${w}.png`), fullPage: true });
      ck(`Sem erro de script (Bruno, ${w} px)`, e2.length === 0, e2);
      await ctx.close();
    }

    // ------------------------------------------------------------------ lado do consultório: fila e Configurações
    fx('settings', JSON.stringify({ patient_can_request_reschedule: true }));
    const B = 'http://127.0.0.1:' + PORT;
    // Telas do consultório: o mesmo public/index.php, servido sem o roteador do app.
    const PORT2 = PORT + 1;
    srv2 = spawn('php', ['-d', 'session.save_path=' + dir, '-S', '127.0.0.1:' + PORT2, '-t', 'public'], { cwd: root, env, stdio: 'ignore' });
    await new Promise((r) => setTimeout(r, 900));
    const W = 'http://127.0.0.1:' + PORT2;
    const bt = (await (await fetch(B + '/api/v1/auth/patient-login', { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify({ email: 'bruno@example.test', password: 'PacienteTeste@2026' }) })).json()).token;
    const opts = await (await fetch(B + '/api/v1/patient/appointments/302/reschedule-options', { headers: { Authorization: 'Bearer ' + bt } })).json();
    const rq = await fetch(B + '/api/v1/patient/appointments/302/reschedule-requests', { method: 'POST', headers: { Authorization: 'Bearer ' + bt, 'Content-Type': 'application/json' }, body: JSON.stringify({ requested_scheduled_at: opts.days[0].slots[0].scheduled_at, reason: 'Consulta médica no mesmo horário' }) });
    ck('Bruno pede outro horário pela API (202)', rq.status === 202);
    ctx = await br.newContext({ viewport: { width: 1280, height: 900 }, locale: 'pt-BR', timezoneId: 'America/Sao_Paulo', bypassCSP: true }); // só para injetar o axe
    p = await ctx.newPage(); const e3 = []; p.on('pageerror', (e) => e3.push(e.message));
    await p.goto(W + '/login');
    await p.locator('input[name="email"]').fill('equipe@example.test'); await p.locator('input[name="password"]').fill('Equipe@2026');
    await Promise.all([p.waitForNavigation(), p.locator('form[action="/login"] button[type="submit"]').first().click()]);
    await p.goto(W + '/agenda');
    ck('Agenda avisa o pedido de remarcação', /1 pedido de remarcação/.test(await p.locator('[data-testid="agenda-change-requests"]').innerText()));
    await p.goto(W + '/agenda/pedidos-remarcacao');
    const q = await p.locator('main, body').first().innerText();
    ck('Fila: paciente, horário atual, horário pedido, motivo e botões', /Bruno Fictício/.test(q) && /Consulta médica no mesmo horário/.test(q) && /Aceitar novo horário/.test(q) && /Recusar/.test(q), q.slice(0, 400));
    await p.screenshot({ path: join(ev, 'consultorio-fila-remarcacao-1280.png'), fullPage: true });
    await p.addScriptTag({ path: axePath }).catch(() => {});
    v = await p.evaluate(async () => window.axe ? (await window.axe.run('[data-testid="change-requests"]', { runOnly: ['wcag2a', 'wcag2aa'] })).violations.map((x) => x.id + ':' + x.nodes.length) : ['axe-indisponivel']);
    ck('Tabela da fila sem violações WCAG 2 A/AA', v.length === 0, v);
    await p.getByTestId('decline-request').click(); await p.waitForLoadState();
    ck('Recusar pela tela: aviso e pedido recusado no banco', /Pedido recusado/.test(await p.locator('[data-testid="change-request-flash"]').innerText()) && one("SELECT status FROM appointment_change_requests WHERE appointment_id = 302") === 'declined');
    await ctx.close();
    ctx = await br.newContext({ viewport: { width: 1280, height: 900 }, locale: 'pt-BR', timezoneId: 'America/Sao_Paulo', bypassCSP: true }); // só para injetar o axe
    p = await ctx.newPage();
    await p.goto(W + '/login');
    await p.locator('input[name="email"]').fill('nutri1@example.test'); await p.locator('input[name="password"]').fill('Equipe@2026');
    await Promise.all([p.waitForNavigation(), p.locator('form[action="/login"] button[type="submit"]').first().click()]);
    await p.goto(W + '/configuracoes');
    const form = p.getByTestId('patient-actions-settings');
    await form.scrollIntoViewIfNeeded();
    await form.screenshot({ path: join(ev, 'consultorio-configuracoes-acoes-paciente.png') });
    const ft = await form.innerText();
    ck('Configurações: seção "Consulta no app da paciente" com as três ações e os prazos (mínimo 1 h)', /Consulta no app da paciente/.test(ft) && /Confirmar presença/.test(ft) && /Cancelar a consulta/.test(ft) && /Pedir outro horário/.test(ft) && (await form.locator('input[name="booking_cancel_min_advance_hours"]').getAttribute('min')) === '1', ft.slice(0, 300));
    await p.addScriptTag({ path: axePath }).catch(() => {});
    v = await p.evaluate(async () => window.axe ? (await window.axe.run('[data-testid="patient-actions-settings"]', { runOnly: ['wcag2a', 'wcag2aa'] })).violations.map((x) => x.id + ':' + x.nodes.length) : ['axe-indisponivel']);
    ck('Seção de Configurações sem violações WCAG 2 A/AA', v.length === 0, v);
    ck('Sem erro de script nas telas do consultório', e3.length === 0, e3);
    await ctx.close();
  } catch (e) {
    ck('Execução sem exceção', false, e.stack);
  } finally {
    await br.close(); srv.kill(); if (srv2) srv2.kill();
  }
  const ok = out.checks.filter((c) => c.ok).length;
  console.log(`\nNI2-137 app (web): ${ok} PASS, ${out.checks.length - ok} FAIL`);
  writeFileSync(join(ev, 'app-web-resultados.json'), JSON.stringify(out, null, 1));
  if (ok !== out.checks.length) process.exitCode = 1;
})().catch((e) => { console.log('ERRO', e.stack); process.exit(1); });
