// NI2-135 — evolução da paciente: métricas habilitadas, autorrelato validado, filtro de período, metas e visão do profissional.
// HTTP real: public/index.php em `php -S` (APP_ENV=production), banco isolado com dados fictícios (tests/support/ni2_91_fixture.php),
// login real da paciente (API) e da equipe (formulário web com CSRF).
//   node tests/ni2_135_evolution_real_test.cjs
//   DB_CONNECTION=mysql DB_HOST=… DB_PORT=… DB_USERNAME=… DB_PASSWORD=… node tests/ni2_135_evolution_real_test.cjs
'use strict';
const { spawn, execFileSync } = require('node:child_process');
const { mkdtempSync, rmSync, writeFileSync } = require('node:fs');
const { join, resolve } = require('node:path');
const net = require('node:net');

const root = resolve(process.env.APP_ROOT || join(__dirname, '..'));
const dir = mkdtempSync('/tmp/ni2-91-');
const useMysql = process.env.DB_CONNECTION === 'mysql';
const mysqlDb = 'ni2_91_test_' + Math.random().toString(36).slice(2, 10);
const env = { ...process.env, DB_CONNECTION: useMysql ? 'mysql' : 'sqlite', DB_DATABASE: useMysql ? mysqlDb : join(dir, 'test.sqlite'), NI2_FIXTURE_DIR: dir,
  APP_ENV: 'production', FORCE_HTTPS: 'false', APP_TIMEZONE: 'America/Sao_Paulo', MAIL_DRIVER: 'null', TZ: 'America/Sao_Paulo' };
const mysqlAdmin = (q) => execFileSync('php', ['-r', '$p=new PDO("mysql:host=".getenv("DB_HOST").";port=".getenv("DB_PORT"),getenv("DB_USERNAME"),getenv("DB_PASSWORD"));$p->exec($argv[1]);', q], { env });
const results = [];
const check = (crit, name, ok, detail) => { results.push({ crit, name, ok: !!ok, detail: ok ? null : detail ?? null }); console.log(`${ok ? 'PASS' : 'FAIL'} [${crit}] ${name}${!ok && detail !== undefined ? ' → ' + JSON.stringify(detail).slice(0, 350) : ''}`); };
const fx = (args) => execFileSync('php', [join(root, 'tests/support/ni2_91_fixture.php'), ...args], { cwd: root, env, encoding: 'utf8' });
const sql = (q) => JSON.parse(fx(['sql', q]));
const one = (q) => { const r = sql(q)[0]; return r ? Object.values(r)[0] : null; };
const phpRun = (code) => execFileSync('php', ['-r', `require '${root}/vendor/autoload.php'; $db = \\ClyoNutri\\Core\\Database\\Connection::getInstance(); ${code}`], { cwd: root, env, encoding: 'utf8' });
const pad = (n) => String(n).padStart(2, '0');
const localNow = () => { const d = new Date(new Date().toLocaleString('en-US', { timeZone: 'America/Sao_Paulo' })); return d; };
const ymd = (d) => `${d.getFullYear()}-${pad(d.getMonth() + 1)}-${pad(d.getDate())}`;

