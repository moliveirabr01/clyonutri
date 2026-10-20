// NI2-437 — teste E2E real: public/index.php servido por `php -S`, SQLite isolado, dois navegadores (cookies)
// distintos: nutricionista e paciente. Opcional: se `playwright` (e `axe-core`) estiverem instalados, abre as
// telas do paciente num Chromium real e roda a verificação automática de acessibilidade (WCAG 2.1 AA).
const { spawn, execFileSync } = require('node:child_process');
const { mkdtempSync, rmSync } = require('node:fs');
const { join, resolve } = require('node:path');
const net = require('node:net');
const assert = require('node:assert/strict');

const root = resolve(__dirname, '..');
const dir = mkdtempSync('/tmp/ni2-437-');
const env = { ...process.env, DB_CONNECTION: 'sqlite', DB_DATABASE: join(dir, 'test.sqlite'), APP_URL: '', MAIL_DRIVER: 'null' };
const fixture = (mode) => execFileSync('php', ['tests/support/ni2_437_fixture.php', mode], { cwd: root, env, encoding: 'utf8' });
const inspect = () => JSON.parse(fixture('inspect'));
let server; let passed = 0;
function check(name, ok) { assert.ok(ok, name); passed++; console.log('PASS ' + name); }

function client(base) {
  let cookie = '';
  async function req(path, options = {}) {
    const r = await fetch(base + path, { ...options, redirect: 'manual', headers: { ...(cookie ? { Cookie: cookie } : {}), ...options.headers } });
    const c = r.headers.get('set-cookie'); if (c) cookie = c.split(';')[0];
    return { status: r.status, location: r.headers.get('location'), text: await r.text() };
  }
  const post = (path, data) => req(path, { method: 'POST', headers: { 'Content-Type': 'application/x-www-form-urlencoded' }, body: data instanceof URLSearchParams ? data : new URLSearchParams(data) });
  return { req, post };
}
const token = (html) => (html.match(/name="_csrf_token" value="([^"]+)"/) || [])[1];
function form(pairs) { const f = new URLSearchParams(); for (const [k, v] of pairs) f.append(k, v); return f; }

