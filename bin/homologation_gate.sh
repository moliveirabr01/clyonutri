#!/usr/bin/env bash
# ==============================================================================
# ClyoNutri II — Gate real de homologação (NI2-409 / AUD-07)
# ------------------------------------------------------------------------------
# Executa, contra MySQL 8 real e pelo entrypoint real (public/index.php):
#   P0 source_traceability       árvore git limpa (evidência vinculada ao commit)
#   P0 php_lint                  sintaxe de todo o PHP
#   P0 mysql_schema              instalação zero, upgrade com dados, SQL de instalação, fingerprints
#   P0 http_contract_security    contrato HTTP + OTP/RBAC/tenant/CSRF/sessão (Playwright request)
#   P0 web_journeys              admin, consulta, service desk e recuperação de senha por e-mail (SMTP real) no Chromium
#   P0 mobile_web_app            build web do app Expo, viewport de celular, API real
#   P0* mobile_native_android    evidência de dispositivo/emulador para ESTE commit (*P0 só no perfil mobile)
#   P0 deploy_rollback_rehearsal deploy, backup, rollback e falha de banco
#   P1 responsive / accessibility / legacy_php_suites   (achados, não bloqueiam)
#   INFO teleconsulta_external_provider (nunca homologada por mock)
#
# Decisão FAIL CLOSED: APPROVED somente com TODOS os P0 em PASS. SKIPPED/BLOCKED bloqueiam.
# Perfis (GATE_RELEASE_PROFILE): web (padrão — sistema web na HostGator; Android nativo só
# entra no relatório) ou mobile (release do app nas lojas; Android nativo é P0).
#
# Uso: bin/homologation_gate.sh [pasta_de_evidencias]
# Ambiente obrigatório: GATE_DB_HOST, GATE_DB_PORT, GATE_DB_USER, GATE_DB_PASS
#   (usuário MySQL com permissão para criar/remover bancos gate_%).
# Opcional: PHP_BIN, GATE_PORT (18180), GATE_MOBILE_PORT (18190), GATE_PREVIOUS_REF,
#   GATE_MOBILE_NATIVE_EVIDENCE (JSON de execução em dispositivo, ver docs/delivery/HOMOLOGATION_GATE.md),
#   GATE_KEEP_DBS=1 (não apaga os bancos ao final).
# Saída: 0 = APPROVED, 1 = BLOCKED. Relatório: <pasta>/GATE_REPORT.md e GATE_REPORT.json
# ==============================================================================
set -uo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
cd "$ROOT"
PHP_BIN="${PHP_BIN:-php}"
COMMIT="$(git rev-parse --short HEAD 2>/dev/null || echo sem-git)"
STAMP="$(date +%Y%m%d_%H%M%S)"
EVID="${1:-$ROOT/release/evidence/${STAMP}_${COMMIT}}"
RUN_ID="$(echo "r${STAMP}" | tr -cd 'a-z0-9' | tail -c 12)"
PROFILE="${GATE_RELEASE_PROFILE:-web}"
case "$PROFILE" in web|mobile) ;; *) echo "GATE_RELEASE_PROFILE inválido: $PROFILE (use web ou mobile)"; exit 2;; esac
NATIVE_PRIO=$([ "$PROFILE" = mobile ] && echo P0 || echo INFO)
PORT="${GATE_PORT:-18180}"; MPORT="${GATE_MOBILE_PORT:-18190}"
BASE_URL="http://127.0.0.1:${PORT}"; MOBILE_URL="http://127.0.0.1:${MPORT}"
APP_DB="gate_${RUN_ID}_apptest"
WORK="$EVID/.work"
mkdir -p "$EVID/logs" "$WORK"
: > "$EVID/checks.jsonl"
PIDS=()

export GATE_RUN_ID="$RUN_ID" GATE_EVIDENCE_DIR="$EVID" GATE_BASE_URL="$BASE_URL" GATE_MOBILE_URL="$MOBILE_URL" GATE_PHP_BIN="$PHP_BIN" PHP_BIN
export APP_TIMEZONE="${APP_TIMEZONE:-America/Sao_Paulo}"

