#!/usr/bin/env bash
# ==============================================================================
# ClyoNutri II — Script Oficial de Geração de Release (Release Gate & Packager)
# ==============================================================================
# Este script:
#  1. Executa rigorosamente toda a bateria de testes automatizados e health check.
#  2. Valida critérios de qualidade e release gate (zero falhas aceitas).
#  3. Gera manifesto de release com hash git, timestamp e sumário de testes.
#  4. Empacota a release em tar.gz com código de produção, migrations E testes.
# ==============================================================================

set -euo pipefail

ROOT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
cd "$ROOT_DIR"

VERSION="${1:-1.0.0}"
RELEASE_DIR="$ROOT_DIR/release"
BUILD_ID="$(date +%Y%m%d_%H%M%S)"
COMMIT_HASH="$(git rev-parse --short HEAD 2>/dev/null || echo "manual_build")"
PACKAGE_NAME="clyonutri_v${VERSION}_${BUILD_ID}_${COMMIT_HASH}.tar.gz"

echo "========================================================================"
echo "   CLYONUTRI II — PIPELINE DE RELEASE v${VERSION}"
echo "========================================================================"
echo " Diretório raiz : $ROOT_DIR"
echo " Commit Git     : $COMMIT_HASH"
echo " Build Timestamp: $BUILD_ID"
echo " Pacote destino : release/$PACKAGE_NAME"
echo "========================================================================"
echo ""

# ------------------------------------------------------------------------------
# ETAPA 1: Verificação de Pré-requisitos & Health Check do Sistema
# ------------------------------------------------------------------------------
echo "==> [1/4] Executando Health Check do Sistema..."
php bin/healthcheck.php
echo "  ✓ Health check aprovado."
echo ""

# ------------------------------------------------------------------------------
# ETAPA 2: Execução de TODA a Bateria de Testes Automatizados (Release Gate)
# ------------------------------------------------------------------------------
echo "==> [2/4] Executando Suíte Completa de Testes Automatizados..."

TEST_FILES=(
    "tests/foundation_test.php"
    "tests/golden_path_test.php"
    "tests/phases_4_5_6_test.php"
    "tests/security_lgpd_test.php"
    "tests/onda_1_benchmark_test.php"
    "tests/onda_2_benchmark_test.php"
    "tests/onda_3_benchmark_test.php"
    "tests/onda_4_benchmark_test.php"
    "tests/replatform_infrastructure_test.php"
    "tests/cross_tenant_offensive_test.php"
    "tests/clinical_safety_and_precision_test.php"
    "tests/privacy_and_lgpd_governance_test.php"
    "tests/delivery_e2e_and_contracts_test.php"
    "tests/patient_portal_e2e_suite_test.php"
)

TOTAL_TESTS=${#TEST_FILES[@]}
PASSED_TESTS=0

for test_file in "${TEST_FILES[@]}"; do
    if [ -f "$test_file" ]; then
        echo " ➔ Executando: $test_file"
        php "$test_file" > /dev/null
        PASSED_TESTS=$((PASSED_TESTS + 1))
        echo "   ✓ Passou: $test_file"
    else
        echo " ⚠️ Arquivo de teste não encontrado: $test_file"
        exit 1
    fi
done

echo ""
echo "  ✓ Suíte completa de testes aprovada: $PASSED_TESTS de $TOTAL_TESTS arquivos com 100% de sucesso."
echo ""

# ------------------------------------------------------------------------------
# ETAPA 3: Geração do Manifesto da Release
# ------------------------------------------------------------------------------
echo "==> [3/4] Gerando Manifesto de Release (RELEASE_INFO.json)..."

mkdir -p "$RELEASE_DIR"

MANIFEST_FILE="$RELEASE_DIR/RELEASE_INFO.json"
cat <<EOF > "$MANIFEST_FILE"
{
  "product": "ClyoNutri II",
  "version": "${VERSION}",
  "build_timestamp": "${BUILD_ID}",
  "git_commit": "${COMMIT_HASH}",
  "release_gate": {
    "status": "APPROVED",
    "health_check": "PASSED",
    "total_test_suites": ${TOTAL_TESTS},
    "passed_test_suites": ${PASSED_TESTS},
    "zero_failures_verified": true
  },
  "modules_included": [
    "app",
    "src",
    "public",
    "views",
    "config",
    "database/migrations",
    "database/baseline",
    "tests",
    "bin",
    "mobile",
    "docs"
  ],
  "runtime_requirements": {
    "php": ">=8.1",
    "mysql": ">=8.0 ou sqlite3",
    "extensions": ["pdo", "pdo_mysql", "pdo_sqlite", "mbstring", "json", "openssl"]
  }
}
EOF

echo "  ✓ Manifesto gerado em: $MANIFEST_FILE"
echo ""

# ------------------------------------------------------------------------------
# ETAPA 4: Empacotamento do Artefato (Incluindo Código, Migrations e Testes)
# ------------------------------------------------------------------------------
echo "==> [4/4] Criando pacote distribuível de Release (tar.gz)..."

# Criar pasta temporária para montagem limpa do pacote
TEMP_STAGE="$RELEASE_DIR/stage_${BUILD_ID}"
mkdir -p "$TEMP_STAGE"

# Copiar componentes essenciais
cp -R app "$TEMP_STAGE/"
cp -R src "$TEMP_STAGE/"
cp -R public "$TEMP_STAGE/"
cp -R views "$TEMP_STAGE/"
cp -R config "$TEMP_STAGE/"
cp -R database "$TEMP_STAGE/"
cp -R bin "$TEMP_STAGE/"
cp -R tests "$TEMP_STAGE/"
cp -R mobile "$TEMP_STAGE/"
cp -R docs "$TEMP_STAGE/"
cp composer.json "$TEMP_STAGE/"
cp .env.example "$TEMP_STAGE/"
cp "$MANIFEST_FILE" "$TEMP_STAGE/"

# Criar estrutura de storage vazia com gitkeeps
mkdir -p "$TEMP_STAGE/storage/logs" "$TEMP_STAGE/storage/uploads" "$TEMP_STAGE/storage/backups"
touch "$TEMP_STAGE/storage/logs/.gitkeep"
touch "$TEMP_STAGE/storage/uploads/.gitkeep"
touch "$TEMP_STAGE/storage/backups/.gitkeep"

# Gerar arquivo comprimido
tar -czf "$RELEASE_DIR/$PACKAGE_NAME" -C "$TEMP_STAGE" .

# Limpar staging temporário
rm -rf "$TEMP_STAGE"

PACKAGE_SIZE="$(du -h "$RELEASE_DIR/$PACKAGE_NAME" | cut -f1)"

echo "========================================================================"
echo "   ✅ RELEASE GERADA COM SUCESSO!"
echo "========================================================================"
echo " Arquivo : $RELEASE_DIR/$PACKAGE_NAME"
echo " Tamanho : $PACKAGE_SIZE"
echo " Versão  : $VERSION"
echo " Testes  : 100% Executados e Incluídos no Pacote"
echo "========================================================================"
