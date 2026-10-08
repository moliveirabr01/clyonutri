# ClyoNutri II — Runbook de Release Gate, Homologação (UAT) e Rollback

**Documento:** `docs/delivery/RELEASE_GATE_AND_ROLLBACK_RUNBOOK.md`  
**Referência da Issue:** NI2-171 ([P0] Implementar release gate formal com UAT e rollback) / Épico NI2-146  
**Versão:** 1.0.0  
**Data:** 08/10/2026  
**Status:** Aprovado e Homologado  

---

## 1. Visão Geral

Este documento define os critérios objetivos, o checklist de aprovação obrigatória (Quality Gate) e o procedimento de contingência e reversão (Rollback) para a promoção de novas versões do ClyoNutri II para os ambientes de Staging e Produção na HostGator.

---

## 2. Checklist Formal do Release Gate (Critérios Bloqueantes)

Nenhuma versão pode ser promovida para produção caso qualquer um dos itens abaixo esteja pendente ou com falha:

| # | Domínio de Qualidade | Critério Obrigatório de Aprovação | Verificador Automatizado / Evidência | Status Gate |
|---|---|---|---|:---:|
| 1 | **Observabilidade & Saúde** | Health check do runtime `/up` 100% OK (PHP, PDO, OPcache, Storage e Heartbeat) | `bin/healthcheck.php` | **APROVADO** |
| 2 | **Testes Automatizados** | 100% de sucesso em toda a bateria oficial (zero falhas, zero asserts ignorados) | `bin/build_release.sh` (Suíte Completa) | **APROVADO** |
| 3 | **Segurança Multi-Tenant** | Bloqueio preventivo comprovado contra IDOR em 12 serviços críticos | `tests/cross_tenant_offensive_test.php` | **APROVADO** |
| 4 | **Segurança Clínica** | Prontuário append-only, integridade de hash, cálculo 100g e bloqueio de alergênicos | `tests/clinical_safety_and_precision_test.php` | **APROVADO** |
| 5 | **Privacidade & LGPD** | Termos versionados, legal hold de 20 anos de prontuário e storage privado anti-executáveis | `tests/privacy_and_lgpd_governance_test.php` | **APROVADO** |
| 6 | **Contratos de API** | Validação sintática e estrutural OpenAPI 3.1 dos endpoints `/api/v1` | `ApiContractValidator` | **APROVADO** |
| 7 | **Jornadas E2E** | Jornada completa do nutricionista e do paciente aprovadas ponta a ponta | `tests/delivery_e2e_and_contracts_test.php` | **APROVADO** |
| 8 | **Desempenho & Carga** | Tempo de resposta p95 $< 200$ms e consumo de memória $< 32$MB por requisição | Performance Gate Automatizado | **APROVADO** |
| 9 | **Design System & A11y** | Contraste WCAG 2.1 AA (Navy `#0F2747` / Copper `#E67A3F`) e alvos de toque $\ge 44$px | A11y Gate Automatizado | **APROVADO** |
| 10 | **Pacote de Release** | Pacote `.tar.gz` assinado com SHA-256 e manifesto `RELEASE_INFO.json` atualizado | `release/RELEASE_INFO.json` | **APROVADO** |

---

## 3. Decisão Formal GO / NO-GO

- **Regra:** A decisão **GO** exige unanimidade de aprovação técnica nos 10 gates acima.
- **Bloqueio:** Qualquer falha de segurança, cálculo incorreto de nutrição ou violação de contrato de API dispara status **NO-GO** imediato, interrompendo a esteira de implantação.

---

## 4. Procedimento Operacional de Rollback

Em caso de anomalia crítica pós-deploy em produção:

```bash
# 1. Identificar versão anterior íntegra no manifesto
cat release/RELEASE_INFO.json

# 2. Restaurar backup do banco de dados imediatamente anterior à release
mysql -u nutri_user -p nutri_db < /backup/nutri_db_pre_deploy.sql

# 3. Descompactar o pacote estável anterior sobre o diretório da aplicação
tar -xzf release/clyonutri_vANTERIOR.tar.gz -C /cpanel/public_html/

# 4. Limpar cache de OPcache e views Blade
php bin/healthcheck.php

# 5. Executar verificação de integridade do prontuário
php -r "require 'vendor/autoload.php'; (new ClyoNutri\Domain\Clinical\ClinicalRecordService())->verifyIntegrity(1);"
```

- **Tempo Máximo de Recuperação Alvo (RTO):** $\le 15$ minutos.
- **Ponto Máximo de Recuperação Alvo (RPO):** $\le 1$ hora (graças aos backups programados).
