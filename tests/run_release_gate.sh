#!/usr/bin/env bash
# NI2-632: portão de release. Roda os testes de domínio (PHP) e as jornadas no navegador (Playwright) em
# bancos SQLite descartáveis com dados TESTE, e decide GO / NO-GO.
#
#   bash tests/run_release_gate.sh            # tudo
#   bash tests/run_release_gate.sh --php      # só PHP
#   bash tests/run_release_gate.sh --browser  # só navegador
#   E2E_ONLY=tenant,p5411 bash tests/run_release_gate.sh --browser
#
# Regras:
#  - nunca usa MySQL nem o .env: cada cenário sobe um servidor local (php -S) com o próprio arquivo SQLite;
#  - qualquer falha = NO-GO (código 1), salvo as falhas já conhecidas listadas em tests/e2e/known_failures.txt;
#  - sem PHP, Node, Playwright ou Chromium, a parte correspondente sai como "NÃO TESTADO" e o resultado é
#    INCOMPLETO (código 2), nunca GO;
#  - relatório (Markdown + JSON), saídas e capturas em storage/e2e-report/<versão>-<data>/ (fora do zip).
set -u
ROOT="$(cd "$(dirname "$0")/.." && pwd)"
cd "$ROOT"
MODE="${1:-all}"
VERSION="$(php -r '$j=json_decode(@file_get_contents("RELEASE_INFO.json"),true);echo $j["version"]??"sem-versao";' 2>/dev/null || echo sem-versao)"
COMMIT="$(git rev-parse --short HEAD 2>/dev/null || echo sem-git)"
STAMP="$(date +%Y%m%d-%H%M%S)"
OUT="$ROOT/storage/e2e-report/${VERSION}-${STAMP}"
mkdir -p "$OUT"
SQ="$ROOT/tests/e2e/lib/sq.php"
KNOWN="$ROOT/tests/e2e/known_failures.txt"
RESULTS="$OUT/results.tsv"; : > "$RESULTS"
# NI2-727: token local aleatório para o webhook de TESTE do cenário p5448. Não é chave de API; não é impresso nem gravado.
E2E_ASAAS_TOKEN="${E2E_ASAAS_TOKEN:-$(php -r 'echo bin2hex(random_bytes(24));' 2>/dev/null || echo '')}"
export E2E_ASAAS_TOKEN
record() { printf '%s\t%s\t%s\t%s\t%s\n' "$1" "$2" "$3" "$4" "$5" >> "$RESULTS"; } # tipo nome status pass fail
known_fail() { grep -E "^$1[[:space:]]+[0-9]+" "$KNOWN" 2>/dev/null | awk '{print $2}'; }

# Cenários de navegador: nome | arquivo | seed | precisa do caminho do banco
SCENARIOS=(
  "v541|v541.js|v541|0"
  "p607|p607.js|rascunho|1"
  "p609|p609.js|rascunho|1"
  "p610|p610.js|rascunho|1"
  "p613c|p613c.js|rascunho|1"
  "p5410|p5410.js|versoes|0"
  "p5411|p5411.js|equipe|0"
  "p5412|p5412.js|versoes|0"
  "p5413|p5413.js|contradicao|1"
  "p5414|p5414.js|versoes|1"
  "p5415|p5415.js|versoes|0"
  "p5416|p5416.js|jornadas|1"
  "p5417|p5417.js|versoes|1"
  "p5418|p5418.js|protocolos|1"
  "p5419|p5419.js|protocolos2|1"
  "p5420|p5420.js|academy|1"
  "p5421|p5421.js|materiais|1"
  "p5423|p5423.js|laminas|1"
  "p5427|p5427.js|teleconsulta|1"
  "p5427p|p5427p.js|prontuario_tele|1"
  "p233|p233.js|homologacao|1"
  "p220|p220.js|ni2_220|1"
  "tenant|tenant.js|tenant|1"
  "p5428|p5428.js|tenant|1"
  "p5447|p5447.js|tenant|1"
  "p5448|p5448.js|tenant|1"
  "p5449|p5449.js|tenant|1"
  "p5456|p5456.js|painel|1"
  "p5457|p5457.js|jornadas|1"
  "ni2815|ni2815.js|ni2815|1"
)

