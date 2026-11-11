const pw=require(process.env.PLAYWRIGHT_MODULE||'playwright'),axe=require(process.env.AXE_MODULE||'axe-core'),fs=require('fs'),assert=require('assert');
(async()=>{
 const f=JSON.parse(fs.readFileSync(process.env.ADAPTIVE_FIXTURE_FILE)),base=process.env.UX_BASE_URL,dir=process.env.UX_EVIDENCE_DIR;
 assert(new URL(base).hostname==='127.0.0.1','isolated local fixtures only');fs.mkdirSync(dir,{recursive:true});
 const checks=[],states=[],errors=[],engines=(process.env.UX_BROWSERS||'chromium,firefox,webkit').split(',');
 const routes=[['consulta','/consulta/'+f.appointment],['perfil','/pacientes/'+f.patient],['agenda','/agenda?date='+f.date],['plano','/planos/'+f.quick_plan],['exames','/pacientes/'+f.patient+'/exames/novo'],['configuracoes','/configuracoes'],['build','/configuracoes/versao']];
 for(const engine of engines){
  const b=await pw[engine].launch({headless:true,...(engine==='chromium'&&process.env.CHROME_PATH?{executablePath:process.env.CHROME_PATH}:{})});
  try{
   const c=await b.newContext(),p=await c.newPage();p.on('dialog',d=>d.accept());p.on('pageerror',e=>errors.push({engine,message:e.message}));
   await p.goto(base+'/login');await p.locator('#email').fill(f.email);await p.locator('#password').fill('Synthetic-local-651!');await p.locator('button[type=submit]').click();await p.waitForLoadState();
   for(const [width,height,mode] of [[375,812,'portrait'],[656,900,'narrow'],[1280,900,'desktop'],[1440,900,'wide'],[812,375,'landscape'],[375,812,'textspacing-longtext']]){
    await p.setViewportSize({width,height});
    for(const [name,route] of routes){
     const r=await p.goto(base+route);
     if(mode==='textspacing-longtext'){
      await p.addStyleTag({content:'*{line-height:1.5!important;letter-spacing:.12em!important;word-spacing:.16em!important}p{margin-bottom:2em!important}'});
      // Synthetic stress fixture in the DOM, not a patient-record mutation or human evidence.
      await p.evaluate(()=>{for(const el of document.querySelectorAll('.patient-header h1,.patient-name,h1'))el.textContent+=' — TESTE Nome extenso de paciente e mensagem detalhada de acompanhamento clínico para verificar quebra de linha';});
     }
     await p.evaluate(axe.source);
     const a=await p.evaluate(()=>axe.run(document,{runOnly:{type:'tag',values:['wcag2a','wcag2aa','wcag21a','wcag21aa','wcag22aa']}}));
     const g=await p.evaluate(()=>({width:innerWidth,scroll:document.documentElement.scrollWidth,main:document.querySelectorAll('main').length,
      hiddenNonTables:[...document.querySelectorAll('main *')].filter(e=>e instanceof HTMLElement&&e.checkVisibility()&&e.clientWidth>0&&e.scrollWidth>e.clientWidth+2&&['auto','scroll','hidden','clip'].includes(getComputedStyle(e).overflowX)&&!e.matches('.sr-only,.visually-hidden')&&getComputedStyle(e).textOverflow!=='ellipsis'&&!e.matches('table,thead')&&!e.querySelector('table')).map(e=>({tag:e.tagName,id:e.id,class:e.className}))}));
     const label=engine+'-'+name+'-'+mode;
     const pass=r.status()===200&&g.scroll<=g.width&&g.main===1&&g.hiddenNonTables.length===0&&a.violations.length===0;
     checks.push({name:label,pass});states.push({label,browser:b.version(),status:r.status(),...g,violations:a.violations.map(v=>({id:v.id,nodes:v.nodes.map(n=>({target:n.target,summary:n.failureSummary}))})),incomplete:a.incomplete.map(i=>({id:i.id,count:i.nodes.length}))});
     if(!pass||name==='exames'&&mode==='landscape')await p.screenshot({path:dir+'/'+label+'.png',fullPage:true});
     fs.writeFileSync(dir+'/progress.json',JSON.stringify({checks,states,errors},null,2));
    }
   }
   await c.close();
  }finally{await b.close();}
 }
 checks.push({name:'no uncaught JavaScript errors',pass:errors.length===0});
 fs.writeFileSync(dir+'/report.json',JSON.stringify({suite:'NI2-702 cross-browser regression',engines,axe_version:axe.version,automated:true,states,checks,errors,limitations:'Landscape and text spacing are automated; long text is a synthetic DOM stress fixture. Native 200/400 zoom is covered separately by the Chromium matrix. Physical devices and assistive readers remain pending.'},null,2));
 const failed=checks.filter(x=>!x.pass);console.log(JSON.stringify({states:states.length,checks:checks.length,failed}));if(failed.length)process.exitCode=1;
})().catch(e=>{console.error(e);process.exitCode=1});
