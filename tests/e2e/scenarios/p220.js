// v54.25 NI2-220: correções do code review pela interface real (seed ni2_220, só dados TESTE).
//  B1 gestora sem CRN não conclui consulta nem grava evolução; colega com CRN conclui e fica como autora
//  B3 peso/cintura fora da faixa voltam com erro no campo e nada é gravado; anamnese com água/sono absurdos idem
//  B2 formulário abre em "Não avaliado", salvar sem marcar grava NULL e a versão exibe "Não avaliado"
//  I8 nenhuma tela mostra texto técnico; I10 selo do prontuário com o texto novo
// Uso: node p220.js <base_url> <arquivo.sqlite>. Cada PASS vem de tela + banco.
const { chromium } = require('playwright');
const fs = require('fs'), path = require('path'), cp = require('child_process');
const B = process.argv[2], DBT = process.argv[3];
const OUT = process.env.E2E_OUT || '/tmp';
const SHOTS = path.join(OUT, 'p220-shots'); fs.mkdirSync(SHOTS, { recursive: true });
const SQM = path.join(__dirname, '..', 'lib', 'sqm.php');
const res = [];
const ok = (c, l) => { res.push(!!c); console.log((c ? '  PASS  ' : '  FAIL  ') + l); };
const clean = (s) => String(s || '').replace(/Error:/g, 'Erro -').replace(/\s+/g, ' ').slice(0, 300);
const q1 = (sql, ...params) => cp.execFileSync('php', [SQM, DBT, sql, ...params.map(String)]).toString().trim().split('\n')[0] || '';
const n = (sql, ...p) => +q1(sql, ...p);
const shot = async (p, name) => { await p.screenshot({ path: path.join(SHOTS, name + '.png'), fullPage: true }).catch(() => {}); };
const TECH = /SQLSTATE|PDOException|Stack trace|\.php:\d+|Uncaught|Fatal error|Warning:|Notice:|Deprecated:/;
const section = async (name, fn) => { console.log('\n== ' + name); try { await fn(); } catch (e) { ok(false, name + ': exceção inesperada — ' + clean(e.message)); } };

