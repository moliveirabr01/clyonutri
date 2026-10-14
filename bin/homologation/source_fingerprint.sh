#!/usr/bin/env bash
# Imprime "<arquivos_nao_commitados> <sha256_do_conteudo>" do repositório (NI2-409).
# Mesma regra no gate e no build_release.sh: o pacote só sai do conteúdo exato que foi homologado.
set -uo pipefail
cd "$(dirname "${BASH_SOURCE[0]}")/../.."
DIRTY=$(git status --porcelain 2>/dev/null | grep -vE '^\?\? (release/|storage/|tests/homologation/node_modules/?|mobile/node_modules/?)$|^\?\? (release|storage)/' | wc -l | tr -d ' ')
SHA=$(git ls-files -co --exclude-standard 2>/dev/null | grep -vE '^(release|storage)/' | LC_ALL=C sort | while read -r f; do [ -f "$f" ] && sha256sum "$f"; done | sha256sum | cut -d' ' -f1)
echo "$DIRTY $SHA"
