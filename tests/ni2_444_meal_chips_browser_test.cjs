// NI2-444 (chips de refeição) e NI2-437 (envio pelo WhatsApp) no navegador real, com a CSP ativa.
// public/index.php em `php -S`, SQLite isolado (fixture da NI2-437), APP_URL absoluta.
// Requer PLAYWRIGHT_MODULE e AXE_MODULE (e, se preciso, CHROMIUM_PATH); sem eles, o teste é pulado.
'use strict';
const { spawn, execFileSync } = require('node:child_process');
const { mkdtempSync, rmSync } = require('node:fs');
const { join, resolve } = require('node:path');
const net = require('node:net');
const assert = require('node:assert/strict');

let playwright; let axePath;
try { playwright = require(process.env.PLAYWRIGHT_MODULE || 'playwright'); axePath = require.resolve(process.env.AXE_MODULE || 'axe-core'); } catch { /* opcional */ }
if (!playwright || !axePath) { console.log('SKIP: instale playwright e axe-core (PLAYWRIGHT_MODULE/AXE_MODULE).'); process.exit(0); }

const root = resolve(__dirname, '..');
const dir = mkdtempSync('/tmp/ni2-437-chips-');
let passed = 0;
function check(name, ok) { assert.ok(ok, name); passed++; console.log('PASS ' + name); }

