#!/usr/bin/env bash
# NI2-233: homologação reexecutável do prontuário/anamnese (fora do zip). Gera evidências em docs/homologacao/NI2-233-EVIDENCIAS/.
# Uso: bash tests/homologacao/run_ni2_233.sh
# Variáveis: MYSQL_SOCK (padrão /tmp/m57v29/sock), MYSQL_PORT (33057), NI2_233_DB (ni2_233_run_test; precisa ter "test"),
#            PORT (8236), E2E_AXE (axe.min.js; sem ele as checagens axe saem NAO TESTADO), NODE_PATH com playwright.
# O banco NI2_233_DB é APAGADO e recriado a cada execução: use só um banco descartável de teste. Só dados TESTE.
set -uo pipefail
ROOT="$(cd "$(dirname "$0")/../.." && pwd)"; cd "$ROOT"
SOCK="${MYSQL_SOCK:-/tmp/m57v29/sock}"; MPORT="${MYSQL_PORT:-33057}"; DB="${NI2_233_DB:-ni2_233_run_test}"; PORT="${PORT:-8236}"
case "$DB" in *test*) ;; *) echo "banco sem 'test' no nome: recusado"; exit 2;; esac
EV="$ROOT/docs/homologacao/NI2-233-EVIDENCIAS"; RAW="$(mktemp -d)"; mkdir -p "$EV/capturas"
export APP_ENV=local APP_TIMEZONE=America/Sao_Paulo APP_KEY='base64:dGVzdGVrZXl0ZXN0ZWtleXRlc3Rla2V5dGVzdGVrZXk='
export DB_CONNECTION=mysql DB_HOST=127.0.0.1 DB_PORT="$MPORT" DB_DATABASE="$DB" DB_USERNAME=root DB_PASSWORD= APP_URL="http://127.0.0.1:$PORT"
san() { sed -e "s#$ROOT#<repo>#g" -e "s#$RAW#<tmp>#g" -e 's#base64:[A-Za-z0-9+/=]*#<APP_KEY>#g' -e "s#$HOME#<home>#g"; }
{
  echo "# NI2-233 — ambiente da execução"; echo "data: $(date '+%Y-%m-%d %H:%M:%S %Z')"
  echo "branch: $(git rev-parse --abbrev-ref HEAD)"; echo "sha: $(git rev-parse HEAD)$(git diff --quiet HEAD || echo ' (+ alterações não commitadas)')"
  echo "php: $(php -r 'echo PHP_VERSION;')"; echo "node: $(node -v)"
  echo "mysql: $(php -r '$p=new PDO("mysql:unix_socket='"$SOCK"'","root","");echo $p->query("select version()")->fetchColumn();')"
  echo "playwright: $(cd tests/e2e && node -e 'console.log(require("playwright/package.json").version)' 2>/dev/null || echo '?')"
  echo "axe-core: $( [ -n "${E2E_AXE:-}" ] && node -e 'console.log(require(process.argv[1]).version)' "$(dirname "$E2E_AXE")/package.json" 2>/dev/null || echo 'não informado (checagens axe = NAO TESTADO)')"
  echo "banco: MySQL $DB (descartável, recriado nesta execução) · APP_TIMEZONE=$APP_TIMEZONE · servidor php -S 127.0.0.1:$PORT tests/_v54_router.php"
  echo "pilha: PHP MVC próprio (public/index.php), NÃO Laravel 13 como diz a história"
} | san > "$EV/ambiente.txt"
rc=0
echo "== 1/5 banco MySQL descartável $DB (migrações até a 84)"
php -r '$p=new PDO("mysql:unix_socket='"$SOCK"'","root","");$p->exec("DROP DATABASE IF EXISTS `'"$DB"'`");$p->exec("CREATE DATABASE `'"$DB"'` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");' || exit 1
php bin/migrate.php > "$RAW/migrate.log" 2>&1 || { cat "$RAW/migrate.log"; exit 1; }
php -r '$p=new PDO("mysql:unix_socket='"$SOCK"';dbname='"$DB"'","root","");echo "migrações: ",$p->query("select count(*) from migrations")->fetchColumn()," · última: ",$p->query("select migration from migrations order by id desc limit 1")->fetchColumn(),"\n";' | tee -a "$EV/ambiente.txt"
echo "== 2/5 dados TESTE"
php tests/mysql/ni2_233_e2e_seed.php "$SOCK" "$DB" > "$RAW/seed.json" || exit 1
echo "== 3/5 E2E pela interface real (390/768/1280/1440)"
php -d opcache.enable=0 -S 127.0.0.1:$PORT -t public tests/_v54_router.php > "$RAW/server.log" 2>&1 & SRV=$!
trap 'kill $SRV 2>/dev/null' EXIT
for i in $(seq 1 50); do curl -s -o /dev/null "http://127.0.0.1:$PORT/login" && break; sleep 0.2; done
rm -f "$EV"/capturas/*.png
( cd tests/e2e/scenarios && E2E_SHOTS="$EV/capturas" E2E_OUT="$RAW" node p233.js "http://127.0.0.1:$PORT" "mysql:$SOCK:$DB" ) > "$RAW/e2e.log" 2>&1; e2e=$?
# capturas reduzidas para 256 cores (continuam PNG legíveis; só para caber no repositório)
python3 -c 'import sys,glob
from PIL import Image
for f in glob.glob(sys.argv[1]+"/*.png"): Image.open(f).convert("RGB").quantize(256).save(f, optimize=True)' "$EV/capturas" 2>/dev/null || true
san < "$RAW/e2e.log" > "$EV/e2e-mysql.log"; tail -3 "$EV/e2e-mysql.log"; [ $e2e -eq 0 ] || rc=1
echo "== 4/5 teste PHP no MySQL (jornada, isolamento, concorrência real, fuso)"
NI2_233_MYSQL_PORT="$MPORT" php tests/mysql/ni2_233_prontuario_mysql_test.php "$SOCK" "$DB" > "$RAW/php.log" 2>&1; ph=$?
san < "$RAW/php.log" > "$EV/php-mysql.log"; tail -1 "$EV/php-mysql.log"; [ $ph -eq 0 ] || rc=1
echo "== 5/5 regressões (SQLite) e resultado do banco"
php tests/v5423_ni2_233_homologacao_test.php 2>&1 | san > "$EV/php-regressoes.log"; tail -1 "$EV/php-regressoes.log"; grep -q ' 0 FAIL' "$EV/php-regressoes.log" || rc=1
php -r '$p=new PDO("mysql:unix_socket='"$SOCK"';dbname='"$DB"'","root","");
foreach (["pacientes TESTE" => "SELECT COUNT(*) FROM patients WHERE name LIKE \"%TESTE%\"", "pacientes sem TESTE no nome" => "SELECT COUNT(*) FROM patients WHERE name NOT LIKE \"%TESTE%\"",
 "registros clínicos" => "SELECT COUNT(*) FROM clinical_records", "versões superadas (retificadas)" => "SELECT COUNT(*) FROM clinical_records WHERE is_retracted = 1",
 "bifurcações (2+ versões superando o mesmo registro)" => "SELECT COUNT(*) FROM (SELECT supersedes_id FROM clinical_records WHERE supersedes_id IS NOT NULL GROUP BY supersedes_id HAVING COUNT(*) > 1) x",
 "versões duplicadas por paciente" => "SELECT COUNT(*) FROM (SELECT patient_id, version FROM clinical_records GROUP BY patient_id, version HAVING COUNT(*) > 1) x",
 "registros com autor fora da clínica" => "SELECT COUNT(*) FROM clinical_records r JOIN professionals p ON p.id = r.professional_id WHERE NOT EXISTS (SELECT 1 FROM clinic_members m WHERE m.user_id = p.user_id AND m.clinic_id = r.clinic_id)",
 "anamneses" => "SELECT COUNT(*) FROM anamneses", "e-mails fora de @teste.invalid" => "SELECT COUNT(*) FROM users WHERE email NOT LIKE \"%@teste.invalid\""] as $k => $s) echo str_pad($k, 52), ": ", $p->query($s)->fetchColumn(), "\n";' > "$EV/resultado-banco.txt" 2>&1
cat "$EV/resultado-banco.txt"
echo "comandos: bash tests/homologacao/run_ni2_233.sh  (E2E_AXE=$( [ -n "${E2E_AXE:-}" ] && echo '<axe.min.js>' || echo '-'))" >> "$EV/ambiente.txt"
echo; [ $rc -eq 0 ] && echo "HOMOLOGAÇÃO AUTOMATIZADA NI2-233: sem falhas" || echo "HOMOLOGAÇÃO AUTOMATIZADA NI2-233: HÁ FALHAS (ver $EV)"
exit $rc
