# Clyo Nutri v54.51 — Fatura/recibo clicável (HostGator)

Na tabela **Faturas** de Configurações → Assinatura, a fatura com recibo no Asaas mostra o número com "Ver recibo", que abre a página do Asaas em nova aba (`target="_blank" rel="noopener noreferrer"`). Sem URL, a fatura aparece como antes, sem link.

- Webhook financeiro (PAYMENT_RECEIVED/CONFIRMED/CHECKOUT_PAID) grava a melhor URL do objeto payment: `transactionReceiptUrl` > `invoiceUrl` > `bankSlipUrl`. Só https em `sandbox.asaas.com`, `www.asaas.com` ou `asaas.com`.
- Fatura com pagamento Asaas e sem URL salva: ao abrir a página, o servidor consulta `GET /payments/{id}` (no máximo 3 por carga, só com `ASAAS_API_KEY` configurada) e grava a URL. Falha ou id de teste/local (ex.: `pay_test_3`): fatura sem link, a página não quebra.
- Página continua só para titular/administrador (RBAC atual). A API key nunca vai para o HTML.

## Banco (MySQL 5.7) — migração 090

Pelo migrador do app (idempotente), ou manualmente no phpMyAdmin:

```sql
SET @c := (SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'asaas_payment_receipts' AND COLUMN_NAME = 'receipt_url');
SET @s := IF(@c = 0, 'ALTER TABLE asaas_payment_receipts ADD COLUMN receipt_url VARCHAR(500) NULL', 'SELECT 1');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;
INSERT INTO migrations (migration) SELECT '2026_11_05_000090_asaas_receipt_url'
  FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM migrations WHERE migration = '2026_11_05_000090_asaas_receipt_url');
```

Deploy: substituir `src/Domain/Billing/` (AsaasWebhookService.php, novo AsaasReceiptUrl.php), `src/Domain/Subscription/SubscriptionService.php`, `views/settings/subscription.php`, `database/migrations/2026_11_05_000090_asaas_receipt_url.php`, `src/Core/Release/BuildIdentity.php`, `RELEASE_INFO.json`, `config/build-manifest.json`. Não sobrescrever `.env`.
