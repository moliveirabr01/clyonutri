// v54.49 — formulário real de dados de cobrança do consultório, CPF/CNPJ e fallback do celular.
const { chromium }=require('playwright');
const res=[];const ok=(c,l)=>{res.push(!!c);console.log((c?'  PASS  ':'  FAIL  ')+l);};
(async()=>{const B=process.argv[2],DB=process.argv[3];const b=await chromium.launch();const ctx=await b.newContext();const p=await ctx.newPage();
try{
 await p.goto(B+'/__teste_login?u=1&c=1&v=0&to=/configuracoes?tab=perfil');
 ok(await p.locator('#dados-cobranca').count()===1,'bloco Dados do consultório para cobrança aparece');
 ok(await p.locator('#profile-clinic_document').getAttribute('required')!==null,'CPF/CNPJ é obrigatório no formulário');
 const csrf=await p.locator('#settings-profile input[name=_csrf_token]').inputValue();const revision=await p.locator('#settings-profile input[name=revision]').inputValue();
 const data=await p.locator('#settings-profile form').evaluate(f=>{const d=Object.fromEntries(new FormData(f));d.clinic_document='';return d;});
 const invalid=await p.evaluate(async data=>{const r=await fetch('/configuracoes',{method:'POST',headers:{'Content-Type':'application/x-www-form-urlencoded'},body:new URLSearchParams(data)});return {s:r.status,t:await r.text()};},data);
 ok(invalid.s===409&&/CPF \(11 dígitos\) ou CNPJ \(14 dígitos\)/.test(invalid.t),'salvar sem documento exibe orientação para assinatura/nota fiscal');
 await p.goto(B+'/configuracoes?tab=perfil');
 const fill=async(id,v)=>p.locator(id).fill(v);
 await fill('#profile-clinic_document','24.971.563/792');await fill('#profile-clinic_email','cobranca.teste@example.com');await fill('#profile-clinic_phone','');
 await fill('#profile-clinic_address_street','Avenida Paulista');await fill('#profile-clinic_address_number','1000');await fill('#profile-clinic_address_neighborhood','Bela Vista');await fill('#profile-clinic_address_city','São Paulo');await fill('#profile-clinic_address_zip','01310-100');await p.locator('#profile-clinic_address_state').selectOption('SP');
 await p.locator('#settings-profile button[type=submit]').evaluate(b=>b.click());await p.waitForURL(u=>u.pathname==='/configuracoes');
 ok(/atualizados/.test(await p.locator('body').innerText()),'perfil salvo pela interface');
 ok(/CPF, CEP|atualizados/.test(await p.locator('body').innerText()),'dados completos foram salvos');
 const q=require('child_process').execSync(`php ${process.env.E2E_SQ} ${DB} "SELECT document||'|'||address_zip||'|'||address_state FROM clinics WHERE id=1"`).toString().trim();
 ok(q==='24971563792|01310100|SP','CPF, CEP e UF normalizados no banco');
 await p.goto(B+'/configuracoes?tab=perfil');ok(await p.locator('#profile-clinic_document').inputValue()==='24971563792','CPF reaparece ao recarregar');
 const phone=require('child_process').execSync(`php ${process.env.E2E_SQ} ${DB} "SELECT c.phone||'|'||COALESCE(u.phone,'') FROM clinics c JOIN users u ON u.id=1 WHERE c.id=1"`).toString().trim();
 require('child_process').execSync(`php ${process.env.E2E_SQ} ${DB} "UPDATE users SET phone='11999990000' WHERE id=1;UPDATE clinics SET phone=NULL WHERE id=1"`);await p.goto(B+'/configuracoes?tab=perfil');await fill('#profile-clinic_address_complement','Sala 1');await p.locator('#settings-profile button[type=submit]').evaluate(b=>b.click());await p.waitForURL(u=>u.search.includes('saved='));const saved=require('child_process').execSync(`php ${process.env.E2E_SQ} ${DB} "SELECT phone FROM clinics WHERE id=1"`).toString().trim();ok(saved.replace(/\D/g,'')==='11999990000','telefone do consultório recebe fallback do celular do perfil quando estava vazio');
}finally{await ctx.close();await b.close();}
const f=res.filter(x=>!x).length;console.log(`\n${res.length-f} PASS, ${f} FAIL`);process.exit(f?1:0);})();
