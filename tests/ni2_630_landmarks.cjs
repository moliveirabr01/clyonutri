const {chromium}=require(process.env.PLAYWRIGHT_MODULE||'playwright'),fs=require('fs'),assert=require('assert');
(async()=>{const f=JSON.parse(fs.readFileSync(process.env.ADAPTIVE_FIXTURE_FILE)),base=process.env.UX_BASE_URL,b=await chromium.launch({headless:true,executablePath:process.env.CHROME_PATH}),p=await b.newPage(),checks=[];const check=(name,pass)=>{checks.push({name,pass:!!pass});assert(pass,name);};
await p.goto(base+'/login');await p.locator('#email').fill(f.email);await p.locator('#password').fill('Synthetic-local-651!');await p.locator('button[type=submit]').click();await p.waitForLoadState();
for(const width of [375,1280]){await p.setViewportSize({width,height:900});await p.goto(base+'/pacientes/'+f.patient);
 check('idioma declarado '+width,await p.locator('html').getAttribute('lang')==='pt-BR');
 check('landmark e título principais '+width,await p.locator('main').count()===1&&await p.locator('main h1').count()===1);
 await p.keyboard.press('Tab');check('primeiro Tab encontra salto '+width,await p.locator('.skip-link').evaluate(e=>e===document.activeElement));
 check('salto visível ao foco '+width,await p.locator('.skip-link').evaluate(e=>{const r=e.getBoundingClientRect();return r.top>=0&&r.right<=innerWidth;}));
 await p.keyboard.press('Enter');check('salto move foco para conteúdo '+width,await p.locator('#clinical-main-content').evaluate(e=>e===document.activeElement));
}
await b.close();fs.writeFileSync(process.env.UX_OUTPUT_FILE,JSON.stringify({issue:'NI2-630',checks,automated:true},null,2));console.log(JSON.stringify({passed:checks.length}));
})().catch(e=>{console.error(e);process.exit(1)});
