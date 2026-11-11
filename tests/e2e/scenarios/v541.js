const { chromium } = require('playwright');
const res = []; const ok = (c, l) => { res.push([!!c, l]); console.log((c ? '  PASS  ' : '  FAIL  ') + l); };
(async () => {
  const B = process.argv[2] || 'http://127.0.0.1:8059';
  const d = new Date(Date.now() + 2 * 86400000); const pad = n => String(n).padStart(2, '0');
  const D2 = `${d.getFullYear()}-${pad(d.getMonth() + 1)}-${pad(d.getDate())}`;
  const b = await chromium.launch(); const ctx = await b.newContext({ viewport: { width: 1280, height: 860 } }); const p = await ctx.newPage();
  const csp = [], errs = [];
  p.on('console', m => { const t = m.text(); if (/Content Security Policy/i.test(t)) csp.push(t); else if (m.type() === 'error') errs.push(t); });
  p.on('pageerror', e => errs.push(e.message));

  console.log('== Link público');
  let r = await p.goto(B + '/agendar/1');
  const slugA = new URL(p.url()).pathname.split('/').pop();
  ok(/^nutri-teste-a-[a-z2-9]{6}$/.test(slugA), 'número redireciona para o apelido: ' + slugA);
  ok(!(await p.content()).includes('R$ 250'), 'sem valor inventado');
  ok(!(await p.content()).includes('Rua TESTE'), 'sem endereço por padrão');
  await p.goto(B + '/agendar/2'); const slugB = new URL(p.url()).pathname.split('/').pop();
  ok(slugB !== slugA && /^nutri-teste-a-/.test(slugB), 'mesmo nome, outro apelido: ' + slugB);
  r = await p.goto(B + '/agendar/' + slugB); ok((await p.content()).includes('Clínica TESTE B'), 'apelido da clínica B abre a clínica B');
  r = await p.goto(B + '/agendar/nutri-teste-a'); ok(r.status() === 404, 'só o nome: 404');
  r = await p.goto(B + '/agendar/nutri-1'); ok(r.status() === 404, '"nutri-1": 404');

  console.log('== Configurações');
  await p.goto(B + '/__teste_login?u=1&c=1&v=0&to=/configuracoes%3Ftab%3Dagenda');
  ok(await p.isVisible('[data-testid="settings-legacy-hours"]'), 'aviso "Confirme seus horários" na conta antiga');
  ok(await p.isVisible('button:has-text("Confirmar estes horários")'), 'botão "Confirmar estes horários"');
  await p.goto(B + '/configuracoes?tab=agenda&secao=link'); // NI2-612: o link online fica na seção própria
  ok((await p.inputValue('#booking-link')).endsWith('/agendar/' + slugA), 'link mostrado é o apelido');
  ok(await p.isVisible('input[name="booking_public_show_address"]'), 'opção própria de endereço no link');
  await p.check('input[name="booking_public_show_address"]'); await p.click('button:has-text("Salvar agendamento online")'); await p.waitForLoadState();
  await p.goto(B + '/agendar/' + slugA); ok((await p.content()).includes('Rua TESTE'), 'com a opção ligada, o endereço aparece');
  await p.goto(B + '/configuracoes#chat-settings'); await p.waitForURL(/tab=paciente-app/, { timeout: 3000 }).catch(() => {});
  ok(p.url().includes('tab=paciente-app#chat-settings'), 'link antigo #chat-settings abre a aba certa');

  console.log('== Agenda: formulário (CSP ligada)');
  await p.goto(B + `/agenda?date=${D2}&novo=1`);
  await p.fill('#appointment-patient-search', 'Dois');
  await p.waitForTimeout(150);
  ok(await p.isVisible('.patient-match-option:has-text("Paciente TESTE Dois")'), 'busca enquanto digita encontra o paciente');
  // v54.30 NI2-630: o atalho saiu da lista (combobox com aria-activedescendant) e fica ao lado do campo
  ok(await p.isVisible('#btn-inline-new-patient'), '"+ novo paciente" ao lado da busca');
  await p.click('.patient-match-option:has-text("Paciente TESTE Dois")');
  await p.fill('#new-appointment-date', D2); await p.fill('#new-appointment-time', '15:00'); await p.dispatchEvent('#new-appointment-time', 'change');
  await p.waitForSelector('#patient-same-day-warning:visible', { timeout: 4000 }).catch(() => {});
  ok((await p.textContent('#patient-same-day-warning') || '').includes('10:00'), 'aviso de mesmo dia pela data escolhida: ' + (await p.textContent('#patient-same-day-warning')));
  ok(await p.isVisible('#label-allow-same-day'), 'caixa "Confirmar mesmo com outra consulta" aparece');
  ok((await p.textContent('[data-availability-feedback]')).length > 20, 'prévia de disponibilidade da v53 de volta');
  await p.click('button:has-text("Confirmar agendamento")'); await p.waitForLoadState();
  ok(p.url().includes('error=conflito') && await p.isVisible('#label-allow-same-day'), 'sem confirmar: volta com a caixa visível (funciona até sem JS)');
  ok(!(await p.isChecked('input[name="allow_conflict"]')), 'encaixe não foi exigido');
  await p.check('#input_allow_same_day'); await p.click('button:has-text("Confirmar agendamento")'); await p.waitForLoadState();
  ok(p.url().includes('success=agendado'), 'confirmando "mesmo dia", agenda sem marcar encaixe');
  await p.goto(B + `/agenda?date=${D2}&novo=1`); await p.click('#btn-inline-new-patient');
  ok(await p.isVisible('#patientModal #p_name'), '"+ novo paciente" abre o cadastro');

  console.log('== Agenda: menu "Mais ações"');
  await p.goto(B + `/agenda?view=day&date=${D2}`);
  const rows = await p.$$('details[data-more-actions]');
  const last = rows[rows.length - 1]; await (await last.$('summary')).click(); await p.waitForTimeout(200);
  const items = await last.$$eval('a, button', els => els.map(el => { const r = el.getBoundingClientRect(); const hit = document.elementFromPoint(r.x + r.width / 2, r.y + r.height / 2); return { t: el.textContent.trim(), reach: !!hit && (hit === el || el.contains(hit)) }; }));
  ok(items.length === 3 && items.every(i => i.reach), 'última linha: ' + JSON.stringify(items));

  console.log('== Perfil e cadastro');
  await p.goto(B + '/pacientes/2');
  const badge = await p.textContent('[data-testid="profile-allergy-badge"]');
  ok(badge.includes('Amendoim') && !badge.includes('Sem alergias'), 'cadastro "nega" + anamnese amendoim: selo mostra Amendoim (' + badge.trim() + ')');
  await p.goto(B + '/pacientes/1');
  ok(await p.isVisible('[data-testid="profile-allergy-notes-hint"]'), 'observação com alergia: aviso "confira" (sem virar alergia)');
  await p.goto(B + '/pacientes?novo=1');
  await p.check('#p_allergy_denied'); ok(await p.isDisabled('#p_allergies'), '"Nega alergias" desliga o campo (sem onchange em linha)');
  await p.goto(B + '/pacientes/1/editar'); await p.check('#edit_allergy_denied'); ok(await p.isDisabled('#edit_allergies'), 'edição: "Nega alergias" desliga o campo');

  console.log('== Plano vazio');
  await p.goto(B + '/planos/2');
  ok(await p.isVisible('[data-testid="plan-print-disabled"]') && !(await p.isVisible('a:has-text("Imprimir plano")')), 'plano vazio: "Imprimir plano" desativado');

  ok(csp.length === 0, 'nenhuma violação de CSP (' + csp.length + ') ' + csp.slice(0, 2).join(' | '));
  ok(errs.filter(e => !/favicon|404|401/.test(e)).length === 0, 'nenhum erro de JS: ' + JSON.stringify(errs.slice(0, 3)));
  await p.screenshot({ path: process.env.E2E_OUT+'/v541-final.png' });
  await b.close();
  const f = res.filter(x => !x[0]).length; console.log(`\n${res.length - f} PASS, ${f} FAIL`); process.exit(f ? 1 : 0);
})();