jstr() { "$PHP_BIN" -r 'echo json_encode($argv[1], JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);' "$1"; }
record() { # record <id> <P0|P1|INFO> <status> <duração_s> <detalhe> [log]
  printf '{"id":%s,"priority":"%s","status":"%s","duration_s":%s,"detail":%s,"log":%s}\n' \
    "$(jstr "$1")" "$2" "$3" "${4:-0}" "$(jstr "$5")" "$(jstr "${6:-}")" >> "$EVID/checks.jsonl"
  printf '  %-8s %-4s %-28s %s\n' "[$3]" "$2" "$1" "$5"
}
now() { date +%s; }
# Limite de tempo portável: GNU timeout (Linux), gtimeout (coreutils no macOS) ou perl (nativo no macOS).
run_timeout() { # run_timeout <segundos> <comando...>
  local secs="$1"; shift
  if command -v timeout >/dev/null 2>&1; then timeout "$secs" "$@"
  elif command -v gtimeout >/dev/null 2>&1; then gtimeout "$secs" "$@"
  else perl -e 'alarm shift; exec @ARGV or die "exec: $!"' "$secs" "$@"; fi
}
cleanup() {
  for p in "${PIDS[@]:-}"; do [ -n "$p" ] && kill "$p" 2>/dev/null; done
  if [ "${GATE_KEEP_DBS:-}" != "1" ] && [ -n "${DB_READY:-}" ]; then
    "$PHP_BIN" bin/homologation/mysql_schema_gate.php drop "$APP_DB" > /dev/null 2>&1 || true
  fi
  rm -rf "$WORK"
}
trap cleanup EXIT

echo "========================================================================"
echo " ClyoNutri II — Gate de homologação (NI2-409)  commit ${COMMIT}  perfil ${PROFILE}"
echo " Evidências: ${EVID}"
echo "========================================================================"

# --- Ambiente e rastreabilidade ----------------------------------------------
read -r DIRTY_FILES CONTENT_SHA < <(bin/homologation/source_fingerprint.sh)
MYSQL_VERSION=$(MYSQL_PWD="${GATE_DB_PASS:-}" mysql --host="${GATE_DB_HOST:-127.0.0.1}" --port="${GATE_DB_PORT:-3306}" --user="${GATE_DB_USER:-root}" -N -e 'SELECT VERSION()' 2>/dev/null || echo indisponível)
cat > "$EVID/environment.json" <<EOF
{"source":{"commit":"$(git rev-parse HEAD 2>/dev/null || echo sem-git)","short":"$COMMIT","dirty":$([ "$DIRTY_FILES" -gt 0 ] && echo true || echo false),"dirty_files":$DIRTY_FILES,"content_sha256":"$CONTENT_SHA","branch":"$(git rev-parse --abbrev-ref HEAD 2>/dev/null || echo ?)"},
 "tools":{"php":$(jstr "$("$PHP_BIN" -r 'echo PHP_VERSION;')"),"mysql":$(jstr "$MYSQL_VERSION"),"node":$(jstr "$(node -v 2>/dev/null || echo ausente)"),"playwright":$(jstr "$(cd tests/homologation && npx --no-install playwright --version 2>/dev/null || echo ausente)"),"os":$(jstr "$(uname -srm)")},
 "release_profile":"$PROFILE","run_id":"$RUN_ID","started_at":"$(date -Iseconds)"}
EOF
if [ "$DIRTY_FILES" -eq 0 ]; then
  record source_traceability P0 PASS 0 "Árvore limpa no commit ${COMMIT}; fingerprint ${CONTENT_SHA:0:16}"
else
  record source_traceability P0 FAIL 0 "${DIRTY_FILES} arquivo(s) não commitado(s): a evidência não corresponde a um commit. Commite e rode de novo (fingerprint ${CONTENT_SHA:0:16})."
fi

# --- P0 php_lint --------------------------------------------------------------
t=$(now); LINT_LOG="$EVID/logs/php_lint.log"
find src bin public views tests/homologation/support database/migrations -name '*.php' -print0 | xargs -0 -n1 "$PHP_BIN" -l > "$LINT_LOG" 2>&1
LINT_ERR=$(grep -vc '^No syntax errors' "$LINT_LOG" || true)
LINT_N=$(grep -c '^No syntax errors' "$LINT_LOG" || true)
[ "$LINT_ERR" = 0 ] && record php_lint P0 PASS $(( $(now)-t )) "${LINT_N} arquivos sem erro" logs/php_lint.log \
                   || record php_lint P0 FAIL $(( $(now)-t )) "${LINT_ERR} erro(s) de sintaxe" logs/php_lint.log

# --- P0 mysql_schema ----------------------------------------------------------
if [ -z "${GATE_DB_HOST:-}" ] || [ -z "${GATE_DB_USER:-}" ]; then
  for c in mysql_schema http_contract_security web_journeys mobile_web_app deploy_rollback_rehearsal; do
    record "$c" P0 BLOCKED 0 "MySQL do gate não configurado (GATE_DB_HOST/GATE_DB_USER). Sem MySQL real não há homologação."
  done
  NO_DB=1
