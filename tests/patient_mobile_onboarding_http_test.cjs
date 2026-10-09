const assert = require('node:assert/strict');
const { execFileSync } = require('node:child_process');
const base = process.env.ONBOARDING_TEST_URL || 'http://127.0.0.1:8097';
const db = process.env.DB_DATABASE;
if (!db?.startsWith('/tmp/ni2-281-')) throw Error('Isolated fixture database required');
function sql(query) { execFileSync('php', ['-r', '$db=new PDO("sqlite:".getenv("DB_DATABASE"));$db->exec($argv[1]);', query], { env: process.env }); }
async function post(path, body={}, token) {
 const r=await fetch(base+path,{method:'POST',headers:{'Content-Type':'application/json',...(token?{Authorization:`Bearer ${token}`}:{})},body:JSON.stringify(body)});
 return {status:r.status, data:await r.json()};
}
(async()=>{
 const login=await post('/api/v1/auth/professional-login',{email:'plan1@example.test',password:'PlanTeste@2026'}); assert.equal(login.status,200); const prof=login.data.token;
 assert.equal((await post('/api/v1/professional/patients/2/app-invitation',{},prof)).status,422);
 const reception=await post('/api/v1/auth/professional-login',{email:'plan3@example.test',password:'PlanTeste@2026'});
 if(reception.status===200) assert.equal((await post('/api/v1/professional/patients/1/app-invitation',{},reception.data.token)).status,422);
 assert.equal((await post('/api/v1/auth/patient-onboarding/preview',{invitation_code:'invalid'})).status,422);
 let inv=await post('/api/v1/professional/patients/1/app-invitation',{},prof); assert.equal(inv.status,200); const old=inv.data.invitation_code;
 inv=await post('/api/v1/professional/patients/1/app-invitation',{},prof); const code=inv.data.invitation_code;
 assert.equal((await post('/api/v1/auth/patient-onboarding/preview',{invitation_code:old})).status,422);
 const preview=await post('/api/v1/auth/patient-onboarding/preview',{invitation_code:code}); assert.equal(preview.status,200); assert.equal(preview.data.clinic_name,'Clínica sintética 1'); assert.ok(!('notes' in preview.data.patient));
 const fields={invitation_code:code,name:'Paciente mobile de teste',email:'patient-app@example.test',phone:'21911112222',password:'AppTeste@2026',password_confirmation:'AppTeste@2026',clinic_id:2,patient_id:2};
 assert.equal((await post('/api/v1/auth/patient-onboarding/complete',{...fields,password_confirmation:'diferente'})).status,422);
 sql("CREATE TRIGGER onboarding_fail_token BEFORE INSERT ON personal_access_tokens WHEN NEW.tokenable_type='patient' BEGIN SELECT RAISE(ABORT,'synthetic failure'); END");
 assert.equal((await post('/api/v1/auth/patient-onboarding/complete',fields)).status,500);
 sql('DROP TRIGGER onboarding_fail_token');
 assert.equal((await post('/api/v1/auth/patient-onboarding/preview',{invitation_code:code})).status,200);
 const activated=await post('/api/v1/auth/patient-onboarding/complete',fields); assert.equal(activated.status,200); assert.equal(activated.data.patient.id,1);assert.equal(activated.data.patient.clinic_id,1);
 assert.equal((await post('/api/v1/auth/patient-onboarding/complete',fields)).status,422);
 assert.equal((await post('/api/v1/professional/patients/1/app-invitation',{},prof)).status,422);
 const auth=await post('/api/v1/auth/patient-login',{email:fields.email,password:fields.password});assert.equal(auth.status,200);
 const home=await fetch(base+'/api/v1/patient/home',{headers:{Authorization:`Bearer ${activated.data.token}`}});assert.equal(home.status,200);
 const prof2=await post('/api/v1/auth/professional-login',{email:'plan2@example.test',password:'PlanTeste@2026'});
 const invite2=await post('/api/v1/professional/patients/2/app-invitation',{},prof2.data.token);assert.equal(invite2.status,200);
 sql("UPDATE patient_app_invitations SET expires_at='2000-01-01 00:00:00' WHERE patient_id=2");
 assert.equal((await post('/api/v1/auth/patient-onboarding/preview',{invitation_code:invite2.data.invitation_code})).status,422);
 console.log('PASS: invitation scope, role, revocation, expiry, rollback, activation, replay, login and authenticated home.');
})();
