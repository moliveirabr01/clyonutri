#!/usr/bin/env bash
set -euo pipefail
ROOT="$(cd "$(dirname "$0")/.." && pwd)"
cd "$ROOT"
: "$PLAYWRIGHT_MODULE" "$AXE_MODULE"
EVIDENCE="$ROOT/docs/delivery/ni2-703/evidence/continuous"
if [ -n "$(printenv UX_EVIDENCE_DIR)" ]; then EVIDENCE="$UX_EVIDENCE_DIR"; fi
mkdir -p "$EVIDENCE"
EVIDENCE="$(cd "$EVIDENCE" && pwd)"
WORK="$(mktemp -d /tmp/clyonutri-695-test-XXXXXX)"
SERVER_PID=''
cleanup() { if [[ -n "$SERVER_PID" ]]; then kill "$SERVER_PID" 2>/dev/null || true; wait "$SERVER_PID" 2>/dev/null || true; fi; rm -rf "$WORK"; }
trap cleanup EXIT
php bin/build_identity.php --verify > "$EVIDENCE/build.json"
export ADAPTIVE_FIXTURE_FILE="$WORK/fixture.json"
for suite in ni2_703 ni2_695_identity ni2_695_gate ni2_688 ux_workflows ni2_685 ni2_695_integrity ni2_695_journeys; do
  ADAPTIVE_TEST_DSN=sqlite::memory: php "tests/$suite.php" > "$EVIDENCE/$suite-sqlite.json"
done
if [[ -n "$(printenv UX_MYSQL_TEST_DSN)" ]]; then
  for suite in ni2_703 ux_workflows ni2_685 ni2_695_integrity ni2_695_journeys; do
    ADAPTIVE_TEST_DSN="$UX_MYSQL_TEST_DSN" php "tests/$suite.php" > "$EVIDENCE/$suite-mysql.json"
  done
fi
export ADAPTIVE_TEST_DSN="sqlite:$WORK/test.sqlite"
php tests/ni2_630_fixture.php > "$EVIDENCE/fixture.json"
PORT=8852
if [ -n "$(printenv UX_TEST_PORT)" ]; then PORT="$UX_TEST_PORT"; fi
cat > "$WORK/router.php" <<'PHP'
<?php
$root=getenv('UX_APP_ROOT').'/public';
$path=parse_url($_SERVER['REQUEST_URI'],PHP_URL_PATH);
if($path!=='/'&&is_file($root.$path))return false;
require $root.'/index.php';
PHP
export UX_APP_ROOT="$ROOT" UX_ISOLATED_TEST_RUN=1 UX_CANDIDATE_ONLY=1 APP_ENV=testing FORCE_HTTPS=false DB_CONNECTION=sqlite DB_DATABASE="$WORK/test.sqlite"
export UX_BASE_URL="http://127.0.0.1:$PORT" APP_URL="http://127.0.0.1:$PORT"
php -S "127.0.0.1:$PORT" -t public "$WORK/router.php" > "$WORK/server.log" 2>&1 &
SERVER_PID=$!
for i in {1..50}; do if curl --silent --fail "$UX_BASE_URL/login" >/dev/null; then break; fi; sleep .1; done
export UX_EVIDENCE_DIR="$EVIDENCE"
node tests/ni2_695_browser.cjs > "$EVIDENCE/browser-summary.json"
UX_EVIDENCE_DIR="$EVIDENCE/cross-browser" node tests/ni2_695_reflow.cjs > "$EVIDENCE/cross-browser-summary.json"
UX_EVIDENCE_DIR="$EVIDENCE/native-matrix" node tests/ni2_630_matrix.cjs > "$EVIDENCE/native-summary.json"
for suite in components tables spacing landmarks session; do
  UX_EVIDENCE_DIR="$EVIDENCE/$suite" UX_OUTPUT_FILE="$EVIDENCE/$suite.json" node "tests/ni2_630_$suite.cjs" > "$EVIDENCE/$suite-summary.json"
done
php tests/ni2_703_fixture.php > "$EVIDENCE/dietary-fixture.json"
UX_EVIDENCE_DIR="$EVIDENCE/dietary-browser" node tests/ni2_703_browser.cjs > "$EVIDENCE/dietary-browser-summary.json"
php bin/build_identity.php --verify > "$EVIDENCE/build-after.json"
echo '{"technical_run":"PASS","production_approved":false,"human_research_executed":false}'
