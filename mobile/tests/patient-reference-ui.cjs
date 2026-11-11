const pw=require(process.env.PLAYWRIGHT_MODULE||'playwright'),axe=require(process.env.AXE_MODULE||'axe-core'),fs=require('node:fs'),assert=require('node:assert/strict');
const f=JSON.parse(fs.readFileSync(process.env.PATIENT_FIXTURE)),base=process.env.PATIENT_TEST_URL,out=process.env.PATIENT_EVIDENCE;
assert(new URL(base).hostname==='127.0.0.1');
const tabs=['Hoje','Plano','Diário','Chat','Evolução','Perfil'],checks=[],states=[],errors=[],profiles=[];
let browser;
function check(name,pass){checks.push({name,pass:!!pass});save();assert(pass,name);}
function save(){fs.writeFileSync(out+'/ui-progress.json',JSON.stringify({checks,states,errors},null,2));}
async function login(p,email=f.patient_email){
 await p.goto(base);await p.getByLabel('E-mail do paciente',{exact:true}).fill(email);await p.getByLabel('Senha do paciente',{exact:true}).fill('Synthetic-mobile-2026!');
 await p.getByRole('button',{name:'Entrar',exact:true}).click();await p.getByTestId('patient-overview').waitFor();await p.waitForTimeout(150);
}
async function tab(p,name){await p.getByRole('tab',{name,exact:true}).click();await p.waitForTimeout(100);}
async function inspect(p,label){
 await p.evaluate(axe.source);const a=await p.evaluate(()=>axe.run(document,{runOnly:{type:'tag',values:['wcag2a','wcag2aa','wcag21a','wcag21aa','wcag22aa']}}));
 const g=await p.evaluate(()=>({width:innerWidth,outerWidth,dpr:devicePixelRatio,scale:visualViewport.scale,scroll:document.documentElement.scrollWidth,
  overflows:[...document.querySelectorAll('main *')].filter(e=>e instanceof HTMLElement&&e.checkVisibility()&&e.clientWidth>0&&e.scrollWidth>e.clientWidth+3&&['hidden','clip','auto','scroll'].includes(getComputedStyle(e).overflowX)&&!e.matches('textarea,input')&&!e.querySelector('[role=tablist]')&&getComputedStyle(e).textOverflow!=='ellipsis').map(e=>({tag:e.tagName,role:e.getAttribute('role'),width:e.clientWidth,scroll:e.scrollWidth}))}));
 states.push({label,...g,violations:a.violations.map(v=>({id:v.id,nodes:v.nodes.map(n=>({target:n.target,summary:n.failureSummary}))})),incomplete:a.incomplete.map(v=>({id:v.id,nodes:v.nodes.length}))});save();
 check(label+' document reflows',g.scroll<=g.width);
 check(label+' axe detects no WCAG violations',a.violations.length===0);
 check(label+' one tab selected',await p.getByRole('tab').evaluateAll(els=>els.filter(e=>e.getAttribute('aria-selected')==='true').length)===1);
 if(a.violations.length)await p.screenshot({path:out+'/'+label+'-failure.png'});
}
(async()=>{try{
 browser=await pw.chromium.launch({headless:true,executablePath:process.env.CHROME_PATH});
 const c=await browser.newContext({viewport:{width:390,height:844},locale:'pt-BR',timezoneId:'America/Sao_Paulo'}),p=await c.newPage();
 p.on('pageerror',e=>errors.push(e.message));await login(p);
 check('all six original destinations retained',JSON.stringify(await p.getByRole('tab').evaluateAll(es=>es.map(e=>e.getAttribute('aria-label'))))===JSON.stringify(tabs));
 check('meal task precedes secondary links',await p.getByRole('heading',{name:'Suas refeições de hoje'}).evaluate(el=>el.compareDocumentPosition([...document.querySelectorAll('[role=button]')].find(e=>e.textContent.includes('Minhas receitas')))&Node.DOCUMENT_POSITION_FOLLOWING));
 await p.getByRole('button',{name:'Ver meu plano alimentar'}).click();
 check('hero opens published plan',await p.getByRole('heading',{name:'Plano alimentar completo'}).count()===1);
 check('v54.32 confirmed notes visible',await p.getByText('TEXTO SINTÉTICO v54.32:',{exact:false}).count()===1);
 check('food quantity and substitute preserved',await p.locator('body').innerText().then(t=>t.includes('300 g')&&t.includes('250 g')&&t.includes('Alternativa sintética autorizada')));
 await p.getByRole('button',{name:'Ver ingredientes e preparo',exact:false}).first().click();
 await p.getByText('Preparo da receita base',{exact:false}).waitFor();check('recipe preparation and base versus dose retained',true);
 await tab(p,'Hoje');await p.getByRole('button',{name:/^Registrar (outro )?consumo da refeição/}).first().click();
 await p.getByRole('radio',{name:'Comi diferente',exact:true}).click();
 const description='TESTE UI '+Date.now()+': sanduíche e fruta descritos pelo paciente.';
 await p.getByLabel('O que você comeu e quantidade aproximada').fill(description);
 await p.getByRole('button',{name:'Confirmar consumo',exact:true}).click();
 await p.getByText('Consumo registrado em',{exact:false}).first().waitFor();
 await tab(p,'Diário');await p.getByText('Comi diferente: '+description,{exact:true}).waitFor();check('actual consumption survives tab change',true);
 await p.reload();await p.getByTestId('patient-overview').waitFor();check('valid web session restores without credentials',true);
 await tab(p,'Diário');await p.getByText('Comi diferente: '+description,{exact:true}).waitFor();check('diary persists after reload',true);
 await tab(p,'Hoje');
 const before=await c.request.get(base+'/api/v1/patient/home',{headers:{'X-ClyoNutri-Web':'1'}}).then(r=>r.json());
 await p.getByTestId('water-add-250').click();await p.getByTestId('water-feedback').waitFor();
 await p.getByTestId('water-feedback').filter({hasText:'registrados'}).waitFor();
 const after=await c.request.get(base+'/api/v1/patient/home',{headers:{'X-ClyoNutri-Web':'1'}}).then(r=>r.json());
 check('hydration is confirmed by v54.32',after.water_ml_today===before.water_ml_today+250);
 await p.getByTestId('water-history-toggle').click();await p.getByTestId('hydration-history').waitFor();check('hydration history opens',await p.getByTestId('hydration-history').isVisible());
 if(await p.getByRole('button',{name:'Corrigir check-in de hoje',exact:true}).count())await p.getByRole('button',{name:'Corrigir check-in de hoje',exact:true}).click();
 await p.getByRole('radio',{name:'Tudo do plano',exact:true}).click();
 await p.getByRole('button',{name:/^(Enviar check-in|Salvar correção)$/}).click();await p.getByText(/Check-in de hoje (enviado|corrigido)\./).waitFor();
 await p.reload();await p.getByTestId('patient-overview').waitFor();
 await p.getByText('Plano seguido hoje:',{exact:false}).waitFor();check('check-in persists after reload',true);
 await tab(p,'Chat');await p.getByLabel('Mensagem para o nutricionista',{exact:true}).fill('Rascunho SINTÉTICO, não enviar.');
 await tab(p,'Plano');await tab(p,'Chat');check('unsent chat draft preserved',await p.getByLabel('Mensagem para o nutricionista',{exact:true}).inputValue()==='Rascunho SINTÉTICO, não enviar.');
 await p.getByLabel('Mensagem para o nutricionista',{exact:true}).fill('');
 for(const width of [320,375,390,768]){
  await p.setViewportSize({width,height:900});
  for(const name of tabs){await tab(p,name);await inspect(p,name+'-'+width);}
 }
 await p.setViewportSize({width:390,height:844});await tab(p,'Hoje');await p.screenshot({path:out+'/today.png'});
 await tab(p,'Plano');await p.screenshot({path:out+'/plan.png'});
 await tab(p,'Evolução');await p.screenshot({path:out+'/evolution.png'});
 await p.setViewportSize({width:812,height:375});await inspect(p,'evolution-landscape');
 await p.setViewportSize({width:375,height:812});await tab(p,'Plano');
 await p.addStyleTag({content:'*{line-height:1.5!important;letter-spacing:.12em!important;word-spacing:.16em!important}p{margin-bottom:2em!important}'});
 await inspect(p,'plan-textspacing');await p.reload();await p.getByTestId('patient-overview').waitFor();
 const waterBeforeOffline=await c.request.get(base+'/api/v1/patient/home',{headers:{'X-ClyoNutri-Web':'1'}}).then(r=>r.json());
 await c.setOffline(true);await p.getByTestId('water-add-250').click();await p.getByTestId('water-pending').waitFor();
 check('offline hydration is pending, never falsely confirmed',await p.getByTestId('water-pending').isVisible());
 await c.setOffline(false);
 await p.getByTestId('water-pending').waitFor({state:'hidden',timeout:20000});
 const waterAfterOffline=await c.request.get(base+'/api/v1/patient/home',{headers:{'X-ClyoNutri-Web':'1'}}).then(r=>r.json());check('offline queue resumes against real API',waterAfterOffline.water_ml_today===waterBeforeOffline.water_ml_today+250);
 await tab(p,'Perfil');await p.getByRole('button',{name:'Encerrar sessão',exact:true}).click();await p.getByLabel('E-mail do paciente',{exact:true}).waitFor();check('logout reaches access screen',true);
 await c.close();
 const other=await browser.newContext({viewport:{width:375,height:812}}),op=await other.newPage();await login(op,f.patient_other_email);
 await op.getByText('Nenhum plano publicado',{exact:true}).waitFor();check('empty state comes from second clinic, no first patient data',!(await op.locator('body').innerText()).includes('Marina'));
 await inspect(op,'empty-other-clinic');await other.close();await browser.close();browser=null;
 for(const engine of ['firefox','webkit']){
  const b=await pw[engine].launch({headless:true});try{
   const page=await b.newPage({viewport:{width:375,height:812},locale:'pt-BR',timezoneId:'America/Sao_Paulo'});await login(page);
   for(const name of ['Hoje','Plano','Evolução']){await tab(page,name);await inspect(page,engine+'-'+name);}
  }finally{await b.close();}
 }
 for(const zoom of [2,4]){
  const profile=fs.mkdtempSync('/tmp/patient-ds-zoom-');profiles.push(profile);fs.mkdirSync(profile+'/Default');
  fs.writeFileSync(profile+'/Default/Preferences',JSON.stringify({partition:{per_host_zoom_levels:{x:{'127.0.0.1':{last_modified:'13400000000000000',zoom_level:Math.log(zoom)/Math.log(1.2)}}}}}));
  const context=await pw.chromium.launchPersistentContext(profile,{headless:true,executablePath:process.env.CHROME_PATH,viewport:null,args:['--window-size=1280,900']});
  try{const page=context.pages()[0];await login(page);check('native zoom geometry '+zoom,await page.evaluate(z=>Math.abs(devicePixelRatio-z)<.01&&Math.abs(outerWidth/innerWidth-z)<.01&&visualViewport.scale===1,zoom));
   for(const name of ['Hoje','Plano','Evolução']){await tab(page,name);await inspect(page,'native'+zoom+'-'+name);}
  }finally{await context.close();fs.rmSync(profile,{recursive:true,force:true});}
 }
 check('no uncaught JavaScript errors',errors.length===0);
 fs.writeFileSync(out+'/ui-report.json',JSON.stringify({synthetic:true,backend:'v54.32',browser:'Chrome/Firefox/WebKit',axe_version:axe.version,checks,states,errors,limitations:'Expo Web and native JS exports; no physical Android/iOS, keyboard/safe-area or assistive reader certification. No chat sent.'},null,2));
 console.log(JSON.stringify({checks:checks.length,states:states.length,failed:checks.filter(x=>!x.pass)}));
}catch(e){save();throw e;}finally{if(browser)await browser.close();for(const profile of profiles)fs.rmSync(profile,{recursive:true,force:true});}})().catch(e=>{console.error(e);process.exitCode=1});
