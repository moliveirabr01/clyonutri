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
const read=q=>JSON.parse(execFileSync('php',['-r','$db=new PDO("sqlite:".getenv("DB_DATABASE"));echo json_encode($db->query($argv[1])->fetchAll(PDO::FETCH_ASSOC));',q],{env,encoding:'utf8'}));
const before=read('SELECT * FROM recipe_ingredients WHERE recipe_id='+recipe.id+' ORDER BY ordering,id');
const nutrition=read('SELECT energy_kcal_per_serving FROM recipes WHERE id='+recipe.id)[0].energy_kcal_per_serving;
for(let attempt=1;attempt<=2;attempt++){
 const edit=await web('/receitas/'+recipe.id+'/editar');assert.equal(edit.status,200);
 const token=edit.text.match(/name="csrf_token" value="([^"]+)"/)[1];
 const saved=await web('/receitas/'+recipe.id+'/editar',{csrf_token:token,title:'Receita editada '+attempt,summary:'Resumo atualizado',meal_type:'Café da manhã',difficulty:'fácil',servings:'2',serving_size_name:'porção',prep_time_minutes:'10',cook_time_minutes:'0',instructions:'Misture e sirva.'});
 assert.equal(saved.status,302,saved.text);assert.equal(saved.location,'/receitas/'+recipe.id+'?success=receita_atualizada');
 const page=await web('/receitas/'+recipe.id);assert.equal(page.status,200);assert.ok(page.text.includes('Receita editada '+attempt));assert.ok(page.text.includes('v'+(attempt+1)));assert.ok(!/Warning:|Fatal error:|SQLSTATE/.test(page.text));
 assert.deepEqual(read('SELECT * FROM recipe_ingredients WHERE recipe_id='+recipe.id+' ORDER BY ordering,id'),before);
 assert.equal(read('SELECT energy_kcal_per_serving FROM recipes WHERE id='+recipe.id)[0].energy_kcal_per_serving,nutrition);
}
console.log('PASS recipe edit HTTP: two saves, redirect, persisted title, new versions, preserved ingredients and nutrition.');
})().catch(e=>{console.error(e);process.exitCode=1;}).finally(()=>{server?.kill();rmSync(dir,{recursive:true,force:true});});
