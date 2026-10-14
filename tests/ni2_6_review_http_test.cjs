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
const sqlValue=q=>execFileSync('php',['-r','$db=new PDO("sqlite:".getenv("DB_DATABASE"));echo $db->query($argv[1])->fetchColumn();',q],{env,encoding:'utf8'});
let form=await web('/pacientes/1/anamnese');assert.equal(form.status,200);const csrf=csrfOf(form);
const long='🍎'.repeat(5001);
let bad=await web('/pacientes/1/anamnese',{_csrf_token:csrf,allergies:long,allergy_assessment:'recorded',save_mode:'draft'});assert.equal(bad.status,302);assert.ok(bad.location.includes('dados_invalidos'));assert.equal(sqlValue('SELECT COUNT(*) FROM anamneses'), '0');
form=await web(bad.location);const recovery=JSON.parse(form.text.match(/id="clinical-form-recovery">([\s\S]*?)<\/script>/)[1]);assert.equal(recovery.values.allergies,long);
sql("CREATE TRIGGER audit_failure BEFORE INSERT ON audit_logs WHEN NEW.action='ANAMNESIS_RECORD_CREATED' BEGIN SELECT RAISE(ABORT,'synthetic failure'); END");
bad=await web('/pacientes/1/anamnese',{_csrf_token:csrf,objectives:'Synthetic goal',save_mode:'final'});assert.equal(bad.status,503);assert.equal(sqlValue('SELECT COUNT(*) FROM anamneses'),'0');sql('DROP TRIGGER audit_failure');
const success=await web('/pacientes/1/anamnese',{_csrf_token:csrf,objectives:'Synthetic goal',save_mode:'final'});assert.equal(success.status,302);
form=await web('/pacientes/1/prontuario');assert.equal(form.status,200);const noteCsrf=csrfOf(form);
assert.equal((await web('/pacientes/1/prontuario',{_csrf_token:noteCsrf,title:'Synthetic title',content_text:'Synthetic note'})).status,302);
const record=sqlValue('SELECT id FROM clinical_records ORDER BY id DESC LIMIT 1');
for(const text of ['', 'é'.repeat(10001)]) {const invalid=await web('/pacientes/1/prontuario/'+record+'/retificar',{_csrf_token:noteCsrf,revision_reason:'Synthetic correction reason',revised_text:text});assert.equal(invalid.status,302);assert.ok(invalid.location.includes('error='));}
assert.equal(sqlValue('SELECT is_retracted FROM clinical_records WHERE id='+record),'0');assert.equal(sqlValue('SELECT COUNT(*) FROM clinical_records'),'1');
assert.equal((await web('/pacientes/1/prontuario/'+record+'/retificar',{_csrf_token:noteCsrf,revision_reason:'Synthetic correction reason',revised_text:'Valid revised note'})).status,302);
sql("UPDATE clinical_records SET title='Tampered title' WHERE version=2");const exported=await web('/pacientes/1/prontuario/exportar');assert.equal(exported.status,400);assert.ok(JSON.parse(exported.text).error.includes('Exportação bloqueada'));
console.log('PASS NI2-6 HTTP: oversized Unicode rejected and recovered, audit failure rollback, valid save, invalid revision no mutation and tampered export blocked.');
})().catch(e=>{console.error(e);process.exitCode=1;}).finally(()=>{server?.kill();rmSync(dir,{recursive:true,force:true});});
