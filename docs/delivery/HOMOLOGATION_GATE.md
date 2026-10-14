# Gate real de homologação — NI2-409 (AUD-07)

O gate substitui o manifesto antigo, que declarava camadas "PASSED" escritas à mão. Agora uma release só é empacotada quando **todos os checks P0 passam** contra MySQL 8 real, pelo entrypoint real (`public/index.php`), num navegador real e com deploy/rollback ensaiado. Cada execução gera uma pasta de evidências vinculada ao commit.

## Perfis de release

| Perfil | Quando usar | Android nativo |
|---|---|---|
| `web` (padrão) | Sistema web na HostGator: o que roda em `clyonutri.com` | Aparece no relatório (INFO), **não bloqueia** |
| `mobile` | Publicação do app nas lojas | **P0**: exige evidência de app compilado em dispositivo/emulador para o commit |

Escolha com `GATE_RELEASE_PROFILE=web|mobile`, que vale para o gate e para o `build_release.sh`. O build recusa relatório de perfil diferente do pedido. Os dois perfis exigem MySQL, API, jornadas web, app (build web) e ensaio de deploy/rollback.

## Como rodar

```bash
export GATE_DB_HOST=127.0.0.1 GATE_DB_PORT=3306 GATE_DB_USER=root GATE_DB_PASS=...   # usuário que cria/remove bancos gate_%
npm ci --prefix tests/homologation && npm ci --prefix mobile
(cd tests/homologation && npx playwright install chromium)
bin/homologation_gate.sh                 # evidências em release/evidence/<data>_<commit>/
```

- Saída `0` = **APPROVED**, `1` = **BLOCKED**. Relatório legível em `GATE_REPORT.md`, completo em `GATE_REPORT.json`.
- `bin/build_release.sh` roda o gate e **não empacota** se a decisão não for APPROVED, se o relatório for de outro commit ou se a árvore tiver alterações não commitadas.
- No GitHub, o workflow `ci.yml` roda o gate com MySQL 8 em serviço, publica as evidências como artefato e só gera o pacote com o gate aprovado.
- O gate usa somente bancos descartáveis com prefixo `gate_` e os remove ao final (`GATE_KEEP_DBS=1` para inspecionar).

## O que cada check prova

| Prioridade | Check | Prova |
|---|---|---|
| P0 | `source_traceability` | Árvore limpa: a evidência corresponde exatamente a um commit (mais fingerprint SHA-256 do conteúdo). |
| P0 | `php_lint` | Todo o PHP compila. |
| P0 | `mysql_schema` | Migrations num MySQL vazio, reexecução idempotente, upgrade N-6 → N com dados preservados e importação de `database/instalacao_mysql8.sql`. Os três schemas precisam ter o **mesmo fingerprint** (colunas, tipos, collation, índices, FKs). |
| P0 | `http_contract_security` | Contrato de `/up`, `/api/v1/*` e 404. CSRF (403 e nada gravado), cookie `HttpOnly`/`SameSite=Lax`, token obrigatório e gravado como hash, isolamento entre consultórios (API e web), convite de uso único, login sem senha proibido, OTP (formato, errado, expirado, uso único) e RBAC (suporte/auditor não executam ações privilegiadas; negação auditada). |
| P0 | `web_journeys` | Chromium: **recuperação de senha por e-mail** (pedido na tela → envio pelo cliente SMTP de produção com TLS e autenticação → link do e-mail → nova senha; senha antiga recusada, link de uso único, conta inexistente sem envio e com a mesma resposta), **consulta** (cadastro → paciente → agenda → sala → conclusão → plano com alimento → publicação → convite → paciente recebe o plano), **admin** (suspensão derruba a sessão, bloqueia login, é auditada, reativação devolve o acesso) e **service desk** (chamado → fila do suporte → resposta → nutricionista vê). Cada passo é conferido no MySQL e tem screenshot. |
| P0 | `mobile_web_app` | Build web do app Expo (código atual), em viewport de celular, contra a API real: primeiro acesso por convite, registro de água persistido, logout revoga token, login do nutricionista. |
| P0 (mobile) / INFO (web) | `mobile_native_android` | **App compilado em dispositivo/emulador.** Fica BLOCKED sem evidência para o commit (veja abaixo); só bloqueia no perfil `mobile`. |
| P0 | `deploy_rollback_rehearsal` | Topologia `releases/` + `shared/` + `current`: release anterior com dados → backup com SHA-256 → deploy + migrate → saúde, login e dados → rollback com restauração → schema anterior e dados intactos → queda do banco devolve 5xx sem vazar SQL, caminho ou senha. |
| P1 | `responsive` | 17 páginas sem rolagem horizontal em 375 px e 768 px. |
| P1 | `accessibility` | axe-core (WCAG 2.1 A/AA) nas páginas-chave; violações em `a11y-violations.json`. |
| P1 | `legacy_php_suites` | As 52 suítes PHP antigas em SQLite novo, com `zend.assertions=1`. |
| INFO | `teleconsulta_external_provider` | Sempre BLOCKED/NOT_TESTED sem teste contra o provedor real. Teleconsulta nunca é declarada homologada por mock. |
| INFO | `mobile_native_ios` | NOT_APPLICABLE enquanto `mobile/app.json` não configurar iOS. Vira P0 automaticamente quando configurado. |

