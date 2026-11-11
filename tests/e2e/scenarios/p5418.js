// NI2-635 v54.15 — governança do catálogo (equipe da plataforma, quatro olhos), chave global, protocolo por clínica,
// condições do paciente e módulos na anamnese. Protocolo de TESTE (sem conteúdo clínico real). Confere o banco.
const { chromium } = require('playwright');
const { execSync } = require('child_process');
const res=[]; const ok=(c,l)=>{res.push(!!c);console.log((c?'  PASS  ':'  FAIL  ')+l);};
const DEF={title:'Protocolo TESTE E2E',sections:{s1:'Seção TESTE'},suggestion_terms:['condicao teste alfa'],questions:[
  {key:'usa_item',section:'s1',type:'yesno',options:{sim:'Sim',nao:'Não'},required:true,text:'Usa o item TESTE?'},
  {key:'qual_item',section:'s1',type:'text',required:true,text:'Qual item TESTE?',condition:{question:'usa_item',equals:'sim'}},
  {key:'medida',section:'s1',type:'number',min:0,max:50,required:true,text:'Medida TESTE',
   red_flag:{when:{question:'medida',gte:40},action:'urgent_evaluation',message:'Valor TESTE alto: encaminhar para avaliação.'}}]};
(async()=>{
  const B=process.argv[2], DB=process.argv[3];
  const sq=q=>execSync(`php ${process.env.E2E_SQ} ${DB} "${q}"`).toString().trim();
  const b=await chromium.launch(); const errs=[]; const csp=[];
  const as=async(u,c,to,vw)=>{const ctx=await b.newContext({viewport:vw||{width:1280,height:900}}); const p=await ctx.newPage();
    p.on('pageerror',e=>errs.push(e.message)); p.on('console',m=>{if(/Content Security Policy/i.test(m.text()))csp.push(m.text())}); p.on('dialog',d=>d.accept());
    await p.goto(B+`/__teste_login?u=${u}&c=${c}&v=0&to=${encodeURIComponent(to)}`); return p;};
  const post=(p,url,data)=>p.evaluate(async([u,d])=>{const t=document.querySelector('input[name="_csrf_token"]')?.value||'';const r=await fetch(u,{method:'POST',body:new URLSearchParams({_csrf_token:t,...d}),redirect:'manual'});return r.status;},[url,data]);

  console.log('== Chave global desligada (estado de produção)');
  const n=await as(1,1,'/pacientes/2/anamnese');
  ok(await n.locator('#anamnese-adaptativa').count()===0, 'anamnese sem o bloco adaptativo');
  const r404=await n.evaluate(async()=>(await fetch('/configuracoes/protocolos')).status);
  const p404=await post(n,'/pacientes/2/condicoes',{code:'teste_e2e',status:'reported',provenance:'professional'});
  ok(r404===404 && p404===404 && sq("SELECT COUNT(*) FROM patient_conditions")==='0', 'telas e envios da clínica respondem 404 e nada é gravado ('+r404+'/'+p404+')');
  await n.goto(B+'/configuracoes'); ok(await n.locator('a[href="/configuracoes/protocolos"]').count()===0, 'Protocolos clínicos escondido nas configurações');
  const adm=await n.evaluate(async()=>{const r=await fetch('/admin/protocolos',{redirect:'manual'});return r.status;});
  ok(adm!==200, 'nutricionista da clínica não abre o catálogo da plataforma (HTTP '+adm+')');

  console.log('== Catálogo: autora registra e escreve; outra pessoa aprova');
  const a=await as(3,3,'/admin/protocolos');
  ok(await a.locator('[data-testid="protocols-flag"]').innerText().then(t=>/desligada/.test(t)), 'catálogo mostra a chave global desligada');
  await a.fill('[data-testid="protocol-register"] input[name="code"]','teste_e2e'); await a.fill('[data-testid="protocol-register"] input[name="name"]','Protocolo TESTE E2E');
  await a.click('[data-testid="protocol-register"] button[type="submit"]'); await a.waitForLoadState();
  const pid=sq("SELECT id FROM clinical_protocols WHERE code='teste_e2e'");
  ok(/\/admin\/protocolos\/\d+$/.test(a.url()) && pid!=='', 'protocolo registrado (id '+pid+')');
  await a.fill('[data-testid="protocol-new-draft"] textarea[name="definition_json"]','{"title": "x", "questions": [{"key": "k", "type": "xpto", "text": "x"}]}');
  await a.click('[data-testid="protocol-new-draft"] button[type="submit"]'); await a.waitForLoadState();
  ok(sq("SELECT COUNT(*) FROM clinical_protocol_versions")==='0' && /tipo/i.test(await a.locator('[data-testid="protocol-flash"]').innerText()), 'definição inválida recusada com motivo, nada gravado');
  ok((await a.locator('[data-testid="protocol-new-draft"] textarea[name="definition_json"]').inputValue()).includes('xpto'), 'o JSON digitado volta no formulário');
  await a.fill('[data-testid="protocol-new-draft"] textarea[name="definition_json"]',JSON.stringify(DEF,null,1));
  await a.fill('[data-testid="protocol-new-draft"] textarea[name="references_text"]','Referência TESTE 2026');
  await a.click('[data-testid="protocol-new-draft"] button[type="submit"]'); await a.waitForLoadState();
  ok(sq("SELECT status FROM clinical_protocol_versions WHERE protocol_id="+pid)==='draft' && await a.locator('[data-testid="protocol-preview"] li').count()===3, 'rascunho criado com prévia das 3 perguntas');
  await a.locator('form[action$="/enviar"] button').first().click(); await a.waitForLoadState();
  ok(sq("SELECT status FROM clinical_protocol_versions WHERE protocol_id="+pid)==='in_review', 'enviado para revisão');
  ok(await a.locator('[data-testid="protocol-own-version"]').count()===1 && await a.locator('[data-testid="protocol-approve"]').count()===0, 'autora não vê o botão de aprovar a própria versão');
  const vid=sq("SELECT id FROM clinical_protocol_versions WHERE protocol_id="+pid);
  const self=await post(a,`/admin/protocolos/${pid}/aprovar`,{version_id:vid,approver_name:'Autora',approver_crn:'CRN-3 12345',review_due_at:'2027-06-01',confirm_review:'1'});
  ok(sq("SELECT status FROM clinical_protocol_versions WHERE id="+vid)==='in_review', 'aprovação forçada pela autora recusada no servidor (HTTP '+self+')');

  const r=await as(4,3,'/admin/protocolos/'+pid);
  const f=r.locator('[data-testid="protocol-approve"]');
  await f.locator('input[name="approver_name"]').fill('Revisora TESTE'); await f.locator('input[name="approver_crn"]').fill('12345');
  const due=new Date(Date.now()+300*864e5).toISOString().slice(0,10);
  await f.locator('input[name="review_due_at"]').fill(due); await f.locator('input[name="confirm_review"]').check();
  await f.locator('button[type="submit"]').click(); await r.waitForLoadState();
  ok(sq("SELECT status FROM clinical_protocol_versions WHERE id="+vid)==='in_review' && /CRN/.test(await r.locator('[data-testid="protocol-flash"]').innerText()), 'CRN sem região recusado');
  const f2=r.locator('[data-testid="protocol-approve"]');
  await f2.locator('input[name="approver_name"]').fill('Revisora TESTE'); await f2.locator('input[name="approver_crn"]').fill('CRN-3 12345');
  await f2.locator('input[name="review_due_at"]').fill(due); await f2.locator('input[name="confirm_review"]').check();
  await f2.locator('button[type="submit"]').click(); await r.waitForLoadState();
  const ap=sq("SELECT status||'|'||approved_by_user_id||'|'||approved_by_crn FROM clinical_protocol_versions WHERE id="+vid);
  ok(ap==='approved|4|CRN-3 12345', 'aprovada pela revisora, com CRN (banco: '+ap+')');
  ok(+sq("SELECT COUNT(*) FROM clinical_protocol_events WHERE protocol_version_id="+vid)>=3, 'trilha editorial gravada');

  console.log('== Aprovado não aparece sem a chave global');
  await n.goto(B+'/pacientes/2/anamnese'); ok(await n.locator('#anamnese-adaptativa').count()===0, 'com a chave desligada, nada muda para a clínica');
  await r.goto(B+'/admin/configuracoes'); await r.locator('[data-testid="flag-clinical-protocols"] input[type="checkbox"]').check();
  await r.click('button:has-text("Salvar parâmetros")'); await r.waitForLoadState();
  ok(sq("SELECT setting_value FROM platform_settings WHERE setting_key='feature_flag_clinical_protocols'")==='1', 'chave global ligada pelo console (banco)');

  console.log('== Clínica liga o protocolo');
  await n.goto(B+'/configuracoes'); ok(await n.locator('a[href="/configuracoes/protocolos"]').count()>0, 'aba "Protocolos clínicos" aparece');
  await n.goto(B+'/pacientes/2/anamnese'); ok(await n.locator('#anamnese-adaptativa').count()===0, 'antes de a clínica ligar: nada na anamnese');
  await n.goto(B+'/configuracoes/protocolos'); await n.click('[data-testid="cp-toggle-teste_e2e"]'); await n.waitForLoadState();
  ok(sq("SELECT s.enabled FROM clinic_protocol_settings s JOIN clinical_protocols p ON p.id=s.protocol_id WHERE s.clinic_id=1 AND p.code='teste_e2e'")==='1', 'clínica 1 ligou o protocolo (banco)');

  console.log('== Condição: sugestão → confirmação → módulo');
  await n.goto(B+'/pacientes/2/anamnese');
  ok(await n.locator('#anamnese-adaptativa').count()===1 && await n.locator('[data-testid="adaptive-form"]').count()===0, 'bloco aparece; sem condição, nenhum módulo');
  await n.click('[data-testid="condition-suggest"]'); await n.waitForLoadState();
  ok(sq("SELECT status FROM patient_conditions WHERE patient_id=2")==='suggested' && await n.locator('[data-testid="condition"][data-status="suggested"]').count()===1, 'texto da anamnese gerou sugestão (banco: suggested)');
  ok(await n.locator('[data-testid="adaptive-form"]').count()===0, 'sugestão não abre módulo');
  const c=n.locator('[data-testid="condition"][data-status="suggested"]');
  await c.locator('select[name="status"]').selectOption('reported'); await c.locator('select[name="provenance"]').selectOption('self_reported');
  await c.locator('[data-testid="condition-confirm"]').click(); await n.waitForLoadState();
  ok(sq("SELECT status||'|'||provenance FROM patient_conditions WHERE patient_id=2")==='reported|self_reported' && await n.locator('[data-testid="adaptive-form"]').count()===1, 'confirmada com origem: módulo aparece');
  ok(await n.locator('[data-key="teste_e2e.qual_item"]').count()===0, 'pergunta condicional escondida até responder "sim"');

  console.log('== Respostas: rascunho, conclusão, alerta');
  await n.locator('input[name="answers[teste_e2e.usa_item]"][value="sim"]').check(); await n.fill('input[name="answers[teste_e2e.medida]"]','45');
  await n.click('[data-testid="adaptive-conclude"]'); await n.waitForLoadState();
  ok(sq("SELECT COUNT(*) FROM protocol_responses WHERE patient_id=2 AND is_draft=0")==='0' && /Faltam/.test(await n.locator('[data-testid="cp-flash"]').innerText()), 'concluir com obrigatória faltando: recusado, nada concluído');
  ok(await n.locator('input[name="answers[teste_e2e.medida]"]').inputValue()==='45' && await n.locator('[data-key="teste_e2e.qual_item"]').count()===1, 'o que foi digitado volta; pergunta condicional aparece');
  await n.fill('input[name="answers[teste_e2e.qual_item]"]','Item TESTE');
  await n.click('[data-testid="adaptive-save-draft"]'); await n.waitForLoadState();
  ok(sq("SELECT is_draft FROM protocol_responses WHERE patient_id=2 ORDER BY id DESC LIMIT 1")==='1' && await n.locator('[data-testid="adaptive-draft"]').count()===1, 'rascunho salvo e sinalizado');
  ok(await n.locator('[data-testid="adaptive-red-flags"]').count()===1 && /encaminhar/.test(await n.locator('[data-testid="adaptive-red-flags"]').innerText()), 'sinal de alerta exibido (encaminhamento)');
  await n.click('[data-testid="adaptive-conclude"]'); await n.waitForLoadState();
  const conc=sq("SELECT answers_json FROM protocol_responses WHERE patient_id=2 AND is_draft=0 ORDER BY id DESC LIMIT 1");
  ok(conc.includes('Item TESTE') && conc.includes('45') && await n.locator('[data-testid="adaptive-draft"]').count()===0, 'concluído (banco), sem aviso de rascunho');
  ok(+sq("SELECT COUNT(*) FROM audit_logs WHERE action='PROTOCOL_DATA_VIEWED'")>0, 'leitura auditada');

  console.log('== Outra clínica e tela pequena');
  const o=await as(2,2,'/dashboard');
  const xs=await post(o,'/pacientes/2/condicoes/'+sq("SELECT id FROM patient_conditions WHERE patient_id=2")+'/encerrar',{to:'resolved',note:'Tentativa TESTE de outra clínica'});
  ok(sq("SELECT status FROM patient_conditions WHERE patient_id=2")==='reported', 'clínica 2 não encerra condição do paciente da clínica 1 (HTTP '+xs+')');
  const xa=await post(o,'/pacientes/2/protocolos',{'answers[teste_e2e.medida]':'1',conclude:'0'});
  ok(sq("SELECT COUNT(*) FROM protocol_responses WHERE clinic_id=2")==='0', 'clínica 2 não grava respostas no paciente da clínica 1 (HTTP '+xa+')');
  const m=await as(1,1,'/pacientes/2/anamnese#anamnese-adaptativa',{width:375,height:800});
  const ov=await m.evaluate(()=>document.documentElement.scrollWidth-document.documentElement.clientWidth);
  ok(ov<=1, 'anamnese com o bloco em 375px sem rolagem lateral ('+ov+'px)');
  ok(errs.length===0 && csp.length===0, 'sem erro de JavaScript nem de CSP ('+errs.concat(csp).join(' | ').slice(0,200)+')');
  await b.close();
  console.log(`\n${res.filter(Boolean).length} PASS, ${res.filter(x=>!x).length} FAIL`); process.exit(res.every(Boolean)?0:1);
})().catch(e=>{console.error(e);process.exit(1);});