(async () => {
  const sock = net.createServer(); await new Promise((r) => sock.listen(0, '127.0.0.1', r)); const port = sock.address().port; await new Promise((r) => sock.close(r));
  const base = 'http://127.0.0.1:' + port;
  const env = { ...process.env, DB_CONNECTION: 'sqlite', DB_DATABASE: join(dir, 'test.sqlite'), APP_URL: base, MAIL_DRIVER: 'null' };
  execFileSync('php', ['tests/support/ni2_437_fixture.php', 'prepare'], { cwd: root, env });
  const server = spawn('php', ['-d', 'session.save_path=' + dir, '-S', '127.0.0.1:' + port, '-t', 'public'], { cwd: root, env, stdio: 'ignore' });
  for (let i = 0; i < 80; i++) { try { await fetch(base + '/login'); break; } catch { await new Promise((r) => setTimeout(r, 50)); } }
  const browser = await playwright.chromium.launch(process.env.CHROMIUM_PATH ? { executablePath: process.env.CHROMIUM_PATH, headless: false, args: ['--headless=new'] } : {});
  try {
    const ctx = await browser.newContext({ viewport: { width: 1280, height: 900 }, permissions: ['clipboard-read', 'clipboard-write'] });
    const p = await ctx.newPage();
    const cspErrors = [];
    p.on('console', (m) => { if (m.type() === 'error' && /Content Security Policy|Refused to/i.test(m.text())) cspErrors.push(m.text()); });
    p.on('pageerror', (e) => cspErrors.push('pageerror: ' + e.message));
    const axe = async (page, selector, label) => {
      await page.addScriptTag({ path: axePath });
      const v = await page.evaluate(async (sel) => (await window.axe.run(sel ? document.querySelector(sel) : document,
        { runOnly: ['wcag2a', 'wcag2aa', 'wcag21a', 'wcag21aa'] })).violations.map((x) => `${x.id} (${x.nodes.length})`), selector);
      check(`axe WCAG 2.1 AA sem violações: ${label}${v.length ? ' → ' + v.join(', ') : ''}`, v.length === 0);
    };

    await p.goto(base + '/login');
    await p.fill('input[name=email]', 'pre1@example.test');
    await p.fill('input[name=password]', 'PreTeste@2026');
    await Promise.all([p.waitForNavigation(), p.locator('form[action="/login"] button[type=submit]').click()]);

    // --- NI2-437: WhatsApp com APP_URL absoluta ---
    await p.goto(base + '/pacientes/1/pre-consulta');
    await p.check('input[name="objectives[]"][value="glycemic_control"]');
    await Promise.all([p.waitForNavigation(), p.locator('form:has(input[name=mode]) button[type=submit]').click()]);
    check('Canal padrão marcado: WhatsApp', (await p.locator('input[name=channel]:checked').getAttribute('value')) === 'whatsapp');
    await Promise.all([p.waitForNavigation(), p.locator('form[action$="/enviar"] button[type=submit]').click()]);
    const href = await p.locator('[data-testid=pc-whatsapp]').getAttribute('href');
    const wa = new URL(href);
    const text = wa.searchParams.get('text') || '';
    check('Botão do WhatsApp aponta para wa.me', wa.host === 'wa.me');
    check('Mensagem leva o link absoluto do paciente', new RegExp('^' + base.replace(/[.:/]/g, '\\$&') + '/pre-consulta/[A-Za-z0-9_-]{43}$', 'm').test(text.split('\n').find((l) => l.includes('/pre-consulta/')) || ''));
    check('Link do WhatsApp abre em nova aba sem referrer', (await p.locator('[data-testid=pc-whatsapp]').getAttribute('rel')) === 'noopener noreferrer');
    await p.click('#pc-copy-link');
    await p.waitForTimeout(300);
    check('"Copiar link" copia o link e anuncia', (await p.evaluate(() => navigator.clipboard.readText())).includes('/pre-consulta/')
      && (await p.locator('#pc-copy-status').innerText()).includes('Link copiado'));
    const axeCtx = await browser.newContext({ bypassCSP: true, storageState: await ctx.storageState() }); // só para injetar o axe
    const ap = await axeCtx.newPage();
    await ap.goto(p.url());
    await Promise.all([ap.waitForNavigation(), ap.locator('form[action$="/enviar"] button[type=submit]').click()]);
    await axe(ap, null, 'tela do link (WhatsApp e copiar)');

    // --- NI2-444: chips de refeição ---
    await p.goto(base + '/planos');
    const csrf = await p.locator('form[action="/planos/novo"] input[name=_csrf_token]').getAttribute('value');
    const created = await p.request.post(base + '/planos/novo', { form: { _csrf_token: csrf, patient_id: '1', title: 'Plano chips' }, maxRedirects: 0 });
    const planPath = created.headers().location.replace(/^https?:\/\/[^/]+/, '');
    await p.goto(base + planPath);
    await p.locator('button:has-text("Refeição")').first().click();
    check('Modal "Adicionar Refeição" abre', await p.locator('#modal-add-meal').isVisible());
    const chips = p.locator('#quick-meal-suggestions .quick-meal-btn');
    check('8 chips de sugestão', (await chips.count()) === 8);
    const options = await p.locator('#common-meals-list option').evaluateAll((els) => els.map((e) => e.value));
    const chipNames = await chips.evaluateAll((els) => els.map((e) => e.dataset.meal));
    check('Datalist e chips usam a mesma lista', JSON.stringify(options) === JSON.stringify(chipNames));
    check('Emoji fora do nome acessível do chip', (await p.getByRole('button', { name: 'Almoço', exact: true }).count()) === 1);
    await p.click('.quick-meal-btn[data-meal="Café da Manhã"]');
    check('Chip preenche nome e horário', (await p.inputValue('#dc19-name')) === 'Café da Manhã' && (await p.inputValue('#dc20-time_of_day')) === '07:30');
    await p.click('.quick-meal-btn[data-meal="Jantar"]');
    check('Trocar de chip troca o horário (achado 3 do review)', (await p.inputValue('#dc19-name')) === 'Jantar' && (await p.inputValue('#dc20-time_of_day')) === '20:00');
    await p.fill('#dc20-time_of_day', '21:15');
    await p.click('.quick-meal-btn[data-meal="Ceia"]');
    check('Horário digitado à mão é preservado', (await p.inputValue('#dc19-name')) === 'Ceia' && (await p.inputValue('#dc20-time_of_day')) === '21:15');
    await p.fill('#dc19-name', 'Lanche pré-plantão');
    await Promise.all([p.waitForNavigation(), p.locator('#modal-add-meal button[type=submit]').click()]);
    check('Refeição com nome livre é salva', (await p.content()).includes('Lanche pré-plantão'));
    const ap2 = await axeCtx.newPage();
    await ap2.goto(base + planPath);
    await ap2.evaluate(() => { document.getElementById('modal-add-meal').style.display = 'flex'; });
    await axe(ap2, '#modal-add-meal', 'modal Adicionar Refeição');
    check('Nenhum bloqueio de CSP nem erro de script', cspErrors.length === 0);
  } finally {
    await browser.close();
    server.kill();
    rmSync(dir, { recursive: true, force: true });
  }
  console.log(`\nNI2-444 + WhatsApp (navegador): ${passed} verificações PASS`);
})().catch((e) => { console.error('FAIL ' + e.message); process.exit(1); });
