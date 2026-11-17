// Real HTTP against tests/support/ni2_95_fixture.php data in an isolated database.
const assert=require('node:assert/strict');
const base=process.env.UX_API_URL || 'http://127.0.0.1:8098';
(async()=>{
const api=async(path,token,body)=>{const r=await fetch(base+'/api/v1/'+path,{method:body?'POST':'GET',headers:{'Content-Type':'application/json',...(token?{Authorization:'Bearer '+token}:{})},body:body?JSON.stringify(body):undefined});return {status:r.status,body:await r.json()}};
const login=async email=>(await api('auth/patient-login',null,{email,password:'PacienteTeste@2026'})).body.token;
const a=await login('ana95@example.test'), b=await login('bruno95@example.test'), c=await login('carla95@example.test');
assert.ok(a&&b&&c);
for(const path of ['patient/food-diary','patient/notification-preferences','patient/recipes']) assert.equal((await api(path,'expired-token')).status,401);
console.log('PASS expired session denied on diary, preferences and recipes');
assert.equal((await api('patient/checkin',a,{meal_adherence_pct:101})).status,422);
assert.equal((await api('patient/checkin',a,{notes:'No adherence'})).status,422);
console.log('PASS negative check-in: missing and invalid adherence refused');
const beforeB=await api('patient/food-diary',b),beforeC=await api('patient/food-diary',c);
const body={meal_name:'Lanche TESTE',description:'Comi diferente: banana 80 g',consumed_at:new Date().toISOString(),timezone:'America/Sao_Paulo',idempotency_key:'ni2_837_api_different'};
const first=await api('patient/food-diary',a,body),again=await api('patient/food-diary',a,body);
assert.ok(first.status>=200&&first.status<300,JSON.stringify(first));assert.equal(first.body.id,again.body.id);
const diary=await api('patient/food-diary',a);assert.ok(diary.body.entries.some(x=>x.description===body.description));
assert.deepEqual((await api('patient/food-diary',b)).body,beforeB.body);assert.deepEqual((await api('patient/food-diary',c)).body,beforeC.body);
console.log('PASS different consumption: actual text persisted, repeat deduplicated, isolated across patient and clinic');
const prefsB=(await api('patient/notification-preferences',b)).body;
const prefsC=(await api('patient/notification-preferences',c)).body;
assert.equal((await api('patient/notification-preferences',a,{checkin_reminder:{time:'99:99'}})).status,422);
await api('patient/notification-preferences',a,{quiet_hours:{enabled:true,start:'22:00',end:'07:00'},checkin_reminder:{enabled:true,time:'23:00'}});
const p=(await api('patient/notification-preferences',a)).body;assert.equal(p.quiet_hours.enabled,true);assert.equal(p.checkin_reminder.time,'23:00');
assert.deepEqual((await api('patient/notification-preferences',b)).body,prefsB);assert.deepEqual((await api('patient/notification-preferences',c)).body,prefsC);
console.log('PASS preferences: invalid time refused, quiet/reminder times persist, patient and clinic isolation');
})().catch(e=>{console.error(e);process.exitCode=1});