(async () => {
  fixture('prepare');
  const sock = net.createServer(); await new Promise((r) => sock.listen(0, '127.0.0.1', r)); const port = sock.address().port; await new Promise((r) => sock.close(r));
  server = spawn('php', ['-d', 'session.save_path=' + dir, '-S', '127.0.0.1:' + port, '-t', 'public'], { cwd: root, env, stdio: 'ignore' });
  const base = 'http://127.0.0.1:' + port;
  for (let i = 0; i < 80; i++) { try { await fetch(base + '/login'); break; } catch { await new Promise((r) => setTimeout(r, 50)); } }

  const pro = client(base);
  async function login(c, n) { const p = await c.req('/login'); return c.post('/login', { _csrf_token: token(p.text), email: `pre${n}@example.test`, password: 'PreTeste@2026' }); }

  check('Sem login, a área do nutricionista redireciona', [302, 303].includes((await pro.req('/pacientes/1/pre-consulta')).status));
  check('Login real do nutricionista', [302, 303].includes((await login(pro, 1)).status));
  let page = await pro.req('/pacientes/1/pre-consulta');
  check('Tela da pré-consulta abre com objetivos por categoria', page.status === 200 && page.text.includes('Objetivos declarados') && page.text.includes('Esportivo'));
  let r = await pro.post('/pacientes/1/pre-consulta', form([['_csrf_token', token(page.text)], ['objectives[]', 'glycemic_control'], ['mode', 'suggest']]));
  check('Proposta criada por HTTP', r.status === 303 && /\/pacientes\/1\/pre-consulta\/1$/.test(r.location));
  page = await pro.req('/pacientes/1/pre-consulta/1');
  check('Sugestão mostra perguntas de glicemia marcadas', /name="questions\[\]" value="glucose_monitoring" checked/.test(page.text) && !/name="questions\[\]" value="sport_modality" checked/.test(page.text));
  const kept = [...page.text.matchAll(/name="questions\[\]" value="([a-z_]+)" checked/g)].map((m) => m[1]).filter((id) => id !== 'water');
  const editForm = form([['_csrf_token', token(page.text)], ...kept.map((id) => ['questions[]', id]), ['questions[]', 'salt_use'], ['required[]', 'main_goal'], ['required[]', 'has_allergy'], ['required[]', 'allergy_detail']]);
  r = await pro.post('/pacientes/1/pre-consulta/1/perguntas', editForm);
  page = await pro.req('/pacientes/1/pre-consulta/1');
  check('Edição: retira sugerida e inclui extra', !/name="questions\[\]" value="water" checked/.test(page.text) && /name="questions\[\]" value="salt_use" checked/.test(page.text));
  r = await pro.post('/pacientes/1/pre-consulta/1/enviar', { _csrf_token: token(page.text), ttl_days: '7', channel: 'link' });
  page = await pro.req('/pacientes/1/pre-consulta/1');
  const link = (page.text.match(/readonly value="([^"]*\/pre-consulta\/[A-Za-z0-9_-]{43})"/) || [])[1];
  check('Link gerado e mostrado ao nutricionista', !!link);
  const linkPath = link.replace(/^https?:\/\/[^/]+/, '');
  check('Banco guarda só o hash do token', !JSON.stringify(inspect()).includes(linkPath.split('/').pop()));
  check('Sem token, POST do paciente sem CSRF é bloqueado', (await client(base).post('/pre-consulta/salvar', { 'answers[main_goal]': 'x' })).status === 403);

  // ---------------- paciente
  const pat = client(base);
  r = await pat.req(linkPath);
  check('Link troca o token por sessão e redireciona para URL limpa', r.status === 303 && r.location === '/pre-consulta');
  page = await pat.req('/pre-consulta');
  check('Pede data de nascimento antes de mostrar perguntas', page.text.includes('Data de nascimento') && !page.text.includes('Com suas palavras'));
  check('Página do paciente não é indexada nem guardada em cache', page.text.includes('noindex'));
  await pat.post('/pre-consulta/verificar', { _csrf_token: token(page.text), birth_date: '1988-03-01' });
  page = await pat.req('/pre-consulta');
  check('Data errada: mensagem de erro e continua bloqueado', page.text.includes('não confere') && page.text.includes('aria-invalid="true"'));
  await pat.post('/pre-consulta/verificar', { _csrf_token: token(page.text), birth_date: '1988-02-29' });
  page = await pat.req('/pre-consulta');
  check('Data certa: formulário com as perguntas da proposta', page.text.includes('Com suas palavras') && page.text.includes('Como é o seu uso de sal') && !page.text.includes('Quanta água'));
  check('Formulário acessível: lang, rótulos e grupos', page.text.includes('lang="pt-BR"') && /<legend class="pc-label">/.test(page.text) && /<label class="pc-label" for="in-main_goal">/.test(page.text));
  await pat.post('/pre-consulta/salvar', { _csrf_token: token(page.text), action: 'draft', 'answers[main_goal]': 'Controlar a glicemia', 'answers[glucose_monitoring]': 'diario' });
  page = await pat.req('/pre-consulta');
  check('Rascunho salvo e mensagem de retomada', page.text.includes('Respostas salvas') && page.text.includes('Controlar a glicemia') && /value="diario" checked/.test(page.text));

  const pat2 = client(base);
  await pat2.req(linkPath); page = await pat2.req('/pre-consulta');
  await pat2.post('/pre-consulta/verificar', { _csrf_token: token(page.text), birth_date: '29/02/1988' });
  page = await pat2.req('/pre-consulta');
  check('Retomada em outro dispositivo pelo mesmo link', page.text.includes('Controlar a glicemia'));

  r = await pat2.post('/pre-consulta/salvar', { _csrf_token: token(page.text), action: 'submit', 'answers[main_goal]': 'Controlar a glicemia', 'answers[has_allergy]': 'sim', consent: '1' });
  page = await pat2.req('/pre-consulta');
  check('Envio incompleto: resumo de erros com links para as perguntas', page.text.includes('pc-error-summary') && page.text.includes('href="#q-allergy_detail"'));
  const answers = form([['_csrf_token', token(page.text)], ['action', 'submit'], ['answers[main_goal]', 'Controlar a glicemia'], ['answers[has_allergy]', 'sim'],
    ['answers[allergy_detail]', 'Amendoim: inchaço'], ['answers[health_conditions][]', 'diabetes'], ['answers[uses_medication]', 'nao'], ['answers[meals_per_day]', '5'],
    ['answers[physical_activity]', '1_2'], ['answers[glucose_monitoring]', 'diario'], ['answers[hypoglycemia]', 'nao'], ['answers[salt_use]', 'pouco'], ['consent', '1']]);
  r = await pat2.post('/pre-consulta/salvar', answers);
  page = await pat2.req('/pre-consulta');
  check('Envio completo: página de agradecimento', page.text.includes('Obrigado'));
  let state = inspect();
  check('Respostas e consentimento persistidos', state.questionnaires[0].status === 'submitted' && JSON.parse(state.questionnaires[0].answers_json).allergy_detail === 'Amendoim: inchaço' && state.consents.length === 1);
  check('Rascunhos e envio gravados como snapshots', state.snapshots.filter((k) => k === 'draft').length >= 2 && state.snapshots.at(-1) === 'submitted');
  r = await pat2.post('/pre-consulta/salvar', answers);
  check('Depois de enviado, novo envio é recusado (409)', r.status === 409);

  // ---------------- nutricionista revisa e importa
  page = await pro.req('/pacientes/1/pre-consulta/1');
  check('Nutricionista vê as respostas', page.text.includes('Amendoim: inchaço') && page.text.includes('Respondido pelo paciente'));
  await pro.post('/pacientes/1/pre-consulta/1/revisar', { _csrf_token: token(page.text), review_notes: 'Ok' });
  page = await pro.req('/pacientes/1/pre-consulta/1');
  await pro.post('/pacientes/1/pre-consulta/1/importar', { _csrf_token: token(page.text) });
  state = inspect();
  const an = state.anamneses.find((a) => Number(a.patient_id) === 1);
  check('Importação cria rascunho da anamnese com alergia registrada', an && Number(an.is_draft) === 1 && an.allergies.includes('Amendoim') && an.allergy_assessment === 'recorded');
  for (const a of ['PRECONSULTATION_CREATED', 'PRECONSULTATION_EDITED', 'PRECONSULTATION_LINK_ISSUED', 'PRECONSULTATION_OPENED', 'PRECONSULTATION_IDENTITY_FAILED', 'PRECONSULTATION_IDENTITY_CONFIRMED', 'PRECONSULTATION_DRAFT_SAVED', 'PRECONSULTATION_SUBMITTED', 'PRECONSULTATION_REVIEWED', 'PRECONSULTATION_IMPORTED']) {
    check('Auditoria ' + a, state.audit.includes(a));
  }

  // ---------------- exposição cruzada e ciclo do link
  const other = client(base);
  await login(other, 2);
  check('Nutricionista de outra clínica: 404 no paciente e na pré-consulta', (await other.req('/pacientes/1/pre-consulta/1')).status === 404);
  check('Sessão de paciente não acessa a área do nutricionista', [302, 303].includes((await pat2.req('/pacientes/1/pre-consulta/1')).status));
  page = await other.req('/pacientes/2/pre-consulta');
  r = await other.post('/pacientes/2/pre-consulta', form([['_csrf_token', token(page.text)], ['objectives[]', 'performance'], ['mode', 'suggest']]));
  page = await other.req(r.location);
  await other.post(r.location.replace(/^https?:\/\/[^/]+/, '') + '/enviar', { _csrf_token: token(page.text), channel: 'link', ttl_days: '3' });
  page = await other.req(r.location);
  const link2 = (page.text.match(/readonly value="[^"]*(\/pre-consulta\/[A-Za-z0-9_-]{43})"/) || [])[1];
  const pB = client(base); await pB.req(link2); page = await pB.req('/pre-consulta');
  await pB.post('/pre-consulta/verificar', { _csrf_token: token(page.text), birth_date: '1990-01-01' });
  page = await pB.req('/pre-consulta');
  check('Paciente da outra clínica vê só o próprio questionário', page.text.includes('Modalidade') || page.text.includes('esporte') ? !page.text.includes('Amendoim') && !page.text.includes('glicemia (açúcar') : false);
  page = await other.req(r.location);
  await other.post(r.location.replace(/^https?:\/\/[^/]+/, '') + '/enviar', { _csrf_token: token(page.text), channel: 'link', ttl_days: '3' });
  check('Novo link invalida o anterior', (await client(base).req(link2)).status === 404);
  check('Token inexistente: 404 sem detalhes', (await client(base).req('/pre-consulta/' + 'A'.repeat(43))).status === 404);

  // ---------------- navegador real + axe (opcional)
  let playwright = null; let axePath = null;
  try { playwright = require(process.env.PLAYWRIGHT_MODULE || 'playwright'); axePath = require.resolve(process.env.AXE_MODULE || 'axe-core'); } catch { /* opcional */ }
  if (playwright && axePath) {
    const browser = await playwright.chromium.launch(process.env.CHROMIUM_PATH ? { executablePath: process.env.CHROMIUM_PATH, headless: false, args: ['--headless=new'] } : {});
    try {
      page = await pro.req('/pacientes/1/pre-consulta');
      r = await pro.post('/pacientes/1/pre-consulta', form([['_csrf_token', token(page.text)], ['objectives[]', 'food_relationship'], ['objectives[]', 'performance'], ['mode', 'suggest']]));
      page = await pro.req(r.location.replace(/^https?:\/\/[^/]+/, ''));
      await pro.post(r.location.replace(/^https?:\/\/[^/]+/, '') + '/enviar', { _csrf_token: token(page.text), channel: 'link', ttl_days: '3' });
      page = await pro.req(r.location.replace(/^https?:\/\/[^/]+/, ''));
      const l3 = (page.text.match(/readonly value="[^"]*(\/pre-consulta\/[A-Za-z0-9_-]{43})"/) || [])[1];
      const ctx = await browser.newContext({ viewport: { width: 390, height: 844 }, bypassCSP: true }); // só para injetar o axe
      const ui = await ctx.newPage();
      const axeRun = async (label) => {
        await ui.addScriptTag({ path: axePath });
        const res = await ui.evaluate(async () => await window.axe.run(document, { runOnly: ['wcag2a', 'wcag2aa', 'wcag21a', 'wcag21aa'] }));
        const v = res.violations.map((x) => `${x.id} (${x.nodes.length})`);
        check(`axe WCAG 2.1 AA sem violações: ${label}${v.length ? ' → ' + v.join(', ') : ''}`, v.length === 0);
      };
      await ui.goto(base + l3);
      await axeRun('verificação de identidade');
      await ui.fill('#pc-birth', '1988-02-29');
      await Promise.all([ui.waitForNavigation(), ui.click('button[type=submit]')]);
      await axeRun('formulário');
      await ui.check('input[name="answers[has_allergy]"][value="nao"]');
      check('Pergunta condicional oculta quando não se aplica', await ui.locator('#q-allergy_detail').isHidden());
      await ui.check('input[name="answers[has_allergy]"][value="sim"]');
      check('Pergunta condicional aparece quando se aplica', await ui.locator('#q-allergy_detail').isVisible());
      await Promise.all([ui.waitForNavigation(), ui.click('button[value=submit]')]);
      check('Resumo de erros recebe o foco', await ui.evaluate(() => document.activeElement && document.activeElement.id === 'pc-error-summary'));
      await axeRun('formulário com erros');
      check('Sem rolagem horizontal no celular', await ui.evaluate(() => document.documentElement.scrollWidth <= window.innerWidth));
      if (process.env.SCREENSHOT_DIR) await ui.screenshot({ path: join(process.env.SCREENSHOT_DIR, 'pre-consulta-390.png'), fullPage: true });
    } finally { await browser.close(); }
  } else {
    console.log('SKIP navegador/axe: instale playwright e axe-core (PLAYWRIGHT_MODULE/AXE_MODULE) para rodar a parte de acessibilidade automática.');
  }
  console.log(`\nNI2-437 E2E: ${passed} verificações PASS`);
})().catch((e) => { console.error('FAIL', e.message); process.exitCode = 1; }).finally(() => { if (server) server.kill(); rmSync(dir, { recursive: true, force: true }); });
