const {spawn,execFileSync}=require('node:child_process');
const {mkdtempSync,rmSync}=require('node:fs');const net=require('node:net');const assert=require('node:assert/strict');
const root=require('node:path').resolve(__dirname,'..');const dir=mkdtempSync('/tmp/ni2-281-patients-');const env={...process.env,DB_CONNECTION:'sqlite',DB_DATABASE:dir+'/test.sqlite'};let server;
const sql=q=>execFileSync('php',['-r','$db=new PDO("sqlite:".getenv("DB_DATABASE"));$db->exec($argv[1]);',q],{env});
(async()=>{
execFileSync('php',['tests/support/ni2_281_fixture.php','prepare'],{cwd:root,env});
sql("UPDATE patients SET email='patient1@example.test',phone='(11) 98765-4321' WHERE id=1; INSERT INTO appointments(clinic_id,patient_id,professional_id,scheduled_at) VALUES(1,1,1,'2026-10-09 09:00'); INSERT INTO checkins(clinic_id,patient_id,meal_adherence_pct,checkin_date) VALUES(1,1,75,'2026-10-09'); INSERT INTO receivables(clinic_id,patient_id,description,amount_cents,due_date) VALUES(1,1,'Cobrança sintética',10000,'2026-10-10'); INSERT INTO patient_consents(clinic_id,patient_id,consent_type,granted,term_id,source_channel) VALUES(1,1,'clinical_care',1,1,'professional_attestation');");
const sock=net.createServer();await new Promise(r=>sock.listen(0,'127.0.0.1',r));const port=sock.address().port;await new Promise(r=>sock.close(r));
server=spawn('php',['-d','session.save_path='+dir,'-S','127.0.0.1:'+port,'-t','public','public/index.php'],{cwd:root,env,stdio:'ignore'});const base='http://127.0.0.1:'+port;
for(let i=0;i<80;i++){try{await fetch(base+'/health');break;}catch{}await new Promise(r=>setTimeout(r,50));}
let cookie='';async function web(path,body){const r=await fetch(base+path,{redirect:'manual',method:body?'POST':'GET',headers:cookie?{Cookie:cookie}:{},body:body?new URLSearchParams(body):undefined});if(r.headers.get('set-cookie'))cookie=r.headers.get('set-cookie').split(';')[0];return{status:r.status,location:r.headers.get('location'),text:await r.text()};}
const csrfOf=r=>r.text.match(/name="_csrf_token" value="([^"]+)"/)[1];
let login=await web('/login');await web('/login',{_csrf_token:csrfOf(login),email:'plan1@example.test',password:'PlanTeste@2026'});
let list=await web('/pacientes');assert.equal(list.status,200);assert.ok(list.text.includes('name="status"'));assert.ok(!list.text.includes('Paciente sintético 2'));
let filtered=await web('/pacientes?q=does-not-exist&status=inactive');assert.equal(filtered.status,200);assert.ok(filtered.text.includes('Nenhum paciente encontrado para estes filtros.'));
let profile=await web('/pacientes/1');assert.equal(profile.status,200);for(const label of ['Check-ins recentes','Financeiro do paciente','Linha do tempo','Consentimentos e privacidade','Cobrança sintética'])assert.ok(profile.text.includes(label),label);
const csrf=csrfOf(profile);
assert.equal((await web('/pacientes/1/status',{status:'inactive',confirm_status:'yes'})).status,403,'CSRF status');
assert.equal((await web('/pacientes/2/status',{_csrf_token:csrf,status:'inactive',confirm_status:'yes'})).status,404,'Foreign status');
assert.equal((await web('/pacientes/1/status',{_csrf_token:csrf,status:'inactive'})).status,422,'Status confirmation');
assert.equal((await web('/pacientes/1/status',{_csrf_token:csrf,status:'inactive',confirm_status:'yes'})).status,302);
filtered=await web('/pacientes?status=inactive');assert.ok(filtered.text.includes('Paciente sintético 1'));
assert.equal((await web('/pacientes/1/status',{_csrf_token:csrf,status:'active',confirm_status:'yes'})).status,302);
assert.equal((await web('/pacientes/2/consentimento/retirar',{_csrf_token:csrf,purpose:'clinical_care',reason:'Pedido',confirm_revoke:'yes'})).status,404);
assert.equal((await web('/pacientes/1/consentimento/retirar',{_csrf_token:csrf,purpose:'clinical_care',reason:'Pedido do titular',confirm_revoke:'yes'})).status,302);
profile=await web('/pacientes/1');assert.ok(profile.text.includes('Retirado em'));assert.ok(profile.text.includes('Pedido do titular'));
const pack=await web('/pacientes/1/privacidade',{_csrf_token:csrf,request_type:'export',confirm_request:'yes'});assert.equal(pack.status,200);let data=JSON.parse(pack.text);assert.equal(data.patient.id,1);assert.equal(data.appointments.length,1);assert.ok(!('password_hash' in data.patient));
assert.equal((await web('/pacientes/1/privacidade',{_csrf_token:csrf,request_type:'deletion',confirm_request:'yes'})).status,302);
list=await web('/pacientes');const submission=list.text.match(/name="_submission_id" value="([^"]+)"/)[1];assert.equal((await web('/pacientes',{_csrf_token:csrfOf(list),_submission_id:submission,name:'Duplicate',email:'patient1@example.test'})).status,302);
list=await web('/pacientes');assert.ok(list.text.includes('Já existe um paciente'));assert.ok(list.text.includes('Conferir cadastro existente'));
const freshSubmission=list.text.match(/name="_submission_id" value="([^"]+)"/)[1];
assert.equal((await web('/pacientes',{_csrf_token:csrfOf(list),_submission_id:freshSubmission,name:'Paciente novo',email:'new@example.test',consent_choice:'not_collected'})).status,302);
list=await web('/pacientes?success=1');assert.ok(list.text.includes('Iniciar anamnese'));assert.ok(list.text.includes('Paciente novo'));
const edit=await web('/pacientes/1/editar');const edited=await web('/pacientes/1/editar',{_csrf_token:csrfOf(edit),name:'Nome atualizado',email:'changed@example.test',phone:'11987654321',birth_date:'1990-01-01',gender:'Outro',notes:'Atualizado'});assert.equal(edited.status,302);
cookie='';login=await web('/login');await web('/login',{_csrf_token:csrfOf(login),email:'plan3@example.test',password:'PlanTeste@2026'});
for(const path of ['/pacientes/1/status','/pacientes/1/consentimento/retirar','/pacientes/1/privacidade'])assert.equal((await web(path,{_csrf_token:csrf,status:'inactive',confirm_status:'yes'})).status,403,'Receptionist '+path);
console.log('PASS NI2-5 HTTP: rendered list/profile, filters, duplicate recovery, edit, status confirmation, CSRF, cross-tenant, consent withdrawal, export/deletion request, receptionist denial.');
})().catch(e=>{console.error(e);process.exitCode=1;}).finally(()=>{server?.kill();rmSync(dir,{recursive:true,force:true});});
