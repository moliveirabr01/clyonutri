#!/bin/bash
# Reteste externo NI2-426 (NI2-427/428/429) + v10 (www, /health mínimo, diagnóstico). Rode no Mac, fora do servidor:
#   bash reteste-ni2-426.sh [https://clyonutri.com] [versao-implantada] > evidencia-ni2-426.txt
# Só GET/HEAD/OPTIONS e um POST com dados FICTÍCIOS por HTTP. Nenhuma credencial real.
# Cookies, tokens CSRF e nonces são mascarados na saída.
BASE="${1:-https://clyonutri.com}"; VER="${2:-v10}"
HOST="${BASE#https://}"; HOST="${HOST%%/*}"; HTTP="${HTTP_BASE:-http://$HOST}"; EXPECT="${EXPECT_HTTPS:-https://$HOST}"
UA="reteste-ni2-426"
san(){ sed -E -e 's/(set-cookie: *[^=]+=)[^;]*/\1<mascarado>/I' -e "s/'nonce-[A-Za-z0-9_+\/=-]+'/'nonce-<mascarado>'/g" -e 's/(_csrf_token" value=")[^"]*/\1<mascarado>/' -e 's/\r$//'; }
hdr(){ grep -iE '^(HTTP/|location|strict-transport-security|content-security-policy|x-frame-options|x-content-type-options|referrer-policy|permissions-policy|content-type|access-control-allow-origin|set-cookie|server):' | san; }
ok=0; nok=0
res(){ if [ "$1" = 1 ]; then ok=$((ok+1)); echo "  RESULTADO: OK - $2"; else nok=$((nok+1)); echo "  RESULTADO: FALHA - $2"; fi; }
req(){ # metodo url [args...]
  local m=$1 u=$2; shift 2
  if [ "$m" = HEAD ]; then curl -sS ${CURL_INSECURE:+-k} -I -A "$UA" -m 20 "$@" "$u"; else curl -sS ${CURL_INSECURE:+-k} -X "$m" -D - -o /tmp/ni2426_body -A "$UA" -m 20 "$@" "$u"; fi 2>&1
}
echo "# Reteste NI2-426 — $(date '+%Y-%m-%d %H:%M:%S %Z') — alvo $BASE — versão declarada: $VER"
echo "# Máquina: $(uname -s) curl $(curl --version | head -1 | awk '{print $2}')"
echo
echo "## NI2-427 — HTTP deve redirecionar (GET/HEAD) e recusar POST"
for p in / /login "/login?teste=https"; do for m in GET HEAD; do
  echo "### $m $HTTP$p"; H=$(req $m "$HTTP$p"); echo "$H" | hdr
  st=$(echo "$H" | awk 'toupper($1) ~ /^HTTP/ {print $2; exit}'); loc=$(echo "$H" | grep -i '^location:' | head -1 | awk '{print $2}' | tr -d '\r')
  body=""; [ $m = GET ] && body=$(cat /tmp/ni2426_body 2>/dev/null)
  [ "$st" = 301 ] && [ "$loc" = "$EXPECT$p" ] && ! echo "$body" | grep -qi '<form' && r=1 || r=0
  res $r "301 para $EXPECT$p, sem HTML de login por HTTP"
