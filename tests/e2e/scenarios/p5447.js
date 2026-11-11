// v54.47 UX Excellence: Z01 retomada pela barra (navegador real), V01 uma CTA primária, teclado/leitor, 375/768/1280, falha de rede.
const { chromium } = require('playwright');
const { execSync } = require('child_process');
const res=[]; const ok=(c,l)=>{res.push(!!c);console.log((c?'  PASS  ':'  FAIL  ')+l);};
(async()=>{
  const B=process.argv[2], DB=process.argv[3];
  const sq=q=>execSync(`php ${process.env.E2E_SQ} ${DB} "${q}"`).toString().trim();
  sq("UPDATE appointments SET status='in_progress', scheduled_at=datetime('now','localtime') WHERE id=1");
  const b=await chromium.launch(); const ctx=await b.newContext({viewport:{width:1280,height:900}}); const p=await ctx.newPage();
  const errs=[]; p.on('pageerror',e=>errs.push(e.message));
  await p.goto(B+'/__teste_login?u=1&c=1&v=0&to=/pacientes/2');
  console.log('== Jornada 2: interrompido → retomar etapa correta');
  const cta=p.locator('[data-testid="attendance-resume"]');
  ok(await cta.isVisible() && /Continuar anamnese/.test(await cta.textContent()), 'barra mostra "Próxima ação: Continuar anamnese"');
  await p.goto(B+'/pacientes/2/antropometria?atendimento=1');
  ok(await cta.isVisible(), 'em outra aba (avaliações) a retomada continua visível');
  await cta.click(); await p.waitForLoadState();
  const u=new URL(p.url()); ok(u.pathname==='/pacientes/2/anamnese' && u.searchParams.get('atendimento')==='1', 'clique abre a anamnese do mesmo paciente com ?atendimento=1');
  await p.goto(B+'/__teste_logout').catch(()=>{}); await ctx.clearCookies();
  await p.goto(B+'/__teste_login?u=1&c=1&v=0&to=/pacientes/2');
  ok(/Continuar anamnese/.test(await cta.textContent()), 'novo login: mesma pendência (estado vem do banco)');
  console.log('== V01 hierarquia / a11y');
  ok(await p.locator('[data-testid="attendance-mode"] .btn-primary').count()===1, 'uma única CTA primária na barra');
  ok(await p.locator('[data-testid="care-flow-next"] .op-button--primary').count()===0, 'bloco de etapas não compete com CTA primária');
  ok(await p.locator('[data-testid="attendance-mode"]').getAttribute('aria-label')==='Modo Atendimento' && await p.locator('[data-testid="attendance-steps"]').getAttribute('aria-label')==='Etapas do atendimento', 'regiões nomeadas para leitor de tela');
  const txt=await p.textContent('[data-testid="attendance-steps"]'); ok(/Anamnese: Em andamento/.test(txt) && /Plano alimentar: /.test(txt), 'estado escrito por extenso (não só cor) ['+txt.replace(/\s+/g,' ').trim().slice(0,120)+']');
  let reached=false; await p.focus('body'); for(let i=0;i<80&&!reached;i++){await p.keyboard.press('Tab'); reached=await p.evaluate(()=>document.activeElement?.dataset?.testid==='attendance-resume');}
  ok(reached, 'próxima ação alcançável por Tab');
  if(reached){ const fv=await p.evaluate(()=>{const s=getComputedStyle(document.activeElement);return s.outlineStyle!=='none'||s.boxShadow!=='none';}); ok(fv,'foco visível na CTA'); }
  for (const w of [375,768,1280]) { await p.setViewportSize({width:w,height:900}); await p.goto(B+'/pacientes/2');
    const r=await p.evaluate(()=>{const e=document.querySelector('[data-testid="attendance-resume"]').getBoundingClientRect();return {over:document.documentElement.scrollWidth>innerWidth+1,h:e.height,w:e.width};});
    ok(!r.over && r.h>=24 && r.w>=24, w+' px: sem rolagem lateral, alvo da CTA ≥ 24px ('+Math.round(r.w)+'×'+Math.round(r.h)+')');
    await p.screenshot({path:process.env.E2E_OUT+'/p5447-'+w+'.png'}); }
  console.log('== Jornada 5: falha de rede ao registrar decisão');
  await p.setViewportSize({width:1280,height:900}); await p.goto(B+'/pacientes/2');
  const before=sq("SELECT COUNT(*) FROM care_progress_events WHERE appointment_id=1");
  await p.route('**/consulta/1/progresso',r=>r.abort('internetdisconnected'));
  const li=p.locator('[data-testid="care-flow"] [data-step="avaliacao"]'); await li.locator('details summary').click();
  await li.locator('select[name="decision"]').selectOption('required');
  await li.locator('button[type="submit"]').click().catch(()=>{}); await p.waitForTimeout(800);
  ok(sq("SELECT COUNT(*) FROM care_progress_events WHERE appointment_id=1")===before && !/progress_saved=1/.test(p.url()), 'rede caiu: nada gravado e nenhuma confirmação exibida');
  await p.unroute('**/consulta/1/progresso'); await p.goto(B+'/pacientes/2');
  ok(/Continuar anamnese/.test(await cta.textContent()), 'rede voltou: interface reflete o banco (mesma pendência)');
  ok(errs.length===0,'sem erro de JS ('+errs.length+(errs[0]?': '+errs[0]:'')+')');
  await b.close();
  const n=res.filter(x=>!x).length; console.log(`\n${res.length-n} PASS, ${n} FAIL`); process.exit(n?1:0);
})();
