# NI2-96: métricas de adesão com privacidade

Data: 22/10/2026. Código em `~/Nutri_II`, branch `merge/v11-teleconsulta`.

## Decisões do produto

- **Check-in:** criado no app, com rota real, tela e auditoria. Isso também destrava o indicador de adesão da NI2-94.
- **Onde aparece:** só no painel da plataforma (`/admin/metricas-adesao`), com números agregados e corte mínimo de 5.
- **Base legal:** legítimo interesse, com dados agregados. Guardamos só contadores diários por clínica e um pseudônimo do dia, apagado no dia seguinte. A coleta pode ser desligada em Configurações globais.

Documentação: `docs/privacy/METRICAS_DE_ADESAO_NI2-96.md`. Também foram atualizados o inventário LGPD e a política de privacidade (seção 3, versão 1.1.0).

## Critérios de aceite

| Critério | Como | Prova |
|---|---|---|
| Medir plano visto, check-ins e hidratação | `AdherenceAnalytics::record` em `GET /api/v1/patient/home` (com plano publicado), `POST /api/v1/patient/checkin` e `POST /api/v1/patient/hydration` | HTTP [coleta] ×7 |
| Tracking mínimo | Por dia, clínica e métrica: `event_count` e `unique_patients`. Nada mais | HTTP [privacidade] colunas |
| Sem conteúdo clínico | Não guarda volume, % de adesão, observação, plano nem id da paciente. O pseudônimo é um HMAC com sal aleatório do dia, apagado na virada | HTTP [privacidade] ×4 e [retenção] ×5 |
| Agregação | Painel da plataforma por período (7, 30 ou 90 dias), por semana e por clínica. Abaixo de 5 aparece "menos de 5". Clínica com menos de 5 pacientes no app não aparece separada | HTTP [painel] ×9; app-web painel |
| Base legal ou consentimento | Legítimo interesse documentado, chave de desligar auditada, política de privacidade atualizada | HTTP [chave] ×4 |
| Documentação | Documento de privacidade, inventário LGPD, OpenAPI 1.6.0 (`/patient/checkin`, `/patient/checkin/today`, `checkin_today`) | arquivos |

## Execução

| Prova | Resultado |
|---|---|
| `tests/ni2_96_adherence_analytics_real_test.cjs`, SQLite | 42/42 (`depois-sqlite.log`) |
| Mesmo teste, MySQL 8.0.46 | 42/42 (`depois-mysql.log`) |
| Mesmo teste na árvore anterior (HEAD 6e2b1d6) | 0 PASS, 3 FAIL: `POST /api/v1/patient/checkin` dá 404 e as tabelas de métricas não existem (`antes.log`) |
| `tests/ni2_96_app_web_real_test.cjs`: app web a 390 px e painel a 1280 px, com axe | 15/15 (`app-web-resultados.json` e PNGs) |
| SQL manual 49, chaves INT e BIGINT UNSIGNED, rodado duas vezes | OK (`sql-manual-mysql8.txt`) |
| Checagem do schema no MySQL 8 (instalação nova igual ao upgrade) | ok (`schema-gate-mysql8.json`) |
| Regressão | 97 suítes legadas sem falha nova (19 já falhavam antes); HTTP/navegador NI2-89, 91, 135, 137, 138, 298, 412, 433, 437 todos PASS; cliente mobile 294 e 323 PASS (`regressao-suites.txt`) |

## Limites

- **Check-in no celular nativo (iOS e Android):** NOT TESTED. Só testado no app web, a 390 px.
- **MySQL 5.7:** NOT TESTED.
- **Validação jurídica e do encarregado (DPO):** pendente. É preciso registrar no contrato ou DPA com as clínicas que o ClyoNutri atua como controlador desta métrica, e fazer o teste de balanceamento (LIA). Ver o item 8 do documento de privacidade.
- **Fora desta entrega:** o painel do profissional da NI2-94. O check-in agora existe e aparece no Perfil 360, mas a lista de atenção não foi alterada aqui.
