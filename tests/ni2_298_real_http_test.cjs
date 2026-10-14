const {spawn,execFileSync}=require('node:child_process');
const {mkdtempSync,writeFileSync,rmSync}=require('node:fs');
const {join,resolve}=require('node:path');
const net=require('node:net');
const assert=require('node:assert/strict');
const root=resolve(__dirname,'..');
const dir=mkdtempSync('/tmp/ni2-298-http-');
const env={...process.env,DB_CONNECTION:'sqlite',DB_DATABASE:join(dir,'test.sqlite'),APP_TIMEZONE:'America/Sao_Paulo'};
const fixture=mode=>execFileSync('php',['tests/support/ni2_298_fixture.php',mode],{cwd:root,env,encoding:'utf8'});
const checks=[];let server,log='';
function check(name,ok){assert.ok(ok,name);checks.push({name,status:'PASS'});console.log('PASS '+name);}
(async()=>{
 const prepared=JSON.parse(fixture('prepare'));const baseline=JSON.parse(fixture('inspect'));
 const socket=net.createServer();await new Promise(r=>socket.listen(0,'127.0.0.1',r));const port=socket.address().port;await new Promise(r=>socket.close(r));
 server=spawn('php',['-d','session.name=NI298TEST','-d','session.save_path='+dir,'-S','127.0.0.1:'+port,'-t','public'],{cwd:root,env});
 server.stdout.on('data',d=>log+=d);server.stderr.on('data',d=>log+=d);
 const base='http://127.0.0.1:'+port;
 for(let i=0;i<80;i++){try{if((await fetch(base+'/up')).ok)break;}catch{}await new Promise(r=>setTimeout(r,50));}
 let cookie='';
 async function req(url,options={}){
  const r=await fetch(base+url,{...options,redirect:'manual',headers:{...(cookie?{Cookie:cookie}:{}),...options.headers}});
  const c=r.headers.get('set-cookie');if(c)cookie=c.split(';')[0];return {status:r.status,location:r.headers.get('location'),text:await r.text()};
 }
 async function login(email,password='AgendaTeste@2026'){
  const page=await req('/login');const csrf=page.text.match(/name="_csrf_token" value="([^"]+)"/)[1];
  return req('/login',{method:'POST',body:new URLSearchParams({_csrf_token:csrf,email,password})});
 }
 const unauth=await req('/dashboard');check('Unauthenticated dashboard denied without appointment data',unauth.status===302 && unauth.location==='/login' && !unauth.text.includes('NI298'));
 const ua=await req('/agenda');check('Unauthenticated agenda denied',ua.status===302 && ua.location==='/login');
 await login('agenda1@example.test','wrong');const invalid=await req('/dashboard');check('Invalid professional password cannot establish session',invalid.status===302 && invalid.location==='/login');
 const auth=await login('agenda1@example.test');check('Real password authentication establishes professional session',auth.status===302 && auth.location==='/dashboard');
 let dashboard=await req('/dashboard');let agenda=await req('/agenda?date='+prepared.date);
 const indicator=html=>Number(html.match(/data-testid="appointments-today-count">\s*(\d+)/)?.[1]);
 check('Dashboard indicator reads six persisted current-day appointments',dashboard.status===200 && indicator(dashboard.text)===6);
 check('Dashboard table contains six statuses excluding adjacent days and other tenant', ['scheduled','confirmed','in_progress','completed','cancelled','no_show'].every(s=>dashboard.text.includes('NI298 '+s)) && !dashboard.text.includes('NI298 previous') && !dashboard.text.includes('NI298 next') && !dashboard.text.includes('NI298 other-tenant'));
 check('Agenda count agrees with dashboard indicator',agenda.status===200 && agenda.text.includes('6 agendamento(s)'));
 check('Pending unconfirmed appointment matches actual agenda row',dashboard.text.includes('NI298 scheduled às 01:00') && !dashboard.text.includes('NI298 next às') && agenda.text.includes('NI298 scheduled'));
 check('Reading views preserves records and history',JSON.stringify(baseline)===JSON.stringify(JSON.parse(fixture('inspect'))));
 const csrf=agenda.text.match(/name="_csrf_token" value="([^"]+)"/)[1];
 const before=JSON.parse(fixture('inspect'));
 const noCsrf=await req('/agenda',{method:'POST',body:new URLSearchParams({patient_id:'1',scheduled_at:prepared.date+' 15:00:00'})});
 check('Mutation without CSRF is denied and persists nothing',noCsrf.status===403 && JSON.stringify(before)===JSON.stringify(JSON.parse(fixture('inspect'))));
 const created=await req('/agenda',{method:'POST',body:new URLSearchParams({_csrf_token:csrf,patient_id:'1',scheduled_at:prepared.date+' 15:00:00',duration_minutes:'30',type:'follow_up'})});
 const after=JSON.parse(fixture('inspect'));
 check('Real authenticated scheduling persists appointment and history',created.status===302 && created.location.includes('success=agendado') && after.appointments.length===before.appointments.length+1 && after.history.length===before.history.length+1);
 check('Scheduling appends history without rewriting existing events',JSON.stringify(before.history)===JSON.stringify(after.history.slice(0,before.history.length)));
 writeFileSync(join(root,'docs/validation/NI2-298/evidence/persistence.json'),JSON.stringify({before,after},null,2));
 dashboard=await req('/dashboard');agenda=await req('/agenda?date='+prepared.date);
 check('Persisted new appointment updates indicator, agenda and pending list',indicator(dashboard.text)===7 && agenda.text.includes('7 agendamento(s)') && dashboard.text.includes('NI298 scheduled às 15:00'));
 const cross=await req('/agenda',{method:'POST',body:new URLSearchParams({_csrf_token:csrf,patient_id:'9',scheduled_at:prepared.date+' 16:00:00'})});
 check('Cross-tenant scheduling denied without persisting',cross.status===302 && !cross.location.includes('success=') && JSON.parse(fixture('inspect')).appointments.length===after.appointments.length);
 const badDate=await req('/agenda?date=2026-02-30');check('Invalid date returns explicit 400 rather than empty agenda',badDate.status===400 && badDate.text.includes('Data inválida') && !badDate.text.includes('Nenhuma consulta agendada'));
 for(const suffix of ['?date[]=2026-10-08','?date=2026-10-08%00']) { const malformed=await req('/agenda'+suffix); check('Malformed date returns 400: '+suffix,malformed.status===400 && !malformed.text.includes('Nenhuma consulta agendada')); }
 fixture('break');
 const failed=await req('/dashboard');const failedAgenda=await req('/agenda');
 check('Database failure produces 503 and retry rather than false zero',failed.status===503 && failed.text.includes('Tentar novamente') && !failed.text.includes('appointments-today-count') && !failed.text.includes('no such table'));
 check('Agenda database failure differs from empty state',failedAgenda.status===503 && !failedAgenda.text.includes('Nenhuma consulta agendada') && !failedAgenda.text.includes('no such table'));
 fixture('restore');check('Recovery restores actual persisted agenda',(await req('/agenda?date='+prepared.date)).text.includes('7 agendamento(s)'));
 await webLogout(req);await login('agenda2@example.test');const other=await req('/dashboard');
 check('Other authenticated clinic sees only own indicator and patient',indicator(other.text)===1 && other.text.includes('NI298 other-tenant') && !other.text.includes('NI298 completed') && !other.text.includes('NI298 scheduled'));
 await webLogout(req);await login('agenda3@example.test');const empty=await req('/dashboard');const emptyAgenda=await req('/agenda?date='+prepared.date);
 check('Real empty clinic yields zero and explicit empty state',indicator(empty.text)===0 && empty.text.includes('Nenhuma consulta agendada para hoje') && emptyAgenda.text.includes('Nenhuma consulta agendada para este dia'));
 await webLogout(req);check('Logout removes access to protected views',(await req('/dashboard')).location==='/login');
 console.log(checks.length+'/'+checks.length+' checks passed');
 writeFileSync(join(root,'docs/validation/NI2-298/evidence/http-context.json'),JSON.stringify(prepared,null,2));
})().catch(e=>{checks.push({name:e.message,status:'FAIL'});console.error(e);process.exitCode=1;}).finally(()=>{
 if(server)server.kill();
 writeFileSync(join(root,'docs/validation/NI2-298/evidence/http-results.json'),JSON.stringify({timestamp:new Date().toISOString(),transport:'real HTTP TCP to production public/index.php; SQLite persisted synthetic fixtures',checks},null,2));
 writeFileSync(join(root,'docs/validation/NI2-298/evidence/http-server.log'),log);
 rmSync(dir,{recursive:true,force:true});
});

// Logout web exige POST + CSRF: lê o token do formulário de confirmação (GET /logout → 405) e envia o POST.
async function webLogout(req){const page=await req('/logout');const m=(page.text||'').match(/name="_csrf_token" value="([^"]+)"/);if(m)await req('/logout',{method:'POST',body:new URLSearchParams({_csrf_token:m[1]})});}
