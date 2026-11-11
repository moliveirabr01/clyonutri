const {chromium}=require(process.env.PLAYWRIGHT_MODULE||'playwright'),axe=require(process.env.AXE_MODULE||'axe-core'),fs=require('fs'),assert=require('assert');
let browser;
(async()=>{
 const f=JSON.parse(fs.readFileSync(process.env.ADAPTIVE_FIXTURE_FILE)),base=process.env.UX_BASE_URL,previous=process.env.UX_BASELINE_URL,dir=process.env.UX_EVIDENCE_DIR||'/tmp/ni2630-interactions';fs.mkdirSync(dir,{recursive:true});
 const checks=[],states=[],errors=[],before={};let p,c;
 const save=()=>fs.writeFileSync(dir+'/progress.json',JSON.stringify({checks,states,errors,before},null,2));
 function check(name,pass){checks.push({name,pass:!!pass});save();assert(pass,name);}
 async function login(page,url,email=f.email){await page.goto(url+'/login');await page.locator('#email').fill(email);await page.locator('#password').fill('Synthetic-local-651!');await page.locator('button[type=submit]').click();await page.waitForLoadState();}
 async function tabTo(selector,max=90){for(let i=0;i<max;i++){if(await p.locator(selector).evaluate(el=>el===document.activeElement))return;await p.keyboard.press('Tab');}throw Error('Não alcançou por Tab: '+selector);}
 async function inspect(label){
  await p.evaluate(axe.source);const r=await p.evaluate(()=>axe.run(document,{runOnly:{type:'tag',values:['wcag2a','wcag2aa','wcag21a','wcag21aa','wcag22aa']}}));
  const geometry=await p.evaluate(()=>({overflow:document.documentElement.scrollWidth>innerWidth,focused:document.activeElement?.id||document.activeElement?.tagName}));
  states.push({label,...geometry,violations:r.violations.map(v=>({id:v.id,nodes:v.nodes.map(n=>({target:n.target,summary:n.failureSummary}))})),incomplete:r.incomplete.map(i=>({id:i.id,count:i.nodes.length}))});save();
  await p.screenshot({path:dir+'/'+label+'.png',fullPage:true});
  check(label+' sem transbordamento',!geometry.overflow);check(label+' axe',r.violations.length===0);
 }
 browser=await chromium.launch({headless:true,executablePath:process.env.CHROME_PATH});const browserVersion=browser.version();c=await browser.newContext({viewport:{width:1280,height:900}});p=await c.newPage();p.on('pageerror',e=>errors.push(e.message));p.on('dialog',d=>d.accept());
 await login(p,previous);await p.goto(previous+'/agenda?novo=1&date='+f.date);await p.locator('#appointment-patient-search').fill('TESTE');await p.keyboard.press('Escape');before.combobox_expanded_after_escape=await p.locator('#appointment-patient-search').getAttribute('aria-expanded');
 await p.goto(previous+'/planos/'+f.quick_plan);await p.locator('#quick-food-query').fill('amendoim');await p.locator('[data-quick-item] button.food-result[data-food-id="'+f.allergen_food+'"]').click();before.clinical_alert_role=await p.locator('[data-quick-item] [data-allergen-item-alert]').getAttribute('role');await p.screenshot({path:dir+'/before-clinical-alert.png',fullPage:true});save();
 await login(p,base);
 // Combobox and booking using keyboard actions; date/time/duration are server-prefilled test conditions.
 await p.goto(base+'/agenda?novo=1&date='+f.date);
 await p.locator('#appointment-patient-search').focus();await p.keyboard.type('TESTE Paciente A');await p.keyboard.press('ArrowDown');
 check('combobox mantém foco e aponta opção válida',await p.locator('#appointment-patient-search').evaluate(el=>el===document.activeElement&&document.getElementById(el.getAttribute('aria-activedescendant'))?.getAttribute('role')==='option'));
 await p.keyboard.press('Escape');check('Escape fecha combobox sem fechar formulário',await p.locator('#appointment-patient-search').getAttribute('aria-expanded')==='false'&&await p.locator('[data-agenda-new]').isVisible());
 await p.keyboard.press('ArrowDown');await p.keyboard.press('Enter');check('Enter escolhe paciente autorizado',await p.locator('#dc11-patient_id').inputValue()===String(f.patient));
 await inspect('agenda-selecao-teclado');
 await tabTo('#dc15-notes');const bookingNote='TESTE NI2-630 teclado '+Date.now();await p.keyboard.type(bookingNote);await tabTo('[data-agenda-new] button[type=submit]');
 await Promise.all([p.waitForURL(url=>url.searchParams.get('success')==='agendado'),p.keyboard.press('Enter')]);
 check('consulta criada por teclado',new URL(p.url()).searchParams.get('success')==='agendado');
 await inspect('agenda-criada');
 // Popover anchors, targets and Escape, including medium widths from NI2-607/613.
 for(const width of [375,800,1024,1280]){
  await p.setViewportSize({width,height:900});await p.goto(base+'/agenda?date='+f.date);
  const summary=p.locator('details[data-more-actions]>summary').first();await summary.focus();await p.keyboard.press('Enter');await p.waitForTimeout(50);
  const rect=await p.locator('details[data-more-actions][open] [data-more-actions-menu]').boundingBox();
  check('menu cabe e não estica '+width,rect&&rect.x>=0&&rect.x+rect.width<=width+1&&rect.y>=0&&rect.y+rect.height<=900&&rect.width<Math.min(width,450));
  await p.keyboard.press('Escape');check('menu devolve foco '+width,await summary.evaluate(el=>el===document.activeElement&&!el.parentElement.open));
 }
 // Native details summaries must participate in the modal Tab cycle, including dynamic refresh.
 await p.setViewportSize({width:375,height:900});await p.goto(base+'/planos/'+f.quick_plan);
 const trigger=p.locator('[data-call=openAddItemModal]:visible').first();await trigger.focus();await p.keyboard.press('Enter');await p.locator('#modal-add-item').waitFor({state:'visible'});await p.waitForTimeout(50);
 const dialog=p.locator('#modal-add-item');
 check('diálogo nomeado e modal',await dialog.getAttribute('role')==='dialog'&&await dialog.getAttribute('aria-modal')==='true'&&!!await dialog.getAttribute('aria-labelledby'));
 for(let i=0;i<35;i++){await p.keyboard.press('Tab');check('Tab contido no diálogo '+i,await dialog.evaluate(d=>d.contains(document.activeElement)));}
 await dialog.locator('[data-clinical-context]>summary').focus();await p.keyboard.press('Space');check('summary do contexto operável',await dialog.locator('[data-clinical-context]').getAttribute('open')!==null);
 const ax=await p.context().newCDPSession(p),tree=await ax.send('Accessibility.getFullAXTree');check('árvore acessível expõe diálogo nomeado',tree.nodes.some(n=>!n.ignored&&n.role?.value==='dialog'&&n.name?.value));await ax.detach();
 await inspect('modal-alimento');
 await p.keyboard.press('Escape');check('Escape retorna ao gatilho',await trigger.evaluate(el=>el===document.activeElement)&&!await dialog.isVisible());
 // Unsaved modal fields keep the existing explicit discard choice.
 const mealTrigger=p.locator('[data-show=modal-add-meal]:visible').first();await mealTrigger.focus();await p.keyboard.press('Enter');const mealDialog=p.locator('#modal-add-meal');await mealDialog.waitFor({state:'visible'});const mealName=mealDialog.locator('[name=name]');await mealName.fill('TESTE ainda não salvo');
 p.removeAllListeners('dialog');p.once('dialog',d=>d.dismiss());await p.keyboard.press('Escape');check('cancelar descarte preserva edição e foco',await mealDialog.isVisible()&&await mealName.inputValue()==='TESTE ainda não salvo'&&await mealDialog.evaluate(d=>d.contains(document.activeElement)));
 p.once('dialog',d=>d.accept());await p.keyboard.press('Escape');check('descarte confirmado fecha diálogo',!await mealDialog.isVisible());p.on('dialog',d=>d.accept());
 // Dynamic component contract: no clinical data or API mock.
 await p.evaluate(()=>{const d=document.createElement('div');d.id='modal-a11y-dynamic';d.dataset.dialog='modal';d.hidden=true;d.style.display='none';d.innerHTML='<h2>Componente de teste</h2><button type="button" id="dynamic-first">Primeiro</button><details><summary id="dynamic-summary">Resumo</summary><button type="button">Dentro</button></details>';document.body.append(d);window.ClyoDialog.open(d);});await p.waitForTimeout(50);
 await p.locator('#dynamic-first').focus();await p.keyboard.press('Tab');check('summary é alcançado no trap',await p.locator('#dynamic-summary').evaluate(e=>e===document.activeElement));await p.keyboard.press('Tab');check('último summary retorna ao primeiro',await p.locator('#dynamic-first').evaluate(e=>e===document.activeElement));await p.keyboard.press('Escape');check('diálogo dinâmico fecha',!await p.locator('#modal-a11y-dynamic').isVisible());await p.locator('#modal-a11y-dynamic').evaluate(e=>e.remove());
 // Clinical warning has explicit text, severity, source and visible field label.
 await p.locator('#quick-food-query').fill('amendoim');await p.locator('[data-quick-item] button.food-result[data-food-id="'+f.allergen_food+'"]').click();const alert=p.locator('[data-quick-item] [data-allergen-item-alert]');
 check('conflito clínico assertivo e textual',await alert.getAttribute('role')==='alert'&&(await alert.textContent()).includes('Alerta clínico'));
 check('justificativa com rótulo visível',await alert.getByLabel('Justificativa clínica',{exact:true}).count()===1);await inspect('conflito-clinico');
 // Keyboard plan edit with actual persisted acknowledgement.
 await p.locator('#quick-meal-name').focus();const mealText='TESTE NI2-630 refeição '+Date.now();await p.keyboard.type(mealText);await tabTo('[data-quick-meal] button[type=submit]');await p.keyboard.press('Enter');await p.locator('[data-quick-plan] [data-state=saved]').waitFor();check('salvar refeição não oculta conflito',await alert.isVisible());await p.reload();check('plano editado persiste', (await p.locator('body').innerText()).includes(mealText));
 // Native validation exposes a named error without sending an invalid empty form.
 await p.locator('#quick-meal-name').focus();await tabTo('[data-quick-meal] button[type=submit]');await p.keyboard.press('Enter');check('erro associado ao campo',await p.locator('#quick-meal-name').getAttribute('aria-invalid')==='true'&&!!await p.locator('#quick-meal-name').getAttribute('aria-describedby'));await inspect('erro-campo');
 // Keyboard exam confirmation to review, preserving eligibility and server validation. No PDF issued.
 await p.goto(base+'/pacientes/'+f.patient+'/exames/novo');const exam=p.locator('#exam-request-form [name="exam_ids[]"]').first();await exam.focus();await p.keyboard.press('Space');await tabTo('#clinical_justification');await p.keyboard.type('TESTE avaliar reservas de ferro para ajustar o plano alimentar.');await tabTo('#exam-request-form [name=coverage_disclaimer_acknowledged]');await p.keyboard.press('Space');await tabTo('#exam-request-form button[type=submit]');await p.keyboard.press('Enter');await p.waitForURL(url=>new RegExp('/pacientes/'+f.patient+'/exames/\\d+$').test(url.pathname));check('exames confirmados em rascunho por teclado',await p.locator('form[action$="/revisar"]').count()===1);
 await p.locator('form[action$="/revisar"] button').focus();await p.keyboard.press('Enter');await p.locator('#revisao-emissao').waitFor();check('envio para revisão sem emitir PDF',await p.locator('[data-testid=btn-emitir-pdf]').isVisible());await inspect('exames-revisao');
 // Error/reload and tenant checks execute the real backend.
 await p.goto(base+'/planos/'+f.quick_plan);await c.setOffline(true);await p.locator('#quick-meal-name').fill('TESTE rede '+Date.now());await p.locator('[data-quick-meal] button[type=submit]').click();await p.locator('[data-quick-error]:visible').waitFor();check('falha de rede mantém formulário',(await p.locator('#quick-meal-name').inputValue()).startsWith('TESTE rede'));await c.setOffline(false);await p.locator('[data-quick-retry]').click();await p.locator('[data-quick-plan] [data-state=saved]').waitFor();await p.reload();check('retry confirmado sobrevive recarga',(await p.locator('body').innerText()).includes('TESTE rede'));
 const other=await browser.newContext(),q=await other.newPage();await login(q,base,f.email_b);for(const route of ['/api/workflow/plan/'+f.quick_plan,'/api/workflow/exam/0?patient_id='+f.patient]){const r=await other.request.get(base+route);check('nega outra clínica '+route,r.status()===403||r.status()===404||r.status()===405);}await q.goto(base+'/pacientes/'+f.patient);check('prontuário de outra clínica não exposto',!(await q.locator('body').innerText()).includes('TESTE Paciente A'));await other.close();
 check('sem erros JavaScript',errors.length===0);save();await c.close();await browser.close();browser=null;fs.writeFileSync(dir+'/report.json',JSON.stringify({issue:'NI2-630',automated:true,browser_version:browserVersion,axe_version:axe.version,synthetic_data:true,checks,states,before,errors,limitations:'Keyboard actions automated with initial focus set by harness; no human metrics or full screen-reader certification.'},null,2));console.log(JSON.stringify({checks:checks.length,states:states.length,passed:true}));
})().catch(async e=>{console.error(e);if(browser)await browser.close();process.exit(1)});
