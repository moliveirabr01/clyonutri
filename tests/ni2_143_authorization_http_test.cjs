const {spawn,execFileSync}=require('node:child_process');
const {mkdtempSync,rmSync}=require('node:fs');const net=require('node:net');const assert=require('node:assert/strict');
const root=require('node:path').resolve(__dirname,'..');const dir=mkdtempSync('/tmp/ni2-281-authz-');const env={...process.env,DB_CONNECTION:'sqlite',DB_DATABASE:dir+'/test.sqlite'};let server;
const sql=q=>execFileSync('php',['-r','$db=new PDO("sqlite:".getenv("DB_DATABASE"));$db->exec($argv[1]);',q],{env});
(async()=>{
execFileSync('php',['tests/support/ni2_281_fixture.php','prepare'],{cwd:root,env});
const socket=net.createServer();await new Promise(r=>socket.listen(0,'127.0.0.1',r));const port=socket.address().port;await new Promise(r=>socket.close(r));
server=spawn('php',['-d','session.save_path='+dir,'-S','127.0.0.1:'+port,'-t','public','public/index.php'],{cwd:root,env,stdio:'ignore'});const base='http://127.0.0.1:'+port;
for(let i=0;i<80;i++){try{await fetch(base+'/health');break;}catch{}await new Promise(r=>setTimeout(r,50));}
async function api(path,body,token){const r=await fetch(base+path,{method:body?'POST':'GET',headers:{'Content-Type':'application/json',...(token?{Authorization:'Bearer '+token}:{})},body:body?JSON.stringify(body):undefined});return{status:r.status,data:await r.json()};}
const login=async email=>(await api('/api/v1/auth/professional-login',{email,password:'PlanTeste@2026'})).data;
const prof=await login('plan1@example.test');assert.ok(prof.token);
const ownPatients=await api('/api/v1/professional/patients?clinic_id=2',null,prof.token);assert.equal(ownPatients.status,200);assert.ok(ownPatients.data.patients.every(p=>p.id!==2),'Query tenant cannot override credential');
sql("INSERT INTO clinic_members(clinic_id,user_id,role,status) VALUES(2,1,'nutritionist','active');UPDATE clinic_members SET status='revoked' WHERE user_id=1 AND clinic_id=1;");
assert.equal((await api('/api/v1/professional/patients',null,prof.token)).status,401,'Token must not switch tenant after membership revocation');
sql("UPDATE clinic_members SET status='active' WHERE user_id=1 AND clinic_id=1;UPDATE clinic_members SET role='receptionist' WHERE user_id=1;");
assert.equal((await api('/api/v1/professional/patients',null,prof.token)).status,403,'Role downgrade must revoke clinical authorization');
assert.equal((await api('/api/v1/auth/professional-login',{email:'plan1@example.test',password:'PlanTeste@2026'})).status,403,'Membership role required at issuance');
sql("UPDATE clinic_members SET role='owner' WHERE user_id=1 AND clinic_id=1;");
const revoked=await login('plan1@example.test');
execFileSync('php',['-r','require "vendor/autoload.php";(new ClyoNutri\\Core\\Auth\\AuthService())->revokeUserSessions(1);'],{cwd:root,env});
assert.equal((await api('/api/v1/professional/patients',null,revoked.token)).status,401,'Explicit session revocation invalidates professional bearer');
const versionRevoked=await login('plan1@example.test');sql('UPDATE users SET auth_version=auth_version+1 WHERE id=1');
assert.equal((await api('/api/v1/professional/patients',null,versionRevoked.token)).status,401,'Credential version alone revokes bearer when deletion is unavailable');
const fresh=await login('plan1@example.test');assert.ok(fresh.token);
const foreign=await api('/api/v1/professional/patients/2/app-invitation',{},fresh.token);assert.ok([403,404].includes(foreign.status),'Foreign invitation must return 403/404');
let cookie='';async function web(path,body){const r=await fetch(base+path,{redirect:'manual',method:body?'POST':'GET',headers:cookie?{Cookie:cookie}:{},body:body?new URLSearchParams(body):undefined});if(r.headers.get('set-cookie'))cookie=r.headers.get('set-cookie').split(';')[0];return{status:r.status,location:r.headers.get('location'),text:await r.text()};}
const form=await web('/login');const csrf=form.text.match(/name="_csrf_token" value="([^"]+)"/)[1];const receptionLogin=await web('/login',{_csrf_token:csrf,email:'plan3@example.test',password:'PlanTeste@2026'});assert.equal(receptionLogin.location,'/agenda','Receptionist login lands on allowed agenda');
assert.equal((await web('/agenda')).status,200,'Receptionist retains appointment workflow');
for(const path of ['/pacientes/1/prontuario','/pacientes/1','/pacientes/1/anamnese','/planos/1','/consulta/1','/chat'])assert.equal((await web(path)).status,403,'Receptionist cannot read '+path);
const pat=await api('/api/v1/auth/patient-onboarding/preview',{invitation_code:'x'});assert.equal(pat.status,422);
sql("UPDATE patients SET email='patient1@example.test',password_hash='"+execFileSync('php',['-r','echo password_hash("Patient@2026",PASSWORD_DEFAULT);'],{encoding:'utf8'})+"' WHERE id=1");
const pa=await api('/api/v1/auth/patient-login',{email:'patient1@example.test',password:'Patient@2026'});assert.equal(pa.status,200);
const ownHome=await api('/api/v1/patient/home?patient_id=2&clinic_id=2',null,pa.data.token);assert.equal(ownHome.status,200);assert.equal(ownHome.data.patient_name,'Paciente sintético 1');
sql("INSERT INTO appointments(id,clinic_id,patient_id,professional_id,scheduled_at,status) VALUES(99,2,2,2,'2026-10-20 10:00:00','scheduled')");
assert.equal((await api('/api/v1/patient/appointment/confirm',{appointment_id:99},pa.data.token)).status,403,'Foreign appointment denied before update');
assert.equal(execFileSync('php',['-r','$db=new PDO("sqlite:".getenv("DB_DATABASE"));echo $db->query("SELECT status FROM appointments WHERE id=99")->fetchColumn();'],{env,encoding:'utf8'}),'scheduled','Foreign appointment remains unchanged');
sql('UPDATE patients SET clinic_id=2 WHERE id=1');assert.equal((await api('/api/v1/patient/home',null,pa.data.token)).status,401,'Patient token must not follow a changed clinic');
console.log('PASS NI2-143 HTTP: tenant-bound tokens, role downgrade, clinical denial, receptionist agenda, foreign invite and patient reassignment.');
})().catch(e=>{console.error(e);process.exitCode=1;}).finally(()=>{server?.kill();rmSync(dir,{recursive:true,force:true});});