run_php() {
  if ! command -v php >/dev/null; then record php "(todos)" NAO_TESTADO 0 0; return; fi
  for t in tests/v4*_test.php tests/v4*_smoke.php tests/v5*_test.php; do
    [ -f "$t" ] || continue
    name="$(basename "$t" .php)"
    php -d opcache.enable=0 "$t" > "$OUT/php-$name.log" 2>&1; rc=$?
    line="$(grep -Eo '[0-9]+ PASS, [0-9]+ FAIL' "$OUT/php-$name.log" | tail -1)"
    p="$(echo "$line" | awk '{print $1}')"; f="$(echo "$line" | awk '{print $3}')"
    p="${p:-0}"; f="${f:-0}"
    if [ -z "$line" ]; then st=ERRO
    elif [ "$f" = "0" ] && [ "$rc" = "0" -o "$p" -gt 0 ]; then st=OK
    elif [ "$f" = "$(known_fail "$name")" ]; then st=CONHECIDA
    else st=FALHA; fi
    record php "$name" "$st" "$p" "$f"
  done
  # Suítes da v54.17/v54.18 e da NI2-684/NI2-685 (outra entrega): saída JSON com "pass" por verificação; SQLite em memória
  for t in tests/adaptive_protocols.php tests/ux_workflows.php tests/ux19.php tests/ni2_684.php tests/ni2_685.php tests/ni2_688.php tests/ni2_695_gate.php tests/ni2_695_identity.php tests/ni2_695_integrity.php tests/ni2_695_journeys.php tests/ni2_703.php; do
    [ -f "$t" ] || continue
    name="$(basename "$t" .php)"
    ADAPTIVE_FIXTURE_FILE="$OUT/fixture-$name.json" php -d opcache.enable=0 "$t" > "$OUT/php-$name.log" 2>&1; rc=$?
    pf="$(php -r '$j=json_decode((string)file_get_contents($argv[1]),true); $r=is_array($j)?($j["results"]??$j["tests"]??$j["checks"]??[]):[]; if(!$r){echo "";exit;} $p=count(array_filter($r,fn($x)=>!empty($x["pass"]))); echo $p." ".(count($r)-$p);' "$OUT/php-$name.log")"
    p="$(echo "$pf" | awk '{print $1}')"; f="$(echo "$pf" | awk '{print $2}')"
    if [ -z "$pf" ]; then st=ERRO; p=0; f=0
    elif [ "$f" = "0" ] && [ "$rc" = "0" ]; then st=OK
    else st=FALHA; fi
    record php "$name" "$st" "$p" "$f"
  done
}

browser_ready() {
  command -v node >/dev/null || return 1
  (cd "$ROOT/tests/e2e" && node -e "require('playwright').chromium.launch().then(b=>b.close()).catch(()=>process.exit(1))") >/dev/null 2>&1
}

free_port() { php -r '$s=stream_socket_server("tcp://127.0.0.1:0");$n=stream_socket_get_name($s,false);echo substr($n,strrpos($n,":")+1);'; }

run_browser() {
  if ! browser_ready; then
    for s in "${SCENARIOS[@]}"; do IFS='|' read -r n _ _ _ <<< "$s"; record navegador "$n" NAO_TESTADO 0 0; done
    return
  fi
  for s in "${SCENARIOS[@]}"; do
    IFS='|' read -r name file seed needdb <<< "$s"
    if [ -n "${E2E_ONLY:-}" ] && [[ ",$E2E_ONLY," != *",$name,"* ]]; then continue; fi
    db="$ROOT/storage/e2e-$name.sqlite"
    if ! php "$ROOT/tests/e2e/lib/seed.php" "$db" "$seed" > "$OUT/seed-$name.log" 2>&1; then record navegador "$name" ERRO 0 0; continue; fi
    port="$(free_port)"
    ( cd "$ROOT" && DB_CONNECTION=sqlite DB_DATABASE="$db" APP_ENV=local APP_URL="http://127.0.0.1:$port" \
      APP_KEY=base64:dGVzdGVrZXl0ZXN0ZWtleXRlc3Rla2V5dGVzdGVrZXk= CLINICAL_ENCRYPTION_KEY=test-clinical-key-v5445-not-for-prod MAIL_DRIVER=log APP_TIMEZONE=America/Sao_Paulo MATERIALS_STORAGE_DIR="$OUT/materials-$name" ACADEMY_HANDOUTS_DIR="$OUT/laminas-$name" \
      ASAAS_ENV=sandbox ASAAS_WEBHOOK_TOKEN="${E2E_ASAAS_TOKEN:-}" \
      exec php -d opcache.enable=0 -S "127.0.0.1:$port" -t public tests/_v54_router.php ) > "$OUT/server-$name.log" 2>&1 &
    spid=$!
    for _ in $(seq 1 50); do php -r "exit(@fsockopen('127.0.0.1',$port)?0:1);" && break; sleep 0.1; done
    args=("http://127.0.0.1:$port"); [ "$needdb" = 1 ] && args+=("$db")
    ( cd "$ROOT/tests/e2e/scenarios" && E2E_SQ="$SQ" E2E_OUT="$OUT" E2E_ASAAS_TOKEN="${E2E_ASAAS_TOKEN:-}" timeout 300 node "$file" "${args[@]}" ) > "$OUT/browser-$name.log" 2>&1; rc=$?
    kill "$spid" 2>/dev/null; wait "$spid" 2>/dev/null
    p="$(grep -c '  PASS  ' "$OUT/browser-$name.log")"; f="$(grep -c '  FAIL  ' "$OUT/browser-$name.log")"
    # exceção não tratada ou saída diferente de zero nunca conta como OK (ex.: TimeoutError no meio do roteiro)
    if [ "$f" = 0 ] && [ "$p" -gt 0 ] && [ "$rc" = 0 ] && ! grep -qE 'Error:|triggerUncaughtException' "$OUT/browser-$name.log"; then st=OK
    elif [ "$f" -gt 0 ]; then st=FALHA
    else st=ERRO; fi
    record navegador "$name" "$st" "$p" "$f"
    rm -f "$db" "$db"-*
  done
}

