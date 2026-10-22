// P1 — Acessibilidade (axe-core, WCAG 2.1 A/AA) nas páginas-chave. Falha com violações críticas;
// as demais ficam registradas no relatório.
import { test, expect } from '@playwright/test';
import AxeBuilder from '@axe-core/playwright';
import fs from 'node:fs';
import path from 'node:path';
import { registerNutritionist, EVIDENCE_DIR } from '../support/helpers.mjs';

test('páginas-chave sem violações críticas de acessibilidade', async ({ page }, testInfo) => {
  const results = {};
  const scan = async (p) => {
    await page.goto(p);
    const r = await new AxeBuilder({ page }).withTags(['wcag2a', 'wcag2aa', 'wcag21a', 'wcag21aa']).analyze();
    results[p] = r.violations.map((v) => ({ id: v.id, impact: v.impact, nodes: v.nodes.length, help: v.help }));
  };
  // NI2-140 (A7): a página /app da paciente entra na varredura e não pode ter violação séria nem crítica.
  for (const p of ['/', '/login', '/cadastro', '/app']) await scan(p);
  await registerNutritionist(page, { name: 'Nutri A11y', clinic: 'Consultório Acessível' });
  for (const p of ['/dashboard', '/pacientes', '/agenda', '/planos', '/ajuda/abrir-chamado']) await scan(p);
  fs.mkdirSync(EVIDENCE_DIR, { recursive: true });
  fs.writeFileSync(path.join(EVIDENCE_DIR, 'a11y-violations.json'), JSON.stringify(results, null, 2));
  const all = Object.entries(results).flatMap(([p, vs]) => vs.map((v) => ({ page: p, ...v })));
  const byImpact = all.reduce((acc, v) => ({ ...acc, [v.impact]: (acc[v.impact] || 0) + 1 }), {});
  testInfo.annotations.push({ type: 'a11y', description: JSON.stringify(byImpact) });
  expect(all.filter((v) => v.impact === 'critical'), 'violações críticas').toEqual([]);
  expect(all.filter((v) => v.page === '/app' && ['critical', 'serious'].includes(v.impact)), 'violações sérias ou críticas em /app').toEqual([]);
});
