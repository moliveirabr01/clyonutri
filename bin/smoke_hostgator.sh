#!/usr/bin/env bash
# v54.46 / CR2-NI2-002 (NI2-718): smoke SOMENTE LEITURA pós-deploy/pós-restore na HostGator.
#   BASE_URL=https://seu-dominio bash bin/smoke_hostgator.sh
# Não envia login, não grava nada. O login real (usuário TESTE) é passo manual do checklist.
set -u
BASE="${BASE_URL:-${1:-}}"
[ -n "$BASE" ] || { echo "uso: BASE_URL=https://dominio bash bin/smoke_hostgator.sh"; exit 2; }
BASE="${BASE%/}"; fail=0
chk() { if [ "$2" = "1" ]; then echo "OK    $1"; else echo "FALHA $1"; fail=1; fi; }
h="$(curl -fsS --max-time 15 "$BASE/health" 2>/dev/null || true)"
chk "/health responde {\"status\":\"ok\"}" "$([ "$h" = '{"status":"ok"}' ] && echo 1 || echo 0)"
code="$(curl -s -o /tmp/smoke_login.html -w '%{http_code}' --max-time 15 "$BASE/login")"
chk "/login HTTP 200" "$([ "$code" = 200 ] && echo 1 || echo 0)"
chk "/login tem formulário com CSRF" "$(grep -qi 'name="_token"\|csrf' /tmp/smoke_login.html && echo 1 || echo 0)"
hdr="$(curl -sI --max-time 15 "$BASE/login")"
chk "header Content-Security-Policy" "$(echo "$hdr" | grep -qi '^content-security-policy' && echo 1 || echo 0)"
chk "header X-Content-Type-Options" "$(echo "$hdr" | grep -qi '^x-content-type-options' && echo 1 || echo 0)"
for p in /.env /storage/logs/app.log /database/instalacao_mysql8.sql /.git/config; do
  c="$(curl -s -o /dev/null -w '%{http_code}' --max-time 15 "$BASE$p")"
  chk "$p bloqueado (HTTP $c)" "$([ "$c" != 200 ] && echo 1 || echo 0)"
done
[ $fail -eq 0 ] && echo "SMOKE HOSTGATOR OK" || echo "SMOKE HOSTGATOR FALHOU"
exit $fail
