// NI2-727 / v54.48 — slice 1 Asaas Sandbox no navegador + webhook HTTP real no servidor de teste.
// Sem chave de API: o checkout fica fail-closed (configuração ausente), o webhook é exercido com token local
// aleatório injetado no processo do servidor (E2E_ASAAS_TOKEN). Nenhum segredo é impresso.
const { chromium } = require('playwright');
const res=[]; const ok=(c,l)=>{res.push(!!c);console.log((c?'  PASS  ':'  FAIL  ')+l);};
(async()=>{
  const B=process.argv[2], DB=process.argv[3];
  const token=process.env.E2E_ASAAS_TOKEN||'';
  const b=await chromium.launch();
  const {ctx,p}=await (async()=>{const ctx=await b.newContext();const p=await ctx.newPage();
    await p.goto(B+'/__teste_login?u=1&c=1&v=0&to=/configuracoes/assinatura');return {ctx,p};})();

  console.log('== tela de assinatura, sandbox sem chave (fail-closed)');
  ok(p.url().includes('/configuracoes/assinatura'), 'tela de assinatura abre');
  const body=await p.textContent('body');
  // v54.50: sem Asaas habilitado não há card Asaas; a tela mostra só a troca manual em Planos.
  ok(/Planos/.test(body) && /Fazer upgrade|Fazer downgrade/.test(body), 'sem Asaas habilitado: Planos com troca manual na mesma tela');
  ok(!/ASAAS_API_KEY|aact_/.test(body), 'nenhuma chave/token renderizado na página');
  // Sem ASAAS_API_KEY no servidor, o botão não deve oferecer contratação online.
  const btn=await p.$('form[action*="assinatura/asaas"] button');
  ok(!btn, 'sem chave configurada não há CTA de contratação online (fail-closed)');

  console.log('== webhook HTTP real: token, JSON, idempotência');
  const post=async(payload,tok)=>{const r=await p.evaluate(async({payload,tok})=>{
    const r=await fetch('/webhooks/asaas',{method:'POST',headers:{'Content-Type':'application/json','asaas-access-token':tok||''},body:typeof payload==='string'?payload:JSON.stringify(payload)});
    return {s:r.status,j:await r.json().catch(()=>null)};},{payload,tok});return r;};
  let r=await post({id:'evt_e2e_1',event:'PAYMENT_RECEIVED',payment:{id:'pay_e2e1',externalReference:'x',value:1}},'');
  ok(r.s===401,'sem token → 401');
  r=await post({id:'evt_e2e_1',event:'PAYMENT_RECEIVED',payment:{id:'pay_e2e1',externalReference:'x',value:1}},'errado-errado-errado-errado-errado');
  ok(r.s===401,'token errado → 401');
  r=await post('{bad',token);
  ok(r.s===400,'JSON inválido → 400');
  r=await post({id:'evt_e2e_created',event:'PAYMENT_CREATED',payment:{id:'pay_e2e0',externalReference:'inexistente',value:1}},token);
  ok(r.s===200 && r.j && r.j.status!=='activated','PAYMENT_CREATED não ativa plano');
  r=await post({id:'evt_e2e_unknown',event:'PAYMENT_RECEIVED',payment:{id:'pay_e2eU',externalReference:'inexistente',value:1}},token);
  ok(r.s===422,'referência desconhecida → 422 e nada ativa');

  // Semeia um checkout real no banco de TESTE e dispara o evento financeiro pela rota pública.
  const seed=`INSERT INTO asaas_checkouts (clinic_id, subscription_id, plan_id, requested_by, idempotency_key, external_reference, amount_cents, billing_cycle, status, created_at, updated_at)
    SELECT cs.clinic_id, cs.id, 3, 1, 'eeeeeeeeeeeeeeeeeeeeeeeeeeeeeeee', 'clyo-e2e-5448', sp.price_cents, 'monthly', 'awaiting_payment', datetime('now'), datetime('now')
    FROM clinic_subscriptions cs JOIN saas_plans sp ON sp.id=3 WHERE cs.clinic_id=1 LIMIT 1;`;
  require('child_process').execSync(`php ${process.env.E2E_SQ} ${DB} ${JSON.stringify(seed.replace(/\n/g,' '))}`);
  r=await post({id:'evt_e2e_paid',event:'PAYMENT_RECEIVED',payment:{id:'pay_e2ePaid',externalReference:'clyo-e2e-5448',value:99999,paymentDate:'2026-11-05',confirmedDate:'2026-11-05',subscription:'sub_e2e5448'}},token);
  ok(r.s===200 && r.j && r.j.status==='activated','PAYMENT_RECEIVED autenticado ativa o plano');
  const plan= require('child_process').execSync(`php ${process.env.E2E_SQ} ${DB} "SELECT plan_id||':'||status FROM clinic_subscriptions WHERE clinic_id=1"`).toString().trim();
  ok(plan==='3:active','plano vira ELITE-03 ativo somente após confirmação financeira');
  const inv= require('child_process').execSync(`php ${process.env.E2E_SQ} ${DB} "SELECT COUNT(*) FROM subscription_invoices WHERE invoice_number='ASAAS-pay_e2ePaid' AND status='paid'"`).toString().trim();
  ok(inv==='1','fatura REAL paga criada a partir do evento (sem fatura fictícia)');
  r=await post({id:'evt_e2e_paid',event:'PAYMENT_RECEIVED',payment:{id:'pay_e2ePaid',externalReference:'clyo-e2e-5448',value:99999}},token);
  ok(r.s===200 && r.j && r.j.status==='duplicate','replay do mesmo evento → duplicate');
  const inv2= require('child_process').execSync(`php ${process.env.E2E_SQ} ${DB} "SELECT COUNT(*) FROM subscription_invoices WHERE invoice_number='ASAAS-pay_e2ePaid'"`).toString().trim();
  ok(inv2==='1','replay não duplica fatura');
  // Valor divergente precisa ser testado num checkout AINDA pendente (o anterior já está ativo e volta already_active).
  const seed2=seed.replace(/clyo-e2e-5448/g,'clyo-e2e-5448b').replace(/'eeeeeeeeeeeeeeeeeeeeeeeeeeeeeeee'/,"'ffffffffffffffffffffffffffffffff'");
  require('child_process').execSync(`php ${process.env.E2E_SQ} ${DB} ${JSON.stringify(seed2.replace(/\n/g,' '))}`);
  r=await post({id:'evt_e2e_low',event:'PAYMENT_RECEIVED',payment:{id:'pay_e2eLow',externalReference:'clyo-e2e-5448b',value:0.01}},token);
  ok(r.s===422,'valor inferior ao contratado → 422');

  console.log('== retorno da successUrl não altera entitlement por si só');
  await p.goto(B+'/configuracoes/assinatura?asaas=retorno');
  ok(/webhook|confirma|Asaas/i.test(await p.textContent('body')),'retorno mostra que a ativação depende do webhook');

  await ctx.close(); await b.close();
  const f=res.filter(x=>!x).length; console.log(`\n${res.length-f} PASS, ${f} FAIL`); process.exit(f?1:0);
})();
