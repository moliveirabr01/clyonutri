// v54.61 NI2-830 (SEC-001): consentimento verificável do paciente antes da transcrição — validação ponta a ponta no navegador real.
// Chromium e Firefox; SQLite descartável com dados TESTE (seed "ni2830"); servidor php -S real; sem mocks. Imprime PASS/FAIL por verificação,
// grava evidências saneadas (token mascarado) em ni2830-evidence-<engine>.json e capturas em E2E_OUT.
const { chromium, firefox } = require('playwright');
const { execSync } = require('child_process');
const fs = require('fs'); const path = require('path');
const res = []; const ok = (c, l) => { res.push(!!c); console.log((c ? '  PASS  ' : '  FAIL  ') + l); };
let axe = null; try { axe = require(process.env.AXE_MODULE || 'axe-core'); } catch (e) {}
const OUT = process.env.E2E_OUT || '/tmp';
const mask = s => String(s).replace(/[A-Z2-7]{20}/g, 'TOKEN****').replace(/[a-f0-9]{32,64}/g, 'HEX****');
const CLAIM = /(consentimento ativo|aceite do paciente confirmado|paciente aceitou|autoriza[çc][ãa]o ativa|consentimento concedido)/i;

(async () => {
  const B = process.argv[2], DB = process.argv[3];
  const sq = q => execSync(`php ${process.env.E2E_SQ} ${DB} "${q.replace(/"/g, '\\"')}"`).toString().trim();
  const ids = JSON.parse(fs.readFileSync(DB + '.ids', 'utf8'));
  const { apptA1, apptB1, apptA2, apptC, patientA, patientB } = ids;
  const resetRL = () => sq('DELETE FROM teleconsultation_access_attempts');
  const resetState = () => {
    for (const t of ['consultation_evolution_drafts', 'consultation_transcription_segments', 'consultation_transcription_sessions', 'consultation_ai_consent_requests', 'consultation_ai_consents']) sq(`DELETE FROM ${t}`);
    sq(`UPDATE appointments SET patient_id=${patientA} WHERE id=${apptA2}`); resetRL();
  };
  const reqs = a => sq(`SELECT status FROM consultation_ai_consent_requests WHERE appointment_id=${a} ORDER BY id`).split('\n').filter(Boolean);
  const lastReq = (a, cols) => sq(`SELECT ${cols} FROM consultation_ai_consent_requests WHERE appointment_id=${a} ORDER BY id DESC LIMIT 1`).split('|');
  const segCount = a => +sq(`SELECT COUNT(*) FROM consultation_transcription_segments WHERE session_id IN (SELECT id FROM consultation_transcription_sessions WHERE appointment_id=${a})`);
  const sessStatus = a => sq(`SELECT status FROM consultation_transcription_sessions WHERE appointment_id=${a} ORDER BY id DESC LIMIT 1`);
  const shot = (p, n, full = false) => p.screenshot({ path: path.join(OUT, `ni2830-${n}.png`), fullPage: full });
  const axeRun = async (p, tag, include) => {
    if (!axe) { ok(false, `${tag}: axe-core indisponível`); return; }
    await p.evaluate(axe.source);
    const a = await p.evaluate(o => axe.run(o.inc ? { include: o.inc } : document, { runOnly: { type: 'tag', values: ['wcag2a', 'wcag2aa', 'wcag21a', 'wcag21aa', 'wcag22aa'] } }), { inc: include || null });
    fs.writeFileSync(path.join(OUT, `ni2830-axe-${tag.replace(/[^a-z0-9]+/gi, '-')}.json`), JSON.stringify({ url: mask(p.url()), violations: a.violations }, null, 2));
    ok(a.violations.length === 0, `${tag}: axe WCAG 2.2 A/AA sem violações` + (a.violations.length ? ': ' + a.violations.map(v => v.id + '×' + v.nodes.length).join(', ') : ''));
  };

  for (const [bt, engine] of [['chromium', chromium], ['firefox', firefox]]) {
    console.log(`== ${bt}`);
    resetState();
    const b = await engine.launch();
    const ev = []; const errs = []; const bad = [];
    const watch = p => { p.on('pageerror', e => errs.push(e.message)); p.on('response', r => { if (r.status() >= 500) bad.push(r.status() + ' ' + mask(r.url().replace(B, ''))); }); };
    const login = async (ctx, u, c, to) => { const p = await ctx.newPage(); watch(p); await p.goto(B + `/__teste_login?u=${u}&c=${c}&v=0&to=${encodeURIComponent(to)}`, { waitUntil: 'load' }); return p; };
    // chamada JSON/POST feita DENTRO do navegador (cookies e Origin reais); csrf: 'auto' lê data-csrf da sala, string = token, null = sem token
    const call = async (p, step, method, url, { data = {}, csrf = 'auto', json = false } = {}) => {
      const r = await p.evaluate(async ({ method, url, data, csrf, json }) => {
        const root = document.getElementById('cn-transcription-module');
        const tok = csrf === 'auto' ? (root ? root.dataset.csrf : '') : csrf;
        const headers = { Accept: 'application/json' }; let body;
        if (method !== 'GET') {
          if (json) { headers['Content-Type'] = 'application/json'; body = JSON.stringify({ ...data, ...(tok ? { _csrf_token: tok } : {}) }); }
          else { const fd = new URLSearchParams(); for (const [k, v] of Object.entries(data)) fd.append(k, v); if (tok) fd.append('_csrf_token', tok); body = fd; }
          if (tok) headers['X-CSRF-Token'] = tok;
        }
        const x = await fetch(url, { method, headers, body }); const t = await x.text(); let j = null; try { j = JSON.parse(t); } catch (e) {}
        return { status: x.status, json: j, text: t.slice(0, 400) };
      }, { method, url, data, csrf, json });
      ev.push({ engine: bt, step, request: `${method} ${mask(url)}`, csrf: csrf === null ? 'ausente' : (csrf === 'auto' ? 'token da sala' : 'token informado'), status: r.status, response: mask(JSON.stringify(r.json ?? r.text)).slice(0, 300) });
      return r;
    };
    const ap = id => `/api/consulta/${id}/transcription`;
    const waitState = (p, st, ms = 16000) => p.waitForFunction(s => document.getElementById('stt-consent-state')?.dataset.state === s, st, { timeout: ms }).then(() => true).catch(() => false);
    const roomText = p => p.locator('#cn-transcription-module').innerText();
    const csrfOf = async p => p.evaluate(() => document.getElementById('cn-transcription-module')?.dataset.csrf || document.querySelector('input[name="_csrf_token"]')?.value || '');
    const pubCsrf = async p => { await p.goto(B + '/paciente/app', { waitUntil: 'load' }); return p.evaluate(() => document.querySelector('form[action="/paciente/app/login"] input[name="_csrf_token"]')?.value || ''); };
    const linkOf = async p => p.locator('#stt-consent-link').inputValue();

    // ============ contextos ============
    const prof = await b.newContext({ viewport: { width: 1280, height: 1000 }, locale: 'pt-BR' });
    const pRoom = await login(prof, 1, 1, `/consulta/${apptA1}`);
    await pRoom.waitForSelector('#cn-transcription-module');

    // ============ CRITÉRIO 1 + 5: profissional sozinho não concede; UI não afirma consentimento ============
    console.log(`-- ${bt}: critério 1 e 5 (sem aceite)`);
    await pRoom.waitForFunction(() => document.getElementById('stt-consent-state')?.dataset.state === 'none', null, { timeout: 8000 }).catch(() => {});
    const t0 = await roomText(pRoom);
    ok(await pRoom.locator('#btn-grant-consent').count() === 0 && !/Registrar consentimento afirmativo/.test(t0), `${bt} C1: não existe mais o botão "Registrar consentimento afirmativo do paciente"`);
    ok((await pRoom.locator('#stt-consent-state').textContent()).trim() === 'Aguardando aceite do paciente. Nenhum áudio é captado.', `${bt} C5: interface diz "Aguardando aceite do paciente" sem aceite`);
    ok((await pRoom.locator('#stt-status-badge').textContent()).trim() === 'Aguardando aceite do paciente', `${bt} C5: selo da sala = "Aguardando aceite do paciente"`);
    ok(!CLAIM.test(t0), `${bt} C5: nenhum texto da sala afirma consentimento/aceite (sem evidência)`);
    ok(await pRoom.locator('#stt-controls-panel').isHidden(), `${bt} C1: controles de captação ocultos sem aceite`);
    await shot(pRoom, `${bt}-01-sem-aceite`, true);
    const g1 = await call(pRoom, 'C1 grant antigo', 'POST', `${ap(apptA1)}/consent`, { data: { version: 'v1.0' } });
    ok(g1.status === 403 && !(g1.json && g1.json.success), `${bt} C1: profissional chama o endpoint antigo /consent com CSRF válido: 403, sem sucesso (${g1.status})`);
    const g2 = await call(pRoom, 'C1 grant forjado', 'POST', `${ap(apptA1)}/consent`, { data: { version: 'v9', status: 'granted', patient_id: patientA, decision_mechanism: 'patient_link' }, json: true });
    ok(g2.status === 403, `${bt} C1: tentativa forjada (status=granted, mecanismo, JSON) também 403 (${g2.status})`);
    ok(reqs(apptA1).length === 0 && +sq('SELECT COUNT(*) FROM consultation_ai_consents') === 0, `${bt} C1: banco sem nenhum aceite/pedido depois das tentativas`);
    const s0 = await call(pRoom, 'C1 start sem aceite', 'POST', `${ap(apptA1)}/start`, { data: { mode: 'live' } });
    ok(s0.status === 403 && s0.json.requires_consent === true, `${bt} C3: iniciar sem aceite → 403 requires_consent (${s0.status})`);
    ok(+sq('SELECT COUNT(*) FROM consultation_transcription_sessions') === 0, `${bt} C3: nenhuma sessão criada sem aceite`);
    const sg0 = await call(pRoom, 'C3 segmento sem sessão', 'POST', `${ap(apptA1)}/segment`, { data: { sequence_number: 1, text: 'TESTE sem aceite' } });
    ok(sg0.status !== 200 && segCount(apptA1) === 0, `${bt} C3: segmento sem sessão/aceite recusado (${sg0.status}) e nada gravado`);
    const st0 = await call(pRoom, 'C1 status', 'GET', `${ap(apptA1)}/session`);
    ok(st0.status === 200 && st0.json.consent_active === false && st0.json.consent.state === 'none', `${bt} C5: API de estado diz consent_active=false`);
    await axeRun(pRoom, `${bt} sala painel de aceite (sem aceite)`, ['#cn-transcription-module']);

    // ============ CSRF e permissões do lado do profissional ============
    console.log(`-- ${bt}: CSRF e permissões`);
    const c1 = await call(pRoom, 'CSRF ausente', 'POST', `${ap(apptA1)}/consent/request`, { csrf: null });
    const c2 = await call(pRoom, 'CSRF errado', 'POST', `${ap(apptA1)}/consent/request`, { csrf: 'x'.repeat(64) });
    ok(c1.status === 403 && /CSRF/.test(c1.text) && c2.status === 403 && /CSRF/.test(c2.text), `${bt} CSRF: pedido de aceite sem token e com token errado → 403 "CSRF" (${c1.status}/${c2.status})`);
    ok(reqs(apptA1).length === 0, `${bt} CSRF: nenhum pedido criado`);
    const anon = await b.newContext(); const pa = await anon.newPage(); await pa.goto(B + '/login');
    const an = await call(pa, 'sem login', 'POST', `${ap(apptA1)}/consent/request`, { csrf: 'x' });
    ok(an.status === 401 || an.status === 403, `${bt} permissões: sem login não pede aceite (${an.status})`);
    const recCtx = await b.newContext(); const pRec = await login(recCtx, ids.reception, 1, '/agenda'); const recCsrf = await csrfOf(pRec);
    ok(recCsrf.length === 64, `${bt} permissões: recepção logada (token CSRF lido da /agenda)`);
    const r1 = await call(pRec, 'recepção pede', 'POST', `${ap(apptA1)}/consent/request`, { csrf: recCsrf });
    const r2 = await call(pRec, 'recepção grant', 'POST', `${ap(apptA1)}/consent`, { csrf: recCsrf });
    const r3 = await call(pRec, 'recepção status', 'GET', `${ap(apptA1)}/session`);
    const r4 = await call(pRec, 'recepção start', 'POST', `${ap(apptA1)}/start`, { csrf: recCsrf });
    ok([r1, r2, r3, r4].every(r => r.status === 403 && /não autorizado para este perfil/i.test(r.text)), `${bt} permissões: recepção recebe 403 "Acesso não autorizado para este perfil" em pedir/conceder/estado/iniciar (${[r1, r2, r3, r4].map(r => r.status)})`);
    ok(reqs(apptA1).length === 0 && sessStatus(apptA1) === '', `${bt} permissões: recepção não alterou nada no banco`);
    const bCtx = await b.newContext(); const pB = await login(bCtx, 2, 2, '/dashboard'); const bCsrf = await csrfOf(pB);
    const x1 = await call(pB, 'clínica B pede em A1', 'POST', `${ap(apptA1)}/consent/request`, { csrf: bCsrf });
    const x2 = await call(pB, 'clínica B estado A1', 'GET', `${ap(apptA1)}/session`);
    const x3 = await call(pB, 'clínica B start A1', 'POST', `${ap(apptA1)}/start`, { csrf: bCsrf });
    ok([x1, x2, x3].every(r => r.status === 404) && reqs(apptA1).length === 0, `${bt} permissões/tenant: profissional da clínica B não pede, não lê e não inicia em atendimento da clínica A (${[x1, x2, x3].map(r => r.status)})`);

    // ============ CRITÉRIO 1/2: pedido pelo profissional (UI real) ============
    console.log(`-- ${bt}: pedido de aceite pela interface`);
    await pRoom.click('#btn-request-consent');
    await pRoom.waitForSelector('#stt-consent-link-box:not([hidden])');
    const linkA1 = await linkOf(pRoom);
    ok(/\/consentimento-transcricao\/[A-Z2-7]{20}$/.test(linkA1), `${bt} C1: link de aceite gerado (20 caracteres base32): ${mask(linkA1)}`);
    ok(await pRoom.locator('#stt-consent-qr canvas, #stt-consent-qr img').count() > 0, `${bt} C2: QR do link renderizado`);
    ok(await waitState(pRoom, 'pending', 6000) && (await pRoom.locator('#stt-consent-state').textContent()).startsWith('Aguardando aceite do paciente'), `${bt} C5: com pedido pendente a interface continua "Aguardando aceite do paciente"`);
    ok(!CLAIM.test(await roomText(pRoom)) && await pRoom.locator('#stt-controls-panel').isHidden(), `${bt} C5: pedido pendente não afirma aceite e não libera controles`);
    ok(reqs(apptA1).join() === 'pending' && !sq('SELECT * FROM consultation_ai_consent_requests').includes(linkA1.split('/').pop()), `${bt} C2: pedido pendente no banco; token em claro não está em nenhuma coluna (só hash)`);
    await shot(pRoom, `${bt}-02-pedido-pendente`, true);

    // ============ CRITÉRIO 1: o profissional não aceita com o próprio link ============
    console.log(`-- ${bt}: profissional abre o link na própria sessão`);
    const pSelf = await prof.newPage(); watch(pSelf);
    const selfResp = await pSelf.goto(linkA1);
    ok(selfResp.status() === 403 && await pSelf.locator('[data-screen="professional_session"]').count() === 1, `${bt} C1: link aberto no navegador com sessão de profissional → 403 "Abra este link no aparelho do paciente"`);
    ok(await pSelf.locator('[data-testid="consent-accept"]').count() === 0, `${bt} C1: sem botão de aceitar na sessão do profissional`);
    const selfPost = await call(pRoom, 'profissional posta aceite no link', 'POST', new URL(linkA1).pathname, { data: { decision: 'accept', terms_sha256: sq("SELECT terms_sha256 FROM consultation_ai_consent_requests ORDER BY id DESC LIMIT 1") } });
    ok(selfPost.status === 403 && reqs(apptA1).join() === 'pending', `${bt} C1: POST de aceite com a sessão do profissional → 403 e o pedido segue pendente (${selfPost.status})`);
    await pSelf.close();

    // ============ CRITÉRIO 2: paciente A aceita pelo próprio aparelho ============
    console.log(`-- ${bt}: paciente A (aparelho próprio, 375px)`);
    const patA = await b.newContext({ viewport: { width: 375, height: 812 }, locale: 'pt-BR' });
    const pA = await patA.newPage(); watch(pA);
    const viewResp = await pA.goto(linkA1);
    ok(viewResp.status() === 200 && await pA.locator('[data-screen="ask"]').count() === 1, `${bt} C2: paciente abre o link e vê a tela de decisão`);
    const pt = await pA.locator('main').innerText();
    ok(['Finalidade', 'Dados captados', 'Duração e retenção', 'Seus direitos', 'Como revogar'].every(s => pt.includes(s)), `${bt} C2: página mostra finalidade, dados captados, duração/retenção, direitos e como revogar`);
    ok(/v2\.0-2026-11/.test(pt) && await pA.locator('[data-testid="terms-version"]').textContent() === 'v2.0-2026-11', `${bt} C2: página mostra a versão do texto (v2.0-2026-11)`);
    ok(/Olá, Ana\./.test(pt) && !/Ana TESTE|Paciente A|@|ni2830|CPF|\d{3}\.\d{3}\.\d{3}/.test(pt), `${bt} C2: só o primeiro nome do paciente aparece; sem sobrenome, e-mail, CPF ou dado clínico`);
    ok(await pA.locator('[data-testid="consent-accept"]').isVisible() && await pA.locator('[data-testid="consent-reject"]').isVisible(), `${bt} C2: botões Aceito / Não aceito visíveis`);
    ok(reqs(apptA1).join() === 'pending', `${bt} C2: só abrir a página não registra aceite`);
    await axeRun(pA, `${bt} página do paciente (375px)`);
    await shot(pA, `${bt}-03-paciente-pergunta`, true);
    // adulteração: sem CSRF, com CSRF errado, texto adulterado
    const patCsrf = await pA.evaluate(() => document.querySelector('input[name="_csrf_token"]').value);
    const patSha = await pA.evaluate(() => document.querySelector('input[name="terms_sha256"]').value);
    const postPat = (p, step, url, data) => p.evaluate(async ({ url, data }) => { const x = await fetch(url, { method: 'POST', headers: { Accept: 'text/html' }, body: new URLSearchParams(data) }); return { status: x.status, text: (await x.text()).slice(0, 2000) }; }, { url, data }).then(r => { ev.push({ engine: bt, step, request: 'POST ' + mask(url), status: r.status, response: mask(r.text.replace(/\s+/g, ' ')).slice(0, 160) }); return r; });
    const lp = new URL(linkA1).pathname;
    const p1 = await postPat(pA, 'paciente sem CSRF', lp, { decision: 'accept', terms_sha256: patSha });
    const p2 = await postPat(pA, 'paciente CSRF errado', lp, { _csrf_token: 'f'.repeat(64), decision: 'accept', terms_sha256: patSha });
    ok(p1.status === 403 && p2.status === 403 && reqs(apptA1).join() === 'pending', `${bt} CSRF: aceite do paciente sem token e com token errado → 403 e continua pendente (${p1.status}/${p2.status})`);
    const p3 = await postPat(pA, 'paciente texto adulterado', lp, { _csrf_token: patCsrf, decision: 'accept', terms_sha256: '0'.repeat(64) });
    ok(p3.status === 410 && reqs(apptA1).join() === 'pending', `${bt} C2: aceite com hash de texto adulterado é recusado (${p3.status}) e o pedido continua pendente`);
    const p4 = await postPat(pA, 'paciente decisão inválida', lp, { _csrf_token: patCsrf, decision: 'granted', terms_sha256: patSha });
    ok(p4.status === 410 && reqs(apptA1).join() === 'pending', `${bt} C2: decisão fora de aceitar/recusar recusada (${p4.status})`);
    // aceite real, clicando
    await pA.click('[data-testid="consent-accept"]');
    await pA.waitForSelector('[data-testid="consent-accepted"]');
    ok(reqs(apptA1).join() === 'accepted', `${bt} C2: paciente clica "Aceito": pedido vira accepted`);
    const evd = lastReq(apptA1, "clinic_id,appointment_id,patient_id,terms_version,length(terms_sha256),decision_mechanism,request_origin,decided_at_utc IS NOT NULL,length(decision_ip_hash),decision_ip_hash,length(decision_user_agent),valid_until_utc IS NOT NULL,requested_by_user_id");
    ok(evd[0] === '1' && +evd[1] === apptA1 && +evd[2] === patientA && evd[3] === 'v2.0-2026-11' && evd[4] === '64' && evd[5] === 'patient_link' && evd[6] === 'professional_session' && evd[7] === '1' && evd[11] === '1', `${bt} C2: evidência: clínica, atendimento, paciente, versão, SHA do texto, mecanismo=patient_link, origem do pedido, timestamp, validade`);
    ok(evd[8] === '64' && evd[9] !== '127.0.0.1' && +evd[10] > 0 && +evd[10] <= 120, `${bt} C2: IP só como hash (64) e user agent truncado (≤120); sem IP em claro`);
    const aud = sq("SELECT action FROM audit_logs WHERE action LIKE 'AI_TRANSCRIPTION_CONSENT%' ORDER BY id").split('\n');
    ok(aud.includes('AI_TRANSCRIPTION_CONSENT_REQUESTED') && aud.includes('AI_TRANSCRIPTION_CONSENT_ACCEPTED_BY_PATIENT') && aud.includes('AI_TRANSCRIPTION_CONSENT_GRANT_DENIED'), `${bt} C2: auditoria: tentativa negada do profissional, pedido e aceite pelo paciente`);
    ok(!sq("SELECT details FROM audit_logs WHERE action LIKE 'AI_TRANSCRIPTION_CONSENT%'").match(/Ana|TESTE Paciente|[A-Z2-7]{20}/), `${bt} C2: auditoria sem nome do paciente nem token`);
    await shot(pA, `${bt}-04-paciente-aceitou`, true);
    await axeRun(pA, `${bt} página do paciente aceito (375px)`);

    // ============ CRITÉRIO 5: a UI do profissional só afirma o aceite depois da evidência ============
    ok(await waitState(pRoom, 'accepted'), `${bt} C5: sala do profissional passa a "accepted" sozinha (sondagem), sem recarregar`);
    const t1 = await roomText(pRoom);
    ok(/Paciente aceitou pelo link/.test(t1) && /v2\.0-2026-11/.test(t1) && await pRoom.locator('#stt-controls-panel').isVisible(), `${bt} C5: agora a sala mostra "Paciente aceitou pelo link", a versão e libera os controles`);
    await shot(pRoom, `${bt}-05-sala-aceite-confirmado`, true);

    // ============ início da captação e segmentos só com aceite ============
    await pRoom.click('#btn-start-stt');
    await pRoom.waitForFunction(() => /Captando/.test(document.getElementById('stt-status-badge').textContent), null, { timeout: 8000 }).catch(() => {});
    ok(sessStatus(apptA1) === 'capturing', `${bt} C3: com aceite válido, a captação inicia (sessão capturing)`);
    const sgA = await call(pRoom, 'segmento com aceite', 'POST', `${ap(apptA1)}/segment`, { data: { sequence_number: 1, text: 'TESTE trecho um da consulta A1', speaker: 'speaker_uncertain', is_final: 1 } });
    ok(sgA.status === 200 && segCount(apptA1) === 1, `${bt} C3: segmento é gravado com aceite vigente (${sgA.status})`);

    // ============ CRITÉRIO 4: consulta A/B, paciente A/B ============
    console.log(`-- ${bt}: isolamento entre consultas e pacientes`);
    const sA2 = await call(pRoom, 'start A2 (mesmo paciente)', 'POST', `${ap(apptA2)}/start`, { data: { mode: 'live' } });
    ok(sA2.status === 403 && sessStatus(apptA2) === '', `${bt} C4: consulta A2 do MESMO paciente não herda o aceite da A1 (403, sem sessão)`);
    const sB1 = await call(pRoom, 'start B1 (outro paciente)', 'POST', `${ap(apptB1)}/start`, { data: { mode: 'live' } });
    ok(sB1.status === 403 && sessStatus(apptB1) === '', `${bt} C4: consulta B1 do paciente B não herda o aceite do paciente A (403)`);
    const stB1 = await call(pRoom, 'status B1', 'GET', `${ap(apptB1)}/session`);
    ok(stB1.json.consent_active === false && stB1.json.consent.state === 'none', `${bt} C4: estado da B1 segue sem aceite`);
    // replay do mesmo link: mesma aba, e outro navegador (link vazado)
    const rp1 = await postPat(pA, 'replay no mesmo contexto', lp, { _csrf_token: patCsrf, decision: 'accept', terms_sha256: patSha });
    const thief = await b.newContext(); const pT = await thief.newPage(); watch(pT);
    const tr = await pT.goto(linkA1);
    const tCsrf = await pubCsrf(pT);
    const rp2 = await postPat(pT, 'replay em outro navegador', lp, { _csrf_token: tCsrf, decision: 'accept', terms_sha256: patSha });
    ok(rp1.status === 410 && tr.status() === 410 && rp2.status === 410, `${bt} C4: replay do token usado → 410 no mesmo contexto, no GET de outro navegador e no POST de outro navegador (${rp1.status}/${tr.status()}/${rp2.status})`);
    const rej = await postPat(pT, 'replay recusar', lp, { _csrf_token: tCsrf, decision: 'reject', terms_sha256: patSha });
    ok(rej.status === 410 && reqs(apptA1).join() === 'accepted', `${bt} C4: replay tentando recusar também falha; o aceite vigente não mudou`);
    const pT2 = await thief.newPage(); await pT2.goto(linkA1);
    ok(!/Ana|TESTE/.test(await pT2.locator('body').innerText()), `${bt} C4: link usado não revela nenhum dado do paciente`);
    await thief.close();
    // paciente B: link de B1 não vale para A1 e vice-versa
    const reqB = await call(pRoom, 'pedido B1', 'POST', `${ap(apptB1)}/consent/request`);
    const linkB1 = reqB.json.link;
    ok(reqB.status === 200 && /consentimento-transcricao\/[A-Z2-7]{20}$/.test(linkB1) && linkB1 !== linkA1, `${bt} C4: pedido do paciente B gera outro link`);
    const patB = await b.newContext({ viewport: { width: 1280, height: 900 }, locale: 'pt-BR' }); const pBp = await patB.newPage(); watch(pBp);
    await pBp.goto(linkB1);
    ok(/Olá, Bruno\./.test(await pBp.locator('main').innerText()), `${bt} C4: a página do link B mostra o paciente B (Bruno), não o A`);
    await pBp.click('[data-testid="consent-reject"]'); await pBp.waitForSelector('[data-testid="consent-rejected"]');
    ok(reqs(apptB1).join() === 'rejected' && reqs(apptA1).join() === 'accepted', `${bt} C3: paciente B recusa: B1 rejected, A1 continua accepted`);
    const sB1b = await call(pRoom, 'start B1 após recusa', 'POST', `${ap(apptB1)}/start`, { data: { mode: 'live' } });
    ok(sB1b.status === 403 && sessStatus(apptB1) === '', `${bt} C3: recusa do paciente bloqueia a captação (403, sem sessão)`);
    const pRoomB = await prof.newPage(); watch(pRoomB); await pRoomB.goto(B + `/consulta/${apptB1}`); await pRoomB.waitForSelector('#cn-transcription-module');
    ok(await waitState(pRoomB, 'rejected', 8000) && /O paciente recusou/.test(await pRoomB.locator('#stt-consent-state').textContent()) && !CLAIM.test(await roomText(pRoomB)), `${bt} C5: sala da B1 diz "O paciente recusou" e não afirma aceite`);
    await shot(pRoomB, `${bt}-06-sala-recusa`, true);
    await pRoomB.close();

    // ============ CRITÉRIO 3: revogação pelo paciente bloqueia gravação e novos segmentos ============
    console.log(`-- ${bt}: revogação pelo paciente`);
    await pA.click('[data-testid="consent-revoke"]');
    await pA.waitForSelector('[data-testid="consent-revoked"]');
    ok(reqs(apptA1).join() === 'revoked' && lastReq(apptA1, 'revoked_by')[0] === 'patient', `${bt} C3: paciente revoga na própria página: revoked / revoked_by=patient`);
    ok(sessStatus(apptA1) === 'paused', `${bt} C3: a revogação pausa a sessão aberta na hora (servidor)`);
    const sgR = await call(pRoom, 'segmento após revogação', 'POST', `${ap(apptA1)}/segment`, { data: { sequence_number: 2, text: 'TESTE depois da revogação', speaker: 'speaker_uncertain', is_final: 1 } });
    ok(sgR.status === 403 && segCount(apptA1) === 1, `${bt} C3: novo segmento após revogação → 403 e nada gravado (${sgR.status}, segmentos=${segCount(apptA1)})`);
    const rsR = await call(pRoom, 'retomar após revogação', 'POST', `${ap(apptA1)}/resume`);
    const stR = await call(pRoom, 'iniciar após revogação', 'POST', `${ap(apptA1)}/start`, { data: { mode: 'live' } });
    ok(rsR.status === 403 && stR.status === 403 && sessStatus(apptA1) === 'paused', `${bt} C3: retomar e iniciar após revogação → 403 (${rsR.status}/${stR.status})`);
    ok(await waitState(pRoom, 'revoked'), `${bt} C5: sala do profissional mostra "revoked" sozinha`);
    const t2 = await roomText(pRoom);
    ok(/Aceite revogado pelo paciente/.test(t2) && await pRoom.locator('#stt-controls-panel').isHidden() && !CLAIM.test(t2.replace(/Paciente aceitou[^.]*\./g, '')), `${bt} C5: sala diz "Aceite revogado pelo paciente", esconde os controles e não afirma consentimento`);
    await shot(pRoom, `${bt}-07-sala-revogado`, true);
    const rv2 = await postPat(pA, 'replay revogar', '/consentimento-transcricao-revogar', { _csrf_token: patCsrf, revoke_token: 'a'.repeat(32) });
    ok(rv2.status === 410, `${bt} C3: código de revogação inventado → 410`);

    // ============ PORTAL DO PACIENTE (autenticado com a própria senha) ============
    console.log(`-- ${bt}: portal do paciente`);
    const reqP = await call(pRoom, 'novo pedido A1 (portal)', 'POST', `${ap(apptA1)}/consent/request`);
    ok(reqP.status === 200 && reqP.json.created === true, `${bt} portal: novo pedido para A1 depois da revogação`);
    const patPortalCtx = await b.newContext({ viewport: { width: 1280, height: 900 }, locale: 'pt-BR' }); const pp = await patPortalCtx.newPage(); watch(pp);
    const noSess = await pp.goto(B + '/paciente/consentimento');
    ok(/\/paciente\/app/.test(pp.url()), `${bt} portal: sem login, /paciente/consentimento volta ao login do portal`);
    await pp.click('#tab-btn-login');
    await pp.fill('#login-input', 'ana.ni2830@teste.invalid'); await pp.fill('#login-pass', 'Teste-Paciente-123');
    await pp.locator('form[action="/paciente/app/login"] button[type="submit"]').click(); await pp.waitForLoadState();
    await pp.goto(B + '/paciente/consentimento');
    ok(await pp.locator('[data-testid="portal-consent-item"][data-state="pending"]').count() === 1, `${bt} portal: paciente A logada vê 1 pedido pendente (o da A1)`);
    const bPending = sq(`SELECT id FROM consultation_ai_consent_requests WHERE appointment_id=${apptB1} ORDER BY id DESC LIMIT 1`);
    const aReqId = sq(`SELECT id FROM consultation_ai_consent_requests WHERE appointment_id=${apptA1} ORDER BY id DESC LIMIT 1`);
    // paciente A tenta decidir o pedido de OUTRO paciente (B1 foi recusado; cria novo pedido pendente do B para o teste)
    const reqB2 = await call(pRoom, 'novo pedido B1 (cruzado)', 'POST', `${ap(apptB1)}/consent/request`);
    const bReq2 = sq(`SELECT id FROM consultation_ai_consent_requests WHERE appointment_id=${apptB1} AND status='pending' ORDER BY id DESC LIMIT 1`);
    const shaPortal = await pp.evaluate(() => document.querySelector('form[action$="/decidir"] input[name="terms_sha256"]').value);
    const portCsrf = await pp.evaluate(() => document.querySelector('form[action$="/decidir"] input[name="_csrf_token"]').value);
    const cross = await postPat(pp, 'portal A decide pedido do B', `/paciente/consentimento/${bReq2}/decidir`, { _csrf_token: portCsrf, decision: 'accept', terms_sha256: shaPortal });
    ok(/não encontrado para este paciente/i.test(cross.text) && sq(`SELECT status FROM consultation_ai_consent_requests WHERE id=${bReq2}`) === 'pending', `${bt} C4: paciente A logada NÃO decide o pedido do paciente B (pedido B segue pending)`);
    const noCsrfPortal = await postPat(pp, 'portal sem CSRF', `/paciente/consentimento/${aReqId}/decidir`, { decision: 'accept', terms_sha256: shaPortal });
    ok(noCsrfPortal.status === 403 && sq(`SELECT status FROM consultation_ai_consent_requests WHERE id=${aReqId}`) === 'pending', `${bt} CSRF: portal sem token → 403 e o pedido segue pendente`);
    await shot(pp, `${bt}-08-portal-pendente`, true);
    await pp.locator('[data-testid="portal-consent-item"][data-state="pending"] [data-testid="portal-consent-accept"]').click();
    await pp.waitForSelector('[data-testid="portal-consent-message"]', );
    ok(/Aceite registrado/.test(await pp.locator('[data-testid="portal-consent-message"]').textContent()) && sq(`SELECT status||'|'||decision_mechanism FROM consultation_ai_consent_requests WHERE id=${aReqId}`) === 'accepted|patient_portal', `${bt} C2: paciente aceita pelo portal (mecanismo patient_portal)`);
    await pRoom.reload(); // o pedido do portal foi criado por API (sem o botão da sala); a sala só faz polling enquanto há pedido/aceite que ela mesma abriu
    ok(await waitState(pRoom, 'accepted') && /Paciente aceitou pelo portal do paciente/.test(await pRoom.locator('#stt-consent-state').textContent()), `${bt} C5: sala mostra "Paciente aceitou pelo portal do paciente"`);
    const stP = await call(pRoom, 'iniciar após novo aceite', 'POST', `${ap(apptA1)}/start`, { data: { mode: 'live' } });
    ok(stP.status === 200 && sessStatus(apptA1) === 'capturing', `${bt} C3: com novo aceite a mesma sessão é retomada (capturing)`);
    const sgP = await call(pRoom, 'segmento após novo aceite', 'POST', `${ap(apptA1)}/segment`, { data: { sequence_number: 2, text: 'TESTE segundo trecho após novo aceite', speaker: 'speaker_uncertain', is_final: 1 } });
    ok(sgP.status === 200 && segCount(apptA1) === 2, `${bt} C3: segmento volta a ser gravado com o novo aceite (${sgP.status})`);
    await pp.locator('[data-testid="portal-consent-revoke"]').click(); await pp.waitForSelector('[data-testid="portal-consent-message"]');
    ok(sq(`SELECT status||'|'||revoked_by FROM consultation_ai_consent_requests WHERE id=${aReqId}`) === 'revoked|patient' && sessStatus(apptA1) === 'paused', `${bt} C3: paciente revoga pelo portal: revoked/patient e sessão pausada`);
    const sgP2 = await call(pRoom, 'segmento após revogar no portal', 'POST', `${ap(apptA1)}/segment`, { data: { sequence_number: 3, text: 'TESTE após revogar no portal', speaker: 'speaker_uncertain', is_final: 1 } });
    ok(sgP2.status === 403 && segCount(apptA1) === 2, `${bt} C3: segmento após revogar no portal → 403 (${sgP2.status}), segmentos=${segCount(apptA1)}`);

    // ============ CRITÉRIO 3/4: expiração e inconsistência (consulta A2) ============
    console.log(`-- ${bt}: expiração e inconsistência de paciente/consulta`);
    const qA2 = await call(pRoom, 'pedido A2', 'POST', `${ap(apptA2)}/consent/request`);
    const linkA2 = qA2.json.link;
    sq(`UPDATE consultation_ai_consent_requests SET expires_at_utc=datetime('now','-1 minute') WHERE appointment_id=${apptA2}`);
    const exCtx = await b.newContext(); const pEx = await exCtx.newPage(); watch(pEx);
    const exResp = await pEx.goto(linkA2);
    ok(exResp.status() === 410 && await pEx.locator('[data-screen="unavailable"]').count() === 1 && !/Ana|TESTE/.test(await pEx.locator('body').innerText()), `${bt} C3: link expirado → 410 "Link indisponível", sem dados do paciente`);
    const exCsrf = await pubCsrf(pEx);
    const exPost = await postPat(pEx, 'POST em link expirado', new URL(linkA2).pathname, { _csrf_token: exCsrf, decision: 'accept', terms_sha256: sq(`SELECT terms_sha256 FROM consultation_ai_consent_requests WHERE appointment_id=${apptA2} LIMIT 1`) });
    ok(exPost.status === 410 && reqs(apptA2).join() === 'pending', `${bt} C3: aceitar com token expirado → 410 e nada vira accepted (${exPost.status}; status=${reqs(apptA2)})`);
    const stEx = await call(pRoom, 'estado A2 expirado', 'GET', `${ap(apptA2)}/session`);
    const sEx = await call(pRoom, 'start A2 expirado', 'POST', `${ap(apptA2)}/start`, { data: { mode: 'live' } });
    ok(stEx.json.consent.state === 'expired' && stEx.json.consent_active === false && sEx.status === 403, `${bt} C3: API diz "expired" e start é negado (${sEx.status})`);
    const pRoomA2 = await prof.newPage(); watch(pRoomA2); await pRoomA2.goto(B + `/consulta/${apptA2}`); await pRoomA2.waitForSelector('#cn-transcription-module');
    ok(await waitState(pRoomA2, 'expired', 8000) && !CLAIM.test(await roomText(pRoomA2)), `${bt} C5: sala da A2 mostra "expirou" e não afirma aceite`);
    await shot(pRoomA2, `${bt}-09-sala-expirado`, true);
    // aceite vigente que expira (6 h) bloqueia segmentos
    const qA2b = await call(pRoom, 'novo pedido A2', 'POST', `${ap(apptA2)}/consent/request`);
    await exCtx.close(); const ex2 = await b.newContext(); const pEx2 = await ex2.newPage(); watch(pEx2);
    await pEx2.goto(qA2b.json.link); await pEx2.click('[data-testid="consent-accept"]'); await pEx2.waitForSelector('[data-testid="consent-accepted"]');
    const stA2 = await call(pRoom, 'start A2 com aceite', 'POST', `${ap(apptA2)}/start`, { data: { mode: 'live' } });
    ok(stA2.status === 200 && sessStatus(apptA2) === 'capturing', `${bt} C3: A2 com aceite próprio inicia (capturing)`);
    const sgA2 = await call(pRoom, 'segmento A2', 'POST', `${ap(apptA2)}/segment`, { data: { sequence_number: 1, text: 'TESTE trecho da consulta A2', speaker: 'speaker_uncertain', is_final: 1 } });
    ok(sgA2.status === 200 && segCount(apptA2) === 1 && segCount(apptA1) === 2, `${bt} C4: segmento de A2 fica só na sessão de A2 (A1 segue com 2)`);
    sq(`UPDATE consultation_ai_consent_requests SET valid_until_utc=datetime('now','-1 minute') WHERE appointment_id=${apptA2} AND status='accepted'`);
    const sgEx = await call(pRoom, 'segmento com aceite vencido', 'POST', `${ap(apptA2)}/segment`, { data: { sequence_number: 2, text: 'TESTE aceite vencido', speaker: 'speaker_uncertain', is_final: 1 } });
    ok(sgEx.status === 403 && segCount(apptA2) === 1 && sessStatus(apptA2) === 'paused', `${bt} C3: aceite com validade vencida → segmento 403, sessão pausada (${sgEx.status})`);
    // inconsistência paciente/consulta
    const qA2c = await call(pRoom, 'pedido A2 (inconsistência)', 'POST', `${ap(apptA2)}/consent/request`);
    await pEx2.goto(qA2c.json.link); await pEx2.click('[data-testid="consent-accept"]'); await pEx2.waitForSelector('[data-testid="consent-accepted"]');
    const stA2c = await call(pRoom, 'retomar A2', 'POST', `${ap(apptA2)}/start`, { data: { mode: 'live' } });
    ok(stA2c.status === 200, `${bt} C3: novo aceite de A2 retoma a sessão`);
    sq(`UPDATE appointments SET patient_id=${patientB} WHERE id=${apptA2}`);
    const sgInc = await call(pRoom, 'segmento com paciente/consulta inconsistentes', 'POST', `${ap(apptA2)}/segment`, { data: { sequence_number: 3, text: 'TESTE inconsistência', speaker: 'speaker_uncertain', is_final: 1 } });
    ok(sgInc.status !== 200 && segCount(apptA2) === 1, `${bt} C3: consulta passa a pertencer a outro paciente → segmento recusado (${sgInc.status}), nada gravado`);
    sq(`UPDATE appointments SET patient_id=${patientA} WHERE id=${apptA2}`);
    await ex2.close();

    // ============ limites de tentativa ============
    console.log(`-- ${bt}: limite de tentativas`);
    resetRL();
    const rl = []; for (let i = 0; i < 8; i++) rl.push((await call(pRoom, `pedido ${i + 1} (limite)`, 'POST', `${ap(apptB1)}/consent/request`)).status);
    ok(rl.slice(0, 6).every(s => s === 200) && rl[6] === 429, `${bt} limite: 6 pedidos de aceite por atendimento em 10 min; o 7º → 429 (${rl.join(',')})`);
    resetRL();
    const guess = []; const gCtx = await b.newContext(); const pG = await gCtx.newPage(); const gCsrf = await pubCsrf(pG);
    for (let i = 0; i < 24; i++) guess.push((await postPat(pG, `adivinhação ${i + 1}`, '/consentimento-transcricao/' + 'ABCDEFGHIJKLMNOPQRST', { _csrf_token: gCsrf, decision: 'accept', terms_sha256: '0'.repeat(64) })).status);
    ok(guess.slice(0, 20).every(s => s === 410) && guess.slice(20).every(s => s === 429), `${bt} limite: 20 tentativas com token inexistente → 410; a partir da 21ª → 429 (${[...new Set(guess)]})`);
    await gCtx.close(); resetRL();

    ok(errs.length === 0, `${bt}: sem erros de JavaScript nas páginas` + (errs.length ? ': ' + errs.slice(0, 3).join(' | ') : ''));
    ok(bad.length === 0, `${bt}: nenhuma resposta 5xx` + (bad.length ? ': ' + bad.slice(0, 3).join(' | ') : ''));
    fs.writeFileSync(path.join(OUT, `ni2830-evidence-${bt}.json`), JSON.stringify(ev, null, 1));
    await b.close();
  }
  const f = res.filter(x => !x).length;
  console.log(`\n${res.length - f} PASS, ${f} FAIL`);
  process.exit(f ? 1 : 0);
})().catch(e => { console.error('Error:', e); process.exit(2); });
