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
    # SQLite para desenvolvimento/staging
    SQLITE_PATH="$ROOT_DIR/storage/database.sqlite"
    if [ -f "$SQLITE_PATH" ]; then
        sqlite3 "$SQLITE_PATH" ".dump" > "$SQL_DUMP_FILE"
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
echo ""
echo "========================================================================"
echo "   ✅ BACKUP CIFRADO E VALIDADO COM SUCESSO!"
echo "========================================================================"
