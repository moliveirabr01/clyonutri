// P1 — UX responsiva: páginas-chave em 375 px (celular) e 768 px (tablet) sem rolagem horizontal.
import { test, expect } from '@playwright/test';
import { registerNutritionist, createStaff, login, shot } from '../support/helpers.mjs';

const PUBLIC = ['/', '/login', '/cadastro', '/recuperar-senha'];
const NUTRI = ['/dashboard', '/pacientes', '/agenda', '/planos', '/alimentos', '/financeiro', '/configuracoes', '/ajuda', '/receitas'];
const ADMIN = ['/admin/dashboard', '/admin/clinicas', '/admin/usuarios', '/admin/auditoria'];

for (const width of [375, 768]) {
  test(`sem rolagem horizontal em ${width}px`, async ({ browser }, testInfo) => {
    const ctx = await browser.newContext({ viewport: { width, height: 900 } });
    const page = await ctx.newPage();
    const overflow = [];
    const check = async (path) => {
      await page.goto(path);
      const { sw, iw } = await page.evaluate(() => ({ sw: document.documentElement.scrollWidth, iw: window.innerWidth }));
      if (sw > iw + 1) overflow.push(`${path} (${sw}px > ${iw}px)`);
      await shot(page, testInfo, `resp-${width}${path.replace(/\//g, '_') || '_home'}`);
    };
    for (const p of PUBLIC) await check(p);
    await registerNutritionist(page, { name: 'Nutri Responsiva', clinic: 'Consultório Responsivo' });
    for (const p of NUTRI) await check(p);
    await ctx.clearCookies();
    const admin = createStaff('superadmin');
    await login(page, admin.email, admin.password);
    for (const p of ADMIN) await check(p);
    await ctx.close();
    testInfo.annotations.push({ type: 'overflow', description: overflow.join('; ') || 'nenhuma' });
    expect(overflow, `páginas com rolagem horizontal em ${width}px`).toEqual([]);
  });
}
