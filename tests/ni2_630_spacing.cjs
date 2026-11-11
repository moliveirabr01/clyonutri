const {chromium}=require(process.env.PLAYWRIGHT_MODULE||'playwright'),fs=require('fs'),assert=require('assert');
(async()=>{const f=JSON.parse(fs.readFileSync(process.env.ADAPTIVE_FIXTURE_FILE)),base=process.env.UX_BASE_URL,b=await chromium.launch({headless:true,executablePath:process.env.CHROME_PATH}),p=await b.newPage({viewport:{width:375,height:900}}),checks=[],states=[];
 await p.goto(base+'/login');await p.locator('#email').fill(f.email);await p.locator('#password').fill('Synthetic-local-651!');await p.locator('button[type=submit]').click();await p.waitForLoadState();
 for(const [name,route] of [['agenda','/agenda?date='+f.date],['consulta','/consulta/'+f.appointment],['plano','/planos/'+f.quick_plan],['exames','/pacientes/'+f.patient+'/exames/novo']]){
  await p.goto(base+route);await p.addStyleTag({content:'*{line-height:1.5!important;letter-spacing:.12em!important;word-spacing:.16em!important}p{margin-bottom:2em!important}'});
  const geometry=await p.evaluate(()=>({viewport:innerWidth,documentWidth:document.documentElement.scrollWidth,buttons:[...document.querySelectorAll('main button,main summary')].filter(e=>e.checkVisibility()).map(e=>{const r=e.getBoundingClientRect();return {width:r.width,height:r.height};})}));states.push({name,...geometry});const pass=geometry.documentWidth<=geometry.viewport&&geometry.buttons.every(x=>x.width>=24&&x.height>=24);checks.push({name,pass});assert(pass,name);
 }
 await b.close();fs.writeFileSync(process.env.UX_OUTPUT_FILE,JSON.stringify({issue:'NI2-630',automated_text_spacing_override:true,checks,states},null,2));console.log(JSON.stringify({passed:checks.length}));
})().catch(e=>{console.error(e);process.exit(1)});
