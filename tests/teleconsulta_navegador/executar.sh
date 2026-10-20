#!/bin/bash
# Testes de navegador do Consultório Online (NI2-221). Não precisa de PHP nem navegador instalados.
# Uso: tests/teleconsulta_navegador/executar.sh
set -eo pipefail
DIR="$(cd "$(dirname "$0")" && pwd)"; ROOT="$(cd "$DIR/../.." && pwd)"
cd "$DIR" && [ -d node_modules ] || npm install --silent
mkdir -p "$ROOT/storage/sessions"
PHP="$DIR/node_modules/.bin/php-wasm-cli"
semear() { WORK="$ROOT" "$PHP" "$DIR/setup_db.php"; }
echo "== Fluxo HTTP =="; IDS=$(semear) WORK="$ROOT" node "$DIR/fluxo_http.mjs" 2>&1 | { grep -v deprecated || true; }
echo "== Homologação E2E Autosave e Rascunho (V17-02) =="; IDS=$(semear) WORK="$ROOT" node "$DIR/autosave_rascunho_e2e.mjs" 2>&1 | { grep -v deprecated || true; }
echo "== Chamada WebRTC entre dois pares =="; IDS=$(semear) WORK="$ROOT" node "$DIR/chamada_webrtc.mjs" 2>&1 | { grep -v deprecated || true; }


