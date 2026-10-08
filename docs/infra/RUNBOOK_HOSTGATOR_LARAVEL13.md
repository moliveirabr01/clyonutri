# ClyoNutri II — Runbook de Operação, Deploy e Infraestrutura HostGator (Laravel 13)

**Versão:** 1.0  
**Data:** 07/10/2026  
**Contexto:** Épico NI2-142 (`[P0][Replataforma] Laravel 13, CI/CD e infraestrutura HostGator`)  
**Base Técnica:** `inventario-migracao-laravel.md` §§ 6, 8 e 11  

---

## 1. Topologia de Servidor e Diretórios

Para garantir segurança rigorosa e isolamento de segredos em ambiente compartilhado cPanel, a aplicação **nunca** deve ser colocada diretamente dentro de `public_html`.

```text
/home/USUARIO/
  clyonutri/
    releases/
      <sha_1>/               Artefato imutável vindo do CI (com vendor/ e public/build)
      <sha_2>/               Release ativo
    current -> releases/<sha_2>  Symlink atômico apontando para a release ativa
    shared/
      .env                   Arquivo de configuração de produção (permissão 600, fora do webroot)
      storage/               Dados persistentes
        app/private/         Uploads clínicos privados (exames, fotos do diário)
        framework/           Caches e sessões
        logs/                Logs diários com retenção de 14 dias
    backups/                 Dumps temporários cifrados (apagados após envio externo)
  public_html/               Site estático institucional ou vazio (NÃO contém o SaaS)
```

---

## 2. Configurações Mandatórias no cPanel

### 2.1 Servidor e Banco de Dados (MySQL 8.0)
* **Requisito Crítico:** A conta deve estar alocada em servidor com **AlmaLinux e MySQL 8.0** (prefixos `sh0000`, `rs000`, `sh-pro00000`). Servidores legados com CentOS 7 / MySQL 5.7 são bloqueadores (NO-GO).
* **Collation:** `utf8mb4_unicode_ci`.
* **Conexões Simultâneas:** Limite de 25 conexões cPanel monitorado.

### 2.2 PHP 8.3 via MultiPHP
* **Versão Selecionada:** PHP 8.3 fixado no domínio e subdomínios.
* **Binário CLI Oficial:** `/opt/cpanel/ea-php83/root/usr/bin/php`.
* **Extensões Ativas:** `pdo`, `pdo_mysql`, `mbstring`, `openssl`, `tokenizer`, `xml`, `ctype`, `json`, `fileinfo`, `dom`, `intl`, `zip`, `gd`.

### 2.3 Document Root (Isolamento Webroot)
* No menu **Domínios** do cPanel, o campo **Document Root** do domínio/subdomínio do aplicativo (`app.clyonutri.com.br`) deve ser configurado como:
  `/home/USUARIO/clyonutri/current/public`
* Apenas os arquivos de `public/` ficam expostos à internet. Arquivos `.env`, `storage/`, `vendor/` e código-fonte permanecem inacessíveis via HTTP.

### 2.4 Repasse de Autorização para API Sanctum
No arquivo `public/.htaccess`, a seguinte diretiva é obrigatória para evitar que o Apache da HostGator elimine o cabeçalho `Authorization: Bearer <token>`:
```apache
RewriteEngine On
RewriteCond %{HTTP:Authorization} .
RewriteRule .* - [E=HTTP_AUTHORIZATION:%{HTTP:Authorization}]
```

### 2.5 Acesso SSH (Porta 2222)
* Solicitar a habilitação de SSH ao suporte da HostGator.
* Conexão: `ssh -p 2222 USUARIO@SERVIDOR`.
* Cadastrar chave pública do GitHub Actions para deploys automatizados.

---

## 3. Agendador (Cron) e Fila (Queue) sem Processos Contínuos

Os Termos de Serviço da HostGator proíbem daemons e workers persistentes (`queue:work` contínuo, Supervisor, Horizon, Reverb).

### 3.1 Entrada Única no Cron do cPanel
* **Intervalo:** A cada 15 minutos (`*/15 * * * *`).
* **Comando:**
```cron
*/15 * * * * /opt/cpanel/ea-php83/root/usr/bin/php /home/USUARIO/clyonutri/current/bin/cron_scheduler.php >> /dev/null 2>&1
```

