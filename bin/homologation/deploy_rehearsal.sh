#!/usr/bin/env bash
# ==============================================================================
# ClyoNutri II — Ensaio de deploy, backup, rollback e falha de banco (NI2-409 / AUD-07)
# ------------------------------------------------------------------------------
# Reproduz a topologia de produção (releases/, shared/, current -> release) com
# MySQL real e dados criados pelo HTTP real:
#   1. instala a release ANTERIOR (N-K migrations) e cria dados (cadastro + paciente)
#   2. backup do banco (mysqldump) + storage, com checksum SHA-256 verificado
#   3. deploy da release NOVA: migrate, troca do symlink, saúde, login e dados intactos
#   4. rollback: volta o symlink, restaura o backup, confere schema anterior e dados
#   5. falha de banco: credencial inválida -> 5xx sem vazar SQL, caminho ou senha
#
# Uso: bin/homologation/deploy_rehearsal.sh <diretorio_de_trabalho> <arquivo_json_de_saida>
# Ambiente: GATE_DB_HOST/PORT/USER/PASS, GATE_RUN_ID, PHP_BIN (padrão: php),
#           GATE_REHEARSAL_PORT (padrão 18480), GATE_UPGRADE_BACK (padrão 6),
#           GATE_PREVIOUS_REF (opcional: tag/commit git da release em produção; sem ele,
#           a "anterior" é o código atual sem as últimas GATE_UPGRADE_BACK migrations).
# ==============================================================================
set -euo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
WORK="${1:?informe o diretório de trabalho}"
OUT="${2:?informe o arquivo JSON de saída}"
PHP_BIN="${PHP_BIN:-php}"
PORT="${GATE_REHEARSAL_PORT:-18480}"
BACK="${GATE_UPGRADE_BACK:-6}"
RUN="$(echo "${GATE_RUN_ID:-$$}" | tr -cd 'a-z0-9')"
DB="gate_${RUN}_deploytest"
HOST="${GATE_DB_HOST:-127.0.0.1}"; DBPORT="${GATE_DB_PORT:-3306}"; USER="${GATE_DB_USER:-root}"; PASS="${GATE_DB_PASS:-}"
BASE="$WORK/deploy"
URL="http://127.0.0.1:${PORT}"
SERVER_PID=""
CHECKS=()
FAILED=0
START_TS=$(date +%s)

mysql_cli() { MYSQL_PWD="$PASS" mysql --host="$HOST" --port="$DBPORT" --user="$USER" --default-character-set=utf8mb4 "$@"; }
json_escape() { "$PHP_BIN" -r 'echo json_encode($argv[1], JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);' "$1"; }
check() { # check <id> <ok|fail> <detalhe>
  local s="PASS"; [ "$2" = ok ] || { s="FAIL"; FAILED=$((FAILED+1)); }
  CHECKS+=("{\"id\":$(json_escape "$1"),\"status\":\"$s\",\"detail\":$(json_escape "$3")}")
  echo "  [$s] $1 — $3"
}
stop_server() { if [ -n "$SERVER_PID" ]; then kill "$SERVER_PID" 2>/dev/null || true; wait "$SERVER_PID" 2>/dev/null || true; SERVER_PID=""; fi; }
start_server() { # start_server [senha_db]
  # FORCE_HTTPS=false: ensaio em HTTP local; com HTTPS obrigatório (NI2-427) tudo responderia 403.
  stop_server
  local pass="${1:-$PASS}"
  ( cd "$BASE/current" && DB_CONNECTION=mysql DB_HOST="$HOST" DB_PORT="$DBPORT" DB_USERNAME="$USER" DB_PASSWORD="$pass" DB_DATABASE="$DB" \
    APP_ENV=production FORCE_HTTPS=false APP_TIMEZONE=America/Sao_Paulo PHP_CLI_SERVER_WORKERS=2 \
    exec "$PHP_BIN" -S "127.0.0.1:${PORT}" -t public public/index.php ) > "$WORK/rehearsal_server.log" 2>&1 &
  SERVER_PID=$!
  for _ in $(seq 1 50); do curl -s -o /dev/null "$URL/up" && return 0; sleep 0.2; done
  return 1
}
migrate_current() {
  ( cd "$BASE/current" && DB_CONNECTION=mysql DB_HOST="$HOST" DB_PORT="$DBPORT" DB_USERNAME="$USER" DB_PASSWORD="$PASS" DB_DATABASE="$DB" \
    APP_ENV=production "$PHP_BIN" bin/migrate.php )
}
schema_version() { mysql_cli -N -e "SELECT COUNT(*), MAX(migration) FROM \`$DB\`.migrations" | tr '\t' ' '; }
csrf_of() { grep -oE 'name="_csrf_token" value="[^"]+"' | head -1 | sed 's/.*value="//;s/"//'; }
login_and_find() { # login_and_find <email> <senha> <texto_esperado_em_/pacientes>
  local jar; jar=$(mktemp)
  local t; t=$(curl -s -c "$jar" -b "$jar" "$URL/login" | csrf_of)
  local loc; loc=$(curl -s -o /dev/null -w '%{redirect_url}' -c "$jar" -b "$jar" --data-urlencode "_csrf_token=$t" --data-urlencode "email=$1" --data-urlencode "password=$2" "$URL/login")
  local page; page=$(curl -s -c "$jar" -b "$jar" "$URL/pacientes")
  rm -f "$jar"
  [[ "$loc" =~ (dashboard|onboarding) ]] && grep -q "$3" <<<"$page"
}
make_release() { # make_release <nome> <migrations_a_omitir>
  local dir="$BASE/releases/$1"
  mkdir -p "$dir"
  rsync -a --exclude='.git' --exclude='node_modules' --exclude='tests' --exclude='mobile' --exclude='docs' --exclude='release' \
    --exclude='replatform' --exclude='storage' --exclude='.env' "$ROOT/" "$dir/"
  if [ "$2" -gt 0 ]; then ls "$dir/database/migrations/"*.php | sort | tail -n "$2" | xargs rm -f; fi
  ln -sfn "$BASE/shared/storage" "$dir/storage"
}
cleanup() { stop_server; mysql_cli -e "DROP DATABASE IF EXISTS \`$DB\`" 2>/dev/null || true; }
trap cleanup EXIT

