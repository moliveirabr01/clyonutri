#!/bin/bash
# Teste da impressão do prontuário pelo index.php real. Usa as dependências npm de tests/teleconsulta_navegador.
set -e
DIR="$(cd "$(dirname "$0")" && pwd)"; ROOT="$(cd "$DIR/../.." && pwd)"
DEPS="$ROOT/tests/teleconsulta_navegador"
(cd "$DEPS" && [ -d node_modules ] || npm install --silent)
ln -sfn "$DEPS/node_modules" "$DIR/node_modules"
IDS=$(WORK="$ROOT" "$DEPS/node_modules/.bin/php-wasm-cli" "$DIR/setup_db.php")
IDS="$IDS" WORK="$ROOT" node "$DIR/teste_impressao.mjs" 2>&1 | grep -v deprecated
