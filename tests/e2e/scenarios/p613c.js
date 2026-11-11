const { chromium } = require('playwright');
const res=[]; const ok=(c,l)=>{res.push(!!c);console.log((c?'  PASS  ':'  FAIL  ')+l);};
(async()=>{
  const B=process.argv[2]; const D=require('child_process').execSync(`php ${process.env.E2E_SQ} ${process.argv[3]} "SELECT substr(scheduled_at,1,10) FROM appointments ORDER BY id LIMIT 1"`).toString().trim(); const b=await chromium.launch(); const ctx=await b.newContext({viewport:{width:1280,height:900}}); const p=await ctx.newPage();
  const csp=[]; p.on('console',m=>{if(/Content Security Policy/i.test(m.text()))csp.push(m.text())});
  await p.goto(B+'/__teste_login?u=1&c=1&v=0&to=/agenda?date='+D+'');
  for (const w of [375,800,900,1024,1280,1440]) {
    await p.setViewportSize({width:w,height:900}); await p.goto(B+'/agenda?date='+D+'&view=day');
    const r=await p.evaluate(()=>{const t=document.querySelector('.agenda-day-table');const wrap=t.closest('.table-responsive')||t.parentElement;
      const vw=document.documentElement.clientWidth;
      const vis=(el)=>{if(!el)return null;const r=el.getBoundingClientRect();const wr=wrap.getBoundingClientRect();return r.width>0&&r.left>=wr.left-1&&r.right<=wr.right+1&&r.right<=vw+1;};
      const row=[...t.querySelectorAll('tbody tr')].find(tr=>tr.querySelector('button[type=submit]')&&tr.textContent.includes('Confirmar'));
      return {scroll: wrap.scrollWidth-wrap.clientWidth, pageScroll: document.documentElement.scrollWidth-vw,
        conf: vis(row&&[...row.querySelectorAll('button')].find(x=>x.textContent.trim()==='Confirmar')),
        ini: vis(row&&[...row.querySelectorAll('a')].find(x=>x.textContent.trim()==='Iniciar')),
        more: vis(row&&row.querySelector('[data-testid="agenda-more-actions"]')),
        mode: getComputedStyle(t.querySelector('thead')).display==='none'?'cartões':'tabela', rowH: Math.round(row.getBoundingClientRect().height)};});
    ok(r.scroll<=1 && r.pageScroll<=1 && r.conf && r.ini && r.more, w+'px ('+r.mode+', linha '+r.rowH+' px): Confirmar/Iniciar/Mais ações visíveis, rolagem lateral tabela='+r.scroll+' página='+r.pageScroll);
    await p.screenshot({path:process.env.E2E_OUT+'/p613-'+w+'.png'});
  }
  await p.setViewportSize({width:800,height:900}); await p.goto(B+'/agenda?date='+D+'&view=day');
  await p.locator('[data-testid="agenda-more-actions"]').first().scrollIntoViewIfNeeded(); await p.waitForTimeout(300);
  await p.click('[data-testid="agenda-more-actions"]'); await p.waitForTimeout(300);
  const m=await p.evaluate(()=>{const mm=document.querySelector('details[open] [data-more-actions-menu]');const r=mm.getBoundingClientRect();return {l:r.left,r:r.right,w:r.width,vw:innerWidth};});
  ok(m.l>=0 && m.r<=m.vw && m.w<400, '800px: menu "Mais ações" abre dentro da tela ('+Math.round(m.l)+'–'+Math.round(m.r)+')');
  ok(csp.length===0,'0 violações de CSP');
  await b.close(); const f=res.filter(x=>!x).length; console.log('\n'+(res.length-f)+' PASS, '+f+' FAIL'); process.exit(f?1:0);
})();
