# ClyoNutri II — v54.77 — atualização completa para HostGator (decisões do Maicon de 17/11/2026)

Origem: `/Users/moliveira/Nutri_II`, branch `feat/v54-77-decisoes` (local, sem push), em cima da v54.76.
Pacote completo de runtime para atualizar uma instalação **v54.71 a v54.76**. Não faz deploy sozinho.
**MFA da plataforma (NI2-876) continua desligado.**

> O SHA-256 do zip está no arquivo `clyonutri-v54-77-hostgator.zip.sha256`, ao lado do zip (e no fim da cópia deste
> LEIA-ME que fica fora do zip). Confira antes de subir: `shasum -a 256 clyonutri-v54-77-hostgator.zip` no Mac.

## O que muda em relação à v54.76

1. **RT dos catálogos = Raphael Lombardi** (NI2-886 e NI2-891). O nome aparece no console de medidas caseiras, no aviso
   do catálogo de exames, na tela de medidas da clínica e em cada revisão gravada ("revisada por Raphael Lombardi — CRN…").
   **Falta o CRN dele** (e, se quiser, o e-mail da conta dele no console): sem CRN o sistema **não grava nenhuma revisão**
   (nem pela tela, nem pelo CSV, nem pelo console). Preencha depois de subir — seção 4, item 2.
2. **Ficha do paciente com até 15 links visíveis, contando as 7 abas** (NI2-887). O resumo deixou de repetir o que as abas
   já abrem: o histórico curto virou texto ("Ver histórico completo e filtros" abre a lista com links), a última evolução
   virou texto, saiu o atalho "Revisar alergia" (o aviso da alergia continua logo acima), e durante a consulta as etapas
   ficam em "Etapas deste atendimento" (clique para abrir). Nada foi removido do sistema.
3. Testes: política de dependências única (a da NI2-475: php, PHPMailer e dompdf aprovados) e o teste do cadastro
   passa a conferir o campo anti-robô atual (o `website_url` antigo não volta). Sem efeito na tela.

## 1. Antes de começar

1. Backup dos arquivos atuais (principalmente `.env` e `storage/`) e do banco (phpMyAdmin → Exportar → Personalizado → todas as tabelas).
   - Atenção ao restaurar um backup: o banco de produção tem **1 linha órfã** em `appointment_status_history`
     (histórico de uma consulta que já foi apagada). Na importação pelo phpMyAdmin, desmarque
     "Ativar verificação de chaves estrangeiras", senão a última `ALTER TABLE` do dump falha.
2. Guarde uma cópia da `CLINICAL_ENCRYPTION_KEY` do `.env` **fora do servidor** (gerenciador de senhas).
3. Abra uma janela de manutenção (horário sem atendimento).

## 2. Banco (phpMyAdmin → selecione o banco do sistema → aba SQL), nesta ordem

Todos os arquivos estão em `database/manual/` dentro do zip. Todos podem rodar mais de uma vez sem duplicar nada e
**sem apagar o que já foi preenchido**. Foram testados no MySQL 5.7.44 real sobre uma cópia do banco de produção de
13/11/2026 (com a v54.63 e a v54.68 aplicadas e a 091 removida, como está a produção hoje): a sequência inteira rodou
**2 vezes**, com `sql_mode` estrito e não estrito, sem erro, com o esquema idêntico depois da 2ª rodada e **nenhuma
migração pendente** no fim.

Conferência inicial (só leitura):
```sql
SELECT migration FROM migrations WHERE migration >= '2026_11_07' ORDER BY migration;
```
Se faltar alguma de 092 a 096 (produção anterior à v54.71), rode antes `atualizacao-v54-63-completa.sql` e
`atualizacao-v54-68-completa.sql` (idempotentes, também testados no mesmo banco).
Se você **já subiu a v54.76** e rodou os itens 1 a 6, basta o item 7 (rodar de novo os anteriores também não faz mal).

