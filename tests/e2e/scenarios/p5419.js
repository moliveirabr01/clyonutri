// NI2-635 v54.16 — pendências: sugestão ignora familiar e explica (NI2-640), dispensar/reativar módulo (NI2-642),
// conflito de gravação e comparação entre conclusões (NI2-652), larguras 375/656/1280/1440. Protocolo TESTE; confere o banco.
const { chromium } = require('playwright');
const { execSync } = require('child_process');
const res=[]; const ok=(c,l)=>{res.push(!!c);console.log((c?'  PASS  ':'  FAIL  ')+l);};
(async()=>{
  const B=process.argv[2], DB=process.argv[3];
  const sq=q=>execSync(`php ${process.env.E2E_SQ} ${DB} "${q}"`).toString().trim();
  const b=await chromium.launch(); const errs=[]; const csp=[];
  const page=async(vw)=>{const ctx=await b.newContext({viewport:vw||{width:1280,height:900}}); const p=await ctx.newPage();
    p.on('pageerror',e=>errs.push(e.message)); p.on('console',m=>{if(/Content Security Policy/i.test(m.text()))csp.push(m.text())}); p.on('dialog',d=>d.accept());
    await p.goto(B+'/__teste_login?u=1&c=1&v=0&to='+encodeURIComponent('/pacientes/2/anamnese')); return p;};
  const n=await page();

  console.log('== NI2-640: sugestão explica e ignora familiar');
  await n.click('[data-testid="condition-suggest"]'); await n.waitForLoadState();
  const fl=await n.locator('[data-testid="cp-flash"]').innerText();
  ok(/1 sugestão/.test(fl) && /Ignorado: "Minha mãe tem condicao teste alfa" \(outra pessoa/.test(fl), 'aviso diz o que foi sugerido e o que foi ignorado, com o trecho');
  const sug=n.locator('[data-testid="condition"][data-status="suggested"]');
  ok(/Sugerido porque o texto diz: "Acho que tenho condicao teste alfa/.test(await sug.innerText()) && /dúvida/.test(await sug.innerText()), 'sugestão mostra o motivo e a dúvida');
  ok(sq("SELECT COUNT(*) FROM patient_conditions WHERE patient_id=2")==='1' && sq("SELECT status FROM patient_conditions WHERE patient_id=2")==='suggested', 'uma sugestão só, sem ativar (banco)');
  await sug.locator('select[name="status"]').selectOption('suspected'); await sug.locator('select[name="provenance"]').selectOption('self_reported');
  await sug.locator('[data-testid="condition-confirm"]').click(); await n.waitForLoadState();
  ok(await n.locator('[data-testid="adaptive-form"]').count()===1, 'confirmada: módulo aparece');

  console.log('== NI2-642: dispensar e reativar módulo');
  const d=n.locator('[data-testid="module-dismiss"]'); await d.locator('summary').click();
  await d.locator('input[name="reason"]').fill('Fora do escopo da consulta TESTE'); await d.locator('button[type="submit"]').click(); await n.waitForLoadState();
  ok(sq("SELECT note FROM patient_condition_events WHERE to_status='module_dismissed'")==='Fora do escopo da consulta TESTE' && sq("SELECT status FROM patient_conditions WHERE patient_id=2")==='suspected', 'dispensa gravada com motivo; condição continua (banco)');
  ok(await n.locator('[data-testid="adaptive-form"]').count()===0 && /Fora do escopo da consulta TESTE/.test(await n.locator('[data-testid="adaptive-dismissed"]').innerText()), 'módulo some e aparece em "Módulos dispensados" com o motivo');
  await n.click('[data-testid="module-restore"]'); await n.waitForLoadState();
  ok(await n.locator('[data-testid="adaptive-form"]').count()===1 && sq("SELECT COUNT(*) FROM patient_condition_events WHERE to_status='module_restored'")==='1', 'reativado (banco)');

  console.log('== NI2-652: conflito de gravação');
  const a=await page(); // mesma pessoa, segunda aba aberta antes da gravação
  await n.locator('input[name="answers[teste_e2e.escolha]"][value="a"]').check(); await n.fill('input[name="answers[teste_e2e.medida]"]','10');
  await n.click('[data-testid="adaptive-conclude"]'); await n.waitForLoadState();
  ok(sq("SELECT COUNT(*) FROM protocol_responses WHERE is_draft=0")==='1', 'primeira aba concluiu (banco)');
  const before=sq("SELECT COUNT(*) FROM protocol_responses");
  await a.locator('input[name="answers[teste_e2e.escolha]"][value="b"]').check(); await a.fill('input[name="answers[teste_e2e.medida]"]','33');
  await a.click('[data-testid="adaptive-save-draft"]'); await a.waitForLoadState();
  ok(sq("SELECT COUNT(*) FROM protocol_responses")===before, 'aba desatualizada: nada gravado (banco)');
  ok(/Outra pessoa salvou/.test(await a.locator('[data-testid="cp-flash"]').innerText()) && /Medida TESTE: 33/.test(await a.locator('[data-testid="adaptive-conflict"]').innerText()), 'conflito explícito, com o que não foi gravado');
  ok(await a.locator('input[name="answers[teste_e2e.medida]"]').inputValue()==='10', 'formulário mostra a resposta atual (10)');
  await a.fill('input[name="answers[teste_e2e.medida]"]','33'); await a.locator('input[name="answers[teste_e2e.escolha]"][value="b"]').check();
  await a.click('[data-testid="adaptive-conclude"]'); await a.waitForLoadState();
  ok(sq("SELECT COUNT(*) FROM protocol_responses WHERE is_draft=0")==='2', 'depois de conferir, grava (banco)');

  console.log('== NI2-652: comparação entre conclusões');
  const c=a.locator('[data-testid="adaptive-compare"]'); await c.locator('summary').click();
  const ct=await c.innerText();
  ok(/Escolha TESTE\s+Opção TESTE A\s+Opção TESTE B/.test(ct) && /Medida TESTE\s+10\s+33/.test(ct), 'tabela Antes/Depois com rótulos');

  console.log('== Larguras e teclado');
  for (const w of [375,656,1280,1440]) {
    const p=await page({width:w,height:900});
    await p.evaluate(()=>document.querySelectorAll('#anamnese-adaptativa details').forEach(d=>d.open=true));
    const ov=await p.evaluate(()=>document.documentElement.scrollWidth-document.documentElement.clientWidth);
    ok(ov<=1, w+'px: sem rolagem lateral com tudo aberto ('+ov+'px)');
    await p.context().close();
  }
  await n.goto(B+'/pacientes/2/anamnese'); await n.focus('[data-testid="module-dismiss"] summary'); await n.keyboard.press('Enter');
  ok(await n.locator('[data-testid="module-dismiss"]').evaluate(e=>e.open) && await n.locator('[data-testid="module-dismiss"] input[name="reason"]').isVisible(), 'dispensa abre pelo teclado');
  ok(errs.length===0 && csp.length===0, 'sem erro de JavaScript nem de CSP ('+errs.concat(csp).join(' | ').slice(0,200)+')');
  await b.close();
  console.log(`\n${res.filter(Boolean).length} PASS, ${res.filter(x=>!x).length} FAIL`); process.exit(res.every(Boolean)?0:1);
})().catch(e=>{console.error(e);process.exit(1);});
