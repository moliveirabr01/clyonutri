// v54.28 NI2-687: migração do Design System nas telas legadas e alertas clínicos.
// Cenário 1: consulta, perfil, agenda, plano, exames e configurações em 375/656/1280/1440 px (e 1280 a 200% = 640 px):
//   sem rolagem lateral, botões dentro da tela, menus que não esticam, foco visível, alertas com ícone e texto
//   (não só cor), erro clínico não escondido por mensagem de sucesso, axe sem violação séria/crítica.
// Cenário 2: duas clínicas, dois pacientes: o servidor recusa sem expor nome nem metadado.
// Cenário 3: erro de validação, falha de rede e recarga: o que foi gravado continua, o estado fica claro; teclado.
// Dados TESTE, SQLite descartável.
const { chromium } = require('playwright');
const { execSync } = require('child_process');
const fs = require('fs');
const res = []; const ok = (c, l) => { res.push(!!c); console.log((c ? '  PASS  ' : '  FAIL  ') + l); };
const clean = s => String(s).replace(/Error:/g, 'Erro -');
(async () => {
  const B = process.argv[2], DB = process.argv[3];
  const sq = q => execSync(`php ${process.env.E2E_SQ} ${DB} "${q}"`).toString().trim();
  const AXE = [process.env.E2E_AXE, '/workspace/m1103/n233-tools/node_modules/axe-core/axe.min.js', (() => { try { return require.resolve('axe-core/axe.min.js'); } catch (e) { return ''; } })()].find(f => f && fs.existsSync(f));
  const AXE_SRC = AXE ? fs.readFileSync(AXE, 'utf8') : '';
  const b = await chromium.launch();
  const errs = [];
  const watch = p => { p.on('pageerror', e => errs.push(clean(e.message))); p.on('dialog', d => d.dismiss()); };
  const login = async (ctx, u, c, to) => { const p = await ctx.newPage(); watch(p); await p.goto(`${B}/__teste_login?u=${u}&c=${c}&v=0&to=${encodeURIComponent(to)}`); return p; };

  const PAGES = [['consulta', '/consulta/1'], ['perfil', '/pacientes/2'], ['agenda', '/agenda'], ['planos', '/planos'], ['plano', '/planos/1'],
    ['exames', '/pacientes/2/exames'], ['configurações', '/configuracoes'], ['assinatura', '/configuracoes/assinatura']];

  // Elementos clicáveis visíveis fora da tela (ignora os que estão dentro de área com rolagem própria, como tabelas).
  const outOfView = () => {
    const vw = document.documentElement.clientWidth, bad = [];
    for (const el of document.querySelectorAll('button, .btn, a.op-button, .op-button, summary, [role="button"], .ds-page-actions a')) {
      const r = el.getBoundingClientRect(), cs = getComputedStyle(el);
      if (!r.width || !r.height || cs.visibility === 'hidden') continue;
      let a = el.parentElement, scroller = false;
      while (a && a !== document.body) { const o = getComputedStyle(a).overflowX; if (o === 'auto' || o === 'scroll' || o === 'hidden') { scroller = true; break; } a = a.parentElement; }
      if (scroller) continue;
      if (r.left < -1 || r.right > vw + 1) bad.push((el.textContent || el.getAttribute('aria-label') || el.tagName).trim().slice(0, 30));
    }
    return bad;
  };
  // Alertas visíveis precisam de ícone (forma) e de texto/papel, não só cor.
  const alertsNoColorOnly = () => {
    const out = [];
    for (const el of document.querySelectorAll('.op-message, .op-alert, .ds-clinical-flag[data-severity]')) {
      const r = el.getBoundingClientRect(); if (!r.width) continue;
      const bf = getComputedStyle(el, '::before');
      const icon = (bf.content && bf.content !== 'none' && bf.content !== 'normal' && (bf.maskImage !== 'none' || bf.webkitMaskImage !== 'none' || bf.backgroundImage !== 'none' || /\S/.test(bf.content.replace(/"/g, ''))))
        || !!el.querySelector('svg, .op-alert__icon, .cn-flag-icon');
      const text = (el.textContent || '').trim().length > 2;
      out.push({ cls: el.className, icon, text, sev: el.getAttribute('data-severity') || '' });
    }
    return out;
  };

  for (const w of [375, 656, 1280, 1440, 640]) {
    const label = w === 640 ? '1280 a 200%' : `${w} px`;
    console.log(`== Jornada crítica em ${label}`);
    const ctx = await b.newContext({ viewport: { width: w, height: w < 700 ? (w === 640 ? 430 : 812) : 900 } });
    const p = await login(ctx, 1, 1, '/dashboard');
    for (const [name, url] of PAGES) {
      const r = await p.goto(B + url);
      const st = r ? r.status() : 0;
      ok(st === 200 && new URL(p.url()).pathname === url, `${label} ${name}: abre (HTTP ${st})`);
      const over = await p.evaluate(() => document.documentElement.scrollWidth - document.documentElement.clientWidth);
      ok(over <= 1, `${label} ${name}: sem rolagem lateral (${over}px)`);
      const bad = await p.evaluate(outOfView);
      ok(bad.length === 0, `${label} ${name}: botões dentro da tela` + (bad.length ? ': ' + bad.join(' | ') : ''));
      const al = await p.evaluate(alertsNoColorOnly);
      const weak = al.filter(a => !a.icon || !a.text);
      ok(weak.length === 0, `${label} ${name}: ${al.length} alerta(s) com ícone e texto` + (weak.length ? ': ' + weak.map(x => x.cls).join(' | ') : ''));
      if (w === 1280 || w === 375) {
        await p.keyboard.press('Tab'); await p.keyboard.press('Tab');
        const f = await p.evaluate(() => { const e = document.activeElement; if (!e || e === document.body) return null; const cs = getComputedStyle(e); return { o: cs.outlineStyle, ow: parseFloat(cs.outlineWidth), bs: cs.boxShadow }; });
        ok(f && ((f.o !== 'none' && f.ow >= 2) || (f.bs && f.bs !== 'none')), `${label} ${name}: foco do teclado visível`);
        if (AXE_SRC) {
          await p.evaluate(AXE_SRC);
          const v = await p.evaluate(async () => (await window.axe.run(document, { resultTypes: ['violations'] })).violations.filter(x => ['serious', 'critical'].includes(x.impact)).map(x => x.id + '×' + x.nodes.length));
          ok(v.length === 0, `${label} ${name}: axe sem violação séria/crítica` + (v.length ? ': ' + v.join(', ') : ''));
        } else ok(false, 'axe-core disponível');
      }
    }
    // Menu "Mais ações" do plano não estica nem sai da tela
    await p.goto(B + '/planos/1');
    await p.click('[data-testid="plan-more-actions"]');
    const mb = await p.locator('.plan-actions-menu').boundingBox();
    const vw = await p.evaluate(() => document.documentElement.clientWidth);
    ok(mb && mb.x >= 0 && mb.x + mb.width <= vw + 1 && mb.width <= Math.min(vw, 440), `${label} plano: menu "Mais ações" dentro da tela e sem esticar (${mb ? Math.round(mb.width) : '?'}px de ${vw}px)`);
    const items = await p.$$eval('.plan-actions-menu a, .plan-actions-menu button', els => els.filter(e => e.getBoundingClientRect().height).map(e => Math.round(e.getBoundingClientRect().height)));
    ok(items.length > 0 && items.every(h => h >= 44), `${label} plano: itens do menu com alvo de 44 px (${items.join(',')})`);
    await ctx.close();
  }

  console.log('== Alerta clínico x aviso x sucesso');
  {
    const ctx = await b.newContext({ viewport: { width: 1280, height: 900 } });
    const p = await login(ctx, 1, 1, '/pacientes/2');
    const flag = p.locator('.ds-clinical-flag[data-severity="critical"]').first();
    ok(await flag.count() === 1 && /amendoim/i.test(await flag.innerText()), 'perfil: alergia (amendoim, do cadastro TESTE) aparece como alerta clínico crítico');
    ok(/Alerta clínico/i.test(await flag.textContent()), 'perfil: alerta crítico tem rótulo para leitor de tela');
    for (const url of ['/pacientes/2/prontuario?success=registro_adicionado&error=falha', '/pacientes/2/anamnese?success=1&error=falha']) {
      await p.goto(B + url);
      const info = await p.evaluate(() => {
        const e = document.querySelector('.op-message--error, .op-message--critical, [data-testid="page-error-message"]');
        const s = document.querySelector('.op-message--success');
        const shape = el => el ? getComputedStyle(el, '::before').maskImage || getComputedStyle(el, '::before').webkitMaskImage : '';
        return { e: !!e, s: !!s, first: e && s ? !!(e.compareDocumentPosition(s) & Node.DOCUMENT_POSITION_FOLLOWING) : !!e, role: e ? (e.getAttribute('role') || e.closest('[role]')?.getAttribute('role')) : '', txt: e ? e.textContent : '', diff: shape(e) !== shape(s) };
      });
      ok(info.e && info.first, `${url.split('?')[0]}: erro aparece e vem antes de qualquer sucesso`);
      ok(info.role === 'alert', `${url.split('?')[0]}: erro anunciado (role=alert)`);
      ok(/Erro/.test(info.txt) && !/PDO|SQLSTATE|Exception|\.php/.test(info.txt), `${url.split('?')[0]}: erro em texto claro, sem detalhe técnico`);
      if (info.s) ok(info.diff, `${url.split('?')[0]}: ícone do erro diferente do ícone de sucesso`);
    }
    await ctx.close();
  }

  console.log('== Cenário 2: duas clínicas');
  {
    const A = /Paciente TESTE Antigo|Paciente TESTE Dois|amendoim|TESTE Plano Fase 1/i, BN = /Paciente TESTE Clinica B/;
    const cb = await b.newContext(); const pb = await login(cb, 2, 2, '/dashboard');
    for (const url of ['/pacientes/2', '/pacientes/2/exames', '/planos/1', '/consulta/1', '/pacientes/2/anamnese']) {
      const r = await pb.goto(B + url); const body = (await pb.textContent('body').catch(() => '')) || '';
      ok(!A.test(body) && !(r && r.status() === 200 && new URL(pb.url()).pathname === url) && !/SQLSTATE|PDOException|Stack trace/.test(body), `clínica B → ${url}: recusado sem dado de A (HTTP ${r ? r.status() : 0})`);
    }
    const meals0 = sq('SELECT COUNT(*) FROM meal_plan_meals WHERE meal_plan_id=1');
    await pb.goto(B + '/pacientes');
    const post = await pb.evaluate(async () => { const tok = document.querySelector('input[name="_csrf_token"]')?.value || document.querySelector('meta[name="csrf-token"]')?.content || '';
      const r = await fetch('/planos/1/refeicoes', { method: 'POST', body: new URLSearchParams({ _csrf_token: tok, name: 'TESTE invasão' }) }); return { s: r.status, t: (await r.text()).slice(0, 20000) }; });
    ok(sq('SELECT COUNT(*) FROM meal_plan_meals WHERE meal_plan_id=1') === meals0 && !A.test(post.t), `clínica B: POST no plano de A não grava nem expõe dado (HTTP ${post.s})`);
    await cb.close();
    const ca = await b.newContext(); const pa = await login(ca, 1, 1, '/dashboard');
    const r = await pa.goto(B + '/pacientes/3'); const body = (await pa.textContent('body').catch(() => '')) || '';
    ok(!BN.test(body) && !(r && r.status() === 200 && new URL(pa.url()).pathname === '/pacientes/3'), `clínica A → /pacientes/3: recusado sem dado de B (HTTP ${r ? r.status() : 0})`);
    await ca.close();
  }

  console.log('== Cenário 3: validação, rede, recarga e teclado');
  {
    const ctx = await b.newContext({ viewport: { width: 1280, height: 900 } });
    const p = await login(ctx, 1, 1, '/planos/1');
    const meals0 = sq('SELECT COUNT(*) FROM meal_plan_meals WHERE meal_plan_id=1');
    await p.evaluate(async () => { const tok = document.querySelector('input[name="_csrf_token"]').value;
      await fetch('/planos/1/refeicoes', { method: 'POST', redirect: 'manual', body: new URLSearchParams({ _csrf_token: tok, name: '   ' }) }); });
    await p.goto(B + '/planos/1');
    const err = p.locator('[role="alert"]:has-text("nome da refeição é obrigatório")').first();
    ok(await err.count() === 1 && await err.isVisible(), 'validação: refeição sem nome mostra erro claro com role=alert');
    ok(sq('SELECT COUNT(*) FROM meal_plan_meals WHERE meal_plan_id=1') === meals0 && /Café da Manhã/.test(await p.textContent('body')), 'validação: nada gravado e a refeição já salva continua na tela');
    await p.reload();
    ok(await p.locator('[role="alert"]:has-text("nome da refeição é obrigatório")').count() === 0 && /Café da Manhã/.test(await p.textContent('body')), 'recarga: o erro antigo some e o que foi gravado continua');
    // falha de rede na busca de alimentos (serviço externo fora)
    const q = p.locator('#quick-food-query');
    if (await q.count()) {
      await p.route('**/catalogo/alimentos/busca**', r => r.abort('failed'));
      await q.fill('pão');
      const picker = p.locator('[data-food-picker]:has(#quick-food-query)');
      await picker.locator('text=Não foi possível buscar alimentos. Tente novamente.').waitFor({ timeout: 10000 }).catch(() => {});
      ok(await picker.locator('[data-food-retry]').isVisible(), 'rede: busca de alimentos falhou com mensagem clara e "Tentar novamente"');
      await p.unroute('**/catalogo/alimentos/busca**');
      await picker.locator('[data-food-retry]').focus(); await p.keyboard.press('Enter');
      await picker.locator('button.food-result').filter({ hasText: 'Pão, trigo, francês' }).first().waitFor({ timeout: 10000 }).catch(() => {});
      ok(await picker.locator('button.food-result').filter({ hasText: 'Pão, trigo, francês' }).first().isVisible(), 'rede: "Tentar novamente" pelo teclado recupera a busca');
    } else ok(false, 'editor do plano tem busca rápida de alimentos');
    // teclado: botão principal de /planos abre a janela, Esc fecha e o foco volta
    await p.goto(B + '/planos');
    const cta = p.locator('[data-testid="plans-page-header"] .ds-page-actions button').first();
    ok(await cta.count() === 1 && /Novo plano alimentar/.test(await cta.innerText()), '/planos: um botão principal identificável no cabeçalho');
    await cta.focus();
    const fv = await cta.evaluate(e => { const cs = getComputedStyle(e); return cs.outlineStyle !== 'none' || cs.boxShadow !== 'none'; });
    ok(fv, '/planos: foco visível no botão principal');
    await p.keyboard.press('Enter');
    const dlg = p.locator('[role="dialog"]:visible, dialog[open]').first();
    await dlg.waitFor({ timeout: 5000 }).catch(() => {});
    ok(await dlg.count() === 1, '/planos: Enter abre a janela de novo plano');
    // espera a janela terminar de abrir (foco dentro dela), como uma pessoa faria, antes do Esc
    await p.waitForFunction(() => { const d = document.querySelector('[role="dialog"]:not([hidden]), dialog[open]'); return d && d.contains(document.activeElement); }, null, { timeout: 3000 }).catch(() => {});
    await p.keyboard.press('Escape');
    await p.locator('[role="dialog"]:visible, dialog[open]').first().waitFor({ state: 'hidden', timeout: 3000 }).catch(() => {});
    ok(await p.locator('[role="dialog"]:visible, dialog[open]').count() === 0, '/planos: Esc fecha a janela');
    await ctx.close();
  }
  ok(errs.length === 0, 'nenhum erro de JavaScript' + (errs.length ? ': ' + errs.slice(0, 3).join(' | ') : ''));
  await b.close();
  const f = res.filter(x => !x).length; console.log(`\n${res.length - f} PASS, ${f} FAIL`); process.exit(f ? 1 : 0);
})();
