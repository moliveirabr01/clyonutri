#!/usr/bin/env bash
set -euo pipefail
PROJECT_ROOT="$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")/.." && pwd -P)"
cd "$PROJECT_ROOT"
if [[ ! -f vendor/autoload.php ]]; then
  echo "Dependências ausentes. Execute composer install nesta pasta." >&2
  exit 1
fi
if [[ ! -f .env ]]; then
  echo "Configuração ausente. Prepare o .env a partir de .env.example antes de iniciar." >&2
  exit 1
fi
PORT="${CLYONUTRI_PORT:-8081}"
if [[ ! "$PORT" =~ ^[0-9]+$ ]] || (( PORT < 1024 || PORT > 65535 )); then
  echo "CLYONUTRI_PORT deve ser uma porta entre 1024 e 65535." >&2
  exit 1
fi
echo "ClyoNutri: $PROJECT_ROOT"
echo "Acesse http://127.0.0.1:$PORT"
exec php -S "127.0.0.1:$PORT" -t "$PROJECT_ROOT/public"