**Regra:** checks obrigatórios do perfil (ou marcados P0) em FAIL, SKIPPED, BLOCKED, NOT_TESTED ou ausentes bloqueiam a release. P1 e INFO aparecem como achados. A regra é testada em `tests/release_gate_and_fail_closed_test.php`.

## Evidência de app nativo (Android)

Depois de instalar o build (EAS/APK) num dispositivo ou emulador e executar o roteiro (primeiro acesso por convite, home com plano, registro de água, logout, login do nutricionista), registre:

```json
{
  "runs": [
    {
      "platform": "android",
      "device": "Samsung Galaxy A54 (Android 14)",
      "app_build": "EAS build 1.0.0 (42) — https://expo.dev/...",
      "git_commit": "<sha do commit testado>",
      "result": "PASS",
      "tested_at": "2026-10-14T10:00:00-03:00",
      "tester": "Nome",
      "notes": "Roteiro completo; screenshots anexadas ao chamado"
    }
  ]
}
```

e rode o gate com `GATE_MOBILE_NATIVE_EVIDENCE=/caminho/evidencia.json`. O commit precisa ser o mesmo do gate; evidência de outro commit **não** aprova. No GitHub, configure a variável `GATE_MOBILE_NATIVE_EVIDENCE_PATH`.

## Pasta de evidências

```
GATE_REPORT.md / GATE_REPORT.json   decisão, bloqueios, checks, achados, SHA-256 de cada evidência
environment.json                    commit, árvore limpa?, fingerprint, versões (PHP, MySQL, Node, Playwright)
mysql_schema.json                   versão e fingerprint do schema nos três caminhos
deploy_rehearsal.json               etapas do ensaio de deploy/rollback
playwright-report.json / -summary   resultado por teste; playwright-html/ navegável
screenshots/                        uma imagem por etapa das jornadas
a11y-violations.json                violações de acessibilidade por página
logs/                               lint, schema, servidor, build do app, Playwright, ensaio, suítes legadas
```

## Rollback em produção (HostGator)

O ensaio do gate executa exatamente estes passos:

1. **Antes do deploy:** `mysqldump --single-transaction` do banco e `tar` de `storage/`; gere `sha256sum` dos dois arquivos.
2. **Deploy:** envie a nova release para `releases/<commit>`, ligue `storage` e `.env` de `shared/`, rode `php bin/migrate.php`, troque `current` e confira `/up` (`"database":"connected"`), o login e um registro conhecido.
3. **Se algo falhar:** volte o symlink `current` para a release anterior, confira os checksums, recrie o banco e importe o dump, restaure `storage/`, confira `/up`, o login e o registro.

As migrations não têm `down()` confiável no MySQL (DDL faz commit implícito), por isso o rollback de schema é **sempre pela restauração do backup**.

## Atualizar o SQL de instalação

Ao criar uma migration, regenere o SQL usado no phpMyAdmin. Senão, o check `mysql_schema` falha com fingerprint divergente:

```bash
php bin/homologation/mysql_schema_gate.php dump-install
```
