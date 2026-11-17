// Requires isolated ni2_95_fixture database and Expo pointing at its API. No API mocks.
const {chromium}=require(process.env.PLAYWRIGHT_MODULE || 'playwright');
const assert=require('node:assert/strict');
const {execFileSync}=require('node:child_process');
const evidence=process.env.NI2_EVIDENCE_DIR || 'docs/validation/NI2-837/evidence';
const db=process.env.DB_DATABASE;
if(!db?.startsWith('/tmp/ni2-95-')) throw Error('Isolated database required');
const sql=q=>JSON.parse(execFileSync('php',['-r','echo json_encode((new PDO("sqlite:".getenv("DB_DATABASE")))->query($argv[1])->fetchAll(PDO::FETCH_ASSOC));',q],{encoding:'utf8'}));
(async()=>{
const browser=await chromium.launch({headless:true,channel:'chrome'});
try {
const p=await browser.newPage();p.setDefaultTimeout(15000);
await p.goto(process.env.UX_APP_URL || 'http://127.0.0.1:8099');
await p.getByLabel('E-mail do paciente',{exact:true}).fill('ana95@example.test');
await p.getByLabel('Senha do paciente',{exact:true}).fill('PacienteTeste@2026');
await p.getByRole('button',{name:'Entrar',exact:true}).click();
const card=p.getByTestId('daily-checkin-card');await card.waitFor();
await card.getByRole('textbox',{name:/Observação para o nutricionista/}).fill('Observação sintética preservada');
await card.getByRole('button',{name:'Enviar check-in',exact:true}).click();
await card.getByRole('alert').waitFor();
assert.match(await card.getByRole('alert').innerText(),/Escolha quanto/);
assert.match(await p.evaluate(()=>document.activeElement.getAttribute('aria-label')),/Quase nada/);
assert.equal(sql('SELECT count(*) n FROM checkins WHERE patient_id=1')[0].n,0);
assert.equal(await card.getByRole('textbox',{name:/Observação para o nutricionista/}).inputValue(),'Observação sintética preservada');
console.log('PASS empty check-in: adjacent alert, focus, optional text retained, zero database writes');
await card.getByRole('radio',{name:'Metade do plano',exact:true}).click();
assert.equal(await card.getByRole('alert').count(),0);
await p.route('**/api/v1/patient/checkin',r=>r.abort());
await card.getByRole('button',{name:'Enviar check-in',exact:true}).click();await card.getByRole('alert').waitFor();
assert.equal(sql('SELECT count(*) n FROM checkins WHERE patient_id=1')[0].n,0);
await p.unroute('**/api/v1/patient/checkin');
await card.getByRole('button',{name:'Enviar check-in',exact:true}).click();await card.getByText('Check-in de hoje enviado.',{exact:true}).waitFor();
assert.equal(sql('SELECT count(*) n FROM checkins WHERE patient_id=1')[0].n,1);
await p.reload();await p.getByTestId('daily-checkin-card').getByText('Observação: Observação sintética preservada',{exact:true}).waitFor();
console.log('PASS check-in network failure and retry: persisted exactly once, survives reload');
for(const width of [1280,390,360]) {
await p.setViewportSize({width,height:844});
for(const name of ['Plano','Diário','Chat','Evolução','Perfil','Hoje']) {
await p.getByRole('tab',{name,exact:true}).click();
await p.waitForTimeout(120);
assert.equal(await p.evaluate(()=>document.activeElement.textContent),name);
assert.equal(await p.evaluate(()=>{const n=document.activeElement;const a=[];for(let x=n.parentElement;x;x=x.parentElement)if(x.scrollHeight>x.clientHeight&&getComputedStyle(x).overflowY==='auto')a.push(x.scrollTop);return a.every(y=>y===0)}),true);
assert.equal(await p.evaluate(()=>document.documentElement.scrollWidth<=innerWidth),true);
}
await p.getByRole('tab',{name:'Perfil',exact:true}).click();
await p.screenshot({path:`${evidence}/profile-${width}.png`,fullPage:true});
const logout=p.getByRole('button',{name:'Encerrar sessão',exact:true});
assert.ok((await logout.boundingBox()).height>=44);
console.log(`PASS ${width}px: six sections focus/start, no horizontal overflow, logout target >=44px`);
}
await p.getByLabel('Ligar lembrete de check-in',{exact:true}).click();
await p.getByText('Sem canal externo habilitado:',{exact:false}).waitFor();
await p.getByRole('button',{name:'Habilitar e-mail de check-in',exact:true}).click();
await p.getByText('Canal habilitado: e-mail.',{exact:true}).waitFor();
await p.reload();await p.getByRole('tab',{name:'Perfil',exact:true}).click();
await p.getByText('Canal habilitado: e-mail.',{exact:true}).waitFor();
console.log('PASS reminder: no-channel warning, explicit enable, server persistence after reload');
await p.getByRole('tab',{name:'Hoje',exact:true}).click();
await p.getByRole('button',{name:'Registrar consumo da refeição Almoço',exact:true}).click();
await p.getByRole('button',{name:'Confirmar consumo',exact:true}).click();
await p.getByRole('tab',{name:'Diário',exact:true}).click();
await p.getByText('Conforme o plano:',{exact:false}).waitFor();
await p.reload();await p.getByRole('tab',{name:'Diário',exact:true}).click();await p.getByText('Conforme o plano:',{exact:false}).waitFor();
assert.match(sql('SELECT description FROM food_diary_entries WHERE patient_id=1 ORDER BY id DESC LIMIT 1')[0].description,/Conforme o plano/);
console.log('PASS diary: actual plan description persisted and rendered after reload');
}finally{await browser.close()}
})().catch(e=>{console.error(e);process.exitCode=1});
