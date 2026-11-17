#!/usr/bin/env bash
# Monta o zip HostGator com permissões normalizadas (arquivos 644, pastas 755) e falha se algum
# arquivo servido ficar ilegível para o Apache (causa do 403 em CSS/JS na v19).
# Nunca empacota .env reais nem chaves: só .env.example, sem valores sensíveis.
# Funciona no macOS e no Linux (sem depender de zip nem de "sed -i ''").
# Uso: scripts/build_hostgator_zip.sh <nome-sem-extensao> <tag-do-leia-me, ex.: v24>
set -euo pipefail
ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"; cd "$ROOT"
NAME="${1:?uso: build_hostgator_zip.sh <nome-sem-extensao> <tag>}"
TAG="${2:-}"
[[ "$NAME" =~ ^[A-Za-z0-9][A-Za-z0-9._-]*$ ]] || { echo "Nome de pacote inválido" >&2; exit 2; }

# NI2-860 (F-02): Gate obrigatório de testes antes de montar o pacote.
echo "==> Executando gate de testes (bin/run-tests core)..."
bash "$ROOT/bin/run-tests" core

# NI2-873 (F-16): Garante vendor/ reprodutível quando composer estiver disponível e registra hash no manifesto.
if [ ! -f "$ROOT/vendor/autoload.php" ]; then
  composer install --no-dev --prefer-dist --no-interaction --optimize-autoloader
fi
VENDOR_HASH="$(shasum -a 256 "$ROOT/composer.lock" 2>/dev/null | awk '{print $1}' || sha256sum "$ROOT/composer.lock" 2>/dev/null | awk '{print $1}' || echo 'unknown')"

STAGE="$ROOT/release/$NAME"; rm -rf "$STAGE"; mkdir -p "$STAGE"
ITEMS=(.htaccess index.php composer.json composer.lock .env.example app bin bootstrap config database public routes src storage vendor views)
for i in "${ITEMS[@]}"; do
  [ -e "$i" ] || continue
  rsync -a \
    --exclude='.DS_Store' \
    --exclude='.env' \
    --exclude='.env.*' \
    --exclude='*.sqlite' \
    --exclude='*.log' \
    --exclude='*.zip' \
    --exclude='*.bundle' \
    --exclude='*.bak' \
    --exclude='*.pem' \
    --exclude='*.key' \
    --exclude='node_modules' \
    --exclude='.expo' \
    --exclude='.test-build' \
    --exclude='*.apk' \
    --exclude='*.aab' \
    --exclude='*.mp4' \
    --exclude='*.webm' \
    --exclude='*.mov' \
    --exclude='*.tar.gz' \
    --exclude='*.tgz' \
    --exclude='storage/logs/*.log' \
    "$i" "$STAGE/"
done
[ -f ".env.example" ] && cp .env.example "$STAGE/.env.example"
# Only current deployment instructions; API schema remains a runtime dependency.
if [ -n "$TAG" ] && [ -f "release/LEIA-ME-HOSTGATOR-$TAG.md" ]; then
  cp "release/LEIA-ME-HOSTGATOR-$TAG.md" "$STAGE/"
fi
[ ! -f RELEASE_INFO.json ] || cp RELEASE_INFO.json "$STAGE/"
mkdir -p "$STAGE/docs/api"
[ ! -f docs/api/openapi_v1.json ] || cp docs/api/openapi_v1.json "$STAGE/docs/api/"
[ ! -f docs/.htaccess ] || cp docs/.htaccess "$STAGE/docs/"
# Development/test runners are never deployed.
rm -f "$STAGE/bin/run-tests" "$STAGE/bin/serve-local.sh" "$STAGE/bin/homologation_gate.sh" "$STAGE/bin/build_release.sh"
rm -rf "$STAGE/bin/homologation"
# storage/ só leva a estrutura (sem logs, sessões, bancos ou uploads locais)
[ -d "$STAGE/storage" ] && find "$STAGE/storage" -type f ! -name .htaccess ! -name .gitignore ! -name index.html -delete
rm -rf "$STAGE/storage/e2e-report" # NI2-632: relatórios do portão de release ficam fora do pacote
# pastas vazias de storage/ (o git não guarda pasta vazia; o app e o .htaccess esperam por elas)
for d in logs/mail uploads backups cache sessions app/chat_attachments app/helpdesk_attachments app/materials app/academy/laminas; do mkdir -p "$STAGE/storage/$d"; done
# .env.example vai sem valores sensíveis (APP_KEY/senhas/tokens/segredos em branco)
if [ -f "$STAGE/.env.example" ]; then
  python3 - "$STAGE/.env.example" <<'PY'
import re,sys
p=sys.argv[1]; out=[]
for l in open(p,encoding='utf-8').read().split('\n'):
    out.append(re.sub(r'^([A-Z0-9_]*(KEY|PASSWORD|PASS|SECRET|TOKEN)[A-Z0-9_]*)=.*', r'\1=', l))
open(p,'w',encoding='utf-8').write('\n'.join(out))
PY
fi
find "$STAGE" -type d -exec chmod 755 {} +
find "$STAGE" -type f -exec chmod 644 {} +
find "$STAGE/bin" -type f -name '*.sh' -exec chmod 755 {} +
BAD=$(find "$STAGE" -type f ! -perm -044 | wc -l | tr -d ' ')
[ "$BAD" = 0 ] || { echo "ERRO: $BAD arquivo(s) sem leitura para o grupo/outros"; exit 1; }
if find "$STAGE" -name '.env*' ! -name '.env.example' | grep -q .; then echo "ERRO: .env real no pacote"; exit 1; fi
if find "$STAGE" \( -name '*.pem' -o -name '*.key' -o -name '*.bundle' -o -name '*.bak' -o -name '*.sqlite' -o -name 'id_rsa*' \) | grep -q .; then echo "ERRO: chave, bundle, backup ou banco local no pacote"; exit 1; fi
[ -f "$STAGE/vendor/autoload.php" ] || { echo "ERRO: vendor/ não está no pacote (o HostGator não roda composer)"; exit 1; }
php "$STAGE/bin/build_identity.php" --generate
php "$STAGE/bin/build_identity.php" --verify
mkdir -p release; rm -f "$ROOT/release/$NAME.zip"
if command -v zip >/dev/null 2>&1; then
  (cd "$STAGE" && zip -r -q "$ROOT/release/$NAME.zip" .)
else
  python3 - "$STAGE" "$ROOT/release/$NAME.zip" <<'PY'
import os,sys,zipfile
src,out=sys.argv[1:3]
with zipfile.ZipFile(out,'w') as z:
    for d,ds,fs in os.walk(src):
        ds.sort()
        for n in sorted(ds)+sorted(fs):
            p=os.path.join(d,n); rel=os.path.relpath(p,src)
            is_dir=os.path.isdir(p)
            if is_dir: rel+='/'
            zi=zipfile.ZipInfo(rel,date_time=(2026,10,24,12,0,0))
            m=os.stat(p).st_mode&0o777
            zi.create_system=3
            zi.external_attr=((0o40000 if is_dir else 0o100000)|m)<<16|(0x10 if is_dir else 0)
            zi.compress_type=zipfile.ZIP_STORED if is_dir else zipfile.ZIP_DEFLATED
            z.writestr(zi, b'' if is_dir else open(p,'rb').read())
PY
fi
if command -v sha256sum >/dev/null 2>&1; then (cd release && sha256sum "$NAME.zip" | tee "$NAME.zip.sha256"); else (cd release && shasum -a 256 "$NAME.zip" | tee "$NAME.zip.sha256"); fi
