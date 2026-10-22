// NI2-135 — app da paciente (build web do Expo) contra o servidor real: meta, gráfico acessível, filtro de período e registro.
// Pré-requisito: build web em mobile/dist (cd mobile && npx expo export --platform web --output-dir dist).
//   PLAYWRIGHT_MODULE=… AXE_MODULE=… CHROMIUM_PATH=… node tests/ni2_135_app_web_real_test.cjs
const { spawn, execFileSync } = require('node:child_process'); const { mkdtempSync } = require('node:fs'); const { join } = require('node:path');
const pw = require(process.env.PLAYWRIGHT_MODULE || 'playwright'); const axePath = require.resolve(process.env.AXE_MODULE || 'axe-core');
const root = require('node:path').resolve(__dirname, '..'), dir = mkdtempSync('/tmp/ni2-91-'); const env = { ...process.env, DB_CONNECTION: 'sqlite', DB_DATABASE: join(dir, 't.sqlite'), NI2_FIXTURE_DIR: dir, APP_ENV: 'production', FORCE_HTTPS: 'false', APP_TIMEZONE: 'America/Sao_Paulo', TZ: 'America/Sao_Paulo' };
const php = (code) => execFileSync('php', ['-r', `require '${root}/vendor/autoload.php'; $db = \\ClyoNutri\\Core\\Database\\Connection::getInstance(); ${code}`], { cwd: root, env, encoding: 'utf8' });
const out = { checks: [] }; const ck = (n, ok, d) => { out.checks.push({ n, ok: !!ok, d: ok ? undefined : d }); console.log((ok ? 'PASS ' : 'FAIL ') + n + (ok ? '' : ' → ' + String(JSON.stringify(d)).slice(0, 200))); };
(async () => {
  execFileSync('php', ['tests/support/ni2_91_fixture.php', 'prepare'], { cwd: root, env });
  php(`$db->exec("DELETE FROM patient_self_metrics");
    $db->exec("INSERT INTO anthropometric_assessments(clinic_id,patient_id,professional_id,weight_kg,height_cm,bmi,waist_cm,hip_cm,notes,assessed_at,is_shared,shared_at) VALUES
      (1,1,1,72.0,165,26.45,90,104,'Nota interna','2026-07-10 09:00:00',1,'2026-07-10 10:00:00'),(1,1,1,70.4,165,25.86,88,103,'Nota interna 2','2026-09-01 09:00:00',1,'2026-09-01 10:00:00'),(1,1,1,69.0,165,25.34,87,101,'Nota interna 3','2026-10-10 09:00:00',1,'2026-10-10 10:00:00')");
    $db->exec("INSERT INTO patient_self_metric_permissions(clinic_id,patient_id,metric_type,enabled,updated_at) VALUES (1,1,'weight_kg',1,CURRENT_TIMESTAMP),(1,1,'waist_cm',1,CURRENT_TIMESTAMP)");
    $db->exec("INSERT INTO patient_self_metrics(clinic_id,patient_id,metric_type,value,unit,source,recorded_at) VALUES (1,1,'weight_kg',70.8,'kg','patient_self_report','2026-08-15 08:00:00'),(1,1,'weight_kg',69.6,'kg','patient_self_report','2026-09-20 08:00:00')");
    $db->exec("INSERT INTO patient_goals(clinic_id,patient_id,professional_id,metric_type,target_value,unit,direction,target_date,message_for_patient,status,created_at,updated_at) VALUES (1,1,1,'weight_kg',66,'kg','decrease','2026-12-20','Vamos acompanhar juntas, sem pressa.','active',CURRENT_TIMESTAMP,CURRENT_TIMESTAMP)");`);
  const PORT = 18000 + Math.floor(Math.random() * 2000); const srv = spawn('php', ['-S', '127.0.0.1:' + PORT, '-t', 'mobile/dist', 'tests/support/ni2_294_ui_router.php'], { cwd: root, env, stdio: 'ignore' }); await new Promise(r => setTimeout(r, 900));
  const br = await pw.chromium.launch({ ...(process.env.CHROMIUM_PATH ? { executablePath: process.env.CHROMIUM_PATH, headless: false, args: ['--headless=new'] } : {}) });
  for (const [w, h] of [[390, 844], [1024, 900]]) {
    const ctx = await br.newContext({ viewport: { width: w, height: h }, locale: 'pt-BR', timezoneId: 'America/Sao_Paulo' }); const p = await ctx.newPage();
    const errs = []; p.on('pageerror', e => errs.push(e.message));
    await p.goto('http://127.0.0.1:' + PORT + '/'); await p.getByPlaceholder('seuemail@exemplo.com').fill('ana@example.test'); await p.getByPlaceholder('Digite sua senha').fill('PacienteTeste@2026');
    await p.getByRole('button', { name: 'Entrar', exact: true }).click(); await p.getByText('Evolução', { exact: true }).last().waitFor({ timeout: 15000 });
    await p.getByText('Evolução', { exact: true }).last().click(); await p.getByText('Evolução e metas').waitFor();
    await p.getByText('12 meses').waitFor(); await p.waitForTimeout(800);
    if (w === 390) {
      const body = await p.locator('body').innerText();
      ck('Meta visível com último registro de cada fonte', /Meta: Reduzir peso/.test(body) && /Último registro do nutricionista: 69/.test(body) && /Seu último registro: 69,6/.test(body), body.slice(0, 400));
      ck('Gráfico de peso com resumo acessível (role=img)', await p.locator('[role="img"][aria-label^="Peso:"]').count() === 1, await p.locator('[role=img]').evaluateAll(es => es.map(e => e.getAttribute('aria-label'))));
      const label = await p.locator('[role="img"][aria-label^="Peso:"]').getAttribute('aria-label');
      ck('Resumo diz número de registros, fontes, primeiro/último e meta', /5 registro\(s\)/.test(label) && /3 do nutricionista, 2 seus/.test(label) && /Meta: 66 kg/.test(label), label);
      ck('Nenhuma anotação interna na tela', !/Nota interna/.test(body));
      ck('Data formatada no padrão brasileiro, sem fuso deslocado', /Avaliação · 10\/10\/2026 09:00/.test(body), body.match(/Avaliação · [^\n]*/g));
      await p.screenshot({ path: `${root}/docs/evidencias/ni2-135/app-evolucao-390.png`, fullPage: true });
      // Filtro: 30 dias esconde avaliações antigas
      await p.getByText('30 dias').click(); await p.waitForTimeout(900);
      const b30 = await p.locator('body').innerText();
      ck('Filtro 30 dias: avaliação de julho sai, a de outubro fica', !/10\/07\/2026/.test(b30) && /10\/10\/2026/.test(b30), b30.match(/Avaliação · [^\n]*/g));
      ck('Filtro anunciado como opção selecionada', await p.locator('[role="radio"][aria-checked="true"]').filter({ hasText: '30 dias' }).count() === 1);
      await p.getByText('12 meses').click(); await p.waitForTimeout(800);
      // Registro: valor fora da faixa bloqueado no app; válido gravado
      await p.getByRole('radio', { name: 'Peso (kg)' }).click();
      await p.getByPlaceholder('Valor em kg').fill('4000'); await p.getByText('Registrar', { exact: true }).click(); await p.waitForTimeout(400);
      ck('App bloqueia 4000 kg com mensagem clara', /Peso deve estar entre 20 e 400 kg/.test(await p.locator('body').innerText()));
      const before = Number(php(`echo $db->query("SELECT COUNT(*) FROM patient_self_metrics")->fetchColumn();`));
      await p.getByPlaceholder('Valor em kg').fill('68,9'); await p.getByPlaceholder('Observação (opcional)').fill('Depois do café');
      await p.getByText('Registrar', { exact: true }).click(); await p.getByText(/Peso registrado: 68,9 kg/).waitFor({ timeout: 8000 });
      const after = php(`echo json_encode($db->query("SELECT id, value, unit, notes, recorded_at FROM patient_self_metrics ORDER BY id DESC")->fetch(PDO::FETCH_ASSOC));`);
      ck('Registro pelo app grava no banco (68,9 kg, observação)', Number(php(`echo $db->query("SELECT COUNT(*) FROM patient_self_metrics")->fetchColumn();`)) === before + 1 && /68\.9/.test(after) && /Depois do caf/.test(after), after);
      await p.waitForTimeout(800);
      ck('Gráfico atualiza com o novo registro (6 pontos)', /6 registro\(s\)/.test(await p.locator('[role="img"][aria-label^="Peso:"]').getAttribute('aria-label')));
      await p.screenshot({ path: `${root}/docs/evidencias/ni2-135/app-evolucao-390-apos-registro.png`, fullPage: true });
      ck('Sem rolagem horizontal a 390 px', await p.evaluate(() => document.documentElement.scrollWidth <= innerWidth + 1));
      await p.addScriptTag({ path: axePath }).catch(() => {});
      const v = await p.evaluate(async () => window.axe ? (await window.axe.run(document, { runOnly: ['wcag2a', 'wcag2aa'] })).violations.map(x => x.id + ':' + x.nodes.length) : ['axe-indisponivel']);
      out.axe = v; ck('Aba Evolução sem violações WCAG 2 A/AA (axe)', v.length === 0, v);
    } else {
      await p.screenshot({ path: `${root}/docs/evidencias/ni2-135/app-evolucao-1024.png`, fullPage: true });
    }
    ck(`Sem erro de script (${w}px)`, errs.length === 0, errs);
    await ctx.close();
  }
  await br.close(); srv.kill(); const ok = out.checks.filter(c => c.ok).length; console.log(`\nNI2-135 app (web): ${ok} PASS, ${out.checks.length - ok} FAIL`); if (ok !== out.checks.length) process.exitCode = 1; require('node:fs').writeFileSync(`${root}/docs/evidencias/ni2-135/app-web-resultados.json`, JSON.stringify(out, null, 1));
})().catch(e => { console.log('ERRO', e.stack); process.exit(1); });
