// NI2-624: alergia histórica contraditória no perfil — revisão, trava mantida, descarte com motivo, multi-clínica.
const { chromium } = require('playwright');
const { execSync } = require('child_process');
const res=[]; const ok=(c,l)=>{res.push(!!c);console.log((c?'  PASS  ':'  FAIL  ')+l);};
(async()=>{
  const B=process.argv[2], DB=process.argv[3];
  const sq=q=>execSync(`php ${process.env.E2E_SQ} ${DB} "${q}"`).toString().trim();
  const b=await chromium.launch(); const ctx=await b.newContext({viewport:{width:1280,height:900}}); const p=await ctx.newPage();
  const csp=[]; const errs=[]; p.on('console',m=>{if(/Content Security Policy/i.test(m.text()))csp.push(m.text())}); p.on('pageerror',e=>errs.push(e.message));
  await p.goto(B+'/__teste_login?u=1&c=1&v=0&to=/pacientes/2');
  ok(await p.isVisible('[data-testid="allergy-review"]'), 'perfil: "Alergia exige revisão clínica"');
  const item=await p.textContent('[data-testid="allergy-review-item"]');
  ok(/anamnese v1/.test(item) && /Amendoim/.test(item) && await p.getAttribute('[data-testid="allergy-review-item"]','data-group')==='amendoim', 'diz qual versão registrou o quê');
  ok(/Alergia em revisão: amendoim/.test(await p.textContent('[data-testid="profile-allergy-badge"]')), 'selo: "Alergia em revisão: amendoim" (não "nega")');
  await p.screenshot({path:process.env.E2E_OUT+'/p5413-revisao.png',fullPage:false});
  // trava continua: resumo clínico (usado pelo plano) e busca de alimento para o paciente
  const sum=await p.evaluate(async()=>(await fetch('/pacientes/2/resumo-prescricao')).json());
  ok(sum.allergy_state==='recorded' && (sum.critical_alerts||[]).some(a=>a.type==='ALERGIA_REVISAO'), 'resumo clínico do plano: alerta "Exige revisão clínica"');
  const fs=await p.evaluate(async()=>{const r=await fetch('/catalogo/alimentos/busca?q=amendoim&patient_id=2');return r.ok?await r.text():'HTTP '+r.status;});
  ok(/amendoim/i.test(fs) && /conflict|alerg|bloq/i.test(fs), 'busca de alimentos marca amendoim para o paciente');
  // teclado: abre o descarte pelo teclado
  await p.focus('.allergy-review__dismiss summary'); await p.keyboard.press('Enter');
  ok(await p.isVisible('textarea[name="reason"]'), 'teclado abre "A alergia foi descartada clinicamente"');
  await p.fill('textarea[name="reason"]','abc'); await p.click('.allergy-review__dismiss button[type="submit"]');
  ok(/\/pacientes\/2$/.test(new URL(p.url()).pathname) && await p.isVisible('[data-testid="allergy-review"]') && sq("SELECT COUNT(*) FROM audit_logs WHERE action='ALLERGY_HISTORY_DISMISSED'")==='0', 'motivo curto: nada é gravado');
  await p.fill('textarea[name="reason"]','Teste de provocação oral TESTE negativo'); await p.click('.allergy-review__dismiss button[type="submit"]'); await p.waitForLoadState();
  ok(await p.isVisible('[data-testid="allergy-review-done"]') && !(await p.isVisible('[data-testid="allergy-review"]')), 'descarte registrado: aviso some e confirmação aparece');
  ok(!/Alergia em revisão/.test(await p.textContent('[data-testid="profile-allergy-badge"]')), 'selo volta ao que a anamnese vigente diz');
  const det=JSON.parse(sq("SELECT details FROM audit_logs WHERE action='ALLERGY_HISTORY_DISMISSED'")||'{}');
  ok(det.group==='amendoim' && /provocação/.test(det.reason) && det.through_version===2, 'histórico: grupo, motivo e versão');
  ok(sq("SELECT COUNT(*) FROM anamneses WHERE patient_id=2")==='2', 'nenhuma versão da anamnese apagada');
  // clínica B tenta descartar para paciente da A
  sq("DELETE FROM audit_logs WHERE action='ALLERGY_HISTORY_DISMISSED'");
  const ctxB=await b.newContext(); const pb=await ctxB.newPage();
  await pb.goto(B+'/__teste_login?u=2&c=2&v=0&to=/dashboard');
  const st=await pb.evaluate(async()=>{const tok=document.querySelector('input[name="_csrf_token"]')?.value||document.querySelector('meta[name="csrf-token"]')?.content||'';
    const r=await fetch('/pacientes/2/alergias/revisao',{method:'POST',body:new URLSearchParams({_csrf_token:tok,group:'amendoim',reason:'Tentativa TESTE de outra clinica'}),redirect:'manual'});return r.status;});
  ok(sq("SELECT COUNT(*) FROM audit_logs WHERE action='ALLERGY_HISTORY_DISMISSED'")==='0', 'clínica B não registra descarte para paciente da A (HTTP '+st+')');
  await ctxB.close();
  await p.goto(B+'/pacientes/2');
  ok(await p.isVisible('[data-testid="allergy-review"]'), 'sem descarte válido, a revisão volta');
  await p.setViewportSize({width:375,height:800}); await p.goto(B+'/pacientes/2');
  ok(await p.evaluate(()=>document.documentElement.scrollWidth<=innerWidth+1), '375 px: sem rolagem horizontal');
  await p.screenshot({path:process.env.E2E_OUT+'/p5413-375.png',fullPage:false});
  ok(csp.length===0 && errs.length===0, 'sem CSP e sem erro de JS ('+csp.length+'/'+errs.length+')');
  await b.close();
  const f=res.filter(x=>!x).length; console.log(`\n${res.length-f} PASS, ${f} FAIL`); process.exit(f?1:0);
})();