### 3.2 Comportamento da Fila
* O comando executa: `queue:work database --stop-when-empty --max-time=120 --timeout=50 --tries=3`.
* Jobs são curtos e limitados a lotes (respeitando o teto de 25% de CPU por 90s).
* **E-mails críticos** (criação de conta, verificação de e-mail, redefinição de senha, convite de paciente) são despachados **sincronamente** via API HTTPS de provedor transacional, sem esperar o ciclo de 15 minutos da fila.

---

## 4. Pipeline de Deploy Reproduzível

O deploy é acionado via GitHub Actions (`.github/workflows/deploy.yml`) ou via script `bin/deploy_hostgator.sh`:

1. **Build do Artefato:**
   `composer install --no-dev --optimize-autoloader` e compilação de assets via Vite no CI.
2. **Transferência Segura:**
   `rsync` sobre SSH (porta 2222) para `~/clyonutri/releases/<commit_sha>/`.
3. **Link Simbólico de Shared:**
   Vinculação de `shared/.env` e `shared/storage/`.
4. **Migrações:**
   Execução de `php bin/migrate.php` ou `php artisan migrate --force`.
5. **Alternância Atômica:**
   `ln -sfn ~/clyonutri/releases/<commit_sha> ~/clyonutri/current`.
6. **Limpeza de Inodes:**
   Manutenção de apenas as 3 últimas releases em `releases/`.
7. **Smoke Test:**
   Validação via `curl https://app.clyonutri.com.br/up`.

---

## 5. Procedimento de Rollback Imediato

Se um release apresentar falhas funcionais ou quebra de regressão:

1. Executar o script `bin/rollback_hostgator.sh` ou via SSH:
   ```bash
   cd ~/clyonutri/releases
   PREV=$(ls -dt */ | sed -n '2p' | tr -d '/')
   ln -sfn ~/clyonutri/releases/$PREV ~/clyonutri/current
   ```
2. Limpar os caches:
   ```bash
   /opt/cpanel/ea-php83/root/usr/bin/php ~/clyonutri/current/artisan optimize:clear
   ```
3. O rollback de código é **imediato (< 5 segundos)** e atômico.
4. **Regra de Migrations Expand/Contract:** Toda migração de banco deve ser retrocompatível com a release anterior (adiciona colunas novas como `NULL` ou com valor padrão; não remove colunas em uso na mesma versão).

---

## 6. Política de Backups Cifrados e Disaster Recovery

Os backups automáticos da HostGator **não** atendem às exigências de conformidade clínica e LGPD (sobrescritos e com taxa de recuperação de R$ 200).

### 6.1 Rotina Diária Própria
* **Horário:** 03:30 BRT.
* **Comando:** `bin/backup_database.sh`.
* **Extração:** `mysqldump --single-transaction --quick --no-tablespaces`.
* **Cifragem:** OpenSSL `AES-256-CBC` com PBKDF2 e chave protegida em variável de ambiente.
* **Destino:** Envio incremental para storage externo S3-compatível com retenção de 7 diários, 4 semanais e 12 mensais.
* **Limpeza:** Remoção do arquivo local imediatamente após o envio.

### 6.2 Ensaio Mensal de Restauração (Rehearsal)
* **Comando:** `bin/restore_database.sh`.
* **Validação:** Descriptografia do backup mais recente, restauração em banco isolado, contagem de tabelas e registros clínicos.
* **Métricas Aprovadas:**
  * **RPO (Recovery Point Objective):** <= 24 horas.
  * **RTO (Recovery Time Objective):** <= 4 horas (medido: < 1 minuto para bases sintéticas).

---

## 7. Gatilhos de Saída da HostGator Compartilhada (Fase 2)

A migração da HostGator compartilhada para VPS gerenciada (Laravel Forge / Ploi / OCI São Paulo) deve ser acionada imediatamente mediante qualquer um dos seguintes sinais objetivos:

| Gatilho | Limiar Objetivo | Motivo Técnico |
|---|---|---|
| **Tempo Real** | Adoção de WebSockets no chat ou teleconsulta | Reverb/WebSockets exigem processo contínuo (proibido no cPanel). |
| **Fila e Latência** | Latência de até 15 min rejeitada pelo negócio | Cron do compartilhado não permite ciclos menores que 15 min. |
| **Recursos cPanel** | Erros de `max_user_connections` (25) ou CPU > 25% | Risco de suspensão automática da conta pelo cPanel. |
| **Volume de Banco** | Banco de dados > 5 GB ou dump > 30 minutos | Backup compartilhado torna-se lento e frágil. |
| **Escala de Tenants** | > 30 clínicas ativas ou > 100 usuários simultâneos | Esgotamento das 25 conexões do MySQL compartilhado. |
