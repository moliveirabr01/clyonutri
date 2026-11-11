// NI2-662: jornadas que faltavam no portão — antropometria pelo perfil, emissão de exames e portal do paciente.
// Cada parte confere o banco, não só a tela.
const { chromium } = require('playwright');
const { execSync } = require('child_process');
const res=[]; const ok=(c,l)=>{res.push(!!c);console.log((c?'  PASS  ':'  FAIL  ')+l);};
(async()=>{
  const B=process.argv[2], DB=process.argv[3];
  const sq=q=>execSync(`php ${process.env.E2E_SQ} ${DB} "${q}"`).toString().trim();
  const b=await chromium.launch(); const ctx=await b.newContext({viewport:{width:1280,height:900}}); const p=await ctx.newPage();
  const csp=[]; const errs=[]; p.on('console',m=>{if(/Content Security Policy/i.test(m.text()))csp.push(m.text())}); p.on('pageerror',e=>errs.push(e.message)); p.on('dialog',d=>d.accept());
  await p.goto(B+'/__teste_login?u=1&c=1&v=0&to=/pacientes/2');

  console.log('== Antropometria pelo perfil (caso do paciente 9448, NI2-602)');
  const before1=sq("SELECT COUNT(*) FROM anthropometric_assessments WHERE patient_id=1"), before2=+sq("SELECT COUNT(*) FROM anthropometric_assessments WHERE patient_id=2");
  ok(sq("SELECT COUNT(*) FROM professionals WHERE id=1")==='1' && sq("SELECT COUNT(*) FROM patients WHERE id=1")==='1', 'cenário com IDs que coincidem: profissional 1 e paciente 1 existem');
  await p.click('[data-testid="patient-more"]'); await p.click('text=Nova avaliação física');
  const form=p.locator('[data-testid="profile-anthro-form"]');
  ok((await form.getAttribute('action'))==='/pacientes/2/antropometria' && (await form.locator('input[name="patient_id"]').inputValue())==='2', 'formulário do perfil aponta para o paciente 2');
  await p.fill('#ant_weight','68.4'); await p.fill('#ant_height','165');
  await form.locator('button[type="submit"]').click(); await p.waitForLoadState(); await p.waitForTimeout(300);
  ok(+sq("SELECT COUNT(*) FROM anthropometric_assessments WHERE patient_id=2")===before2+1 && sq("SELECT COUNT(*) FROM anthropometric_assessments WHERE patient_id=1")===before1, 'avaliação gravada só no paciente 2 (banco)');
  ok(sq("SELECT weight_kg FROM anthropometric_assessments WHERE patient_id=2 ORDER BY id DESC LIMIT 1").startsWith('68.4'), 'peso gravado: 68,4 kg');
  const tok=await p.evaluate(()=>document.querySelector('input[name="_csrf_token"]')?.value||'');
  const st=await p.evaluate(async(t)=>{const r=await fetch('/pacientes/2/antropometria',{method:'POST',body:new URLSearchParams({_csrf_token:t,patient_id:'1',weight_kg:'90',height_cm:'170'}),redirect:'manual'});return r.status;},tok);
  ok(sq("SELECT COUNT(*) FROM anthropometric_assessments WHERE patient_id=1")===before1 && +sq("SELECT COUNT(*) FROM anthropometric_assessments WHERE patient_id=2")===before2+1, 'envio com patient_id trocado é recusado: nada gravado (HTTP '+st+')');

  console.log('== Emissão de exames de ponta a ponta');
  const reqsBefore=+sq("SELECT COUNT(*) FROM exam_requests WHERE patient_id=2");
  await p.goto(B+'/pacientes/2/exames/novo');
  const box=p.locator('input[name="exam_ids[]"]').first();
  if(!(await box.isVisible().catch(()=>false))){ const s=p.locator('input[type="search"], #exam-search').first(); if(await s.count()){await s.fill('glicemia'); await p.waitForTimeout(600);} }
  await p.locator('input[name="exam_ids[]"]').first().check({force:true});
  const just=p.locator('textarea[name="clinical_justification"]'); if(await just.count()) await just.fill('Acompanhamento nutricional TESTE');
  const ack=p.locator('input[name="coverage_disclaimer_acknowledged"]'); if(await ack.count()) await ack.check({force:true});
  // v54.18+: salvamento pelo recurso canônico (autosave + "Salvar rascunho" no mesmo registro), depois abre o documento salvo
  await p.click('button[type="submit"]:has-text("Salvar rascunho")'); await p.waitForURL(/\/pacientes\/2\/exames\/\d+(?:[?#]|$)/); await p.waitForLoadState();
  const rid=sq("SELECT id FROM exam_requests WHERE patient_id=2 ORDER BY id DESC LIMIT 1");
  ok(+sq("SELECT COUNT(*) FROM exam_requests WHERE patient_id=2")===reqsBefore+1 && sq(`SELECT status FROM exam_requests WHERE id=${rid}`)==='draft' && new RegExp('/exames/'+rid).test(p.url()), 'um único rascunho criado no banco e aberto (solicitação '+rid+')');
  await p.goto(B+`/pacientes/2/exames/${rid}`);
  ok(await p.locator('#revisao-emissao').count()===0, 'rascunho ainda não mostra a emissão (v54.18+: passa por "Enviar para revisão")');
  await p.click('button:has-text("Enviar para revisão")'); await p.waitForLoadState();
  ok(sq(`SELECT status FROM exam_requests WHERE id=${rid}`)==='review', 'enviado para revisão (banco)');
  await p.locator('#revisao-emissao input[name="coverage_disclaimer_acknowledged"]').check({force:true});
  await p.click('[data-testid="btn-emitir-pdf"]'); await p.waitForLoadState(); await p.waitForTimeout(500);
  const row=sq(`SELECT status||'|'||COALESCE(request_number,'')||'|'||patient_id||'|'||COALESCE(pdf_hash,'') FROM exam_requests WHERE id=${rid}`).split('|');
  ok(row[0]==='issued' && /^EXAM-/.test(row[1]) && row[2]==='2' && /^[a-f0-9]{64}$/.test(row[3]), 'emitida: número '+row[1]+', paciente 2, PDF com SHA-256 (banco)');
  const pdf=await p.evaluate(async(u)=>{const r=await fetch(u);return {s:r.status,t:r.headers.get('content-type'),n:(await r.arrayBuffer()).byteLength};},`/pacientes/2/exames/${rid}/pdf`);
  ok(pdf.s===200 && /pdf/.test(pdf.t||'') && pdf.n>1000, 'PDF baixa ('+pdf.n+' bytes)');

  console.log('== Portal do paciente');
  const pc=await b.newContext(); const pp=await pc.newPage(); pp.on('pageerror',e=>errs.push('portal: '+e.message));
  await pp.goto(B+'/paciente/app');
  const login=await pp.evaluate(async()=>{const t=document.querySelector('form[action="/paciente/app/login"] input[name="_csrf_token"]')?.value||'';const fd=new URLSearchParams({_csrf_token:t,login:'paciente.teste@teste.invalid',password:'Teste-Paciente-123'});
    const r=await fetch('/paciente/app/login',{method:'POST',body:fd,headers:{'X-ClyoNutri-Web':'1'}});return r.status;});
  const home=await pp.evaluate(async()=>{const r=await fetch('/api/v1/patient/home');return {s:r.status,j:await r.json().catch(()=>null)};});
  ok(login===200 && home.s===200 && home.j && home.j.patient_name==='Paciente TESTE Dois', 'paciente entra e o app abre como "Paciente TESTE Dois"');
  ok(home.j && home.j.active_plan && home.j.active_plan.status==='published' && home.j.active_plan.title==='TESTE Plano Fase 1', 'vê o plano publicado (versão '+(home.j&&home.j.active_plan?home.j.active_plan.version:'?')+')');
  ok(!JSON.stringify(home.j||{}).includes('TESTE Plano Vazio') && !JSON.stringify(home.j||{}).includes('Paciente TESTE Antigo'), 'não vê plano em rascunho nem dado de outro paciente');
  const other=sq("SELECT id FROM hydration_logs WHERE patient_id=1 LIMIT 1");
  const del=await pp.evaluate(async(id)=>{const r=await fetch('/api/v1/patient/hydration/'+id+'/delete',{method:'POST',headers:{'X-ClyoNutri-Web':'1','Content-Type':'application/json'},body:'{}'});return r.status;},other);
  ok(del===404 && sq(`SELECT COUNT(*) FROM hydration_logs WHERE id=${other} AND deleted_at IS NULL`)==='1', 'não apaga registro de água de outro paciente (HTTP '+del+', registro intacto)');
  const anon=await (await b.newContext()).request.get(B+'/api/v1/patient/home');
  ok(anon.status()===401, 'sem login: API do paciente recusa (401)');
  await pc.close();

  ok(csp.length===0 && errs.length===0, 'sem CSP e sem erro de JS ('+csp.length+'/'+errs.length+(errs[0]?': '+errs[0]:'')+')');
  await b.close();
  const f=res.filter(x=>!x).length; console.log(`\n${res.length-f} PASS, ${f} FAIL`); process.exit(f?1:0);
})();
