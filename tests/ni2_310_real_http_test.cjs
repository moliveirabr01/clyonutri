const {spawn,execFileSync}=require('node:child_process');
const {mkdtempSync,writeFileSync,rmSync}=require('node:fs');
const {join,resolve}=require('node:path');const net=require('node:net');const assert=require('node:assert/strict');
const root=resolve(__dirname,'..'),dir=mkdtempSync('/tmp/ni2-310-http-'),out=join(root,'docs/validation/NI2-310/evidence');
const env={...process.env,DB_CONNECTION:'sqlite',DB_DATABASE:join(dir,'test.sqlite')};
const fixture=mode=>execFileSync('php',['tests/support/ni2_310_fixture.php',mode],{cwd:root,env,encoding:'utf8'});
const inspect=()=>JSON.parse(fixture('inspect'));const checks=[];let server,browser,log='';
function check(name,ok){assert.ok(ok,name);checks.push({name,status:'PASS'});console.log('PASS '+name);}
(async()=>{
 fixture('prepare');const sock=net.createServer();await new Promise(r=>sock.listen(0,'127.0.0.1',r));const port=sock.address().port;await new Promise(r=>sock.close(r));
 server=spawn('php',['-d','session.name=NI310TEST','-d','session.save_path='+dir,'-S','127.0.0.1:'+port,'-t','public'],{cwd:root,env});server.stdout.on('data',d=>log+=d);server.stderr.on('data',d=>log+=d);
 const base='http://127.0.0.1:'+port;for(let i=0;i<80;i++){try{if((await fetch(base+'/health')).ok)break;}catch{}await new Promise(r=>setTimeout(r,50));}
 let cookie='',loginCsrf='';async function req(path,options={}){const r=await fetch(base+path,{...options,redirect:'manual',headers:{...(cookie?{Cookie:cookie}:{}),...options.headers}});const c=r.headers.get('set-cookie');if(c)cookie=c.split(';')[0];return {status:r.status,location:r.headers.get('location'),text:await r.text()};}
 const token=html=>html.match(/name="_csrf_token" value="([^"]+)"/)[1];
 const revision=html=>html.match(/name="revision" value="([^"]+)"/)[1];
 const post=(path,data)=>req(path,{method:'POST',body:new URLSearchParams(data)});
 async function login(n){await webLogout(req);const p=await req('/login');loginCsrf=token(p.text);return post('/login',{_csrf_token:token(p.text),email:`plan${n}@example.test`,password:'PlanTeste@2026'});}
 check('Unauthenticated route denied',(await post('/planos/1/itens/1/editar',{})).status===403);
 check('Real professional login',(await login(1)).status===302);
 const empty=await req('/planos/3');check('Empty draft is explicit and has no item editor',empty.status===200&&empty.text.includes('Nenhuma refeição')&&!empty.text.includes('data-testid="edit-plan-item'));
 let page=await req('/planos/1');check('Draft renders contextual editor and current values',page.status===200&&page.text.includes('edit-plan-item-1')&&page.text.includes('Orientação inicial'));
 const csrf=token(page.text),rev=revision(page.text),before=inspect();
 const data={_csrf_token:csrf,revision:rev,amount:'150.5',measure_unit:'g',notes:'Orientação revisada'};
 check('CSRF missing blocked',(await post('/planos/1/itens/1/editar',{...data,_csrf_token:''})).status===403);
 check('Cross-plan item rejected',(await post('/planos/2/itens/1/editar',data)).status===302&&JSON.stringify(inspect())===JSON.stringify(before));
 check('Other tenant item rejected',(await post('/planos/2/itens/2/editar',data)).status===302&&JSON.stringify(inspect())===JSON.stringify(before));
 check('Edit routed through real HTTP',(await post('/planos/1/itens/1/editar',data)).status===302);
 let after=inspect();check('Identity, context and substitutions preserved',after.items[0].id===before.items[0].id&&after.items[0].meal_id===before.items[0].meal_id&&after.items[0].food_id===before.items[0].food_id&&JSON.stringify(after.subs)===JSON.stringify(before.subs));
 check('Portion, note and totals persisted',Number(after.items[0].amount)===150.5&&after.items[0].notes===data.notes&&Number(after.items[0].energy_kcal)===192.64&&Number(after.plans[0].daily_energy_kcal)===192.64);
 check('Stale second tab rejected',(await post('/planos/1/itens/1/editar',{...data,amount:'300'})).status===302&&JSON.stringify(inspect())===JSON.stringify(after));
 page=await req('/planos/1');check('Conflict has explicit feedback',page.text.includes('outra aba'));
 const current={...data,revision:revision(page.text)};
 for(const amount of ['0','-1','abc','1e999']){await post('/planos/1/itens/1/editar',{...current,amount});check('Invalid amount rejected '+amount,JSON.stringify(inspect())===JSON.stringify(after));}
 await post('/planos/1/itens/1/editar',{...current,measure_unit:'invalid-unit'});check('Invalid unit rejected without writes',JSON.stringify(inspect())===JSON.stringify(after));
 fixture('break');await post('/planos/1/itens/1/editar',{...current,amount:'200'});check('Totals failure rolls back item and totals',JSON.stringify(inspect())===JSON.stringify(after));page=await req('/planos/1');check('Persistence error differs from empty and hides database details',page.text.includes('Nenhuma alteração foi gravada')&&!page.text.includes('synthetic persistence failure'));fixture('restore');
 await post('/planos/1/itens/1/editar',{...current,amount:'2',measure_unit:'colher de sopa',notes:''});after=inspect();check('Unit conversion and clearing note persisted',Number(after.items[0].energy_kcal)===38.4&&after.items[0].measure_unit==='colher de sopa'&&after.items[0].notes==='');
 page=await req('/planos/1');const publish=await post('/planos/1/publicar',{_csrf_token:token(page.text)});let published=inspect();check('Real publication preserves snapshot',publish.status===302&&published.plans[0].status==='published'&&published.versions.length===1);
 await post('/planos/1/itens/1/editar',{...current,amount:'300'});check('Published mutation refused without rewriting snapshot',JSON.stringify(inspect())===JSON.stringify(published));
 page=await req('/planos/1');check('Published editor offers new version and hides direct item editing',page.text.includes('Criar nova versão para editar')&&!page.text.includes('edit-plan-item-1'));
 const duplicate=await post('/planos/1/duplicar',{_csrf_token:token(page.text)});const newId=Number(duplicate.location.split('/').pop());page=await req('/planos/'+newId);let cloned=inspect();const clonedItem=cloned.items.find(i=>i.id!==1&&i.id!==2);
 check('New draft links original and preserves substitutions',cloned.plans.find(p=>Number(p.id)===newId).parent_plan_id===1&&cloned.subs.some(s=>s.item_id===clonedItem.id&&s.notes==='Alternativa preservada'));
 await post(`/planos/${newId}/itens/${clonedItem.id}/editar`,{_csrf_token:token(page.text),revision:revision(page.text),amount:'80',measure_unit:'g',notes:'Nova versão'});
 cloned=inspect();check('Editing new version leaves published history unchanged',Number(cloned.items.find(i=>i.id===clonedItem.id).amount)===80&&JSON.stringify(cloned.versions)===JSON.stringify(published.versions)&&JSON.stringify(cloned.items[0])===JSON.stringify(published.items[0]));
 await login(3);check('Unauthorized role denied without writes',(await post(`/planos/${newId}/itens/${clonedItem.id}/editar`,{...data,_csrf_token:loginCsrf})).status===403&&JSON.stringify(inspect())===JSON.stringify(cloned));
 await login(2);const other=await req('/planos/2');check('Other authorized clinic can edit own item',other.text.includes('edit-plan-item-2'));await post('/planos/2/itens/2/editar',{_csrf_token:token(other.text),revision:revision(other.text),amount:'120',measure_unit:'g',notes:'Clínica 2'});check('Other clinic edit persists',Number(inspect().items[1].amount)===120);
 const {chromium}=require('/tmp/cfit276-browser/node_modules/playwright');browser=await chromium.launch({headless:true,channel:'chrome'});const context=await browser.newContext({viewport:{width:1440,height:1000}});const ui=await context.newPage();await ui.goto(base+'/login');await ui.locator('input[name=email]').fill('plan1@example.test');await ui.locator('input[name=password]').fill('PlanTeste@2026');await Promise.all([ui.waitForURL('**/dashboard'),ui.locator('button[type=submit]').click()]);await ui.goto(base+'/planos/'+newId);await ui.locator('[data-testid^=edit-plan-item] summary').click();await ui.screenshot({path:join(out,'editor-1440.png'),fullPage:true});
 const editor=ui.locator('[data-testid^=edit-plan-item]');await editor.locator('input[name=amount]').fill('90.5');await editor.locator('textarea[name=notes]').fill('Editado pela interface');await Promise.all([ui.waitForNavigation(),editor.getByRole('button',{name:'Salvar alterações'}).click()]);check('Browser form submission persists and shows success',Number(inspect().items.find(i=>i.id===clonedItem.id).amount)===90.5&&await ui.getByText('Item atualizado e totais nutricionais recalculados.',{exact:false}).isVisible());await ui.screenshot({path:join(out,'success-1440.png'),fullPage:true});
 await ui.setViewportSize({width:390,height:844});await ui.locator('[data-testid^=edit-plan-item] summary').click();await ui.screenshot({path:join(out,'editor-390.png'),fullPage:true});check('Mobile edit form fits viewport',await editor.evaluate(el=>el.getBoundingClientRect().right<=window.innerWidth));
 writeFileSync(join(out,'persistence.json'),JSON.stringify({before,after:inspect(),published},null,2));
})().catch(e=>{checks.push({name:e.message,status:'FAIL'});console.error(e);process.exitCode=1;}).finally(async()=>{if(browser)await browser.close();if(server)server.kill();writeFileSync(join(out,'http-results.json'),JSON.stringify({finding:'PX-17',issue:'NI2-310',transport:'real HTTP and browser against production public/index.php, isolated persisted SQLite',checks},null,2));writeFileSync(join(out,'http-server.log'),log);rmSync(dir,{recursive:true,force:true});});

// Logout web exige POST + CSRF: lê o token do formulário de confirmação (GET /logout → 405) e envia o POST.
async function webLogout(req){const page=await req('/logout');const m=(page.text||'').match(/name="_csrf_token" value="([^"]+)"/);if(m)await req('/logout',{method:'POST',body:new URLSearchParams({_csrf_token:m[1]})});}
