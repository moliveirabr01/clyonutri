// v54.59 NI2-815 (UX-001/UX-020/UX-021/UX-022 + preservação do épico) — validação independente no navegador real.
// Chromium e Firefox; SQLite descartável com dados TESTE (seed "ni2815"). Imprime PASS/FAIL por verificação,
// grava medidas em ni2815-metrics.json, axe em ni2815-axe-*.json e capturas em E2E_OUT.
const { chromium, firefox } = require('playwright');
const { execSync } = require('child_process');
const fs = require('fs'); const path = require('path');
const res = []; const metrics = {}; const ok = (c, l) => { res.push(!!c); console.log((c ? '  PASS  ' : '  FAIL  ') + l); };
let axe = null; try { axe = require(process.env.AXE_MODULE || 'axe-core'); } catch (e) {}
const OUT = process.env.E2E_OUT || '/tmp'; const shot = (p, n, full = false) => p.screenshot({ path: path.join(OUT, `ni2815-${n}.png`), fullPage: full });
const TZ = 'America/Sao_Paulo';
const loc = d => { const f = new Intl.DateTimeFormat('en-CA', { timeZone: TZ, year: 'numeric', month: '2-digit', day: '2-digit', hour: '2-digit', minute: '2-digit', hour12: false }).formatToParts(d); const g = t => f.find(x => x.type === t).value; return { ymd: `${g('year')}-${g('month')}-${g('day')}`, hm: `${g('hour') === '24' ? '00' : g('hour')}:${g('minute')}` }; };
// contraste WCAG: cor do texto × primeiro fundo opaco dos ancestrais
const contrastOf = (p, sel) => p.evaluate(sel => {
  const el = document.querySelector(sel); if (!el) return null;
  const parse = c => { const m = c.match(/rgba?\(([^)]+)\)/); if (!m) return null; const v = m[1].split(/[ ,/]+/).filter(Boolean).map(Number); return { r: v[0], g: v[1], b: v[2], a: v.length > 3 ? v[3] : 1 }; };
  const lum = ({ r, g, b }) => { const f = x => { x /= 255; return x <= 0.03928 ? x / 12.92 : Math.pow((x + 0.055) / 1.055, 2.4); }; return 0.2126 * f(r) + 0.7152 * f(g) + 0.0722 * f(b); };
  const cs = getComputedStyle(el); let fg = parse(cs.color); let bg = null; let n = el; const bgs = [];
  while (n && n.nodeType === 1) { const c = parse(getComputedStyle(n).backgroundColor); if (c && c.a > 0) { bgs.push(c); if (c.a >= 1) break; } n = n.parentElement; }
  bg = { r: 255, g: 255, b: 255 }; for (const c of bgs.reverse()) bg = { r: c.r * c.a + bg.r * (1 - c.a), g: c.g * c.a + bg.g * (1 - c.a), b: c.b * c.a + bg.b * (1 - c.a) };
  if (fg.a < 1) fg = { r: fg.r * fg.a + bg.r * (1 - fg.a), g: fg.g * fg.a + bg.g * (1 - fg.a), b: fg.b * fg.a + bg.b * (1 - fg.a) };
  const L1 = lum(fg), L2 = lum(bg); const ratio = (Math.max(L1, L2) + 0.05) / (Math.min(L1, L2) + 0.05);
  const hex = c => '#' + [c.r, c.g, c.b].map(x => Math.round(x).toString(16).padStart(2, '0')).join('');
  return { ratio: Math.round(ratio * 100) / 100, fg: hex(fg), bg: hex(bg), fontSize: cs.fontSize, fontWeight: cs.fontWeight, text: el.textContent.trim(), visible: el.checkVisibility() };
}, sel);
const axeRun = async (p, tag, file, opts) => {
  if (!axe) { ok(false, `${tag}: axe-core indisponível`); return null; }
  await p.evaluate(axe.source);
  const a = await p.evaluate(o => axe.run(o.ctx || document, o.run), { ctx: opts && opts.include ? { include: opts.include } : null, run: { runOnly: { type: 'tag', values: ['wcag2a', 'wcag2aa', 'wcag21a', 'wcag21aa', 'wcag22aa'] } } });
  fs.writeFileSync(path.join(OUT, `ni2815-axe-${file}.json`), JSON.stringify({ url: p.url(), violations: a.violations, passes: a.passes.map(x => x.id), incomplete: a.incomplete.map(x => ({ id: x.id, nodes: x.nodes.length })) }, null, 2));
  ok(a.violations.length === 0, `${tag}: axe WCAG 2.2 A/AA sem violações` + (a.violations.length ? ': ' + a.violations.map(v => v.id + '×' + v.nodes.length).join(', ') : ''));
  return a;
};
(async () => {
  const B = process.argv[2], DB = process.argv[3];
  const sq = q => execSync(`php ${process.env.E2E_SQ} ${DB} "${q}"`).toString().trim();
  const ids = JSON.parse(fs.readFileSync(DB + '.ids', 'utf8'));
  const p2name = sq('SELECT name FROM patients WHERE id=2');
  const pB = sq("SELECT id FROM patients WHERE clinic_id=2 ORDER BY id DESC LIMIT 1");
  const planId = sq('SELECT id FROM meal_plans WHERE patient_id=2 ORDER BY id LIMIT 1');
  const todayYmd = loc(new Date()).ymd;
  for (const [bt, engine] of [['chromium', chromium], ['firefox', firefox]]) {
    console.log(`== ${bt}`);
    const b = await engine.launch(); metrics[bt] = {};
    const login = async (ctx, u, to) => { const p = await ctx.newPage(); await p.goto(B + `/__teste_login?u=${u}&c=1&v=0&to=${encodeURIComponent(to)}`, { waitUntil: 'load' }); await p.waitForLoadState('networkidle', { timeout: 8000 }).catch(() => {}); return p; };
    const errs = []; const bad = [];
    const watch = p => { p.on('pageerror', e => errs.push(e.message)); p.on('response', r => { if (r.status() >= 500) bad.push(r.status() + ' ' + r.url().replace(B, '')); }); };

    // ---------------- NI2-817 / UX-020 ----------------
    console.log(`-- ${bt}: NI2-817 (UX-020) "Sem consultas" no dia atual`);
    sq(`DELETE FROM appointments WHERE clinic_id=1 AND substr(scheduled_at,1,10)='${todayYmd}'`);
    for (const [w, h] of [[1440, 900], [375, 812]]) {
      const ctx = await b.newContext({ viewport: { width: w, height: h }, locale: 'pt-BR', timezoneId: TZ });
      const p = await login(ctx, 1, '/dashboard'); watch(p);
      const sel = '.dash-day--today > .dash-day__empty';
      const c = await contrastOf(p, sel);
      metrics[bt][`ux020_empty_${w}`] = c;
      ok(c && c.visible && c.text === 'Sem consultas', `${bt} ${w}px: "${c && c.text}" visível em ${sel}`);
      ok(c && c.ratio >= 4.5, `${bt} ${w}px: contraste ${c && c.ratio}:1 (${c && c.fg} sobre ${c && c.bg}, ${c && c.fontSize}) ≥ 4,5:1`);
      ok(await p.locator('.dash-day--today[aria-current="date"]').count() === 1, `${bt} ${w}px: dia atual continua destacado (aria-current=date)`);
      const red = c && (() => { const n = parseInt(c.fg.slice(1), 16); const r = n >> 16, g = (n >> 8) & 255, bb = n & 255; return r > g + 40 && r > bb + 40; })();
      ok(c && !red && await p.locator('.dash-day--today [role="alert"]').count() === 0, `${bt} ${w}px: vazio não aparece como erro (cor neutra ${c && c.fg}, sem role=alert)`);
      await axeRun(p, `${bt} ${w}px painel com hoje vazio`, `ux020-vazio-${bt}-${w}`);
      await p.locator('.dash-day--today').first().scrollIntoViewIfNeeded(); await shot(p, `ux020-vazio-${bt}-${w}`);
      if (bt === 'chromium') {
        await p.emulateMedia({ forcedColors: 'active' }); await p.reload(); await p.waitForLoadState('networkidle', { timeout: 8000 }).catch(() => {});
        const fc = await contrastOf(p, sel);
        metrics[bt][`ux020_forced_${w}`] = fc;
        ok(fc && fc.visible && fc.text === 'Sem consultas' && fc.ratio >= 4.5 && await p.locator('.dash-day--today[aria-current="date"]').count() === 1, `${bt} ${w}px forced-colors: texto legível (${fc && fc.ratio}:1) e dia atual identificado`);
        await p.locator('.dash-day--today').first().scrollIntoViewIfNeeded(); await shot(p, `ux020-forced-colors-${bt}-${w}`);
        await p.emulateMedia({ forcedColors: 'none' });
      }
      await ctx.close();
    }
    // dia atual preenchido
    {
      const L = loc(new Date(Date.now() + 3 * 3600e3));
      const at = L.ymd === todayYmd ? L.hm : '23:30';
      sq(`INSERT INTO appointments (clinic_id, patient_id, professional_id, scheduled_at, duration_minutes, status, type) VALUES (1, 2, 1, '${todayYmd} ${at}:00', 30, 'confirmed', 'follow_up')`);
      const ctx = await b.newContext({ viewport: { width: 1440, height: 900 }, locale: 'pt-BR', timezoneId: TZ });
      const p = await login(ctx, 1, '/dashboard'); watch(p);
      const filled = await p.locator('.dash-day--today').first();
      ok(await filled.locator('.dash-day__empty').count() === 0 && (await filled.getAttribute('data-count')) === '1', `${bt}: dia atual preenchido mostra 1 consulta e não mostra "Sem consultas"`);
      await axeRun(p, `${bt} 1440px painel com hoje preenchido`, `ux020-preenchido-${bt}`);
      await filled.scrollIntoViewIfNeeded(); await shot(p, `ux020-preenchido-${bt}`);
      await ctx.close();
      sq(`DELETE FROM appointments WHERE clinic_id=1 AND substr(scheduled_at,1,10)='${todayYmd}'`);
    }

    // ---------------- NI2-818 / UX-021 ----------------
    console.log(`-- ${bt}: NI2-818 (UX-021) menu móvel modal`);
    {
      const ctx = await b.newContext({ viewport: { width: 375, height: 812 }, locale: 'pt-BR', timezoneId: TZ, hasTouch: false });
      const p = await login(ctx, 1, '/dashboard'); watch(p);
      const st = () => p.evaluate(() => { const t = document.getElementById('clinical-menu-toggle'), n = document.getElementById('clinical-navigation'), a = document.activeElement; const lb = n.getAttribute('aria-labelledby'); return { expanded: t.getAttribute('aria-expanded'), role: n.getAttribute('role'), modal: n.getAttribute('aria-modal'), name: lb ? (document.getElementById(lb)?.textContent || '').trim() : (n.getAttribute('aria-label') || ''), mainInert: !!document.querySelector('.app-main')?.inert, overflow: document.body.style.overflow, activeInPanel: n.contains(a), activeLabel: (a.getAttribute('aria-label') || a.textContent || '').trim().slice(0, 40), activeIsTrigger: a === t, open: n.classList.contains('is-open') }; });
      const trigger = p.locator('#clinical-menu-toggle');
      await trigger.click(); await p.locator('#clinical-navigation.is-open').waitFor(); await p.waitForTimeout(350);
      let s = await st(); metrics[bt].ux021_open = s;
      ok(s.expanded === 'true' && s.activeInPanel && /Fechar menu/.test(s.activeLabel), `${bt}: abrir Menu foca "${s.activeLabel}"`);
      ok(s.role === 'dialog' && s.modal === 'true' && s.name.length > 0, `${bt}: painel com role=dialog, aria-modal=true e nome "${s.name}"`);
      ok(s.mainInert && s.overflow === 'hidden', `${bt}: fundo inerte (main.inert) e rolagem bloqueada`);
      await shot(p, `ux021-menu-aberto-${bt}`);
      await axeRun(p, `${bt} 375px menu aberto`, `ux021-menu-${bt}`);
      const nf = await p.evaluate(() => document.querySelectorAll('#clinical-navigation a[href], #clinical-navigation button').length);
      let escaped = 0; for (let i = 0; i < nf + 4; i++) { await p.keyboard.press('Tab'); if (!(await st()).activeInPanel) escaped++; }
      ok(escaped === 0, `${bt}: ${nf + 4} Tabs seguidos não saem do painel (${escaped} fugas)`);
      escaped = 0; for (let i = 0; i < nf + 4; i++) { await p.keyboard.press('Shift+Tab'); if (!(await st()).activeInPanel) escaped++; }
      ok(escaped === 0, `${bt}: ${nf + 4} Shift+Tab seguidos não saem do painel (${escaped} fugas)`);
      // reprodução do finding: com o menu aberto, a busca do painel não recebe foco por trás
      const searchFocusable = await p.evaluate(() => { const s = document.getElementById('dash-patient-search'); if (!s) return 'sem-busca'; s.focus(); return document.activeElement === s; });
      ok(searchFocusable === false, `${bt}: #dash-patient-search não recebe foco com o menu aberto (${searchFocusable})`);
      await p.keyboard.press('Escape'); await p.waitForTimeout(100); s = await st();
      ok(s.expanded === 'false' && s.activeIsTrigger && !s.mainInert && s.overflow !== 'hidden' && !s.role, `${bt}: Escape fecha, devolve foco ao Menu e remove inert/role/bloqueio`);
      // depois de fechar: Tab chega ao conteúdo sem estar coberto
      for (let i = 0; i < 25; i++) { await p.keyboard.press('Tab'); const id = await p.evaluate(() => document.activeElement.id); if (id === 'dash-patient-search') break; }
      const cover = await p.evaluate(() => { const s = document.activeElement; if (s.id !== 'dash-patient-search') return 'nao-chegou:' + s.id; const r = s.getBoundingClientRect(); const pts = [[r.left + 6, r.top + 6], [r.right - 6, r.top + 6], [r.left + 6, r.bottom - 6], [r.right - 6, r.bottom - 6]]; return pts.filter(([x, y]) => { const e = document.elementFromPoint(x, y); return e && !e.closest('#clinical-navigation, [data-nav-backdrop]') && (e === s || s.contains(e) || e.contains(s)); }).length; });
      ok(cover === 4, `${bt}: após fechar, Tab chega a #dash-patient-search com os 4 cantos descobertos (${cover})`);
      // botão Fechar
      await trigger.click(); await p.locator('#clinical-navigation.is-open').waitFor(); await p.waitForTimeout(350); await p.locator('#clinical-navigation [data-nav-close]').click(); await p.waitForTimeout(100); s = await st();
      ok(s.expanded === 'false' && s.activeIsTrigger && !s.mainInert, `${bt}: botão Fechar fecha e devolve foco ao Menu`);
      // toque fora (fundo) e no cabeçalho
      await trigger.click(); await p.locator('#clinical-navigation.is-open').waitFor(); await p.waitForTimeout(350);
      const pbox = await p.locator('#clinical-navigation').boundingBox();
      await p.mouse.click(Math.min(370, pbox.x + pbox.width + 10), 600); await p.waitForTimeout(100); s = await st();
      ok(s.expanded === 'false' && s.activeIsTrigger && !s.mainInert, `${bt}: toque fora do painel (x=${Math.round(Math.min(370, pbox.x + pbox.width + 10))}) fecha e devolve foco`);
      await trigger.click(); await p.locator('#clinical-navigation.is-open').waitFor(); await p.waitForTimeout(350);
      // no celular o cabeçalho visível é a barra superior (logo + Menu); toca à direita do painel, na altura do botão, fora dele
      const tb = await trigger.boundingBox(); const pb2 = await p.locator('#clinical-navigation').boundingBox();
      const hx = Math.min(373, Math.max(pb2.x + pb2.width + 4, tb.x + tb.width + 6)), hy = tb.y + tb.height / 2;
      const hitBackdrop = await p.evaluate(([x, y]) => { const e = document.elementFromPoint(x, y); return e ? (e.matches('[data-nav-backdrop]') ? 'backdrop' : e.tagName + '.' + e.className) : 'nada'; }, [hx, hy]);
      await p.mouse.click(hx, hy); await p.waitForTimeout(100); s = await st();
      ok(s.expanded === 'false' && s.activeIsTrigger, `${bt}: toque na área do cabeçalho (${Math.round(hx)},${Math.round(hy)} → ${hitBackdrop}) fecha e devolve foco`);
      // selecionar destino
      await trigger.click(); await p.locator('#clinical-navigation.is-open').waitFor(); await p.waitForTimeout(350);
      await p.locator('#clinical-navigation a[href="/pacientes"]').click(); await p.waitForLoadState('networkidle', { timeout: 8000 }).catch(() => {}); s = await st();
      ok(new URL(p.url()).pathname === '/pacientes' && s.expanded === 'false' && !s.mainInert && !s.role, `${bt}: escolher Pacientes navega e o menu chega fechado, sem inert`);
      // passar para desktop com o menu aberto
      await trigger.click(); await p.locator('#clinical-navigation.is-open').waitFor(); await p.waitForTimeout(350);
      await p.setViewportSize({ width: 1440, height: 900 }); await p.waitForTimeout(300); s = await st();
      ok(!s.role && s.modal === null && !s.mainInert && s.overflow !== 'hidden', `${bt}: ao passar para 1440px saem modalidade, inert e bloqueio de rolagem`);
      ok(await p.locator('#clinical-navigation a[href="/agenda"]').isVisible(), `${bt}: no desktop o menu lateral fica visível`);
      await ctx.close();
    }
    // recepção: menu filtrado e servidor nega
    {
      const ctx = await b.newContext({ viewport: { width: 375, height: 812 }, locale: 'pt-BR', timezoneId: TZ });
      const p = await login(ctx, ids.reception, '/agenda'); watch(p);
      await p.locator('#clinical-menu-toggle').click(); await p.waitForTimeout(150);
      const links = await p.$$eval('#clinical-navigation a[href]', ls => ls.map(l => l.getAttribute('href')));
      metrics[bt].reception_links = links;
      const forbidden = ['/exames', '/planos', '/alimentos', '/receitas', '/configuracoes/equipe'].filter(h => links.includes(h));
      ok(forbidden.length === 0 && links.includes('/agenda'), `${bt}: recepção — menu sem destinos clínicos (${forbidden.join(',') || 'nenhum'}) e com Agenda`);
      ok((await p.evaluate(() => document.getElementById('clinical-navigation').getAttribute('aria-modal'))) === 'true', `${bt}: recepção — menu também modal`);
      await p.keyboard.press('Escape');
      for (const u of ['/exames/catalogo', `/pacientes/2/anamnese`, `/planos/${planId}`]) {
        const r = await p.goto(B + u); const body = (await p.textContent('body')) || '';
        ok(r.status() === 403 && !body.includes('amendoim'), `${bt}: recepção — GET ${u} → HTTP ${r.status()} (sem dado clínico)`);
      }
      await shot(p, `recepcao-403-${bt}`);
      await ctx.close();
    }

    // ---------------- NI2-819 / UX-022 ----------------
    console.log(`-- ${bt}: NI2-819 (UX-022) Catálogo de exames e item ativo`);
    {
      const ctx = await b.newContext({ viewport: { width: 1440, height: 900 }, locale: 'pt-BR', timezoneId: TZ });
      const p = await login(ctx, 1, '/dashboard'); watch(p);
      const navState = () => p.evaluate(() => [...document.querySelectorAll('#clinical-navigation a[aria-current="page"]')].map(a => a.textContent.trim()));
      await p.locator('#clinical-navigation a', { hasText: 'Catálogo de exames' }).click(); await p.waitForLoadState('networkidle', { timeout: 8000 }).catch(() => {});
      for (const u of ['/exames', '/exames/catalogo']) {
        if (new URL(p.url()).pathname !== u) await p.goto(B + u);
        const cur = await navState();
        const lib = p.locator('[data-nav-group="Biblioteca nutricional"] a', { hasText: 'Catálogo de exames' });
        ok(await lib.isVisible() && (await lib.getAttribute('aria-current')) === 'page' && cur.length === 1 && /Catálogo de exames/.test(cur[0]), `${bt}: ${u} — "Catálogo de exames" visível na Biblioteca nutricional com aria-current=page (ativos: ${cur.join(' | ')})`);
        ok(!(await p.locator('#clinical-navigation a[href="/pacientes"]').getAttribute('aria-current')), `${bt}: ${u} — Pacientes não fica ativo`);
        ok(await p.locator('[data-nav-group="Atendimento"] a[href="/pacientes"]').isVisible(), `${bt}: ${u} — grupos estáticos continuam abertos (Atendimento visível)`);
      }
      await shot(p, `ux022-catalogo-${bt}`);
      await axeRun(p, `${bt} 1440px catálogo de exames`, `ux022-catalogo-${bt}`);
      const r = await p.goto(B + '/pacientes/2/exames'); const cur = await navState();
      ok(r.status() === 200 && cur.length === 1 && /Pacientes/.test(cur[0]), `${bt}: /pacientes/2/exames — Pacientes é o item ativo (ativos: ${cur.join(' | ')})`);
      ok((await p.textContent('main')).includes(p2name), `${bt}: /pacientes/2/exames — identidade do paciente preservada ("${p2name}")`);
      ok(!(await p.locator('#clinical-navigation a', { hasText: 'Catálogo de exames' }).getAttribute('aria-current')), `${bt}: /pacientes/2/exames — catálogo não fica ativo`);
      const examLabels = await p.$$eval('#clinical-navigation a', ls => ls.map(l => l.textContent.trim()).filter(t => /exame/i.test(t)));
      ok(examLabels.length === 1 && examLabels[0] === 'Catálogo de exames', `${bt}: menu tem um único destino de exames, nomeado como catálogo (${examLabels.join(' | ')})`);
      await shot(p, `ux022-paciente-exames-${bt}`);
      // isolamento: aba de exames de paciente de outra clínica não abre
      const rb = await p.goto(B + `/pacientes/${pB}/exames`);
      ok(rb.status() !== 200 || !(await p.content()).includes('Clinica B 815'), `${bt}: /pacientes/${pB}/exames (clínica B) não abre (HTTP ${rb.status()})`);
      // estado ativo por destino
      const dest = [['/dashboard', 'Início'], ['/agenda', 'Agenda'], ['/pacientes', 'Pacientes'], ['/planos', 'Planos alimentares'], ['/alimentos', 'Base de alimentos']];
      const wrong = [];
      for (const [u, lbl] of dest) { await p.goto(B + u); const c = await navState(); if (!(c.length === 1 && c[0] === lbl)) wrong.push(`${u}→${c.join('|') || 'nenhum'}`); }
      ok(wrong.length === 0, `${bt}: item ativo correto em ${dest.length} destinos` + (wrong.length ? ': ' + wrong.join(', ') : ''));
      await ctx.close();
      const mc = await b.newContext({ viewport: { width: 375, height: 812 }, locale: 'pt-BR', timezoneId: TZ });
      const mp = await login(mc, 1, '/exames/catalogo'); watch(mp);
      await mp.locator('#clinical-menu-toggle').click(); await mp.waitForTimeout(150);
      const ml = mp.locator('#clinical-navigation a', { hasText: 'Catálogo de exames' });
      ok(await ml.isVisible() && (await ml.getAttribute('aria-current')) === 'page', `${bt} 375px: menu móvel marca "Catálogo de exames" como atual`);
      await mc.close();
    }

    // ---------------- NI2-816 / UX-001 ----------------
    console.log(`-- ${bt}: NI2-816 (UX-001) editor de refeição no celular`);
    {
      const L = loc(new Date(Date.now() - 10 * 60000));
      sq(`DELETE FROM appointments WHERE clinic_id=1 AND patient_id=2 AND status='in_progress'`);
      sq(`INSERT INTO appointments (clinic_id, patient_id, professional_id, scheduled_at, duration_minutes, status, type) VALUES (1, 2, 1, '${L.ymd} ${L.hm}:00', 60, 'in_progress', 'follow_up')`);
      const aid = sq('SELECT MAX(id) FROM appointments');
      const draftStatus = sq(`SELECT status FROM meal_plans WHERE id=${planId}`);
      ok(/draft|rascunho/i.test(draftStatus), `${bt}: plano ${planId} está em rascunho (${draftStatus}) e consulta #${aid} em andamento`);
      for (const url of [`/planos/${planId}`, `/planos/${planId}?atendimento=${aid}`]) {
        const ctx = await b.newContext({ viewport: { width: 375, height: 812 }, locale: 'pt-BR', timezoneId: TZ });
        const p = await login(ctx, 1, url); watch(p);
        await p.evaluate(() => window.scrollTo(0, 0));
        const g = await p.evaluate(() => { const r = document.getElementById('quick-meal-name')?.getBoundingClientRect(); return r ? { x: r.x, y: r.y, w: r.width, h: r.height, bottom: r.bottom, right: r.right, scrollY: window.scrollY, vh: innerHeight, vw: innerWidth, docW: document.documentElement.scrollWidth } : null; });
        metrics[bt][`ux001 ${url}`] = g;
        ok(g && g.scrollY === 0 && g.y >= 0 && g.bottom <= g.vh && g.x >= 0 && g.right <= g.vw, `${bt} ${url}: #quick-meal-name inteiro na 1ª tela sem rolar (y=${g && g.y.toFixed(2)}, altura=${g && g.h.toFixed(0)}, base=${g && g.bottom.toFixed(2)} ≤ ${g && g.vh})`);
        ok(g && g.docW <= g.vw, `${bt} ${url}: sem rolagem horizontal (${g && g.docW} ≤ ${g && g.vw})`);
        ok(await p.locator('[data-testid="profile-allergy-badge"]').first().isVisible(), `${bt} ${url}: alerta de alergia visível`);
        ok((await p.textContent('main')).includes(p2name), `${bt} ${url}: identidade do paciente visível`);
        const back = await p.$$eval('main a[href]', (ls, aid) => ls.map(l => l.getAttribute('href')).filter(h => h === `/consulta/${aid}` || h.startsWith(`/consulta/${aid}?`)), aid);
        ok(back.length > 0, `${bt} ${url}: retorno ao atendimento (/consulta/${aid}) disponível`);
        await shot(p, `ux001-editor-${bt}${url.includes('atendimento') ? '-atendimento' : ''}`);
        if (url.includes('atendimento')) {
          const tools = p.locator('.ux52-context-tools').first();
          ok(await tools.count() === 1 && !(await tools.evaluate(e => e.open)), `${bt}: disclosure "Contexto clínico e etapas" existe e começa fechado`);
          // chegar ao summary só por teclado
          let reached = false; for (let i = 0; i < 40; i++) { await p.keyboard.press('Tab'); if (await p.evaluate(() => document.activeElement?.matches('.ux52-context-tools > summary'))) { reached = true; break; } }
          ok(reached, `${bt}: summary do disclosure alcançável por Tab`);
          await p.keyboard.press('Enter'); await p.waitForTimeout(100);
          ok(await tools.evaluate(e => e.open), `${bt}: Enter abre o disclosure`);
          // dentro do disclosure: resumo do progresso (outro <details>) e as etapas com estado real
          const prog = tools.locator('details.ux52-progress').first();
          let reachedProg = false; for (let i = 0; i < 10; i++) { await p.keyboard.press('Tab'); if (await p.evaluate(() => document.activeElement?.matches('details.ux52-progress > summary'))) { reachedProg = true; break; } }
          const progTxt = ((await prog.locator('summary').first().textContent()) || '').replace(/\s+/g, ' ').trim();
          ok(reachedProg && /\d+ de \d+ etapas conclu/.test(progTxt), `${bt}: resumo do progresso alcançável por Tab: "${progTxt.slice(0, 90)}"`);
          await p.keyboard.press('Enter'); await p.waitForTimeout(100);
          const steps = await p.$$eval('.ux52-context-tools li[data-step]', ls => ls.filter(l => l.checkVisibility()).map(l => ({ s: l.dataset.step, state: l.dataset.state, href: l.querySelector('a[href]')?.getAttribute('href') || '' })));
          metrics[bt].ux001_hidden_duplicate_steps = await p.$$eval('.ux52-context-tools li[data-step]', ls => ls.filter(l => !l.checkVisibility()).length);
          metrics[bt].ux001_steps = { progress: progTxt, steps };
          ok(steps.length >= 4 && steps.every(x => x.state && x.href.includes('atendimento=')), `${bt}: disclosure lista ${steps.length} etapas com estado real e link no atendimento (${steps.map(x => x.s + ':' + x.state).join(', ')})`);
          let tabbed = new Set(); for (let i = 0; i < 80 && tabbed.size < steps.length; i++) { await p.keyboard.press('Tab'); const st = await p.evaluate(() => { const a = document.activeElement; return a && a.matches('li[data-step] a') && a.closest('.ux52-context-tools') && a.closest('li[data-step]').checkVisibility() ? a.closest('li[data-step]').dataset.step : null; }); if (st) tabbed.add(st); }
          ok(steps.length > 0 && tabbed.size === steps.length, `${bt}: todas as ${steps.length} etapas recebem foco por Tab (${[...tabbed].join(', ')})`);
          await shot(p, `ux001-disclosure-aberto-${bt}`, true);
          await axeRun(p, `${bt} 375px editor com disclosure aberto`, `ux001-editor-${bt}`);
          // salvar e retomar
          await p.keyboard.press('Shift+Tab'); await tools.evaluate(e => { e.open = false; });
          await p.evaluate(() => window.scrollTo(0, 0));
          const meal = `TESTE NI2-815 ${bt} ${Date.now() % 100000}`;
          await p.locator('#quick-meal-name').focus(); await p.keyboard.type(meal); await p.keyboard.press('Enter');
          await p.locator('[data-quick-plan] [data-state="saved"]').first().waitFor({ timeout: 8000 }).catch(() => {});
          const saved = await p.locator('[data-quick-plan] [data-state="saved"]').first();
          const savedOk = await saved.count() && await saved.isVisible();
          ok(savedOk, `${bt}: estado "salvo" visível após adicionar refeição por teclado`);
          ok(+sq(`SELECT COUNT(*) FROM meal_plan_meals WHERE meal_plan_id=${planId} AND name='${meal}'`) === 1, `${bt}: refeição gravada uma única vez no banco`);
          await p.reload(); await p.waitForLoadState('networkidle', { timeout: 8000 }).catch(() => {});
          ok((await p.textContent('main')).includes(meal) && /atendimento=/.test(p.url()), `${bt}: após recarregar, refeição retomada e contexto do atendimento mantido`);
          // reflow 320 px (≈ 400 %): estado de salvamento legível
          await p.setViewportSize({ width: 320, height: 640 }); await p.waitForTimeout(200);
          await p.locator('#quick-meal-name').fill(meal + ' r'); await p.locator('[data-quick-meal] button[type=submit]').click();
          await p.locator('[data-quick-plan] [data-state="saved"]').first().waitFor({ timeout: 8000 }).catch(() => {});
          const rf = await p.evaluate(() => { const s = document.querySelector('[data-quick-plan] [data-state="saved"]'); const r = s?.getBoundingClientRect(); return { docW: document.documentElement.scrollWidth, saved: s ? { text: s.textContent.trim(), w: r.width, h: r.height, left: r.left, right: r.right, vis: s.checkVisibility() } : null }; });
          metrics[bt].ux001_reflow320 = rf;
          ok(rf.docW <= 320 && rf.saved && rf.saved.vis && rf.saved.right <= 320 && rf.saved.left >= 0 && rf.saved.text.length > 0, `${bt} 320px: sem rolagem horizontal (${rf.docW}) e estado "${rf.saved && rf.saved.text}" legível dentro da tela`);
          await shot(p, `ux001-reflow-320-${bt}`);
        }
        await ctx.close();
      }
    }

    // ---------------- Preservação do épico ----------------
    console.log(`-- ${bt}: preservação (UX-002, UX-018, CSRF, alergia, rascunhos)`);
    {
      const now = new Date(); const LA = loc(new Date(now - 20 * 60000)); const LB = loc(new Date(now - 5 * 60000));
      sq(`UPDATE appointments SET status='completed' WHERE clinic_id=1 AND patient_id=2 AND status='in_progress'`);
      sq(`INSERT INTO appointments (clinic_id, patient_id, professional_id, scheduled_at, duration_minutes, status, type) VALUES (1, 2, 1, '${LA.ymd} ${LA.hm}:00', 30, 'in_progress', 'follow_up')`); const A = sq('SELECT MAX(id) FROM appointments');
      sq(`INSERT INTO appointments (clinic_id, patient_id, professional_id, scheduled_at, duration_minutes, status, type) VALUES (1, 2, 1, '${LB.ymd} ${LB.hm}:00', 30, 'confirmed', 'follow_up')`); const Bp = sq('SELECT MAX(id) FROM appointments');
      const ctx = await b.newContext({ viewport: { width: 1440, height: 900 }, locale: 'pt-BR', timezoneId: TZ });
      const p = await login(ctx, 1, '/dashboard'); watch(p);
      for (const ap of [A, Bp]) {
        const r = await p.goto(B + `/pacientes/2/pre-consulta?atendimento=${ap}`);
        const links = await p.$$eval('a[href]', ls => ls.map(l => l.getAttribute('href')).filter(h => /^\/pacientes\/2(\?|$)/.test(h)));
        ok(r.status() === 200 && links.length > 0 && links.every(h => h.includes(`atendimento=${ap}`)), `${bt}: UX-002 Pré-consulta (consulta #${ap}) → links do perfil mantêm atendimento=${ap} [${links.slice(0, 3).join(' ')}]`);
        if (links.length) { await p.goto(B + links[0]); ok(new URL(p.url()).searchParams.get('atendimento') === ap && (await p.textContent('main')).includes(`#${ap}`), `${bt}: UX-002 perfil aberto pela Pré-consulta mostra a consulta #${ap}`); }
      }
      // CSRF inválido não grava; contexto mantido
      await p.goto(B + `/pacientes/2/anamnese?atendimento=${A}`);
      const before = sq('SELECT COUNT(*)||\'/\'||COALESCE(MAX(updated_at),\'\') FROM anamneses WHERE patient_id=2');
      const hasForm = await p.locator('form[action*="/pacientes/2/anamnese"] input[name="_csrf_token"]').count();
      if (hasForm) {
        await p.evaluate(() => { document.querySelectorAll('form[action*="/pacientes/2/anamnese"] input[name="_csrf_token"]').forEach(i => { i.value = 'token-invalido-teste'; }); });
        const ta = p.locator('form[action*="/pacientes/2/anamnese"] textarea:visible').first(); if (await ta.count()) await ta.fill('TESTE CSRF não deve gravar');
        const resp = p.waitForResponse(r => r.request().method() === 'POST' && /\/pacientes\/2\/anamnese|\/api\/workflow\/anamnesis\/2/.test(r.url()), { timeout: 8000 }).catch(() => null);
        await p.locator('form[action*="/pacientes/2/anamnese"] button[type=submit]:visible').first().click(); const rr = await resp; await p.waitForLoadState();
        const after = sq('SELECT COUNT(*)||\'/\'||COALESCE(MAX(updated_at),\'\') FROM anamneses WHERE patient_id=2');
        const alertTxt = ((await p.locator('[role=alert]:visible, .op-message--error:visible, [data-workflow-error]:visible').allTextContents()).join(' ').replace(/\s+/g, ' ').trim()).slice(0, 140);
        ok(after === before && rr && rr.status() >= 400 && rr.status() < 500 && /atendimento=/.test(p.url()), `${bt}: UX-002 envio com CSRF inválido não grava (antes ${before} = depois ${after}, ${rr && rr.request().url().replace(B, '')} HTTP ${rr && rr.status()}), contexto mantido; aviso: "${alertTxt}"`);
        await p.goBack().catch(() => {}); await p.goForward().catch(() => {});
        ok(sq('SELECT COUNT(*)||\'/\'||COALESCE(MAX(updated_at),\'\') FROM anamneses WHERE patient_id=2') === before, `${bt}: UX-002 voltar/avançar após o erro não repete a gravação`);
      } else ok(false, `${bt}: formulário da anamnese com CSRF não encontrado`);
      // UX-018: liberar avaliação pede confirmação mesmo com ?atendimento= no action; cancelar não muda nada
      sq(`INSERT INTO anthropometric_assessments (clinic_id, patient_id, professional_id, weight_kg, height_cm, bmi, assessed_at, created_at, is_shared) VALUES (1, 2, 1, 70, 165, 25.7, '${LA.ymd} 08:00:00', '${LA.ymd} 08:00:00', 0)`);
      const asid = sq('SELECT MAX(id) FROM anthropometric_assessments');
      await p.goto(B + `/pacientes/2/antropometria?atendimento=${A}`);
      const form = p.locator(`form[action*="/antropometria/${asid}/compartilhar"]`).first();
      const act = await form.getAttribute('action').catch(() => null);
      let dialogs = [];
      p.once('dialog', d => { dialogs.push(d.message()); d.dismiss(); });
      await form.locator('button[type=submit]').click().catch(() => {}); await p.waitForTimeout(500);
      ok(dialogs.length === 1 && /Liberar esta avaliação/.test(dialogs[0]) && sq(`SELECT is_shared FROM anthropometric_assessments WHERE id=${asid}`) === '0', `${bt}: UX-018 liberar avaliação (action ${act}) pede confirmação e cancelar não muda (is_shared=0)`);
      p.once('dialog', d => { dialogs.push(d.message()); d.accept(); });
      await p.locator(`form[action*="/antropometria/${asid}/compartilhar"] button[type=submit]`).first().click(); await p.waitForLoadState();
      ok(sq(`SELECT is_shared FROM anthropometric_assessments WHERE id=${asid}`) === '1' && /atendimento=/.test(p.url()), `${bt}: UX-018 confirmar libera (is_shared=1) e mantém o atendimento na URL`);
      sq(`UPDATE appointments SET status='completed' WHERE id IN (${A},${Bp})`);
      await ctx.close();
    }
    ok(errs.length === 0 && bad.length === 0, `${bt}: sem erro de JavaScript nem resposta 5xx` + (errs[0] ? ': ' + errs[0].slice(0, 160) : '') + (bad[0] ? ' ' + bad.slice(0, 3).join(',') : ''));
    await b.close();
  }
  fs.writeFileSync(path.join(OUT, 'ni2815-metrics.json'), JSON.stringify(metrics, null, 2));
  const n = res.filter(x => !x).length; console.log(`\n${res.length - n} PASS, ${n} FAIL`); process.exit(n ? 1 : 0);
})().catch(e => { console.error(e); console.log('\n0 PASS, 1 FAIL'); process.exit(1); });
