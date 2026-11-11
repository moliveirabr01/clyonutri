const {chromium}=require(process.env.PLAYWRIGHT_MODULE||'playwright'),axe=require(process.env.AXE_MODULE||'axe-core'),fs=require('fs'),assert=require('assert');
(async()=>{
const f=JSON.parse(fs.readFileSync(process.env.ADAPTIVE_FIXTURE_FILE)),base=process.env.UX_BASE_URL,out=process.env.UX_EVIDENCE_DIR;
assert(new URL(base).hostname==='127.0.0.1');fs.mkdirSync(out,{recursive:true});
const b=await chromium.launch({headless:true,executablePath:process.env.CHROME_PATH}),checks=[],states=[],errors=[];
const check=(name,pass)=>{checks.push({name,pass:!!pass});assert(pass,name);};
async function login(p,email=f.email){await p.goto(base+'/login');await p.locator('#email').fill(email);await p.locator('#password').fill('Synthetic-local-651!');await p.locator('button[type=submit]').click();await p.waitForLoadState();}
async function inspect(p,label){await p.evaluate(axe.source);const a=await p.evaluate(()=>axe.run(document,{runOnly:{type:'tag',values:['wcag2a','wcag2aa','wcag21a','wcag21aa','wcag22aa']}}));const g=await p.evaluate(()=>({width:innerWidth,scroll:document.documentElement.scrollWidth}));
states.push({label,...g,violations:a.violations.map(v=>({id:v.id,nodes:v.nodes.map(n=>({target:n.target,summary:n.failureSummary}))})),incomplete:a.incomplete.map(x=>({id:x.id,count:x.nodes.length}))});
check(label+' no horizontal overflow',g.scroll<=g.width);check(label+' axe detected violations zero',a.violations.length===0);}
try{
const c=await b.newContext(),p=await c.newPage();p.on('pageerror',e=>errors.push(e.message));await login(p);
const route=base+'/planos/'+f.diet_plan+'/sugestoes-dieteticas';
await p.goto(route);check('proposal visible for confirmed condition',await p.locator('legend').filter({hasText:'Planejamento individualizado'}).count()===1);
check('no preselected recommendation',await p.locator('[name="selected[]"]:checked').count()===0);
check('confirmation starts unchecked',!await p.locator('[name=confirmed]').isChecked());
const payload=await p.locator('form[action*="sugestoes-dieteticas"]').evaluate(form=>{const o={};for(const [k,v]of new FormData(form))o[k]=v;return o;});
const selected=await p.locator('[name="selected[]"]').first().inputValue();
payload['selected[]']=selected;payload['reason']='TESTE análise clínica individual';payload['texts['+selected+']']='TESTE orientação revisada no navegador';
let r=await c.request.post(route,{form:payload});check('HTTP refuses missing clinical confirmation',r.status()===422);
payload.confirmed='1';const bad={...payload,_csrf_token:'invalid'};
r=await c.request.post(route,{form:bad});check('HTTP refuses invalid CSRF',r.status()===403);
const stalePage=await c.newPage();await stalePage.goto(route);const stale=await stalePage.locator('form[action*="sugestoes-dieteticas"]').evaluate(form=>{const o={};for(const[k,v]of new FormData(form))o[k]=v;return o;});
await p.goto(route);await p.locator('[name="selected[]"]').first().check();await p.locator('textarea[name^="texts"]').first().fill('TESTE orientação revisada no navegador');await p.locator('#diet-reason').fill('TESTE análise clínica individual');await p.locator('[name=confirmed]').check();
await p.getByRole('button',{name:'Confirmar e incluir no rascunho'}).click();
check('UI confirms persistence',await p.getByRole('status').textContent().then(t=>t.includes('incluídas no rascunho')));
r=await c.request.post(route,{form:payload});check('old same key is either first save or stale',r.status()===409);
stale['selected[]']=selected;stale['texts['+selected+']']='TESTE aba anterior';stale.reason='TESTE justificativa clínica';stale.confirmed='1';
r=await c.request.post(route,{form:stale});check('second tab gets conflict',r.status()===409);await stalePage.close();
await p.goto(base+'/planos/'+f.diet_plan);
check('reviewed guidance present in plan',await p.locator('body').innerText().then(t=>t.includes('TESTE orientação revisada no navegador')));
await p.goto(route);
for(const width of [375,656,1280,1440]){await p.setViewportSize({width,height:900});await inspect(p,'suggestions-'+width);}
await p.setViewportSize({width:375,height:900});await p.screenshot({path:out+'/suggestions-mobile.png',fullPage:true});
await p.goto(base+'/configuracoes/protocolos/editor');
await p.locator('form').filter({has:p.locator('input[name=condition][value=gastrite]')}).getByRole('button').click();
check('starter created as draft with explicit pending review',await p.getByRole('status').innerText().then(t=>t.includes('rascunho')));
const version=await p.locator('[data-protocol-editor] [name=version_id]').inputValue();
check('starter carries dietary metadata in editor',await p.locator('[name=dietary_condition]').inputValue()==='gastrite');
await p.locator('[name="dietary[0][guidance]"]').fill('TESTE texto qualitativo revisado na edição');
await p.locator('[data-protocol-editor] button[value=save]').click();
check('editor saves proposal without stripping dietary data',await p.locator('[name="dietary[0][guidance]"]').inputValue()==='TESTE texto qualitativo revisado na edição');
let csrf=await p.locator('[data-protocol-editor] [name=_csrf_token]').inputValue();
r=await c.request.post(base+'/configuracoes/protocolos/editor',{form:{_csrf_token:csrf,version_id:version,action:'submit'}});
check('real editorial submission succeeds',r.status()===200&&(await r.text()).includes('Enviado para revisão'));
r=await c.request.post(base+'/configuracoes/protocolos/editor',{form:{_csrf_token:csrf,version_id:version,action:'approve',review_due_at:'2027-06-01'}});
check('author cannot self approve',!(await r.text()).includes('Versão aprovada. A ativação'));
const reviewer=await b.newContext(),rp=await reviewer.newPage();await login(rp,f.email.replace('0@','1@'));await rp.goto(base+'/configuracoes/protocolos/editor?version_id='+version);
csrf=await rp.locator('[data-protocol-editor] [name=_csrf_token]').inputValue();
r=await reviewer.request.post(base+'/configuracoes/protocolos/editor',{form:{_csrf_token:csrf,version_id:version,action:'approve',review_due_at:'2027-06-01'}});
check('independent synthetic reviewer can approve',r.status()===200&&(await r.text()).includes('Versão aprovada.'));
await rp.goto(base+'/configuracoes/protocolos/editor?version_id='+version);
check('approved version preserves dietary text',await rp.locator('[name="dietary[0][guidance]"]').inputValue()==='TESTE texto qualitativo revisado na edição');await reviewer.close();
await p.goto(base+'/configuracoes/protocolos');
const starterRow=p.locator('li').filter({hasText:'Proposta dietética — Gastrite'}).first();
check('clinical approval does not auto enable starter',await starterRow.innerText().then(t=>t.includes('Desligado')));
await p.goto(base+'/configuracoes/protocolos/editor?version_id='+version);await inspect(p,'dietary-editor-mobile');
const other=await b.newContext(),op=await other.newPage();await login(op,f.email_b);r=await other.request.get(route);
check('cross-clinic HTTP denied without suggestion content',r.status()===403&&!(await r.text()).includes('Planejamento individualizado'));
await other.close();await c.close();

for(const engine of ['firefox','webkit']){
 const otherBrowser=await require(process.env.PLAYWRIGHT_MODULE||'playwright')[engine].launch({headless:true});
 try{
  const page=await otherBrowser.newPage({viewport:{width:375,height:900}});await login(page);await page.goto(route);await inspect(page,'suggestions-'+engine+'-375');
  await page.goto(base+'/configuracoes/protocolos/editor?version_id='+version);await inspect(page,'editor-'+engine+'-375');await page.close();
 }finally{await otherBrowser.close();}
}
for(const zoom of [2,4]){
 const profile=fs.mkdtempSync('/tmp/ni2703-native-');fs.mkdirSync(profile+'/Default');
 fs.writeFileSync(profile+'/Default/Preferences',JSON.stringify({partition:{per_host_zoom_levels:{x:{'127.0.0.1':{last_modified:'13400000000000000',zoom_level:Math.log(zoom)/Math.log(1.2)}}}}}));
 let context;
 try{
  context=await chromium.launchPersistentContext(profile,{headless:true,executablePath:process.env.CHROME_PATH,viewport:null,args:['--window-size=1280,900']});
  const page=context.pages()[0];await login(page);await page.goto(route);
  check('dietary native zoom geometry '+zoom,await page.evaluate(z=>Math.abs(devicePixelRatio-z)<.01&&Math.abs(outerWidth/innerWidth-z)<.01&&getComputedStyle(document.documentElement).zoom==='1'&&visualViewport.scale===1,zoom));
  await inspect(page,'suggestions-native-'+zoom*100);
  const capture=await context.newCDPSession(page),shot=await capture.send('Page.captureScreenshot',{format:'png',captureBeyondViewport:false,fromSurface:true});await capture.detach();fs.writeFileSync(out+'/suggestions-native'+zoom*100+'.png',Buffer.from(shot.data,'base64'));
 }finally{if(context)await context.close();fs.rmSync(profile,{recursive:true,force:true});}
}

check('no uncaught JavaScript errors',errors.length===0);
fs.writeFileSync(out+'/browser.json',JSON.stringify({suite:'NI2-703 browser','synthetic':true,'clinical_approval':false,browser:b.version(),axe_version:axe.version,checks,states,errors},null,2));
console.log(JSON.stringify({checks:checks.length,states:states.length,failed:checks.filter(x=>!x.pass)}));
}finally{await b.close();}
})().catch(e=>{console.error(e);process.exitCode=1});
