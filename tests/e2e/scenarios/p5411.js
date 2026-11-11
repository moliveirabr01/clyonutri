const { chromium } = require('playwright');
const res=[]; const ok=(c,l)=>{res.push(!!c);console.log((c?'  PASS  ':'  FAIL  ')+l);};
(async()=>{
  const B=process.argv[2];
  const b=await chromium.launch(); const ctx=await b.newContext({viewport:{width:1280,height:800}}); const p=await ctx.newPage();
  const csp=[]; const errs=[];
  const watch=pg=>{pg.on('console',m=>{if(/Content Security Policy/i.test(m.text()))csp.push(m.text())}); pg.on('pageerror',e=>errs.push(e.message)); pg.on('dialog',d=>d.accept());};
  watch(p);
  await p.goto(B+'/__teste_login?u=1&c=1&v=0&to=/configuracoes/equipe');

  console.log('== NI2-606');
  ok(/\/configuracoes\/equipe$/.test(p.url()), 'abre /configuracoes/equipe');
  // v54.58/59: o menu lateral também tem "Equipe" (dono/admin); cada navegação marca o seu item atual
  const tab=p.locator('nav[aria-label="Áreas de configurações"] a[aria-current="page"]', {hasText:'Equipe'});
  ok(await tab.count()===1 && await p.locator('#clinical-navigation a[aria-current="page"]', {hasText:'Equipe'}).count()===1, 'aba "Equipe" marcada como atual (abas de configurações e menu lateral)');
  ok(/1 de 3 do plano/.test(await p.textContent('[data-testid="team-limit"]')), 'limite: "1 de 3 do plano" ('+(await p.textContent('[data-testid="team-limit"]')).trim().replace(/\s+/g,' ')+')');
  // convite sem CRN
  await p.fill('#team-name','Nutri TESTE Convidada'); await p.fill('#team-email','nutri.teste.b@teste.invalid');
  await p.click('[data-testid="team-invite-submit"]'); await p.waitForLoadState();
  ok(/CRN/.test(await p.textContent('[data-testid="team-flash"]')) && await p.inputValue('#team-name')==='Nutri TESTE Convidada', 'sem CRN: erro e campos preservados');
  await p.fill('#team-crn','12345'); await p.selectOption('#team-crn-region','CRN-3');
  await p.click('[data-testid="team-invite-submit"]'); await p.waitForLoadState();
  const link=await p.inputValue('[data-testid="team-invite-link"]').catch(()=>'');
  ok(/\/recuperar-senha\//.test(link), 'convite: link exibido uma vez (servidor sem e-mail)');
  const rowB=p.locator('[data-testid="team-member"]',{hasText:'nutri.teste.b@teste.invalid'});
  ok(await rowB.getAttribute('data-state')==='invited' && /CRN-3 12345/.test(await rowB.textContent()), 'convidada aparece como "convidada" com CRN');
  ok(/2 de 3/.test(await p.textContent('[data-testid="team-limit"]')), 'convite conta no limite (2 de 3)');
  await p.reload();
  ok(!(await p.isVisible('[data-testid="team-invite-link"]')), 'link não reaparece ao recarregar');
  // convidada cria a senha
  const ctx2=await b.newContext(); const q=await ctx2.newPage(); watch(q);
  await q.goto(link); await q.fill('#password','Teste-Senha-456'); await q.fill('#password_confirmation','Teste-Senha-456');
  await q.click('button[type="submit"]'); await q.waitForLoadState();
  await q.goto(B+'/login'); await q.fill('input[name="email"]','nutri.teste.b@teste.invalid'); await q.fill('input[name="password"]','Teste-Senha-456');
  await q.click('button[type="submit"]'); await q.waitForLoadState();
  ok(!/\/login/.test(q.url()), 'convidada cria a senha e entra ('+new URL(q.url()).pathname+')');
  await q.goto(B+'/configuracoes/equipe');
  ok(!(await q.isVisible('[data-testid="team-invite-form"]')) && /Somente o titular/.test(await q.textContent('body')), 'nutricionista comum vê a equipe sem poder convidar');
  // recepção leva ao limite
  await p.fill('#team-name','Recepcao TESTE'); await p.fill('#team-email','recepcao.teste@teste.invalid'); await p.selectOption('#team-role','receptionist');
  await p.click('[data-testid="team-invite-submit"]'); await p.waitForLoadState();
  ok(await p.isVisible('[data-testid="team-limit-reached"]') && (await p.getAttribute('[data-testid="team-upgrade"]','href')).includes('/configuracoes/assinatura'), 'limite atingido (3 de 3): aviso com "Trocar de plano"');
  ok(!(await p.isVisible('[data-testid="team-invite-form"]')), 'formulário some no limite');
  // papel
  const uidB=await rowB.getAttribute('data-user');
  await p.selectOption('#role-'+uidB,'admin'); await rowB.locator('button:has-text("Salvar papel")').click(); await p.waitForLoadState();
  ok(/gestor/.test(await rowB.textContent()), 'papel alterado para gestor(a)');
  // desativar
  await rowB.locator('[data-testid="team-deactivate"]').click(); await p.waitForLoadState();
  ok(await rowB.getAttribute('data-state')==='inactive', 'desativar: situação "inativa"');
  ok(await p.isVisible('[data-testid="team-invite-form"]') && /2 de 3/.test(await p.textContent('[data-testid="team-limit"]')), 'vaga liberada (2 de 3) e formulário volta');
  await q.goto(B+'/dashboard');
  ok(/\/login/.test(q.url()), 'sessão da pessoa desativada encerrada na hora');
  await q.fill('input[name="email"]','nutri.teste.b@teste.invalid'); await q.fill('input[name="password"]','Teste-Senha-456');
  await q.click('button[type="submit"]'); await q.waitForLoadState();
  ok(/\/login/.test(q.url()) && /acesso a esta clínica foi desativado/.test(await q.textContent('body')), 'login de desativada: mensagem clara, sem laço');
  // reativar
  await rowB.locator('[data-testid="team-reactivate"]').click(); await p.waitForLoadState();
  ok(await rowB.getAttribute('data-state')==='active', 'reativar: volta a "ativa"');
  const hist=await p.textContent('[data-testid="team-history"]');
  ok(/Convite enviado/.test(hist) && /Acesso desativado/.test(hist) && /Acesso reativado/.test(hist) && /Papel alterado/.test(hist) && !/recuperar-senha/.test(hist), 'histórico com as ações, sem o link');
  await p.goto(B+'/configuracoes');
  ok(await p.locator('a',{hasText:'Equipe'}).first().isVisible(), 'aba "Equipe" também no perfil');
  await p.setViewportSize({width:375,height:800}); await p.goto(B+'/configuracoes/equipe');
  ok(await p.evaluate(()=>document.documentElement.scrollWidth<=window.innerWidth+1), '375 px: sem rolagem horizontal');
  await p.screenshot({path:process.env.E2E_OUT+'/p5411-equipe-375.png',fullPage:true});
  await p.setViewportSize({width:1280,height:800}); await p.goto(B+'/configuracoes/equipe');
  await p.screenshot({path:process.env.E2E_OUT+'/p5411-equipe.png',fullPage:true});

  console.log('== NI2-608');
  await p.goto(B+'/agenda?novo=1'); await p.waitForTimeout(400);
  const inp=p.locator('#appointment-patient-search');
  if(!(await inp.isVisible())){ await p.click('text=Novo agendamento').catch(()=>{}); await p.waitForTimeout(300); }
  await inp.fill('TESTE'); await p.waitForSelector('.patient-match-option',{timeout:5000}).catch(()=>{});
  const opts=p.locator('.patient-match-option'); const n=await opts.count();
  ok(n>=2, 'busca lista pacientes ('+n+')');
  const h=await opts.first().evaluate(e=>e.getBoundingClientRect().height);
  ok(h>=32, 'item com altura ≥ 32 px ('+Math.round(h)+')');
  const pad=await opts.first().evaluate(e=>parseFloat(getComputedStyle(e).paddingLeft));
  ok(pad>=8, 'item com respiro lateral ('+pad+' px)');
  // v54.30 NI2-630: foco fica no campo; a opção ativa vem por aria-activedescendant + aria-selected
  const act=async()=>inp.evaluate(e=>e.getAttribute('aria-activedescendant')||'');
  await inp.press('ArrowDown');
  const id0=await opts.nth(0).getAttribute('id');
  ok(await act()===id0 && await inp.evaluate(e=>e===document.activeElement), '↓ no campo ativa o 1º paciente');
  const ol=await opts.nth(0).evaluate(e=>{const s=getComputedStyle(e);return {sel:e.getAttribute('aria-selected'),st:s.outlineStyle,w:parseFloat(s.outlineWidth),bg:s.backgroundColor,sh:s.boxShadow};});
  ok(ol.sel==='true' && ((ol.st!=='none'&&ol.w>=2) || ol.sh!=='none' || ol.bg!=='rgba(0, 0, 0, 0)'), 'opção ativa visível ('+JSON.stringify(ol)+')');
  await p.keyboard.press('ArrowDown');
  ok(await act()===await opts.nth(1).getAttribute('id'), '↓ passa ao próximo');
  await p.keyboard.press('ArrowUp');
  ok(await act()===id0 && await inp.evaluate(e=>e===document.activeElement), '↑ volta ao anterior com foco no campo');
  await inp.press('ArrowDown'); await p.keyboard.press('Escape');
  ok(!(await p.isVisible('#appointment-patient-matches')) && await inp.evaluate(e=>e===document.activeElement), 'Esc fecha a lista e devolve o foco');
  await inp.fill('TESTE'); await p.waitForSelector('.patient-match-option'); await inp.press('ArrowDown'); await p.keyboard.press('Enter');
  ok(/Paciente TESTE/.test(await inp.inputValue()) || await p.locator('#appointment-patient-matches').isHidden(), 'Enter escolhe o paciente ('+(await inp.inputValue())+')');
  await inp.fill('TESTE'); await p.waitForSelector('.patient-match-option'); 
  await p.locator('#appointment-patient-matches').screenshot({path:process.env.E2E_OUT+'/p5411-busca.png'});

  ok(csp.length===0, 'sem violação de CSP ('+csp.length+')');
  ok(errs.length===0, 'sem erro de JavaScript ('+errs.join(' | ').slice(0,200)+')');
  console.log(res.filter(x=>x).length+'/'+res.length);
  await b.close();
})();