done; done
echo "### POST $HTTP/login (dados fictícios)"
H=$(req POST "$HTTP/login" --data 'email=ficticio%40example.invalid&password=nao-e-senha&_csrf_token=ficticio'); echo "$H" | hdr
st=$(echo "$H" | awk 'toupper($1) ~ /^HTTP/ {print $2; exit}')
[ "$st" = 403 ] && ! grep -qi '<form' /tmp/ni2426_body && r=1 || r=0
res $r "POST por HTTP recusado (403) sem renderizar login. Obs.: o corpo já trafegou; o servidor não consegue protegê-lo — a proteção é nunca servir o formulário por HTTP + HSTS."
echo "### GET $HTTP/login com X-Forwarded-Proto: https forjado"
st=$(curl -sS -o /dev/null -w '%{http_code}' -m 20 -H 'X-Forwarded-Proto: https' "$HTTP/login"); echo "  status $st"; [ "$st" = 301 ] && r=1 || r=0; res $r "cabeçalho forwarded forjado não evita o redirect"
echo "### Cadeia limitada (seguindo redirects, máx. 3)"
curl -sS -o /dev/null -L --max-redirs 3 -w '  final=%{url_effective} status=%{http_code} redirects=%{num_redirects}\n' -m 30 "$HTTP/login?teste=https"
echo
echo "## NI2-428/429 — cabeçalhos por HTTPS"
for p in / /login /dashboard /admin /api/v1/patient/home /rota-inexistente-ni2-426 /assets/css/clinical-botanical.css; do for m in GET HEAD; do
  echo "### $m $BASE$p"; H=$(req $m "$BASE$p" -H 'Origin: https://example.org'); echo "$H" | hdr
  st=$(echo "$H" | awk 'toupper($1) ~ /^HTTP/ {print $2; exit}')
  n_hsts=$(echo "$H" | grep -ci '^strict-transport-security:'); hsts=$(echo "$H" | grep -i '^strict-transport-security:' | head -1 | tr -d '\r')
  n_csp=$(echo "$H" | grep -ci '^content-security-policy:'); csp=$(echo "$H" | grep -i '^content-security-policy:' | head -1)
  xfo=$(echo "$H" | grep -i '^x-frame-options:' | tr -d '\r' | awk '{print $2}'); nos=$(echo "$H" | grep -ci '^x-content-type-options: *nosniff')
  case $p in /assets/*) [ "$nos" = 1 ] && [ "$xfo" = DENY ] && r=1 || r=0; res $r "estático: nosniff + X-Frame-Options (HSTS só nas respostas do PHP, por desenho)";;
  *) [ "$n_hsts" = 1 ] && echo "$hsts" | grep -qE 'max-age=[1-9][0-9]*' && ! echo "$hsts" | grep -qi 'includesubdomains\|preload' && r=1 || r=0; res $r "HSTS único, max-age>0, sem includeSubDomains/preload ($st)"
     [ "$n_csp" = 1 ] && echo "$csp" | grep -q "frame-ancestors 'none'" && [ "$xfo" = DENY ] && [ "$nos" = 1 ] && r=1 || r=0; res $r "CSP efetiva única com frame-ancestors 'none', X-Frame-Options DENY, nosniff";;
  esac
  case $p in /api/*) [ $m = GET ] && { grep -q '"error"' /tmp/ni2426_body && ! echo "$H" | grep -qi '^access-control-allow-origin' && r=1 || r=0; res $r "API: $st JSON, origem externa sem Allow-Origin"; };;
  /dashboard|/admin) loc=$(echo "$H" | grep -i '^location:' | awk '{print $2}' | tr -d '\r'); [ "$st" = 302 ] && [ "$loc" = /login ] && r=1 || r=0; res $r "sem sessão -> 302 /login";;
  /|/login) [ "$st" = 200 ] && r=1 || r=0; res $r "$m retorna 200 (HEAD não deve mais dar 404)";;
  esac
done; done
echo "### Diretivas da CSP de /login"
curl -sS ${CURL_INSECURE:+-k} -I -m 20 "$BASE/login" | grep -i '^content-security-policy:' | san | tr ';' '\n' | sed 's/^/   /'
echo
echo "## v10 — www, /health mínimo e diagnóstico"
APEX="${HOST#www.}"; WWW="www.$APEX"
for u in "http://$WWW/" "http://$WWW/login" "https://$WWW/" "https://$WWW/login" "http://$APEX/health"; do
  p="/${u#*://*/}"; [ "$p" = "/$u" ] && p=/
  echo "### GET $u"; H=$(req GET "$u"); echo "$H" | hdr
  st=$(echo "$H" | awk 'toupper($1) ~ /^HTTP/ {print $2; exit}'); loc=$(echo "$H" | grep -i '^location:' | head -1 | awk '{print $2}' | tr -d '\r')
  [ "$st" = 301 ] && [ "$loc" = "https://$APEX$p" ] && r=1 || r=0; res $r "301 para https://$APEX$p"
done
echo "### GET $BASE/health"; H=$(req GET "$BASE/health"); echo "$H" | hdr; b=$(cat /tmp/ni2426_body); echo "  corpo: $b"
st=$(echo "$H" | awk 'toupper($1) ~ /^HTTP/ {print $2; exit}')
[ "$st" = 200 ] && [ "$b" = '{"status":"ok"}' ] && r=1 || r=0; res $r "/health responde só {\"status\":\"ok\"} (sem serviço, versão ou horário)"
echo "$H" | grep -qi '^cache-control: *no-store' && r=1 || r=0; res $r "/health com Cache-Control: no-store"
echo "### GET $BASE/up"; H=$(req GET "$BASE/up"); b=$(cat /tmp/ni2426_body); echo "  corpo: $b"
echo "$b" | grep -Eq '^\{"status":"(ok|unavailable)"\}$' && r=1 || r=0; res $r "/up mínimo (sem banco, versão ou horário)"
for f in _diag_99ba259d91b45cec0606e89f.php test_mail.php phpinfo.php info.php .env bin/diagnostico.php vendor/autoload.php; do
  st=$(curl -sS ${CURL_INSECURE:+-k} -o /tmp/ni2426_body -w '%{http_code}' -m 20 "$BASE/$f")
  [ "$st" = 404 ] || [ "$st" = 403 ] && r=1 || r=0; res $r "/$f -> $st (esperado 404/403)"
done
st=$(curl -sS ${CURL_INSECURE:+-k} -o /tmp/ni2426_body -w '%{http_code}' -m 20 "$BASE/rota-inexistente-v10")
grep -Eqi 'stack trace|\.php|sqlstate|/home[0-9]*/' /tmp/ni2426_body && r=0 || r=1; res $r "página de erro sem stack trace/caminho ($st)"
echo
echo "## Totais: $ok OK, $nok FALHA"
echo "## Pendentes manuais: (1) abrir scripts/teste-iframe-ni2-426.html no navegador e ver que o login NÃO aparece no quadro;"
echo "##   (2) HSTS no navegador: após abrir $BASE uma vez, digitar http://$HOST e ver no DevTools > Network '307 Internal Redirect' (upgrade local);"
echo "##   (3) login/logout com conta de teste por HTTPS e DevTools > Console sem erros de CSP."
