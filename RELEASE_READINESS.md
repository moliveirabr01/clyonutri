# Release Readiness — decisões do Maicon de 17/11/2026 (`v54.77`)

**Veredicto:** **GO com ressalvas para homologação na HostGator** (não é aprovação de produção)
**Versão:** `v54.77` · **Base:** `/Users/moliveira/Nutri_II`, branch `feat/v54-77-decisoes` (sobre a v54.76) · **Data:** 17/11/2026
**Build:** `v54.77-ad4ce64468413efb` · **Integridade do zip extraído:** `consistent (1325 arquivos)`

| Item | Status | Evidência |
|---|---|---|
| Gate core limpo (`bin/run-tests core`, cópia sem .env/sqlite) | PASS — 42 suítes OK, 0 falhas | `gate/gate-core-final.log` |
| Suíte completa (`bin/run-tests full`) | 0 FAIL; números no RELATORIO (v54.76: 166 PASS / 0 FAIL / 69 conhecidas) | `gate/full-final.log` + `.tsv` |
| SQL 091→102 em MySQL 5.7.44 real, banco como produção, 2x (estrito e não estrito) | PASS 26/0 em cada modo | `evidencias/v5477_sql_producao_*.txt` |
| RT dos catálogos no MySQL 5.7.44 (Chromium + Firefox) | PASS 8/0 | `evidencias/rt_mysql57_strict.json` |
| RT dos catálogos, ficha ≤15 links, cadastro (Chromium + Firefox, axe) | PASS | `evidencias/browser_*_v5477.json` |
| Pacote: sem .env, sem tests/, sem docs/, com storage/.htaccess, sem vendor/vendor | PASS | RELATORIO §Pacote |
| CRN do RT (Raphael Lombardi) | **PENDENTE — Maicon preenche** em Admin > Medidas caseiras; sem CRN as revisões ficam bloqueadas | LEIA-ME §4.2 |
| NI2-885 cifragem em produção | ADIADA por decisão (não rodar agora) | LEIA-ME §5 |
| Apache/HostGator real, SMTP HostGator, Asaas sandbox, 2 aparelhos, voz real | BLOCKED | sem acesso/credenciais no box |
| Celular, Safari, leitor de tela | NOT TESTED | — |
| NI2-876 MFA | NÃO FEITO (decisão: MFA desligado) | — |

Não marcar nenhuma história como Feito na Jira sem a validação independente na HostGator.
