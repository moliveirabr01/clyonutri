# Clyo Nutri v54.52 — Central de Eventos de Produção, Hardening de Segurança & Governança Clínica

Esta versão consolida os épicos de infraestrutura operacional, segurança AppSec 360° e funcionalidades clínicas aprovadas para o ecossistema Clyo (ClyoNutri / ClyoPsi).

---

## 1. Principais Entregas Desta Versão

1. **Central de Eventos de Produção no Backoffice (NI2-767 / OPS EVENTS 01 a 12):**
   - Rastreamento unificado de observabilidade operacional com 9 categorias canônicas (`AUTH`, `SECURITY`, `CLINICAL_WORKFLOW`, `SCHEDULING`, `DOCUMENT`, `BILLING`, `NOTIFICATION`, `SYSTEM`, `ADMIN`).
   - Política estrita Anti-PHI e LGPD: remoção automática e recursiva de senhas, tokens JWT, CPFs e dados clínicos sensíveis de prontuários em metadados de eventos técnicos.
   - Fail-safe atômico não-bloqueante: a emissão de eventos em falhas de banco nunca derruba transações clínicas ou agendamentos de usuários.
   - Painel executivo com métricas em tempo real (volume 24h, taxa de erros %, total de falhas e distribuição de severidades).
   - Timeline investigativa com reconstrução causal completa de requisições correlacionadas via `correlation_id`.
   - Motor de alertas operacionais (`HIGH_ERROR_RATE`, `BILLING_WEBHOOK_FAILURE`) com janela de cooldown (2h) para evitar fadiga de alertas.
   - Integração com Service Desk (`HelpdeskTicketService`) para abertura imediata de chamados P1/P2/P3 vinculados a eventos.
   - Purga automatizada de retenção para eventos anteriores a 90 dias conforme diretrizes da LGPD.

2. **Hardening de Segurança e Conformidade AppSec (NI2-794 & NI2-802):**
   - Tratamento das diretrizes ZAP Baseline e Active Scan.
   - Cabeçalhos de segurança estritos: Content Security Policy (CSP), CORS com allowlist restritiva, proteção anti-clickjacking (`X-Frame-Options: SAMEORIGIN`) e bloqueio de framing em rotas autenticadas.
   - Sanitização de exposição de erros técnicos (`ErrorExposure`) em ambientes produtivos.

3. **Governança Clínica e Sugestões Dietéticas (NI2-703):**
   - Sugestões dietéticas adaptativas baseadas em comorbidades documentadas (diabetes, gastrite, hipertensão, celíaca, renal).
   - Princípio de 4-olhos: bloqueio de auto-aprovação de protocolos clínicos e validação obrigatória de CRN ativo do nutricionista responsável.

4. **Sizing e Resiliência Operacional (NI2-754):**
   - Envelope de operação e concorrência estabelecido para hospedagem compartilhada HostGator (capacidade nominal segura de até 30 usuários simultâneos).
   - Otimização de queries com índices compostos de alta seletividade.

---

## 2. Atualização de Banco de Dados (MySQL 5.7+ / 8.0+)

A migração `2026_11_07_000091_create_production_events_table.php` cria as tabelas de observabilidade com compatibilidade total para MySQL 5.7, 8.0 e SQLite.

### Execução via Linha de Comando (Recomendada):
```bash
php bin/migrate.php
```

### Execução Manual no phpMyAdmin:
```sql
CREATE TABLE IF NOT EXISTS production_events (
    id INT AUTO_INCREMENT PRIMARY KEY,
    event_id VARCHAR(36) NOT NULL UNIQUE,
    schema_version VARCHAR(10) NOT NULL DEFAULT 'v1',
    occurred_at DATETIME NOT NULL,
    received_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    environment VARCHAR(20) NOT NULL DEFAULT 'production',
    service VARCHAR(50) NOT NULL DEFAULT 'clyo_core',
    category VARCHAR(30) NOT NULL,
    event_type VARCHAR(80) NOT NULL,
    severity VARCHAR(20) NOT NULL DEFAULT 'info',
    outcome VARCHAR(20) NOT NULL DEFAULT 'success',
    clinic_id INTEGER NULL,
    actor_type VARCHAR(30) NOT NULL DEFAULT 'system',
    actor_id VARCHAR(64) NULL,
    resource_type VARCHAR(50) NULL,
    resource_ref VARCHAR(64) NULL,
    correlation_id VARCHAR(64) NULL,
    request_id VARCHAR(64) NULL,
    duration_ms INTEGER NULL,
    retry_count INTEGER NOT NULL DEFAULT 0,
    metadata TEXT NULL,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE INDEX idx_prod_events_occurred_cat ON production_events (occurred_at, category);
CREATE INDEX idx_prod_events_cat_sev ON production_events (category, severity);
CREATE INDEX idx_prod_events_clinic_date ON production_events (clinic_id, occurred_at);
CREATE INDEX idx_prod_events_correlation ON production_events (correlation_id);
CREATE INDEX idx_prod_events_type ON production_events (event_type);

CREATE TABLE IF NOT EXISTS production_alerts (
    id INT AUTO_INCREMENT PRIMARY KEY,
    alert_id VARCHAR(36) NOT NULL UNIQUE,
    rule_name VARCHAR(80) NOT NULL,
    severity VARCHAR(20) NOT NULL DEFAULT 'warning',
    status VARCHAR(20) NOT NULL DEFAULT 'firing',
    description TEXT NOT NULL,
    context_json TEXT NULL,
    triggered_at DATETIME NOT NULL,
    resolved_at DATETIME NULL,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE INDEX idx_prod_alerts_status ON production_alerts (status, severity);
CREATE INDEX idx_prod_alerts_rule ON production_alerts (rule_name, status);

INSERT INTO migrations (migration) 
SELECT '2026_11_07_000091_create_production_events_table' 
FROM DUAL WHERE NOT EXISTS (
    SELECT 1 FROM migrations WHERE migration = '2026_11_07_000091_create_production_events_table'
);
```

---

## 3. Procedimento de Deploy (HostGator)

1. Envie o pacote `clyonutri-v54-52-deploy.zip` para a raiz da instalação na HostGator.
2. Descompacte o pacote substituindo os arquivos do sistema:
   ```bash
   unzip -o clyonutri-v54-52-deploy.zip
   ```
   *(Nota: O arquivo `.env` de produção NUNCA é sobrescrito pelo pacote).*
3. Execute o script de migração:
   ```bash
   php bin/migrate.php
   ```
4. Verifique a integridade do runtime e saúde da plataforma:
   ```bash
   php bin/build_identity.php --verify
   php bin/healthcheck.php
   ```
5. Acesse o Backoffice (`/admin/eventos`) com usuário `superadmin` ou `admin_operacional` para validar a Central de Eventos de Produção.
