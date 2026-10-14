// Resume o relatório JSON do Playwright por projeto (NI2-409).
// Uso: node summarize.mjs <playwright-report.json>  → JSON em stdout.
// Teste pulado (skipped) é contado como SKIPPED — nunca como sucesso.
import fs from 'node:fs';

const file = process.argv[2];
const out = { projects: {}, annotations: [] };
if (!file || !fs.existsSync(file)) {
  console.log(JSON.stringify({ ...out, error: 'relatório do Playwright ausente' }));
  process.exit(0);
}
const report = JSON.parse(fs.readFileSync(file, 'utf8'));

const walk = (suite, parents = []) => {
  for (const spec of suite.specs ?? []) {
    for (const t of spec.tests ?? []) {
      const p = (out.projects[t.projectName] ??= { passed: 0, failed: 0, skipped: 0, flaky: 0, tests: [] });
      const last = t.results?.[t.results.length - 1];
      const status = t.status === 'expected' ? 'passed' : t.status === 'skipped' ? 'skipped' : t.status === 'flaky' ? 'flaky' : 'failed';
      p[status] += 1;
      const error = last?.errors?.[0]?.message?.replace(/\u001b\[[0-9;]*m/g, '').split('\n').slice(0, 3).join(' ') ?? null;
      p.tests.push({ title: [...parents, spec.title].filter(Boolean).join(' › '), file: spec.file, status, duration_ms: last?.duration ?? 0, error });
      for (const a of t.annotations ?? []) out.annotations.push({ project: t.projectName, test: spec.title, ...a });
    }
  }
  for (const child of suite.suites ?? []) walk(child, [...parents, child.title && !child.title.endsWith('.mjs') ? child.title : null]);
};
for (const s of report.suites ?? []) walk(s);
console.log(JSON.stringify(out));
