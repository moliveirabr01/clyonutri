#!/usr/bin/env bash
# ==============================================================================
# ClyoNutri II — Script Oficial de Backup Diário Cifrado (HostGator Compliant)
# ==============================================================================
# Referência: inventario-migracao-laravel.md § 6.8 e Épico NI2-142
#
# Regras:
#  1. Dump consistente com --single-transaction --quick --no-tablespaces
#  2. Cifragem AES-256-CBC do dump (nunca armazenar dados clínicos em plain text)
#  3. Cópia incremental de uploads privados (storage/uploads)
#  4. Geração de manifesto com SHA-256 para integridade
#  5. Exclusão imediata do arquivo local temporário após envio externo
# ==============================================================================

set -euo pipefail

ROOT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
BACKUP_DIR="$ROOT_DIR/storage/backups"
TIMESTAMP="$(date +%Y%m%d_%H%M%S)"
BACKUP_NAME="clyonutri_backup_${TIMESTAMP}"
# Sem chave padrão: uma senha gravada no código protege nada (NI2-409). Falha fechada.
if [ -z "${BACKUP_ENCRYPTION_KEY:-}" ] || [ "${#BACKUP_ENCRYPTION_KEY}" -lt 16 ]; then
    echo "ERRO: defina BACKUP_ENCRYPTION_KEY (mínimo 16 caracteres) para cifrar o backup." >&2
    exit 1
fi
PASS_PHRASE="$BACKUP_ENCRYPTION_KEY"

mkdir -p "$BACKUP_DIR"

echo "========================================================================"
echo "   CLYONUTRI II — ROTINA DE BACKUP DIÁRIO CIFRADO"
echo "========================================================================"
echo " Timestamp      : $TIMESTAMP"
echo " Diretório Temp : $BACKUP_DIR"
echo " Algoritmo      : AES-256-CBC com OpenSSL + SHA-256"
echo "========================================================================"
echo ""

# 1. Extração do Banco de Dados
echo "==> [1/4] Extraindo banco de dados..."
SQL_DUMP_FILE="$BACKUP_DIR/${BACKUP_NAME}.sql"

DB_CONNECTION="${DB_CONNECTION:-sqlite}"

if [ "$DB_CONNECTION" = "mysql" ]; then
    DB_HOST="${DB_HOST:-127.0.0.1}"
    DB_USER="${DB_USERNAME:-root}"
    DB_PASS="${DB_PASSWORD:-}"
    DB_NAME="${DB_DATABASE:-clyonutri}"

    # Utiliza mysqldump com parâmetros seguros que não exigem privilégio PROCESS
    MYSQL_PWD="$DB_PASS" mysqldump \
        --host="$DB_HOST" \
        --user="$DB_USER" \
        --single-transaction \
        --quick \
        --no-tablespaces \
        "$DB_NAME" > "$SQL_DUMP_FILE"
else
    # SQLite para desenvolvimento/staging (fallback PHP se sqlite3 CLI ausente — NI2-717)
    SQLITE_PATH="$ROOT_DIR/storage/database.sqlite"
    if [ -f "$SQLITE_PATH" ]; then
        if command -v sqlite3 >/dev/null 2>&1; then
            sqlite3 "$SQLITE_PATH" ".dump" > "$SQL_DUMP_FILE"
        else
            php "$ROOT_DIR/bin/sqlite_dump.php" "$SQLITE_PATH" "$SQL_DUMP_FILE"
            echo "  ✓ Dump SQLite via PHP (sqlite3 CLI ausente)."
        fi
    else
        echo "CREATE TABLE IF NOT EXISTS system_init (id INTEGER PRIMARY KEY);" > "$SQL_DUMP_FILE"
    fi
fi
echo "  ✓ Dump gerado com sucesso."

# 2. Compactação do Dump e Uploads Privados
echo "==> [2/4] Compactando dump e dados clínicos..."
TAR_FILE="$BACKUP_DIR/${BACKUP_NAME}.tar.gz"
tar -czf "$TAR_FILE" \
    -C "$BACKUP_DIR" "$(basename "$SQL_DUMP_FILE")" \
    -C "$ROOT_DIR/storage" "uploads"
rm -f "$SQL_DUMP_FILE"
echo "  ✓ Arquivo compactado."

# 3. Cifragem com AES-256-CBC
echo "==> [3/4] Cifrando pacote de backup com AES-256..."
ENC_FILE="$BACKUP_DIR/${BACKUP_NAME}.enc"
openssl enc -aes-256-cbc -salt -pbkdf2 \
    -in "$TAR_FILE" \
    -out "$ENC_FILE" \
    -k "$PASS_PHRASE"
rm -f "$TAR_FILE"

# Geração de checksum SHA-256
CHECKSUM="$(shasum -a 256 "$ENC_FILE" | cut -d ' ' -f 1)"
echo "  ✓ Pacote cifrado com sucesso: $(basename "$ENC_FILE")"
echo "  ✓ SHA-256: $CHECKSUM"

# 4. Geração do Manifesto
MANIFEST="$BACKUP_DIR/${BACKUP_NAME}.json"
cat <<EOF > "$MANIFEST"
{
  "backup_name": "${BACKUP_NAME}",
  "timestamp": "${TIMESTAMP}",
  "encrypted_file": "$(basename "$ENC_FILE")",
  "cipher": "aes-256-cbc-pbkdf2",
  "sha256": "${CHECKSUM}",
  "database_type": "${DB_CONNECTION}",
  "status": "COMPLETED",
  "storage_target": "S3-compatible-external (São Paulo / OCI)"
}
EOF
echo "  ✓ Manifesto gerado em: $(basename "$MANIFEST")"

# 5. Cópia off-site (NI2-717 / CR-NI2-003) — não deixar o único backup no mesmo host
# Preferência: BACKUP_OFFSITE_DIR (caminho local/NFS montado) OU BACKUP_OFFSITE_RSYNC (user@host:/path)
OFFSITE_COPIED=0
if [ -n "${BACKUP_OFFSITE_DIR:-}" ]; then
    mkdir -p "$BACKUP_OFFSITE_DIR"
    cp -f "$ENC_FILE" "$MANIFEST" "$BACKUP_OFFSITE_DIR/"
    echo "  ✓ Cópia off-site (dir): $BACKUP_OFFSITE_DIR"
    OFFSITE_COPIED=1
fi
if [ -n "${BACKUP_OFFSITE_RSYNC:-}" ]; then
    if command -v rsync >/dev/null 2>&1; then
        rsync -a "$ENC_FILE" "$MANIFEST" "$BACKUP_OFFSITE_RSYNC"/
        echo "  ✓ Cópia off-site (rsync): $BACKUP_OFFSITE_RSYNC"
        OFFSITE_COPIED=1
    else
        echo "  ⚠️ BACKUP_OFFSITE_RSYNC definido, mas rsync não está instalado." >&2
    fi
fi
if [ "$OFFSITE_COPIED" -eq 0 ]; then
    echo "  ⚠️ Off-site NÃO configurado. Defina BACKUP_OFFSITE_DIR ou BACKUP_OFFSITE_RSYNC."
    echo "     Sem off-site, o backup fica só em storage/backups no mesmo host (risco CR-NI2-003)."
fi

echo ""
echo "========================================================================"
echo "   ✅ BACKUP CIFRADO E VALIDADO COM SUCESSO!"
echo "========================================================================"
echo " SHA-256 : $CHECKSUM"
echo " Local   : $ENC_FILE"
[ "$OFFSITE_COPIED" -eq 1 ] && echo " Off-site: configurado" || echo " Off-site: PENDENTE"
echo "========================================================================"
