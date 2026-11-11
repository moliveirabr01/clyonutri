// NI2-622: perfil do paciente com uma ação principal por estado e navegação clínica única.
const { chromium } = require('playwright');
const { execSync } = require('child_process');
const res=[]; const ok=(c,l)=>{res.push(!!c);console.log((c?'  PASS  ':'  FAIL  ')+l);};
(async()=>{
  const B=process.argv[2], DB=process.argv[3];
  const sq=q=>execSync(`php ${process.env.E2E_SQ} ${DB} "${q}"`).toString().trim();
  const b=await chromium.launch(); const ctx=await b.newContext({viewport:{width:1280,height:900}}); const p=await ctx.newPage();
  const csp=[]; const errs=[]; p.on('console',m=>{if(/Content Security Policy/i.test(m.text()))csp.push(m.text())}); p.on('pageerror',e=>errs.push(e.message));
  const primaries=async()=>p.$$eval('[data-testid="patient-header"] .btn-primary',els=>els.filter(e=>e.offsetParent!==null).map(e=>e.textContent.trim()));
  await p.goto(B+'/__teste_login?u=1&c=1&v=0&to=/pacientes/1');
  let pr=await primaries();
  ok(pr.length===1 && pr[0]==='Iniciar agora', 'paciente sem consulta: uma ação principal, "Iniciar agora" (NI2-705)');
  ok((await p.getAttribute('[data-testid="care-schedule"]','href')).includes('paciente=1'), '"Agendar" abre a agenda já com o paciente');
  await p.goto(B+'/pacientes/2');
  pr=await primaries();
  ok(pr.length===1 && pr[0]==='Preparar consulta' && /Próxima consulta: \d\d\/\d\d\/\d{4} às \d\d:\d\d/.test(await p.textContent('[data-testid="patient-care-context"]')), 'consulta futura: "Preparar consulta" e a data no cabeçalho');
  const tabs=await p.$$eval('[data-testid="patient-tabs"] a',as=>as.map(a=>({t:a.childNodes[0].textContent.trim(),h:a.getAttribute('href'),cur:a.getAttribute('aria-current'),dot:!!a.querySelector('.patient-tabs__dot'),sr:(a.querySelector('.visually-hidden')||{}).textContent||''})));
  ok(tabs.map(t=>t.t).join('|')==='Resumo|Atendimento|Plano|Anamnese|Avaliações|Exames|Documentos', 'abas: Resumo, Atendimento, Plano, Anamnese, Avaliações, Exames, Documentos');
  ok(new Set(tabs.map(t=>t.h)).size===tabs.length && tabs[0].cur==='page', 'sem entradas repetidas; Resumo marcado como atual');
  ok(await p.$$eval('.cn-section-nav, .cn-profile-nav',e=>e.length)===0, 'atalhos duplicados antigos removidos');
  ok(tabs[3].dot && /alteração em andamento/.test(tabs[3].sr), 'Anamnese com indicador discreto (rascunho v2) e texto para leitor de tela');
  ok(tabs[5].dot === (+sq("SELECT COUNT(*) FROM exam_requests WHERE patient_id=2 AND status IN ('draft','review')")>0), 'Exames: indicador só quando há solicitação a concluir');
  // ações raras no menu, pelo teclado
  await p.focus('[data-testid="patient-more"]'); await p.keyboard.press('Enter');
  const menu=await p.$$eval('.patient-header__menu > *',els=>els.filter(e=>e.offsetParent!==null).map(e=>e.textContent.trim()));
  ok(menu.some(t=>/Editar cadastro/.test(t)) && menu.some(t=>/Reagendar consulta/.test(t)) && menu.some(t=>/convite/.test(t)), 'teclado abre "Mais ações": editar, reagendar, convite…');
  await p.keyboard.press('Escape');
  // documentos (NI2-711: rota própria)
  ok((await p.getAttribute('[data-tab="documentos"]','href'))==='/pacientes/2/documentos', 'aba Documentos tem rota própria');
  ok((await p.getAttribute('[data-tab="plano"]','href'))==='/pacientes/2/plano', 'aba Plano tem rota própria');
  await p.click('[data-tab="documentos"]'); await p.waitForURL(/\/pacientes\/2\/documentos/);
  ok(await p.isVisible('[data-testid="profile-manipulados-btn"]'), 'Documentos: manipulados, impressão, exportação e consentimentos');
  // atendimento em andamento
  sq("UPDATE appointments SET status='in_progress', scheduled_at=datetime('now','localtime') WHERE id=1");
  await p.goto(B+'/pacientes/2');
  pr=await primaries();
  const tabs2=await p.$$eval('[data-testid="patient-tabs"] a',as=>as.map(a=>!!a.querySelector('.patient-tabs__dot')));
  ok(pr.length===1 && pr[0]==='Continuar atendimento' && tabs2[1], 'em andamento: "Continuar atendimento" e indicador em Atendimento');
  ok((await p.getAttribute('[data-testid="care-continue"]','href'))==='/consulta/1', 'continua na mesma consulta (rascunho preservado)');
  // larguras
  for (const w of [375,656,1280,1440]) {
    await p.setViewportSize({width:w,height:900}); await p.goto(B+'/pacientes/2');
    const r=await p.evaluate(()=>{const btn=document.querySelector('[data-testid="care-continue"]');const bb=btn.getBoundingClientRect();const top=document.elementFromPoint(bb.left+bb.width/2,bb.top+bb.height/2);
      return {ov:document.documentElement.scrollWidth<=innerWidth+1, vis:bb.width>0&&bb.right<=innerWidth+1, cover:btn.contains(top)};});
    ok(r.ov && r.vis && r.cover, w+' px: sem rolagem lateral; ação principal visível e não encoberta');
    if (w===375) {
      await p.click('[data-testid="patient-more"]');
      const m=await p.$eval('.patient-header__menu',e=>{const r=e.getBoundingClientRect();return {l:r.left,r:r.right,vw:innerWidth};});
      ok(m.l>=0 && m.r<=m.vw+1, '375 px: menu "Mais ações" dentro da tela');
      await p.screenshot({path:process.env.E2E_OUT+'/p5414-375.png',fullPage:false});
    }
  }
  await p.setViewportSize({width:1280,height:900}); await p.goto(B+'/pacientes/2');
  await p.screenshot({path:process.env.E2E_OUT+'/p5414-1280.png',fullPage:false});
  ok(csp.length===0 && errs.length===0, 'sem CSP e sem erro de JS ('+csp.length+'/'+errs.length+(errs[0]?': '+errs[0]:'')+')');
  await b.close();
  const f=res.filter(x=>!x).length; console.log(`\n${res.length-f} PASS, ${f} FAIL`); process.exit(f?1:0);
})();
