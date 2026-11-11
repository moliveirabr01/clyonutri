#!/usr/bin/env bash
# v54.46 / CR-NI2-009 (NI2-723): etapa MySQL opcional do portão. Ativada por GATE_MYSQL=1.
# Requer: cliente `mysql` + servidor MySQL 5.7/8 ou MariaDB local, descartável, com socket em MYSQL_SOCKET.
#   GATE_MYSQL=1 MYSQL_SOCKET=/run/mysqld/mysqld.sock bash tests/run_release_gate.sh --php
#   bash tests/mysql/run_mysql_stage.sh /run/mysqld/mysqld.sock     # só a etapa MySQL
# Sem servidor/socket: imprime "BLOQUEADO <motivo>" e sai 3. NUNCA imprime PASS sem ter rodado.
# Os smokes criam bancos clyonutri_smoke_* descartáveis e apagam o que gravaram; o nome do banco de
# produção é recusado pelos próprios testes.
set -u
ROOT="$(cd "$(dirname "$0")/../.." && pwd)"
SOCK="${1:-${MYSQL_SOCKET:-}}"
if ! command -v mysql >/dev/null 2>&1; then echo "BLOQUEADO cliente mysql ausente nesta máquina"; exit 3; fi
if [ -z "$SOCK" ] || [ ! -S "$SOCK" ]; then echo "BLOQUEADO MYSQL_SOCKET não informado ou inexistente (${SOCK:-vazio})"; exit 3; fi
VER="$(mysql --socket="$SOCK" -uroot -N -e 'SELECT VERSION()' 2>/dev/null || true)"
[ -n "$VER" ] || { echo "BLOQUEADO servidor não respondeu em $SOCK"; exit 3; }
echo "MySQL/MariaDB: $VER"
rc=0
for t in ni2_635_mysql_smoke.php ni2_663_mysql_smoke.php v5421_materials_mysql_smoke.php ni2_220_anamnese_mysql_smoke.php; do
  if php "$ROOT/tests/mysql/$t" "$SOCK" >"/tmp/gate_mysql_$t.log" 2>&1; then echo "OK $t"; else echo "FALHA $t (log /tmp/gate_mysql_$t.log)"; rc=1; fi
done
exit $rc
