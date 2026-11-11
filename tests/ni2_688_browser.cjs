const {chromium}=require(process.env.PLAYWRIGHT_MODULE||'playwright'),axe=require(process.env.AXE_MODULE||'axe-core'),fs=require('fs');
let browser;
(async()=>{
 const dir=process.env.UX_EVIDENCE_DIR||'/tmp/ni2688-browser';fs.mkdirSync(dir,{recursive:true});const rows=[],checks=[],errors=[];
 browser=await chromium.launch({headless:true,executablePath:process.env.CHROME_PATH});
 function check(build,name,pass){checks.push({build,name,pass:!!pass});}
 for(const [build,base,path] of [['v54.18',process.env.UX_BASELINE_URL,process.env.BASELINE_FIXTURE_FILE],['v54.28',process.env.UX_BASE_URL,process.env.ADAPTIVE_FIXTURE_FILE]]){
  const f=JSON.parse(fs.readFileSync(path)),c=await browser.newContext(),p=await c.newPage();p.on('pageerror',e=>errors.push({build,message:e.message}));p.on('dialog',d=>d.accept());
  await p.goto(base+'/login');await p.locator('#email').fill(f.email);await p.locator('#password').fill('Synthetic-local-651!');await p.locator('button[type=submit]').click();await p.waitForLoadState();
  const routes=[['consulta','/consulta/'+f.appointment],['perfil','/pacientes/'+f.patient],['agenda','/agenda'],['plano','/planos/'+f.quick_plan],['exames','/pacientes/'+f.patient+'/exames/novo'],['configuracoes','/configuracoes']];
  async function inspect(name,route,width,mode='default'){
   const response=await p.goto(base+route);const panel=p.locator('[data-clinical-context]:visible>summary').first();if(await panel.count())await panel.click();
   if(mode==='css-zoom-200')await p.evaluate(()=>document.documentElement.style.zoom='2');
   await p.evaluate(axe.source);const result=await p.evaluate(()=>axe.run(document,{runOnly:{type:'tag',values:['wcag2a','wcag2aa','wcag21a','wcag21aa','wcag22aa']}}));
   rows.push({build,name,route,width,mode,http_status:response.status(),final_path:new URL(p.url()).pathname,overflow:await p.evaluate(()=>document.documentElement.scrollWidth>innerWidth),violations:result.violations.map(v=>({id:v.id,impact:v.impact,nodes:v.nodes.map(n=>({target:n.target,summary:n.failureSummary}))})),incomplete:result.incomplete.map(v=>({id:v.id,count:v.nodes.length}))});
   await p.screenshot({path:dir+'/'+build+'-'+name+'-'+width+'-'+mode+'.png',fullPage:true});
  }
  for(const width of [375,656,1280,1440]){await p.setViewportSize({width,height:900});for(const [name,route] of routes)await inspect(name,route,width);}
  await p.setViewportSize({width:375,height:900});await p.emulateMedia({forcedColors:'active',reducedMotion:'reduce'});await inspect('plano','/planos/'+f.quick_plan,375,'forced-colors');
  await p.emulateMedia({forcedColors:'none'});await p.setViewportSize({width:1280,height:900});await inspect('exames','/pacientes/'+f.patient+'/exames/novo',1280,'css-zoom-200');await p.evaluate(()=>document.documentElement.style.zoom='');
  // Real persisted draft, validation failure, retry after offline, and reload.
  await p.goto(base+'/planos/'+f.quick_plan);
  const form=p.locator('[data-quick-meal]'),field=form.locator('[name=name]'),name='TESTE NI2-688 '+Date.now();
  await c.setOffline(true);await field.fill(name);await form.locator('button[type=submit]').click();await p.locator('[data-quick-error]:visible').waitFor();
  check(build,'offline retains editing text',await field.inputValue()===name);await c.setOffline(false);await p.locator('[data-quick-retry]').click();await p.locator('[data-quick-plan] [data-state=saved]').waitFor();await p.reload();check(build,'acknowledged draft survives reload',(await p.locator('body').innerText()).includes(name));
  const state=await c.request.get(base+'/api/workflow/plan/'+f.quick_plan);check(build,'authorized plan state',state.status()===200);
  const bctx=await browser.newContext(),q=await bctx.newPage();await q.goto(base+'/login');await q.locator('#email').fill(f.email_b);await q.locator('#password').fill('Synthetic-local-651!');await q.locator('button[type=submit]').click();await q.waitForLoadState();
  const denied=await bctx.request.get(base+'/api/workflow/plan/'+f.quick_plan);check(build,'clinic B cannot read clinic A state',denied.status()===404||denied.status()===403);
  await q.goto(base+'/pacientes/'+f.patient);check(build,'foreign patient not rendered',await q.locator('[data-clinical-context]').count()===0&&!(await q.locator('body').innerText()).includes('TESTE Paciente A'));
  await bctx.close();
  // Actual patient credentials and scoped cookie, not a professional impersonation.
  const pc=await browser.newContext(),patient=await pc.newPage();
  const login=await pc.request.post(base+'/api/v1/auth/patient-login',{headers:{Origin:base,'X-Clyonutri-Web':'1'},data:{email:f.patient_email,password:'Synthetic-local-688!'}});check(build,'patient strong authentication',login.status()===200);
  const home=await pc.request.get(base+'/api/v1/patient/home');check(build,'patient own home API',home.status()===200);
  const other=await pc.request.get(base+'/api/v1/patient/home?patient_id='+f.patient_b);
  check(build,'caller patient_id cannot expose other patient',other.status()!==200||!(await other.text()).includes('TESTE Paciente B'));
  const professional=await pc.request.get(base+'/api/workflow/plan/'+f.quick_plan);check(build,'patient token cannot access professional plan state',professional.status()===401||professional.status()===403||new URL(professional.url()).pathname==='/login');
  for(const route of ['/paciente/app','/paciente/consultas']){await patient.goto(base+route);await patient.screenshot({path:dir+'/'+build+'-patient-'+route.split('/').pop()+'.png',fullPage:true});}
  await pc.close();await c.close();
 }
 const report={issue:'NI2-688',source:'automation',human_sessions:0,human_scores:null,not_a_human_baseline_comparison:true,browser:browser.version(),axe_version:axe.version,rows,checks,errors,limitations:['Technical characterization, not human performance.','No full onboarding-to-return human journey, SUS or recordings collected.','Patient APIs and entry pages exercised; booking/rescheduling/document tasks still require participant sessions.','CSS zoom and forced colors simulated; native zoom, touch and assistive review pending.']};
 fs.writeFileSync(dir+'/report.json',JSON.stringify(report,null,2));await browser.close();browser=null;
 console.log(JSON.stringify({states:rows.length,checks:checks.length,failed_checks:checks.filter(x=>!x.pass),js_errors:errors,candidate_findings:rows.filter(x=>x.build==='v54.28'&&(x.overflow||x.violations.length)).map(x=>({name:x.name,width:x.width,mode:x.mode,overflow:x.overflow,violations:x.violations.map(v=>v.id)}))}));
 if(checks.some(x=>!x.pass)||errors.length)process.exitCode=1;
})().catch(async e=>{console.error(e);if(browser)await browser.close();process.exit(1)});
