// NI2-135 (code review, A1) — anotações internas do profissional não chegam à paciente.
// HTTP real: php -S (APP_ENV=production), SQLite isolado, dados fictícios (fixture da NI2-89), login real da paciente.
//   node tests/ni2_135_internal_notes_real_test.cjs   [APP_ROOT=… para rodar contra outra árvore]
'use strict';
const { spawn, execFileSync } = require('node:child_process');
const { mkdtempSync, rmSync, writeFileSync } = require('node:fs');
const { join, resolve } = require('node:path');
const net = require('node:net');

const root = resolve(process.env.APP_ROOT || join(__dirname, '..'));
const dir = mkdtempSync('/tmp/ni2-89-');
const env = { ...process.env, DB_CONNECTION: 'sqlite', DB_DATABASE: join(dir, 'test.sqlite'), NI2_FIXTURE_DIR: dir, APP_ENV: 'production', FORCE_HTTPS: 'false', APP_TIMEZONE: 'America/Sao_Paulo', TZ: 'America/Sao_Paulo' };
const fx = (args) => execFileSync('php', [join(root, 'tests/support/ni2_89_fixture.php'), ...args], { cwd: root, env, encoding: 'utf8' });
const sql = (q) => JSON.parse(fx(['sql', q]));
const phpRun = (code) => execFileSync('php', ['-r', `require '${root}/vendor/autoload.php'; $db = \\ClyoNutri\\Core\\Database\\Connection::getInstance(); ${code}`], { cwd: root, env, encoding: 'utf8' });
const results = [];
const check = (name, ok, detail) => { results.push({ name, ok: !!ok, detail: ok ? null : detail ?? null }); console.log(`${ok ? 'PASS' : 'FAIL'} ${name}${!ok && detail !== undefined ? ' → ' + JSON.stringify(detail).slice(0, 300) : ''}`); };
const SECRET_A = 'Obs. interna: suspeita de compulsão alimentar';
const SECRET_B = 'Avaliação ainda não revisada';
const SECRET_C = 'Queixa inicial: refluxo; encaminhar para gastro';

(async () => {
  fx(['prepare']);
  phpRun(`$db->exec("INSERT INTO anthropometric_assessments(clinic_id,patient_id,professional_id,weight_kg,height_cm,bmi,waist_cm,notes,assessed_at,is_shared,shared_at) VALUES
    (1,1,1,70.0,165,25.71,88,'${SECRET_A}','2026-09-01 09:00:00',1,'2026-09-01 10:00:00'),
    (1,1,1,68.5,165,25.16,86,'${SECRET_B}','2026-10-01 09:00:00',0,NULL)");
    $db->exec("UPDATE appointments SET notes = '${SECRET_C}' WHERE id = 11");`);
  const sock = net.createServer(); await new Promise((r) => sock.listen(0, '127.0.0.1', r)); const port = sock.address().port; await new Promise((r) => sock.close(r));
  const server = spawn('php', ['-S', '127.0.0.1:' + port, '-t', 'public'], { cwd: root, env, stdio: 'ignore' });
  const B = 'http://127.0.0.1:' + port;
  for (let i = 0; i < 100; i++) { try { await fetch(B + '/up'); break; } catch { await new Promise((r) => setTimeout(r, 50)); } }
  const get = async (path, token) => { const r = await fetch(B + path, { headers: { Accept: 'application/json', Authorization: 'Bearer ' + token } }); const text = await r.text(); let json = null; try { json = JSON.parse(text); } catch { /* */ } return { status: r.status, text, json }; };
  // JSON escapa acentos (ç…): comparar no texto decodificado.
  const plain = (j) => JSON.stringify(j ?? {});
  try {
    const login = async (email) => (await (await fetch(B + '/api/v1/auth/patient-login', { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify({ email, password: 'PacienteTeste@2026' }) })).json()).token;
    const ana = await login('ana@example.test'), bruno = await login('bruno@example.test');

    const ev = await get('/api/v1/patient/evolution', ana);
    const c = (ev.json && ev.json.clinical_records) || [];
    check('Evolução responde 200 com a avaliação liberada', ev.status === 200 && c.length === 1, { status: ev.status, n: c.length });
    check('Avaliação liberada chega sem a anotação interna (sem campo notes)', c.length === 1 && !('notes' in c[0]) && !plain(ev.json).includes(SECRET_A), c[0]);
    check('Medidas liberadas continuam visíveis (peso, altura, IMC, cintura, data, fonte)', c.length === 1 && Number(c[0].weight_kg) === 70 && Number(c[0].height_cm) === 165 && Number(c[0].bmi) === 25.71 && Number(c[0].waist_cm) === 88 && !!c[0].date && c[0].source === 'clinical_official', c[0]);
    check('Avaliação não liberada continua oculta', !plain(ev.json).includes(SECRET_B) && !plain(ev.json).includes('68.5'));

    const home = await get('/api/v1/patient/home', ana);
    const na = home.json && home.json.next_appointment;
    check('Home: próxima consulta continua presente', home.status === 200 && na && na.id === 11, home.text.slice(0, 200));
    check('Home: próxima consulta chega sem a observação interna da agenda', na && !('notes' in na) && !plain(home.json).includes(SECRET_C), na);

    const evB = await get('/api/v1/patient/evolution', bruno);
    check('Outro paciente não vê avaliações nem anotações da Ana', evB.status === 200 && (evB.json.clinical_records || []).length === 0 && !plain(evB.json).includes(SECRET_A));

    const kept = sql("SELECT notes FROM anthropometric_assessments WHERE patient_id = 1 ORDER BY id");
    const keptApp = sql('SELECT notes FROM appointments WHERE id = 11')[0];
    check('Anotações continuam gravadas para o profissional (nada foi apagado)', kept.length === 2 && kept[0].notes === SECRET_A && keptApp.notes === SECRET_C, { kept, keptApp });

    // Método de serviço que também monta a visão da paciente.
    const svc = phpRun(`\\ClyoNutri\\Core\\Auth\\TenantContext::set(1, ['id' => 1, 'role' => 'nutritionist']); echo json_encode((new \\ClyoNutri\\Domain\\Anthropometry\\AnthropometryService($db))->getSharedAssessmentsForPatient(1));`);
    const rows = JSON.parse(svc);
    check('AnthropometryService::getSharedAssessmentsForPatient não leva a anotação interna', rows.length === 1 && !('notes' in rows[0]) && !svc.includes('suspeita'), rows[0] && Object.keys(rows[0]));
  } catch (e) {
    results.push({ name: e.message.split('\n')[0], ok: false }); console.log('FAIL (interrompido) ' + e.stack);
  } finally {
    server.kill(); rmSync(dir, { recursive: true, force: true });
    if (process.env.RESULTS_JSON) writeFileSync(process.env.RESULTS_JSON, JSON.stringify(results, null, 1));
  }
  const ok = results.filter((r) => r.ok).length;
  console.log(`\nNI2-135 anotações internas: ${ok} PASS, ${results.length - ok} FAIL`);
  if (ok !== results.length) process.exitCode = 1;
})();
