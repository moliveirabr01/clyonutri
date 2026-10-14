// Jornada do service desk: nutricionista abre chamado, agente de suporte atende pela fila,
// nutricionista vê a resposta. Cada etapa conferida no MySQL; acesso indevido à fila bloqueado.
import { test, expect } from '@playwright/test';
import { registerNutritionist, createStaff, dbOne, db, shot, login, uid } from '../support/helpers.mjs';

test.describe.configure({ mode: 'serial' });

let nutri; let agent; let ticket;
const subject = `Dúvida sobre plano alimentar ${uid()}`;

test('nutricionista abre chamado na Central de Ajuda', async ({ page }, testInfo) => {
  nutri = await registerNutritionist(page, { name: 'Nutri Chamado', clinic: 'Consultório Suporte' });
  await page.goto('/ajuda/abrir-chamado');
  const form = page.locator('form[action="/ajuda/abrir-chamado"]');
  await form.locator('select[name=category]').selectOption({ index: 1 });
  await form.locator('select[name=type]').selectOption({ index: 1 });
  await form.locator('select[name=priority_perceived]').selectOption({ index: 1 });
  await form.locator('input[name=subject]').fill(subject);
  await form.locator('textarea[name=description]').fill('Não encontro onde publicar o plano depois de adicionar os alimentos. Passo a passo detalhado para reproduzir.');
  await Promise.all([page.waitForURL(/\/ajuda\/chamado\//), form.locator('#btn-submit-ticket').click()]);
  ticket = dbOne('SELECT id, protocol, status, clinic_id FROM helpdesk_tickets WHERE subject = ?', [subject]);
  expect(ticket, 'chamado persistido').not.toBeNull();
  expect(Number(ticket.clinic_id)).toBe(nutri.clinicId);
  await expect(page.getByText(ticket.protocol).first()).toBeVisible();
  await shot(page, testInfo, '20-chamado-aberto');
});

test('nutricionista não acessa a fila do suporte (403, sem dados de chamados)', async ({ page }, testInfo) => {
  await login(page, nutri.email, nutri.password);
  const res = await page.goto('/suporte/fila');
  expect(res.status()).toBe(403);
  await expect(page.locator('body')).not.toContainText(subject);
  // Achado de UX registrado no relatório: a negação chega ao navegador como JSON cru.
  testInfo.annotations.push({ type: 'ux-finding', description: 'GET /suporte/fila por perfil sem acesso devolve JSON cru ao navegador em vez de página de erro.' });
  await shot(page, testInfo, '20b-fila-negada-nutricionista');
});

test('agente de suporte atende pela fila e muda o status', async ({ page }, testInfo) => {
  agent = createStaff('support_agent');
  await login(page, agent.email, agent.password);
  await page.goto('/suporte/fila');
  await expect(page.getByText(ticket.protocol).first()).toBeVisible();
  await shot(page, testInfo, '21-fila-suporte');

  await page.goto(`/suporte/chamado/${ticket.id}`);
  const reply = page.locator(`form[action="/suporte/chamado/${ticket.id}/responder"]`);
  await reply.locator('textarea[name=message]').fill('Olá! Abra o plano, clique em "Revisar & Publicar" e confirme. Qualquer dúvida estamos aqui.');
  await Promise.all([page.waitForNavigation(), reply.locator('[type=submit]').first().click()]);
  const msgs = db('SELECT message FROM helpdesk_ticket_messages WHERE ticket_id = ? ORDER BY id', [ticket.id]);
  expect(msgs.some((m) => m.message.includes('Revisar & Publicar')), 'resposta do suporte persistida').toBeTruthy();

  const statusForm = page.locator(`form[action="/suporte/chamado/${ticket.id}/status"]`);
  const select = statusForm.locator('select[name=status]');
  await select.selectOption('in_progress').catch(async () => select.selectOption({ index: 1 }));
  await Promise.all([page.waitForNavigation(), statusForm.locator('[type=submit]').first().click()]);
  expect(dbOne('SELECT status FROM helpdesk_tickets WHERE id = ?', [ticket.id]).status).not.toBe('open');
  await shot(page, testInfo, '22-chamado-atendido');
});

test('nutricionista vê a resposta do suporte no chamado', async ({ page }, testInfo) => {
  await login(page, nutri.email, nutri.password);
  await page.goto(`/ajuda/chamado/${ticket.protocol}`);
  await expect(page.getByText('Revisar & Publicar').first()).toBeVisible();
  await shot(page, testInfo, '23-resposta-recebida');
});
