# ClyoNutri II — v54.76 — atualização completa para HostGator (épico NI2-875)

Origem: `/Users/moliveira/Nutri_II`, branch `feat/v54-76-ni2-875` (local, sem push). Pacote completo de runtime para
atualizar uma instalação **v54.71 a v54.75**. Não faz deploy sozinho. **MFA da plataforma (NI2-876) continua desligado.**

> O SHA-256 do zip está no arquivo `clyonutri-v54-76-hostgator.zip.sha256`, ao lado do zip (e no fim da cópia deste
> LEIA-ME que fica fora do zip). Confira antes de subir: `shasum -a 256 clyonutri-v54-76-hostgator.zip` no Mac.

## 1. Antes de começar

1. Backup dos arquivos atuais (principalmente `.env` e `storage/`) e do banco (phpMyAdmin → Exportar → Personalizado → todas as tabelas).
   - Atenção ao restaurar um backup: o banco de produção tem **1 linha órfã** em `appointment_status_history`
     (histórico de uma consulta que já foi apagada). Na importação pelo phpMyAdmin, desmarque
     "Ativar verificação de chaves estrangeiras", senão a última `ALTER TABLE` do dump falha.
2. Guarde uma cópia da `CLINICAL_ENCRYPTION_KEY` do `.env` **fora do servidor** (gerenciador de senhas).
3. Abra uma janela de manutenção (horário sem atendimento).

## 2. Banco (phpMyAdmin → selecione o banco do sistema → aba SQL), nesta ordem

Todos os arquivos estão em `database/manual/` dentro do zip. Todos podem rodar mais de uma vez sem duplicar nada.
Foram testados no MySQL 5.7.44 real sobre uma cópia do banco de produção de 13/11/2026 (com a v54.63 e a v54.68
aplicadas e a 091 removida, como está a produção hoje): a sequência inteira rodou **2 vezes**, com `sql_mode` estrito e
não estrito, sem erro, com o esquema idêntico depois da 2ª rodada e **nenhuma migração pendente** no fim.

Conferência inicial (só leitura):
```sql
SELECT migration FROM migrations WHERE migration >= '2026_11_07' ORDER BY migration;
```
Se faltar alguma de 092 a 096 (produção anterior à v54.71), rode antes `atualizacao-v54-63-completa.sql` e
`atualizacao-v54-68-completa.sql` (idempotentes, também testados no mesmo banco).

| # | Migração | Arquivo | O que faz |
|---|---|---|---|
| 1 | **091** | `atualizacao-v54-52-ni2-767-production-events.sql` | **AINDA FALTA EM PRODUÇÃO** (NI2-767): cria `production_events` e `production_alerts` e registra a 091. |
| 2 | 097 | `ni2_878_subscription_activation_mysql57.sql` | NI2-878: `subscription_activation_requests`. **Novo na v54.76:** agora também registra a 097 (antes ficava pendente). |
| 3 | 098 | `ni2_885_patients_allergies_text_mysql57.sql` | NI2-885: `patients.allergies` VARCHAR(500) → TEXT. Rodar **antes** da cifragem. Última consulta: `allergies | text`. |
| 4 | 099 | `ni2_886_medidas_caseiras_revisao_mysql57.sql` | NI2-886: 5 colunas de revisão em `food_household_measures` + contagem das medidas estimadas. |
| 5 | 100 | `ni2_891_exames_revisao_mysql57.sql` | NI2-891: 2 colunas em `exam_catalog` + quantos exames aguardam revisão (hoje 22). |
| 6 | 101 | `cr875_14_password_reset_purpose_mysql57.sql` | CR875-14/NI2-890: `password_resets.purpose`. Links já enviados continuam valendo até expirar. |

Só leitura (para anexar ao Jira, quando quiser): `ni2_879_consultas_concluidas_fora_do_dia_LEITURA.sql` e
`ni2_885_verificacao_cifragem_LEITURA.sql`. **Não** rode `database/instalacao_mysql8.sql` em produção.

## 3. Arquivos

1. Envie `clyonutri-v54-76-hostgator.zip` para a raiz da instalação (a pasta com `.env`, `public`, `src`, `vendor`, `storage`) e extraia por cima. Não apague a pasta atual.
2. O zip **não tem** `.env`, `tests/`, `docs/`, banco local, sessões nem uploads. Tem `storage/.htaccess` (bloqueia acesso público) e o `vendor/` sem a cópia duplicada `vendor/vendor`.
3. `.env` de produção:
   - **Apague a linha `HSTS_MAX_AGE`** (ou deixe `HSTS_MAX_AGE=15552000`). Um valor antigo (ex.: 86400) segura o HSTS em 1 dia.
   - Mantenha `MFA_PLATFORM_REQUIRED=false` (MFA desligado, por decisão).
4. Confira que **não existe** `storage/hsts_rollback` (se existir, o HSTS sai `max-age=0`).
5. Se o cPanel mantiver opcache antigo, reinicie o PHP antes de testar.

## 4. Depois de subir

1. `/login` → entrar → rodapé do console mostra **v54.76**; `/configuracoes/versao`: integridade **consistente** e build igual ao do RELATORIO.
2. DevTools → Rede → qualquer página → `strict-transport-security: max-age=15552000` (sem includeSubDomains/preload).
3. Com pacientes de TESTE: login; agenda (concluir consulta de hoje; consulta futura não vira "Realizada"); ficha; agendamento online pelo link público (o comprovante mostra o código de 8 letras/números); convite de equipe; nova senha; sala com transcrição (aceite do paciente).
4. Integrações (NI2-877), pelo Terminal do cPanel na pasta do sistema:
   ```
   php bin/integration_check.php                        # prontidão de Asaas, SMTP, teleconsulta e voz (não envia nada)
   php bin/integration_check.php --asaas-ping           # só leitura no Asaas (precisa ASAAS_API_KEY)
   php bin/integration_check.php --smtp-send=SEU@EMAIL  # envia 1 e-mail de teste pelo SMTP do .env
   php bin/integration_check.php --stun                 # teste STUN real (UDP) da teleconsulta
   php bin/integration_check.php --evidence=ID_DA_CLINICA --json   # evidência do roteiro, sem dados pessoais
   ```
   Status: PASS (verificado agora), READY (configurado, falta o teste manual), BLOCKED (falta credencial/serviço), MANUAL. BLOCKED não é aprovação. O relatório fica em `storage/integration_validation/`.

