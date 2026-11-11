// v54.56: Painel geral no estilo Talui — dados reais (conferidos no banco), comparação neutra sem base,
// isolamento entre clínicas, login obrigatório, 4 itens + "Ver todos", agenda na 1ª dobra, 1440/390, Chromium e Firefox, axe.
const { chromium, firefox } = require('playwright');
const { execSync } = require('child_process');
const res=[]; const ok=(c,l)=>{res.push(!!c);console.log((c?'  PASS  ':'  FAIL  ')+l);};
let axe=null; try { axe=require(process.env.AXE_MODULE||'axe-core'); } catch(e) {}
(async()=>{
  const B=process.argv[2], DB=process.argv[3];
  const sq=q=>execSync(`php ${process.env.E2E_SQ} ${DB} "${q}"`).toString().trim();
  const TZ='America/Sao_Paulo';
  const ymd=d=>new Intl.DateTimeFormat('en-CA',{timeZone:TZ,year:'numeric',month:'2-digit',day:'2-digit'}).format(d);
  const now=new Date(); const today=ymd(now); const yest=ymd(new Date(now.getTime()-86400000));
  const hour=parseInt(new Intl.DateTimeFormat('en-GB',{timeZone:TZ,hour:'2-digit',hour12:false}).format(now),10);
  const greet=hour<12?'Bom dia':(hour<18?'Boa tarde':'Boa noite');
  const t0=new Date(today+'T12:00:00Z'); const dow=(t0.getUTCDay()+6)%7; const mon=new Date(t0.getTime()-dow*86400000); const nextMon=new Date(mon.getTime()+7*86400000);
  const monS=mon.toISOString().slice(0,10), nextS=nextMon.toISOString().slice(0,10), prevMonS=new Date(mon.getTime()-7*86400000).toISOString().slice(0,10);
  const cnt=q=>parseInt(sq(q)||'0',10);
  const expToday=cnt(`SELECT COUNT(*) FROM appointments WHERE clinic_id=1 AND scheduled_at>='${today} 00:00:00' AND scheduled_at<date('${today}','+1 day')||' 00:00:00'`);
  const expYest=cnt(`SELECT COUNT(*) FROM appointments WHERE clinic_id=1 AND scheduled_at>='${yest} 00:00:00' AND scheduled_at<'${today} 00:00:00'`);
  const expWeek=cnt(`SELECT COUNT(*) FROM appointments WHERE clinic_id=1 AND scheduled_at>='${monS} 00:00:00' AND scheduled_at<'${nextS} 00:00:00' AND status<>'cancelled'`);
  const expPrevWeek=cnt(`SELECT COUNT(*) FROM appointments WHERE clinic_id=1 AND scheduled_at>='${prevMonS} 00:00:00' AND scheduled_at<'${monS} 00:00:00' AND status<>'cancelled'`);
  const expPatients=cnt("SELECT COUNT(*) FROM patients WHERE clinic_id=1");
  const nameB=sq("SELECT name FROM patients WHERE clinic_id=2 LIMIT 1");
  const lbl=(c,p,per)=>(p===0&&c>0)?'sem base anterior':(c===p?'sem variação':((c>p?'↑ ':'↓ ')+Math.abs(c-p)+' '+per));

  for (const [bt, engine] of [['chromium', chromium], ['firefox', firefox]]) {
    console.log(`== ${bt}`);
    const b=await engine.launch();
    for (const [w,h] of [[1440,900],[390,844]]) {
      const ctx=await b.newContext({viewport:{width:w,height:h}}); const p=await ctx.newPage();
      const errs=[]; p.on('pageerror',e=>errs.push(e.message)); p.on('console',m=>{if(m.type()==='error')errs.push(m.text())});
      const r=await p.goto(B+'/__teste_login?u=1&c=1&v=0&to=/dashboard'); await p.waitForLoadState('networkidle');
      const tag=`${bt} ${w}px`;
      ok(r.status()===200 && await p.locator('[data-testid="dashboard-v5456"]').count()===1, `${tag}: /dashboard 200 com o novo painel`);
      const h1=(await p.textContent('h1')).replace(/\s+/g,' ').trim();
      ok(h1===`${greet}, Maicon`, `${tag}: saudação pelo horário de São Paulo e primeiro nome do banco ("${h1}")`);
      const dl=(await p.textContent('[data-testid="dash-date"]')).trim();
      const exp=new Intl.DateTimeFormat('pt-BR',{timeZone:TZ,weekday:'long',day:'numeric',month:'long',year:'numeric'}).format(now);
      ok(dl.toLowerCase()===exp.toLowerCase(), `${tag}: data por extenso "${dl}"`);
      const geom=await p.evaluate(()=>({sw:document.documentElement.scrollWidth,iw:innerWidth,week:document.querySelector('[data-testid="dash-week"]').getBoundingClientRect().top+scrollY,
        metrics:document.querySelector('[data-testid="dash-metrics"]').getBoundingClientRect().top+scrollY,att:document.querySelector('[data-testid="attention-panel"]').getBoundingClientRect().top+scrollY,
        greet:document.querySelector('[data-testid="dash-greeting"]').getBoundingClientRect().top+scrollY}));
      ok(geom.sw<=geom.iw, `${tag}: sem rolagem horizontal (${geom.sw} ≤ ${geom.iw})`);
      ok(geom.greet<geom.metrics && geom.metrics<geom.week && geom.week<geom.att, `${tag}: ordem saudação → indicadores → agenda → lista acionável`);
      if (w===1440) ok(geom.week+80<h, `${tag}: agenda da semana na primeira dobra (topo em ${Math.round(geom.week)} px de ${h})`);
      // small targets
      const small=await p.evaluate(min=>[...document.querySelectorAll('.dash a, .dash button, .dash summary')].filter(e=>{const r=e.getBoundingClientRect();return r.width>0&&(r.height<min||r.width<min)&&!e.closest('p')}).map(e=>e.textContent.trim().slice(0,30)),w<769?44:24);
      ok(small.length===0, `${tag}: alvos de toque ≥ ${w<769?44:24} px fora de texto corrido`+(small.length?': '+small.join(' | '):''));
      if (axe) {
        await p.evaluate(axe.source);
        const a=await p.evaluate(()=>axe.run(document,{runOnly:{type:'tag',values:['wcag2a','wcag2aa','wcag21a','wcag21aa','wcag22aa']}}));
        ok(a.violations.length===0, `${tag}: axe WCAG 2.2 A/AA sem violações`+(a.violations.length?': '+a.violations.map(v=>v.id+'×'+v.nodes.length).join(', '):''));
      } else ok(false, `${tag}: axe-core indisponível (instale axe-core em tests/e2e)`);
      if (bt==='chromium' && w===1440) {
        console.log('== dados reais (conferidos no SQLite do cenário)');
        ok(parseInt(await p.textContent('[data-testid="appointments-today-count"]'))===expToday, `consultas hoje = ${expToday} (banco)`);
        ok(parseInt(await p.textContent('[data-testid="appointments-week-count"]'))===expWeek, `consultas na semana = ${expWeek} (banco, sem canceladas)`);
        ok(parseInt(await p.textContent('[data-testid="active-patients-count"]'))===expPatients, `pacientes = ${expPatients} (banco)`);
        const trends=await p.$$eval('[data-testid="dash-metrics"] [data-testid="dash-trend"]',ls=>ls.map(l=>l.textContent.replace(/\s+/g,' ').trim()));
        ok(trends[0].startsWith(lbl(expToday,expYest,'vs. ontem')), `selo de hoje = "${lbl(expToday,expYest,'vs. ontem')}" (ontem ${expYest}) → "${trends[0]}"`);
        const weekTrend=((await p.locator('[data-testid="dash-week-range"] ~ [data-testid="dash-trend"]').first().textContent())||'').replace(/\s+/g,' ').trim(); // v54.58: selo semanal junto da agenda
        ok(weekTrend.startsWith(lbl(expWeek,expPrevWeek,'vs. semana anterior')), `selo da semana = "${lbl(expWeek,expPrevWeek,'vs. semana anterior')}" (anterior ${expPrevWeek})`);
        const srs=await p.$$eval('[data-testid="dash-trend"] .sr-only',ls=>ls.map(l=>l.textContent.trim()));
        ok(srs.length>=3 && srs.every(s=>s.length>5), 'cada selo tem texto para leitor de tela (não só seta/cor)');
        const days=await p.$$eval('[data-testid="dash-week-day"]',ls=>ls.map(l=>({d:l.dataset.date,c:+l.dataset.count,today:l.getAttribute('aria-current')==='date',n:l.querySelectorAll('[data-testid="dash-week-appt"]').length,more:(l.querySelector('.dash-day__more')||{}).textContent||''})));
        ok(days.reduce((s,d)=>s+d.c,0)===expWeek, `agenda da semana soma ${days.reduce((s,d)=>s+d.c,0)} = card da semana`);
        let perDayOk=true; for (const d of days) { const e=cnt(`SELECT COUNT(*) FROM appointments a JOIN patients p ON p.id=a.patient_id AND p.clinic_id=a.clinic_id WHERE a.clinic_id=1 AND a.status<>'cancelled' AND a.scheduled_at>='${d.d} 00:00:00' AND a.scheduled_at<date('${d.d}','+1 day')||' 00:00:00'`); if (e!==d.c || d.n!==Math.min(4,e) || (e>4 && !d.more.includes('+'+(e-4)))) perDayOk=false; }
        ok(perDayOk, 'cada dia confere com o banco (até 4 visíveis + "+N neste dia")');
        ok(days.filter(d=>d.today).length===1 && days.find(d=>d.today).d===today, 'hoje destacado (aria-current="date")');
        const cancelled=sq(`SELECT p.name FROM appointments a JOIN patients p ON p.id=a.patient_id WHERE a.clinic_id=1 AND a.status='cancelled' AND a.scheduled_at>='${monS}' LIMIT 1`);
        const weekTxt=await p.textContent('[data-testid="dash-week"]');
        ok(!/Cancelad/.test(weekTxt), 'nenhuma consulta cancelada na agenda da semana'+(cancelled?' (há cancelada no banco)':''));
        const html=await p.content();
        ok(nameB && !html.includes(nameB), `isolamento: paciente da clínica B ("${nameB}") não aparece no painel da clínica A`);
        console.log('== lista acionável');
        const vis=await p.$$eval('[data-testid="attention-panel"] > ul > [data-testid="attention-item"]',ls=>ls.length);
        const all=await p.$$eval('[data-testid="attention-item"]',ls=>ls.length);
        const total=parseInt(await p.textContent('[data-testid="attention-count"]'));
        ok(vis===Math.min(4,total) && all===total, `${vis} itens visíveis de ${total}; todos alcançáveis no painel (${all})`);
        const sum=(await p.textContent('[data-testid="attention-more"] summary')).replace(/\s+/g,' ').trim();
        ok(total<=4 || new RegExp('^Ver todos \\('+total+'\\)').test(sum), `botão "${sum}"`);
        await p.click('[data-testid="attention-more"] summary');
        ok(await p.locator('[data-testid="attention-more"] [data-testid="attention-item"]').first().isVisible(), '"Ver todos" abre os demais itens');
        const noShow=p.locator('[data-testid="attention-action-no_show_follow_up"]').first();
        ok(/Remarcar consulta/.test(await noShow.textContent()), 'botão "Remarcar consulta" mantido');
        const draft=p.locator('[data-testid^="attention-action-draft_plan"]').first();
        ok(/Revisar plano/.test(await draft.textContent()), 'botão "Revisar plano(s)" mantido');
        console.log('== links do painel respondem 200');
        const hrefs=[...new Set(await p.$$eval('.dash a[href^="/"]',ls=>ls.map(a=>a.getAttribute('href'))))].filter(u=>!/sair|logout/.test(u));
        const bad=[]; for (const u of hrefs) { const rr=await ctx.request.get(B+u,{maxRedirects:5}); if (rr.status()!==200) bad.push(u+' '+rr.status()); }
        ok(bad.length===0, `${hrefs.length} links internos do painel com 200`+(bad.length?': '+bad.join(', '):''));
        const atender=p.locator('[data-testid="dash-today-item"] a.btn-primary').first();
        if (await atender.count()) { await atender.click(); await p.waitForLoadState(); ok(/\/consulta\/\d+/.test(p.url()), '"Atender" abre a consulta '+new URL(p.url()).pathname); }
        else ok(false, 'sem consulta "Atender" hoje no cenário');
      }
      ok(errs.length===0, `${tag}: sem erro de JS/console`+(errs[0]?': '+errs[0].slice(0,120):''));
      await ctx.close();
    }
    await b.close();
  }

  console.log('== negativo: clínica B (sem base anterior) e acesso sem login');
  const b=await chromium.launch();
  const cb=await b.newContext({viewport:{width:1440,height:900}}); const pb=await cb.newPage();
  await pb.goto(B+'/__teste_login?u=2&c=2&v=0&to=/dashboard');
  const tonesB=await pb.$$eval('[data-testid="dash-metrics"] [data-testid="dash-trend"], [data-testid="dash-week-range"] ~ [data-testid="dash-trend"]',ls=>ls.map(l=>l.dataset.tone+':'+l.textContent.replace(/\s+/g,' ').trim()));
  const todayB=cnt(`SELECT COUNT(*) FROM appointments WHERE clinic_id=2 AND scheduled_at>='${today} 00:00:00' AND scheduled_at<date('${today}','+1 day')||' 00:00:00'`);
  ok(tonesB.length>=4 && tonesB.every(t=>t.startsWith('neutral:')), 'clínica B: todos os selos neutros, nenhuma seta inventada ['+tonesB.join(' | ')+']');
  ok(todayB>0 ? /sem base anterior/.test(tonesB[0]) : /sem variação/.test(tonesB[0]), `clínica B: hoje ${todayB} sem consulta ontem → selo sem base`);
  ok(/Sem dados/.test(await pb.textContent('[data-testid="average-adherence-value"]')), 'clínica B: adesão "Sem dados" (sem check-in), sem número inventado');
  const htmlB=await pb.content();
  const nameA=sq("SELECT name FROM patients WHERE clinic_id=1 AND name LIKE '%TESTE Ribeiro%' LIMIT 1");
  ok(nameA && !htmlB.includes(nameA), `isolamento: paciente da clínica A ("${nameA}") não aparece para a clínica B`);
  ok(/Nutri TESTE B|Boa|Bom/.test(await pb.textContent('h1')), 'clínica B: saudação com o próprio usuário');
  const anon=await b.newContext(); const pa=await anon.newPage();
  const ra=await pa.goto(B+'/dashboard');
  ok(!/\/dashboard$/.test(new URL(pa.url()).pathname) && await pa.locator('[data-testid="dashboard-v5456"]').count()===0, `sem login: /dashboard não abre o painel (foi para ${new URL(pa.url()).pathname}, ${ra.status()})`);
  await b.close();
  const n=res.filter(x=>!x).length; console.log(`\n${res.length-n} PASS, ${n} FAIL`); process.exit(n?1:0);
})().catch(e=>{console.error(e);console.log('\n0 PASS, 1 FAIL');process.exit(1);});
