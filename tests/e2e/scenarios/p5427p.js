// v54.27 Prontuário: evolução gravada na sala de teleconsulta aparece como texto (Anotações, Metas até o retorno
// só quando preenchidas, selo "Registrada durante a teleconsulta" com link para a consulta), nunca como JSON.
// Retificação abre com o texto vigente e mantém anotações/metas. Impressão sem chaves. 1280 e 390 px, axe, CSP.
// Dados TESTE, SQLite descartável.
const { chromium } = require('playwright');
const { execSync } = require('child_process');
const fs = require('fs');
const res = []; const ok = (c, l) => { res.push(!!c); console.log((c ? '  PASS  ' : '  FAIL  ') + l); };
const clean = s => String(s).replace(/Error:/g, 'Erro -');
(async () => {
  const B = process.argv[2], DB = process.argv[3];
  const sq = q => execSync(`php ${process.env.E2E_SQ} ${DB} "${q}"`).toString().trim();
  const ids = JSON.parse(fs.readFileSync(DB + '.ids', 'utf8')); fs.unlinkSync(DB + '.ids');
  const AXE = [process.env.E2E_AXE, '/workspace/m1103/n233-tools/node_modules/axe-core/axe.min.js', (() => { try { return require.resolve('axe-core/axe.min.js'); } catch (e) { return ''; } })()].find(f => f && fs.existsSync(f));
  const AXE_SRC = AXE ? fs.readFileSync(AXE, 'utf8') : '';
  const hashes0 = sq('SELECT group_concat(record_hash) FROM clinical_records');
  const b = await chromium.launch();
  const errs = [], csp = [];
  const watch = p => { p.on('pageerror', e => errs.push(clean(e.message))); p.on('console', m => { if (/Content Security Policy/i.test(m.text())) csp.push(clean(m.text())); }); p.on('dialog', d => d.accept()); };
  const TL = 'section[aria-labelledby="cr-timeline-title"]';
  for (const w of [1280, 390]) {
    console.log(`== Prontuário em ${w} px`);
    const c = await b.newContext({ viewport: { width: w, height: w > 500 ? 860 : 844 } });
    const p = await c.newPage(); watch(p);
    await p.goto(`${B}/__teste_login?u=1&c=1&v=0&to=${encodeURIComponent('/pacientes/2/prontuario')}`);
    await p.waitForSelector(TL);
    const txt = await p.locator(TL).innerText();
    ok(!txt.includes('{') && !txt.includes('}'), `${w}: linha do tempo sem "{" nem "}"`);
    ok(!/"notes"|"goals"|teleconsultation|appointment_id|recorded_during|source/i.test(txt), `${w}: nenhuma chave interna visível`);
    ok(txt.includes('durante a consulta TESTE a paciente relatou fome à noite.'), `${w}: anotação da teleconsulta em texto`);
    ok((txt.match(/Metas até o retorno/gi) || []).length === 1 && txt.includes('Caminhar 30 min, 3x por semana.'), `${w}: metas só na evolução que tem metas`);
    ok(await p.locator('[data-testid="cr-entry-tele"]').count() === 2 && (txt.match(/Registrada durante a teleconsulta/g) || []).length === 2, `${w}: selo "Registrada durante a teleconsulta" nas duas evoluções da sala`);
    ok(await p.locator(`${TL} a[href="/consulta/${ids.appointment_id}"]`).count() === 2, `${w}: link "Ver consulta" para a consulta de origem`);
    ok(await p.locator(`${TL} pre`).count() === 0, `${w}: sem bloco <pre>`);
    const over = await p.evaluate(() => document.documentElement.scrollWidth - document.documentElement.clientWidth);
    ok(over <= 1, `${w}: sem rolagem horizontal (${over}px)`);
    const tgt = await p.locator(`${TL} .cr-entry__origin-link`).first().boundingBox();
    ok(tgt && tgt.height >= 24, `${w}: link "Ver consulta" com alvo de ao menos 24 px`);
    if (AXE_SRC) {
      await p.evaluate(AXE_SRC);
      const v = await p.evaluate(async sel => (await window.axe.run(document.querySelector(sel), { resultTypes: ['violations'] })).violations.filter(x => ['serious', 'critical'].includes(x.impact)).map(x => x.id), TL);
      ok(v.length === 0, `${w}: axe sem violação séria/crítica na linha do tempo` + (v.length ? ': ' + v.join(', ') : ''));
    } else ok(false, 'axe-core disponível');
    await c.close();
  }

  console.log('== Retificação abre com o texto vigente');
  const c = await b.newContext({ viewport: { width: 1280, height: 860 } });
  const p = await c.newPage(); watch(p);
  await p.goto(`${B}/__teste_login?u=1&c=1&v=0&to=${encodeURIComponent('/pacientes/2/prontuario')}`);
  const withGoals = sq("SELECT id FROM clinical_records WHERE title='Evolução — teleconsulta com metas'");
  const plain = sq("SELECT id FROM clinical_records WHERE title='Evolução Clínica — retorno TESTE'");
  await p.click(`[data-rev-id="${plain}"]`);
  ok(await p.inputValue('#dc8-revised_text') === 'Paciente TESTE relata boa adesão ao plano.\nManter hidratação.', 'anotação comum: texto vigente no campo');
  ok(await p.locator('#dc11-goals-field').isHidden() && await p.locator('#dc11-revised_goals').isDisabled(), 'anotação comum: sem campo de metas');
  await p.click('#modal-revise-record [data-hide="modal-revise-record"]');
  await p.click(`[data-rev-id="${withGoals}"]`);
  const nv = await p.inputValue('#dc8-revised_text'), gv = await p.inputValue('#dc11-revised_goals');
  ok(nv === 'Retorno TESTE por vídeo, sem queixas.' && !nv.includes('{'), 'teleconsulta: anotações no campo, sem JSON');
  ok(await p.locator('#dc11-goals-field').isVisible() && gv === 'Caminhar 30 min, 3x por semana.\nIncluir uma fruta no café da manhã.', 'teleconsulta: metas no campo próprio');
  ok(/Anotações corrigidas/.test(await p.locator('#dc8-label').innerText()), 'rótulo do campo vira "Anotações corrigidas"');
  await p.fill('#dc7-revision_reason', 'Correção TESTE das metas combinadas');
  await p.fill('#dc8-revised_text', 'Retorno TESTE por vídeo, sem queixas. Revisado.');
  await p.fill('#dc11-revised_goals', 'Caminhar 40 min, 3x por semana.');
  await Promise.all([p.waitForNavigation(), p.click('#form-revise-record button[type=submit]')]);
  const t2 = await p.locator(TL).innerText();
  ok(t2.includes('Retorno TESTE por vídeo, sem queixas. Revisado.') && t2.includes('Caminhar 40 min, 3x por semana.'), 'versão retificada mostra anotações e metas novas');
  ok(!t2.includes('{') && !/"notes"|teleconsultation/i.test(t2), 'versão retificada sem JSON');
  ok((t2.match(/Registrada durante a teleconsulta/g) || []).length === 3, 'retificação mantém o selo da teleconsulta');
  const rc = JSON.parse(sq("SELECT content_json FROM clinical_records ORDER BY version DESC LIMIT 1"));
  ok(rc.notes === 'Retorno TESTE por vídeo, sem queixas. Revisado.' && rc.goals === 'Caminhar 40 min, 3x por semana.' && rc.text === undefined && rc.recorded_during_telehealth === true, 'conteúdo novo gravado em notes/goals, com a origem');
  ok(sq('SELECT group_concat(record_hash) FROM clinical_records').startsWith(hashes0), 'códigos de integridade anteriores intactos');
  ok(/sem divergências/.test(await p.locator('[data-testid="integrity-status"]').innerText()), 'encadeamento conferido depois da retificação');

  console.log('== Impressão');
  await p.goto(`${B}/pacientes/2/prontuario/imprimir`);
  const pt = await p.locator('body').innerText();
  ok(!/"notes"|"goals"|teleconsultation|appointment_id|recorded_during|Source/i.test(pt) && !pt.includes('{'), 'impressão sem JSON nem chave interna');
  ok(/Anotações/i.test(pt) && /Metas até o retorno/i.test(pt) && /registrada durante a teleconsulta/i.test(pt), 'impressão com Anotações, Metas e origem');
  ok(errs.length === 0, 'sem erro de JavaScript' + (errs.length ? ': ' + errs.slice(0, 3).join(' | ') : ''));
  ok(csp.length === 0, 'CSP sem bloqueios' + (csp.length ? ': ' + csp.slice(0, 2).join(' | ') : ''));
  await b.close();
  console.log(`${res.filter(Boolean).length} PASS, ${res.filter(x => !x).length} FAIL`);
  process.exit(res.every(Boolean) ? 0 : 1);
})().catch(e => { console.log('  FAIL  exceção: ' + clean(e.message)); process.exit(1); });
