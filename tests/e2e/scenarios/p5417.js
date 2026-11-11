// NI2-623 (etapas do atendimento conferidas no banco) e NI2-629 (contexto clínico persistente).
const { chromium } = require('playwright');
const { execSync } = require('child_process');
const res=[]; const ok=(c,l)=>{res.push(!!c);console.log((c?'  PASS  ':'  FAIL  ')+l);};
(async()=>{
  const B=process.argv[2], DB=process.argv[3];
  const sq=q=>execSync(`php ${process.env.E2E_SQ} ${DB} "${q}"`).toString().trim();
  sq("UPDATE appointments SET status='in_progress', scheduled_at=datetime('now','localtime') WHERE id=1");
  const b=await chromium.launch(); const ctx=await b.newContext({viewport:{width:1280,height:900}}); const p=await ctx.newPage();
  const csp=[]; const errs=[]; p.on('console',m=>{if(/Content Security Policy/i.test(m.text()))csp.push(m.text())}); p.on('pageerror',e=>errs.push(e.message));
  const flow=async()=>p.$$eval('[data-testid="care-flow"] [data-step]',ls=>ls.map(l=>l.dataset.step+':'+l.dataset.state+(l.classList.contains('is-next')?'*':'')).join(' '));
  await p.goto(B+'/__teste_login?u=1&c=1&v=0&to=/pacientes/2');

  console.log('== NI2-623/686 etapas do atendimento (contrato da v54.19: vínculo explícito ao atendimento)');
  const confirmStep=async(step)=>{ const li=p.locator(`[data-testid="care-flow"] [data-step="${step}"]`); await li.locator('details summary').click();
    await li.locator('select[name="decision"]').selectOption('confirm'); await li.locator('button[type="submit"]').click(); await p.waitForLoadState(); };
  let f=await flow();
  ok(/anamnese:in_progress/.test(f) && /avaliacao:pending/.test(f) && /revisao:pending/.test(f), 'em andamento: anamnese com rascunho, avaliação e revisão a fazer ['+f+']');
  ok(/Continuar Anamnese/.test(await p.textContent('[data-testid="care-flow-next"]')), 'próxima ação: "Continuar Anamnese"');
  ok((await p.getAttribute('[data-step="anamnese"] a','href'))==='/pacientes/2/anamnese?atendimento=1' && (await p.getAttribute('[data-step="avaliacao"] a','href'))==='/pacientes/2/antropometria?atendimento=1', 'cada etapa é um link (ordem é só sugestão)');
  for (const u of ['/pacientes/2/anamnese','/pacientes/2/antropometria','/planos/1','/pacientes/2/exames']) { await p.goto(B+u); ok(await p.isVisible('[data-testid="care-flow"]'), 'etapas visíveis em '+u); }
  await p.goto(B+'/consulta/1');
  ok(await p.isVisible('[data-testid="patient-context-only"] [data-testid="care-flow"]') && !(await p.$('[data-testid="patient-tabs"]')), 'tela da consulta: etapas e contexto, sem repetir as abas');
  // conclui a anamnese (banco) e recarrega: registro do mesmo dia NÃO conclui a etapa sozinho
  sq("UPDATE anamneses SET is_draft=0, created_at=datetime('now','localtime') WHERE patient_id=2 AND version=2");
  await p.goto(B+'/pacientes/2'); f=await flow();
  ok(/anamnese:review/.test(f) && !/anamnese:done/.test(f), 'anamnese concluída hoje fica "revisão pendente" até ser vinculada ao atendimento ['+f+']');
  ok(!(await p.$('[data-testid="care-flow"] #care-progress-meter, [data-testid="care-flow"] progress')) && /Sem percentual/.test(await p.textContent('[data-testid="care-flow"]')), 'sem aplicabilidade definida: nenhum percentual');
  await confirmStep('anamnese'); await p.goto(B+'/pacientes/2'); f=await flow();
  ok(/anamnese:done/.test(f) && sq("SELECT COUNT(*) FROM care_progress_events WHERE appointment_id=1 AND step_key='anamnese' AND decision='confirm'")==='1', 'vínculo explícito: anamnese concluída (banco) ['+f+']');
  sq("INSERT INTO anthropometric_assessments (clinic_id, patient_id, professional_id, weight_kg, height_cm, bmi, assessed_at, created_at) VALUES (1, 2, 1, 70.2, 165, 25.8, datetime('now','localtime'), datetime('now','localtime'))");
  await p.reload(); f=await flow();
  ok(/avaliacao:review/.test(f) && /plano:in_progress/.test(f) && /Continuar Plano/.test(await p.textContent('[data-testid="care-flow-next"]')), 'avaliação gravada fica para vincular; próxima ação: plano em rascunho ['+f+']');
  await confirmStep('avaliacao'); await p.reload(); f=await flow();
  ok(/anamnese:done/.test(f) && /avaliacao:done/.test(f) && /plano:in_progress/.test(f), 'recarregar mantém as etapas vinculadas ['+f+']');

  console.log('== NI2-629 contexto clínico');
  const det=p.locator('[data-testid="clinical-context"]');
  ok(!(await det.evaluate(d=>d.open)), 'recolhido por padrão (privacidade)');
  await det.locator('summary').focus(); await p.keyboard.press('Enter');
  ok(await det.evaluate(d=>d.open) && await p.isVisible('[data-testid="context-allergies"]'), 'abre pelo teclado, sem trocar de página');
  const al=await p.textContent('[data-testid="context-allergies"]');
  ok(/amendoim/i.test(al) && /anamnese v\d+/i.test(al) && !/Não registrado\. Ausência/.test(al), 'alergia com avaliação e origem, sem "Não registrado" junto da revisão ('+al.replace(/\s+/g,' ').trim().slice(0,90)+')');
  ok(/Não registrado/.test(await p.textContent('[data-testid="context-restrictions"]')), 'dado ausente é dito ("Não registrado")');
  ok(/70,2 kg/.test(await p.textContent('[data-testid="context-assessment"]')) && /IMC 25,8/.test(await p.textContent('[data-testid="context-assessment"]')), 'última avaliação: 70,2 kg · IMC 25,8');
  await p.goto(B+'/pacientes/2'); await p.locator('[data-testid="clinical-context"] summary').first().click();
  const ap=await p.textContent('[data-testid="context-appointment"]');
  ok(/Consulta/.test(ap) && /Em andamento/.test(ap) && await p.isVisible('[data-testid="context-appointment"] a[href="/consulta/1"]'), 'contexto da consulta: em andamento, com "Abrir consulta" (v54.26 devolveu o item Consulta: '+ap.replace(/\s+/g,' ').trim()+')');
  await p.goto(B+'/consulta/1'); await p.locator('[data-testid="clinical-context"] summary').first().click();
  const apc=await p.textContent('[data-testid="clinical-context"] [data-testid="context-appointment"]');
  ok(/Em andamento/.test(apc) && (await p.locator('[data-testid="clinical-context"] [data-testid="context-appointment"] a').count())===0, 'na tela da consulta: item Consulta "Em andamento" sem link para ela mesma');
  await p.goto(B+'/planos/1'); ok(/Atendimento em andamento/.test(await p.textContent('[data-testid="patient-care-context"]')) && (await p.getAttribute('[data-testid="care-next-step"]','data-state'))==='continue', 'consulta em andamento indicada também no cabeçalho do paciente');
  // rascunho nunca como vigente
  sq("INSERT INTO anamneses (clinic_id, patient_id, professional_id, version, allergies, allergy_assessment, is_draft, created_at) VALUES (1, 2, 1, 3, NULL, 'not_assessed', 1, datetime('now','localtime'))");
  await p.goto(B+'/planos/1'); await p.click('[data-testid="clinical-context"] summary');
  const an=await p.textContent('[data-testid="context-anamnesis"]');
  ok(/v2 concluída/.test(an) && /Rascunho v3/.test(an) && /Não substitui a versão vigente/.test(an), 'no plano: v2 vigente e rascunho v3 marcado como "não substitui a versão vigente" (NI2-685)');
  ok(await p.getAttribute('[data-testid="patient-tabs"] a[aria-current="page"]','data-tab')==='plano', 'plano: cabeçalho do paciente com a aba Plano atual');
  // larguras
  for (const w of [375,656,1440]) {
    await p.setViewportSize({width:w,height:900}); let bad=[];
    for (const u of ['/pacientes/2','/consulta/1','/planos/1']) { await p.goto(B+u); if(!(await p.evaluate(()=>document.documentElement.scrollWidth<=innerWidth+1))) bad.push(u); }
    ok(bad.length===0, w+' px: sem rolagem lateral'+(bad.length?' ('+bad.join(', ')+')':''));
  }
  await p.setViewportSize({width:375,height:900}); await p.goto(B+'/pacientes/2');
  await p.screenshot({path:process.env.E2E_OUT+'/p5417-375.png',fullPage:false});
  await p.setViewportSize({width:1280,height:900}); await p.goto(B+'/pacientes/2'); await p.click('[data-testid="clinical-context"] summary');
  await p.screenshot({path:process.env.E2E_OUT+'/p5417-1280.png',fullPage:false});
  ok(csp.length===0 && errs.length===0, 'sem CSP e sem erro de JS ('+csp.length+'/'+errs.length+(errs[0]?': '+errs[0]:'')+')');
  await b.close();
  const n=res.filter(x=>!x).length; console.log(`\n${res.length-n} PASS, ${n} FAIL`); process.exit(n?1:0);
})();
