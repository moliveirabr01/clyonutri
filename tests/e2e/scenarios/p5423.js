// v54.23 Lâminas para pacientes na Clyo Academy. A equipe Clyo Nutri sobe o PDF e a prévia no console (rascunho) e publica;
// a clínica busca pelo topo da tela, abre a lâmina, vê a prévia, abre e baixa o PDF e favorita. Rascunho dá 404; escrita da clínica, 403.
// Arquivos sintéticos de TESTE gerados aqui. Confere o banco.
const { chromium } = require('playwright');
const { execSync } = require('child_process');
const fs = require('fs'); const path = require('path');
const res=[]; const ok=(c,l)=>{res.push(!!c);console.log((c?'  PASS  ':'  FAIL  ')+l);};
(async()=>{
  const B=process.argv[2], DB=process.argv[3];
  const sq=q=>execSync(`php ${process.env.E2E_SQ} ${DB} "${q}"`).toString().trim();
  const dir=fs.mkdtempSync(path.join(process.env.E2E_OUT||'/tmp','p5423-'));
  const pdf=path.join(dir,'lamina-teste.pdf'), png=path.join(dir,'lamina-teste.png');
  fs.writeFileSync(pdf,"%PDF-1.4\n1 0 obj<</Type/Catalog/Pages 2 0 R>>endobj\n2 0 obj<</Type/Pages/Kids[3 0 R]/Count 1>>endobj\n3 0 obj<</Type/Page/Parent 2 0 R/MediaBox[0 0 842 595]>>endobj\n% lamina TESTE e2e\ntrailer<</Root 1 0 R>>\n%%EOF\n");
  execSync(`php -r '$i=imagecreatetruecolor(420,297);imagefill($i,0,0,imagecolorallocate($i,230,244,236));imagestring($i,5,20,20,"Lamina TESTE",imagecolorallocate($i,20,80,50));imagepng($i,$argv[1]);' ${png}`);
  const b=await chromium.launch(); const errs=[]; const csp=[];
  const as=async(u,c,to,vw)=>{const ctx=await b.newContext({viewport:vw||{width:1280,height:900},acceptDownloads:true}); const p=await ctx.newPage();
    p.on('pageerror',e=>errs.push(e.message)); p.on('console',m=>{if(/Content Security Policy/i.test(m.text()))csp.push(m.text())}); p.on('dialog',d=>d.accept());
    await p.goto(B+`/__teste_login?u=${u}&c=${c}&v=0&to=${encodeURIComponent(to)}`); return p;};
  const post=(p,url,data)=>p.evaluate(async([u,d])=>{const t=document.querySelector('input[name="_csrf_token"]')?.value||'';const r=await fetch(u,{method:'POST',body:new URLSearchParams({_csrf_token:t,...d}),redirect:'manual'});return r.status;},[url,data]);
  const head=(p,u)=>p.evaluate(async(u)=>{const r=await fetch(u);const t=await r.arrayBuffer();return {s:r.status,ct:r.headers.get('content-type')||'',cd:r.headers.get('content-disposition')||'',nos:r.headers.get('x-content-type-options')||'',len:t.byteLength,start:new TextDecoder().decode(t.slice(0,5))};},u);

  console.log('== Console: equipe sobe a lâmina');
  const a=await as(3,3,'/admin/academy');
  ok(await a.locator('[data-testid="academy-handouts"]').count()===1, 'console mostra a seção de lâminas');
  await a.click('[data-testid="academy-new-handout"]'); await a.waitForLoadState();
  const F='[data-testid="academy-handout-form"]';
  await a.fill(`${F} [name="title"]`,'Lâmina TESTE sal e rótulos');
  await a.fill(`${F} [name="tags"]`,'sal, rótulos, teste');
  await a.fill(`${F} [name="summary"]`,'Como achar o sódio no rótulo e reduzir o sal no dia a dia (TESTE).');
  await a.fill(`${F} [name="source_citation"]`,'BRASIL. Ministério da Saúde. Guia alimentar para a população brasileira. 2. ed. 2014 (TESTE). Disponível em: https://bvsms.saude.gov.br/bvs/publicacoes/guia_alimentar_populacao_brasileira_2ed_teste_endereco_longo.pdf');
  await a.setInputFiles('[data-testid="academy-handout-pdf"]',pdf);
  await a.setInputFiles('[data-testid="academy-handout-preview"]',png);
  await a.click('[data-testid="academy-handout-save"]'); await a.waitForLoadState();
  const hid=sq("SELECT id FROM academy_handouts WHERE slug='lamina-teste-sal-e-rotulos'");
  ok(hid!=='' && sq(`SELECT status||'|'||created_by_user_id||'|'||(preview_storage_name IS NOT NULL)||'|'||mime_type FROM academy_handouts WHERE id=${hid}`)==='draft|3|1|application/pdf', 'cadastrada como rascunho, com autor, prévia e PDF (banco)');
  ok(/rascunho/i.test(await a.locator('[data-testid="academy-admin-flash"]').innerText()), 'aviso de rascunho no console');
  const adminPdf=await head(a,`/admin/academy/laminas/${hid}/arquivo`);
  ok(adminPdf.s===200 && adminPdf.start==='%PDF-', 'equipe confere o PDF do rascunho');

  console.log('== Clínica: rascunho não aparece');
  const n=await as(1,1,'/academy');
  ok((await head(n,`/academy/laminas/${hid}`)).s===404 && (await head(n,`/academy/laminas/${hid}/arquivo`)).s===404 && (await head(n,`/admin/academy/laminas/${hid}/arquivo`)).s!==200, 'rascunho: 404 na página e no arquivo; arquivo do console fechado para a clínica');

  console.log('== Console: publica');
  await a.goto(B+`/admin/academy/laminas/${hid}`); await a.click('[data-testid="academy-handout-publish"]'); await a.waitForLoadState();
  ok(sq(`SELECT status||'|'||published_by_user_id FROM academy_handouts WHERE id=${hid}`)==='published|3', 'publicada pela equipe (banco)');

  console.log('== Clínica: busca no topo e abre a lâmina');
  await n.goto(B+'/dashboard');
  await n.fill('[data-testid="header-academy-search"] input[name="q"]','rótulo sódio'); await n.press('[data-testid="header-academy-search"] input[name="q"]','Enter'); await n.waitForLoadState();
  ok(/\/academy\?q=/.test(n.url()) && await n.locator('[data-testid="academy-handout-results"] [data-testid="academy-handout-card"]').count()===1, 'busca do topo leva à Academy e acha a lâmina');
  await Promise.all([n.waitForURL(/tipo=laminas/), n.selectOption('[data-testid="academy-type-filter"]','laminas')]); await n.waitForLoadState();
  ok(/tipo=laminas/.test(n.url()) && await n.locator('[data-testid="academy-handout-card"]').count()===1, 'filtro Tipo = Lâminas');
  await n.click('[data-testid="academy-handout-card"] h3 a'); await n.waitForLoadState();
  ok(new RegExp(`/academy/laminas/${hid}$`).test(n.url()) && /Lâmina TESTE sal e rótulos/.test(await n.locator('[data-testid="academy-handout-detail"] h1').innerText()), 'abre a página da lâmina');
  ok(await n.locator('[data-testid="academy-handout-preview"]').evaluate(i=>i.complete&&i.naturalWidth>0), 'prévia carrega');
  const open=await head(n,`/academy/laminas/${hid}/arquivo`);
  ok(open.s===200 && open.ct.startsWith('application/pdf') && /^inline/.test(open.cd) && open.nos==='nosniff' && open.start==='%PDF-', 'abrir: PDF inline com nosniff ('+open.ct+'; '+open.cd+')');
  const dl=await head(n,`/academy/laminas/${hid}/arquivo?baixar=1`);
  ok(dl.s===200 && /^attachment/.test(dl.cd) && /lamina-teste-sal-e-rotulos\.pdf/.test(dl.cd) && dl.nos==='nosniff', 'baixar: attachment com nome legível');
  const [d]=await Promise.all([n.waitForEvent('download'), n.click('[data-testid="academy-handout-download"]')]);
  ok(d.suggestedFilename()==='lamina-teste-sal-e-rotulos.pdf', 'botão Baixar PDF entrega o arquivo');
  const stored=sq(`SELECT storage_name FROM academy_handouts WHERE id=${hid}`);
  ok((await head(n,`/storage/app/academy/laminas/${stored}`)).s!==200 && (await head(n,`/${stored}`)).s!==200, 'arquivo não é servido pela pasta pública');
  ok(+sq(`SELECT COUNT(*) FROM audit_logs WHERE action IN ('ACADEMY_HANDOUT_OPENED','ACADEMY_HANDOUT_DOWNLOADED') AND resource_id='${hid}'`)>=3, 'abrir e baixar ficam na auditoria');

  console.log('== Clínica: favorita');
  await n.click('[data-testid="academy-handout-fav"]'); await n.waitForLoadState();
  ok(sq(`SELECT COUNT(*) FROM academy_handout_favorites WHERE handout_id=${hid} AND clinic_id=1 AND user_id=1`)==='1', 'favorito gravado para a clínica e o usuário (banco)');
  await n.goto(B+'/academy/colecao');
  ok(await n.locator('[data-testid="academy-collection-handouts"] [data-testid="academy-handout-card"]').count()===1, 'lâmina aparece em Minha coleção');
  const o=await as(2,2,'/academy/laminas');
  await o.goto(B+'/academy/colecao');
  ok(await o.locator('[data-testid="academy-collection-handouts"]').count()===0, 'outra clínica não vê o favorito');

  console.log('== Clínica: não escreve');
  const c1=await post(n,'/academy/laminas',{title:'Invasão TESTE'}); const c2=await post(n,`/academy/laminas/${hid}`,{title:'Invasão TESTE'});
  const c3=await post(n,'/admin/academy/lamina-salvar',{title:'Invasão TESTE'});
  ok(c1===403 && c2===403 && c3!==200 && sq("SELECT COUNT(*) FROM academy_handouts WHERE title='Invasão TESTE'")==='0', `clínica não cria nem edita (HTTP ${c1}/${c2}/console ${c3})`);
  ok(sq("SELECT COUNT(*) FROM academy_handouts")==='1' && sq(`SELECT title FROM academy_handouts WHERE id=${hid}`)==='Lâmina TESTE sal e rótulos', 'nada alterado (banco)');

  console.log('== Larguras');
  for (const w of [375,656,1440]) { const p=await as(1,1,`/academy/laminas/${hid}`,{width:w,height:900}); const ov=await p.evaluate(()=>document.documentElement.scrollWidth-document.documentElement.clientWidth); ok(ov<=1, w+'px sem rolagem lateral na lâmina ('+ov+')'); await p.context().close(); }
  for (const w of [375,1440]) { const p=await as(1,1,`/academy?tipo=laminas`,{width:w,height:900}); const ov=await p.evaluate(()=>document.documentElement.scrollWidth-document.documentElement.clientWidth); ok(ov<=1, w+'px sem rolagem lateral na lista ('+ov+')'); await p.context().close(); }
  ok(errs.length===0 && csp.length===0, 'sem erro de JavaScript nem de CSP ('+errs.concat(csp).join(' | ').slice(0,240)+')');
  await b.close();
  console.log(`\n${res.filter(Boolean).length} PASS, ${res.filter(x=>!x).length} FAIL`); process.exit(res.every(Boolean)?0:1);
})().catch(e=>{console.error(e);process.exit(1);});
