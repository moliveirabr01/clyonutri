// NI2-625: checklist de ativação conferido no banco (onboarding passo 5 e painel).
const { chromium } = require('playwright');
const res=[]; const ok=(c,l)=>{res.push(!!c);console.log((c?'  PASS  ':'  FAIL  ')+l);};
(async()=>{
  const B=process.argv[2];
  const b=await chromium.launch(); const ctx=await b.newContext({viewport:{width:1280,height:900}}); const p=await ctx.newPage();
  const csp=[]; const errs=[]; p.on('console',m=>{if(/Content Security Policy/i.test(m.text()))csp.push(m.text())}); p.on('pageerror',e=>errs.push(e.message));
  await p.goto(B+'/__teste_login?u=1&c=1&v=0&to=/onboarding?passo=5');
  const st=async k=>p.getAttribute(`[data-testid="activation-${k}"]`,'data-state');
  let body=await p.textContent('body');
  ok(/Quase pronto: faltam 2 itens obrigatórios/.test(await p.textContent('[data-testid="activation-title"]')) && !/100% Concluído/.test(body), 'link ligado sem horários: "Quase pronto: faltam 2", sem 100%');
  ok(await st('profile')==='done' && await st('hours')==='pending' && await st('link')==='pending', 'perfil feito; horários e link pendentes');
  ok(await st('patient')==='done' && await st('appointment')==='done' && await st('plan')==='done', 'paciente, consulta e plano conferidos no banco');
  ok(/4 de 6/.test(await p.textContent('[data-testid="activation-count"]')), 'contagem "4 de 6"');
  ok(await p.isVisible('[data-testid="onboarding-booking-pending"]') && (await p.getAttribute('[data-testid="onboarding-booking-activate"]','href')).includes('secao=horarios'), 'aviso com botão para confirmar horários');
  const pct=(await p.textContent('body')).match(/(\d+)% Concluído/); ok(pct && +pct[1]<100, 'barra de progresso abaixo de 100% ('+(pct?pct[1]:'?')+'%)');
  const api=await p.evaluate(async()=>{const r=await fetch('/api/v1/onboarding/status');return r.ok?r.json():null;}).catch(()=>null);
  ok(!api || (api.activation && api.activation.ready===false), 'API de status traz a ativação'+(api?'':' (rota não exposta)'));
  await p.goto(B+'/dashboard');
  ok(await p.isVisible('[data-testid="activation-banner"]') && /incompleta \(4 de 6\)/.test(await p.textContent('[data-testid="activation-banner"]')), 'painel: "Ativação do consultório incompleta (4 de 6)"');
  ok((await p.getAttribute('[data-testid="activation-banner-cta"]','href'))==='/onboarding?passo=5', 'painel leva ao checklist');
  await p.goto(B+'/dashboard?onboarding=concluido');
  ok(!/Consultório ativado\. Já dá para agendar/.test(await p.textContent('body')), 'sem "Consultório ativado" enquanto falta obrigatório');
  await p.setViewportSize({width:375,height:800}); await p.goto(B+'/onboarding?passo=5');
  ok(await p.evaluate(()=>document.documentElement.scrollWidth<=innerWidth+1), '375 px: sem rolagem horizontal');
  await p.screenshot({path:process.env.E2E_OUT+'/p5412-375.png',fullPage:true});
  await p.setViewportSize({width:1280,height:900});
  // resolve a pendência
  await p.goto(B+'/configuracoes?tab=agenda&secao=horarios'); await p.click('button:has-text("Confirmar estes horários")'); await p.waitForLoadState();
  await p.goto(B+'/onboarding?passo=5');
  ok(/Consultório pronto para atender/.test(await p.textContent('[data-testid="activation-title"]')) && await st('hours')==='done' && await st('link')==='done', 'depois de confirmar: pronto, horários e link feitos');
  ok(/6 de 6/.test(await p.textContent('[data-testid="activation-count"]')) && !(await p.isVisible('[data-testid="onboarding-booking-pending"]')), '6 de 6 e sem aviso');
  await p.screenshot({path:process.env.E2E_OUT+'/p5412-pronto.png',fullPage:true});
  await p.goto(B+'/dashboard');
  ok(!(await p.isVisible('[data-testid="activation-banner"]')), 'painel sem o aviso de ativação');
  ok(csp.length===0 && errs.length===0, 'sem CSP e sem erro de JS ('+csp.length+'/'+errs.length+')');
  await b.close();
  const f=res.filter(x=>!x).length; console.log(`\n${res.length-f} PASS, ${f} FAIL`); process.exit(f?1:0);
})();