| # | Migração | Arquivo | O que faz |
|---|---|---|---|
| 1 | **091** | `atualizacao-v54-52-ni2-767-production-events.sql` | **AINDA FALTA EM PRODUÇÃO** (NI2-767): cria `production_events` e `production_alerts` e registra a 091. |
| 2 | 097 | `ni2_878_subscription_activation_mysql57.sql` | NI2-878: `subscription_activation_requests` e registra a 097. |
| 3 | 098 | `ni2_885_patients_allergies_text_mysql57.sql` | NI2-885: `patients.allergies` VARCHAR(500) → TEXT. **Pode rodar agora** (só muda o tipo da coluna). Última consulta: `allergies | text`. |
| 4 | 099 | `ni2_886_medidas_caseiras_revisao_mysql57.sql` | NI2-886: 5 colunas de revisão em `food_household_measures` + contagem das medidas estimadas. |
| 5 | 100 | `ni2_891_exames_revisao_mysql57.sql` | NI2-891: 2 colunas em `exam_catalog` + quantos exames aguardam revisão (hoje 22). |
| 6 | 101 | `cr875_14_password_reset_purpose_mysql57.sql` | CR875-14/NI2-890: `password_resets.purpose`. Links já enviados continuam valendo até expirar. |
| 7 | **102** | `v54_77_rt_catalogos_mysql57.sql` | **Novo na v54.77:** grava o RT dos catálogos (nome **Raphael Lombardi**; CRN e e-mail em branco) em `platform_settings` e registra a 102. Última consulta: 3 linhas — `catalog_rt_name = Raphael Lombardi`, `catalog_rt_crn` e `catalog_rt_email` vazios. Rodar de novo não apaga um CRN já preenchido. |

Só leitura (para anexar ao Jira, quando quiser): `ni2_879_consultas_concluidas_fora_do_dia_LEITURA.sql` e
`ni2_885_verificacao_cifragem_LEITURA.sql`. **Não** rode `database/instalacao_mysql8.sql` em produção.

## 3. Arquivos

1. Envie `clyonutri-v54-77-hostgator.zip` para a raiz da instalação (a pasta com `.env`, `public`, `src`, `vendor`, `storage`) e extraia por cima. Não apague a pasta atual.
2. O zip **não tem** `.env`, `tests/`, `docs/`, banco local, sessões nem uploads. Tem `storage/.htaccess` (bloqueia acesso público) e o `vendor/` sem a cópia duplicada `vendor/vendor`.
3. `.env` de produção:
   - **Apague a linha `HSTS_MAX_AGE`** (ou deixe `HSTS_MAX_AGE=15552000`). Um valor antigo (ex.: 86400) segura o HSTS em 1 dia.
   - Mantenha `MFA_PLATFORM_REQUIRED=false` (MFA desligado, por decisão).
4. Confira que **não existe** `storage/hsts_rollback` (se existir, o HSTS sai `max-age=0`).
5. Se o cPanel mantiver opcache antigo, reinicie o PHP antes de testar.

## 4. Depois de subir

1. `/login` → entrar → rodapé do console mostra **v54.77**; `/configuracoes/versao`: integridade **consistente** e build igual ao do RELATORIO.
2. **CRN do RT (obrigatório para revisar medidas caseiras e exames):** entre com a conta de administração da plataforma →
   **Admin → Medidas caseiras → "Responsável técnico (RT) dos catálogos"** → confira o nome **Raphael Lombardi**, preencha o
   **CRN** no formato `CRN-<região> <número>` (ex.: `CRN-3 12345`; também aceita `crn3 12345`) e, se ele tiver conta no
   console, o **e-mail dessa conta** (opcional: com ele, as revisões feitas pelo próprio Raphael não mostram "registrado por").
   (o formulário fica em "Alterar dados do RT") → Salvar. A faixa passa a dizer "…ficam registradas em nome de
   Raphael Lombardi — CRN-…". Enquanto o CRN estiver vazio, a tela mostra "Revisões bloqueadas"
   e nenhuma revisão é gravada. A alteração fica na auditoria (`CATALOG_RT_UPDATED`).