## 5. NI2-885 — cifrar os dados clínicos antigos (quando você decidir; não precisa ser no mesmo dia)

1. Backup completo + cópia da `CLINICAL_ENCRYPTION_KEY` fora do servidor. **Sem a chave, o dado cifrado é irrecuperável.**
2. SQL 098 (item 3 da tabela acima) já aplicado.
3. cPanel → Cron Jobs → rodar daqui a 2–3 min (troque o caminho):
   `/usr/local/bin/php /home/SEU_USUARIO/CAMINHO/bin/encrypt_clinical_records.php --dry-run >> /home/SEU_USUARIO/CAMINHO/storage/logs/cron-ni2-885.txt 2>&1`
   e confira as contagens no `storage/logs/cron-ni2-885.txt`. Nada é gravado no `--dry-run`.
4. Execução real: `... bin/encrypt_clinical_records.php --max-seconds=240 >> .../storage/logs/cron-ni2-885.txt 2>&1`.
   PARCIAL = deixe o Cron rodar de novo (continua de onde parou). Erros por registro ficam em `storage/logs/encrypt_clinical_records-*.json` (tabela, id e coluna; nunca o conteúdo).
5. Verificação: `... bin/encrypt_clinical_records.php --verify` → esperado `OK: 0 valores em claro e 0 que não decifram`. No phpMyAdmin: `ni2_885_verificacao_cifragem_LEITURA.sql` → tudo 0 (ou NULL).
6. Abra 3 prontuários antigos (perfil, prontuário, anamnese), teste a busca e a exportação.
7. **Remova a entrada do Cron.**
- HSTS: volta atrás criando `storage/hsts_rollback` (ou `HSTS_MAX_AGE=0`).

## 6. Histórias (NI2-876 a NI2-894)

| História | Status nesta versão |
|---|---|
| NI2-876 MFA do console | **Não feita** — MFA segue desligado por decisão sua |
| NI2-877 integrações reais | Feita (ferramenta + SMTP que não prende a página); validação externa **BLOCKED** (Asaas sandbox, SMTP HostGator, 2 aparelhos, voz) |
| NI2-878 fim do teste grátis | Sem mudança de código; SQL 097 agora registra a migração (testado no MySQL 5.7) |
| NI2-879 consulta futura | Feita — regressão da v54.75 desfeita |
| NI2-880 diário alimentar | Sem mudança (já estava na v54.75); só teste automatizado, E2E não testado |
| NI2-881 manipulados | Sem mudança (já estava na v54.75) |
| NI2-882 transcrição | Feita — sem texto fictício, com locutor; fala real BLOCKED |
| NI2-883 evolução no app | Sem mudança (já estava na v54.75); só teste automatizado |
| NI2-884 suíte de testes | **Parcial** — `bin/run-tests full` + triagem; 40 testes desatualizados e 12 a investigar |
| NI2-885 cifragem do legado + HSTS | Feita — execução em produção é sua (seção 5) |
| NI2-886 medidas caseiras | Feita — revisão do conteúdo pelo nutricionista RT **BLOCKED** |
| NI2-887 carga cognitiva | **Parcial** — só a ficha; editor de plano, anamnese em etapas, Configurações e menu **adiados** |
| NI2-888 agenda | Sem mudança (já estava na v54.75) |
| NI2-889 textos e plurais | Feita |
| NI2-890 entrada e convite | Feita — limite de links inválidos, tipo do link (101), tela de espera limpa |
| NI2-891 catálogos | Feita — códigos TUSS/LOINC dos 22 exames dependem do RT (**BLOCKED**) |
| NI2-892 dívida estrutural | **Parcial** — fatia 1; etapas 2–4 adiadas, metas de consultas não atingidas |
| NI2-893 acabamento | Feita — item 3 (agendamento sem id sequencial) nesta versão |
| NI2-894 avisos duplicados | Feita (preservada) |

## 7. Decisões que dependem de você

1. Quem é o **nutricionista RT** que revisa o catálogo de exames (NI2-891) e as medidas caseiras (NI2-886, hoje o papel ficou com o superadmin).
2. A **assinatura ICP-Brasil** (NI2-535) entra no roteiro?
3. NI2-887: as **7 abas da ficha contam** na meta de ≤15 links? (com as abas: 18; sem: 11).
4. NI2-884: (a) política de dependências do `composer.json` (ni2_469); (b) a mesma política no ni2_475; (c) o campo anti-spam (honeypot) que saiu do cadastro volta?
5. OK para abrir no Jira os **12 bugs INVESTIGAR** da NI2-884 (lista no RELATORIO).
6. **Quando** rodar a cifragem da NI2-885 em produção.

## 8. Voltar à versão anterior

Restaure o backup de arquivos preservando `.env` e `storage/`. As mudanças de banco são aditivas (tabelas e colunas novas;
`allergies` ficou TEXT) e podem ficar. Se já rodou a cifragem (NI2-885), **não volte para versão anterior à v54.71**:
as leituras cifradas exigem o código atual. Não há rollback destrutivo automático.