else
  t=$(now)
  "$PHP_BIN" bin/homologation/mysql_schema_gate.php all > "$EVID/mysql_schema.json" 2> "$EVID/logs/mysql_schema.log"
  if [ $? -eq 0 ]; then
    record mysql_schema P0 PASS $(( $(now)-t )) "$("$PHP_BIN" -r '$r=json_decode(file_get_contents($argv[1]),true);$s=$r["schema"];echo "MySQL {$r["mysql_version"]}: instalação zero, upgrade e SQL de instalação idênticos — {$s["version"]}, {$s["tables"]} tabelas, ".implode(",",$s["collations"]);' "$EVID/mysql_schema.json")" mysql_schema.json
  else
    record mysql_schema P0 FAIL $(( $(now)-t )) "$("$PHP_BIN" -r '$r=json_decode(@file_get_contents($argv[1]),true)?:[];$d=[];foreach(["fresh","upgrade","install_sql"] as $k){if(isset($r[$k])&&empty($r[$k]["ok"]))$d[]="$k: ".($r[$k]["detail"]??"falhou");}if(isset($r["fingerprints_identical"])&&!$r["fingerprints_identical"])$d[]="fingerprints divergentes";if(isset($r["error"]))$d[]=$r["error"];echo implode("; ",$d)?:"falhou";' "$EVID/mysql_schema.json")" mysql_schema.json
  fi
fi

