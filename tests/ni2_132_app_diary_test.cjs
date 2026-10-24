// NI2-132 — app (paciente e profissional): datas do diário, contrato com a API e telas.
// Usa o TypeScript de mobile/node_modules (como a NI2-323). Rode `npm ci` em mobile/ antes.
//   node tests/ni2_132_app_diary_test.cjs
'use strict';
const assert = require('node:assert/strict');
const fs = require('node:fs');
const os = require('node:os');
const path = require('node:path');
const { execFileSync } = require('node:child_process');
const root = path.resolve(__dirname, '..');
const ts = require(path.join(root, 'mobile/node_modules/typescript'));
const read = (p) => fs.readFileSync(path.join(root, p), 'utf8');
const results = [];
const check = (name, fn) => { try { fn(); results.push(true); console.log('PASS ' + name); } catch (e) { results.push(false); console.log('FAIL ' + name + ' → ' + String(e.message).slice(0, 300)); } };

const dir = fs.mkdtempSync(path.join(os.tmpdir(), 'ni2-132-app-'));
const file = path.join(dir, 'presentation.cjs');
fs.writeFileSync(file, ts.transpileModule(read('mobile/src/utils/presentation.ts'), { compilerOptions: { module: ts.ModuleKind.CommonJS, target: ts.ScriptTarget.ES2022 } }).outputText);
const code = `
const p = require(${JSON.stringify(file)});
const out = {
  local: p.displayLocalDateTime('2026-10-20 12:30:00'),
  localT: p.displayLocalDateTime('2026-10-20T12:30:00'),
  localDate: p.displayLocalDateTime('2026-10-20 12:30:00', false),
  localBad: p.displayLocalDateTime('lixo'),
  localNull: p.displayLocalDateTime(null),
  legacy: p.displayDateTime('2026-10-20 12:30:00'),
  day: p.localDayKey('2026-10-20 23:59:00'),
  dayNull: p.localDayKey(null),
  todayYes: p.isMealToday('2026-10-20 23:59:00', new Date(2026, 9, 20, 0, 5)),
  todayNo: p.isMealToday('2026-10-19 23:59:00', new Date(2026, 9, 20, 0, 5)),
  todayNull: p.isMealToday(null, new Date(2026, 9, 20, 0, 5)),
};
console.log(JSON.stringify(out));`;
for (const tz of ['America/Sao_Paulo', 'UTC', 'Asia/Tokyo', 'Pacific/Auckland']) {
  check(`Datas do diário no fuso do aparelho ${tz}: 12:30 gravado continua 12:30 (sem os 3 h de diferença)`, () => {
    const o = JSON.parse(execFileSync('node', ['-e', code], { env: { ...process.env, TZ: tz }, encoding: 'utf8' }));
    assert.equal(o.local, '20/10/2026 12:30'); assert.equal(o.localT, '20/10/2026 12:30'); assert.equal(o.localDate, '20/10/2026');
    assert.equal(o.localBad, 'Data não informada'); assert.equal(o.localNull, 'Data não informada');
    assert.equal(o.day, '2026-10-20'); assert.equal(o.dayNull, null);
    assert.equal(o.todayYes, true); assert.equal(o.todayNo, false); assert.equal(o.todayNull, false);
    if (tz === 'America/Sao_Paulo') assert.notEqual(o.legacy, '20/10/2026 12:30', 'displayDateTime (UTC) é o comportamento antigo que causava o erro de 3 h');
  });
}

const patient = read('mobile/src/screens/PatientHomeScreen.tsx');
const pro = read('mobile/src/screens/ProfessionalHomeScreen.tsx');
const api = read('mobile/src/services/api.ts');
check('Paciente: horário do diário usa displayLocalDateTime e o dia de "hoje" não passa por Date', () => {
  assert.match(patient, /displayLocalDateTime\(/);
  assert.doesNotMatch(patient, /displayDateTime\([^)]*consumed_at/);
  assert.doesNotMatch(patient, /new Date\([^)]*consumed_at[^)]*\)\.toLocaleDateString/);
  assert.match(patient, /isMealToday\(/);
});
check('Profissional: consumed_at e reviewed_at com displayLocalDateTime (não mostra horário cru)', () => {
  assert.match(pro, /displayLocalDateTime\([^)]*consumed_at/);
  assert.match(pro, /displayLocalDateTime\([^)]*reviewed_at/);
});
check('Cliente da API: paginação, patient_id, timezone e sem campos internos nos tipos', () => {
  assert.match(api, /getFoodDiary\(/); assert.match(api, /next_cursor/); assert.match(api, /patientId/); assert.match(api, /timezone/);
  const typeBlock = api.slice(api.indexOf('interface ProfessionalFoodDiaryItem'), api.indexOf('interface ProfessionalFoodDiaryItem') + 900);
  assert.doesNotMatch(typeBlock, /patient_email|clinic_id|photo_path/);
});
check('Paciente: envia o fuso do aparelho e trata 4xx sem reenviar a mesma chave', () => {
  assert.match(patient, /deviceTimezone\(\)/);
  assert.match(patient, /status >= 400 && [^\n]*status < 500|status === 429/);
});
check('Diário: subtítulo correto e botões de carregar mais', () => {
  assert.match(patient, /Refeições que você registrou e os comentários do seu nutricionista/);
  assert.doesNotMatch(patient, /Fotos e histórico/);
  assert.match(patient, /Carregar registros mais antigos/); assert.match(pro, /Carregar mais antigos/);
});
const failed = results.filter((x) => !x).length;
console.log(`\nNI2-132 app: ${results.length - failed} PASS, ${failed} FAIL`);
fs.rmSync(dir, { recursive: true, force: true });
if (failed) process.exitCode = 1;
