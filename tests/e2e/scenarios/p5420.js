// NI2-663 Clyo Academy. v54.22: a clínica só consome (pesquisa, lê e favorita). Acervo, fichas e publicações são da equipe
// Clyo Nutri no console, com quatro olhos; rascunho não aparece para a clínica; rotas antigas de escrita da clínica dão 403.
// Metadados de TESTE (DOI com prefixo 10.99999, inexistente). Confere o banco.
const { chromium } = require('playwright');
const { execSync } = require('child_process');
const res=[]; const ok=(c,l)=>{res.push(!!c);console.log((c?'  PASS  ':'  FAIL  ')+l);};
(async()=>{
  const B=process.argv[2], DB=process.argv[3];
  const sq=q=>execSync(`php ${process.env.E2E_SQ} ${DB} "${q}"`).toString().trim();
  const b=await chromium.launch(); const errs=[]; const csp=[];
  const as=async(u,c,to,vw)=>{const ctx=await b.newContext({viewport:vw||{width:1280,height:900}}); await ctx.grantPermissions(['clipboard-read','clipboard-write'],{origin:B}); const p=await ctx.newPage();
    p.on('pageerror',e=>errs.push(e.message)); p.on('console',m=>{if(/Content Security Policy/i.test(m.text()))csp.push(m.text())}); p.on('dialog',d=>d.accept());
    await p.goto(B+`/__teste_login?u=${u}&c=${c}&v=0&to=${encodeURIComponent(to)}`); return p;};
  const post=(p,url,data)=>p.evaluate(async([u,d])=>{const t=document.querySelector('input[name="_csrf_token"]')?.value||'';const r=await fetch(u,{method:'POST',body:new URLSearchParams({_csrf_token:t,...d}),redirect:'manual'});return r.status;},[url,data]);
  const fill=async(p,form,obj)=>{for(const [k,v] of Object.entries(obj)){const el=p.locator(`${form} [name="${k}"]`); if((await el.evaluate(e=>e.tagName))==='SELECT') await el.selectOption(v); else await el.fill(v);}};

  console.log('== Chave desligada');
  const n=await as(1,1,'/dashboard');
  ok(await n.locator('a.app-nav__item[href="/academy"]').count()===0, 'menu sem Clyo Academy');
  const st=await n.evaluate(async()=>(await fetch('/academy')).status);
  const api=await n.evaluate(async()=>(await fetch('/api/v1/academy/search?q=x')).status);
  ok(st===404 && api===404, 'páginas e API respondem 404 ('+st+'/'+api+')');
  ok(sq("SELECT COUNT(*) FROM academy_articles")==='0', 'nenhum artigo gravado pelo código');

  console.log('== Console: acervo com proveniência');
  const a=await as(3,3,'/admin/academy');
  const art={title:'Estudo TESTE de fibras e glicemia',authors:'Silva, Ana; Souza, Bruno',journal:'Revista TESTE',publication_year:'2024',doi:'',url:'https://doi.org/10.99999/e2e.1',study_design:'rct',access_type:'publisher_link',source:'crossref',condition_tags:'diabetes',abstract:'Resumo TESTE'};
  await fill(a,'[data-testid="academy-article-form"]',art); await a.click('[data-testid="academy-article-form"] button[type="submit"]'); await a.waitForLoadState();
  ok(/DOI ou PMID/.test(await a.locator('[data-testid="academy-admin-flash"]').innerText()) && sq("SELECT COUNT(*) FROM academy_articles")==='0', 'sem DOI/PMID: recusado com motivo');
  ok(await a.locator('[data-testid="academy-article-form"] [name="title"]').inputValue()===art.title, 'o que foi digitado volta no formulário');
  await a.fill('[data-testid="academy-article-form"] [name="doi"]','10.99999/e2e.1'); await a.click('[data-testid="academy-article-form"] button[type="submit"]'); await a.waitForLoadState();
  ok(/licença/.test(await a.locator('[data-testid="academy-admin-flash"]').innerText()), 'resumo sem licença: recusado');
  await a.fill('[data-testid="academy-article-form"] [name="abstract"]',''); await a.click('[data-testid="academy-article-form"] button[type="submit"]'); await a.waitForLoadState();
  const aid=sq("SELECT id FROM academy_articles WHERE doi='10.99999/e2e.1'");
  ok(aid!=='' && /\/admin\/academy\/artigos\/\d+$/.test(a.url()) && sq(`SELECT created_by_user_id||'|'||source||'|'||(retrieved_at IS NOT NULL)||'|'||status FROM academy_articles WHERE id=${aid}`)==='3|crossref|1|draft', 'artigo cadastrado como rascunho, com autor, fonte e data de conferência (banco)');

  console.log('== Ficha: curador escreve, outra pessoa aprova');
  await a.click('summary:has-text("Escrever nova ficha")');
  await fill(a,'[data-testid="academy-sheet-form"]',{curator_name:'Editora TESTE',curator_crn:'CRN-3 12345',population:'Adultos TESTE',intervention:'Fibra TESTE',comparator:'Controle TESTE',outcomes:'Desfecho TESTE',methodological_limitations:'Limitação TESTE',clinical_applicability:'Aplicabilidade TESTE',evidence_certainty:'moderate'});
  await a.click('[data-testid="academy-sheet-form"] button[type="submit"]'); await a.waitForLoadState();
  const sid=sq(`SELECT id FROM academy_evidence_sheets WHERE article_id=${aid}`);
  ok(sq(`SELECT status FROM academy_evidence_sheets WHERE id=${sid}`)==='draft', 'ficha criada como rascunho (banco)');
  await a.goto(B+'/admin/academy'); const card=a.locator(`[data-testid="academy-sheet"][data-id="${sid}"]`); await card.locator('summary').click();
  await card.locator('button:has-text("Enviar para revisão")').click(); await a.waitForLoadState();
  await a.locator(`[data-testid="academy-sheet"][data-id="${sid}"] summary`).click();
  ok(await a.locator(`[data-testid="academy-sheet"][data-id="${sid}"] [data-testid="academy-own-sheet"]`).count()===1 && await a.locator(`[data-testid="academy-sheet"][data-id="${sid}"] [data-testid="academy-sheet-approve"]`).count()===0, 'curadora não vê o botão de aprovar a própria ficha');
  const self=await post(a,`/admin/academy/ficha/${sid}/ficha-aprovar`,{approver_name:'Editora',approver_crn:'CRN-3 12345',confirm_review:'1'});
  ok(sq(`SELECT status FROM academy_evidence_sheets WHERE id=${sid}`)==='in_review', 'aprovação forçada pela curadora recusada no servidor (HTTP '+self+')');
  const r=await as(4,3,'/admin/academy'); const rc=r.locator(`[data-testid="academy-sheet"][data-id="${sid}"]`); await rc.locator('summary').click();
  await rc.locator('[name="approver_name"]').fill('Revisor TESTE'); await rc.locator('[name="approver_crn"]').fill('CRN-3 54321'); await rc.locator('[name="confirm_review"]').check();
  await rc.locator('button:has-text("Aprovar e publicar ficha")').click(); await r.waitForLoadState();
  ok(sq(`SELECT status||'|'||approved_by_user_id||'|'||approved_by_crn FROM academy_evidence_sheets WHERE id=${sid}`)==='published|4|CRN-3 54321' && sq(`SELECT has_evidence_sheet FROM academy_articles WHERE id=${aid}`)==='1', 'ficha publicada pelo revisor, com CRN (banco)');

  console.log('== Chave ligada: biblioteca da clínica');
  await r.goto(B+'/admin/configuracoes'); await r.locator('[data-testid="flag-academy"] input[type="checkbox"]').check(); await r.click('button:has-text("Salvar parâmetros")'); await r.waitForLoadState();
  ok(sq("SELECT setting_value FROM platform_settings WHERE setting_key='feature_flag_academy'")==='1', 'chave ligada pelo console (banco)');
  await n.goto(B+'/academy');
  ok(await n.locator('a.app-nav__item[href="/academy"][aria-current="page"]').count()===1 && !/Estudo TESTE de fibras/.test(await n.locator('main').innerText()), 'menu ligado; artigo em rascunho fora da biblioteca');
  ok(await n.evaluate(async(u)=>(await fetch(u)).status,`/academy/artigo/${aid}`)===404, 'rascunho: 404 para a clínica');
  await a.goto(B+`/admin/academy/artigos/${aid}`); await a.click('[data-testid="academy-article-publish"] button'); await a.waitForLoadState();
  ok(sq(`SELECT status FROM academy_articles WHERE id=${aid}`)==='published' && await a.locator('[data-testid="academy-article-status"]').count()===1, 'equipe publica o artigo no console (banco)');
  await n.goto(B+'/academy');
  ok(/Estudo TESTE de fibras/.test(await n.locator('main').innerText()), 'publicado: aparece na biblioteca da clínica');
  await n.goto(B+'/academy?q=fibras'); ok(/Estudo TESTE de fibras/.test(await n.locator('main').innerText()), 'clínica pesquisa e encontra');
  await n.goto(B+`/academy/artigo/${aid}`); const body=await n.locator('main').innerText();
  ok(/aprovada por Revisor TESTE \(CRN-3 54321\)/.test(body) && /Certeza moderada/i.test(body), 'ficha publicada com curadoria, aprovação e certeza');
  ok(!/Resumo \(/.test(body) && /Conferido na fonte em/.test(body), 'sem resumo (sem licença); proveniência visível');
  await n.locator('button[data-copy]').first().click(); await n.waitForTimeout(200);
  ok(/Copiado|Não foi possível/.test(await n.locator('button[data-copy]').first().innerText()), 'copiar citação funciona sem atributo onclick');
  await n.goto(B+'/academy?page=1'); await Promise.all([n.waitForResponse(r=>r.request().method()==='POST'&&/favoritar/.test(r.url())), n.locator(`form[action="/academy/artigo/${aid}/favoritar"] button`).first().click()]); await n.waitForLoadState(); await n.waitForSelector('input[name="_csrf_token"]', {state:'attached'});
  ok(/\/academy\?page=1$/.test(n.url()) && sq(`SELECT COUNT(*) FROM academy_favorites WHERE user_id=1 AND article_id=${aid}`)==='1', 'favoritar salva e volta para a mesma página');
  await n.goto(B+'/academy/colecao'); ok(/Estudo TESTE de fibras/.test(await n.locator('main').innerText()), 'favorito aparece em Minha coleção');
  await n.goto(B+'/academy?page=1');
  const hdr=await n.request.post(B+`/academy/artigo/${aid}/favoritar`,{form:{_csrf_token:await n.locator('input[name="_csrf_token"]').first().inputValue(),back:'https://exemplo.invalid/x'},maxRedirects:0});
  ok(!/exemplo\.invalid/.test(hdr.headers()['location']||''), 'volta para endereço externo é ignorada ('+(hdr.headers()['location']||'')+')');
  ok(sq(`SELECT COUNT(*) FROM academy_favorites WHERE user_id=1 AND article_id=${aid}`)==='0', 'favoritar de novo desfaz o favorito (banco)');

  const o=await as(2,2,'/academy/colecao');
  ok(!/Estudo TESTE de fibras/.test(await o.locator('main').innerText()), 'favorito da clínica 1 não aparece para a clínica 2');

  console.log('== Clínica não escreve');
  await n.goto(B+'/academy/publicacoes');
  ok(await n.locator('a[href="/academy/publicacoes/nova"]').count()===0 && await n.locator('[data-testid="publication-form"]').count()===0 && await n.locator('[data-testid="publications-empty"]').count()===1, 'sem botão de nova publicação; aviso de lista vazia');
  const nova=await n.goto(B+'/academy/publicacoes/nova');
  ok(nova.status()===403 && await n.locator('[data-testid="academy-read-only"]').count()===1, 'abrir o formulário antigo: 403 com aviso de somente leitura');
  await n.goto(B+'/academy/publicacoes');
  const cr=await post(n,'/academy/publicacoes',{title:'Texto TESTE da clínica',category:'practical_review',summary:'x',body_markdown:'x',references_text:'x',conflict_of_interest_disclosure:'x',save_action:'submit'});
  ok(cr===403 && sq("SELECT COUNT(*) FROM academy_publications")==='0', 'POST de publicação pela clínica: 403 e nada gravado (HTTP '+cr+')');

  console.log('== Publicação escrita pela equipe');
  await a.goto(B+'/admin/academy'); await a.click('[data-testid="academy-new-publication"]'); await a.waitForLoadState();
  const pub={author_name:'Autora TESTE',author_crn:'CRN-3 12345',title:'Síntese TESTE de fibras',category:'practical_review',summary:'Resumo TESTE',body_markdown:'Paciente com diabetes tipo 2 TESTE. CPF 123.456.789-09',references_text:'Referência TESTE doi:10.99999/e2e.1',conflict_of_interest_disclosure:'Sem conflitos (TESTE)'};
  await fill(a,'[data-testid="academy-publication-form"]',pub); await a.click('[data-testid="academy-publication-form"] button[value="submit"]'); await a.waitForLoadState();
  ok(/CPF/.test(await a.locator('[data-testid="academy-admin-flash"]').innerText()) && sq("SELECT COUNT(*) FROM academy_publications")==='0', 'texto com CPF recusado, nada gravado');
  ok((await a.locator('[data-testid="academy-publication-form"] [name="body_markdown"]').inputValue()).includes('Paciente com diabetes tipo 2'), 'o texto digitado volta no formulário');
  await a.fill('[data-testid="academy-publication-form"] [name="body_markdown"]','Paciente com diabetes tipo 2 TESTE, sem identificação.'); await a.click('[data-testid="academy-publication-form"] button[value="submit"]'); await a.waitForLoadState();
  const pid=sq("SELECT id FROM academy_publications ORDER BY id DESC LIMIT 1");
  ok(sq(`SELECT status||'|'||author_crn||'|'||clinic_id||'|'||author_user_id FROM academy_publications WHERE id=${pid}`)==='submitted|CRN-3 12345|0|3', 'enviada para revisão com assinatura e CRN, sem clínica (banco)');
  ok(await o.evaluate(async(u)=>(await fetch(u)).status,`/academy/publicacoes/${pid}`)===404, 'em revisão: clínica não lê');
  await a.goto(B+'/admin/academy');
  const selfp=await post(a,`/admin/academy/publicacao/${pid}/publicacao-revisar`,{review_action:'approve'});
  ok(sq(`SELECT status FROM academy_publications WHERE id=${pid}`)==='submitted', 'quem escreveu não aprova o próprio texto (HTTP '+selfp+')');
  await r.goto(B+'/admin/academy'); const q=r.locator(`[data-testid="academy-submitted"][data-id="${pid}"]`); await q.locator('summary').click();
  await q.locator('button[value="reject"]').click(); await r.waitForLoadState();
  ok(/parecer/.test(await r.locator('[data-testid="academy-admin-flash"]').innerText()) && sq(`SELECT status FROM academy_publications WHERE id=${pid}`)==='submitted', 'devolver sem parecer: recusado');
  const q2=r.locator(`[data-testid="academy-submitted"][data-id="${pid}"]`); await q2.locator('summary').click(); await q2.locator('button[value="approve"]').click(); await r.waitForLoadState();
  ok(sq(`SELECT status||'|'||reviewer_user_id FROM academy_publications WHERE id=${pid}`)==='published|4', 'aprovada no console por outra pessoa (banco)');
  ok(await o.evaluate(async(u)=>(await fetch(u)).status,`/academy/publicacoes/${pid}`)===200, 'publicada: todas as clínicas leem');
  await n.goto(B+'/academy/publicacoes'); ok(/Síntese TESTE de fibras/.test(await n.locator('main').innerText()), 'aparece na lista de publicações da clínica');
  const ed=await post(o,`/academy/publicacoes/${pid}`,{title:'Invasão TESTE',category:'practical_review',summary:'x',body_markdown:'x',references_text:'x',conflict_of_interest_disclosure:'x'});
  const old=await post(o,`/academy/publicacoes/${pid}/status`,{review_action:'approve'});
  ok(ed===403 && sq(`SELECT title FROM academy_publications WHERE id=${pid}`)==='Síntese TESTE de fibras', 'clínica não altera publicação (HTTP '+ed+'; rota antiga de status '+old+')');
  await r.goto(B+`/admin/academy/publicacoes/${pid}`); await r.fill('[data-testid="academy-publication-archive"] [name="reason"]','Arquivada no TESTE'); await r.click('[data-testid="academy-publication-archive"] button'); await r.waitForLoadState();
  ok(sq(`SELECT status FROM academy_publications WHERE id=${pid}`)==='archived' && await o.evaluate(async(u)=>(await fetch(u)).status,`/academy/publicacoes/${pid}`)===404, 'arquivada pela equipe: sai do ar para as clínicas');
  ok(+sq("SELECT COUNT(*) FROM audit_logs WHERE action LIKE 'ACADEMY_%'")>=8 && +sq("SELECT COUNT(*) FROM audit_logs WHERE action='ACADEMY_AUTHORING_DENIED'")>=2 && sq("SELECT COUNT(*) FROM audit_logs WHERE action='ACADEMY_ARTICLE_PUBLISHED'")==='1', 'ações da equipe e recusas da clínica auditadas');

  console.log('== Larguras');
  for (const w of [375,656,1440]) { const p=await as(1,1,`/academy/artigo/${aid}`,{width:w,height:900}); const ov=await p.evaluate(()=>document.documentElement.scrollWidth-document.documentElement.clientWidth); ok(ov<=1, w+'px sem rolagem lateral no artigo ('+ov+')'); await p.context().close(); }
  ok(errs.length===0 && csp.length===0, 'sem erro de JavaScript nem de CSP ('+errs.concat(csp).join(' | ').slice(0,240)+')');
  await b.close();
  console.log(`\n${res.filter(Boolean).length} PASS, ${res.filter(x=>!x).length} FAIL`); process.exit(res.every(Boolean)?0:1);
})().catch(e=>{console.error(e);process.exit(1);});
