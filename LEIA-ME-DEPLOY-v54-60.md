# ClyoNutri II — Guia de Implementação e Deploy v54.60

**Versão:** v54.60 (Candidata com Transcrição Nutricional em Tempo Real — NI2-820)  
**Data:** 12/11/2026  
**Base Canônica:** `/Users/moliveira/Nutri_II`  
**Build Identity:** `v54.60-bd976f6aa1821029` (Integridade: `consistent`)  
**Status de Homologação:** Validado localmente com 100% de aprovação (30/30 checks E2E clínicos). Ticket Jira [NI2-820] mantido em `A fazer` aguardando validação independente.

---

## 1. Conteúdo da Versão v54.60

### A. Transcrição Nutricional em Tempo Real (NI2-820)
- **Modos Ao Vivo e Discreto:** Alternância em tempo real entre visualização do streaming de texto e ocultamento visual completo para preservação da privacidade do paciente perante acompanhantes, mantendo a ingestão de áudio ativa em segundo plano com indicador sutil.
- **Consentimento LGPD Prévio Obrigatório:** Bloqueio estrito de qualquer tentativa de gravação ou transcrição sem consentimento prévio afirmativo do paciente. Possibilidade de revogação imediata a qualquer momento.
- **Ingestão Incremental com Idempotência:** Segmentação sequencial numerada com detecção e descarte de duplicatas em reconexões.
- **Resiliência e Recuperação pós-Refresh:** Recuperação imediata de todos os segmentos confirmados ao recarregar a tela do atendimento.
- **Pausa Resiliente:** Ação de pausa bloqueia sumariamente a gravação e a ingestão de novos segmentos no backend.
- **Minuta Estruturada (8 Tópicos Clínicos) & Zero Alucinações:** Extração fidedigna a partir do diálogo clínico:
  1. Queixa Principal
  2. Objetivos Nutricionais
  3. Rotina Alimentar
  4. Medidas Antropométricas Informadas
  5. Sintomas Clínicos
  6. Notas de Adesão e Dificuldades
  7. Condutas e Metas Combinadas
  8. Previsão de Retorno
  *Regra clínica inviolável:* O sistema não inventa medicamentos, dietas automáticas, exames laboratoriais ou condutas não expressas pelo nutricionista.
- **Aprovação Humana Obrigatória:** Nenhuma informação transcrita entra no prontuário do paciente sem revisão, eventual edição e clique de aprovação explícito pelo nutricionista.
- **Isolamento Multi-Tenant (Anti-IDOR):** Garantia de segregação total entre clínicas distintas.
- **Auditoria Segura:** Registro de todas as ações de ciclo de vida da sessão e consentimento sem vazamento de dados de saúde ou áudio em metadados de logs.

---

## 2. Atualização de Banco de Dados (MySQL 5.7+ / 8.0+)

### Opção A — Execução via CLI (Recomendada):
```bash
php bin/migrate.php
```

### Opção B — Execução Manual SQL (phpMyAdmin ou terminal MySQL):
Execute o arquivo: `database/manual/atualizacao-v54-60-ni2-820-realtime-transcription.sql`

