// Provas do code review da NI2-135 (evolução, metas e autorrelatos com proveniência) — app/API do paciente.
// Reproduz o comportamento ATUAL por HTTP real (php -S, SQLite isolado, dados fictícios da fixture da NI2-89).
// Cada linha "ACHADO" é um defeito confirmado; "ok" é comportamento correto conferido.
//   node tests/review_ni2_135_provas.cjs
'use strict';
const { spawn, execFileSync } = require('node:child_process');
const { mkdtempSync, rmSync, readFileSync } = require('node:fs');
const { join, resolve } = require('node:path');
const net = require('node:net');

const root = resolve(process.env.APP_ROOT || join(__dirname, '..'));
const dir = mkdtempSync('/tmp/ni2-89-');
const env = { ...process.env, DB_CONNECTION: 'sqlite', DB_DATABASE: join(dir, 'test.sqlite'), NI2_FIXTURE_DIR: dir, APP_ENV: 'production', FORCE_HTTPS: 'false', APP_TIMEZONE: 'America/Sao_Paulo', TZ: 'America/Sao_Paulo' };
const fx = (args) => execFileSync('php', [join(root, 'tests/support/ni2_89_fixture.php'), ...args], { cwd: root, env, encoding: 'utf8' });
const sql = (q) => JSON.parse(fx(['sql', q]));
const phpRun = (code) => execFileSync('php', ['-r', `require '${root}/vendor/autoload.php'; $db = \\ClyoNutri\\Core\\Database\\Connection::getInstance(); ${code}`], { cwd: root, env, encoding: 'utf8' });
const say = (id, defect, text) => console.log(`${defect ? 'ACHADO' : 'ok    '} ${id}: ${text}`);