if [ -z "${NO_DB:-}" ]; then
  # --- Aplicação sob teste: banco limpo de produção + servidor real -----------
  "$PHP_BIN" bin/homologation/mysql_schema_gate.php provision-app "$APP_DB" > "$EVID/logs/provision_app.json" 2>&1 && DB_READY=1

  # SMTP de teste com TLS implícito + AUTH: o app envia pelo cliente SMTP de produção, com
  # verificação de certificado ligada (CA de teste via openssl.cafile). Mensagens viram .eml.
  SMTP_PORT="${GATE_SMTP_PORT:-18465}"; export GATE_MAIL_DIR="$EVID/mail"; mkdir -p "$GATE_MAIL_DIR" "$WORK/smtp"
  openssl req -x509 -newkey rsa:2048 -nodes -keyout "$WORK/smtp/key.pem" -out "$WORK/smtp/cert.pem" -days 1 \
    -subj "/CN=localhost" -addext "subjectAltName=DNS:localhost" > /dev/null 2>&1
  python3 tests/homologation/support/fake_smtp.py --port "$SMTP_PORT" --dir "$GATE_MAIL_DIR" --cert "$WORK/smtp/cert.pem" \
    --key "$WORK/smtp/key.pem" --user noreply@gate.test --password gate-smtp-senha > "$EVID/logs/smtp.log" 2>&1 &
  PIDS+=($!)

  ( DB_CONNECTION=mysql DB_HOST="$GATE_DB_HOST" DB_PORT="${GATE_DB_PORT:-3306}" DB_USERNAME="$GATE_DB_USER" DB_PASSWORD="${GATE_DB_PASS:-}" \
    DB_DATABASE="$APP_DB" APP_ENV=production APP_URL="$BASE_URL" APP_SUPPORT_EMAIL=suporte@gate.test MOBILE_WEB_ORIGINS="$MOBILE_URL" \
    MAIL_DRIVER=smtp MAIL_HOST=localhost MAIL_PORT="$SMTP_PORT" MAIL_ENCRYPTION=ssl MAIL_USERNAME=noreply@gate.test \
    MAIL_PASSWORD=gate-smtp-senha MAIL_FROM_ADDRESS=noreply@gate.test MAIL_FROM_NAME=ClyoNutri PHP_CLI_SERVER_WORKERS=4 \
    exec "$PHP_BIN" -d openssl.cafile="$WORK/smtp/cert.pem" -S "127.0.0.1:${PORT}" -t public tests/homologation/support/router.php ) > "$EVID/logs/app_server.log" 2>&1 &
  PIDS+=($!)
  export DB_CONNECTION=mysql DB_HOST="$GATE_DB_HOST" DB_PORT="${GATE_DB_PORT:-3306}" DB_USERNAME="$GATE_DB_USER" DB_PASSWORD="${GATE_DB_PASS:-}" DB_DATABASE="$APP_DB" APP_ENV=production
  for _ in $(seq 1 50); do curl -s -o /dev/null "$BASE_URL/up" && break; sleep 0.2; done

  # --- Build web do app mobile (código atual) ----------------------------------
  t=$(now); MOBILE_BUILD="$WORK/mobile-web"; MOBILE_OK=0
  ( cd mobile && { [ -d node_modules ] || npm ci --no-audit --no-fund; } && npx expo export -p web --output-dir "$MOBILE_BUILD" ) > "$EVID/logs/mobile_web_build.log" 2>&1 && MOBILE_OK=1
  if [ "$MOBILE_OK" = 1 ]; then
    node tests/homologation/support/mobile-proxy.mjs "$MOBILE_BUILD" "$MPORT" "$BASE_URL" > "$EVID/logs/mobile_proxy.log" 2>&1 &
    PIDS+=($!); sleep 1
  fi
  MOBILE_BUILD_S=$(( $(now)-t ))

  # --- Playwright: API, web, mobile, responsivo, acessibilidade ----------------
  t=$(now)
  PROJECTS=(--project=api --project=web --project=responsive --project=a11y)
  [ "$MOBILE_OK" = 1 ] && PROJECTS+=(--project=mobile-app)
  ( cd tests/homologation && { [ -d node_modules ] || npm ci --no-audit --no-fund; } && npx playwright test "${PROJECTS[@]}" ) > "$EVID/logs/playwright.log" 2>&1
  PW_S=$(( $(now)-t ))
  node tests/homologation/support/summarize.mjs "$EVID/playwright-report.json" > "$EVID/playwright-summary.json"
  pw_status() { # pw_status <projeto> → "STATUS|detalhe"
    "$PHP_BIN" -r '$s=json_decode(file_get_contents($argv[1]),true);$p=$s["projects"][$argv[2]]??null;
      if(!$p){echo "FAIL|projeto não executou (ver logs/playwright.log)";exit;}
      $st=$p["failed"]>0||$p["flaky"]>0?"FAIL":($p["skipped"]>0?"SKIPPED":($p["passed"]>0?"PASS":"FAIL"));
      $bad=array_values(array_filter($p["tests"],fn($t)=>$t["status"]!=="passed"));
      $d="{$p["passed"]} ok, {$p["failed"]} falha(s), {$p["skipped"]} pulado(s)";
      if($bad)$d.=" — ".$bad[0]["title"].": ".substr((string)$bad[0]["error"],0,160);
      echo "$st|$d";' "$EVID/playwright-summary.json" "$1"
  }
  for pair in "api:http_contract_security:P0" "web:web_journeys:P0" "responsive:responsive:P1" "a11y:accessibility:P1"; do
    IFS=: read -r proj id prio <<<"$pair"; r=$(pw_status "$proj")
    record "$id" "$prio" "${r%%|*}" "$PW_S" "${r#*|}" logs/playwright.log
  done
  if [ "$MOBILE_OK" = 1 ]; then
    r=$(pw_status mobile-app); record mobile_web_app P0 "${r%%|*}" "$(( PW_S + MOBILE_BUILD_S ))" "Build web Expo + ${r#*|}" logs/playwright.log
  else
    record mobile_web_app P0 FAIL "$MOBILE_BUILD_S" "Build web do app falhou — app não testado (ver logs/mobile_web_build.log)" logs/mobile_web_build.log
  fi

  # --- P0 deploy / backup / rollback / falha de banco -------------------------
  t=$(now)
  bin/homologation/deploy_rehearsal.sh "$WORK" "$EVID/deploy_rehearsal.json" > "$EVID/logs/deploy_rehearsal.log" 2>&1 \
    && record deploy_rollback_rehearsal P0 PASS $(( $(now)-t )) "$(grep -c '\[PASS\]' "$EVID/logs/deploy_rehearsal.log") etapas: deploy, backup com checksum, rollback com restauração e falha de banco controlada" deploy_rehearsal.json \
    || record deploy_rollback_rehearsal P0 FAIL $(( $(now)-t )) "$( (grep '\[FAIL\]' "$EVID/logs/deploy_rehearsal.log" || tail -n 3 "$EVID/logs/deploy_rehearsal.log") | head -3 | tr '\n' ' ' | cut -c1-300)" logs/deploy_rehearsal.log
fi