(async () => {
  if (useMysql) mysqlAdmin(`CREATE DATABASE ${mysqlDb} CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci`);
  fx(['prepare']);
  const recent = ymd(new Date(localNow().getTime() - 10 * 86400000));
  phpRun(`$db->exec("INSERT INTO anthropometric_assessments(clinic_id,patient_id,professional_id,weight_kg,height_cm,bmi,waist_cm,hip_cm,notes,assessed_at,is_shared,shared_at) VALUES
    (1,1,1,72.0,165,26.45,90,104,'Nota interna antiga','2026-01-10 09:00:00',1,'2026-01-10 10:00:00'),
    (1,1,1,69.0,165,25.34,87,101,'Nota interna recente','${recent} 09:00:00',1,'${recent} 10:00:00')");`);
  // A fixture compartilhada (NI2-91) traz um autorrelato antigo; este teste começa sem autorrelatos.
  phpRun(`$db->exec("DELETE FROM patient_self_metrics");`);
  const sock = net.createServer(); await new Promise((r) => sock.listen(0, '127.0.0.1', r)); const port = sock.address().port; await new Promise((r) => sock.close(r));
  const server = spawn('php', ['-d', 'display_errors=0', '-d', 'session.save_path=' + dir, '-S', '127.0.0.1:' + port, '-t', 'public'], { cwd: root, env, stdio: 'ignore' });
  const B = 'http://127.0.0.1:' + port;
  for (let i = 0; i < 100; i++) { try { await fetch(B + '/up'); break; } catch { await new Promise((r) => setTimeout(r, 50)); } }
  const api = async (method, path, token, body) => {
    const res = await fetch(B + path, { method, headers: { Accept: 'application/json', Authorization: 'Bearer ' + token, ...(body !== undefined ? { 'Content-Type': 'application/json' } : {}) }, body: body !== undefined ? JSON.stringify(body) : undefined });
    const text = await res.text(); let json = null; try { json = JSON.parse(text); } catch { /* */ } return { status: res.status, text, json };
  };
  const webUser = () => {
    let cookie = '';
    const req = async (method, path, form) => {
      const res = await fetch(B + path, { method, redirect: 'manual', headers: { Cookie: cookie, ...(form ? { 'Content-Type': 'application/x-www-form-urlencoded' } : {}) }, body: form ? form.toString() : undefined });
      for (const c of (res.headers.getSetCookie ? res.headers.getSetCookie() : [])) { const kv = c.split(';')[0]; if (kv.startsWith('PHPSESSID=')) cookie = kv; }
      return { status: res.status, location: res.headers.get('location') || '', text: await res.text() };
    };
    const csrf = async (path) => { const r = await req('GET', path); const m = r.text.match(/name="_csrf_token" value="([^"]+)"/); return m ? m[1] : ''; };
    const login = async (email) => { const t = await csrf('/login'); return req('POST', '/login', new URLSearchParams({ _csrf_token: t, email, password: 'Equipe@2026' })); };
    const post = async (path, pairs) => { const t = await csrf('/pacientes/1'); const f = new URLSearchParams([['_csrf_token', t], ...pairs]); return req('POST', path, f); };
    return { req, csrf, login, post };
  };
  try {
    const tok = async (email) => (await api('POST', '/api/v1/auth/patient-login', '', { email, password: 'PacienteTeste@2026' })).json.token;
    const ana = await tok('ana@example.test'), bruno = await tok('bruno@example.test');

    // ---------------------------------------------------------------- permissões (fechado por padrão)
    let r = await api('POST', '/api/v1/patient/self-metrics', ana, { metric_type: 'weight_kg', value: 64 });
    check('permissão', 'Sem liberação do nutricionista: peso → 403 METRIC_NOT_ENABLED e nada gravado', r.status === 403 && r.json.code === 'METRIC_NOT_ENABLED' && Number(one('SELECT COUNT(*) FROM patient_self_metrics WHERE patient_id = 1')) === 0, r.text.slice(0, 200));
    r = await api('GET', '/api/v1/patient/evolution', ana);
    check('permissão', 'Evolução informa que não há medidas liberadas', r.status === 200 && Array.isArray(r.json.enabled_metrics) && r.json.enabled_metrics.length === 0);

    const recep = webUser(); await recep.login('recepcao@example.test');
    r = await recep.post('/pacientes/1/autorrelatos/permissoes', [['metrics[]', 'weight_kg']]);
    check('permissão', 'Recepção não libera medidas (403)', r.status === 403 && Number(one('SELECT COUNT(*) FROM patient_self_metric_permissions')) === 0, r.status);
    const equipe = webUser(); await equipe.login('equipe@example.test');
    r = await equipe.req('POST', '/pacientes/1/autorrelatos/permissoes', new URLSearchParams([['_csrf_token', 'x'], ['metrics[]', 'weight_kg']]));
    check('permissão', 'Sem CSRF válido → 403', r.status === 403 && Number(one('SELECT COUNT(*) FROM patient_self_metric_permissions')) === 0, r.status);
    r = await equipe.post('/pacientes/1/autorrelatos/permissoes', [['metrics[]', 'weight_kg'], ['metrics[]', 'pressao_inventada']]);
    check('permissão', 'Medida fora do catálogo é recusada e nada muda', r.status === 302 && Number(one('SELECT COUNT(*) FROM patient_self_metric_permissions')) === 0, r.status);
    r = await equipe.post('/pacientes/1/autorrelatos/permissoes', [['metrics[]', 'weight_kg'], ['metrics[]', 'waist_cm']]);
    check('permissão', 'Nutricionista libera peso e cintura', r.status === 302 && Number(one("SELECT COUNT(*) FROM patient_self_metric_permissions WHERE patient_id = 1 AND enabled = 1")) === 2, r.status);
    const pa = sql("SELECT user_id FROM audit_logs WHERE action = 'SELF_METRIC_PERMISSIONS_CHANGED' ORDER BY id DESC LIMIT 1")[0];
    check('permissão', 'Liberação auditada com autor', pa && Number(pa.user_id) === 3, pa);
    const outro = webUser(); await outro.login('nutri2@example.test');
    r = await outro.post('/pacientes/1/autorrelatos/permissoes', [['metrics[]', 'hip_cm']]);
    check('permissão', 'Nutricionista de outra clínica não altera a paciente', r.status !== 302 && Number(one("SELECT COUNT(*) FROM patient_self_metric_permissions WHERE metric_type = 'hip_cm'")) === 0, r.status);

    // ---------------------------------------------------------------- autorrelato validado
    r = await api('GET', '/api/v1/patient/evolution', ana);
    check('autorrelato', 'Evolução lista as medidas liberadas com unidade e faixa', r.json.enabled_metrics.map((m) => m.type).join(',') === 'waist_cm,weight_kg' && r.json.enabled_metrics.every((m) => m.unit && m.min > 0 && m.max > m.min), r.json.enabled_metrics);
    r = await api('POST', '/api/v1/patient/self-metrics', ana, { metric_type: 'weight_kg', value: 64.34, notes: 'Pela manhã' });
    const now = localNow();
    const recAt = r.json && r.json.recorded_at ? new Date(r.json.recorded_at.replace(' ', 'T')) : null;
    check('autorrelato', 'Peso liberado → 201, unidade do catálogo e valor arredondado', r.status === 201 && r.json.unit === 'kg' && r.json.value === 64.3 && r.json.label === 'Peso', r.text.slice(0, 200));
    check('autorrelato', 'Horário gravado no fuso do consultório (igual ao devolvido)', recAt && Math.abs(recAt - now) < 180000 && one(`SELECT recorded_at FROM patient_self_metrics WHERE id = ${r.json.id}`) === r.json.recorded_at, { rec: r.json && r.json.recorded_at, now: now.toString() });
    const n0 = Number(one('SELECT COUNT(*) FROM patient_self_metrics'));
    const bad = [
      ['peso 4000 kg', { metric_type: 'weight_kg', value: 4000 }, 422],
      ['peso 10 kg', { metric_type: 'weight_kg', value: 10 }, 422],
      ['peso em lb', { metric_type: 'weight_kg', value: 150, unit: 'lb' }, 422],
      ['tipo inventado', { metric_type: 'pressao_arterial_inventada', value: 120, unit: 'mmHg' }, 422],
      ['quadril não liberado', { metric_type: 'hip_cm', value: 100 }, 403],
      ['observação de 501 caracteres', { metric_type: 'weight_kg', value: 64, notes: 'x'.repeat(501) }, 422],
      ['valor não numérico', { metric_type: 'weight_kg', value: 'abc' }, 422],
      ['sem valor', { metric_type: 'weight_kg' }, 422],
      ['sem tipo', { value: 63.4 }, 422],
    ];
    for (const [label, body, code] of bad) {
      r = await api('POST', '/api/v1/patient/self-metrics', ana, body);
      check('autorrelato', `Recusado: ${label} → ${code}`, r.status === code && r.json && typeof r.json.error === 'string', { status: r.status, body: r.text.slice(0, 160) });
    }
    check('autorrelato', 'Nenhuma tentativa inválida gravou registro', Number(one('SELECT COUNT(*) FROM patient_self_metrics')) === n0);
    r = await api('POST', '/api/v1/patient/self-metrics', ana, { metric_type: 'waist_cm', value: '86,5'.replace(',', '.') });
    check('autorrelato', 'Cintura liberada → 201 em cm', r.status === 201 && r.json.unit === 'cm', r.status);
    r = await api('POST', '/api/v1/patient/self-metrics', bruno, { metric_type: 'weight_kg', value: 80 });
    check('autorrelato', 'Liberação é por paciente: Bruno sem liberação → 403', r.status === 403, r.status);

    // ---------------------------------------------------------------- linha do tempo e filtro
    r = await api('GET', '/api/v1/patient/evolution', ana);
    const all = r.json;
    const peso = (all.series || []).find((s) => s.type === 'weight_kg');
    check('evolução', 'Série de peso combina avaliação do nutricionista e autorrelato, cada ponto com a fonte', peso && peso.points.length === 3 && peso.points.filter((p) => p.source === 'clinical_official').length === 2 && peso.points.some((p) => p.source === 'patient_self_report'), peso);
    check('evolução', 'Pontos em ordem cronológica', peso && peso.points.every((p, i, a) => i === 0 || a[i - 1].date <= p.date));
    check('evolução', 'Rótulos legíveis e fonte por extenso', all.self_reports.every((s) => s.label && s.source_label === 'Registrado por você') && all.clinical_records.every((c) => c.source_label === 'Registrado pelo seu nutricionista'));
    check('evolução', 'Anotação interna do profissional continua fora da resposta', !JSON.stringify(all).includes('Nota interna'));
    const from = ymd(new Date(localNow().getTime() - 90 * 86400000));
    r = await api('GET', `/api/v1/patient/evolution?from=${from}`, ana);
    check('evolução', 'Filtro de 3 meses exclui a avaliação de janeiro', r.status === 200 && r.json.clinical_records.length === 1 && r.json.period.from === from && r.json.self_reports.length === 2, { n: r.json && r.json.clinical_records.length });
    r = await api('GET', '/api/v1/patient/evolution?from=2026-01-01&to=2026-01-31', ana);
    check('evolução', 'Período fechado (jan/2026) só traz o que está nele', r.status === 200 && r.json.clinical_records.length === 1 && r.json.self_reports.length === 0, r.json && r.json.summary);
    r = await api('GET', '/api/v1/patient/evolution?from=ontem', ana);
    check('evolução', 'Data inválida no filtro → 422', r.status === 422, r.status);
    r = await api('GET', '/api/v1/patient/evolution?from=2026-05-01&to=2026-04-01', ana);
    check('evolução', 'Início depois do fim → 422', r.status === 422, r.status);
    r = await api('GET', '/api/v1/patient/evolution', bruno);
    check('evolução', 'Bruno não vê registros nem metas da Ana', r.status === 200 && r.json.self_reports.length === 0 && r.json.clinical_records.length === 0 && r.json.series.length === 0);

    // ---------------------------------------------------------------- metas
    const future = ymd(new Date(localNow().getTime() + 60 * 86400000));
    r = await recep.post('/pacientes/1/metas', [['metric_type', 'weight_kg'], ['direction', 'decrease'], ['target_value', '62'], ['target_date', future]]);
    check('metas', 'Recepção não cria meta (403)', r.status === 403 && Number(one('SELECT COUNT(*) FROM patient_goals')) === 0, r.status);
    for (const [label, pairs] of [
      ['valor fora da faixa', [['metric_type', 'weight_kg'], ['direction', 'decrease'], ['target_value', '4000']]],
      ['data no passado', [['metric_type', 'weight_kg'], ['direction', 'decrease'], ['target_value', '62'], ['target_date', '2020-01-01']]],
      ['medida fora do catálogo', [['metric_type', 'pressao'], ['direction', 'decrease'], ['target_value', '62']]],
      ['direção inválida', [['metric_type', 'weight_kg'], ['direction', 'zerar'], ['target_value', '62']]],
    ]) {
      r = await equipe.post('/pacientes/1/metas', pairs);
      check('metas', `Meta recusada: ${label}`, r.status === 302 && Number(one('SELECT COUNT(*) FROM patient_goals')) === 0, r.status);
    }
    r = await equipe.post('/pacientes/1/metas', [['metric_type', 'weight_kg'], ['direction', 'decrease'], ['target_value', '62,5'], ['target_date', future], ['message_for_patient', 'Vamos acompanhar juntas, sem pressa.']]);
    const goal = sql('SELECT * FROM patient_goals')[0];
    check('metas', 'Nutricionista cria meta (valor com vírgula, data futura, mensagem)', r.status === 302 && goal && Number(goal.target_value) === 62.5 && goal.status === 'active' && Number(goal.professional_id) === 3, goal);
    r = await api('GET', '/api/v1/patient/evolution', ana);
    const g = (r.json.goals || [])[0];
    check('metas', 'Paciente vê a meta com o último registro de cada fonte, sem "quanto falta"', g && g.label === 'Peso' && g.direction_label === 'Reduzir' && g.latest_clinical && g.latest_clinical.value === 69 && g.latest_self_report && g.latest_self_report.value === 64.3 && !('remaining' in g) && !('progress' in g), g);
    check('metas', 'Mensagem do nutricionista chega à paciente', g && g.message_for_patient === 'Vamos acompanhar juntas, sem pressa.');
    r = await api('GET', '/api/v1/patient/evolution', bruno);
    check('metas', 'Meta da Ana não aparece para o Bruno', (r.json.goals || []).length === 0);
    r = await equipe.post(`/pacientes/1/metas/${goal.id}/encerrar`, [['status', 'achieved']]);
    check('metas', 'Nutricionista marca a meta como atingida', r.status === 302 && one(`SELECT status FROM patient_goals WHERE id = ${goal.id}`) === 'achieved', r.status);
    r = await equipe.post(`/pacientes/1/metas/${goal.id}/encerrar`, [['status', 'cancelled']]);
    check('metas', 'Meta encerrada não muda de novo', one(`SELECT status FROM patient_goals WHERE id = ${goal.id}`) === 'achieved');
    r = await api('GET', '/api/v1/patient/evolution', ana);
    check('metas', 'Meta encerrada sai da lista da paciente (fica no histórico do profissional)', (r.json.goals || []).length === 0 && Number(one('SELECT COUNT(*) FROM patient_goals')) === 1);
    const ga = sql("SELECT action, user_id FROM audit_logs WHERE action IN ('PATIENT_GOAL_CREATED','PATIENT_GOAL_CLOSED') ORDER BY id");
    check('metas', 'Criação e encerramento auditados com autor', ga.length === 2 && ga.every((x) => Number(x.user_id) === 3), ga);

    // ---------------------------------------------------------------- visão do profissional
    r = await equipe.req('GET', '/pacientes/1');
    check('profissional', 'Perfil mostra a seção "Evolução no app da paciente"', r.status === 200 && r.text.includes('Evolução no app da paciente'), r.status);
    check('profissional', 'Perfil lista os autorrelatos da paciente (valor, medida e observação)', /data-testid="self-reports"/.test(r.text) && r.text.includes('64,3 kg') && r.text.includes('Pela manhã') && r.text.includes('Circunferência da cintura'), null);
    check('profissional', 'Perfil mostra a meta com status e histórico', r.text.includes('Atingida') && r.text.includes('62,5 kg'));
    check('profissional', 'Caixas de liberação refletem o que está liberado', /value="weight_kg" checked/.test(r.text) && /value="hip_cm" >/.test(r.text));
    r = await recep.req('GET', '/pacientes/1');
    check('profissional', 'Recepção vê a seção sem os formulários de alteração', r.status !== 200 || (!r.text.includes('/autorrelatos/permissoes') && !r.text.includes('goal-form')), r.status);

    // ---------------------------------------------------------------- revogar liberação e LGPD
    r = await equipe.post('/pacientes/1/autorrelatos/permissoes', [['metrics[]', 'waist_cm']]);
    r = await api('POST', '/api/v1/patient/self-metrics', ana, { metric_type: 'weight_kg', value: 64 });
    check('permissão', 'Peso deixa de ser liberado: novo registro → 403; anteriores continuam visíveis', r.status === 403 && (await api('GET', '/api/v1/patient/evolution', ana)).json.self_reports.some((s) => s.metric_type === 'weight_kg'), r.status);
    const exp = phpRun(`\\ClyoNutri\\Core\\Auth\\TenantContext::set(1, ['id' => 1, 'role' => 'nutritionist']); echo json_encode(array_keys((new \\ClyoNutri\\Domain\\Privacy\\LgpdService($db))->exportPatientData(1)));`);
    check('lgpd', 'Pacote de portabilidade inclui metas e liberações', exp.includes('patient_goals') && exp.includes('patient_self_metric_permissions'), exp);
  } catch (e) {
    results.push({ crit: 'erro', name: e.message.split('\n')[0], ok: false }); console.log('FAIL (interrompido) ' + e.stack);
  } finally {
    server.kill(); rmSync(dir, { recursive: true, force: true });
    if (useMysql) { try { mysqlAdmin(`DROP DATABASE IF EXISTS ${mysqlDb}`); } catch { /* */ } }
    if (process.env.RESULTS_JSON) writeFileSync(process.env.RESULTS_JSON, JSON.stringify(results, null, 1));
  }
  const ok = results.filter((x) => x.ok).length;
  console.log(`\nNI2-135 (${useMysql ? 'MySQL' : 'SQLite'}): ${ok} PASS, ${results.length - ok} FAIL`);
  if (ok !== results.length) process.exitCode = 1;
})();
