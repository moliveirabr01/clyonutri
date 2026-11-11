# ClyoNutri II — Guia de Deploy HostGator v50 Consolidado

**Versão da Release:** `v50-consolidado`  
**Data:** 01/11/2026  
**Banco de Dados:** MySQL 5.7+ / MySQL 8 (`maic6824_Clyonutri2`)  
**Pré-requisito:** Aplicação em produção com migrações até a v49 (Migração 68 aplicada).

---

## 1. Conteúdo Unificado Desta Versão

Esta release consolida e unifica integralmente em um único pacote testado:
1. **v50 — Feriados, Bloqueios, Exceções & Correção do Fluxo da Agenda:**
   - Separação nativa de data (`scheduled_date`) e horário (`scheduled_time`) na criação e no reagendamento de consultas.
   - Políticas de atendimento, feriados municipais/estaduais/nacionais (dia inteiro ou parcial) e bloqueios com escopo (clínica ou profissional).
   - Intervalos de resguardo e preparo (buffers antes e depois de cada consulta) aplicados à agenda interna e ao autoagendamento.
   - Marcadores visuais de feriados e exceções nas visões Diária, Semanal e Mensal da agenda com link direto para edição.
   - Lista de espera pública/autenticada no Portal do Paciente com processamento assíncrono via cron e expiração por TTL.
   - **Migração 69:** Tabelas `booking_calendar_policies`, `booking_calendar_exceptions` e `booking_waitlist`.
2. **NI2-223 — Anamnese Estruturada em 12 Blocos Clínicos (P0):**
   - 12 seções clínicas completas: Queixa Principal, Histórico Familiar, Patologias Preexistentes, Cirurgias, Medicamentos, Suplementos, Intolerâncias/Alergias, Consumo de Álcool, Tabagismo, Sono, Atividade Física e Exames Laboratoriais Referenciados.
   - Campos condicionais com validação em tempo real e persistência no banco.
   - Renderização adaptativa em tela de consulta, histórico, visualização e impressão clínica.
   - **Migração 70:** Colunas clínicas estruturadas na tabela `anamneses`.
3. **NI2-523 — Alertas Clínicos Populacionais:**
   - Avisos clínicos automáticos nas fórmulas de cálculo energético (gestantes, adolescentes atletas sob equação de Cunningham e idosos $\ge 65$ anos com IMC mínimo).
4. **NI2-433 & NI2-487 — Autoagendamento Self-Service & Notificações Transacionais:**
   - Agendamento autônomo pelo Portal do Paciente, prevenção de overbooking com hold temporário e outbox transacional de notificações sem risco de mensagens obsoletas.
5. **NI2-514, NI2-519 & NI2-520 — Equivalências, Modelos & Importação CSV:**
   - Normalizador de DDI +55 e política de expurgo LGPD (M2/M3), planos qualitativos (A2) e sincronização declarativa de rotas.

---

## 2. Passo a Passo do Deploy

### Passo 1: Backup Completo
1. Pelo cPanel ou phpMyAdmin, gere um dump completo (estrutura + dados) do banco `maic6824_Clyonutri2`.
2. Compacte e baixe um backup dos arquivos atuais do site (`/home3/maic6824/clyonutri.com`).

### Passo 2: Pausa Operacional
1. No cPanel > Tarefas Cron (Cron Jobs), desative temporariamente a execução do `bin/cron_scheduler.php`.

### Passo 3: Atualização do Banco de Dados via phpMyAdmin
Abra o phpMyAdmin, certifique-se de que o banco `maic6824_Clyonutri2` está selecionado e execute os arquivos SQL da pasta `database/manual/` na ordem:
1. `atualizacao-v50-consolidada-preflight.sql`: Leitura diagnóstica; valida existência da tabela `anamneses` e migração 68.
2. `atualizacao-v50-consolidada-completa.sql`: Cria as tabelas da Migração 69 e adiciona as colunas da Migração 70 com proteção idempotente.
3. `atualizacao-v50-consolidada-verificacao.sql`: Confere se todas as tabelas, colunas, índices e registros de migração (69 e 70) estão em conformidade.

### Passo 4: Atualização dos Arquivos
1. Acesse o Gerenciador de Arquivos do cPanel na pasta raiz da aplicação (`/home3/maic6824/clyonutri.com`).
2. Faça o upload do arquivo `clyonutri-v50-consolidado-hostgator.zip`.
3. Extraia o ZIP sobrescrevendo os arquivos existentes (o conteúdo está na raiz do arquivo).
4. **IMPORTANTE:** O arquivo `.env` e o diretório `storage/` de produção **não são sobrescritos** pelo pacote e devem permanecer intactos.
5. Exclua o arquivo `.zip` enviado da hospedagem.

### Passo 5: Finalização e Reativação
1. Se disponível no cPanel, limpe o cache do PHP (OPcache).
2. Reative a tarefa cron no cPanel (`* * * * * php /home3/maic6824/clyonutri.com/bin/cron_scheduler.php >/dev/null 2>&1`).
3. Acesse a aplicação no navegador e valide:
   - Login no sistema.
   - Navegação na Agenda (`/agenda`) e conferência dos campos separados de data e hora.
   - Configurações > Feriados e Exceções (`/configuracoes`).
   - Prontuário do Paciente > Anamnese (`/pacientes/{id}/anamnese`).

---

## 3. Procedimento de Rollback

Em caso de necessidade de reversão:
1. Pause a tarefa do cron no cPanel.
2. Desabilite a lista de espera e os feriados em Configurações.
3. Restaure o backup dos arquivos da versão v49 na pasta da aplicação.
4. Mantenha as colunas e tabelas criadas no banco de dados (elas não interferem na versão anterior e evitam perda de novos agendamentos).
5. Reative o cron do sistema.
