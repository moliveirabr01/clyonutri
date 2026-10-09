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
    "tests/verifiable_multilateral_audit_suite_test.php"
    "tests/patient_mobile_api_integration_test.php"
    "tests/patient_mobile_http_contract_test.php"
    "tests/anthropometry_lifecycle_and_evaluation_test.php"
    "tests/agenda_and_consultation_lifecycle_test.php"
    "tests/agenda_operational_and_concurrency_test.php"
    "tests/meal_plan_and_prescription_lifecycle_test.php"
    "tests/canonical_first_consultation_e2e_journey_test.php"
    "tests/meal_plan_web_csrf_and_form_test.php"
    "tests/demo_seeder_and_runtime_seed_protection_test.php"
    "tests/cross_tenant_end_to_end_security_test.php"
    "tests/laravel_replatform_verification_test.php"
    "tests/ni2_209_remediation_and_go_live_gate_test.php"
    "tests/ni2_6_prontuario_e_anamnese_nutricional_test.php"
    "tests/teleconsultation_online_clinic_e2e_test.php"
    "tests/helpdesk_and_service_desk_e2e_test.php"
    "tests/benchmark_360_competitive_suite_test.php"
    "tests/chat_secure_communication_and_engagement_e2e_test.php"
    "tests/recipe_culinary_domain_and_prescription_e2e_test.php"
    "tests/onboarding_guided_and_activation_e2e_test.php"
    "tests/px_02_patient_evolution_and_clean_install_test.php"
    "tests/px_03_onboarding_birthdate_gender_optional_test.php"
    "tests/px_04_adherence_real_calculation_and_empty_state_test.php"
    "tests/px_20_prescribed_water_goal_and_empty_state_test.php"
    "tests/px_07_accessible_clinical_fields_dom_test.php"
    "tests/px_12_financial_empty_state_and_operations_test.php"
    "tests/px_13_clinical_language_and_enum_sanitization_test.php"
    "tests/px_29_password_reset_and_access_recovery_test.php"
    "tests/release_gate_and_fail_closed_test.php"
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
# ETAPA 3: Execução da Matriz de Qualidade em 6 Camadas (NI2-178)
# ------------------------------------------------------------------------------
echo "==> [3/5] Executando Validação da Matriz de Qualidade Multicamada..."
php bin/verify_quality_gate.php
echo "  ✓ Matriz de qualidade avaliada com sucesso."
echo ""

# ------------------------------------------------------------------------------
# ETAPA 4: Geração do Manifesto da Release com Princípio FAIL CLOSED
# ------------------------------------------------------------------------------
echo "==> [4/5] Gerando Manifesto de Release (RELEASE_INFO.json)..."

mkdir -p "$RELEASE_DIR"

MANIFEST_FILE="$RELEASE_DIR/RELEASE_INFO.json"
cat <<EOF > "$MANIFEST_FILE"
{
  "product": "ClyoNutri II",
  "version": "${VERSION}",
  "build_timestamp": "${BUILD_ID}",
  "git_commit": "${COMMIT_HASH}",
  "release_gate": {
    "status": "APPROVED_LOCAL_CI_GATES_ONLY (PRODUCTION_DEPLOY_BLOCKED)",
    "production_deployment_status": "BLOCKED (Credenciais remotas não injetadas)",
    "is_production_approved": false,
    "fail_closed_enforced": true,
    "health_check": "PASSED",
    "total_test_suites": ${TOTAL_TESTS},
    "passed_test_suites": ${PASSED_TESTS},
    "zero_failures_verified": true,
    "audit_360_layers": {
      "syntax_static": "PASSED (Zero erros em php -l)",
      "domain_services": "PASSED (Cálculo 100g e hash SHA-256)",
      "http_web": "PASSED (CSRF e isolamento de sessão)",
      "mobile_api": "PASSED (Sanctum tokens e persistência)",
      "e2e_journeys": "PASSED (HTTP E2E Harness)",
      "browser_playwright_e2e": "NOT TESTED (Playwright não configurado neste ambiente CI)",
      "remote_hostgator_ssh": "BLOCKED (Credenciais remotas não injetadas no ambiente local)"
    }
  },
  "modules_included": [
    "app",
    "src",
    "public",
    "views",
    "config",
    "database",
    "tests",
    "bin",
    "mobile",
    "docs",
    "vendor",
    "replatform",
    "artisan"
  ],
  "replatform_laravel13": {
    "framework": "Laravel 13.35.0",
    "sanctum": "present",
    "fortify": "present",
    "pest": "present (Pest 4.7.8)",
    "artisan_cli": "present"
  },
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
# ETAPA 5: Empacotamento do Artefato Autossuficiente (Código, Migrations, Testes e Vendor)
# ------------------------------------------------------------------------------
echo "==> [5/5] Criando pacote distribuível de Release (tar.gz)..."

TEMP_STAGE="$RELEASE_DIR/stage_${BUILD_ID}"
mkdir -p "$TEMP_STAGE"

# Copiar componentes essenciais incluindo VENDOR e REPLATAFORMA LARAVEL 13 (NI2-174 / NI2-178)
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
cp -R vendor "$TEMP_STAGE/"
cp -R replatform "$TEMP_STAGE/"
cp artisan "$TEMP_STAGE/"
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

# Validação Independente do Pacote Gerado: Extração e teste de autossuficiência
echo "  ➔ Testando autossuficiência do pacote gerado..."
TEST_EXTRACT_DIR="/tmp/test_extract_${BUILD_ID}"
mkdir -p "$TEST_EXTRACT_DIR"
tar -xzf "$RELEASE_DIR/$PACKAGE_NAME" -C "$TEST_EXTRACT_DIR"

if [ -f "$TEST_EXTRACT_DIR/vendor/autoload.php" ]; then
    echo "    ✓ vendor/autoload.php presente no pacote distribuível."
else
    echo "    ❌ ERRO: vendor/autoload.php ausente no pacote distribuível!"
    rm -rf "$TEST_EXTRACT_DIR"
    exit 1
fi

if [ -f "$TEST_EXTRACT_DIR/replatform/laravel13/composer.lock" ] && [ -f "$TEST_EXTRACT_DIR/replatform/laravel13/artisan" ]; then
    echo "    ✓ replatform/laravel13 presente com composer.lock e artisan no pacote distribuível."
else
    echo "    ❌ ERRO: replatform/laravel13 incompleto no pacote distribuível!"
    rm -rf "$TEST_EXTRACT_DIR"
    exit 1
fi

php -r "require '$TEST_EXTRACT_DIR/vendor/autoload.php'; echo '    ✓ Autoloader executado com sucesso no pacote isolado.\n';"
php "$TEST_EXTRACT_DIR/replatform/laravel13/artisan" --version > /dev/null && echo "    ✓ Laravel 13 artisan executado com sucesso a partir do pacote extraído."
rm -rf "$TEST_EXTRACT_DIR"

echo "========================================================================"
echo "   ✅ RELEASE GERADA COM SUCESSO!"
echo "========================================================================"
echo " Arquivo : $RELEASE_DIR/$PACKAGE_NAME"
echo " Tamanho : $PACKAGE_SIZE"
echo " Versão  : $VERSION"
echo " Status  : APPROVED_LOCAL_CI_GATES_ONLY (FAIL CLOSED - PRODUCTION BLOCKED)"
echo " Testes  : 100% Executados, Autossuficiente com Vendor Incluído"
echo "========================================================================"
