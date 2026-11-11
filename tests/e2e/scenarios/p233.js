// NI2-233: homologação E2E de anamnese e prontuário pela interface real.
// Uso: node p233.js <base_url> <alvo_do_banco>   (alvo = arquivo .sqlite do portão, ou mysql:<socket>:<banco_com_test>)
// Variáveis opcionais: E2E_OUT (pasta de saída), E2E_SHOTS (capturas), E2E_AXE (caminho do axe.min.js), E2E_RUN_ID.
// Só dados TESTE (nomes com "TESTE", e-mails @teste.invalid). Nenhuma checagem é dada como PASS por inspeção:
// cada PASS vem de tela + banco. O que não dá para provar neste ambiente sai como "NAO TESTADO" (não conta como PASS).
const { chromium } = require('playwright');
const fs = require('fs'), path = require('path'), cp = require('child_process');
const B = process.argv[2], DBT = process.argv[3];
const OUT = process.env.E2E_OUT || '/tmp';
const SHOTS = process.env.E2E_SHOTS || path.join(OUT, 'p233-shots');
fs.mkdirSync(SHOTS, { recursive: true });
const RUN = process.env.E2E_RUN_ID || Date.now().toString(36);
const IS_MYSQL = String(DBT || '').startsWith('mysql:');
const SQM = path.join(__dirname, '..', 'lib', 'sqm.php');
const PASSWORD = 'Teste-Senha-123';
const res = []; const notTested = [];
const ok = (c, l) => { res.push(!!c); console.log((c ? '  PASS  ' : '  FAIL  ') + l); };
const nt = (l, why) => { notTested.push(l); console.log('  NAO TESTADO  ' + l + ' (' + why + ')'); };
const clean = (s) => String(s || '').replace(/Error:/g, 'Erro -').replace(/\s+/g, ' ').slice(0, 300);
const q = (sql, ...params) => cp.execFileSync('php', [SQM, DBT, sql, ...params.map(String)]).toString().trim();
const q1 = (sql, ...p) => q(sql, ...p).split('\n')[0] || '';
const tzNow = () => new Date(new Date().toLocaleString('en-US', { timeZone: 'America/Sao_Paulo' }));
const fmtD = (d) => d.toLocaleDateString('sv-SE'); const fmtT = (d) => d.toTimeString().slice(0, 5);
const shot = async (p, name) => { await p.screenshot({ path: path.join(SHOTS, name + '.png'), fullPage: true }).catch(() => {}); };
const pickFood = async (p, query, label) => {
  await p.fill('#quick-food-query', query);
  const btn = p.locator(`[data-food-picker]:has(#quick-food-query) button.food-result`).filter({ hasText: label }).first(); // v54.30: nome acessível = texto visível
  await btn.click({ timeout: 10000 });
};
// primeiro horário livre (sem consulta do profissional a menos de 60 min) no dia, entre 08:00 e 19:30
const freeSlot = (profId, day, from = 8 * 60, to = 19 * 60 + 30) => {
  const busy = q("SELECT scheduled_at FROM appointments WHERE professional_id = ? AND status NOT IN ('cancelled')", profId).split('\n').filter(Boolean).map(r => new Date(r.slice(0, 16).replace(' ', 'T')));
  for (let m = from; m <= to; m += 30) {
    const d = new Date(day); d.setHours(Math.floor(m / 60), m % 60, 0, 0);
    if (!busy.some(x => Math.abs(x - d) < 60 * 60000)) return d;
  }
  throw new Error('sem horário livre no dia ' + fmtD(day));
};
const section = async (name, fn) => { console.log('\n== ' + name); try { await fn(); } catch (e) { ok(false, name + ': exceção inesperada — ' + clean(e.message)); } };