(async () => {
  fx(['prepare']);
  // Duas avaliações da Ana: uma liberada à paciente (com observação interna do profissional) e uma não liberada.
  phpRun(`$db->exec("INSERT INTO anthropometric_assessments(clinic_id,patient_id,professional_id,weight_kg,height_cm,bmi,waist_cm,notes,assessed_at,is_shared,shared_at) VALUES
    (1,1,1,70.0,165,25.71,88,'Obs. interna: suspeita de compulsão alimentar, discutir com psicóloga','2026-09-01 09:00:00',1,'2026-09-01 10:00:00'),
    (1,1,1,68.5,165,25.16,86,'Avaliação ainda não revisada','2026-10-01 09:00:00',0,NULL)");`);
  const sock = net.createServer(); await new Promise((r) => sock.listen(0, '127.0.0.1', r)); const port = sock.address().port; await new Promise((r) => sock.close(r));
  const server = spawn('php', ['-S', '127.0.0.1:' + port, '-t', 'public'], { cwd: root, env, stdio: 'ignore' });
  const B = 'http://127.0.0.1:' + port;
  for (let i = 0; i < 100; i++) { try { await fetch(B + '/up'); break; } catch { await new Promise((r) => setTimeout(r, 50)); } }
  const call = async (method, path, token, body) => {
    const res = await fetch(B + path, { method, headers: { Accept: 'application/json', Authorization: 'Bearer ' + token, ...(body ? { 'Content-Type': 'application/json' } : {}) }, body: body ? JSON.stringify(body) : undefined });
    const text = await res.text(); let json = null; try { json = JSON.parse(text); } catch { /* */ } return { status: res.status, json, text };
  };
  try {
    const login = async (email) => (await (await fetch(B + '/api/v1/auth/patient-login', { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify({ email, password: 'PacienteTeste@2026' }) })).json()).token;
    const ana = await login('ana@example.test'), bruno = await login('bruno@example.test');

    // A paciente tem peso liberado pelo profissional (a partir da correção, nada fica liberado por padrão).
    try { phpRun(`$db->exec("INSERT INTO patient_self_metric_permissions (clinic_id, patient_id, metric_type, enabled, updated_at) VALUES (1, 1, 'weight_kg', 1, CURRENT_TIMESTAMP)");`); } catch { /* árvore antiga: tabela não existe */ }
    // P1 campos habilitados
    let r = await call('POST', '/api/v1/patient/self-metrics', ana, { metric_type: 'pressao_arterial_inventada', value: 999, unit: 'xyz' });
    say('P1', r.status === 201, `autorrelato com tipo e unidade arbitrários ("pressao_arterial_inventada", "xyz") → HTTP ${r.status}`);
    r = await call('POST', '/api/v1/patient/self-metrics', ana, { metric_type: 'weight_kg', value: 4000, unit: 'kg' });
    say('P2', r.status === 201, `peso autorrelatado de 4000 kg → HTTP ${r.status}`);
    r = await call('POST', '/api/v1/patient/self-metrics', ana, { metric_type: 'weight_kg', value: 150, unit: 'lb' });
    say('P3', r.status === 201, `tipo weight_kg com unidade "lb" (incoerente) → HTTP ${r.status}`);
    r = await call('POST', '/api/v1/patient/self-metrics', ana, { metric_type: 'weight_kg', value: 64, unit: 'kg', notes: 'x'.repeat(100000) });
    say('P4', r.status === 201, `observação de 100.000 caracteres → HTTP ${r.status}`);
    r = await call('POST', '/api/v1/patient/self-metrics', ana, { value: 63.4 });
    say('P5', r.status === 201 && r.json && r.json.metric_type === 'weight_kg', `sem metric_type nem unit, o servidor assume peso em kg → HTTP ${r.status}, tipo "${r.json && r.json.metric_type}"`);

    // P6 horário do autorrelato
    r = await call('POST', '/api/v1/patient/self-metrics', ana, { metric_type: 'weight_kg', value: 63.4 });
    const lastId = r.json && r.json.id;
    const stored = lastId ? sql(`SELECT recorded_at FROM patient_self_metrics WHERE id = ${lastId}`)[0].recorded_at : null;
    say('P6', r.json && stored !== r.json.recorded_at, `horário devolvido na criação "${r.json && r.json.recorded_at}" x gravado "${stored}" (UTC); a avaliação clínica usa o fuso da aplicação`);

    // P7 evolução: notas internas e avaliações não liberadas
    const ev = await call('GET', '/api/v1/patient/evolution', ana);
    const evTxt = JSON.stringify(ev.json);
    say('P7', evTxt.includes('Obs. interna'), 'GET /evolution devolve à paciente a observação interna (notes) da avaliação liberada');
    say('P8', evTxt.includes('ainda não revisada'), `avaliação NÃO liberada (is_shared = 0) ${evTxt.includes('ainda não revisada') ? 'aparece' : 'não aparece'} para a paciente`);
    // P9 filtros temporais
    const ev2 = await call('GET', '/api/v1/patient/evolution?from=2030-01-01&to=2030-12-31', ana);
    const n1 = ev.json.summary.total_entries, n2 = ev2.json.summary.total_entries;
    say('P9', n1 === n2, `filtro temporal: sem filtro ${n1} registros; com from/to em 2030 ${n2} registros (parâmetros ignorados; não há filtro na API)`);
    // P10 isolamento
    const evB = await call('GET', '/api/v1/patient/evolution', bruno);
    say('P10', JSON.stringify(evB.json).includes('63.4') || JSON.stringify(evB.json).includes('Obs. interna'), 'Bruno não vê autorrelatos nem avaliações da Ana');
    // P11 metas
    say('P11', !('goals' in (ev.json || {})) && !/meta|goal/i.test(Object.keys(ev.json || {}).join(',')), `resposta da evolução não tem metas (chaves: ${Object.keys(ev.json || {}).join(', ')})`);
    // P12 tamanho da resposta: sem paginação
    say('P12', ev.text.length > 100000, `resposta de /evolution sem paginação nem limite: ${ev.text.length} bytes (inclui a observação de 100 mil caracteres)`);

    // P13–P16 app (leitura do código da tela)
    const screen = readFileSync(join(root, 'mobile/src/screens/PatientHomeScreen.tsx'), 'utf8');
    const evoStart = screen.indexOf("{currentTab === 'Evolucao' && (");
    const evo = screen.slice(evoStart, screen.indexOf("{currentTab === 'Perfil' && (", evoStart));
    if (evoStart < 0 || evo.length < 200) throw new Error('bloco da aba Evolução não encontrado');
    say('P13', !/logSelfMetric/.test(screen), 'tela do app não oferece registrar autorrelato (ApiService.logSelfMetric existe, mas a tela não usa)');
    say('P14', !/Svg|chart|Chart|grafico|gráfico|Polyline|LineChart/.test(evo), 'aba Evolução não tem gráfico (só lista de cartões)');
    say('P15', !/filtro|Filtro|period|Período|periodo/.test(evo), 'aba Evolução não tem filtro de período');
    say('P16', /\{s\.metric_type\}/.test(evo) && !/displayDateTime\((c|s)\.date\)/.test(evo), 'tela mostra o código técnico do tipo (ex.: "weight_kg") e a data crua do banco, sem formatar nem converter o fuso');
    // P17 profissional não vê autorrelatos
    let pro = '';
    try { pro = execFileSync('grep', ['-rln', 'patient_self_metrics\\|self_reports', join(root, 'src/Application'), join(root, 'views')], { encoding: 'utf8' }).trim(); } catch { pro = ''; }
    say('P17', pro === '', 'nenhuma tela do profissional mostra os autorrelatos da paciente');
  } catch (e) {
    console.log('ERRO ' + e.stack);
  } finally { server.kill(); rmSync(dir, { recursive: true, force: true }); }
})();
