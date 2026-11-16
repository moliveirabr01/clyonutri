# ClyoNutri v54.63 · deploy na HostGator

Pacote: `clyonutri-v54-63-hostgator.zip` (o conteúdo fica na raiz do zip) · Data: 15/11/2026 · Branch: `feat/reteste-ux-v54-63` (commit `7b41a53`) · Produção hoje: v54.60.

Leve junto o `.sha256` e confira o hash antes de subir.

## O que entra

Esta versão leva para produção a v54.61, a v54.62 e a v54.63 de uma vez:
- **v54.61:** consentimento do paciente para a transcrição (NI2-830).
- **v54.62:** simplificação das jornadas do nutricionista (NI2-844).
- **v54.63:** correções do reteste UX:
  - quantidade com vírgula no plano;
  - catálogo sem duplicata do frango;
  - meta de energia com ajuste coerente com o objetivo;
  - painel de fechamento;
  - botões de 44 px;
  - textos.

Detalhes em `RELEASE_INFO.json`.

**Base do pacote:** o último build (`release/clyonutri-v54-60-deploy.zip`) foi comparado arquivo a arquivo com o git. Todas as diferenças estão no git, e o código atual contém tudo o que o v54.60 tinha. Não há correção feita só no build a preservar (`source_is_committed_release: true`).

**Não vão no zip:** `.env`, `.git`, `tests/`, `docs/`, `mobile/`, `scripts/`, `database/scripts/`, `artisan`, bancos locais, logs e uploads. O `vendor/` vai pronto (o projeto não tem dependências de desenvolvimento). Arquivos com permissão 0644 e pastas com 0755.

## Ordem

### 1. Backup
1. No cPanel, em **Backup**, baixe o backup do banco `maic6824_Clyonutri2`. Pode ser também pelo phpMyAdmin, em Exportar, formato SQL, com estrutura e dados.
2. No Gerenciador de Arquivos, compacte a pasta `/home3/maic6824/clyonutri.com` (exceto `storage/uploads`, se estiver grande) e baixe o zip.
3. Guarde o `.env` de produção à parte.
4. Pause a tarefa do cron do `bin/cron_scheduler.php` no cPanel.

### 2. Manutenção
Em `/admin/configuracoes`, ponha **Modo de manutenção da plataforma** em **Ativo**. O `/admin` continua acessível.

### 3. Preflight (só leitura)
No phpMyAdmin, com o banco `maic6824_Clyonutri2` selecionado, abra a aba **Importar** e escolha `database/manual/atualizacao-v54-63-preflight.sql`. O conjunto de caracteres do arquivo deve ser **utf-8**, que é o padrão.

Confira o resultado:
- **P1:** MySQL 5.7.x.
- **P3:** `consultation_ai_consent_requests = 0`. As outras tabelas devem ser 1.
- **P4:** 4 linhas. **Se faltar alguma, pare.**
- **P5:** anote os números.
- **P6:** `0` e `0`. **Se vier diferente de zero, pare e avise:** a v54.62 teria rodado neste banco.

### 4. Atualização do banco
Importe `database/manual/atualizacao-v54-63-completa.sql` da mesma forma.

O script:
- registra a 089 e a 092, que já estavam aplicadas mas não registradas;
- cria a tabela da 093;
- grava as medidas caseiras estimadas da 094 (cerca de 1.630 linhas, todas marcadas como "Estimativa ClyoNutri (revisar)");
- registra a 095.

Ele é idempotente: se a importação cair no meio, importe de novo.

Depois importe `database/manual/atualizacao-v54-63-verificacao.sql` e confira o esperado:
- **V1:** 5 linhas.
- **V2:** 7.
- **V3:** 3.
- **V4:** `medidas_estimadas` perto de 1.630 e `medidas_total` igual ao P5 + 1.630.
- **V5:** pão francês com "unidade 50", mamão com "fatia 100", "Peito de frango grelhado" com "filé 100".
- **V6:** 0.

O banco novo é compatível com o código antigo: as mudanças só acrescentam. Se algo falhar a partir daqui, o código v54.60 continua funcionando.

### 5. Arquivos
1. No Gerenciador de Arquivos, na pasta `/home3/maic6824/clyonutri.com`, envie o `clyonutri-v54-63-hostgator.zip`.
2. Extraia sobrescrevendo.
   - O `.env` não é tocado.
   - Em `storage/`, o pacote só traz as pastas vazias e os `.htaccess`. Uploads, logs e sessões de produção ficam como estão.
3. Apague o `.zip` do servidor.
4. Confira as permissões: arquivos 644, pastas 755 e `storage/` com escrita para o PHP, como já estava.

### 6. Cache
1. Se o cPanel oferecer, limpe o OPcache. Se não, espere 1 a 2 minutos.
2. No navegador, recarregue com Ctrl+Shift+R. Os CSS e JS alterados já têm `?v=v54-63`.

### 7. Conferência
1. Desligue a manutenção em `/admin/configuracoes` e reative o cron.
2. O rodapé do menu deve mostrar **v54.63**. `/up` deve responder `{"status":"ok"}`.
3. Teste com a sua conta, usando um paciente de teste com a marca TESTE:
   - abrir a agenda e um plano alimentar;
   - buscar "frango grelhado": o 1º resultado deve ser "Peito de frango grelhado";
   - num plano em rascunho, incluir "banana" com quantidade **1,5**: a mensagem deve dizer "1,5 unidade (≈ 150 g)";
   - apagar esse item de teste;
   - abrir uma consulta e conferir o painel "Fechamento da consulta";
   - na sala de atendimento, conferir que o botão "Pedir aceite ao paciente" da transcrição gera o pedido (é a tabela da 093).
4. Olhe `storage/logs/` em busca de erros novos.

## Reversão

- **Código:** restaure o zip de arquivos do passo 1 (ou extraia de novo o `release/clyonutri-v54-60-deploy.zip`, sem o `.env`) e limpe o OPcache.
- **Banco:** não precisa voltar. As mudanças só acrescentam e o código v54.60 as ignora.
  - Se quiser tirar as medidas estimadas: `DELETE FROM food_household_measures WHERE source = 'Estimativa ClyoNutri (revisar)';`. Itens de plano já gravados guardam o próprio peso e não mudam.
  - Não apague `consultation_ai_consent_requests` se algum paciente já tiver dado aceite pela v54.63.
- Só restaure o backup do banco em caso extremo: tudo o que foi gravado depois do deploy se perde.

## Limites desta entrega

- **SQL:** validado em MariaDB 10.11 local, sobre uma cópia da estrutura de produção (dump de 15/11/2026, só estrutura e catálogo, sem dados de pacientes), duas vezes seguidas, sem erro. Não foi executado em MySQL 5.7 real.
- **Medidas caseiras:** os pesos são estimativas por regra de nome e precisam de **revisão de nutricionista**. A tela mostra "estimativa da base geral, confira o peso".
- **Diferenças antigas, que não vêm desta versão nem são corrigidas aqui:**
  - colunas `updated_at` ausentes em `patient_documents` e `patient_self_metrics` (o código não usa);
  - alguns índices de desempenho ausentes (`asaas_checkouts`, `asaas_payment_receipts`, `foods.clinic_id`, `patient_documents`).
- **Testes:** feitos em SQLite, MariaDB e Chromium headless (ver `RELEASE_INFO.json`). Não foram testados Safari, Edge, Chrome em Windows/macOS reais nem a infraestrutura HostGator.
- **E-mail:** nada muda no envio. A pendência da NI2-463 (SPF/DKIM/DMARC) continua.
