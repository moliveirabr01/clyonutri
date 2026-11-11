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
STAGE="$ROOT/release/$NAME"; rm -rf "$STAGE"; mkdir -p "$STAGE"
ITEMS=(.htaccess index.php artisan composer.json composer.lock .env.example app bin bootstrap config database docs public routes scripts src storage vendor views tests)
for i in "${ITEMS[@]}"; do
  [ -e "$i" ] && rsync -a \
    --exclude='.DS_Store' \
    --exclude='.env' \
    --exclude='.env.*' \
    --exclude='*.sqlite' \
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
    "$i" "$STAGE/" || true
done
[ -f ".env.example" ] && cp .env.example "$STAGE/.env.example"
[ -n "$TAG" ] && [ -f "LEIA-ME-HOSTGATOR-$TAG.md" ] && cp "LEIA-ME-HOSTGATOR-$TAG.md" "$STAGE/" || true
# documentos herdados na raiz (LEIA-ME de versões anteriores, comparações, validações) e a ficha da versão
for f in LEIA-ME-HOSTGATOR-v*.md COMPARACAO-*.txt VALIDACAO-V*.txt RELEASE_INFO.json; do
  [ -f "$f" ] && cp "$f" "$STAGE/" || true
done
# docs/: só entrega e evidências documentais (sem binários pesados, builds mobile ou vídeos)
[ -d "$STAGE/docs" ] && find "$STAGE/docs" -mindepth 1 -maxdepth 1 ! -name delivery ! -name evidencias ! -name validation ! -name benchmark-webdiet ! -name adr ! -name .htaccess -exec rm -rf {} +
# docs/delivery: remove builds mobile e artefatos compilados pesados (.apk, eas-android, etc)
rm -rf "$STAGE/docs/delivery/patient-mobile-reference/eas-android" "$STAGE/docs/delivery/patient-mobile-reference"/*.zip "$STAGE/docs/delivery/patient-mobile-reference"/*.apk 2>/dev/null || true
# tests/: remove caches e dependências transitórias (node_modules, binários wasm, relatórios)
rm -rf "$STAGE/tests/teleconsulta_navegador/node_modules" "$STAGE/tests/homologation/node_modules" 2>/dev/null || true
find "$STAGE/tests" -type d -name "node_modules" -prune -exec rm -rf {} + 2>/dev/null || true
# evidências: sem logs, imagens, capturas nem os registros "antes" (FAIL de propósito, só serviam de prova da correção)
if [ -d "$STAGE/docs/evidencias" ]; then
  find "$STAGE/docs/evidencias" -type f \( -name '*.log' -o -name '*.png' -o -name '*.jpg' -o -name '*.jpeg' -o -name '*.webm' -o -name '*.mp4' -o -iname '*antes*' -o -iname '*before*' \) -delete
  find "$STAGE/docs/evidencias" -mindepth 1 -type d -empty -delete
fi
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