# v54.46 / CR-NI2-009: etapa MySQL real, opcional (GATE_MYSQL=1). Sem servidor = BLOQUEADO (nunca PASS fingido).
# BLOQUEADO aparece no relatório e não vira GO "MySQL"; o veredito GO continua valendo só para SQLite+navegador.
run_mysql() {
  [ "${GATE_MYSQL:-0}" = "1" ] || return 0
  local log="$OUT/mysql_stage.log"
  bash "$ROOT/tests/mysql/run_mysql_stage.sh" >"$log" 2>&1; local rc=$?
  local okc; okc="$(grep -c '^OK ' "$log")"; local flc; flc="$(grep -c '^FALHA ' "$log")"
  if [ $rc -eq 3 ]; then record mysql mysql_stage BLOQUEADO 0 0; echo "mysql_stage: $(head -1 "$log")"
  elif [ $rc -eq 0 ]; then record mysql mysql_stage OK "$okc" 0
  else record mysql mysql_stage FALHA "$okc" "$flc"; fi
}

case "$MODE" in
  --php) run_php; run_mysql ;;
  --browser) run_browser ;;
  --mysql) GATE_MYSQL=1 run_mysql ;;
  *) run_php; run_mysql; run_browser ;;
esac

bad="$(awk -F'\t' '$3=="FALHA"||$3=="ERRO"' "$RESULTS" | wc -l)"
untested="$(awk -F'\t' '$3=="NAO_TESTADO"' "$RESULTS" | wc -l)"
if [ "$bad" -gt 0 ]; then VERDICT="NO-GO"; CODE=1
elif [ "$untested" -gt 0 ]; then VERDICT="INCOMPLETO (há partes não testadas)"; CODE=2
else VERDICT="GO"; CODE=0; fi

{
  echo "# Portão de release ${VERSION}"
  echo
  echo "- **Resultado:** ${VERDICT}"
  echo "- **Versão:** ${VERSION} · commit ${COMMIT} · $(date '+%d/%m/%Y %H:%M')"
  echo "- **Ambiente:** local, SQLite descartável por cenário, dados TESTE, e-mail desligado (MAIL_DRIVER=log)"
  echo "- **Ferramentas:** PHP $(php -r 'echo PHP_VERSION;' 2>/dev/null || echo ausente) · Node $(node -v 2>/dev/null || echo ausente)"
  echo
  echo "| Tipo | Teste | Situação | Passou | Falhou |"
  echo "|---|---|---|---|---|"
  awk -F'\t' '{printf "| %s | %s | %s | %s | %s |\n",$1,$2,$3,$4,$5}' "$RESULTS"
  echo
  if grep -q $'\tBLOQUEADO\t' "$RESULTS"; then echo "BLOQUEADO = etapa MySQL pedida (GATE_MYSQL=1) mas sem servidor MySQL disponível; NÃO conta como aprovada."; echo; fi
  echo "CONHECIDA = falha já registrada em tests/e2e/known_failures.txt, com a mesma contagem. Qualquer outra falha bloqueia."
} > "$OUT/report.md"
php -r '$r=[];foreach(file($argv[1],FILE_IGNORE_NEW_LINES|FILE_SKIP_EMPTY_LINES) as $l){[$t,$n,$s,$p,$f]=explode("\t",$l);$r[]=compact("t","n","s")+["pass"=>(int)$p,"fail"=>(int)$f];}
echo json_encode(["version"=>$argv[2],"commit"=>$argv[3],"verdict"=>$argv[4],"results"=>$r],JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE);' "$RESULTS" "$VERSION" "$COMMIT" "$VERDICT" > "$OUT/report.json"
cat "$OUT/report.md"
echo
echo "Relatório: $OUT"
exit $CODE
