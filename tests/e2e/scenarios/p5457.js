// v54.57: correções da auditoria UX/UI 360° (v54.56), pelo navegador real — Chromium e Firefox, 1440 e 390 px.
// UX-103 (rascunho corrompido), UX-102 (portal web), UX-111/R55-01 (erro HTML), UX-101/UX-119/UX-105 (etapas do atendimento),
// UX-110 (busca e Nova consulta), UX-113 (voltar à tela após o login), UX-120 (KPI), isolamento entre clínicas e axe WCAG 2.2 A/AA.
const { chromium, firefox } = require('playwright');
const { execSync } = require('child_process');
const res=[]; const ok=(c,l)=>{res.push(!!c);console.log((c?'  PASS  ':'  FAIL  ')+l);};
let axe=null; try { axe=require(process.env.AXE_MODULE||'axe-core'); } catch(e) {}
(async()=>{
  const B=process.argv[2], DB=process.argv[3];
  const sq=q=>execSync(`php ${process.env.E2E_SQ} ${DB} "${q}"`).toString().trim();
  const TZ='America/Sao_Paulo';
  const loc=d=>{const f=new Intl.DateTimeFormat('en-CA',{timeZone:TZ,year:'numeric',month:'2-digit',day:'2-digit',hour:'2-digit',minute:'2-digit',hour12:false}).formatToParts(d);const g=t=>f.find(x=>x.type===t).value;return {ymd:`${g('year')}-${g('month')}-${g('day')}`,hm:`${g('hour')==='24'?'00':g('hour')}:${g('minute')}`,br:`${g('day')}/${g('month')}/${g('year')}`};};
  const axeRun=async(p,tag)=>{ if(!axe){ok(false,`${tag}: axe-core indisponível`);return;} await p.evaluate(axe.source);
    const a=await p.evaluate(()=>axe.run(document,{runOnly:{type:'tag',values:['wcag2a','wcag2aa','wcag21a','wcag21aa','wcag22aa']}}));
    ok(a.violations.length===0, `${tag}: axe WCAG 2.2 A/AA sem violações`+(a.violations.length?': '+a.violations.map(v=>v.id+'×'+v.nodes.length).join(', '):'')); };
  const p2name=sq("SELECT name FROM patients WHERE id=2");
  sq(`INSERT INTO patients (clinic_id, uuid, name, birth_date, gender, status) VALUES (2, 'p5457b', 'Paciente TESTE Outra Clinica 5457', '1991-01-01', 'F', 'active')`);
  const pB=sq("SELECT id FROM patients WHERE uuid='p5457b'");

  for (const [bt, engine] of [['chromium', chromium], ['firefox', firefox]]) {
    console.log(`== ${bt}`);
    const b=await engine.launch();
    for (const [w,h] of [[1440,900],[390,844]]) {
      const tag=`${bt} ${w}px`;
      const ctx=await b.newContext({viewport:{width:w,height:h},locale:'pt-BR',timezoneId:TZ}); const p=await ctx.newPage();
      const errs=[]; p.on('pageerror',e=>errs.push(e.message));
      const bad=[]; p.on('response',r=>{ if(r.status()>=500) bad.push(r.status()+' '+r.url().replace(B,'')); });
      await p.goto(B+'/__teste_login?u=1&c=1&v=0&to=/dashboard'); await p.waitForLoadState('networkidle');

      console.log(`-- ${tag}: UX-103 rascunho corrompido`);
      sq("UPDATE anamneses SET objectives='enc:v1:AAAAcorrompidoTESTE' WHERE patient_id=2 AND is_draft=1");
      const audBefore=+sq("SELECT COUNT(*) FROM audit_logs WHERE action='ANAMNESIS_DRAFT_UNREADABLE_DISCARDED'");
      let r=await p.goto(B+'/pacientes/2/anamnese');
      ok(r.status()===200 && await p.locator('[data-testid="anamnese-rascunho-ilegivel"]').isVisible(), `${tag}: anamnese abre (HTTP ${r.status()}) e mostra o aviso do campo ilegível`);
      ok(/Objetivos/.test(await p.textContent('[data-testid="anamnese-rascunho-ilegivel"]')), `${tag}: o aviso nomeia o campo (Objetivos)`);
      if (w===1440) await axeRun(p, `${tag} anamnese com aviso`);
      await p.click('[data-testid="anamnese-recuperar-rascunho"]'); await p.waitForLoadState();
      ok(/success=rascunho_recuperado/.test(p.url()) && await p.locator('[data-testid="anamnese-rascunho-ilegivel"]').count()===0, `${tag}: "Recuperar rascunho" resolve e o aviso some`);
      ok(+sq("SELECT COUNT(*) FROM audit_logs WHERE action='ANAMNESIS_DRAFT_UNREADABLE_DISCARDED'")===audBefore+1, `${tag}: recuperação auditada (+1 registro)`);
      ok(sq("SELECT COUNT(*) FROM anamneses WHERE patient_id=2 AND is_draft=0")==='1', `${tag}: versão concluída intacta`);

      console.log(`-- ${tag}: UX-101/UX-119/UX-105 etapas do atendimento`);
      const when=new Date(Date.now()-10*60000); const L=loc(when);
      sq(`INSERT INTO appointments (clinic_id, patient_id, professional_id, scheduled_at, duration_minutes, status, type) VALUES (1, 2, 1, '${L.ymd} ${L.hm}:00', 60, 'in_progress', 'follow_up')`);
      const aid=sq("SELECT MAX(id) FROM appointments");
      r=await p.goto(B+`/pacientes/2/antropometria?atendimento=${aid}`);
      const txt=(await p.textContent('main')).replace(/\s+/g,' ');
      ok(r.status()===200 && txt.includes(`Consulta #${aid}`) && txt.includes(`${L.br} às ${L.hm}`), `${tag}: UX-119 faixa com data e hora (${L.br} às ${L.hm})`);
      const steps=p.locator('[data-testid="attendance-steps"] a[data-step]');
      ok(await steps.count()>=3 && await p.locator('[data-testid="attendance-steps"] a[data-step="avaliacao"][aria-current="step"]').count()===1, `${tag}: UX-105 etapas navegáveis com a atual marcada`);
      if (!(await p.locator('input[name="weight_kg"]').first().isVisible())) { await p.locator("button:has-text('Nova Avaliação'):visible").first().click(); await p.waitForTimeout(300); }
      await p.fill('input[name="weight_kg"]:visible','70.5'); await p.fill('input[name="height_cm"]:visible','164');
      if (await p.locator("button:has-text('2. Circunferências'):visible").count()) await p.locator("button:has-text('2. Circunferências'):visible").click();
      await p.fill('input[name="waist_cm"]:visible','84'); await p.fill('input[name="hip_cm"]:visible','101');
      await p.locator("button:has-text('Salvar avaliação antropométrica'):visible").click(); await p.waitForLoadState();
      ok(sq(`SELECT COUNT(*) FROM care_progress_events WHERE appointment_id=${aid} AND step_key='avaliacao' AND decision='confirm'`)==='1', `${tag}: UX-101 avaliação salva no atendimento vira etapa concluída`);
      await p.locator(`[data-testid="attendance-steps"] a[data-step="plano"]`).first().click(); await p.waitForLoadState();
      ok(/\/plano/.test(p.url()) && /✓\s*Avaliação|Avaliação\s*✓/.test(await p.textContent('[data-testid="attendance-steps"]')), `${tag}: UX-105 vai direto ao plano pela faixa, com Avaliação ✓`);
      await p.goto(B+`/consulta/${aid}`);
      const pend=await p.$$eval('[data-testid="consult-pending-review"] li[data-step]',ls=>ls.map(l=>l.dataset.step));
      ok(!pend.includes('avaliacao') && pend.includes('anamnese'), `${tag}: UX-101 conclusão lista só o que falta [${pend.join(', ')}] (negativo: anamnese não feita continua pendente)`);
      sq(`UPDATE appointments SET status='completed' WHERE id=${aid}`);

      console.log(`-- ${tag}: UX-111/R55-01 páginas de erro e isolamento`);
      r=await p.goto(B+'/rota-que-nao-existe-v5457');
      ok(r.status()===404 && await p.locator('[data-testid="error-page"]').isVisible() && !(await p.textContent('body')).trim().startsWith('{'), `${tag}: 404 em página HTML (não JSON)`);
      if (w===1440) await axeRun(p, `${tag} página 404`);
      await p.locator('[data-testid="error-page"] a[href="/dashboard"]').first().click(); await p.waitForLoadState();
      ok(new URL(p.url()).pathname==='/dashboard', `${tag}: caminho de volta leva ao painel`);
      r=await p.goto(B+'/pacientes/2/pre-consulta?atendimento=999999');
      ok(r.status()===403 && await p.locator('[data-testid="error-page"]').isVisible(), `${tag}: R55-01 atendimento inválido → 403 com layout`);
      r=await p.goto(B+`/pacientes/${pB}`);
      ok(new URL(p.url()).pathname!==`/pacientes/${pB}` && !(await p.content()).includes('Outra Clinica 5457'), `${tag}: isolamento — perfil do paciente da clínica B não abre (foi para ${new URL(p.url()).pathname}, HTTP ${r.status()})`);

      console.log(`-- ${tag}: UX-110/UX-120 painel`);
      await p.goto(B+'/dashboard');
      const q=p2name.split(' ')[0];
      await p.fill('#dash-patient-search', q); await p.press('#dash-patient-search','Enter'); await p.waitForLoadState();
      ok(/\/pacientes\?q=/.test(p.url()) && (await p.textContent('main')).includes(p2name), `${tag}: UX-110 busca no topo acha "${p2name}"`);
      await p.goto(B+'/dashboard');
      const nb=await p.locator('[data-testid="dash-new-appointment"]').boundingBox();
      ok(nb && nb.y+nb.height<=h, `${tag}: UX-110 "+ Nova consulta" na 1ª dobra (y=${nb?Math.round(nb.y):'-'})`);
      await p.click('[data-testid="dash-new-appointment"]'); await p.waitForLoadState();
      ok(/\/agenda\?novo=1/.test(p.url()), `${tag}: "+ Nova consulta" abre o agendamento`);
      await ctx.close();

      console.log(`-- ${tag}: UX-102 portal web do paciente`);
      const pc=await b.newContext({viewport:{width:w,height:h},locale:'pt-BR',timezoneId:TZ}); const pp=await pc.newPage();
      const p401=[]; pp.on('response',r=>{ if(r.status()===401) p401.push(r.url().replace(B,'')); });
      await pp.goto(B+'/paciente/app');
      await pp.click('#tab-btn-login');
      await pp.fill('#login-input','paciente.teste@teste.invalid'); await pp.fill('#login-pass','Teste-Paciente-123');
      await pp.locator('form[action="/paciente/app/login"] button[type="submit"]').click(); await pp.waitForLoadState();
      ok(await pp.locator('[data-testid="portal-card-plano"]').isVisible(), `${tag}: login web do portal abre a área logada`);
      await pp.locator('a[href="/paciente/plano"]').first().click(); await pp.waitForLoadState();
      ok(await pp.locator('[data-testid="portal-plano"]').isVisible() && !/ainda não publicou/.test(await pp.textContent('body')), `${tag}: UX-102 plano publicado visível para a paciente`);
      if (w===1440) await axeRun(pp, `${tag} portal plano`);
      await pp.goto(B+'/paciente/app');
      await pp.locator('a[href="/paciente/consultas"]').first().click(); await pp.waitForLoadState('networkidle');
      const body=(await pp.textContent('body')).trim();
      ok(/Minhas consultas/.test(body) && !body.startsWith('{') && p401.length===0, `${tag}: UX-102 "Minhas consultas" sem JSON 401`+(p401.length?': '+p401.join(','):''));
      await pc.close();

      console.log(`-- ${tag}: UX-113 sessão expirada volta à tela`);
      const lc=await b.newContext({viewport:{width:w,height:h}}); const lp=await lc.newPage();
      await lp.goto(B+'/login?error=session_expired&voltar='+encodeURIComponent('/pacientes/2/anamnese'));
      ok(/Sua sessão terminou/.test(await lp.textContent('body')), `${tag}: login explica a sessão expirada`);
      await lp.fill('input[name=email]','nutri.teste.a@teste.invalid'); await lp.fill('input[name=password]','Teste-Senha-123');
      await lp.locator('form:has(input[name=password]) button[type=submit]').first().click(); await lp.waitForLoadState();
      ok(new URL(lp.url()).pathname==='/pacientes/2/anamnese', `${tag}: UX-113 depois de entrar volta à anamnese (${new URL(lp.url()).pathname})`);
      await lc.close();
      ok(errs.length===0 && bad.length===0, `${tag}: sem erro de JS nem 5xx`+(errs[0]?': '+errs[0].slice(0,120):'')+(bad[0]?' '+bad.join(','):''));
    }
    await b.close();
  }
  console.log('== negativo: sem login');
  const b=await chromium.launch(); const ac=await b.newContext(); const ap=await ac.newPage();
  await ap.goto(B+'/pacientes/2/anamnese');
  ok(/\/login/.test(new URL(ap.url()).pathname), `sem login: anamnese manda para o login (${new URL(ap.url()).pathname})`);
  const rr=await ap.goto(B+'/paciente/plano');
  ok(/\/paciente\/app/.test(ap.url()) && !(await ap.textContent('body')).trim().startsWith('{'), 'sem sessão do portal: plano volta ao login do portal (HTML)');
  await b.close();
  const n=res.filter(x=>!x).length; console.log(`\n${res.length-n} PASS, ${n} FAIL`); process.exit(n?1:0);
})().catch(e=>{console.error(e);console.log('\n0 PASS, 1 FAIL');process.exit(1);});
