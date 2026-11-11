const {chromium}=require(process.env.PLAYWRIGHT_MODULE||'playwright'),fs=require('fs'),path=require('path'),assert=require('assert'),axe=require(process.env.AXE_MODULE||'axe-core');
(async()=>{
 const f=JSON.parse(fs.readFileSync(process.env.ADAPTIVE_FIXTURE_FILE)),base=process.env.UX_BASE_URL,checks=[],root=process.env.UX_APP_ROOT;assert(root&&root.endsWith('/release-v54-31') || process.env.UX_ISOLATED_TEST_RUN==='1','Only isolated candidate session files');assert(['127.0.0.1','localhost'].includes(new URL(base).hostname));
 const b=await chromium.launch({headless:true,executablePath:process.env.CHROME_PATH}),c=await b.newContext(),p=await c.newPage();p.on('dialog',d=>d.accept());const check=(name,pass)=>{checks.push({name,pass:!!pass});assert(pass,name);};
 await p.goto(base+'/login');await p.locator('#email').fill(f.email);await p.locator('#password').fill('Synthetic-local-651!');await p.locator('button[type=submit]').click();await p.waitForLoadState();await p.goto(base+'/planos/'+f.quick_plan);await p.locator('[data-show=modal-add-meal]:visible').first().click();const input=p.locator('#modal-add-meal [name=name]');await input.fill('TESTE sessão preservada');
 await p.setViewportSize({width:320,height:600});
 const state=await (await c.request.get(base+'/sessao/estado')).json(),cookie=(await c.cookies()).find(x=>x.name==='PHPSESSID');assert(cookie&&/^[a-zA-Z0-9,-]+$/.test(cookie.value));
 const filename=path.join(root,'storage/sessions','sess_'+cookie.value),original=fs.readFileSync(filename,'utf8');assert(/last_activity\|i:\d+;/.test(original));
 // Expire only the idle timestamp of this synthetic browser's session, leaving 120 real server seconds.
 fs.writeFileSync(filename,original.replace(/last_activity\|i:\d+;/,'last_activity|i:'+(Math.floor(Date.now()/1000)-state.ttl+120)+';'));
 await p.evaluate(()=>{window.ClyoSession._setDeadline(60000);return window.ClyoSession.check();});const warning=p.locator('[data-testid=session-warning]');await warning.waitFor({state:'visible'});await p.waitForTimeout(50);
 check('aviso mantém alertdialog e nome',await warning.getAttribute('role')==='alertdialog'&&await warning.getAttribute('aria-modal')==='true'&&!!await warning.getAttribute('aria-labelledby'));
 check('aviso cabe em 320px',await warning.evaluate(el=>{const r=el.getBoundingClientRect();return document.documentElement.scrollWidth<=innerWidth&&r.left>=0&&r.right<=innerWidth&&r.top>=0&&r.bottom<=innerHeight;}));
 check('foco vai ao controle de renovação',await warning.locator('button').evaluate(e=>e===document.activeElement));
 await p.keyboard.press('Tab');check('Tab permanece no aviso',await warning.locator('button').evaluate(e=>e===document.activeElement));
 await p.evaluate(axe.source);const a=await p.evaluate(()=>axe.run(document,{runOnly:{type:'tag',values:['wcag2a','wcag2aa','wcag21a','wcag21aa','wcag22aa']}}));check('aviso sem violações axe',a.violations.length===0);
 await p.keyboard.press('Enter');await warning.waitFor({state:'hidden'});await p.waitForTimeout(50);
 check('renovação restaura janela e edição',await p.locator('#modal-add-meal').isVisible()&&await input.inputValue()==='TESTE sessão preservada');
 check('renovação devolve foco ao campo',await input.evaluate(e=>e===document.activeElement));
 const after=await (await c.request.get(base+'/sessao/estado')).json();check('servidor confirmou renovação',after.authenticated&&after.remaining>600);
 check('contador não anuncia a cada segundo',await warning.locator('[data-session-left]').getAttribute('aria-live')==='off');
 await b.close();fs.writeFileSync(process.env.UX_OUTPUT_FILE,JSON.stringify({issue:'NI2-630',checks,server_session_test:true,synthetic_session_only:true,no_auth_policy_change:true},null,2));console.log(JSON.stringify({passed:checks.length}));
})().catch(e=>{console.error(e);process.exit(1)});
