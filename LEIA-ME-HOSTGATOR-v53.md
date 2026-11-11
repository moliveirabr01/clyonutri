# Clyo Nutri — Guia de Atualização HostGator (Release v53 Consolidada)

**Build:** `v53-consolidado-20261101`  
**Escopo unificado:** NI2-567 (15 remediações de UX/A11y/Navegação), Domínio de Prescrições e Manipulados (NI2-554), Autoagendamento Self-Service Completo (NI2-433), Worker Bounded da Fila de Espera, e 12 Seções de Anamnese Estruturada (NI2-223).

---

## 1. Scripts SQL Disponíveis (Pasta `database/manual/`)

Para instalações no cPanel / phpMyAdmin da HostGator, foram preparados três scripts consolidados e idempotentes:

1. **`atualizacao-v53-consolidada-preflight.sql`** (Somente leitura):
   - Executar antes de qualquer alteração.
   - Confere a existência das tabelas base, do schema `maic6824_Clyonutri2` e das migrações já registradas.

2. **`atualizacao-v53-consolidada-completa.sql`**:
   - Cria de forma idempotente todas as 17 novas tabelas (`booking_calendar_*`, `booking_waitlist_*`, `booking_self_service_*`, `rx_*`).
   - Adiciona com segurança todas as 23 colunas da Anamnese Estruturada (NI2-223) na tabela `anamneses`.
   - Cria os índices de alta performance para busca FIFO e integridade multi-tenant.
   - Registra as migrações 69, 70, 71, 72 e 73 na tabela `migrations`.

3. **`atualizacao-v53-consolidada-verificacao.sql`** (Somente leitura):
   - Executar após o script completo.
   - Valida que as 17 tabelas, 23 colunas da anamnese, 7 índices e as 5 migrações estão 100% ativas.

> **Nota:** Se a sua base já estava na v52 com migrações até 72, a execução do script completo é 100% segura (usa `IF NOT EXISTS` e `_clyo_add_col_if_missing`).

---

## 2. Passo a Passo de Instalação no HostGator

1. **Backup Preventivo:**
   - Gere um backup completo do banco MySQL no phpMyAdmin.
   - Faça uma cópia do arquivo `.env` e da pasta `storage/` atual.

2. **Banco de Dados (phpMyAdmin):**
   - Selecione o banco da aplicação (`maic6824_Clyonutri2`).
   - Execute o script `database/manual/atualizacao-v53-consolidada-preflight.sql`.
   - Em seguida, execute `database/manual/atualizacao-v53-consolidada-completa.sql`.
   - Finalize com `database/manual/atualizacao-v53-consolidada-verificacao.sql`.

3. **Deploy dos Arquivos:**
   - Envie o conteúdo do pacote descompactado para a raiz da instalação no servidor (geralmente `public_html` ou o subdiretório configurado).
   - **NÃO** sobrescreva seu arquivo `.env` de produção nem apague os arquivos da pasta `storage/uploads/`.
   - Assegure que as permissões estejam em `755` para diretórios e `644` para arquivos.

4. **Verificação Operacional:**
   - Faça login no painel profissional e acerte o perfil.
   - Teste a Agenda (visão mensal, diária e novos horários com feedback do motor de jornada).
   - Teste o perfil do paciente: barra de ferramentas retrátil com persistência (`data-profile-tools-preference`), novas abas de prescrições e exames.
   - Teste o editor de anamnese estruturada (12 blocos com navegação por âncoras e campos condicionais).
   - Teste a prescrição dietética e a calculadora de energia (alertas clínicos de populações especiais).

---

## 3. Rollback
Em caso de necessidade de reversão, restaure os arquivos da versão v50/v52 anterior mantendo o arquivo `.env` e as pastas de dados. As novas colunas e tabelas criadas no banco são não-destrutivas (nullable / defaults seguros) e não interferem em versões legadas.
