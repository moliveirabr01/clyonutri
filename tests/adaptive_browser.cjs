// Real browser + real isolated database. No request interception or simulated app responses.
const { chromium } = require(process.env.PLAYWRIGHT_MODULE || 'playwright');
const fs = require('fs');
const path = require('path');
const base = process.env.ADAPTIVE_BASE_URL || 'http://127.0.0.1:8767';
const fixture = JSON.parse(fs.readFileSync(process.env.ADAPTIVE_FIXTURE_FILE));
const out = process.env.ADAPTIVE_EVIDENCE_DIR || '/tmp/clyonutri-adaptive-evidence';
fs.mkdirSync(out,{recursive:true});
let browser;
(async () => {
 browser = await chromium.launch({executablePath:process.env.CHROME_PATH,headless:true});
 const results=[], errors=[];
 function check(name,ok) { results.push({test:name,pass:!!ok}); if(!ok)throw new Error(name); }
 async function context(viewport={width:1280,height:900}) {
  const c=await browser.newContext({viewport});
  c.on('page',p=>{p.on('pageerror',e=>errors.push(e.message));p.on('console',m=>{if(m.type()==='error' && /Content Security Policy|Refused to/.test(m.text()))errors.push(m.text())})});return c;
 }
 async function login(c,email) {
  const p=await c.newPage();await p.goto(base+'/login');await p.locator('#email').fill(email);await p.locator('#password').fill('Synthetic-local-651!');await p.locator('button[type=submit]').click();await p.waitForLoadState();check('login profissional '+(email===fixture.email?'A':'B'),!p.url().includes('/login'));return p;
 }
 const a=await context(), p=await login(a,fixture.email);
 let response=await p.goto(base+'/configuracoes/protocolos/editor');check('editor autenticado',response.status()===200 && await p.locator('[data-protocol-editor]').count()===1);
 await p.locator('[name=title]').fill('TESTE Navegador');await p.locator('[name=scope]').fill('Teste técnico de questionário sem conteúdo clínico.');await p.locator('[name=editor]').fill('TESTE Editor');await p.locator('[name=references]').fill('Referência sintética TESTE');
 await p.locator('[name="questions[0][text]"]').fill('Como descreve sua rotina?');
 await p.locator('[data-add-question]').click();await p.locator('[name="questions[1][text]"]').fill('Quantas vezes?');await p.locator('[name="questions[1][type]"]').selectOption('number');
 const second=p.locator('[data-editor-question]').nth(1);await second.locator('details').nth(1).locator('summary').click();await second.locator('[name$="[min]"]').fill('1');await second.locator('[name$="[max]"]').fill('5');
 await p.locator('[name=action][value=preview]').click();await p.waitForLoadState();check('prévia usa validação do servidor',await p.locator('[data-testid=protocol-preview]').count()===1);
 await p.locator('[data-testid=protocol-answer-preview] input').nth(1).fill('999');await p.locator('[name=action][value=preview]').click();await p.waitForLoadState();check('prévia rejeita limite inválido',(await p.locator('body').innerText()).includes('Valor fora do intervalo'));
 await p.locator('[name=action][value=save]').click();await p.waitForLoadState();check('rascunho salvo pela interface',(await p.locator('body').innerText()).includes('Rascunho salvo.'));
 await p.screenshot({path:path.join(out,'editor-desktop.png'),fullPage:true});
 await p.setViewportSize({width:375,height:812});await p.goto(base+'/configuracoes/protocolos/editor');check('editor sem rolagem horizontal em 375px',await p.evaluate(()=>document.documentElement.scrollWidth<=innerWidth));
 await p.screenshot({path:path.join(out,'editor-mobile.png'),fullPage:true});
 await p.setViewportSize({width:1280,height:900});
 const b=await context(), pb=await login(b,fixture.email_b);await pb.goto(base+'/configuracoes/protocolos/editor?version_id='+fixture.private_version);check('B não lê modelo privado A',(await pb.locator('body').innerText()).includes('Protocolo não encontrado.'));
 await pb.goto(base+'/pacientes/'+fixture.patient+'/anamnese');check('B não lê respostas clínicas A',await pb.locator('[data-testid=transversal-summary]').count()===0);
 const badCsrf=await a.request.post(base+'/configuracoes/protocolos/editor',{form:{action:'save',title:'TESTE CSRF'}});check('mutações exigem CSRF',badCsrf.status()>=400);
 await p.goto(base+'/pacientes/'+fixture.patient+'/pre-consulta');let csrf=await p.locator('[name=_csrf_token]').first().inputValue();
 const proposal=await a.request.post(base+'/pacientes/'+fixture.patient+'/pre-consulta',{form:{_csrf_token:csrf,mode:'blank'}});check('convite criado sem enviar mensagem',proposal.status()===200);
 const target=proposal.url();await p.goto(target);await p.locator('input[name=channel][value=link]').check();await p.locator('form[action$="/enviar"] button[type=submit]').click();await p.waitForLoadState();const link=await p.locator('#pc-link-input').inputValue();
 const patient=await context({width:375,height:812}), pp=await patient.newPage();await pp.goto(link);check('paciente confirma identidade',await pp.locator('[name=birth_date]').count()===1);await pp.locator('[name=birth_date]').fill('1990-01-01');await pp.getByRole('button',{name:'Continuar',exact:true}).click();await pp.waitForLoadState();
 check('perguntas exclusivas do profissional ausentes',!(await pp.locator('body').innerText()).includes('Revisão exclusiva profissional'));
 check('etapas acessíveis',await pp.locator('[data-pc-navigation]').isVisible());
 check('questionário sem rolagem horizontal em 375px',await pp.evaluate(()=>document.documentElement.scrollWidth<=innerWidth));
 await pp.screenshot({path:path.join(out,'preconsulta-mobile.png'),fullPage:true});
 await pp.locator('input[name=consent]').check();
 let steps=0;
 do {
   const visible=pp.locator('[data-pc-step]:visible');
   const texts=visible.locator('input[type=text]:visible,textarea:visible');for(let i=0;i<await texts.count();i++)await texts.nth(i).fill(await texts.nth(i).getAttribute('inputmode') === 'decimal' ? '3' : 'TESTE relato opcional');
   const radios=visible.locator('input[type=radio][value=nao]:visible');for(let i=0;i<await radios.count();i++)await radios.nth(i).check();
   const none=visible.locator('input[type=checkbox][value=nenhuma]:visible');for(let i=0;i<await none.count();i++)await none.nth(i).check();
   if(await pp.locator('[data-pc-next]').isDisabled())break;
   await pp.locator('[data-pc-next]').click();steps++;
 }while(steps<20);
 await pp.locator('[name=action][value=draft]').click();await pp.waitForLoadState();check('rascunho salvo e retomado',(await pp.locator('body').innerText()).includes('Respostas salvas.'));
 const duplicate=await context(), dup=await duplicate.newPage();await dup.goto(link);check('mesmo convite não abre em outro navegador',(await dup.locator('body').innerText()).includes('já foi utilizado'));
 await pp.goto(link);check('mesmo navegador retoma pelo convite',await pp.locator('[data-pc-form]').count()===1);
 await pp.reload();check('retomada continua autenticada',await pp.locator('[data-pc-form]').count()===1);
 await pp.locator('input[name=consent]').check();await pp.locator('[name=action][value=submit]').click();await pp.waitForLoadState();if(await pp.locator('[data-pc-form]').count()) console.log((await pp.locator('body').innerText()).slice(0,1800));
 check('envio final pelo paciente',await pp.locator('[data-pc-form]').count()===0 && (await pp.locator('body').innerText()).includes('enviad'));
 await p.goto(base+'/pacientes/'+fixture.patient+'/anamnese');check('resumo integrado à anamnese',await p.locator('[data-testid=transversal-summary]').count()===1);
 await p.locator('[data-testid=transversal-summary] summary').click();await p.screenshot({path:path.join(out,'resumo-desktop.png'),fullPage:true});
 for(const suffix of ['', '/prontuario','/exames']){
  await p.goto(base+'/pacientes/'+fixture.patient+suffix);check('resumo integrado '+(suffix||'perfil'),await p.locator('[data-testid=transversal-summary]').count()===1);
 }
 for(const suffix of ['/planos/'+fixture.plan, '/planos/'+fixture.plan+'/lista-compras','/consulta/'+fixture.appointment]) {
  await p.goto(base+suffix);check('resumo integrado '+suffix.replace(/\d+/g, ':id'),await p.locator('[data-testid=transversal-summary]').count()===1);
 }
 await p.goto(base+'/configuracoes/protocolos/editor');await p.locator('body').evaluate(el=>el.style.zoom='200%');check('editor a 200% sem rolagem horizontal',await p.evaluate(()=>document.documentElement.scrollWidth<=innerWidth));
 await p.keyboard.press('Tab');check('navegação por teclado tem foco',await p.evaluate(()=>document.activeElement!==document.body));
 if(errors.length)console.error(JSON.stringify(errors));
 check('sem erros JS ou violações CSP',errors.length===0);
 fs.writeFileSync(path.join(out,'browser-results.json'),JSON.stringify({build:'v54.17',passed:results.length,failed:0,tests:results,errors},null,2));console.log(JSON.stringify({passed:results.length,errors}));
 await browser.close();
})().catch(async e=>{console.error(e);if(browser)await browser.close();process.exit(1)});
