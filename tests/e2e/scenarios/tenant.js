// NI2-632: isolamento entre clínicas. A clínica B tenta abrir recursos da clínica A pelo endereço, e vice-versa.
// Nada de dado de outra clínica pode aparecer, e nenhuma ação pode ser aceita.
const { chromium } = require('playwright');
const res=[]; const ok=(c,l)=>{res.push(!!c);console.log((c?'  PASS  ':'  FAIL  ')+l);};
(async()=>{
  const B=process.argv[2];
  const b=await chromium.launch();
  const A_NAMES=/Paciente TESTE Antigo|Paciente TESTE Dois|amendoim/i, B_NAMES=/Paciente TESTE Clinica B/;
  const asUser=async(u,c)=>{const ctx=await b.newContext(); const p=await ctx.newPage(); await p.goto(B+`/__teste_login?u=${u}&c=${c}&v=0&to=/dashboard`); return {ctx,p};};
  const probe=async(p,url,forbidden)=>{const r=await p.goto(B+url); const body=(await p.textContent('body').catch(()=>''))||''; return {status:r?r.status():0, leaked:forbidden.test(body), final:new URL(p.url()).pathname};};

  console.log('== clínica B tentando dados da clínica A');
  const {ctx:cb,p:pb}=await asUser(2,2);
  for (const url of ['/pacientes/2','/pacientes/2/anamnese','/pacientes/2/prontuario','/pacientes/2/prontuario/imprimir','/pacientes/2/resumo-prescricao','/pacientes/2/antropometria','/planos/1','/pacientes/2/exames/1','/consulta/1','/pacientes/1/editar']) {
    const r=await probe(pb,url,A_NAMES);
    ok(!r.leaked && !(r.status===200 && r.final===url), `B → ${url}: sem dado de A (HTTP ${r.status}, terminou em ${r.final})`);
  }
  const list=await probe(pb,'/pacientes',A_NAMES);
  ok(!list.leaked && /Paciente TESTE Clinica B/.test(await pb.textContent('body')), 'lista de pacientes de B mostra só os de B');
  const api=await pb.evaluate(async()=>{const r=await fetch('/api/v1/patients/2');return {s:r.status,t:(await r.text()).slice(0,4000)};}).catch(()=>({s:0,t:''}));
  ok(!A_NAMES.test(api.t), `API /api/v1/patients/2 sem dado de A (HTTP ${api.s})`);
  // ação: tentar gravar evolução/remarcar em recurso de A
  const post=await pb.evaluate(async()=>{const tok=document.querySelector('input[name="_csrf_token"]')?.value||document.querySelector('meta[name="csrf-token"]')?.content||'';
    const fd=new URLSearchParams({_csrf_token:tok,status:'cancelled'});const r=await fetch('/agenda/1/status',{method:'POST',body:fd,redirect:'manual'});return r.status;}).catch(()=>0);
  await cb.close();

  console.log('== clínica A tentando dados da clínica B');
  const {ctx:ca,p:pa}=await asUser(1,1);
  const r3=await probe(pa,'/pacientes/3',B_NAMES);
  ok(!r3.leaked && !(r3.status===200 && r3.final==='/pacientes/3'), `A → /pacientes/3: sem dado de B (HTTP ${r3.status})`);
  // sessão forjada: usuário de A com clinic_id de B (sem vínculo)
  await pa.goto(B+'/__teste_login?u=1&c=2&v=0&to=/pacientes');
  const forged=(await pa.textContent('body').catch(()=>''))||'';
  ok(!B_NAMES.test(forged), 'sessão com clínica sem vínculo não mostra pacientes de B');
  await ca.close();
  const appt=require('child_process').execSync(`php ${process.env.E2E_SQ} ${process.argv[3]} "SELECT status FROM appointments WHERE id=1"`).toString().trim();
  ok(appt==='scheduled', `consulta de A intacta depois da tentativa de B (status ${appt}, POST ${post})`);
  await b.close();
  const f=res.filter(x=>!x).length; console.log(`\n${res.length-f} PASS, ${f} FAIL`); process.exit(f?1:0);
})();
