const { spawn, execFileSync } = require('node:child_process');
const { mkdtempSync, writeFileSync, rmSync } = require('node:fs');
const { join, resolve } = require('node:path');
const net = require('node:net');
const assert = require('node:assert/strict');

const root = resolve(__dirname, '..');
const dir = mkdtempSync('/tmp/ni2-268-http-');
const out = join(root, 'docs/validation/NI2-268/evidence');
const env = { ...process.env, DB_CONNECTION: 'sqlite', DB_DATABASE: join(dir, 'test.sqlite') };

const fixture = (mode) => execFileSync('php', ['tests/support/ni2_268_fixture.php', mode], { cwd: root, env, encoding: 'utf8' });
const inspect = () => JSON.parse(fixture('inspect'));

const checks = [];
let server, browser, log = '';

function check(name, ok, details = '') {
  assert.ok(ok, name + (details ? ': ' + details : ''));
  checks.push({ name, status: 'PASS', details });
  console.log('PASS ' + name + (details ? ' ➔ ' + details : ''));
}

(async () => {
  console.log('\n========================================================================');
  console.log('>>> CLYONUTRI II — TESTE E2E HTTP REAL: RECEITAS CULINÁRIAS (NI2-268) <<<');
  console.log('========================================================================\n');

  const fixtureData = JSON.parse(fixture('prepare'));
  console.log('[SETUP] Fixture preparada com sucesso no banco temporário SQLite');

  const sock = net.createServer();
  await new Promise((r) => sock.listen(0, '127.0.0.1', r));
  const port = sock.address().port;
  await new Promise((r) => sock.close(r));

  server = spawn(
    'php',
    ['-d', 'session.name=NI268TEST', '-d', 'session.save_path=' + dir, '-S', '127.0.0.1:' + port, '-t', 'public'],
    { cwd: root, env }
  );
  server.stdout.on('data', (d) => (log += d));
  server.stderr.on('data', (d) => (log += d));

  const base = 'http://127.0.0.1:' + port;
  for (let i = 0; i < 80; i++) {
    try {
      if ((await fetch(base + '/health')).ok) break;
    } catch {}
    await new Promise((r) => setTimeout(r, 50));
  }

  let cookie = '', loginCsrf = '';
  async function req(path, options = {}) {
    const r = await fetch(base + path, {
      ...options,
      redirect: 'manual',
      headers: { ...(cookie ? { Cookie: cookie } : {}), ...options.headers },
    });
    const c = r.headers.get('set-cookie');
    if (c) cookie = c.split(';')[0];
    return { status: r.status, location: r.headers.get('location'), text: await r.text() };
  }

  const hidden = (html, name) => {
    const match = html.match(new RegExp('name="' + name + '" value="([^"]+)"'));
    return match ? match[1] : '';
  };
  const post = (path, data) => req(path, { method: 'POST', body: new URLSearchParams(data) });

  async function login(n) {
    await webLogout(req);
    const p = await req('/login');
    loginCsrf = hidden(p.text, '_csrf_token');
    return post('/login', {
      _csrf_token: loginCsrf,
      email: `nutri${n}@example.test`,
      password: 'PlanTeste@2026',
    });
  }

  // 1. Autenticação do Nutricionista 1
  const loginRes = await login(1);
  check('Login web do nutricionista redireciona para o dashboard', loginRes.status === 302);

  // 2. Acesso à Biblioteca de Receitas
  const libraryRes = await req('/receitas');
  check('Biblioteca de receitas responde 200 e renderiza HTML', libraryRes.status === 200 && libraryRes.text.includes('Receitas Culinárias'));

  // Obter token CSRF da página de criação (/receitas/nova)
  const novaRes = await req('/receitas/nova');
  check('Página de criação de receitas responde 200', novaRes.status === 200);
  const csrfToken = hidden(novaRes.text, 'csrf_token');
  check('Token CSRF presente no formulário de criação', csrfToken.length > 0);

  // 3. Criação de Nova Receita com Composição TACO
  const newRecipeRes = await post('/receitas', {
    csrf_token: csrfToken,
    title: 'Omelete Funcional de Espinafre com Queijo',
    summary: 'Rica em ferro biodisponível e proteínas de alto valor biológico',
    meal_type: 'Café da Manhã',
    difficulty: 'fácil',
    servings: '2',
    serving_size_name: 'omelete',
    prep_time_minutes: '10',
    cook_time_minutes: '10',
    'ingredients[0][food_id]': '103',
    'ingredients[0][amount]': '100',
    'ingredients[0][measure_unit]': 'g',
    'steps[]': 'Bata os ovos, refogue o espinafre e doure na frigideira antiaderente.',
    tags: 'Sem Glúten, Proteica',
  });
  check('Criação de receita persiste no banco e redireciona', newRecipeRes.status === 302);

  const insp = inspect();
  const createdRecipe = insp.recipes.find((r) => r.title.includes('Omelete Funcional'));
  check('Receita criada está persistida no banco relacional', createdRecipe && createdRecipe.id > 0);
  check('Composição nutricional calculada via TACO', parseFloat(createdRecipe.energy_kcal_per_serving) > 0);

  // 4. Detalhes da Receita Criada
  const showRes = await req(`/receitas/${createdRecipe.id}`);
  check('Detalhes da receita renderizam com 200', showRes.status === 200 && showRes.text.includes('Omelete Funcional'));

  // Obter token CSRF da página de prescrição
  const prescFormRes = await req(`/receitas/${createdRecipe.id}/prescrever`);
  check('Página de prescrição responde 200', prescFormRes.status === 200);
  const prescCsrfToken = hidden(prescFormRes.text, 'csrf_token');

  // 5. Prescrição da Receita ao Paciente
  const prescribeRes = await post(`/receitas/${createdRecipe.id}/prescrever`, {
    csrf_token: prescCsrfToken,
    patient_id: '1',
    meal_plan_id: '1',
    meal_name: 'Café da Manhã',
    servings: '1.5',
    instructions: 'Servir com chá verde sem açúcar.',
  });
  check('Prescrição de receita ao paciente redireciona com sucesso', prescribeRes.status === 302);

  const inspPresc = inspect();
  const prescription = inspPresc.prescriptions.find((p) => p.recipe_id === createdRecipe.id && p.patient_id === 1);
  check('Prescrição persistida com porções escalonadas (1.5x)', prescription && parseFloat(prescription.prescribed_servings) === 1.5);

  // 6. API Móvel do Paciente (Autenticado via Sanctum Bearer Token)
  const patientHeaders = { Authorization: 'Bearer ' + fixtureData.raw_token };
  const patRecipesRes = await fetch(base + '/api/v1/patient/recipes', { headers: patientHeaders });
  check('API do paciente retorna 200 para receitas prescritas', patRecipesRes.status === 200);
  const patRecipesJson = await patRecipesRes.json();
  check(
    'Paciente visualiza receita prescrita no seu portal móvel',
    Array.isArray(patRecipesJson.data) && patRecipesJson.data.some((p) => p.recipe_id === createdRecipe.id)
  );

  // 7. Toggle de Favoritos pelo Paciente
  const favToggleRes = await fetch(base + `/api/v1/patient/recipes/${createdRecipe.id}/favorite`, {
    method: 'POST',
    headers: patientHeaders,
  });
  check('Toggle de favorito do paciente retorna 200', favToggleRes.status === 200);
  const favJson = await favToggleRes.json();
  check('Receita marcada como favorita com sucesso', favJson.is_favorite === true);

  // 8. Lista de Compras do Paciente
  const shopListRes = await fetch(base + '/api/v1/patient/recipes-shopping-list?days=7', { headers: patientHeaders });
  check('Lista de compras do paciente gerada com 200', shopListRes.status === 200);
  const shopJson = await shopListRes.json();
  check('Estrutura da lista de compras organizada por gôndolas', typeof shopJson.data === 'object' && shopJson.data.by_aisle !== undefined);

  // 9. Rejeição CSRF Fail-Closed
  const fakeCsrfRes = await post('/receitas', {
    csrf_token: 'forged_fake_token',
    title: 'Ataque CSRF Forjado',
  });
  check('Submissão com CSRF inválido é bloqueada com 403', fakeCsrfRes.status === 403);

  // 10. Isolamento Multi-Tenant Ofensivo (Nutricionista 2 tenta acessar receita privada da Clínica 1)
  await login(2);
  const crossTenantRes = await req(`/receitas/${createdRecipe.id}`);
  check(
    'Acesso cruzado de outro tenant bloqueado (403 ou 404)',
    crossTenantRes.status === 403 || crossTenantRes.status === 404
  );

  // 11. Controle de Perfil (Recepcionista sem permissão)
  await login(3);
  const unauthRes = await req('/receitas');
  check('Usuário recepcionista bloqueado do módulo clínico de receitas (403)', unauthRes.status === 403);

  console.log('\n========================================================================');
  console.log('>>> TODOS OS 11 CHECKS E2E HTTP E DE SEGURANÇA (NI2-268) APROVADOS! <<<');
  console.log('========================================================================\n');

  writeFileSync(
    join(out, 'http-results.json'),
    JSON.stringify({ issue: 'NI2-268', transport: 'real HTTP/API with SQLite multi-tenant', checks }, null, 2)
  );
  writeFileSync(join(out, 'persistence.json'), JSON.stringify(inspect(), null, 2));
  console.log('[EVIDENCE] Evidências persistidas em docs/validation/NI2-268/evidence/');
})()
  .catch((e) => {
    checks.push({ name: e.message, status: 'FAIL' });
    console.error(e);
    process.exitCode = 1;
  })
  .finally(async () => {
    if (browser) await browser.close();
    if (server) server.kill();
    writeFileSync(join(out, 'http-server.log'), log);
    rmSync(dir, { recursive: true, force: true });
  });

// Logout web exige POST + CSRF: lê o token do formulário de confirmação (GET /logout → 405) e envia o POST.
async function webLogout(req){const page=await req('/logout');const m=(page.text||'').match(/name="_csrf_token" value="([^"]+)"/);if(m)await req('/logout',{method:'POST',body:new URLSearchParams({_csrf_token:m[1]})});}
