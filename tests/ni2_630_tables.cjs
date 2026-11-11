const {chromium}=require(process.env.PLAYWRIGHT_MODULE||'playwright'),fs=require('fs'),assert=require('assert');
(async()=>{const f=JSON.parse(fs.readFileSync(process.env.ADAPTIVE_FIXTURE_FILE)),b=await chromium.launch({headless:true,executablePath:process.env.CHROME_PATH}),checks=[],results=[];
for(const [build,base] of (process.env.UX_CANDIDATE_ONLY==='1'?[['v54.32',process.env.UX_BASE_URL]]:[['v54.29',process.env.UX_BASELINE_URL],['v54.30',process.env.UX_BASE_URL]])){
 const p=await b.newPage({viewport:{width:375,height:900}});await p.goto(base+'/login');await p.locator('#email').fill(f.email);await p.locator('#password').fill('Synthetic-local-651!');await p.locator('button[type=submit]').click();await p.waitForLoadState();await p.goto(base+'/agenda?date='+f.date);
 const c=await p.context().newCDPSession(p),tree=await c.send('Accessibility.getFullAXTree'),headers=tree.nodes.filter(n=>!n.ignored&&n.role?.value==='columnheader').map(n=>n.name?.value);await c.detach();
 await p.goto(base+'/pacientes/'+f.patient+'/anamnese');const table=p.locator('table.an-table').first(),wrap=table.locator('..'),data=await table.evaluate(t=>({tableDisplay:getComputedStyle(t).display,tableTabIndex:t.tabIndex,wrapperTabIndex:t.parentElement.tabIndex,wrapperRole:t.parentElement.getAttribute('role'),wrapperLabel:t.parentElement.getAttribute('aria-label')}));
 let scrollLeft=null;if(build==='v54.30'||build==='v54.32'){await wrap.focus();await p.keyboard.press('ArrowRight');await p.waitForTimeout(150);scrollLeft=await wrap.evaluate(e=>e.scrollLeft);
  for(const [name,pass] of [['seis cabeçalhos na árvore acessível',headers.length===6],['semântica nativa da tabela de anamnese',data.tableDisplay==='table'],['região de rolagem rotulada e focável',data.wrapperTabIndex===0&&data.wrapperRole==='region'&&!!data.wrapperLabel],['teclado rola a comparação',scrollLeft>0]]){checks.push({name,pass});assert(pass,name);}
 }
 results.push({build,headers,...data,scrollLeft});await p.close();
}
await b.close();fs.writeFileSync(process.env.UX_OUTPUT_FILE,JSON.stringify({automated:true,issue:'NI2-630',checks,results,table_exception:'SC 1.4.10 two-dimensional data table; keyboard reachable scroll area, not a page-level exception'},null,2));console.log(JSON.stringify({passed:checks.length}));})().catch(e=>{console.error(e);process.exit(1)});
