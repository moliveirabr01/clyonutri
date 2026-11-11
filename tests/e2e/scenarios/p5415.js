// NI2-661: o mesmo cabeçalho do paciente e as abas em todas as páginas clínicas internas.
const { chromium } = require('playwright');
const res=[]; const ok=(c,l)=>{res.push(!!c);console.log((c?'  PASS  ':'  FAIL  ')+l);};
(async()=>{
  const B=process.argv[2];
  const b=await chromium.launch(); const ctx=await b.newContext({viewport:{width:1280,height:900}}); const p=await ctx.newPage();
  const csp=[]; const errs=[]; p.on('console',m=>{if(/Content Security Policy/i.test(m.text()))csp.push(m.text())}); p.on('pageerror',e=>errs.push(e.message));
  await p.goto(B+'/__teste_login?u=1&c=1&v=0&to=/pacientes/2');
  const pages=[['/pacientes/2','resumo'],['/pacientes/2/prontuario','atendimento'],['/pacientes/2/plano','plano'],['/pacientes/2/anamnese','anamnese'],['/pacientes/2/antropometria','avaliacoes'],['/pacientes/2/exames','exames'],['/pacientes/2/documentos','documentos']];
  for (const [url,tab] of pages) {
    await p.goto(B+url);
    const r=await p.evaluate(()=>({
      header:!!document.querySelector('[data-testid="patient-header"]'),
      tabs:[...document.querySelectorAll('[data-testid="patient-tabs"] a')].map(a=>a.dataset.tab),
      cur:document.querySelector('[data-testid="patient-tabs"] a[aria-current="page"]')?.dataset.tab,
      prim:[...document.querySelectorAll('[data-testid="patient-header"] .btn-primary')].filter(e=>e.offsetParent!==null).length,
      h1:document.querySelectorAll('main h1, .main-content h1, #main-content h1, body h1').length,
      name:document.querySelector('.patient-header__name')?.textContent.trim()}));
    ok(r.header && r.tabs.length===7 && r.cur===tab && r.prim===1 && r.h1===1 && r.name==='Paciente TESTE Dois', url+': cabeçalho, 7 abas, "'+tab+'" atual, 1 ação principal, 1 título principal');
  }
  // navegação pelas abas leva às páginas certas
  await p.goto(B+'/pacientes/2/anamnese'); await p.click('[data-tab="exames"]'); await p.waitForLoadState();
  ok(/\/pacientes\/2\/exames$/.test(new URL(p.url()).pathname), 'aba Exames a partir da Anamnese abre a página de exames');
  await p.click('[data-tab="resumo"]'); await p.waitForLoadState();
  ok(new URL(p.url()).pathname==='/pacientes/2', 'aba Resumo volta ao perfil');
  await p.click('[data-tab="documentos"]'); await p.waitForLoadState();
  ok(/\/pacientes\/2\/documentos$/.test(new URL(p.url()).pathname), 'aba Documentos abre rota própria');
  await p.click('[data-tab="plano"]'); await p.waitForLoadState();
  ok(/\/pacientes\/2\/plano$/.test(new URL(p.url()).pathname), 'aba Plano abre rota própria');
  // larguras
  for (const w of [375,656,1280,1440]) {
    await p.setViewportSize({width:w,height:900});
    let bad=[];
    for (const [url] of pages.slice(1)) { await p.goto(B+url); if(!(await p.evaluate(()=>document.documentElement.scrollWidth<=innerWidth+1))) bad.push(url); }
    ok(bad.length===0, w+' px: páginas internas sem rolagem lateral'+(bad.length?' ('+bad.join(', ')+')':''));
    if (w===375) { await p.goto(B+'/pacientes/2/anamnese'); await p.screenshot({path:process.env.E2E_OUT+'/p5415-anamnese-375.png'}); }
  }
  await p.setViewportSize({width:1280,height:900}); await p.goto(B+'/pacientes/2/prontuario');
  await p.screenshot({path:process.env.E2E_OUT+'/p5415-prontuario.png'});
  // outra clínica
  const ctxB=await b.newContext(); const pb=await ctxB.newPage();
  await pb.goto(B+'/__teste_login?u=2&c=2&v=0&to=/dashboard');
  for (const [url] of pages.slice(1)) {
    await pb.goto(B+url);
    const t=(await pb.textContent('body').catch(()=>''))||'';
    ok(!/Paciente TESTE Dois/.test(t) && !(await pb.$('[data-testid="patient-header"]')), 'clínica B em '+url+': nenhum dado do paciente');
  }
  await ctxB.close();
  ok(csp.length===0 && errs.length===0, 'sem CSP e sem erro de JS ('+csp.length+'/'+errs.length+(errs[0]?': '+errs[0]:'')+')');
  await b.close();
  const f=res.filter(x=>!x).length; console.log(`\n${res.length-f} PASS, ${f} FAIL`); process.exit(f?1:0);
})();
