const {spawn,execFileSync}=require('node:child_process');
const {mkdtempSync,rmSync}=require('node:fs');const net=require('node:net');const assert=require('node:assert/strict');
const root=require('node:path').resolve(__dirname,'..');const dir=mkdtempSync('/tmp/ni2-281-patients-');const env={...process.env,DB_CONNECTION:'sqlite',DB_DATABASE:dir+'/test.sqlite'};let server;
const sql=q=>execFileSync('php',['-r','$db=new PDO("sqlite:".getenv("DB_DATABASE"));$db->exec($argv[1]);',q],{env});
(async()=>{
execFileSync('php',['tests/support/ni2_281_fixture.php','prepare'],{cwd:root,env});
const sock=net.createServer();await new Promise(r=>sock.listen(0,'127.0.0.1',r));const port=sock.address().port;await new Promise(r=>sock.close(r));
server=spawn('php',['-d','session.save_path='+dir,'-S','127.0.0.1:'+port,'-t','public','public/index.php'],{cwd:root,env,stdio:'ignore'});const base='http://127.0.0.1:'+port;
for(let i=0;i<80;i++){try{await fetch(base+'/health');break;}catch{}await new Promise(r=>setTimeout(r,50));}
let cookie='';async function web(path,body){const r=await fetch(base+path,{redirect:'manual',method:body?'POST':'GET',headers:cookie?{Cookie:cookie}:{},body:body?new URLSearchParams(body):undefined});if(r.headers.get('set-cookie'))cookie=r.headers.get('set-cookie').split(';')[0];return{status:r.status,location:r.headers.get('location'),text:await r.text()};}
const csrfOf=r=>r.text.match(/name="_csrf_token" value="([^"]+)"/)[1];
let login=await web('/login');await web('/login',{_csrf_token:csrfOf(login),email:'plan1@example.test',password:'PlanTeste@2026'});
execFileSync('php',['-r','require "vendor/autoload.php";(new ClyoNutri\\Domain\\Food\\FoodCatalogService())->seedTacoReference();'],{cwd:root,env});
const examples=JSON.parse(execFileSync('php',['bin/seed_fit_example_recipes.php','1','1'],{cwd:root,env,encoding:'utf8'})).created;
const recipe=examples[0];
const form=await web('/receitas/'+recipe.id+'/prescrever?patient_id=1');
const token=form.text.match(/name="csrf_token" value="([^"]+)"/)[1];
const saved=await web('/receitas/'+recipe.id+'/prescrever',{csrf_token:token,patient_id:'1',meal_plan_id:'1',meal_name:'Café da manhã',servings:'1.5',instructions:'Consumir após o treino.'});assert.equal(saved.status,302,saved.text);
async function api(path,body,token){const r=await fetch(base+path,{method:body?'POST':'GET',headers:{'Content-Type':'application/json',...(token?{Authorization:'Bearer '+token}:{})},body:body?JSON.stringify(body):undefined});return{status:r.status,data:await r.json()};}
const professional=await api('/api/v1/auth/professional-login',{email:'plan1@example.test',password:'PlanTeste@2026'});assert.equal(professional.status,200);
const invitation=await api('/api/v1/professional/patients/1/app-invitation',{},professional.data.token);assert.equal(invitation.status,200);
const patient=await api('/api/v1/auth/patient-onboarding/complete',{invitation_code:invitation.data.invitation_code,name:'Paciente teste',email:'recipes-patient@example.test',phone:'21911112222',password:'AppTeste@2026',password_confirmation:'AppTeste@2026'});assert.equal(patient.status,200);
const list=await api('/api/v1/patient/recipes',null,patient.data.token);assert.equal(list.status,200);assert.equal(list.data.data.length,1);assert.equal(Number(list.data.data[0].recipe_id),recipe.id);assert.equal(list.data.data[0].instructions_override,'Consumir após o treino.');assert.equal(Number(list.data.data[0].prescribed_servings),1.5);
const details=await api('/api/v1/patient/recipes/'+recipe.id,null,patient.data.token);assert.equal(details.status,200);assert.equal(details.data.data.ingredients.length,3);assert.ok(details.data.data.instructions);
// Exercise the same explicit prescription route used by the app, including immutable history.
const prescriptionId=Number(list.data.data[0].id);
const historicalPath='/api/v1/patient/recipes/'+recipe.id+'?prescription_id='+prescriptionId;
const historical=await api(historicalPath,null,patient.data.token);
assert.equal(historical.status,200);assert.equal(historical.data.data.ingredients.length,3);
assert.equal((await api(historicalPath,null,'expired-token')).status,401);
assert.equal((await api('/api/v1/patient/recipes/'+recipe.id+'?prescription_id=999999',null,patient.data.token)).status,404);
sql('UPDATE recipe_versions SET payload_json=\'{}\' WHERE recipe_id='+recipe.id);
const unavailable=await api(historicalPath,null,patient.data.token);
assert.equal(unavailable.status,404);assert.equal(unavailable.data.code,'prescription_content_unavailable');
console.log('PASS explicit prescription: full historical content, expired session, invalid prescription and incomplete snapshot refused');
assert.equal((await api('/api/v1/patient/recipes/'+examples[1].id,null,patient.data.token)).status,404);
sql('UPDATE recipe_prescriptions SET patient_id=2 WHERE recipe_id='+recipe.id);
assert.equal((await api('/api/v1/patient/recipes/'+recipe.id,null,patient.data.token)).status,404);
assert.equal((await api('/api/v1/patient/recipes',null,patient.data.token)).data.data.length,0);
console.log('PASS patient recipes: professional prescription, patient onboarding, list, portions, instructions, ingredients and patient access isolation.');
})().catch(e=>{console.error(e);process.exitCode=1;}).finally(()=>{server?.kill();rmSync(dir,{recursive:true,force:true});});
