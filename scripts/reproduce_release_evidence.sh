#!/usr/bin/env bash
# v54.46 / CR2-NI2-003 (NI2-694, F04): gera a evidência do portão de forma reproduzível A PARTIR DO REPO
# (não do zip do app, que vai sem tests/).
#   git clone <repo> && cd <repo> && git checkout <SHA citado no PACOTE>
#   (cd tests/e2e && npm ci && npx playwright install chromium)   # só para a parte de navegador
#   bash scripts/reproduce_release_evidence.sh [pasta-destino] [zip-opcional]
# Saída: results.tsv, report.md, report.json, git-sha.txt, commands.txt, env.txt e (se zip) zip.sha256.
set -u
ROOT="$(cd "$(dirname "$0")/.." && pwd)"; cd "$ROOT"
DEST="${1:-$ROOT/release-evidence/local}"; ZIP="${2:-}"
mkdir -p "$DEST"
SHA="$(git rev-parse HEAD 2>/dev/null || echo sem-git)"
DIRTY="$(git status --porcelain 2>/dev/null | grep -v '^??' | wc -l)"
bash tests/run_release_gate.sh > "$DEST/gate-stdout.txt" 2>&1; CODE=$?
REP="$(grep -E '^Relatório: ' "$DEST/gate-stdout.txt" | tail -1 | sed 's/^Relatório: //')"
for f in results.tsv report.md report.json; do [ -f "$REP/$f" ] && cp "$REP/$f" "$DEST/"; done
{ echo "git_head=$SHA"; echo "tracked_changes_not_committed=$DIRTY"; echo "gate_exit_code=$CODE"; } > "$DEST/git-sha.txt"
{ echo "php $(php -r 'echo PHP_VERSION;')"; echo "node $(node -v 2>/dev/null || echo ausente)"; uname -srm; date -Iseconds; } > "$DEST/env.txt"
cat > "$DEST/commands.txt" <<CMD
git checkout $SHA
(cd tests/e2e && npm ci && npx playwright install chromium)
bash tests/run_release_gate.sh            # PHP + navegador, SQLite descartável, dados TESTE
GATE_MYSQL=1 MYSQL_SOCKET=/caminho/mysqld.sock bash tests/run_release_gate.sh --php   # opcional, MySQL real
bash scripts/build_hostgator_zip.sh clyonutri-v54-46 v54-46
sha256sum release/clyonutri-v54-46.zip
CMD
if [ -n "$ZIP" ] && [ -f "$ZIP" ]; then sha256sum "$ZIP" | awk '{print $1"  "FILENAME}' FILENAME="$(basename "$ZIP")" > "$DEST/zip.sha256"; fi
awk -F'\t' '{p+=$4;f+=$5} END{printf "total PASS=%d FAIL=%d linhas=%d\n",p,f,NR}' "$DEST/results.tsv" | tee "$DEST/totals.txt"
exit $CODE
