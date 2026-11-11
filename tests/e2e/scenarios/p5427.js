// v54.27 Teleconsulta com o vídeo em primeiro lugar. Paciente: horário exato de abertura, contagem regressiva com
// relógio falso, botão que libera sozinho e erro visível no topo. Nutricionista: vídeo grande com o painel de
// evolução aberto (≥60% da largura em 1280), modo foco (≥85%), tela cheia no celular, barra de controles
// acessível (axe + alvos de 44px + aria-pressed + teclado). Chamada conectada com dois navegadores e mídia falsa.
// Dados TESTE, SQLite descartável.
const { chromium } = require('playwright');
const { execSync } = require('child_process');
const fs = require('fs'); const path = require('path');
const res = []; const ok = (c, l) => { res.push(!!c); console.log((c ? '  PASS  ' : '  FAIL  ') + l); };
const clean = s => String(s).replace(/Error:/g, 'Erro -');
(async () => {
  const B = process.argv[2], DB = process.argv[3];
  const OUT = process.env.E2E_OUT || '/tmp';
  const sq = q => execSync(`php ${process.env.E2E_SQ} ${DB} "${q}"`).toString().trim();
  const token = fs.readFileSync(DB + '.token', 'utf8').trim(); fs.unlinkSync(DB + '.token');
  const sid = sq('SELECT id FROM teleconsultation_sessions LIMIT 1');
  const app = sq(`SELECT appointment_id FROM teleconsultation_sessions WHERE id=${sid}`);
  const utcSql = ms => new Date(ms).toISOString().slice(0, 19).replace('T', ' ');
  const locSql = ms => new Date(ms).toLocaleString('sv-SE', { timeZone: 'America/Sao_Paulo' }).slice(0, 19);
  const locHM = ms => new Date(ms).toLocaleTimeString('pt-BR', { timeZone: 'America/Sao_Paulo', hour: '2-digit', minute: '2-digit' });
  const AXE = [process.env.E2E_AXE, '/workspace/m1103/n233-tools/node_modules/axe-core/axe.min.js', (() => { try { return require.resolve('axe-core/axe.min.js'); } catch (e) { return ''; } })()].find(f => f && fs.existsSync(f));
  const AXE_SRC = AXE ? fs.readFileSync(AXE, 'utf8') : '';
  const b = await chromium.launch({ args: ['--use-fake-ui-for-media-stream', '--use-fake-device-for-media-stream', '--autoplay-policy=no-user-gesture-required'] });
  const errs = [], csp = [];
  const watch = p => { p.on('pageerror', e => errs.push(clean(e.message))); p.on('console', m => { if (/Content Security Policy/i.test(m.text())) csp.push(clean(m.text())); }); p.on('dialog', d => d.accept()); };
  const ctxOf = w => b.newContext({ viewport: { width: w, height: w > 500 ? 860 : 844 }, permissions: ['camera', 'microphone'] });

  // abertura daqui a 90 s (consulta às +105 min menos 15 min de antecedência padrão)
  const t0 = Date.now();
  const opensMs = Math.ceil((t0 + 90e3) / 1000) * 1000;
  sq(`UPDATE appointments SET scheduled_at='${locSql(opensMs + 15 * 60e3)}' WHERE id=${app}`);
  sq(`UPDATE teleconsultation_sessions SET join_opens_at_utc='${utcSql(opensMs)}' WHERE id=${sid}`);

  console.log('== Paciente: sala de espera antes da abertura (390 px, relógio falso)');
  const pc = await ctxOf(390); const pp = await pc.newPage(); watch(pp);
  let advanced = 0; // o relógio falso do "servidor" anda junto com o do aparelho
  await pp.route('**/teleconsulta/sala-espera/status', async route => {
    const r = await route.fetch(); const j = await r.json();
    j.server_now_utc = new Date(Date.parse(j.server_now_utc) + advanced).toISOString().replace(/\.\d+Z$/, 'Z');
    await route.fulfill({ response: r, json: j });
  });
  await pp.clock.install({ time: Date.now() });
  await pp.goto(`${B}/teleconsulta/sala-espera/${token}`);
  const label = locHM(opensMs);
  ok((await pp.locator('#tc-opening-time').innerText()).includes(label), `mostra o horário exato: "A sala abre às ${label}"`);
  const cd1 = await pp.locator('#tc-countdown').innerText();
  ok(/^Faltam (1 min \d\d s|\d+ s)$/.test(cd1), `contagem regressiva visível ("${cd1}")`);
  ok(await pp.locator('#tc-countdown').getAttribute('role') === 'timer' && await pp.locator('#tc-countdown').getAttribute('aria-live') === 'off', 'contagem como timer que não interrompe o leitor de tela');
  await pp.click('#tc-test');
  await pp.waitForFunction(() => /funcionando/.test(document.getElementById('tc-test-status').textContent), null, { timeout: 10000 });
  await pp.check('#tc-consent');
  await pp.waitForFunction(() => /botão libera sozinho/.test(document.getElementById('tc-wait-status').textContent), null, { timeout: 10000 });
  const en = pp.locator('#tc-enter');
  ok(await en.isDisabled() && (await en.innerText()).includes('A sala abre às ' + label), 'etapas feitas, mas o botão segue desligado com "A sala abre às …"');
  await pp.clock.runFor(30e3); advanced += 30e3;
  const cd2 = await pp.locator('#tc-countdown').innerText();
  ok(cd2 !== cd1 && /^Faltam/.test(cd2), `30 s depois a contagem andou ("${cd2}")`);
  ok(await en.isDisabled(), 'ainda fechada: botão desligado');
  const left = opensMs - (t0 + advanced) + 2000;
  advanced += left; await pp.clock.runFor(left);
  await pp.waitForFunction(() => !document.getElementById('tc-enter').disabled, null, { timeout: 5000 }).catch(() => {});
  ok(!(await en.isDisabled()) && (await en.innerText()).trim() === 'Entrar na sala de espera', 'no horário o botão liga sozinho: "Entrar na sala de espera"');
  ok(/está aberta/.test(await pp.locator('#tc-opening-time').innerText()), 'aviso troca para "A sala de espera está aberta"');

  console.log('== Erro do servidor aparece no topo');
  // o relógio real do servidor ainda não chegou na abertura: o servidor recusa e a página mostra o motivo
  await pp.evaluate(() => window.scrollTo(0, document.body.scrollHeight));
  await en.click();
  const al = pp.locator('#tc-alert');
  await al.waitFor({ state: 'visible', timeout: 8000 }).catch(() => {});
  ok(await al.isVisible() && await al.getAttribute('role') === 'alert', 'erro em role=alert visível');
  ok((await pp.locator('#tc-alert-text').innerText()).includes('abre às ' + label), 'mensagem do servidor com o horário exato');
  await pp.waitForTimeout(900);
  const box = await al.boundingBox();
  ok(box && box.y >= -1 && box.y + box.height <= 844 + 1, 'o aviso foi rolado para dentro da tela');
  ok(await pp.evaluate(() => document.activeElement && document.activeElement.id === 'tc-alert'), 'foco vai para o aviso');
  ok(sq(`SELECT status FROM teleconsultation_sessions WHERE id=${sid}`) !== 'waiting_room', 'servidor continua barrando antes da hora (banco)');
  await pc.close();

  console.log('== Sala aberta: paciente entra, nutricionista é avisado e admite');
  sq(`UPDATE teleconsultation_sessions SET join_opens_at_utc='${utcSql(Date.now() - 60e3)}' WHERE id=${sid}`);
  const prc = await ctxOf(1280); const pr = await prc.newPage(); watch(pr);
  await pr.goto(`${B}/__teste_login?u=1&c=1&v=0&to=${encodeURIComponent('/teleconsulta/' + sid + '/atendimento')}`);
  const vw = async () => pr.evaluate(() => { const v = document.getElementById('tc-video-area').getBoundingClientRect(); return { r: v.width / document.documentElement.clientWidth, h: v.height, w: v.width }; });
  const panelOpen = () => pr.evaluate(() => { const p = document.getElementById('tc-panel'); const r = p.getBoundingClientRect(); return getComputedStyle(p).visibility !== 'hidden' && getComputedStyle(p).display !== 'none' && r.width > 300 && r.left < document.documentElement.clientWidth; });
  ok(await panelOpen(), '1280: painel "Evolução no prontuário" aberto ao lado');
  let m = await vw();
  ok(m.r >= 0.6, `1280 com painel: vídeo ocupa ${(m.r * 100).toFixed(0)}% da largura (≥60%)`);
  ok(await pr.locator('#tc-chip').innerText() !== '' && /Aguardando/i.test(await pr.locator('#tc-placeholder-title').innerText()), 'estado "aguardando paciente" no vídeo');
  const pc2 = await ctxOf(390); const pp2 = await pc2.newPage(); watch(pp2);
  await pp2.goto(`${B}/teleconsulta/sala-espera/${token}`);
  await pp2.click('#tc-test');
  await pp2.waitForFunction(() => /funcionando/.test(document.getElementById('tc-test-status').textContent), null, { timeout: 10000 });
  await pp2.check('#tc-consent');
  await pp2.waitForFunction(() => !document.getElementById('tc-enter').disabled, null, { timeout: 10000 });
  await pp2.click('#tc-enter');
  await pr.waitForFunction(() => document.getElementById('tc-lobby').classList.contains('is-waiting') && !document.getElementById('tc-admit').closest('[hidden]'), null, { timeout: 15000 }).catch(() => {});
  ok(await pr.locator('#tc-admit').isVisible(), 'faixa compacta com "Admitir" aparece quando o paciente chega');
  ok(/^\(1\)/.test(await pr.title()), 'aviso também no título da aba ("(1) Paciente aguardando")');
  ok(/sala de espera/i.test(await pr.locator('#tc-placeholder-title').innerText()), 'vídeo diz que o paciente está na sala de espera');

  console.log('== Barra de controles: axe, alvos, teclado');
  if (AXE_SRC) {
    await pr.evaluate(AXE_SRC);
    const v = await pr.evaluate(async () => (await window.axe.run(['.tc-callbar', '#tc-lobby'], { runOnly: { type: 'tag', values: ['wcag2a', 'wcag2aa'] } })).violations.map(x => x.id + '×' + x.nodes.length));
    ok(v.length === 0, 'axe (WCAG 2 A/AA) sem violações na barra e na faixa de espera' + (v.length ? ': ' + v.join(', ') : ''));
  } else {
    console.log('  (axe-core não encontrado; seguem só as verificações manuais)');
  }
  const ctl = await pr.evaluate(() => [...document.querySelectorAll('.tc-callbar button, .tc-lobby button')].filter(e => e.offsetParent).map(e => { const r = e.getBoundingClientRect(); return { id: e.id || e.textContent.trim(), w: r.width, h: r.height, name: (e.getAttribute('aria-label') || e.textContent).trim() }; }));
  const small = ctl.filter(c => c.w < 44 || c.h < 44 || !c.name);
  ok(ctl.length >= 4 && small.length === 0, `controles com nome e alvo ≥44 px (${ctl.length} botões)` + (small.length ? ': ' + small.map(s => `${s.id} ${s.w|0}x${s.h|0}`).join(', ') : ''));
  ok(await pr.locator('#tc-focus').getAttribute('aria-pressed') === 'false' && await pr.locator('#tc-panel-toggle').getAttribute('aria-expanded') === 'true', 'botões de alternância com aria-pressed / aria-expanded');
  await pr.focus('#tc-focus'); await pr.keyboard.press('Enter');
  ok(await pr.locator('#tc-focus').getAttribute('aria-pressed') === 'true', 'modo foco liga pelo teclado');
  m = await vw();
  ok(m.r >= 0.85 && !(await panelOpen()), `modo foco: vídeo ocupa ${(m.r * 100).toFixed(0)}% (≥85%), painel recolhido`);
  await pr.keyboard.press('Enter');
  ok(await panelOpen(), 'sair do modo foco devolve o painel');

  console.log('== Chamada conectada (dois navegadores, mídia falsa)');
  await Promise.all([pr.waitForLoadState('load'), pr.click('#tc-admit')]);
  await pr.waitForTimeout(600);
  await pr.click('#tc-start');
  await pp2.waitForURL(/\/teleconsulta\/sala$/, { timeout: 20000 }).catch(() => {});
  const conn = await pr.waitForFunction(() => document.getElementById('tc-video-area').getAttribute('data-state') === 'connected' && document.getElementById('tc-remote').videoWidth > 0, null, { timeout: 30000 }).then(() => true, () => false);
  ok(conn, 'nutricionista vê o vídeo do paciente (estado "conectado")');
  ok(/Conectad/i.test(await pr.locator('#tc-chip').innerText()), 'selo "Conectado" no vídeo');
  ok(await pr.locator('#tc-pip').isVisible() && await pr.evaluate(() => document.getElementById('tc-local').videoWidth > 0), 'própria câmera em miniatura (PiP)');
  await pr.click('#tc-mic');
  ok(await pr.locator('#tc-mic').getAttribute('aria-pressed') === 'true', 'microfone desligado: aria-pressed=true');
  await pr.click('#tc-mic');
  const c0 = await pr.locator('#tc-pip').getAttribute('data-corner');
  await pr.click('#tc-pip-move');
  ok(await pr.locator('#tc-pip').getAttribute('data-corner') !== c0, 'miniatura muda de canto pelo botão');
  await pr.screenshot({ path: path.join(OUT, 'p5427-profissional-conectado-1280.png') });
  const pconn = await pp2.waitForFunction(() => { const a = document.getElementById('tc-video-area'); return a && a.getAttribute('data-state') === 'connected' && document.getElementById('tc-remote').videoWidth > 0; }, null, { timeout: 20000 }).then(() => true, () => false);
  ok(pconn, 'paciente vê o vídeo do nutricionista');
  const pm = await pp2.evaluate(() => { const v = document.getElementById('tc-video-area').getBoundingClientRect(); return v.width / document.documentElement.clientWidth; });
  ok(pm >= 0.98, `celular do paciente: vídeo na largura toda (${(pm * 100).toFixed(0)}%)`);
  const pctl = await pp2.evaluate(() => [...document.querySelectorAll('.tc-callbar button')].filter(e => e.offsetParent).map(e => { const r = e.getBoundingClientRect(); return r.width >= 44 && r.height >= 44 && r.bottom <= window.innerHeight + 1; }));
  ok(pctl.length >= 3 && pctl.every(Boolean), 'celular: controles na tela, alvos ≥44 px');
  await pp2.screenshot({ path: path.join(OUT, 'p5427-paciente-conectado-390.png') });

  console.log('== Larguras do nutricionista');
  for (const w of [390, 768, 1440, 1920]) {
    await pr.setViewportSize({ width: w, height: w > 500 ? 860 : 844 }); await pr.waitForTimeout(250);
    const r = await vw();
    const sw = await pr.evaluate(() => document.documentElement.scrollWidth - document.documentElement.clientWidth);
    const need = w < 768 ? 0.95 : (w < 1280 ? 0.9 : 0.6);
    ok(r.r >= need && sw <= 1 && r.h >= 180, `${w}px: vídeo ${(r.r * 100).toFixed(0)}% da largura, sem rolagem lateral`);
  }
  await pr.setViewportSize({ width: 768, height: 900 }); await pr.waitForTimeout(200);
  ok(!(await panelOpen()), '768: painel começa recolhido (gaveta)');
  await pr.click('#tc-panel-toggle'); await pr.waitForTimeout(400);
  ok(await panelOpen() && await pr.locator('#tc-panel-toggle').getAttribute('aria-expanded') === 'true', '768: gaveta abre pelo botão "Anotações"');
  await pr.keyboard.press('Escape'); await pr.waitForTimeout(400);
  ok(!(await panelOpen()), 'Esc fecha a gaveta');
  ok(/sem gravação/i.test(await pr.locator('body').innerText()), 'aviso de "sem gravação" continua na tela');

  await pr.setViewportSize({ width: 1280, height: 860 });
  await pr.click('#tc-end');
  await pr.waitForLoadState('load');
  ok(sq(`SELECT status FROM teleconsultation_sessions WHERE id=${sid}`) === 'ended' || /encerrad/i.test(await pr.locator('body').innerText()), 'encerrar finaliza a sessão');
  ok(errs.length === 0, 'sem erro de JavaScript' + (errs.length ? ': ' + errs.slice(0, 3).join(' | ') : ''));
  ok(csp.length === 0, 'CSP sem bloqueios' + (csp.length ? ': ' + csp.slice(0, 2).join(' | ') : ''));
  await b.close();
  console.log(`${res.filter(Boolean).length} PASS, ${res.filter(x => !x).length} FAIL`);
  process.exit(res.every(Boolean) ? 0 : 1);
})().catch(e => { console.log('  FAIL  exceção: ' + clean(e.message)); process.exit(1); });
