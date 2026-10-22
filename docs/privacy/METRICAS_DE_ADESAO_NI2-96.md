# Métricas de adesão com privacidade (NI2-96)

- **Card:** [NI2-96](https://caplybr.atlassian.net/browse/NI2-96), épico NI2-12.
- **Versão:** 1.0, de 22/10/2026.
- **Status:** implementado. Falta a validação do jurídico e do encarregado (DPO) (ver o item 8).

## 1. O que medimos e por quê

Como produto, queremos saber se as pacientes usam o app no dia a dia. Medimos três ações:

| Métrica | Quando conta | O que **não** é guardado |
|---|---|---|
| `plan_viewed`: plano visto no app | A paciente abre o resumo do app (`GET /api/v1/patient/home`) e ela tem um plano publicado | Conteúdo do plano, refeições, alimentos, horário |
| `checkin_submitted`: check-in enviado | A paciente envia ou corrige o check-in do dia (`POST /api/v1/patient/checkin`) | % de adesão, energia, fome, observação |
| `hydration_logged`: hidratação registrada | A paciente registra água (`POST /api/v1/patient/hydration`) | Volume em ml, meta |

Finalidade: entender o uso do app para melhorar o produto, por exemplo para saber se o check-in é usado e se o plano é visto. A finalidade **não** inclui avaliar ou ranquear pacientes, nutricionistas ou clínicas.

## 2. O que é guardado

**`analytics_daily_metrics`:** uma linha por dia, clínica e métrica, com:
- `event_count`: quantas vezes aconteceu no dia;
- `unique_patients`: quantas pacientes diferentes fizeram a ação no dia.

Não há id, nome, e-mail ou qualquer valor clínico. Depois de o dia acabar, essa tabela não tem como apontar para uma paciente.

**Pseudônimo transitório (`analytics_daily_subjects` e `analytics_daily_salts`):** existe só para contar pacientes distintas no dia.
- Para cada dia é sorteado um sal aleatório de 256 bits, `random_bytes`, que não deriva de nenhuma chave da aplicação.
- O pseudônimo é `HMAC-SHA256("clínica:paciente", sal do dia)`.
- O sal e os pseudônimos de dias encerrados são apagados pelo agendador (`bin/cron_scheduler.php`, a cada 15 minutos). Sem o sal, o hash não volta à paciente.
- Durante o próprio dia, quem tiver acesso ao banco e ao sal poderia testar ids de pacientes contra os hashes. Por isso tratamos esse dado como dado pessoal pseudonimizado, com prazo de um dia.

## 3. Base legal

- **Pseudônimo do dia (dado pessoal):** legítimo interesse (LGPD, art. 7º, IX), com as medidas do art. 10:
  - minimização: só o pseudônimo, sem valores;
  - prazo curtíssimo;
  - transparência na política de privacidade (seção 3, "Métricas de uso do app");
  - possibilidade de desligar.
- **Contadores agregados:** dado anonimizado depois que o sal é apagado (art. 12). Mesmo assim, aplicamos o corte mínimo (item 5).
- **Não usamos consentimento para esta métrica.** Também não ligamos a métrica a nenhum dado de saúde da paciente: o check-in em si é dado de saúde, guardado em `checkins` para o acompanhamento clínico (art. 11, II, "f"), mas a métrica guarda só o fato de que houve um envio.

## 4. Quem vê

- Só o console da plataforma, em `/admin/metricas-adesao`, com a permissão `analytics.read`.
  - Têm essa permissão: `superadmin`, `admin_operacional` e `operator`.
  - Não têm: suporte, financeiro e auditor.
- As clínicas e os profissionais não veem esta tela. Os dados das próprias pacientes continuam no Perfil 360.

## 5. Corte mínimo (k = 5)

- Qualquer número de pacientes abaixo de 5 aparece como "menos de 5", tanto nos totais quanto por semana e por clínica.
- Uma clínica com menos de 5 pacientes com acesso ao app não aparece separada. Ela entra só nos totais, e a tela diz quantas ficaram de fora.
- "Pacientes por dia" soma os distintos de cada dia: a mesma paciente em dois dias conta duas vezes. A tela explica isso.

## 6. Retenção

| Dado | Prazo |
|---|---|
| Sal e pseudônimos do dia | Apagados assim que o dia acaba (na próxima execução do agendador) |
| Contadores agregados | 24 meses, depois apagados (`AdherenceAnalytics::purge`) |

O pedido LGPD da paciente (exportação e exclusão) não precisa tratar estas tabelas: elas não têm id de paciente. Os check-ins da paciente seguem as regras do prontuário: entram na exportação (`checkins`) e ficam sob a guarda legal.

## 7. Como desligar

Em Admin > Configurações globais, desmarque "Métricas de adesão agregadas (NI2-96)" (`analytics_adherence_enabled = 0`).
- A coleta para na hora. A mudança é auditada como `ADMIN_SETTINGS_UPDATED`.
- O que já foi somado continua até o prazo do item 6.
- A coleta nunca interrompe a ação da paciente: se falhar, o erro é engolido e registrado sem dados.

## 8. Pendências

- **Validação jurídica e do encarregado (DPO):** o ClyoNutri é operador dos dados das clínicas. Para esta métrica de produto, atua como controlador. É preciso:
  - registrar isso no contrato ou DPA com as clínicas;
  - fazer o teste de balanceamento (LIA) do legítimo interesse.

  O código está pronto para isso, mas a decisão jurídica não é do time de engenharia.
- **Não medido:** abertura do plano pelo portal web do profissional (fora do escopo, só o app da paciente conta).