# --- P0 app nativo (Android) — exige evidência real de dispositivo -------------
NATIVE="${GATE_MOBILE_NATIVE_EVIDENCE:-}"
if [ -n "$NATIVE" ] && [ -f "$NATIVE" ]; then
  cp "$NATIVE" "$EVID/mobile_native_evidence.json"
  r=$("$PHP_BIN" -r '$e=json_decode(file_get_contents($argv[1]),true)?:[];$c=$argv[2];
    $a=array_values(array_filter($e["runs"]??[],fn($x)=>($x["platform"]??"")==="android"));
    if(!$a){echo "BLOCKED|evidência sem execução Android";exit;}
    $ok=array_filter($a,fn($x)=>($x["result"]??"")==="PASS"&&str_starts_with((string)($x["git_commit"]??""),$c)&&!empty($x["device"])&&!empty($x["app_build"]));
    echo $ok?"PASS|".count($ok)." execução(ões) em ".implode(", ",array_column($ok,"device")):"FAIL|evidência Android não é PASS para o commit $c (ou falta device/app_build)";' "$NATIVE" "$COMMIT")
  record mobile_native_android "$NATIVE_PRIO" "${r%%|*}" 0 "${r#*|}" mobile_native_evidence.json
else
  record mobile_native_android "$NATIVE_PRIO" BLOCKED 0 "Sem evidência de app compilado (EAS/APK) em dispositivo ou emulador Android para o commit ${COMMIT}. Informe GATE_MOBILE_NATIVE_EVIDENCE."
fi
if grep -q '"ios"' mobile/app.json 2>/dev/null; then
  record mobile_native_ios "$NATIVE_PRIO" BLOCKED 0 "iOS configurado no app.json, mas sem evidência de execução em dispositivo/simulador."
else
  record mobile_native_ios INFO NOT_APPLICABLE 0 "App sem configuração iOS (mobile/app.json só define Android)."
fi

# --- P1 suítes PHP legadas (SQLite) -------------------------------------------
t=$(now); LEG_LOG="$EVID/logs/legacy_php_suites.log"; : > "$LEG_LOG"
LEG_DB="$WORK/legacy.sqlite"; LEG_OK=0; LEG_FAIL=0; LEG_FAILED=()
( unset DB_HOST DB_PORT DB_USERNAME DB_PASSWORD; export DB_CONNECTION=sqlite DB_DATABASE="$LEG_DB" APP_ENV=local
  "$PHP_BIN" bin/migrate.php && "$PHP_BIN" bin/seed.php && "$PHP_BIN" bin/seed.php --demo ) >> "$LEG_LOG" 2>&1
while read -r suite; do
  [[ -z "$suite" || "$suite" == \#* ]] && continue
  cp "$LEG_DB" "$WORK/legacy_run.sqlite"
  if ( unset DB_HOST DB_PORT DB_USERNAME DB_PASSWORD; DB_CONNECTION=sqlite DB_DATABASE="$WORK/legacy_run.sqlite" APP_ENV=local \
       run_timeout 180 "$PHP_BIN" -d zend.assertions=1 -d assert.exception=1 "$suite" ) >> "$LEG_LOG" 2>&1; then
    LEG_OK=$((LEG_OK+1)); echo "[PASS] $suite" >> "$LEG_LOG"
  else
    LEG_FAIL=$((LEG_FAIL+1)); LEG_FAILED+=("$(basename "$suite" .php)"); echo "[FAIL] $suite" >> "$LEG_LOG"
  fi
done < bin/homologation/legacy_suites.txt
[ "$LEG_FAIL" = 0 ] && record legacy_php_suites P1 PASS $(( $(now)-t )) "${LEG_OK} suítes aprovadas" logs/legacy_php_suites.log \
  || record legacy_php_suites P1 FAIL $(( $(now)-t )) "${LEG_OK} ok, ${LEG_FAIL} falharam: ${LEG_FAILED[*]}" logs/legacy_php_suites.log

# --- INFO teleconsulta externa -------------------------------------------------
if [ -n "${DAILY_API_KEY:-}" ] && [ -n "${DAILY_DOMAIN:-}" ]; then
  record teleconsulta_external_provider INFO NOT_TESTED 0 "Credenciais Daily presentes, mas não há teste automatizado contra o provedor real; não declarada homologada."
else
  record teleconsulta_external_provider INFO BLOCKED 0 "Provedor de vídeo (Daily) sem credenciais: teleconsulta externa NÃO homologada (sem mock)."
fi

# --- Relatório e decisão ------------------------------------------------------
echo "------------------------------------------------------------------------"
"$PHP_BIN" bin/homologation/build_report.php "$EVID"
RC=$?
exit $RC