(async () => {
  const b = await chromium.launch();
  const ids = { clinicA: +q1('SELECT id FROM clinics WHERE name = ?', 'Clínica TESTE A') };
  for (const [k, email] of Object.entries({ a: 'nutri.homolog.a@teste.invalid', a2: 'nutri.homolog.a2@teste.invalid', adm: 'gestora.semcrn.a@teste.invalid' })) {
    const [uid, pid] = q1('SELECT u.id, p.id FROM users u LEFT JOIN professionals p ON p.user_id = u.id WHERE u.email = ?', email).split('|');
    ids[k] = { user: +uid, prof: pid ? +pid : null };
  }
  ids.pid = n('SELECT id FROM patients WHERE name = ?', 'Paciente TESTE 220');
  ids.app = n('SELECT id FROM appointments WHERE patient_id = ? ORDER BY id DESC LIMIT 1', ids.pid);
  console.log('ids ' + JSON.stringify(ids));
  const asUser = async (who, to, w = 1280) => {
    const ctx = await b.newContext({ viewport: { width: w, height: 900 } }); const p = await ctx.newPage(); p.setDefaultTimeout(15000);
    p.on('dialog', d => d.accept()); p.on('pageerror', e => console.log('   [erro de JS na página] ' + clean(e.message)));
    await p.goto(B + `/__teste_login?u=${ids[who].user}&c=${ids.clinicA}&v=0&to=${encodeURIComponent(to)}`); return { ctx, p };
  };
  const recs = () => n('SELECT COUNT(*) FROM clinical_records WHERE patient_id = ?', ids.pid);
  const status = () => q1('SELECT status FROM appointments WHERE id = ?', ids.app);
  const submitConsult = async (p, data) => {
    await p.goto(B + `/consulta/${ids.app}`);
    await p.fill('#evolution_summary', data.summary);
    await p.fill('#dc9-weight_kg', data.weight || ''); await p.fill('#dc10-waist_cm', data.waist || '');
    if (await p.locator('[data-testid=confirm-pending-review]').count()) await p.locator('[data-testid=confirm-pending-review]').check(); // NI2-706
    await p.evaluate(() => { const f = document.querySelector('form[action$="/concluir"]'); if (f) f.noValidate = true; });
    await Promise.all([p.waitForLoadState('load'), p.locator('form[action$="/concluir"] button[type=submit]').click()]);
    await p.waitForTimeout(300);
  };

  await section('B1 gestora sem CRN não conclui consulta', async () => {
    const { ctx, p } = await asUser('adm', `/consulta/${ids.app}`);
    const r0 = recs();
    await submitConsult(p, { summary: 'Evolução TESTE escrita pela gestora sem CRN', weight: '70' });
    const err = p.locator('[data-testid=consult-submit-error]');
    const txt = (await err.textContent().catch(() => '')) || '';
    ok(await err.getAttribute('data-error').catch(() => '') === 'sem_crn' && /CRN/.test(txt), `B1.1 aviso de CRN na sala de consulta ("${clean(txt).slice(0, 120)}")`);
    ok(recs() === r0 && status() === 'in_progress', `B1.2 nada gravado e consulta segue em andamento (registros ${r0}→${recs()}, status ${status()})`);
    ok(await p.inputValue('#evolution_summary').catch(() => '') !== '', 'B1.3 texto digitado continua no formulário');
    ok(!TECH.test(await p.textContent('body')), 'B1.4 sem texto técnico na tela');
    await shot(p, 'b1-gestora-sem-crn');
    await ctx.close();
  });

  await section('B3 medidas fora da faixa', async () => {
    const { ctx, p } = await asUser('a2', `/consulta/${ids.app}`);
    const r0 = recs(), a0 = n('SELECT COUNT(*) FROM anthropometric_assessments WHERE patient_id = ?', ids.pid);
    await submitConsult(p, { summary: 'Evolução TESTE com medidas digitadas erradas', weight: '1000', waist: '3' });
    const err = p.locator('[data-testid=consult-submit-error]');
    ok(await err.getAttribute('data-error').catch(() => '') === 'medidas_invalidas', 'B3.1 erro "medidas inválidas" na sala de consulta');
    const wErr = (await p.textContent('#err_weight_kg').catch(() => '')) || '', cErr = (await p.textContent('#err_waist_cm').catch(() => '')) || '';
    ok(/entre 10 e 400 kg/.test(wErr) && /entre 10 e 250 cm/.test(cErr), `B3.2 mensagem no campo de peso e de cintura ("${clean(wErr)}" / "${clean(cErr)}")`);
    ok(await p.getAttribute('#dc9-weight_kg', 'aria-invalid') === 'true', 'B3.3 campo de peso marcado como inválido para leitor de tela');
    ok(recs() === r0 && n('SELECT COUNT(*) FROM anthropometric_assessments WHERE patient_id = ?', ids.pid) === a0 && status() === 'in_progress', 'B3.4 nada gravado no prontuário nem na antropometria');
    await shot(p, 'b3-medidas-invalidas');
    // B1: a colega com CRN conclui a consulta agendada para a nutri A
    await submitConsult(p, { summary: 'Evolução TESTE concluída pela colega A2', weight: '68.5', waist: '80' });
    const row = q1("SELECT professional_id, content_json FROM clinical_records WHERE patient_id = ? ORDER BY id DESC LIMIT 1", ids.pid).split('|');
    const content = JSON.parse(row.slice(1).join('|') || '{}');
    ok(status() === 'completed' && +row[0] === ids.a2.prof, `B1.5 colega com CRN conclui e a evolução fica com o CRN dela (prof ${row[0]}, esperado ${ids.a2.prof})`);
    ok(+content.scheduled_professional_id === ids.a.prof && Math.abs(+content.weight_kg - 68.5) < 0.01, 'B1.6 profissional agendado registrado à parte e peso 68.5 kg gravado');
    const anth = q1('SELECT professional_id FROM anthropometric_assessments WHERE patient_id = ? ORDER BY id DESC LIMIT 1', ids.pid);
    ok(+anth === ids.a2.prof, `B1.7 antropometria da conclusão com o mesmo autor (${anth})`);
    await ctx.close();
  });

  await section('I10 selo do prontuário', async () => {
    const { ctx, p } = await asUser('a2', `/pacientes/${ids.pid}/prontuario`);
    const t = (await p.textContent('main')).replace(/\s+/g, ' ');
    ok(/Encadeamento dos registros conferido, sem divergências/.test(t) && !/autêntico/i.test(t), 'I10.1 selo diz "encadeamento conferido", sem prometer autenticidade');
    ok(!TECH.test(t), 'I10.2 prontuário sem texto técnico');
    await shot(p, 'i10-prontuario');
    await ctx.close();
  });

  await section('B2 anamnese abre em "Não avaliado" e B3 recusa valores absurdos', async () => {
    const { ctx, p } = await asUser('a', `/pacientes/${ids.pid}/anamnese`);
    const enums = ['has_surgeries', 'medication_usage', 'supplement_usage', 'intolerance_assessment', 'alcohol_consumption', 'smoking_status', 'physical_activity'];
    const vals = await p.evaluate((names) => names.map(nm => { const s = document.querySelector(`#anamnesis-form select[name="${nm}"]`); return s ? [s.value, s.options[s.selectedIndex]?.text] : null; }), enums);
    ok(vals.every(v => v && v[0] === '' && v[1] === 'Não avaliado'), `B2.1 as 7 perguntas fechadas abrem em "Não avaliado" (${JSON.stringify(vals)})`);
    const a0 = n('SELECT COUNT(*) FROM anamneses WHERE patient_id = ?', ids.pid);
    await p.fill('#anamnesis_chief_complaint', 'Queixa TESTE 220');
    await p.fill('#anamnesis_water_intake_ml', '99999'); await p.fill('#anamnesis_sleep_hours', '30');
    await p.evaluate(() => { document.querySelector('#anamnesis-form').noValidate = true; });
    await Promise.all([p.waitForLoadState('load'), p.click('#anamnesis-form button[name=save_mode][value=final]')]);
    await p.waitForTimeout(300);
    const alertTxt = (await p.locator('#anamnesis-form [role=alert]').allTextContents()).join(' ');
    const wErr = (await p.textContent('#err_water_intake_ml').catch(() => '')) || '';
    ok(/10\.000 ml/.test(alertTxt) && /24 horas/.test(alertTxt) && /10\.000 ml/.test(wErr), `B3.5 anamnese avisa água e sono fora da faixa, no aviso e junto do campo ("${clean(alertTxt).slice(0, 160)}")`);
    ok(await p.getAttribute('#anamnesis_water_intake_ml', 'aria-invalid').catch(() => null) === 'true' && await p.getAttribute('#anamnesis_sleep_hours', 'aria-invalid').catch(() => null) === 'true', 'B3.6 campos de água e sono marcados como inválidos');
    ok(await p.inputValue('#anamnesis_water_intake_ml') === '99999', 'B3.6b valor digitado continua na tela para corrigir (sem corte silencioso)');
    ok(n('SELECT COUNT(*) FROM anamneses WHERE patient_id = ?', ids.pid) === a0, 'B3.7 nenhuma versão gravada com dado inválido');
    ok(!TECH.test(await p.textContent('body')), 'B3.8 sem texto técnico na tela');
    await shot(p, 'b3-anamnese-invalida');
    // mesmo envio sem JavaScript: o servidor devolve a lista de erros e marca os campos
    { const ctx2 = await b.newContext({ javaScriptEnabled: false, viewport: { width: 1280, height: 900 } }); const p2 = await ctx2.newPage();
      await p2.goto(B + `/__teste_login?u=${ids.a.user}&c=${ids.clinicA}&v=0&to=${encodeURIComponent('/pacientes/' + ids.pid + '/anamnese')}`);
      await p2.fill('#anamnesis_chief_complaint', 'Queixa TESTE 220 sem JS');
      // desliga só a validação do navegador, para o valor chegar ao servidor como chegaria de um cliente qualquer
      await p2.locator('#anamnesis-form').evaluate(f => { f.noValidate = true; }).catch(() => {});
      const nojsOk = await p2.locator('#anamnesis-form').evaluate(f => f.noValidate).catch(() => false);
      if (nojsOk) {
        await p2.fill('#anamnesis_water_intake_ml', '99999');
        await Promise.all([p2.waitForURL(/[?&](error|success)=/, { timeout: 15000 }).catch(() => {}), p2.click('#anamnesis-form button[name=save_mode][value=final]')]);
        const box2 = (await p2.textContent('[data-testid=anamnese-field-errors]').catch(() => '')) || '';
        if (!/error=dados_invalidos/.test(p2.url())) console.log('   sem JS url=' + p2.url() + ' corpo=' + clean(await p2.textContent('main').catch(() => '')).slice(0, 300));
        ok(/error=dados_invalidos/.test(p2.url()) && /10\.000 ml/.test(box2) && await p2.getAttribute('#anamnesis_water_intake_ml', 'aria-invalid') === 'true', `B3.5b sem JavaScript: lista de erros e campo marcado (${new URL(p2.url()).search.slice(0, 40)})`);
        ok(n('SELECT COUNT(*) FROM anamneses WHERE patient_id = ?', ids.pid) === a0, 'B3.5c sem JavaScript: nada gravado');
        await shot(p2, 'b3-anamnese-invalida-sem-js');
      } else console.log('   (sem JavaScript não dá para tirar o max do campo; conferido só pelo teste PHP)');
      await ctx2.close(); }
    // corrige e conclui sem marcar as perguntas fechadas
    await p.goto(B + `/pacientes/${ids.pid}/anamnese`);
    await p.fill('#anamnesis_chief_complaint', 'Queixa TESTE 220');
    await p.fill('#anamnesis_water_intake_ml', '2000'); await p.fill('#anamnesis_sleep_hours', '7,5').catch(async () => p.fill('#anamnesis_sleep_hours', '7.5'));
    await Promise.all([p.waitForLoadState('load'), p.click('#anamnesis-form button[name=save_mode][value=final]')]);
    await p.waitForTimeout(300);
    const row = q1('SELECT smoking_status, physical_activity, has_surgeries, water_intake_ml, is_draft FROM anamneses WHERE patient_id = ? ORDER BY id DESC LIMIT 1', ids.pid);
    ok(row.startsWith('|||2000|0'), `B2.2 concluída sem marcar: perguntas fechadas gravadas como NULL (${row})`);
    const v = n('SELECT version FROM anamneses WHERE patient_id = ? AND is_draft = 0 ORDER BY id DESC LIMIT 1', ids.pid);
    await p.goto(B + `/pacientes/${ids.pid}/anamnese?versao=${v}`);
    let t = await p.textContent('main');
    if (!/Não avaliado/.test(t)) { await p.goto(B + `/pacientes/${ids.pid}/anamnese/versao/${v}`).catch(() => {}); t = await p.textContent('main'); }
    ok(/Não avaliado/.test(t), 'B2.3 versão concluída exibe "Não avaliado"');
    await shot(p, 'b2-versao-nao-avaliado');
    await ctx.close();
  });

  await section('I9 telas em 390 px sem rolagem lateral', async () => {
    for (const url of [`/pacientes/${ids.pid}/prontuario`, `/pacientes/${ids.pid}/anamnese`, `/consulta/${ids.app}`]) {
      const { ctx, p } = await asUser('a', url, 390);
      const over = await p.evaluate(() => document.documentElement.scrollWidth - window.innerWidth);
      // só o conteúdo da tela (o cabeçalho do shell é comum a todas as páginas); variável CSS posta pelo JS não conta
      const inlList = await p.evaluate(() => [...document.querySelectorAll('[data-testid=consulta-page] [style], [data-testid=anamnese-page] [style], [data-testid=prontuario-page] [style]')]
        
        .filter(e => !e.closest('[data-testid=patient-header]'))
        .filter(e => !/^\s*--[\w-]+:[^;]+;?\s*$/.test(e.getAttribute('style'))).map(e => e.tagName + '#' + e.id + '=' + e.getAttribute('style')));
      const inl = inlList.length;
      if (inl) console.log('   inline: ' + JSON.stringify(inlList).slice(0, 600));
      ok(over <= 1, `I9 ${url} cabe em 390 px (excesso ${over}px)`);
      ok(inl === 0, `I9 ${url} sem estilo inline no conteúdo (${inl})`);
      await ctx.close();
    }
  });

  await b.close();
  const f = res.filter(x => !x).length;
  console.log(`\n${res.length - f} PASS, ${f} FAIL`); process.exit(f ? 1 : 0);
})();
