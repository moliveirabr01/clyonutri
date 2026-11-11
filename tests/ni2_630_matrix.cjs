const {chromium}=require(process.env.PLAYWRIGHT_MODULE||'playwright'),axe=require(process.env.AXE_MODULE||'axe-core'),fs=require('fs');
let browser;
(async()=>{
 const f=JSON.parse(fs.readFileSync(process.env.ADAPTIVE_FIXTURE_FILE)),base=process.env.UX_BASE_URL,dir=process.env.UX_EVIDENCE_DIR||'/tmp/ni2630-matrix';fs.mkdirSync(dir,{recursive:true});const states=[],checks=[],errors=[],profiles=[];
 const routes=[['agenda','/agenda?date='+f.date],['nova-consulta','/agenda?novo=1&date='+f.date],['consulta','/consulta/'+f.appointment],['perfil','/pacientes/'+f.patient],['anamnese','/pacientes/'+f.patient+'/anamnese'],['avaliacao','/pacientes/'+f.patient+'/antropometria'],['plano','/planos/'+f.quick_plan],['exames','/pacientes/'+f.patient+'/exames/novo'],['configuracoes','/configuracoes']];
 const save=()=>fs.writeFileSync(dir+'/progress.json',JSON.stringify({states,checks,errors},null,2));function check(name,pass){checks.push({name,pass:!!pass});save();}
 async function login(p){await p.goto(base+'/login');await p.locator('#email').fill(f.email);await p.locator('#password').fill('Synthetic-local-651!');await p.locator('button[type=submit]').click();await p.waitForLoadState();}
 async function inspect(p,label,mode){
  await p.evaluate(axe.source);const a=await p.evaluate(()=>axe.run(document,{runOnly:{type:'tag',values:['wcag2a','wcag2aa','wcag21a','wcag21aa','wcag22aa']}}));
  const geo=await p.evaluate(()=>({innerWidth,outerWidth,dpr:devicePixelRatio,cssZoom:getComputedStyle(document.documentElement).zoom,visualScale:visualViewport.scale,documentWidth:document.documentElement.scrollWidth,mainCount:document.querySelectorAll('main').length,h1Count:document.querySelectorAll('main h1').length,internalHorizontalScroll:[...document.querySelectorAll('main *')].filter(el=>el instanceof HTMLElement&&el.checkVisibility()&&el.clientWidth>0&&el.scrollWidth>el.clientWidth+2&&['auto','scroll','hidden','clip'].includes(getComputedStyle(el).overflowX)&&!el.matches('.visually-hidden,.sr-only')&&getComputedStyle(el).textOverflow!=='ellipsis').map(el=>({tag:el.tagName,class:el.className,id:el.id,width:el.clientWidth,scrollWidth:el.scrollWidth,tableException:el.matches('table,thead')||!!el.querySelector('table')})),smallControls:[...document.querySelectorAll('main button,main summary,main input[type=checkbox],main input[type=radio]')].filter(el=>el.checkVisibility()).map(el=>{const r=el.getBoundingClientRect();return {id:el.id,tag:el.tagName,type:el.type,width:r.width,height:r.height,label:el.getAttribute('aria-label')||el.textContent.trim().slice(0,80),labelTarget:!!el.labels?.length};}).filter(x=>x.width<24||x.height<24)}));
  const row={label,mode,path:new URL(p.url()).pathname,...geo,violations:a.violations.map(v=>({id:v.id,impact:v.impact,nodes:v.nodes.map(n=>({target:n.target,summary:n.failureSummary}))})),incomplete:a.incomplete.map(v=>({id:v.id,count:v.nodes.length}))};states.push(row);save();
  if(mode.startsWith('native-zoom-')){
   const capture=await p.context().newCDPSession(p),shot=await capture.send('Page.captureScreenshot',{format:'png',captureBeyondViewport:false,fromSurface:true});await capture.detach();
   const png=Buffer.from(shot.data,'base64');if(png.readUInt32BE(16)!==geo.outerWidth||await p.evaluate(()=>innerWidth)!==geo.innerWidth)throw Error('Native screenshot geometry mismatch');
   row.screenshot={scope:'native viewport, no metrics override',width:png.readUInt32BE(16),height:png.readUInt32BE(20)};fs.writeFileSync(dir+'/'+label+'.png',png);
  }else await p.screenshot({path:dir+'/'+label+'.png',fullPage:true});
  check(label+' reflow',geo.documentWidth<=geo.innerWidth);
  check(label+' axe',a.violations.length===0);
  check(label+' landmark e título',geo.mainCount===1&&geo.h1Count>=1);
  check(label+' conteúdo não tabular sem rolagem lateral',geo.internalHorizontalScroll.every(x=>x.tableException));
 }
 async function anchor(p,label){
  const link=p.locator('a[href="#sec-alergias"]:visible').first();await link.focus();await p.keyboard.press('Enter');
  const position=await p.locator('#sec-alergias').evaluate(el=>{const r=el.getBoundingClientRect(),index=document.querySelector('.cn-anamnesis-index'),ir=index?.getBoundingClientRect(),sticky=index&&getComputedStyle(index).position==='sticky';return {top:r.top,focus:document.activeElement===el,indexBottom:sticky?Math.max(0,ir.bottom):0};});
  check(label+' âncora e foco não encobertos',position.focus&&position.top>=position.indexBottom-2);
 }
 browser=await chromium.launch({headless:true,executablePath:process.env.CHROME_PATH});const browserVersion=browser.version(),c=await browser.newContext(),p=await c.newPage();p.on('dialog',d=>d.accept());p.on('pageerror',e=>errors.push(e.message));await login(p);
 for(const width of [375,656,1280,1440]){await p.setViewportSize({width,height:900});for(const [name,path] of routes){await p.goto(base+path);await inspect(p,name+'-'+width,'default');if(name==='anamnese')await anchor(p,'anamnese-'+width);}}
 for(const width of [656,1280]){await p.setViewportSize({width,height:900});for(const date of ['2026-12-01','2027-01-01']){await p.goto(base+'/agenda?view=month&date='+date);const calendar=await p.locator('.agenda-month-grid').evaluate(el=>({columns:getComputedStyle(el).gridTemplateColumns.split(' ').length,labels:[...el.parentElement.querySelectorAll('.agenda-month-list-label')].filter(x=>x.checkVisibility()).length}));check('calendário semanas ou lista '+width+' '+date,calendar.columns===7||calendar.labels>0);}}
 await p.setViewportSize({width:375,height:900});await p.emulateMedia({forcedColors:'active',reducedMotion:'reduce'});
 for(const [name,path] of routes.filter(x=>['agenda','plano','exames'].includes(x[0]))){await p.goto(base+path);await inspect(p,name+'-forced-colors','forced-colors-reduced-motion');}
 await c.close();await browser.close();browser=null;
 for(const zoom of [2,4]){
  const profile=fs.mkdtempSync('/tmp/ni2630-zoom-');profiles.push(profile);fs.mkdirSync(profile+'/Default');fs.writeFileSync(profile+'/Default/Preferences',JSON.stringify({partition:{per_host_zoom_levels:{x:{'127.0.0.1':{last_modified:'13400000000000000',zoom_level:Math.log(zoom)/Math.log(1.2)}}}}}));
  const context=await chromium.launchPersistentContext(profile,{headless:true,executablePath:process.env.CHROME_PATH,viewport:null,args:['--window-size=1280,900']}),page=context.pages()[0];page.on('dialog',d=>d.accept());page.on('pageerror',e=>errors.push(e.message));await login(page);
  for(const [name,path] of routes){
   await page.goto(base+path);check(name+' zoom nativo '+zoom,await page.evaluate(z=>Math.abs(devicePixelRatio-z)<.01&&Math.abs(outerWidth/innerWidth-z)<.01&&getComputedStyle(document.documentElement).zoom==='1'&&visualViewport.scale===1,zoom));await inspect(page,name+'-native'+zoom*100,'native-zoom-'+zoom*100);if(name==='anamnese')await anchor(page,'anamnese-native'+zoom*100);
  }
  await page.goto(base+'/planos/'+f.quick_plan);await page.locator('[data-call=openAddItemModal]:visible').first().focus();await page.keyboard.press('Enter');await page.locator('#modal-add-item').waitFor({state:'visible'});await page.waitForTimeout(50);
  check('diálogo aberto em zoom '+zoom,await page.locator('#modal-add-item').getAttribute('role')==='dialog');
  let retained=true;for(let i=0;i<14;i++){await page.keyboard.press('Tab');retained=retained&&await page.locator('#modal-add-item').evaluate(d=>d.contains(document.activeElement));}check('Tab em diálogo com zoom '+zoom,retained);
  await inspect(page,'modal-native'+zoom*100,'native-zoom-'+zoom*100);await page.keyboard.press('Escape');
  await context.close();fs.rmSync(profile,{recursive:true,force:true});
 }
 // Touch event emulation on the real UI, distinct from physical-device acceptance.
 browser=await chromium.launch({headless:true,executablePath:process.env.CHROME_PATH});const touch=await browser.newContext({viewport:{width:375,height:812},hasTouch:true,isMobile:true}),tp=await touch.newPage();tp.on('dialog',d=>d.accept());await login(tp);await tp.goto(base+'/agenda?date='+f.date);
 await tp.locator('[data-toggle-dialog="card-novo-agendamento"]').tap();check('touch abre nova consulta',await tp.locator('[data-agenda-new]').isVisible());await tp.locator('#appointment-patient-search').fill('TESTE Paciente A');await tp.locator('#appointment-patient-matches [role=option]').first().tap();check('touch seleciona paciente',await tp.locator('#dc11-patient_id').inputValue()===String(f.patient));await tp.locator('[data-agenda-new] [data-dialog-close]').tap();check('touch cancela com descarte explícito',!await tp.locator('[data-agenda-new]').isVisible());await touch.close();await browser.close();browser=null;
 check('sem erros JavaScript',errors.length===0);fs.writeFileSync(dir+'/report.json',JSON.stringify({issue:'NI2-630',browser_version:browserVersion,axe_version:axe.version,automated:true,states,checks,errors,native_zoom_method:'Per-host Chromium preference in disposable profiles; no CSS/viewport scale emulation. 1280 physical -> 640/320 CSS px asserted.',limitations:'Touch and forced colors emulated. VoiceOver/NVDA/TalkBack, physical devices and full WCAG certification require manual review. Table exceptions and incomplete axe entries are explicitly retained.'},null,2));
 const failures=checks.filter(x=>!x.pass);console.log(JSON.stringify({states:states.length,checks:checks.length,failures,findings:states.filter(x=>x.violations.length||x.internalHorizontalScroll.some(i=>!i.tableException)).map(x=>({label:x.label,violations:x.violations,internal:x.internalHorizontalScroll})),small_controls:states.filter(x=>x.smallControls.length).map(x=>({label:x.label,controls:x.smallControls}))}));if(failures.length)process.exitCode=1;
})().catch(async e=>{console.error(e);if(browser)await browser.close();process.exit(1)});
