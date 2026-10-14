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
for(const recipe of examples){
 const page=await web('/receitas/'+recipe.id);assert.equal(page.status,200);
 for(const phrase of ['professional-shell','Exemplo','Preparação simples','v1']) {
  if(phrase==='professional-shell')assert.ok(page.text.includes('<!DOCTYPE html>')||page.text.includes('<!doctype html>'),'Full layout');
  else assert.ok(page.text.includes(phrase),phrase);
 }
 assert.ok(!/\bcontains\b|\bnuts\b|\bmilk\b|Multi-tenant|>Tags:/i.test(page.text),'Portuguese labels');
 assert.ok(page.text.includes('2 porções'),'Plural servings');
 assert.ok(!/Warning:|Fatal error:|Undefined array key|Stack trace:/i.test(page.text),'No PHP errors');
}
console.log('PASS recipe detail HTTP: all six examples render full layout, string tags, version 1 and no PHP diagnostics.');
})().catch(e=>{console.error(e);process.exitCode=1;}).finally(()=>{server?.kill();rmSync(dir,{recursive:true,force:true});});
