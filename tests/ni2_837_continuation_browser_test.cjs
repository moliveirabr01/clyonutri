// Real Expo web + PHP API using ni2_837_continuation_fixture; no API response mocks.
const {chromium}=require(process.env.PLAYWRIGHT_MODULE || 'playwright');
const assert=require('node:assert/strict');const fs=require('node:fs');const {execFileSync}=require('node:child_process');
const dir=process.env.NI2_EVIDENCE_DIR || 'docs/validation/NI2-837/continuation/evidence';fs.mkdirSync(dir,{recursive:true});
if(!process.env.DB_DATABASE?.startsWith('/tmp/ni2-95-'))throw Error('Isolated database required');
const sql=q=>JSON.parse(execFileSync('php',['-r','echo json_encode((new PDO("sqlite:".getenv("DB_DATABASE")))->query($argv[1])->fetchAll(PDO::FETCH_ASSOC));',q],{encoding:'utf8'}));
const out={checks:[],axe:{}};const check=(name,ok,detail)=>{out.checks.push({name,ok,detail});console.log(`${ok?'PASS':'FAIL'} ${name}${detail?' '+JSON.stringify(detail):''}`);};
const tabs=['Hoje','Plano','Diário','Chat','Evolução','Perfil'];
(async()=>{const b=await chromium.launch({headless:true,channel:'chrome'});try{
const p=await b.newPage({viewport:{width:390,height:844}});p.setDefaultTimeout(12000);
await p.goto(process.env.UX_APP_URL || 'http://127.0.0.1:8099');await p.getByLabel('E-mail do paciente',{exact:true}).fill('ana95@example.test');await p.getByLabel('Senha do paciente',{exact:true}).fill('PacienteTeste@2026');await p.getByRole('button',{name:'Entrar',exact:true}).click();await p.getByTestId('daily-checkin-card').waitFor();
const tab=async name=>{await p.getByRole('tab',{name,exact:true}).click();await p.waitForTimeout(120);};
const register=()=>p.getByRole('button',{name:'Registrar consumo da refeição Almoço',exact:true});
await register().focus();await p.keyboard.press('Enter');await p.getByRole('radio',{name:'Comi diferente',exact:true}).focus();await p.keyboard.press('Space');
const meal=p.getByRole('textbox',{name:'O que você comeu e quantidade aproximada',exact:true});await meal.fill('Rascunho sintético banana 80 g');
const countBefore=sql('SELECT COUNT(*) n FROM food_diary_entries')[0].n;
await p.getByRole('button',{name:'Cancelar',exact:true}).focus();await p.keyboard.press('Enter');
check('Cancelar por teclado devolve foco ao registro e não grava consumo',await register().evaluate(e=>e===document.activeElement)&&sql('SELECT COUNT(*) n FROM food_diary_entries')[0].n===countBefore);
await tab('Plano');check('Sequência original Cancelar → Plano abre no início e foca título',await p.evaluate(()=>document.activeElement.textContent==='Plano'&&document.activeElement.getBoundingClientRect().top<200));
await tab('Hoje');await register().focus();await p.keyboard.press('Enter');await meal.waitFor();
check('Cancelar e reabrir preserva modo e texto do rascunho',await meal.inputValue()==='Rascunho sintético banana 80 g');
await tab('Diário');await tab('Hoje');check('Trocar de seção preserva formulário aberto',await meal.inputValue()==='Rascunho sintético banana 80 g');
await p.getByRole('button',{name:'Cancelar',exact:true}).click();
await tab('Plano');
const complete=p.getByText('Receita completa TESTE',{exact:true}).locator('..');
let release;const gate=new Promise(r=>release=r);await p.route('**/api/v1/patient/recipes/*',async r=>{await gate;await r.continue()});
await complete.getByRole('button',{name:'Ver ingredientes e preparo',exact:true}).click();
await p.getByLabel('Carregando receita',{exact:true}).waitFor();check('Receita mostra carregamento e impede abertura concorrente',await complete.getByRole('button').isDisabled());release();
await complete.getByText('Separar o arroz em duas porções.',{exact:false}).waitFor();await p.unroute('**/api/v1/patient/recipes/*');
check('Receita válida mostra ingredientes, quantidades e preparo reais',(await complete.innerText()).includes('Arroz TESTE')&&(await complete.innerText()).includes('50'));
await complete.getByRole('button',{name:'Ver ingredientes e preparo',exact:true}).click();await complete.getByText('Separar o arroz em duas porções.',{exact:false}).waitFor();check('Reabrir receita não duplica prescrição',sql('SELECT COUNT(*) n FROM recipe_prescriptions')[0].n===2);
await p.screenshot({path:dir+'/recipe-valid-390.png',fullPage:true});
const missing=p.getByText('Receita histórica incompleta TESTE',{exact:true}).locator('..');await missing.getByRole('button',{name:'Ver ingredientes e preparo',exact:true}).click();await missing.getByRole('alert').waitFor();
check('Snapshot incompleto mostra indisponibilidade contextual',/versão desta prescrição não tem conteúdo completo/.test(await missing.getByRole('alert').innerText()));
await missing.getByRole('button',{name:'Solicitar nova prescrição no chat',exact:true}).click();
check('Recuperação abre Chat sem enviar mensagem automaticamente',await p.getByRole('tab',{name:'Chat',exact:true}).getAttribute('aria-selected')==='true');
// Axe measures rendered text contrast and semantic rules; does not replace a screen-reader session.
for(const width of [1280,390,360]){
await p.setViewportSize({width,height:900});
for(const name of tabs){await tab(name);await p.addScriptTag({path:process.env.AXE_PATH});const a=await p.evaluate(async()=>{const r=await axe.run(document,{runOnly:['wcag2a','wcag2aa','wcag21a','wcag21aa']});return r.violations.map(v=>({id:v.id,impact:v.impact,nodes:v.nodes.map(n=>({target:n.target,summary:n.failureSummary}))}))});out.axe[width+':'+name]=a;
check(`Axe WCAG A/AA ${width}px ${name}`,a.length===0,a.length?a:undefined);
check(`Sem overflow horizontal ${width}px ${name}`,await p.evaluate(()=>document.documentElement.scrollWidth<=innerWidth));
await p.screenshot({path:`${dir}/section-${width}-${name}.png`,fullPage:true});
}
}
// Keyboard navigation into content, radios, and error correction.
await tab('Hoje');const card=p.getByTestId('daily-checkin-card');await card.getByRole('button',{name:'Enviar check-in',exact:true}).focus();await p.keyboard.press('Enter');await card.getByRole('alert').waitFor();
check('Erro obrigatório associado semanticamente ao grupo',await card.getByRole('radiogroup').first().getAttribute('aria-describedby')==='checkin-adherence-error');
await p.keyboard.press('Space');check('Resposta por teclado resolve erro',await card.getByRole('alert').count()===0);
// Controlled text enlargement: CSS zoom. Report separately from genuine browser UI zoom.
await p.setViewportSize({width:1280,height:900});await p.evaluate(()=>document.body.style.zoom='2');
for(const name of tabs){await tab(name);check(`Ampliação CSS 200%: seção ${name} alcançável`,await p.getByRole('tab',{name,exact:true}).getAttribute('aria-selected')==='true');}
await p.screenshot({path:dir+'/css-zoom-200-desktop.png',fullPage:true});await p.evaluate(()=>document.body.style.zoom='1');
// Verify professional unread badge and actual settings page in a separate cookie context.
const pro=await b.newPage({viewport:{width:1280,height:900}});await pro.goto('http://127.0.0.1:8098/login');await pro.locator('[name=email]').fill('nutri95a@example.test');await pro.locator('[name=password]').fill('Equipe@2026');await Promise.all([pro.waitForURL('**/dashboard'),pro.locator('button[type=submit]').click()]);await pro.goto('http://127.0.0.1:8098/avisos');
check('Menu profissional mostra 2 avisos reais não lidos',await pro.getByTestId('nav-notices-count').innerText()==='2');await pro.screenshot({path:dir+'/professional-notices-1280.png',fullPage:true});
await pro.goto('http://127.0.0.1:8098/configuracoes?tab=paciente-app&secao=notifications#task-notifications');await pro.locator('[data-testid=notification-settings]').waitFor({state:'visible'});check('Formulário de avisos acessível no destino atual',await pro.locator('[name=notify_chat_email]').isChecked());await pro.screenshot({path:dir+'/professional-settings-1280.png',fullPage:true});
}finally{await b.contexts()[0]?.pages()[0]?.screenshot({path:dir+'/last-state.png',fullPage:true}).catch(()=>{});await b.close();fs.writeFileSync(dir+'/browser-results.json',JSON.stringify(out,null,2));}
if(out.checks.some(c=>!c.ok))process.exitCode=1;
})().catch(e=>{console.error(e);process.exitCode=1});
