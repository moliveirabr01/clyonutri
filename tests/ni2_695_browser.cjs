const {chromium}=require(process.env.PLAYWRIGHT_MODULE||'playwright'),fs=require('fs'),path=require('path'),assert=require('assert');
(async()=>{
 const f=JSON.parse(fs.readFileSync(process.env.ADAPTIVE_FIXTURE_FILE)),base=process.env.UX_BASE_URL,root=path.resolve(__dirname,'..'),out=process.env.UX_EVIDENCE_DIR;
 assert(new URL(base).hostname==='127.0.0.1','local synthetic server only');
 const checks=[];let b,lock=root+'/storage/maintenance_migration.lock';
 function check(name,ok){checks.push({name,pass:!!ok});assert(ok,name);}
 async function login(p,email=f.email){await p.goto(base+'/login');await p.locator('#email').fill(email);await p.locator('#password').fill('Synthetic-local-651!');await p.locator('button[type=submit]').click();await p.waitForLoadState();}
 async function call(p,method,data){return p.evaluate(async({url,method,data})=>{const token=document.querySelector('[name=_csrf_token]')?.value;const r=await fetch(url,{method,headers:{'Accept':'application/json','Content-Type':'application/json','X-CSRF-Token':token||''},body:method==='POST'?JSON.stringify(data):undefined});return {status:r.status,body:await r.json()};},{url:'/consulta/'+f.appointment+'/rascunho',method,data});}
 try{
 b=await chromium.launch({headless:true,executablePath:process.env.CHROME_PATH});
 const anon=await b.newContext(),ap=await anon.newPage();await ap.goto(base+'/configuracoes/versao');check('anonymous diagnostic requires login',new URL(ap.url()).pathname==='/login');await anon.close();
 const c=await b.newContext(),p=await c.newPage();await login(p);let r=await c.request.get(base+'/configuracoes/versao');
 check('owner diagnostic available',r.status()===200);
 check('diagnostic never cached',r.headers()['cache-control'].includes('no-store'));
 let html=await r.text();check('diagnostic distinct UI/package',html.includes('v54.32')&&html.includes('v54.27')&&html.includes('SHA-256'));
 check('diagnostic does not expose inventory or absolute paths',!html.includes('build-manifest.json')&&!html.includes(root)&&!html.includes('patient_token_hash'));
 await p.goto(base+'/consulta/'+f.appointment);let draft=await call(p,'GET');check('authenticated consultation draft endpoint',draft.status===200);
 const version=draft.body.version,notes='TESTE NI2-697 persisted '+Date.now();
 r=await call(p,'POST',{version,evolution_summary:notes});check('HTTP save acknowledgement',r.status===200&&r.body.status==='saved');
 const current=r.body.version;
 const tab=await c.newPage();await tab.goto(base+'/consulta/'+f.appointment);r=await call(tab,'POST',{version,evolution_summary:'TESTE stale tab'});
 check('two tabs conflict HTTP409',r.status===409&&r.body.server.evolution_summary===notes);
 r=await call(tab,'POST',{version:current,evolution_summary:'x'.repeat(60001)});check('HTTP422 does not confirm invalid save',r.status===422);
 fs.writeFileSync(lock,'synthetic local fault');
 try{r=await call(tab,'POST',{version:current,evolution_summary:'TESTE failed write'});check('real server write lock returns HTTP503',r.status===503&&r.body.status==='error');}finally{fs.unlinkSync(lock);}
 r=await call(tab,'GET');check('4xx and 5xx keep last confirmed content',r.body.version===current&&r.body.evolution_summary===notes);
 await p.close();await tab.close();
 const resumed=await c.newPage();await resumed.goto(base+'/consulta/'+f.appointment);r=await call(resumed,'GET');check('closed tab resumes server data',r.body.evolution_summary===notes);
 await c.setOffline(true);
 let offlineFailed=false;try{await call(resumed,'POST',{version:current,evolution_summary:'TESTE offline'});}catch(e){offlineFailed=true;}
 check('offline save fails without server acknowledgement',offlineFailed);await c.setOffline(false);
 r=await call(resumed,'GET');check('offline write did not replace confirmed content',r.body.evolution_summary===notes&&r.body.version===current);
 await c.clearCookies();await login(resumed);await resumed.goto(base+'/consulta/'+f.appointment);r=await call(resumed,'GET');check('reauthentication resumes persisted draft',r.body.evolution_summary===notes);
 const cookie=(await c.cookies()).find(x=>x.name==='PHPSESSID');assert(cookie&&/^[a-zA-Z0-9,-]+$/.test(cookie.value));
 const session=root+'/storage/sessions/sess_'+cookie.value;const raw=fs.readFileSync(session,'utf8');assert(raw.includes('last_activity|i:'));
 fs.writeFileSync(session,raw.replace(/last_activity\|i:\d+;/,'last_activity|i:1;'));
 r=await call(resumed,'GET');check('expired session refuses draft access',r.status===401);
 await login(resumed);await resumed.goto(base+'/consulta/'+f.appointment);r=await call(resumed,'GET');check('timeout and reauth preserve persisted draft',r.body.evolution_summary===notes);
 if(process.env.UX_ISOLATED_TEST_RUN==='1'){
  require('child_process').execFileSync('php',[path.join(__dirname,'ni2_695_revoke.php'),process.env.DB_DATABASE,String(f.user)],{stdio:'pipe'});
  r=await c.request.get(base+'/configuracoes/versao',{maxRedirects:0});
  check('revoked canonical session cannot read diagnostic',r.status()===302||r.status()===401);
 }
 await c.close();
 const member=await b.newContext(),mp=await member.newPage();await login(mp,f.email.replace('0@','1@'));
 r=await member.request.get(base+'/configuracoes/versao');check('ordinary clinical member forbidden diagnostic',r.status()===403);
 await mp.goto(base+'/consulta/'+f.appointment);r=await call(mp,'GET');check('other actor does not inherit consultation notes',r.status===200&&r.body.version===0);await member.close();
 const other=await b.newContext(),op=await other.newPage();await login(op,f.email_b);
 r=await other.request.get(base+'/configuracoes/versao');check('other clinic owner sees global build metadata',r.status()===200);
 await op.goto(base+'/agenda');r=await call(op,'GET');check('other clinic never receives draft content',r.status!==200&&!JSON.stringify(r.body).includes(notes));await other.close();
 if(process.env.UX_ISOLATED_TEST_RUN==='1'){
  const role=(id,value)=>require('child_process').execFileSync('php',[path.join(__dirname,'ni2_695_role.php'),process.env.DB_DATABASE,String(id),value],{stdio:'pipe'});
  try{
   role(f.user_b,'support_agent');
   const staff=await b.newContext(),sp=await staff.newPage();await login(sp,f.email_b);
   for(const route of ['/admin/versao','/suporte/versao']){
    r=await staff.request.get(base+route);const body=await r.text();
    check('platform staff protected diagnostic '+route,r.status()===200&&body.includes('v54.32')&&body.includes('Os arquivos conferem'));
   }
   await staff.close();
  }finally{role(f.user_b,'nutritionist');}
  try{
   role(f.reviewer,'receptionist');
   const reception=await b.newContext(),rp=await reception.newPage();await login(rp,f.email.replace('0@','1@'));
   r=await reception.request.get(base+'/configuracoes/versao');check('receptionist forbidden build diagnostic',r.status()===403);await reception.close();
  }finally{role(f.reviewer,'nutritionist');}
 }
 fs.mkdirSync(out,{recursive:true});fs.writeFileSync(out+'/browser-integrity.json',JSON.stringify({suite:'NI2-695 protected identity and NI2-697 HTTP integrity',browser:b.version(),synthetic:true,checks},null,2));
 console.log(JSON.stringify({checks:checks.length,failed:checks.filter(x=>!x.pass)}));
 }finally{if(fs.existsSync(lock))fs.unlinkSync(lock);if(b)await b.close();}
})().catch(e=>{console.error(e);process.exitCode=1;});
