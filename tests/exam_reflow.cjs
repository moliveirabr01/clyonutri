const {chromium}=require(process.env.PLAYWRIGHT_MODULE||'playwright'),axe=require(process.env.AXE_MODULE||'axe-core'),fs=require('fs'),assert=require('assert');
let browser;
(async()=>{
 const f=JSON.parse(fs.readFileSync(process.env.ADAPTIVE_FIXTURE_FILE)),base=process.env.UX_BASE_URL,before=process.env.UX_BASELINE_URL,dir=process.env.UX_EVIDENCE_DIR||'/tmp/exam-reflow';
 fs.mkdirSync(dir,{recursive:true});const states=[],checks=[],errors=[],profiles=[];
 const executablePath=process.env.CHROME_PATH;
 function check(name,pass){checks.push({name,pass:!!pass});assert(pass,name);}
 async function login(p,url){await p.goto(url+'/login');await p.locator('#email').fill(f.email);await p.locator('#password').fill('Synthetic-local-651!');await p.locator('button[type=submit]').click();await p.waitForLoadState();}
 async function inspect(p,label,mode){
  await p.evaluate(axe.source);const a=await p.evaluate(()=>axe.run(document,{runOnly:{type:'tag',values:['wcag2a','wcag2aa','wcag21a','wcag21aa','wcag22aa']}}));
  const geometry=await p.evaluate(()=>({innerWidth,outerWidth,dpr:devicePixelRatio,cssZoom:getComputedStyle(document.documentElement).zoom,visualScale:visualViewport.scale,documentWidth:document.documentElement.scrollWidth,criticalControls:[...document.querySelectorAll('.app-header a,.app-header button,.app-header input,.patient-tabs a,#exam-request-form button[type=submit]')].filter(el=>el.getClientRects().length).map(el=>{const r=el.getBoundingClientRect();return {label:el.getAttribute('aria-label')||el.textContent.trim()||el.name,left:r.left,right:r.right,width:r.width};})}));
  const row={label,mode,...geometry,violations:a.violations.map(v=>({id:v.id,nodes:v.nodes.map(n=>({target:n.target,summary:n.failureSummary}))})),incomplete:a.incomplete.map(v=>({id:v.id,count:v.nodes.length}))};states.push(row);fs.writeFileSync(dir+'/states-progress.json',JSON.stringify(states,null,2));
  await p.screenshot({path:dir+'/'+label+'.png',fullPage:true});
  check(label+' sem rolagem horizontal',geometry.documentWidth<=geometry.innerWidth);
  check(label+' controles dentro do viewport',geometry.criticalControls.every(x=>x.left>=-1&&x.right<=geometry.innerWidth+1));
  check(label+' sem violações axe detectadas',a.violations.length===0);
 }
 browser=await chromium.launch({headless:true,executablePath});const c=await browser.newContext(),p=await c.newPage();p.on('pageerror',e=>errors.push(e.message));p.on('dialog',d=>d.accept());
 await login(p,before);await p.setViewportSize({width:1280,height:900});await p.goto(before+'/pacientes/'+f.patient+'/exames/novo');await p.evaluate(()=>document.documentElement.style.zoom='2');const original=await p.evaluate(()=>({innerWidth,documentWidth:document.documentElement.scrollWidth}));check('reproduz transbordamento original',original.documentWidth>original.innerWidth);await p.screenshot({path:dir+'/before-css200-1280.png',fullPage:true});
 await login(p,base);
 const routes=[['exame-novo','/pacientes/'+f.patient+'/exames/novo'],['perfil','/pacientes/'+f.patient],['consulta','/consulta/'+f.appointment],['agenda','/agenda'],['plano','/planos/'+f.quick_plan],['configuracoes','/configuracoes']];
 for(const width of [375,656,1280,1440]){
  await p.setViewportSize({width,height:900});
  for(const [name,route] of routes){await p.goto(base+route);await inspect(p,name+'-'+width,'default');}
 }
 for(const width of [1280,1440]){
  await p.setViewportSize({width,height:900});await p.goto(base+'/pacientes/'+f.patient+'/exames/novo');await p.evaluate(()=>document.documentElement.style.zoom='2');await inspect(p,'exame-css200-'+width,'css-zoom-200');
  const tabs=p.locator('.patient-tabs a');await tabs.first().focus();for(let i=1;i<await tabs.count();i++){await p.keyboard.press('Tab');check('aba alcançável por teclado '+width+' '+i,await tabs.nth(i).evaluate(el=>el===document.activeElement));}
  const logout=p.locator('.app-header__logout');await logout.focus();check('foco do cabeçalho visível '+width,await logout.evaluate(el=>{const s=getComputedStyle(el);return s.outlineStyle!=='none'&&parseFloat(s.outlineWidth)>0;}));
 }
 // A real synthetic draft is saved while zoomed, then reopened for edit.
 await p.goto(base+'/pacientes/'+f.patient+'/exames/novo');await p.evaluate(()=>document.documentElement.style.zoom='2');
 const text='TESTE reflow exame '+Date.now();await p.locator('#exam-request-form [name="exam_ids[]"]').first().check();await p.locator('#clinical_justification').fill(text);
 await p.locator('#exam-request-form [data-state=saved]').waitFor();
 const endpoint=await p.locator('#exam-request-form').getAttribute('data-workflow-draft'),id=endpoint.split('/').pop();check('rascunho recebe id persistido',Number(id)>0);
 await p.goto(base+'/pacientes/'+f.patient+'/exames/'+id+'/editar');await p.evaluate(()=>document.documentElement.style.zoom='2');check('edição persistida após recarregar',await p.locator('#clinical_justification').inputValue()===text);await inspect(p,'exame-editar-css200','css-zoom-200');
 await c.close();await browser.close();browser=null;
 // Chromium native per-host browser zoom. No CSS zoom, viewport or device scale emulation.
 for(const width of [1280,1440]){
  const profile=fs.mkdtempSync('/tmp/exam-reflow-native-');profiles.push(profile);fs.mkdirSync(profile+'/Default');
  fs.writeFileSync(profile+'/Default/Preferences',JSON.stringify({partition:{per_host_zoom_levels:{x:{'127.0.0.1':{last_modified:'13400000000000000',zoom_level:Math.log(2)/Math.log(1.2)}}}}}));
  const context=await chromium.launchPersistentContext(profile,{headless:true,executablePath,viewport:null,args:['--window-size='+width+',900']});const page=context.pages()[0];page.on('pageerror',e=>errors.push(e.message));await login(page,base);
  for(const [name,route] of [['novo','/pacientes/'+f.patient+'/exames/novo'],['editar','/pacientes/'+f.patient+'/exames/'+id+'/editar']]){
   await page.goto(base+route);check('zoom nativo real '+width+' '+name,await page.evaluate(()=>Math.abs(devicePixelRatio-2)<.01&&outerWidth===innerWidth*2&&getComputedStyle(document.documentElement).zoom==='1'&&visualViewport.scale===1));await inspect(page,'exame-native200-'+name+'-'+width,'native-chromium-zoom-200');
  }
  await context.close();fs.rmSync(profile,{recursive:true,force:true});
 }
 check('sem erros JavaScript',errors.length===0);
 fs.writeFileSync(dir+'/report.json',JSON.stringify({issue:'NI2-688-OBS-01',base:'v54.28',candidate:'v54.29',automated:true,original,states,checks,errors,native_zoom_method:'Chromium per-host Preferences zoom_level=log(2)/log(1.2); separate disposable profile; no viewport/device scale emulation; DPR=2, outerWidth=2*innerWidth, CSS zoom=1 and visualViewport.scale=1 asserted.',limitations:'Headless Chrome on macOS, synthetic data. Not a full assistive-technology or cross-browser certification.'},null,2));
 console.log(JSON.stringify({states:states.length,checks:checks.length,passed:true,original,final_css200:states.filter(x=>x.mode==='css-zoom-200').map(x=>({label:x.label,width:x.innerWidth,documentWidth:x.documentWidth})),native:states.filter(x=>x.mode==='native-chromium-zoom-200').map(x=>({label:x.label,innerWidth:x.innerWidth,outerWidth:x.outerWidth,dpr:x.dpr}))}));
})().catch(async e=>{console.error(e);if(browser)await browser.close();process.exit(1)});
