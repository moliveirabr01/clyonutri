const { chromium } = require('playwright');
const res=[]; const ok=(c,l)=>{res.push(!!c);console.log((c?'  PASS  ':'  FAIL  ')+l);};
(async()=>{
  const B=process.argv[2]; const D=require('child_process').execSync(`php ${process.env.E2E_SQ} ${process.argv[3]} "SELECT substr(scheduled_at,1,10) FROM appointments ORDER BY id LIMIT 1"`).toString().trim(); const b=await chromium.launch(); const ctx=await b.newContext({viewport:{width:1280,height:900}}); const p=await ctx.newPage();
  const csp=[]; p.on('console',m=>{if(/Content Security Policy/i.test(m.text()))csp.push(m.text())});
  await p.goto(B+'/__teste_login?u=1&c=1&v=0&to=/agenda?date='+D+'');
  for (const w of [800,1024,1280]) {
    await p.setViewportSize({width:w,height:900}); await p.goto(B+'/agenda?date='+D+'&view=day');
    const btns=p.locator('[data-testid="agenda-more-actions"]'); const n=await btns.count();
    for (const [idx,label] of [[0,'primeira'],[n-1,'última']]) {
      const s=btns.nth(idx); await s.scrollIntoViewIfNeeded(); await p.waitForTimeout(200); await s.click(); await p.waitForTimeout(200);
      const r=await p.evaluate(()=>{const det=document.querySelector('details[data-more-actions][open]'); if(!det) return null;
        const m=det.querySelector('[data-more-actions-menu]').getBoundingClientRect(); const bt=det.querySelector('summary').getBoundingClientRect();
        const side=document.querySelector('.app-sidebar'); const sr=side&&getComputedStyle(side).display!=='none'?side.getBoundingClientRect().right:0;
        const items=[...det.querySelectorAll('.agenda-menu-item')].map(it=>{const q=it.getBoundingClientRect();const e=document.elementFromPoint(q.left+q.width/2,q.top+q.height/2);return !!e&&(e===it||it.contains(e));});
        return {l:m.left,r:m.right,w:m.width,t:m.top,b:m.bottom,bl:bt.left,br:bt.right,bt:bt.top,bb:bt.bottom,sr,vw:innerWidth,vh:innerHeight,items};});
      if(!r){ok(false,w+'px '+label+' linha: menu não abriu');continue;}
      const near=(Math.abs(r.r-r.br)<=2||Math.abs(r.l-r.bl)<=2||(r.l>=r.bl-2&&r.r<=r.br+2)) && (Math.abs(r.t-r.bb-4)<=2||Math.abs(r.bt-4-r.b)<=2);
      ok(near && r.l>=r.sr && r.r<=r.vw && r.w<300 && r.b<=r.vh, w+'px, '+label+' linha: menu junto do botão ('+Math.round(r.l)+'–'+Math.round(r.r)+', '+Math.round(r.w)+' px), botão '+Math.round(r.bl)+'-'+Math.round(r.br)+' / '+Math.round(r.bt)+'-'+Math.round(r.bb)+', menu '+Math.round(r.t)+'-'+Math.round(r.b)+', lateral até '+Math.round(r.sr)+')');
      ok(r.items.length===3 && r.items.every(Boolean), w+'px, '+label+' linha: Reagendar, Não compareceu e Cancelar recebem clique');
      await p.keyboard.press('Escape');
    }
  }
  // clique real em Reagendar na última linha (1024 px)
  await p.setViewportSize({width:1024,height:900}); await p.goto(B+'/agenda?date='+D+'&view=day');
  const last=p.locator('[data-testid="agenda-more-actions"]').last(); await last.scrollIntoViewIfNeeded(); await p.waitForTimeout(200); await last.click(); await p.waitForTimeout(200);
  await Promise.all([p.waitForNavigation(), p.locator('details[open] [data-testid="agenda-reschedule"]').click()]);
  ok(/\/agenda\/\d+\/reagendar/.test(p.url()), 'clique real em Reagendar (última linha, 1024 px) abre '+new URL(p.url()).pathname);
  ok(csp.length===0,'0 violações de CSP');
  await b.close(); const f=res.filter(x=>!x).length; console.log('\n'+(res.length-f)+' PASS, '+f+' FAIL'); process.exit(f?1:0);
})();