3. DevTools → Rede → qualquer página → `strict-transport-security: max-age=15552000` (sem includeSubDomains/preload).
4. Com pacientes de TESTE: login; agenda (concluir consulta de hoje; consulta futura não vira "Realizada"); **ficha do
   paciente** (Resumo com as 7 abas e no máximo 15 links; "Ver histórico completo e filtros" / "Abrir registros e filtrar" abre a lista com links e filtros; durante a
   consulta, "Etapas deste atendimento" abre as etapas); agendamento online pelo link público; convite de equipe; nova senha;
   cadastro de nutricionista; sala com transcrição (aceite do paciente).
5. Integrações (NI2-877), pelo Terminal do cPanel na pasta do sistema:
   ```
   php bin/integration_check.php                        # prontidão de Asaas, SMTP, teleconsulta e voz (não envia nada)
   php bin/integration_check.php --asaas-ping           # só leitura no Asaas (precisa ASAAS_API_KEY)
   php bin/integration_check.php --smtp-send=SEU@EMAIL  # envia 1 e-mail de teste pelo SMTP do .env
   php bin/integration_check.php --stun                 # teste STUN real (UDP) da teleconsulta
   php bin/integration_check.php --evidence=ID_DA_CLINICA --json   # evidência do roteiro, sem dados pessoais
   ```
   Status: PASS (verificado agora), READY (configurado, falta o teste manual), BLOCKED (falta credencial/serviço), MANUAL. BLOCKED não é aprovação.

## 5. NI2-885 — cifragem dos dados clínicos antigos: **NÃO RODE AGORA**

Decisão sua de 17/11/2026: a cifragem em produção fica para depois.
- **Não** crie o Cron e **não** rode `bin/encrypt_clinical_records.php` (nem `--dry-run`) nesta atualização.
- O SQL **098** (item 3 da seção 2) **pode e deve** rodar agora: só troca o tipo de `patients.allergies` para TEXT.
- O roteiro da cifragem (backup + chave fora do servidor, `--dry-run`, execução, `--verify`, remover o Cron) continua no
  `LEIA-ME-HOSTGATOR-v54-76.md`, seção 5 (no pacote da v54.76 e no repositório), para quando você decidir.
- HSTS de 6 meses vale já nesta versão (volta atrás criando `storage/hsts_rollback` ou `HSTS_MAX_AGE=0`).

## 6. Decisões aplicadas nesta versão (17/11/2026)

| Decisão | O que mudou |
|---|---|
| RT dos catálogos = Raphael Lombardi | Nome configurável (SQL 102 + tela Admin > Medidas caseiras); revisões exigem o CRN; nome em telas e registros. **Falta o CRN.** |
| Assinatura ICP-Brasil (NI2-535) | Fora do roteiro — nada mudou. |
| As 7 abas contam nos 15 links | Ficha do paciente: 18 → 12 (consulta futura), 20 → 14 (alergia + anamnese), 23 → 12 (em atendimento). |
| Política de dependências: NI2-475 | Lista aprovada: php, PHPMailer, dompdf (PDF do pedido de exame, v45). Pacote novo = decisão nova. |
| Honeypot `website_url` não volta | Nada muda no cadastro: o campo anti-robô atual (`cn_campo_extra`) já existia e continua. |
| 12 bugs INVESTIGAR no Jira | Fora deste pacote (Jira tratado à parte). |
| NI2-885 em produção | Depois — seção 5. |

## 7. Voltar à versão anterior

Restaure o backup de arquivos preservando `.env` e `storage/`. As mudanças de banco são aditivas (a 102 só acrescenta 3
linhas em `platform_settings`) e podem ficar; a v54.76 ignora essas linhas. Não há rollback destrutivo automático.