rm -rf "$BASE"; mkdir -p "$BASE/releases" "$BASE/shared/storage/logs" "$BASE/shared/storage/uploads" "$BASE/backups"
mysql_cli -e "DROP DATABASE IF EXISTS \`$DB\`; CREATE DATABASE \`$DB\` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci"

if [ -n "${GATE_PREVIOUS_REF:-}" ]; then
  echo "== 1. Instala a release anterior real ($GATE_PREVIOUS_REF)"
  mkdir -p "$BASE/releases/previous"
  git -C "$ROOT" archive "$GATE_PREVIOUS_REF" | tar -x -C "$BASE/releases/previous"
  ln -sfn "$BASE/shared/storage" "$BASE/releases/previous/storage"
  PREVIOUS_KIND="git:$GATE_PREVIOUS_REF"
else
  echo "== 1. Instala a release anterior (código atual sem as últimas $BACK migrations)"
  make_release previous "$BACK"
  PREVIOUS_KIND="simulada:-${BACK}-migrations"
fi
ln -sfn "$BASE/releases/previous" "$BASE/current"
migrate_current > "$WORK/rehearsal_migrate_previous.log"
PREV_SCHEMA="$(schema_version)"
start_server && check "release_anterior_no_ar" ok "schema ${PREV_SCHEMA}" || check "release_anterior_no_ar" fail "servidor não subiu"

EMAIL="deploy.$(date +%s)@gate.test"; PW='SenhaForte!2026x'; PATIENT="Paciente Ensaio Deploy $RUN"
JAR=$(mktemp)
T=$(curl -s -c "$JAR" -b "$JAR" "$URL/cadastro" | csrf_of)
curl -s -o /dev/null -c "$JAR" -b "$JAR" --data-urlencode "_csrf_token=$T" --data-urlencode "name=Nutri Ensaio" --data-urlencode "email=$EMAIL" \
  --data-urlencode "password=$PW" --data-urlencode "clinic_name=Consultório Ensaio" -d "crn=45678&crn_region=3" "$URL/cadastro"
HTML=$(curl -s -c "$JAR" -b "$JAR" "$URL/pacientes")
T=$(csrf_of <<<"$HTML"); SUB=$(grep -oE 'name="_submission_id" value="[^"]+"' <<<"$HTML" | head -1 | sed 's/.*value="//;s/"//')
curl -s -o /dev/null -c "$JAR" -b "$JAR" --data-urlencode "_csrf_token=$T" --data-urlencode "_submission_id=$SUB" --data-urlencode "name=$PATIENT" \
  --data-urlencode "email=ensaio.$RUN@gate.test" -d "phone=11955554444&birth_date=1991-01-01&gender=female" "$URL/pacientes"
rm -f "$JAR"
ROWS_BEFORE=$(mysql_cli -N -e "SELECT COUNT(*) FROM \`$DB\`.patients")
login_and_find "$EMAIL" "$PW" "$PATIENT" && check "dados_criados_pelo_http" ok "nutricionista + paciente; pacientes=$ROWS_BEFORE" || check "dados_criados_pelo_http" fail "login/paciente não encontrados"

echo "== 2. Backup (mysqldump + storage) com checksum"
echo "anexo-sintetico" > "$BASE/shared/storage/uploads/anexo-ensaio.txt"
MYSQL_PWD="$PASS" mysqldump --host="$HOST" --port="$DBPORT" --user="$USER" --single-transaction --no-tablespaces --default-character-set=utf8mb4 "$DB" > "$BASE/backups/db.sql"
tar -czf "$BASE/backups/storage.tar.gz" -C "$BASE/shared" storage
( cd "$BASE/backups" && sha256sum db.sql storage.tar.gz > SHA256SUMS )
( cd "$BASE/backups" && sha256sum -c --quiet SHA256SUMS ) && check "backup_integro" ok "$(wc -c < "$BASE/backups/db.sql") bytes de SQL; checksums conferidos" || check "backup_integro" fail "checksum divergente"