(async () => {
  const b = await chromium.launch();
  const ids = {
    clinicA: +q1("SELECT id FROM clinics WHERE name = ?", 'Clínica TESTE A'),
    clinicB: +q1("SELECT id FROM clinics WHERE name = ?", 'Clínica TESTE B'),
  };
  for (const [k, email] of Object.entries({ a: 'nutri.homolog.a@teste.invalid', a2: 'nutri.homolog.a2@teste.invalid', rec: 'recepcao.homolog.a@teste.invalid', adm: 'gestora.semcrn.a@teste.invalid', b: 'nutri.homolog.b@teste.invalid' })) {
    const [uid, pid] = q1('SELECT u.id, p.id FROM users u LEFT JOIN professionals p ON p.user_id = u.id WHERE u.email = ?', email).split('|');
    ids[k] = { user: +uid, prof: pid ? +pid : null, email };
  }
  ids.patientB = +q1('SELECT id FROM patients WHERE clinic_id = ? AND name = ?', ids.clinicB, 'Paciente TESTE Clinica B');
  console.log('alvo: ' + (IS_MYSQL ? 'MySQL ' + DBT.split(':')[2] : 'SQLite') + ' · execução ' + RUN + ' · ids ' + JSON.stringify(ids));
  const newCtx = async (w = 1280, h = 900) => { const ctx = await b.newContext({ viewport: { width: w, height: h } }); const p = await ctx.newPage();
    p.setDefaultTimeout(15000); p.on('dialog', d => d.accept()); p.on('pageerror', e => console.log('   [erro de JS na página] ' + clean(e.message))); return { ctx, p }; };
  const asUser = async (who, to = '/dashboard', w) => { const s = await newCtx(w); await s.p.goto(B + `/__teste_login?u=${ids[who].user}&c=${who === 'b' ? ids.clinicB : ids.clinicA}&v=0&to=${encodeURIComponent(to)}`); return s; };
  const formLogin = async (p, email) => { await p.goto(B + '/login'); await p.fill('#email', email); await p.fill('#password', PASSWORD);
    await Promise.all([p.waitForURL(u => !/\/login/.test(new URL(u).pathname), { timeout: 15000 }).catch(() => {}), p.click('form[action="/login"] button[type=submit]')]); };
  const csrfPost = (p, url, data) => p.evaluate(async ([url, data]) => { const tok = document.querySelector('input[name="_csrf_token"]')?.value || document.querySelector('meta[name="csrf-token"]')?.content || '';
    const r = await fetch(url, { method: 'POST', body: new URLSearchParams({ _csrf_token: tok, ...data }), redirect: 'manual' }); return { s: r.status, t: r.type }; }, [url, data]);
  const S = {}; // estado da jornada

  // ---------------------------------------------------------------- 1. caminho feliz
  await section('C1 caminho feliz: paciente → anamnese → avaliação → conduta → finalização → retorno', async () => {
    const { ctx, p } = await newCtx(); S.ctxA = ctx; S.pA = p;
    await formLogin(p, ids.a.email);
    ok(!/\/login/.test(new URL(p.url()).pathname), 'C1.1 login real pelo formulário /login (nutri A)');
    const name = `Paciente TESTE H233 ${RUN}`; S.name = name;
    await p.goto(B + '/pacientes'); await p.click('text=Cadastrar paciente');
    await p.fill('#p_name', name); await p.fill('#p_birth', '1990-05-10'); await p.fill('#p_allergies', 'Amendoim TESTE (urticária)');
    await p.selectOption('#consent-choice', 'granted');
    await Promise.all([p.waitForURL(/success=/, { timeout: 15000 }), p.click('button:has-text("Salvar paciente")')]);
    S.pid = +q1('SELECT id FROM patients WHERE clinic_id = ? AND name = ?', ids.clinicA, name);
    ok(S.pid > 0 && /Abrir perfil/.test(await p.textContent('main')), `C1.2 paciente cadastrada pela tela e gravada no banco (id ${S.pid})`);
    await shot(p, 'c1-01-paciente-cadastrada');

    await p.goto(B + `/pacientes/${S.pid}/anamnese`);
    await p.selectOption('#allergy-assessment', 'recorded'); await p.fill('#anamnesis_allergies', 'Amendoim TESTE (urticária)');
    await p.fill('#anamnesis_chief_complaint', 'Queixa TESTE: cansaço e ganho de peso'); await p.fill('#anamnesis_objectives', 'Objetivo TESTE: reeducação alimentar');
    await Promise.all([p.waitForURL(/success=|error=/, { timeout: 15000 }), p.click('button[name=save_mode]:has-text("Concluir anamnese")')]);
    const an = q1('SELECT COUNT(*), MAX(version), MIN(professional_id), MAX(is_draft) FROM anamneses WHERE patient_id = ? AND clinic_id = ?', S.pid, ids.clinicA).split('|');
    ok(/anamnese_concluida/.test(p.url()) && an[0] === '1' && +an[2] === ids.a.prof && an[3] === '0', `C1.3 anamnese concluída: 1 versão final, autoria = profissional canônico de A (banco: ${an.join('/')})`);
    await shot(p, 'c1-02-anamnese-concluida');

    // primeiro horário livre de A hoje, a partir de agora+1h (permite reexecutar a suíte no mesmo banco MySQL)
    const nowL = tzNow(); const fromM = Math.ceil((nowL.getHours() * 60 + nowL.getMinutes() + 60) / 30) * 30;
    let start; try { start = freeSlot(ids.a.prof, nowL, fromM, 22 * 60 + 30); } catch (e) { start = freeSlot(ids.a.prof, nowL, 6 * 60, 22 * 60 + 30); } // fim do dia: horário já passado de hoje (último início 22:30, para a consulta de 60 min não virar o dia)
    S.apptLocal = fmtD(start) + ' ' + fmtT(start);
    await p.goto(B + '/agenda'); await p.click('text=+ Nova Consulta');
    await p.selectOption('#dc11-patient_id', String(S.pid)); await p.fill('#new-appointment-date', fmtD(start)); await p.fill('#new-appointment-time', fmtT(start));
    await p.selectOption('#dc14-type', 'first_visit');
    await Promise.all([p.waitForURL(/success=|error=/, { timeout: 15000 }), p.click('button:has-text("Confirmar agendamento")')]);
    const ap = q1("SELECT id, status, scheduled_at, professional_id FROM appointments WHERE patient_id = ? AND type = 'first_visit' ORDER BY id DESC LIMIT 1", S.pid).split('|');
    S.appt = +ap[0];
    ok(/success=agendado/.test(p.url()) && ap[1] === 'scheduled', `C1.4 consulta agendada pela agenda (id ${ap[0]}, ${ap[2]}) ${/error=/.test(p.url()) ? 'erro: ' + decodeURIComponent(p.url().split('error=')[1]) : ''}`);

    await p.goto(B + `/pacientes/${S.pid}`);
    await Promise.all([p.waitForURL(/\/consulta\//, { timeout: 15000 }), p.click('[data-testid=care-start]')]);
    if (await p.locator('input[name=confirm_early]').count()) { await p.locator('input[name=confirm_early]').check(); await Promise.all([p.waitForLoadState(), p.click('[data-testid=consult-start]')]); }
    await p.waitForSelector('#evolution_summary', { timeout: 15000 });
    ok(q1('SELECT status FROM appointments WHERE id = ?', S.appt) === 'in_progress', 'C1.5 atendimento iniciado (status in_progress no banco)');

    // avaliação antropométrica
    await p.goto(B + `/pacientes/${S.pid}/antropometria`);
    const opener = p.locator('[data-call=openAnthroModal]:visible').first();
    if (await opener.count() && !(await p.locator('#ant_weight').isVisible())) await opener.click();
    await p.fill('#ant_weight', '70.5'); await p.fill('#ant_height', '165');
    await p.locator('button:has-text("2. Circunferências")').click().catch(() => {}); await p.fill('#ant_waist_cm', '82').catch(() => {});
    await Promise.all([p.waitForURL(/success=|error=/, { timeout: 15000 }), p.click('button:has-text("Salvar avaliação antropométrica")')]);
    const as = q1('SELECT COUNT(*), MAX(weight_kg), MAX(professional_id) FROM anthropometric_assessments WHERE patient_id = ?', S.pid).split('|');
    ok(/avaliacao_registrada/.test(p.url()) && as[0] === '1' && Math.abs(+as[1] - 70.5) < 0.01 && +as[2] === ids.a.prof, `C1.6 avaliação antropométrica gravada (70,5 kg; autoria ${as[2]})`);
    await shot(p, 'c1-03-avaliacao');

    // conduta: plano alimentar publicado
    await p.goto(B + `/pacientes/${S.pid}`); await p.click('text=+ Novo Plano'); await p.fill('#dc1-title', `Plano TESTE H233 ${RUN}`);
    await Promise.all([p.waitForURL(/\/planos\/\d+/, { timeout: 15000 }), p.click('button:has-text("Abrir editor")')]);
    S.plan = +new URL(p.url()).pathname.split('/')[2];
    await p.fill('#quick-meal-name', 'Café da manhã TESTE'); await p.fill('#quick-meal-time', '07:00');
    await p.click('button:has-text("Adicionar refeição")'); await p.waitForLoadState(); await p.waitForTimeout(800);
    const mealVal = await p.evaluate(() => [...document.querySelectorAll('#quick-meal-select option')].map(o => o.value).filter(Boolean).pop());
    await p.selectOption('#quick-meal-select', mealVal);
    await pickFood(p, 'amendoim', 'Amendoim, grão, cru'); await p.fill('#quick-amount', '30');
    await p.click('button:has-text("Adicionar alimento")'); await p.waitForTimeout(1200);
    const alertBox = p.locator('#quick-plan-editor [data-allergen-item-alert]');
    S.peanutAlert = (await alertBox.isVisible()) ? (await alertBox.innerText()).replace(/\s+/g, ' ') : '';
    await shot(p, 'c1-03b-alerta-alergia');
    const peanutItems = +q1('SELECT COUNT(*) FROM meal_plan_items i JOIN meal_plan_meals m ON m.id = i.meal_id JOIN foods f ON f.id = i.food_id WHERE m.meal_plan_id = ? AND f.name LIKE ?', S.plan, 'Amendoim%');
    ok(peanutItems === 0 && /alérg|alerg|conflito/i.test(S.peanutAlert), `C1.7 alerta de alergia na tela barra amendoim (paciente alérgica) sem confirmação expressa (itens no banco: ${peanutItems}; tela: "${S.peanutAlert.slice(0, 120)}")`);
    await pickFood(p, 'pão', 'Pão, trigo, francês'); await p.fill('#quick-amount', '50');
    await p.click('button:has-text("Adicionar alimento")'); await p.waitForTimeout(1200);
    const breadItems = +q1('SELECT COUNT(*) FROM meal_plan_items i JOIN meal_plan_meals m ON m.id = i.meal_id JOIN foods f ON f.id = i.food_id WHERE m.meal_plan_id = ? AND f.name LIKE ?', S.plan, 'Pão%');
    ok(breadItems === 1, `C1.7b alimento sem conflito (pão 50 g) entra no plano (itens no banco: ${breadItems})`);
    await p.reload(); await p.click('button:has-text("Revisar e publicar")');
    const panel = p.locator('#publish-plan-panel');
    if (await panel.locator('[data-testid=allow-adequacy-override]').count()) { await panel.locator('[data-testid=allow-adequacy-override]').check(); await panel.locator('#adequacy-override-reason').fill('TESTE: prescrição inicial de transição, completar no retorno.'); }
    if (await panel.locator('input[name=allow_allergen_override]').count()) { await panel.locator('input[name=allow_allergen_override]').check(); await panel.locator('#allergen-override-reason').fill('TESTE: pão francês sem amendoim, rótulo conferido com a paciente.'); }
    await Promise.all([p.waitForURL(/success=|error=/, { timeout: 15000 }).catch(() => {}), panel.locator('form[action$="/publicar"] button[type=submit]').last().click()]);
    const pst = q1('SELECT status FROM meal_plans WHERE id = ?', S.plan);
    const perr = /published/.test(pst) ? '' : ' — url ' + p.url() + ' — ' + ((await p.locator('.op-message--error, .alert-danger, [role=alert]').allTextContents()).join(' | ').slice(0, 300));
    ok(/published/.test(pst), `C1.8 conduta: plano alimentar publicado (status ${pst})${perr}`);
    await shot(p, 'c1-04-plano-publicado');

    // finalização
    await p.goto(B + `/consulta/${S.appt}`);
    await p.fill('#evolution_summary', 'Evolução TESTE: paciente orientada sobre o plano; retorno em 7 dias.');
    if (await p.locator('[data-testid=confirm-pending-review]').count()) await p.locator('[data-testid=confirm-pending-review]').check(); // NI2-706
    await Promise.all([p.waitForURL(/success=|error=/, { timeout: 15000 }), p.click('button:has-text("Concluir consulta e registrar no prontuário")')]);
    const cr = q1('SELECT COUNT(*), MIN(professional_id), MIN(hash_version) FROM clinical_records WHERE patient_id = ? AND clinic_id = ?', S.pid, ids.clinicA).split('|');
    ok(/consulta_concluida/.test(p.url()) && q1('SELECT status FROM appointments WHERE id = ?', S.appt) === 'completed' && cr[0] === '1' && +cr[1] === ids.a.prof,
      `C1.9 consulta concluída: status completed e 1 evolução no prontuário com autoria de A (banco: ${cr.join('/')})`);
    await p.goto(B + `/pacientes/${S.pid}/prontuario`);
    const mtxt = await p.textContent('main'); S.dbgIntegr = (mtxt.match(/.{0,60}integridade.{0,80}/i) || ['(sem texto de integridade)'])[0];
    ok(/Encadeamento dos registros conferido, sem divergências/.test(mtxt), 'C1.10 prontuário: cadeia de integridade (hash) verificada na tela' + (/Encadeamento dos registros conferido, sem divergências/.test(mtxt) ? '' : ' — ' + S.dbgIntegr.replace(/\s+/g, ' ')));
    await shot(p, 'c1-05-prontuario');

    // retorno
    await p.goto(B + `/agenda?novo=1&paciente=${S.pid}`);
    if (!(await p.locator('#dc11-patient_id').isVisible())) await p.click('text=+ Nova Consulta');
    const d7 = tzNow(); d7.setDate(d7.getDate() + 7); const ret = freeSlot(ids.a.prof, d7);
    await p.selectOption('#dc11-patient_id', String(S.pid)); await p.fill('#new-appointment-date', fmtD(ret)); await p.fill('#new-appointment-time', fmtT(ret));
    await p.selectOption('#dc14-type', 'follow_up');
    await Promise.all([p.waitForURL(/success=|error=/, { timeout: 15000 }), p.click('button:has-text("Confirmar agendamento")')]);
    const rr = q1("SELECT id, scheduled_at, status FROM appointments WHERE patient_id = ? AND type = 'follow_up' ORDER BY id DESC LIMIT 1", S.pid).split('|');
    S.retorno = +rr[0];
    ok(/success=agendado/.test(p.url()) && rr[1] && rr[1].startsWith(fmtD(ret) + ' ' + fmtT(ret)) && rr[2] === 'scheduled', `C1.11 retorno agendado (follow_up ${rr[1]})`);
    await p.goto(B + `/pacientes/${S.pid}`);
    ok(new RegExp(fmtD(ret).split('-').reverse().join('/')).test(await p.textContent('main')), 'C1.12 perfil mostra o retorno marcado');
    await shot(p, 'c1-06-perfil-final');
  });

  // ---------------------------------------------------------------- utilitários das seções 2–6
  // POST no servidor com a sessão da página (cookies do contexto) e o token CSRF da própria tela; sem seguir o redirect
  const post = async (p, url, data, follow = false) => {
    const tok = await p.evaluate(() => document.querySelector('input[name="_csrf_token"]')?.value || document.querySelector('meta[name="csrf-token"]')?.content || '');
    const r = await p.request.post(B + url, { form: { _csrf_token: tok, ...data }, maxRedirects: follow ? 10 : 0 });
    return { s: r.status(), loc: r.headers()['location'] || '', body: follow || r.status() !== 302 ? await r.text() : '' };
  };
  // formulário real da tela serializado (com o token e os campos ocultos) e alguns campos trocados
  const formData = (p, sel, over) => p.evaluate(([sel, over]) => { const f = document.querySelector(sel); const o = {};
    for (const [k, v] of new FormData(f)) if (typeof v === 'string') o[k] = v; return Object.assign(o, over); }, [sel, over]);
  const count = (sql, ...a) => +q1(sql, ...a);
  const recCount = (pid) => count('SELECT COUNT(*) FROM clinical_records WHERE patient_id = ?', pid);
  const PFORM = 'form[method="POST"][action="/pacientes"]';
  const patientForm = async (p, over) => { await p.goto(B + '/pacientes');
    return formData(p, PFORM, Object.assign({ birth_date: '1985-03-20', gender: 'Feminino', allergy_assessment: 'denied', allergy_denied: '1', consent_choice: 'granted' }, over)); };
  const newPatient = async (p, name) => { const r = await post(p, '/pacientes', await patientForm(p, { name }));
    return count('SELECT MAX(id) FROM patients WHERE clinic_id = ? AND name = ?', ids.clinicA, name); };
  const lastRec = (pid) => count('SELECT MAX(id) FROM clinical_records WHERE patient_id = ? AND is_retracted = 0', pid);

  // ---------------------------------------------------------------- 2. validação, erros, falha externa, sessão, vazios
  await section('C2 validação, erros, falha externa, sessão expirada, estados vazios e recuperação', async () => {
    const p = S.pA; if (!p || !S.pid) throw new Error('C1 não deixou a jornada pronta');
    // C2.1 paciente sem nome: o navegador barra (required) e o servidor também recusa um envio forjado
    const nBefore = count('SELECT COUNT(*) FROM patients WHERE clinic_id = ?', ids.clinicA);
    await p.goto(B + '/pacientes'); await p.click('text=Cadastrar paciente'); await p.fill('#p_birth', '1990-05-10');
    await p.click('button:has-text("Salvar paciente")'); await p.waitForTimeout(400);
    const invalidUi = await p.evaluate(() => !document.querySelector('#p_name').checkValidity());
    const forged = await post(p, '/pacientes', await patientForm(p, { name: '   ' }));
    const nAfter = count('SELECT COUNT(*) FROM patients WHERE clinic_id = ?', ids.clinicA);
    ok(invalidUi && nAfter === nBefore && /error=cadastro/.test(forged.loc), `C2.1 paciente sem nome: tela barra o envio e o servidor recusa o envio forjado; nada gravado (${nBefore}→${nAfter}; resposta ${forged.s} ${clean(forged.loc).slice(0, 80)})`);

    // C2.2 prontuário sem título / sem conteúdo
    await p.goto(B + `/pacientes/${S.pid}/prontuario`);
    const r0 = recCount(S.pid);
    const e1 = await post(p, `/pacientes/${S.pid}/prontuario`, { title: '', content_text: 'Conteúdo TESTE sem título', category: 'observacao' });
    const e2 = await post(p, `/pacientes/${S.pid}/prontuario`, { title: 'Título TESTE sem conteúdo', content_text: '   ', category: 'observacao' });
    ok(/campos_obrigatorios/.test(e1.loc) && /campos_obrigatorios/.test(e2.loc) && recCount(S.pid) === r0, `C2.2 prontuário sem título ou sem conteúdo é recusado com mensagem e nada é gravado (${r0}→${recCount(S.pid)})`);
    await p.goto(B + e1.loc.replace(/^https?:\/\/[^/]+/, ''));
    ok(/Título e conteúdo são obrigatórios/.test(await p.textContent('body')), 'C2.3 a mensagem de erro aparece na tela, em português');
    await shot(p, 'c2-01-erro-campos-obrigatorios');

    // C2.4 retificação com justificativa curta
    const live = lastRec(S.pid);
    const e3 = await post(p, `/pacientes/${S.pid}/prontuario/${live}/retificar`, { revision_reason: 'curto', revised_text: 'Texto TESTE' });
    ok(/motivo_curto/.test(e3.loc) && recCount(S.pid) === r0 && count('SELECT is_retracted FROM clinical_records WHERE id = ?', live) === 0, 'C2.4 retificação com justificativa < 10 caracteres recusada; registro original intacto');

    // C2.5 antropometria com valor fora da faixa (envio forjado, passando por cima do limite da tela)
    await p.goto(B + `/pacientes/${S.pid}/antropometria`);
    const aBefore = count('SELECT COUNT(*) FROM anthropometric_assessments WHERE patient_id = ?', S.pid);
    const wname = await p.getAttribute('#ant_weight', 'name');
    const fd = await formData(p, '[data-testid=unified-anthropometry-form]', { [wname]: '900', [await p.getAttribute('#ant_height', 'name')]: '165' });
    const e4 = await post(p, `/pacientes/${S.pid}/antropometria`, fd);
    ok(/error=/.test(e4.loc) && count('SELECT COUNT(*) FROM anthropometric_assessments WHERE patient_id = ?', S.pid) === aBefore, `C2.5 antropometria com peso 900 kg recusada pelo servidor; nada gravado (${clean(decodeURIComponent(e4.loc.split('msg=')[1] || e4.loc)).slice(0, 120)})`);

    // C2.6 falha externa: a busca de alimentos cai, a tela avisa e o "Tentar novamente" recupera
    await p.goto(B + `/pacientes/${S.pid}`); await p.click('text=+ Novo Plano'); await p.fill('#dc1-title', `Plano TESTE rascunho H233 ${RUN}`);
    await Promise.all([p.waitForURL(/\/planos\/\d+/, { timeout: 15000 }), p.click('button:has-text("Abrir editor")')]);
    S.plan2 = +new URL(p.url()).pathname.split('/')[2];
    await p.route('**/catalogo/alimentos/busca**', r => r.abort('failed'));
    await p.fill('#quick-food-query', 'pão');
    const picker = p.locator('[data-food-picker]:has(#quick-food-query)');
    await picker.locator('text=Não foi possível buscar alimentos. Tente novamente.').waitFor({ timeout: 10000 });
    const retryVisible = await picker.locator('[data-food-retry]').isVisible();
    await shot(p, 'c2-02-busca-falhou');
    await p.unroute('**/catalogo/alimentos/busca**');
    await picker.locator('[data-food-retry]').click();
    await picker.locator('button.food-result').filter({ hasText: 'Pão, trigo, francês' }).first().waitFor({ timeout: 10000 });
    ok(retryVisible, 'C2.6 falha da busca de alimentos (serviço externo fora): mensagem clara e botão "Tentar novamente"');
    ok(await picker.locator('button.food-result').filter({ hasText: 'Pão, trigo, francês' }).first().isVisible() && !(await picker.locator('[data-food-retry]').isVisible()), 'C2.7 "Tentar novamente" recupera a busca sem recarregar a página');

    // C2.8 sessão expirada no meio do registro: volta ao login, nada gravado, e o login devolve o acesso
    await p.goto(B + `/pacientes/${S.pid}/prontuario`);
    const r1 = recCount(S.pid);
    await p.click('button:has-text("+ Nova Evolução / Anotação")');
    await p.fill('#dc4-title', 'Registro TESTE com sessão expirada'); await p.fill('#dc6-content_text', 'Conteúdo TESTE que não deve ser gravado');
    const ctx2 = p.context(); const exp = await ctx2.newPage(); await exp.goto(B + '/__teste_expirar'); await exp.close();
    await Promise.all([p.waitForLoadState('load'), p.click('button:has-text("Gravar no prontuário")')]); await p.waitForTimeout(800);
    const onLogin = /\/login/.test(new URL(p.url()).pathname);
    ok(onLogin && recCount(S.pid) === r1, `C2.8 sessão expirada: envio vai para o login e nada é gravado (url ${new URL(p.url()).pathname + new URL(p.url()).search}; registros ${r1}→${recCount(S.pid)})`);
    S.expiredMsg = (await p.textContent('body')).match(/sess[aã]o[^.]*\./i)?.[0] || '';
    ok(/expir|encerrad/i.test(S.expiredMsg), `C2.9 a tela de login explica que a sessão expirou ("${clean(S.expiredMsg).slice(0, 100)}")`);
    await shot(p, 'c2-03-sessao-expirada');
    await p.fill('#email', ids.a.email); await p.fill('#password', PASSWORD);
    await Promise.all([p.waitForURL(u => !/\/login/.test(new URL(u).pathname), { timeout: 15000 }).catch(() => {}), p.click('form[action="/login"] button[type=submit]')]);
    await p.goto(B + `/pacientes/${S.pid}/prontuario`);
    ok(/Encadeamento dos registros conferido/.test(await p.textContent('main')), 'C2.10 novo login recupera o acesso ao prontuário (cadeia íntegra)');

    // C2.11 estados vazios
    S.pidEmpty = await newPatient(p, `Paciente TESTE Vazio H233 ${RUN}`);
    if (!S.pidEmpty) throw new Error('não criou paciente vazio');
    await p.goto(B + `/pacientes/${S.pidEmpty}/prontuario`);
    const emptyTxt = await p.textContent('main');
    ok(/Nenhuma entrada clínica registrada ainda neste prontuário/.test(emptyTxt) && await p.locator('text=Registrar primeira evolução').isVisible(), 'C2.11 prontuário vazio mostra estado vazio explicativo com ação "Registrar primeira evolução"');
    ok(!/Encadeamento dos registros conferido|conferência do encadeamento encontrou divergência/.test(emptyTxt), 'C2.12 prontuário vazio não mostra selo de integridade nem alerta falso');
    await shot(p, 'c2-04-prontuario-vazio');
    await p.goto(B + '/pacientes?busca=' + encodeURIComponent('Inexistente TESTE ' + RUN) + '&q=' + encodeURIComponent('Inexistente TESTE ' + RUN));
    ok(/Nenhum|nenhum/.test(await p.textContent('main')), 'C2.13 busca de paciente sem resultado mostra estado vazio');
  });

  // ---------------------------------------------------------------- 3. autorização: papel, clínica, profissional, vínculo, IDOR
  await section('C3 autorização por papel, clínica, professional_id canônico e vínculo; IDOR negativo', async () => {
    if (!S.pid || !S.appt) throw new Error('C1 não deixou a jornada pronta');
    const recTitle = q1('SELECT title FROM clinical_records WHERE patient_id = ? ORDER BY id LIMIT 1', S.pid);
    const recId = lastRec(S.pid);
    const snap = () => [recCount(S.pid), count('SELECT COUNT(*) FROM anamneses WHERE patient_id = ?', S.pid), count('SELECT COUNT(*) FROM anthropometric_assessments WHERE patient_id = ?', S.pid),
      q1('SELECT status FROM appointments WHERE id = ?', S.appt), count('SELECT COUNT(*) FROM clinical_records WHERE is_retracted = 1')].join('/');
    const leaks = (t) => t.includes(recTitle) || t.includes('Conteúdo TESTE') || /Evolução Clínica — Consulta/.test(t);
    for (const who of ['rec', 'b']) {
      const label = who === 'rec' ? 'recepção da clínica A' : 'nutricionista da clínica B';
      const { ctx, p } = await asUser(who, '/dashboard');
      const before = snap();
      const gets = [];
      for (const u of [`/pacientes/${S.pid}/prontuario`, `/pacientes/${S.pid}/prontuario/exportar`, `/pacientes/${S.pid}/prontuario/imprimir`, `/pacientes/${S.pid}/anamnese`, `/pacientes/${S.pid}/antropometria`, `/consulta/${S.appt}`]) {
        const r = await p.request.get(B + u, { maxRedirects: 0 }); const t = r.status() === 200 ? await r.text() : '';
        gets.push({ u, s: r.status(), loc: (r.headers()['location'] || '').replace(/^https?:\/\/[^/]+/, ''), leak: leaks(t) });
      }
      const allBlocked = gets.every(g => g.s !== 200 || !g.leak) && gets.every(g => !g.leak);
      ok(allBlocked, `C3.${who === 'rec' ? 1 : 3} ${label}: leitura de prontuário, exportação, impressão, anamnese, avaliação e consulta de paciente de A não mostra dado clínico (${gets.map(g => g.u.replace(/\/pacientes\/\d+|\/consulta\/\d+/, '') + '→' + g.s + (g.loc ? ' ' + g.loc.slice(0, 30) : '')).join('; ')})`);
      await p.goto(B + (who === 'rec' ? '/dashboard' : '/dashboard'));
      const posts = [];
      posts.push(await post(p, `/pacientes/${S.pid}/prontuario`, { title: 'Invasão TESTE', content_text: 'Conteúdo TESTE invasor', category: 'observacao' }));
      posts.push(await post(p, `/pacientes/${S.pid}/prontuario/${recId}/retificar`, { revision_reason: 'Retificação TESTE indevida', revised_text: 'Texto TESTE invasor' }));
      posts.push(await post(p, `/pacientes/${S.pid}/anamnese`, { save_mode: 'final', version: '1', chief_complaint: 'Invasão TESTE' }));
      posts.push(await post(p, `/pacientes/${S.pid}/antropometria`, { weight_kg: '80', height_cm: '170', assessed_at: fmtD(tzNow()) }));
      posts.push(await post(p, `/consulta/${S.appt}/concluir`, { evolution_summary: 'Invasão TESTE', weight_kg: '80' }));
      const after = snap();
      ok(after === before, `C3.${who === 'rec' ? 2 : 4} ${label}: escrita em prontuário, retificação, anamnese, avaliação e conclusão de consulta recusadas; banco intacto (${before} → ${after}; respostas ${posts.map(x => x.s + (x.loc ? ' ' + x.loc.replace(/^https?:\/\/[^/]+/, '').slice(0, 40) : '')).join('; ')})`);
      if (who === 'b') {
        // controle positivo: B trabalha normalmente com o próprio paciente
        const r0 = recCount(ids.patientB);
        await p.goto(B + `/pacientes/${ids.patientB}/prontuario`);
        const okRead = /Prontuário e evolução clínica/.test(await p.textContent('main'));
        const w = await post(p, `/pacientes/${ids.patientB}/prontuario`, { title: 'Evolução TESTE clínica B', content_text: 'Conteúdo TESTE da clínica B', category: 'observacao' });
        const nb = q1('SELECT COUNT(*), MAX(professional_id), MAX(clinic_id) FROM clinical_records WHERE patient_id = ?', ids.patientB).split('|');
        ok(okRead && +nb[0] === r0 + 1 && +nb[1] === ids.b.prof && +nb[2] === ids.clinicB, `C3.5 controle positivo: B lê e registra no próprio paciente com o próprio professional_id (${nb.join('/')})`);
        S.recB = count('SELECT MAX(id) FROM clinical_records WHERE patient_id = ?', ids.patientB);
        // IDOR: B usa o paciente dela na URL, mas o id de registro é de A
        const before2 = snap();
        const i1 = await post(p, `/pacientes/${ids.patientB}/prontuario/${recId}/retificar`, { revision_reason: 'Retificação TESTE cruzada', revised_text: 'Texto TESTE invasor' });
        ok(snap() === before2 && count('SELECT COUNT(*) FROM clinical_records WHERE patient_id = ?', ids.patientB) === r0 + 1, `C3.6 IDOR: B não retifica registro de A trocando o id na URL do próprio paciente (${i1.s} ${i1.loc.replace(/^https?:\/\/[^/]+/, '').slice(0, 60)})`);
        await shot(p, 'c3-01-clinica-b');
      }
      await ctx.close();
    }
    // IDOR dentro da clínica A: registro do paciente X pela URL do paciente Y
    {
      const p = S.pA; await p.goto(B + `/pacientes/${S.pidEmpty}/prontuario`);
      const before = snap(); const ne = recCount(S.pidEmpty);
      const i2 = await post(p, `/pacientes/${S.pidEmpty}/prontuario/${recId}/retificar`, { revision_reason: 'Retificação TESTE paciente trocado', revised_text: 'Texto TESTE trocado' });
      const i3 = await post(p, `/pacientes/${S.pidEmpty}/prontuario/${S.recB}/retificar`, { revision_reason: 'Retificação TESTE outra clínica', revised_text: 'Texto TESTE trocado' });
      ok(snap() === before && recCount(S.pidEmpty) === ne && count('SELECT COUNT(*) FROM clinical_records WHERE patient_id = ?', ids.patientB) === count('SELECT COUNT(*) FROM clinical_records WHERE patient_id = ? AND is_retracted = 0', ids.patientB),
        `C3.7 IDOR: A não retifica registro de outro paciente nem de outra clínica pela URL (${i2.s} ${i2.loc.replace(/^https?:\/\/[^/]+/, '').slice(0, 50)}; ${i3.s} ${i3.loc.replace(/^https?:\/\/[^/]+/, '').slice(0, 50)})`);
      const r4 = await p.request.get(B + `/pacientes/${ids.patientB}/prontuario`, { maxRedirects: 0 });
      ok(r4.status() !== 200 || !(await r4.text()).includes('Evolução TESTE clínica B'), `C3.8 IDOR: A não lê o prontuário do paciente da clínica B trocando o id (${r4.status()})`);
    }
    // professional_id canônico: a segunda nutricionista de A assina com o próprio cadastro
    {
      const { ctx, p } = await newCtx(); await formLogin(p, ids.a2.email);
      await p.goto(B + `/pacientes/${S.pid}/prontuario`);
      await p.click('button:has-text("+ Nova Evolução / Anotação")');
      await p.fill('#dc4-title', 'Evolução TESTE segunda nutricionista'); await p.fill('#dc6-content_text', 'Conteúdo TESTE assinado pela A2');
      await Promise.all([p.waitForURL(/success=|error=/, { timeout: 15000 }), p.click('button:has-text("Gravar no prontuário")')]);
      const a2 = q1("SELECT professional_id, clinic_id FROM clinical_records WHERE patient_id = ? AND title = 'Evolução TESTE segunda nutricionista'", S.pid).split('|');
      ok(+a2[0] === ids.a2.prof && +a2[1] === ids.clinicA, `C3.9 registro da segunda nutricionista fica com o professional_id dela (${a2[0]}; dona da clínica = ${ids.a.prof})`);
      await p.goto(B + `/pacientes/${S.pid}/prontuario`);
      const mt = (await p.textContent('main')).replace(/\s+/g, ' ');
      ok(/Evolução TESTE segunda nutricionista Por Nutri TESTE A2 em/.test(mt), 'C3.10 a tela mostra a autoria da segunda nutricionista no registro dela');
      const prevA2 = q1("SELECT previous_hash FROM clinical_records WHERE patient_id = ? AND title = 'Evolução TESTE segunda nutricionista'", S.pid);
      ok(prevA2.length === 64 && mt.includes('Elo anterior: ' + prevA2), `C3.10b painel de autenticidade mostra o elo real com a versão anterior (${prevA2.slice(0, 12)}…), não "Registro inicial" (correção NI2-233 d)`);
      await ctx.close();
    }
    // gestora sem CRN: pode administrar, mas não assina registro clínico (correção NI2-233 a)
    {
      const { ctx, p } = await newCtx(); await formLogin(p, ids.adm.email);
      await p.goto(B + `/pacientes/${S.pid}/prontuario`);
      const before = snap();
      const g1 = await post(p, `/pacientes/${S.pid}/prontuario`, { title: 'Registro TESTE sem CRN', content_text: 'Conteúdo TESTE sem autoria', category: 'observacao' });
      const g2 = await post(p, `/pacientes/${S.pid}/prontuario/${recId}/retificar`, { revision_reason: 'Retificação TESTE sem CRN', revised_text: 'Texto TESTE sem CRN' });
      await p.goto(B + `/pacientes/${S.pid}/antropometria`);
      const g3 = await post(p, `/pacientes/${S.pid}/antropometria`, { weight_kg: '70', height_cm: '165', assessed_at: fmtD(tzNow()) });
      ok(/sem_crn/.test(g1.loc) && /sem_crn/.test(g2.loc) && g3.s === 403 && snap() === before, `C3.11 usuária administradora sem CRN não grava evolução, retificação nem avaliação em nome de outro profissional (${g1.s} sem_crn; ${g2.s} sem_crn; antropometria ${g3.s}; banco ${before}→${snap()})`);
      await p.goto(B + g1.loc.replace(/^https?:\/\/[^/]+/, ''));
      ok(/cadastro profissional \(CRN\)/.test(await p.textContent('body')), 'C3.12 a mensagem explica o motivo e o caminho (Configurações › Equipe)');
      await shot(p, 'c3-02-sem-crn');
      await ctx.close();
    }
    nt('C3.13 vínculo paciente↔profissional', 'o sistema não tem vínculo por paciente: toda nutricionista ativa da clínica acessa todos os pacientes dela; lacuna de desenho registrada no relatório');
  });

  // ---------------------------------------------------------------- 4. concorrência, idempotência e fuso horário
  // Observação honesta: o servidor de teste (php -S) atende uma requisição por vez. Aqui os envios
  // "simultâneos" chegam em sequência imediata (duplo clique, aba antiga, reenvio). A disputa real
  // entre processos é coberta em tests/mysql/ni2_233_prontuario_mysql_test.php.
  await section('C4 concorrência (duplo envio / aba antiga), idempotência e fuso horário', async () => {
    const p = S.pA; if (!p || !S.pid) throw new Error('C1 não deixou a jornada pronta');
    // C4.1 cadastro de paciente reenviado com o mesmo identificador de envio
    const nm = `Paciente TESTE Reenvio H233 ${RUN}`;
    const fd = await patientForm(p, { name: nm });
    const [x1, x2] = await Promise.all([post(p, '/pacientes', fd), post(p, '/pacientes', fd)]);
    const x3 = await post(p, '/pacientes', fd);
    const nP = count('SELECT COUNT(*) FROM patients WHERE clinic_id = ? AND name = ?', ids.clinicA, nm);
    ok(nP === 1, `C4.1 cadastro enviado 3 vezes com o mesmo formulário cria 1 paciente (${nP}; respostas ${[x1, x2, x3].map(x => x.s + ' ' + x.loc.replace(/^https?:\/\/[^/]+/, '').slice(0, 30)).join('; ')})`);
    S.pidDup = count('SELECT MAX(id) FROM patients WHERE clinic_id = ? AND name = ?', ids.clinicA, nm);

    // C4.2 conclusão de consulta enviada duas vezes
    const nowL = tzNow(); const fromM = Math.ceil((nowL.getHours() * 60 + nowL.getMinutes() + 60) / 30) * 30;
    let st; try { st = freeSlot(ids.a.prof, nowL, fromM, 22 * 60 + 30); } catch (e) { st = freeSlot(ids.a.prof, nowL, 6 * 60, 22 * 60 + 30); }
    await p.goto(B + '/agenda'); await p.click('text=+ Nova Consulta');
    await p.selectOption('#dc11-patient_id', String(S.pidDup)); await p.fill('#new-appointment-date', fmtD(st)); await p.fill('#new-appointment-time', fmtT(st));
    await p.selectOption('#dc14-type', 'first_visit');
    await Promise.all([p.waitForURL(/success=|error=/, { timeout: 15000 }), p.click('button:has-text("Confirmar agendamento")')]);
    S.appt2 = count("SELECT MAX(id) FROM appointments WHERE patient_id = ?", S.pidDup);
    S.appt2Local = fmtD(st) + ' ' + fmtT(st);
    await p.goto(B + `/pacientes/${S.pidDup}`);
    await Promise.all([p.waitForURL(/\/consulta\//, { timeout: 15000 }), p.click('[data-testid=care-start]')]);
    if (await p.locator('input[name=confirm_early]').count()) { await p.locator('input[name=confirm_early]').check(); await Promise.all([p.waitForLoadState(), p.click('[data-testid=consult-start]')]); }
    await p.waitForSelector('#evolution_summary');
    await p.fill('#evolution_summary', 'Evolução TESTE de consulta enviada duas vezes. Conduta TESTE.');
    const cf = await formData(p, `form[action="/consulta/${S.appt2}/concluir"]`, { confirm_pending_review: '1' }); // NI2-706: confirmação explícita
    const [c1, c2] = await Promise.all([post(p, `/consulta/${S.appt2}/concluir`, cf), post(p, `/consulta/${S.appt2}/concluir`, cf)]);
    const nC = recCount(S.pidDup);
    ok(nC === 1 && q1('SELECT status FROM appointments WHERE id = ?', S.appt2) === 'completed', `C4.2 conclusão da consulta enviada 2 vezes gera 1 evolução e a consulta fica concluída (${nC} registro(s); ${c1.s} ${c1.loc.replace(/^https?:\/\/[^/]+/, '').slice(0, 40)}; ${c2.s} ${c2.loc.replace(/^https?:\/\/[^/]+/, '').slice(0, 40)})`);

    // C4.3 retificação em duas abas abertas sobre a mesma versão
    await p.goto(B + `/pacientes/${S.pidDup}/prontuario`);
    const rid = lastRec(S.pidDup);
    const { ctx: c2x, p: p2 } = await newCtx(); await formLogin(p2, ids.a2.email); await p2.goto(B + `/pacientes/${S.pidDup}/prontuario`);
    const v1 = await post(p, `/pacientes/${S.pidDup}/prontuario/${rid}/retificar`, { revision_reason: 'Retificação TESTE aba 1 (A)', revised_text: 'Texto TESTE aba 1' });
    const v2 = await post(p2, `/pacientes/${S.pidDup}/prontuario/${rid}/retificar`, { revision_reason: 'Retificação TESTE aba 2 (A2)', revised_text: 'Texto TESTE aba 2' });
    const live = count('SELECT COUNT(*) FROM clinical_records WHERE patient_id = ? AND is_retracted = 0', S.pidDup);
    const forks = count('SELECT COUNT(*) FROM (SELECT supersedes_id FROM clinical_records WHERE patient_id = ? AND supersedes_id IS NOT NULL GROUP BY supersedes_id HAVING COUNT(*) > 1) x', S.pidDup);
    const msg2 = decodeURIComponent((v2.loc.split('msg=')[1] || '').replace(/\+/g, ' '));
    ok(/success=/.test(v1.loc) && /já foi retificado/.test(msg2) && live === 1 && forks === 0, `C4.3 duas abas retificando a mesma versão: a primeira grava, a segunda recebe "${clean(msg2).slice(0, 70)}"; 1 versão vigente, 0 bifurcações (correção NI2-233 c)`);
    await p2.goto(B + v2.loc.replace(/^https?:\/\/[^/]+/, ''));
    await shot(p2, 'c4-01-aba-antiga-retificacao');
    await c2x.close();
    await p.goto(B + `/pacientes/${S.pidDup}/prontuario`);
    ok(/Encadeamento dos registros conferido/.test(await p.textContent('main')), 'C4.4 cadeia de integridade continua válida depois da disputa');

    // C4.5 duplo clique em "Gravar no prontuário"
    const r0 = recCount(S.pidDup);
    await p.click('button:has-text("+ Nova Evolução / Anotação")');
    await p.fill('#dc4-title', 'Evolução TESTE duplo clique'); await p.fill('#dc6-content_text', 'Conteúdo TESTE duplo clique');
    await Promise.all([p.waitForURL(/success=|error=/, { timeout: 15000 }).catch(() => {}), p.dblclick('button:has-text("Gravar no prontuário")')]);
    await p.waitForTimeout(1500);
    const dup = count("SELECT COUNT(*) FROM clinical_records WHERE patient_id = ? AND title = 'Evolução TESTE duplo clique'", S.pidDup);
    S.dblClick = dup;
    ok(dup === 1, `C4.5 duplo clique em "Gravar no prontuário" grava 1 entrada (${dup}; total ${r0}→${recCount(S.pidDup)})`);

    // C4.6 fuso horário (America/Sao_Paulo)
    if (IS_MYSQL) {
      const sa = q1('SELECT scheduled_at FROM appointments WHERE id = ?', S.appt2);
      ok(sa.startsWith(S.appt2Local), `C4.6 horário da consulta gravado como digitado no horário de Brasília (${sa} = ${S.appt2Local})`);
      const ca = q1("SELECT created_at FROM clinical_records WHERE patient_id = ? AND title = 'Evolução TESTE duplo clique' ORDER BY id LIMIT 1", S.pidDup);
      const diff = Math.abs(new Date(ca.replace(' ', 'T')) - tzNow()) / 60000;
      ok(diff < 5, `C4.7 data/hora do registro clínico no banco está no horário de Brasília (${ca}; diferença ${diff.toFixed(1)} min do relógio de São Paulo)`);
      await p.goto(B + `/pacientes/${S.pidDup}/prontuario`);
      const hhmm = ca.slice(11, 16);
      ok((await p.textContent('main')).includes(ca.slice(8, 10) + '/' + ca.slice(5, 7) + '/' + ca.slice(0, 4) + ' às ' + hhmm), `C4.8 a tela mostra a mesma data/hora do banco (${hhmm})`);
    } else {
      nt('C4.6–C4.8 fuso horário', 'no SQLite CURRENT_TIMESTAMP é UTC por definição; a verificação roda só no MySQL 5.7 (execução MySQL da suíte)');
    }
  });

  // ---------------------------------------------------------------- 5. larguras, teclado, contraste, carregamento/erro
  const AXE = process.env.E2E_AXE || '';
  // contraste calculado na página (WCAG 2.x): texto visível × primeiro fundo opaco dos ancestrais
  const contrastIssues = (p) => p.evaluate(() => {
    const rgb = s => { const m = s.match(/rgba?\(([^)]+)\)/); if (!m) return null; const v = m[1].split(',').map(x => parseFloat(x)); return { r: v[0], g: v[1], b: v[2], a: v.length > 3 ? v[3] : 1 }; };
    const lum = c => { const f = x => { x /= 255; return x <= 0.03928 ? x / 12.92 : Math.pow((x + 0.055) / 1.055, 2.4); }; return 0.2126 * f(c.r) + 0.7152 * f(c.g) + 0.0722 * f(c.b); };
    const bgOf = el => { for (let e = el; e; e = e.parentElement) { const cs = getComputedStyle(e); if (cs.backgroundImage && cs.backgroundImage !== 'none') return null; const c = rgb(cs.backgroundColor); if (c && c.a >= 0.99) return c; } return { r: 255, g: 255, b: 255, a: 1 }; };
    const out = [];
    for (const el of document.querySelectorAll('main *, header *, nav *')) {
      const own = [...el.childNodes].some(n => n.nodeType === 3 && n.textContent.trim().length > 1); if (!own) continue;
      const r = el.getBoundingClientRect(); const cs = getComputedStyle(el);
      if (!r.width || !r.height || cs.visibility === 'hidden' || +cs.opacity < 0.5 || el.closest('[hidden],[aria-hidden=true],details:not([open]) > :not(summary)')) continue;
      const fg = rgb(cs.color), bg = bgOf(el); if (!fg || !bg || fg.a < 0.99) continue;
      const L1 = lum(fg), L2 = lum(bg), ratio = (Math.max(L1, L2) + 0.05) / (Math.min(L1, L2) + 0.05);
      const px = parseFloat(cs.fontSize), bold = +cs.fontWeight >= 700, large = px >= 24 || (bold && px >= 18.66);
      if (ratio < (large ? 3 : 4.5)) out.push(`${ratio.toFixed(2)}:1 "${el.textContent.trim().replace(/\s+/g, ' ').slice(0, 40)}" (${cs.color} sobre rgb(${bg.r}, ${bg.g}, ${bg.b}))`);
    }
    return [...new Set(out)];
  });
  const AXE_SRC = AXE ? require('fs').readFileSync(AXE, 'utf8') : '';
  // injetado pelo protocolo do navegador (não passa pela CSP da página, que continua valendo para o app)
  const axeRun = async (p) => { if (!(await p.evaluate(() => !!window.axe))) await p.evaluate(AXE_SRC);
    return p.evaluate(async () => { const r = await window.axe.run(document, { runOnly: { type: 'tag', values: ['wcag2a', 'wcag2aa'] } });
      return r.violations.map(v => ({ id: v.id, impact: v.impact, n: v.nodes.length, ex: v.nodes[0]?.target?.join(' ') })); }); };
  await section('C5 larguras 390/768/1280/1440, teclado, contraste e estados de carregamento/erro', async () => {
    if (!S.pid) throw new Error('C1 não deixou a jornada pronta');
    S.axe = {}; S.contrast = {};
    const pages = [['perfil', `/pacientes/${S.pid}`], ['prontuario', `/pacientes/${S.pid}/prontuario`], ['anamnese', `/pacientes/${S.pid}/anamnese`], ['agenda', '/agenda']];
    for (const w of [390, 768, 1280, 1440]) {
      const { ctx, p } = await asUser('a', '/dashboard', w);
      const over = [], axeBad = [], low = [];
      for (const [nm, u] of pages) {
        await p.goto(B + u); await p.waitForLoadState('networkidle').catch(() => {});
        const o = await p.evaluate(() => ({ sw: document.documentElement.scrollWidth, cw: document.documentElement.clientWidth }));
        if (o.sw > o.cw + 1) over.push(`${nm} ${o.sw}>${o.cw}`);
        await shot(p, `c5-${w}-${nm}`);
        const ci = await contrastIssues(p); if (ci.length) { low.push(`${nm}: ${ci.length}`); S.contrast[`${w}/${nm}`] = ci.slice(0, 6); }
        if (AXE) { const v = (await axeRun(p)).filter(x => x.impact === 'serious' || x.impact === 'critical'); if (v.length) { axeBad.push(`${nm}: ${v.map(x => x.id + '×' + x.n).join(',')}`); S.axe[`${w}/${nm}`] = v; } }
      }
      ok(over.length === 0, `C5.${w} largura ${w}px: sem rolagem horizontal em perfil, prontuário, anamnese e agenda${over.length ? ' — ' + over.join('; ') : ''}`);
      ok(low.length === 0, `C5.${w}c contraste de texto ≥ 4,5:1 (3:1 texto grande) — verificação própria${low.length ? ' — ' + low.join('; ') : ''}`);
      if (AXE) ok(axeBad.length === 0, `C5.${w}a axe-core WCAG 2 A/AA sem violação séria/crítica${axeBad.length ? ' — ' + axeBad.join('; ') : ''}`);
      await ctx.close();
    }
    if (!AXE) nt('C5 axe-core', 'E2E_AXE não informado; rode tests/homologacao/run_ni2_233.sh');
    for (const [k, v] of Object.entries(S.contrast)) console.log(`   contraste ${k}: ${v.join(' | ')}`);
    for (const [k, v] of Object.entries(S.axe)) console.log(`   axe ${k}: ${v.map(x => `${x.id}(${x.impact}) ×${x.n} ex. ${x.ex}`).join(' | ')}`);

    // C5.k teclado: chegar, abrir, preencher e gravar uma evolução sem mouse
    {
      const { ctx, p } = await asUser('a', `/pacientes/${S.pid}/prontuario`, 1280);
      const r0 = recCount(S.pid);
      let found = false, ring = '';
      for (let i = 0; i < 80 && !found; i++) { await p.keyboard.press('Tab');
        const a = await p.evaluate(() => { const e = document.activeElement; const cs = getComputedStyle(e); return { t: (e.textContent || '').trim(), ring: cs.outlineStyle !== 'none' && parseFloat(cs.outlineWidth) > 0 ? 'outline ' + cs.outlineWidth : (cs.boxShadow !== 'none' ? 'box-shadow' : '') }; });
        if (/\+ Nova Evolução/.test(a.t)) { found = true; ring = a.ring; } }
      ok(found, 'C5.k1 Tab alcança "+ Nova Evolução / Anotação"');
      ok(!!ring, `C5.k2 foco visível no botão (${ring || 'sem contorno de foco'})`);
      await shot(p, 'c5-teclado-foco');
      await p.keyboard.press('Enter');
      await p.waitForSelector('#dc4-title', { state: 'visible', timeout: 5000 }).catch(() => {});
      const inDialog = await p.evaluate(() => !!document.activeElement.closest('#modal-new-record, [role=dialog], dialog'));
      ok(await p.locator('#dc4-title').isVisible() && inDialog, `C5.k3 Enter abre o formulário e o foco vai para dentro dele (${await p.evaluate(() => document.activeElement.id || document.activeElement.tagName)})`);
      await p.keyboard.press('Escape'); await p.waitForTimeout(300);
      ok(!(await p.locator('#dc4-title').isVisible()), 'C5.k4 Esc fecha o formulário');
      await p.locator('button:has-text("+ Nova Evolução / Anotação")').focus(); await p.keyboard.press('Enter');
      await p.locator('#dc4-title').focus(); await p.keyboard.type('Evolução TESTE por teclado');
      await p.keyboard.press('Tab'); await p.keyboard.press('Tab'); await p.keyboard.type('Conteúdo TESTE digitado só pelo teclado');
      const onContent = await p.evaluate(() => document.activeElement.id);
      await Promise.all([p.waitForURL(/success=|error=/, { timeout: 15000 }).catch(() => {}), (async () => { await p.keyboard.press('Tab'); await p.keyboard.press('Tab'); await p.keyboard.press('Enter'); })()]);
      const got = count("SELECT COUNT(*) FROM clinical_records WHERE patient_id = ? AND title = 'Evolução TESTE por teclado'", S.pid);
      ok(onContent === 'dc6-content_text' && got === 1 && recCount(S.pid) === r0 + 1, `C5.k5 título → categoria → conteúdo → Gravar só com Tab/Enter grava a evolução (${onContent}; ${got})`);
      // C5.l estado de carregamento da busca de alimentos
      if (S.plan2) {
        await p.goto(B + `/planos/${S.plan2}`);
        await p.route('**/catalogo/alimentos/busca**', async r => { await new Promise(res => setTimeout(res, 2500)); await r.continue(); });
        await p.fill('#quick-food-query', 'arroz');
        const pk = p.locator('[data-food-picker]:has(#quick-food-query)');
        await pk.locator('text=Buscando alimentos…').waitFor({ timeout: 5000 }).catch(() => {});
        const busy = await pk.evaluate(e => !!e.querySelector('[aria-busy=true]') || e.getAttribute('aria-busy') === 'true');
        const loadingVisible = await pk.locator('text=Buscando alimentos…').isVisible();
        await shot(p, 'c5-carregando-busca');
        ok(loadingVisible && busy, `C5.l1 busca lenta mostra "Buscando alimentos…" com aria-busy (${loadingVisible}/${busy})`);
        await p.unroute('**/catalogo/alimentos/busca**');
      } else nt('C5.l1 carregamento', 'plano rascunho de C2 não existe');
      await ctx.close();
    }
    // C5.e erro visível em celular
    {
      const { ctx, p } = await asUser('a', `/pacientes/${S.pid}/prontuario?error=campos_obrigatorios&msg=${encodeURIComponent('Título e conteúdo são obrigatórios.')}`, 390);
      const al = p.locator('[role=alert]').filter({ hasText: 'obrigatórios' }).first();
      const box = await al.boundingBox().catch(() => null);
      ok(!!box && box.x >= 0 && box.x + box.width <= 391, `C5.e1 mensagem de erro cabe na tela de 390px e tem papel de alerta (${box ? Math.round(box.width) + 'px' : 'não encontrada'})`);
      await shot(p, 'c5-390-erro');
      await ctx.close();
    }
  });

  // ---------------------------------------------------------------- 6. regressão após recarregar, sair e entrar de novo
  await section('C6 regressão após recarregar a página, sair e entrar de novo', async () => {
    const p = S.pA; if (!p || !S.pid) throw new Error('C1 não deixou a jornada pronta');
    const state = () => [recCount(S.pid), count('SELECT COUNT(*) FROM anamneses WHERE patient_id = ? AND is_draft = 0', S.pid), q1('SELECT status FROM meal_plans WHERE id = ?', S.plan), q1('SELECT status FROM appointments WHERE id = ?', S.retorno)].join('/');
    const screen = async () => { await p.goto(B + `/pacientes/${S.pid}/prontuario`); const t = (await p.textContent('main')).replace(/\s+/g, ' ');
      return { n: (t.match(/histórico preservado \((\d+) registros?\)/i) || [])[1], ok: /Encadeamento dos registros conferido/.test(t) }; };
    const s0 = state(); const v0 = await screen();
    await p.reload(); const t1 = (await p.textContent('main')).replace(/\s+/g, ' ');
    ok(+v0.n === recCount(S.pid) && t1.toLowerCase().includes(`(${v0.n} registro`) && /Encadeamento dos registros conferido/.test(t1), `C6.1 recarregar o prontuário mantém os ${v0.n} registros e a integridade`);
    const r = await p.request.get(B + `/pacientes/${S.pid}/prontuario`);
    const cc = r.headers()['cache-control'] || '';
    ok(/no-store/.test(cc), `C6.2 prontuário é servido sem cache (Cache-Control: ${cc || 'ausente'})`);
    await Promise.all([p.waitForURL(/\/login|\/$/, { timeout: 15000 }).catch(() => {}), p.locator('[data-testid=logout-form] button, [data-testid=logout-form] [type=submit]').first().click()]);
    const after = await p.request.get(B + `/pacientes/${S.pid}/prontuario`, { maxRedirects: 0 });
    ok(after.status() === 302 && /login/.test(after.headers()['location'] || ''), `C6.3 depois de sair, o prontuário exige login (${after.status()} ${after.headers()['location'] || ''})`);
    await p.goBack().catch(() => {}); await p.waitForTimeout(500);
    const back = await p.textContent('body');
    ok(!back.includes('Evolução TESTE segunda nutricionista') && !back.includes('Conteúdo TESTE assinado'), `C6.4 botão Voltar depois de sair não reexibe dado clínico (${new URL(p.url()).pathname})`);
    await shot(p, 'c6-01-voltar-apos-sair');
    await formLogin(p, ids.a.email);
    const v2 = await screen();
    ok(v2.ok && v2.n === v0.n && state() === s0, `C6.5 novo login: mesmos registros, anamnese final, plano publicado e retorno marcado (${s0})`);
    await p.goto(B + `/pacientes/${S.pid}/anamnese`);
    ok(/versão|Versão|v1/.test(await p.textContent('main')) && !/rascunho não salvo/i.test(await p.textContent('main')), 'C6.6 anamnese continua acessível como versão concluída');
    await p.goto(B + `/pacientes/${S.pid}`);
    await shot(p, 'c6-02-perfil-apos-novo-login');
  });

  await b.close();
  const f = res.filter(x => !x).length;
  console.log(`\nNão testados aqui: ${notTested.length}${notTested.length ? ' — ' + notTested.join('; ') : ''}`);
  console.log(`\n${res.length - f} PASS, ${f} FAIL`); process.exit(f ? 1 : 0);
})();
