# ClyoNutri II — Runbook de Resposta a Incidentes, Retenção e Restauração (LGPD)

**Documento:** `docs/privacy/RUNBOOK_RESPOSTA_INCIDENTES_E_RETENCAO_LGPD.md`  
**Referência da Issue:** NI2-166 ([P0] Criar runbook de incidentes, retenção e restauração) / Épico NI2-145  
**Versão:** 1.0.0  
**Data:** 07/10/2026  
**Status:** Aprovado e Homologado  

---

## 1. Objetivo e Escopo

Este runbook estabelece o procedimento operacional padrão para a **Equipe de Resposta a Incidentes de Segurança (CSIRT / Privacidade)** do ClyoNutri II em caso de incidentes de segurança com dados pessoais (vazamentos, acessos indevidos, adulteração de prontuários ou indisponibilidade de serviços clínicos), em estrita conformidade com o Art. 48 da Lei Geral de Proteção de Dados (LGPD) e a Resolução CD/ANPD nº 15/2024.

---

## 2. Matriz de Classificação de Severidade de Incidentes

| Nível de Severidade | Descrição | Exemplo de Cenário | Tempo de Resposta Inicial |
|---|---|---|---|
| **CRÍTICO (P0)** | Violação ou comprometimento de dados sensíveis de saúde em larga escala; adulteração comprovada de prontuário clínico. | Quebra de barreira multi-tenant com vazamento de prontuários; falha na cadeia criptográfica de integridade. | $\le 1$ hora |
| **ALTO (P1)** | Tentativa repetida de exploração cross-tenant (IDOR); exposição de documentos clínicos de um paciente específico. | Anexo clínico acessado indevidamente por credencial comprometida. | $\le 4$ horas |
| **MÉDIO (P2)** | Exposição de dados cadastrais não sensíveis sem histórico clínico; falha transitória de backup offsite. | Erro de log registrando e-mail de usuário em terminal de debug. | $\le 12$ horas |
| **BAIXO (P3)** | Tentativa maliciosa bloqueada preventivamente pela barreira do `TenantGuard`. | Ataque IDOR capturado e registrado com sucesso em `audit_logs` sem vazamento. | $\le 24$ horas |

---

## 3. Fluxo Operacional de Resposta (6 Fases)

### Fase 1: Detecção e Triagem
- **Fontes de Alerta:**
  - Eventos `SECURITY_CROSS_TENANT_VIOLATION_BLOCKED` na tabela `audit_logs`.
  - Disparo de inconsistência pelo verificador `ClinicalRecordService::verifyIntegrity`.
  - Tentativas bloqueadas pelo `SecureClinicalStorageService` (upload malicioso).
- **Ação Imediata:** Registro do incidente com timestamp UTC, identificador do tenant e abertura de chamado de crise.

### Fase 2: Contenção e Isolamento
- Revogação imediata de sessões ativas do usuário/ator suspeito na tabela `sessions`.
- Bloqueio temporário de IP na camada web / HostGator cPanel se detectado ataque de força bruta.
- Suspensão preventiva de credenciais de API mobile comprometidas.

### Fase 3: Avaliação de Impacto e Risco
- O Encarregado pelo Tratamento de Dados Pessoais (DPO) e o Responsável Técnico avaliam:
  1. Número de titulares afetados.
  2. Natureza dos dados (cadastrais vs. dados clínicos sensíveis de saúde).
  3. Potencial dano relevante aos direitos dos pacientes.

### Fase 4: Comunicação e Notificação Legal
- **Notificação à ANPD:** Havendo risco relevante, notificar a Autoridade Nacional de Proteção de Dados (ANPD) no prazo regulamentar de **3 (três) dias úteis**, contados do conhecimento do incidente (Resolução CD/ANPD nº 15/2024).
- **Comunicação aos Titulares Afetados:** Envio de comunicação clara em linguagem simples contendo a natureza do incidente, medidas de mitigação adotadas e recomendações de segurança.

### Fase 5: Erradicação, Recuperação e Ensaio de Restauração
- Correção definitiva da vulnerabilidade na codebase.
- Validação da integridade dos bancos de dados via `ClinicalRecordService::verifyIntegrity`.
- **Ensaio de Restauração (Restore Test):** Restauração do último backup íntegro em ambiente isolado de staging, confirmando checksum SHA-256 e ausência de corrupção.

### Fase 6: Lições Aprendidas e Pós-Incidente
- Elaboração do Relatório Final de Incidente em até 5 dias úteis.
- Atualização das suítes de testes automatizados com novos cenários de teste defensivo.

---

## 4. Política de Retenção e Descarte Seguro

1. **Prontuários e Avaliações Clínicas:** Guarda mínima de 20 anos (Lei 13.787/2018). Impossibilidade de eliminação física antes do término desse prazo.
2. **Backups Externos:** Rotação mensal com criptografia AES-256 e retenção imutável de imagens históricas.
3. **Descarte de Mídias:** Sobrevindo o término legal de guarda, descarte eletrônico com sobrescrita criptográfica (zero-fill ou destruição de chaves).