echo "== 3. Deploy da release nova"
make_release new 0
ln -sfn "$BASE/releases/new" "$BASE/current"
migrate_current > "$WORK/rehearsal_migrate_new.log" && MIG_OK=1 || MIG_OK=0
NEW_SCHEMA="$(schema_version)"
[ "$MIG_OK" = 1 ] && [ "$NEW_SCHEMA" != "$PREV_SCHEMA" ] && check "upgrade_aplicado" ok "schema ${PREV_SCHEMA} -> ${NEW_SCHEMA}" || check "upgrade_aplicado" fail "migrate falhou ou schema não mudou"
start_server
UP=$(curl -s -w ' %{http_code}' "$URL/up")
[[ "$UP" == *'"database":"connected"'*" 200" ]] && check "saude_apos_deploy" ok "/up 200 connected" || check "saude_apos_deploy" fail "$UP"
login_and_find "$EMAIL" "$PW" "$PATIENT" && check "dados_intactos_apos_deploy" ok "login e paciente preservados" || check "dados_intactos_apos_deploy" fail "dados não encontrados após deploy"

echo "== 4. Rollback (symlink + restauração do backup)"
( cd "$BASE/backups" && sha256sum -c --quiet SHA256SUMS ) || check "backup_integro_no_rollback" fail "checksum divergente antes de restaurar"
ln -sfn "$BASE/releases/previous" "$BASE/current"
mysql_cli -e "DROP DATABASE \`$DB\`; CREATE DATABASE \`$DB\` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci"
mysql_cli "$DB" < "$BASE/backups/db.sql"
rm -rf "$BASE/shared/storage"; tar -xzf "$BASE/backups/storage.tar.gz" -C "$BASE/shared"
RB_SCHEMA="$(schema_version)"
[ "$RB_SCHEMA" = "$PREV_SCHEMA" ] && check "schema_restaurado" ok "schema ${RB_SCHEMA}" || check "schema_restaurado" fail "esperado ${PREV_SCHEMA}, obtido ${RB_SCHEMA}"
start_server
UP=$(curl -s -w ' %{http_code}' "$URL/up")
[[ "$UP" == *'"database":"connected"'*" 200" ]] && check "saude_apos_rollback" ok "/up 200 connected" || check "saude_apos_rollback" fail "$UP"
ROWS_AFTER=$(mysql_cli -N -e "SELECT COUNT(*) FROM \`$DB\`.patients")
login_and_find "$EMAIL" "$PW" "$PATIENT" && [ "$ROWS_AFTER" = "$ROWS_BEFORE" ] && [ -f "$BASE/shared/storage/uploads/anexo-ensaio.txt" ] \
  && check "dados_intactos_apos_rollback" ok "login, paciente e anexo restaurados; pacientes=$ROWS_AFTER" || check "dados_intactos_apos_rollback" fail "pacientes antes=$ROWS_BEFORE depois=$ROWS_AFTER"

echo "== 5. Falha de banco (credencial inválida)"
start_server "senha-invalida-$RUN" || true
RESP_UP=$(curl -s -w '\n%{http_code}' "$URL/up"); CODE_UP=$(tail -n1 <<<"$RESP_UP")
RESP_LOGIN=$(curl -s -w '\n%{http_code}' "$URL/login"); CODE_LOGIN=$(tail -n1 <<<"$RESP_LOGIN")
LEAK=$(printf '%s\n%s' "$RESP_UP" "$RESP_LOGIN" | grep -ciE "SQLSTATE|PDOException|senha-invalida|Stack trace|/home/|/var/www|\.php on line" || true)
[[ "$CODE_UP" =~ ^5 ]] && [[ "$CODE_LOGIN" =~ ^5 ]] && [ "$LEAK" = 0 ] \
  && check "falha_de_banco_controlada" ok "/up=$CODE_UP /login=$CODE_LOGIN, sem vazamento de detalhes" \
  || check "falha_de_banco_controlada" fail "/up=$CODE_UP /login=$CODE_LOGIN vazamentos=$LEAK"
stop_server

DURATION=$(( $(date +%s) - START_TS ))
STATUS="PASS"; [ "$FAILED" -eq 0 ] || STATUS="FAIL"
IFS=,; printf '{"status":"%s","duration_s":%d,"previous_release":"'"$PREVIOUS_KIND"'","database":"%s","schema_previous":%s,"schema_new":%s,"checks":[%s]}\n' \
  "$STATUS" "$DURATION" "$DB" "$(json_escape "$PREV_SCHEMA")" "$(json_escape "${NEW_SCHEMA:-}")" "${CHECKS[*]}" > "$OUT"
unset IFS
echo "== Ensaio: $STATUS ($DURATION s) → $OUT"
[ "$FAILED" -eq 0 ]
