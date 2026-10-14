// Playwright do gate de homologação ClyoNutri II (NI2-409).
// Sem retries: falha intermitente é falha. Screenshots e traces viram evidência do build.
import { defineConfig, devices } from '@playwright/test';
import path from 'node:path';

const evidence = process.env.GATE_EVIDENCE_DIR || path.resolve('../../storage/homologation-local');
const base = process.env.GATE_BASE_URL || 'http://127.0.0.1:8180';
const mobile = process.env.GATE_MOBILE_URL || 'http://127.0.0.1:8190';

export default defineConfig({
  testDir: './specs',
  outputDir: path.join(evidence, 'playwright-artifacts'),
  fullyParallel: false,
  workers: 1,
  retries: 0,
  forbidOnly: true,
  timeout: 90_000,
  expect: { timeout: 10_000 },
  reporter: [
    ['list'],
    ['json', { outputFile: path.join(evidence, 'playwright-report.json') }],
    ['html', { outputFolder: path.join(evidence, 'playwright-html'), open: 'never' }],
  ],
  use: {
    baseURL: base,
    screenshot: 'only-on-failure',
    trace: 'retain-on-failure',
    locale: 'pt-BR',
    timezoneId: 'America/Sao_Paulo',
  },
  projects: [
    { name: 'api', testMatch: /api-.*\.spec\.mjs/ },
    { name: 'web', testMatch: /web-.*\.spec\.mjs/, use: { ...devices['Desktop Chrome'], viewport: { width: 1280, height: 800 } } },
    { name: 'responsive', testMatch: /responsive\.spec\.mjs/, use: { ...devices['Desktop Chrome'] } },
    { name: 'a11y', testMatch: /a11y\.spec\.mjs/, use: { ...devices['Desktop Chrome'], viewport: { width: 1280, height: 800 } } },
    { name: 'mobile-app', testMatch: /mobile-.*\.spec\.mjs/, use: { ...devices['Pixel 7'], baseURL: mobile } },
  ],
});
