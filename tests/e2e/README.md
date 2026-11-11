# Portão de release (NI2-632)

Um comando roda tudo e decide **GO / NO-GO**:

```bash
composer install            # uma vez
npm i -D playwright@1 && npx playwright install chromium   # uma vez, se ainda não houver Playwright
bash tests/run_release_gate.sh             # PHP + navegador
bash tests/run_release_gate.sh --php       # só domínio
E2E_ONLY=tenant,p5411 bash tests/run_release_gate.sh --browser
```

| Código | Resultado | Quando |
|---|---|---|
| 0 | GO | tudo passou (falhas conhecidas só com a mesma contagem) |
| 1 | NO-GO | qualquer falha nova ou erro de execução |
| 2 | INCOMPLETO | faltou PHP, Node, Playwright ou Chromium: a parte sai "NÃO TESTADO", nunca aprovada |

## Garantias
- Cada cenário sobe o próprio servidor (`php -S`, porta livre) com um **SQLite descartável** montado por `lib/seed.php`, só com dados TESTE. Nunca lê o `.env` nem abre MySQL; e-mail desligado (`MAIL_DRIVER=log`).
- O banco do cenário é apagado ao final. Saídas, capturas e o relatório (`report.md`, `report.json`, com versão e commit) ficam em `storage/e2e-report/<versão>-<data>/`, que não entra no zip.
- `known_failures.txt` lista falhas já conhecidas com a contagem exata. Mudou a contagem, bloqueia.

## Cenários de navegador
| Nome | Cobre | Seed |
|---|---|---|
| v541 | link público, apelido, alergia, plano (v54.1) | v541 |
| p607 | NI2-607 | rascunho |
| p609 | NI2-609 medidas caseiras | rascunho |
| p610 | NI2-610/612/615 pendências, horários, feriados | rascunho |
| p613c | NI2-613 agenda em várias larguras (375 a 1440 px) | rascunho |
| p5410 | NI2-603/604/605/611 agendamento, sessão, versões da anamnese, textos | versoes |
| p5411 | NI2-606/608 equipe e busca de paciente | equipe |
| tenant | isolamento entre duas clínicas (leitura e ação) | tenant |

Cada script imprime `PASS`/`FAIL` por verificação e recebe a URL base (e, quando precisa, o caminho do banco). Variáveis: `E2E_SQ` (consulta ao SQLite) e `E2E_OUT` (pasta de capturas).

## Também coberto (NI2-662, v54.14)
`p5416`: antropometria pelo perfil com IDs coincidentes (caso do paciente 9448), emissão de exames de ponta a ponta com PDF e hash, portal do paciente com plano publicado e isolamento.

## Ainda não coberto
Agenda com conflito e feriado no mesmo dia; expiração de sessão no meio de um formulário longo.