```sql
-- 1. Consentimentos de IA / LGPD
CREATE TABLE IF NOT EXISTS consultation_ai_consents (
    id INT AUTO_INCREMENT PRIMARY KEY,
    clinic_id INT NOT NULL,
    appointment_id INT NOT NULL,
    patient_id INT NOT NULL,
    user_id INT NOT NULL,
    status VARCHAR(20) NOT NULL DEFAULT 'granted',
    terms_version VARCHAR(50) NOT NULL DEFAULT 'v1.0',
    consented_at_utc DATETIME NOT NULL,
    revoked_at_utc DATETIME NULL,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_ai_consent_clinic_appt (clinic_id, appointment_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- 2. Sessões de Transcrição
CREATE TABLE IF NOT EXISTS consultation_transcription_sessions (
    id INT AUTO_INCREMENT PRIMARY KEY,
    session_uuid VARCHAR(64) NOT NULL UNIQUE,
    clinic_id INT NOT NULL,
    appointment_id INT NOT NULL,
    patient_id INT NOT NULL,
    professional_id INT NOT NULL,
    mode VARCHAR(20) NOT NULL DEFAULT 'live',
    status VARCHAR(30) NOT NULL DEFAULT 'connected',
    started_at_utc DATETIME NOT NULL,
    paused_at_utc DATETIME NULL,
    resumed_at_utc DATETIME NULL,
    completed_at_utc DATETIME NULL,
    duration_seconds INT NOT NULL DEFAULT 0,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_transcription_sess_clinic (clinic_id, appointment_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- 3. Segmentos Incrementais
CREATE TABLE IF NOT EXISTS consultation_transcription_segments (
    id INT AUTO_INCREMENT PRIMARY KEY,
    session_id INT NOT NULL,
    clinic_id INT NOT NULL,
    sequence_number INT NOT NULL,
    speaker VARCHAR(50) NOT NULL DEFAULT 'speaker_uncertain',
    text TEXT NOT NULL,
    is_final TINYINT(1) NOT NULL DEFAULT 1,
    confidence DECIMAL(5,4) NULL,
    start_seconds DECIMAL(8,2) NULL,
    end_seconds DECIMAL(8,2) NULL,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_transcription_seg_seq (session_id, sequence_number),
    INDEX idx_transcription_seg_clinic (clinic_id, session_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- 4. Minutas de Evolução
CREATE TABLE IF NOT EXISTS consultation_evolution_drafts (
    id INT AUTO_INCREMENT PRIMARY KEY,
    session_id INT NOT NULL,
    clinic_id INT NOT NULL,
    appointment_id INT NOT NULL,
    patient_id INT NOT NULL,
    professional_id INT NOT NULL,
    chief_complaint TEXT NULL,
    nutritional_goals TEXT NULL,
    dietary_routine TEXT NULL,
    reported_anthropometry TEXT NULL,
    clinical_symptoms TEXT NULL,
    adherence_notes TEXT NULL,
    agreed_conduct TEXT NULL,
    next_appointment_notes TEXT NULL,
    full_evolution_text MEDIUMTEXT NOT NULL,
    approval_status VARCHAR(20) NOT NULL DEFAULT 'draft',
    approved_by INT NULL,
    approved_at_utc DATETIME NULL,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_evolution_drafts_clinic_appt (clinic_id, appointment_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
```

*(Nota: Se a base de produção for anterior à v54.52, aplicar antes o script `database/manual/atualizacao-v54-52-ni2-767-production-events.sql`).*

---

## 3. Verificações Automatizadas Executadas

1. **E2E Transcrição em Tempo Real (NI2-820):**
   ```bash
   php tests/realtime_transcription_e2e_test.php
   ```
   *Resultado:* **30 checks PASS, 0 FAIL** (100% dos requisitos cobertos).

2. **Segurança OWASP ZAP (NI2-806 a NI2-810):**
   ```bash
   php tests/ni2_806_810_p1_remediation_test.php
   ```
   *Resultado:* **19 checks PASS, 0 FAIL**.

3. **Rollback Isolado de HSTS (NI2-811):**
   ```bash
   php tests/hsts_end_to_end_rollback_test.php
   ```
   *Resultado:* **15 checks PASS, 0 FAIL**.

4. **Integridade de Build (BuildIdentity):**
   ```bash
   php bin/build_identity.php --verify
   ```
   *Resultado:* `integrity: consistent`, build `v54.60-bd976f6aa1821029`.

---

## 4. Instruções para Subir em Homologação / Produção

1. Copie o pacote `clyonutri-v54-60-deploy.zip` para o servidor.
2. Descompacte na pasta da release (fora da `public_html`).
3. Execute o script de migration ou importe o SQL manual acima.
4. Ajuste as permissões de `storage/` para escrita (`775`).
5. Atualize o link simbólico `current` para a nova versão.
6. Realize o teste de fumaça na sala de atendimento (`/consulta/{id}`).
