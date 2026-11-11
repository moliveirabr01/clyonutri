// v54.21 Materiais educativos (estilo WebDiet): a nutricionista sobe um PDF, envia para a paciente, a paciente vê e baixa
// no portal; formato conferido pelo conteúdo, isolamento entre clínicas e entre pacientes, revogação, celular. Confere o banco.
const { chromium } = require('playwright');
const { execSync } = require('child_process');
const res=[]; const ok=(c,l)=>{res.push(!!c);console.log((c?'  PASS  ':'  FAIL  ')+l);};
const PDF=Buffer.from("%PDF-1.4\n1 0 obj<</Type/Catalog/Pages 2 0 R>>endobj\n2 0 obj<</Type/Pages/Kids[3 0 R]/Count 1>>endobj\n3 0 obj<</Type/Page/Parent 2 0 R/MediaBox[0 0 200 200]>>endobj\n% material TESTE\ntrailer<</Root 1 0 R>>\n%%EOF\n");
(async()=>{
  const B=process.argv[2], DB=process.argv[3];
  const sq=q=>execSync(`php ${process.env.E2E_SQ} ${DB} "${q}"`).toString().trim();
  const b=await chromium.launch(); const errs=[]; const csp=[];
  const as=async(u,c,to,vw)=>{const ctx=await b.newContext({viewport:vw||{width:1280,height:900}}); const p=await ctx.newPage();
    p.on('pageerror',e=>errs.push(e.message)); p.on('console',m=>{if(/Content Security Policy/i.test(m.text()))csp.push(m.text())});
    await p.goto(B+`/__teste_login?u=${u}&c=${c}&v=0&to=${encodeURIComponent(to)}`); return p;};
  const flash=p=>p.locator('[data-testid="materials-flash"]').innerText().catch(()=>'');

  console.log('== Biblioteca da clínica');
  const n=await as(1,1,'/dashboard');
  const nav=n.locator('a.app-nav__item[href="/materiais"]');
  ok(await nav.count()===1, 'menu "Materiais" na barra lateral');
  await nav.click(); await n.waitForURL(/\/materiais$/);
  ok(await n.locator('[data-testid="materials-empty"]').isVisible() && await n.locator('[data-testid="materials-upload"]').evaluate(e=>e.open), 'biblioteca vazia já abre o formulário de envio');

  const up=async(name,mime,buf,title,cat)=>{await n.setInputFiles('[data-testid="materials-upload"] input[type="file"]',{name,mimeType:mime,buffer:buf});
    await n.fill('[data-testid="materials-upload"] [name="title"]',title); await n.selectOption('[data-testid="materials-upload"] [name="category"]',cat);
    await Promise.all([n.waitForLoadState('load'), n.waitForURL(/\/materiais/), n.click('[data-testid="materials-upload"] button[type="submit"]')]);};
  await up('falso.pdf','application/pdf',Buffer.from('<html><body>TESTE</body></html>'),'Falso TESTE','outros');
  ok(/Formato não aceito/.test(await flash(n)) && sq('SELECT COUNT(*) FROM educational_materials')==='0', 'HTML com nome .pdf: recusado pelo conteúdo');
  ok(await n.locator('[data-testid="materials-upload"] [name="title"]').inputValue()==='Falso TESTE', 'título digitado volta no formulário');
  await n.locator('[data-testid="materials-upload"] [name="title"]').fill('');
  await up('guia-teste.pdf','application/pdf',PDF,'Guia de hidratação TESTE','orientacao');
  const mid=sq('SELECT id FROM educational_materials ORDER BY id DESC LIMIT 1');
  ok(new URL(n.url()).pathname===`/materiais/${mid}` && /Agora escolha/.test(await flash(n)), 'PDF aceito: abre o material para enviar');
  ok(sq(`SELECT mime_type||'|'||length(sha256)||'|'||status FROM educational_materials WHERE id=${mid}`)==='application/pdf|64|active', 'banco: PDF, SHA-256, ativo');
  const hd=await n.evaluate(async(u)=>{const r=await fetch(u);return {s:r.status,t:r.headers.get('content-type'),x:r.headers.get('x-frame-options'),d:r.headers.get('content-disposition'),n:(await r.arrayBuffer()).byteLength};},`/materiais/${mid}/arquivo`);
  ok(hd.s===200 && /pdf/.test(hd.t) && hd.x==='SAMEORIGIN' && /^inline/.test(hd.d||'') && hd.n===PDF.length, 'prévia: PDF inteiro, só enquadrável no próprio site');
  const dl=await n.evaluate(async(u)=>{const r=await fetch(u);return {x:r.headers.get('x-frame-options'),d:r.headers.get('content-disposition')};},`/materiais/${mid}/arquivo?baixar=1`);
  ok(/^attachment/.test(dl.d||'') && dl.x==='DENY', 'baixar: anexo');

  console.log('== Envio para a paciente');
  await n.fill('[data-mat-filter]','dois');
  const vis=await n.locator('[data-mat-list] label[data-name]').evaluateAll(ls=>ls.filter(l=>!l.hidden).map(l=>l.textContent.trim()));
  ok(vis.length===1 && /Paciente TESTE Dois/.test(vis[0]), 'busca filtra a lista de pacientes');
  ok(!/Clinica B/.test(await n.locator('[data-mat-list]').innerText()), 'lista não mostra paciente de outra clínica');
  await n.check('[data-mat-list] input[value="2"]'); await n.fill('[data-testid="material-share"] [name="note"]','Recado TESTE: leia antes da consulta.');
  await Promise.all([n.waitForURL(new RegExp(`/materiais/${mid}$`)), n.click('[data-testid="material-send"]')]);
  ok(/enviado para 1 paciente/.test(await flash(n)) && await n.locator('[data-testid="material-share-row"][data-patient="2"]').count()===1, 'enviado: aparece em "Quem recebeu"');
  ok(/Ainda não abriu/.test(await n.locator('[data-testid="material-share-row"][data-patient="2"]').innerText()), 'situação "Ainda não abriu"');
  const forged=await n.evaluate(async(u)=>{const t=document.querySelector('input[name="_csrf_token"]').value;const r=await fetch(u,{method:'POST',body:new URLSearchParams({_csrf_token:t,'patients[]':'3'}),redirect:'manual'});return r.status;},`/materiais/${mid}/enviar`);
  ok(sq(`SELECT COUNT(*) FROM educational_material_shares WHERE patient_id=3`)==='0', 'envio forjado para paciente de outra clínica: nada gravado (HTTP '+forged+')');

  console.log('== Atalho pelo perfil do paciente');
  await n.goto(B+'/pacientes/2');
  ok(await n.locator('[data-testid="patient-send-material"]').count()===1, 'perfil tem "Enviar material educativo"');
  await n.goto(B+'/materiais?para=2');
  ok(/Paciente TESTE Dois/.test(await n.locator('[data-testid="materials-target"]').innerText()) && await n.locator('[data-testid="material-already-sent"]').count()===1 && await n.locator('[data-testid="material-quick-send"]').count()===0, 'modo "enviar para" a paciente: o que ela já tem aparece como "Já enviado"');

  console.log('== Portal da paciente');
  const pc=await b.newContext({viewport:{width:375,height:812}}); const pp=await pc.newPage(); pp.on('pageerror',e=>errs.push('portal: '+e.message)); pp.on('console',m=>{if(/Content Security Policy/i.test(m.text()))csp.push('portal: '+m.text())});
  await pp.goto(B+'/paciente/app');
  const login=await pp.evaluate(async()=>{const t=document.querySelector('form[action="/paciente/app/login"] input[name="_csrf_token"]')?.value||'';const fd=new URLSearchParams({_csrf_token:t,login:'paciente.teste@teste.invalid',password:'Teste-Paciente-123'});
    const r=await fetch('/paciente/app/login',{method:'POST',body:fd,headers:{'X-ClyoNutri-Web':'1'}});return {s:r.status,h:await r.text()};});
  ok(login.s===200, 'paciente entra no portal');
  ok(/data-testid="patient-materials-link"/.test(login.h) || /accept/.test(login.h), 'área do paciente tem o link "Meus materiais"'+(/patient-materials-link/.test(login.h)?'':' (tela de aceite antes)'));
  await pp.goto(B+'/paciente/materiais');
  const card=pp.locator('[data-testid="patient-material"]');
  ok(await card.count()===1 && /Guia de hidratação TESTE/.test(await card.innerText()) && /Recado TESTE/.test(await card.innerText()) && /Novo/.test(await card.innerText()), 'vê o material, o recado e o selo "Novo"');
  ok(await pp.evaluate(()=>document.documentElement.scrollWidth<=window.innerWidth+1), 'cabe em 375 px sem rolagem lateral');
  const [download]=await Promise.all([pp.waitForEvent('download'), pp.click('[data-testid="patient-material-download"]')]);
  ok(/guia-teste\.pdf$/.test(download.suggestedFilename()), 'baixa o PDF ('+download.suggestedFilename()+')');
  ok(sq(`SELECT first_opened_at IS NOT NULL FROM educational_material_shares WHERE patient_id=2`)==='1', 'banco: primeira abertura registrada');
  const api=await pp.evaluate(async()=>{const r=await fetch('/api/v1/patient/materials');return {s:r.status,j:await r.json().catch(()=>null)};});
  ok(api.s===200 && api.j.materials.length===1 && api.j.materials[0].title==='Guia de hidratação TESTE', 'API do app lista o material');
  const nope=await pp.evaluate(async()=>(await fetch('/api/v1/patient/materials/99999')).status);
  ok(nope===404, 'material inexistente ou de outro paciente: 404');

  console.log('== Clínica B');
  const cb=await as(2,2,'/materiais');
  ok(!/Guia de hidratação TESTE/.test(await cb.locator('body').innerText()), 'biblioteca da clínica B não mostra o material da A');
  const bf=await cb.evaluate(async(u)=>(await fetch(u)).status,`/materiais/${mid}/arquivo`);
  const bp=await cb.goto(B+`/materiais/${mid}`);
  ok(bf===404 && bp.status()===404, 'clínica B: arquivo e página respondem 404');

  console.log('== Revogar');
  await n.goto(B+`/materiais/${mid}`);
  await Promise.all([n.waitForURL(new RegExp(`/materiais/${mid}$`)), n.click('[data-testid="material-share-row"][data-patient="2"] [data-testid="material-revoke"]')]);
  ok(/Revogado em/.test(await n.locator('[data-testid="material-share-row"][data-patient="2"]').innerText()), 'histórico mostra "Revogado em"');
  await pp.goto(B+'/paciente/materiais');
  const after=await pp.evaluate(async(id)=>(await fetch('/api/v1/patient/materials/'+id)).status,mid);
  ok(await pp.locator('[data-testid="patient-materials-empty"]').isVisible() && after===404, 'paciente deixa de ver e de baixar');

  console.log('== Celular (clínica)');
  const m=await as(1,1,'/materiais',{width:375,height:812});
  ok(await m.evaluate(()=>document.documentElement.scrollWidth<=window.innerWidth+1), 'biblioteca cabe em 375 px');
  await m.goto(B+`/materiais/${mid}`);
  ok(await m.evaluate(()=>document.documentElement.scrollWidth<=window.innerWidth+1), 'detalhe cabe em 375 px');

  ok(errs.length===0, 'sem erro de JavaScript'+(errs.length?': '+errs.slice(0,3).join(' | '):''));
  ok(csp.length===0, 'sem bloqueio de CSP'+(csp.length?': '+csp.slice(0,2).join(' | '):''));
  await b.close();
  const f=res.filter(x=>!x).length; console.log(`\n${res.length-f} PASS, ${f} FAIL`); process.exit(f?1:0);
})();
