#!/usr/bin/env bash
# ==============================================================================
# ClyoNutri II — Script Oficial de Geração de Release (Release Gate & Packager)
# ==============================================================================
# Este script:
#  1. Executa o gate real de homologação (bin/homologation_gate.sh — NI2-409).
#  2. Bloqueia a release se algum check P0 não estiver em PASS, se o relatório for
#     de outro commit ou de árvore não commitada (FAIL CLOSED).
#  3. Gera RELEASE_INFO.json a partir do relatório de evidências (nada fixo no texto).
#  4. Empacota a release em tar.gz.
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
# ETAPAS 1-3: Gate real de homologação (NI2-409) — FAIL CLOSED
# ------------------------------------------------------------------------------
# Antes, este script rodava suítes com a saída descartada e escrevia um manifesto
# com camadas "PASSED" fixas no texto. Agora a release só é empacotada quando o gate
# de homologação (MySQL real, HTTP real, navegador, app, deploy/rollback) aprova
# TODOS os checks P0, e o manifesto é derivado do relatório de evidências.
#
# Reaproveitar um relatório já gerado (ex.: no CI): GATE_REPORT_FILE=<GATE_REPORT.json>
# — aceito somente se for do mesmo commit e do mesmo conteúdo (fingerprint).
# Perfil da release: web (padrão — sistema web na HostGator) ou mobile (lojas; exige app nativo em dispositivo).
export GATE_RELEASE_PROFILE="${GATE_RELEASE_PROFILE:-web}"
echo "==> [1/3] Gate de homologação (NI2-409) — perfil ${GATE_RELEASE_PROFILE}..."
if [ -n "${GATE_REPORT_FILE:-}" ]; then
    GATE_REPORT="$GATE_REPORT_FILE"
    echo "  ➔ Reaproveitando relatório: $GATE_REPORT"
else
    EVID_DIR="$RELEASE_DIR/evidence/${BUILD_ID}_${COMMIT_HASH}"
    bin/homologation_gate.sh "$EVID_DIR" || true
    GATE_REPORT="$EVID_DIR/GATE_REPORT.json"
fi
if [ ! -f "$GATE_REPORT" ]; then
    echo "  ❌ Relatório do gate ausente. Release bloqueada."
    exit 1
fi

echo "==> [2/3] Validando decisão e vínculo do relatório com o código..."
read -r NOW_DIRTY NOW_SHA < <(bin/homologation/source_fingerprint.sh)
NOW_DIRTY="$NOW_DIRTY" NOW_SHA="$NOW_SHA" php -r '
$r = json_decode(file_get_contents($argv[1]), true) ?: [];
$head = trim((string)shell_exec("git rev-parse HEAD 2>/dev/null"));
$errors = [];
if (($r["source"]["commit"] ?? "") !== $head) $errors[] = "relatório é do commit " . ($r["source"]["commit"] ?? "?") . ", HEAD é $head";
if (!empty($r["source"]["dirty"])) $errors[] = "relatório gerado com árvore não commitada";
if ((int)getenv("NOW_DIRTY") > 0) $errors[] = getenv("NOW_DIRTY") . " arquivo(s) alterado(s) desde o commit: o pacote não corresponderia ao código homologado";
if (($r["source"]["content_sha256"] ?? "") !== getenv("NOW_SHA")) $errors[] = "conteúdo atual difere do homologado (fingerprint " . substr((string)getenv("NOW_SHA"), 0, 16) . " ≠ " . substr((string)($r["source"]["content_sha256"] ?? "?"), 0, 16) . ")";
if (($r["release_profile"] ?? "") !== getenv("GATE_RELEASE_PROFILE")) $errors[] = "relatório do perfil " . ($r["release_profile"] ?? "?") . ", release pedida no perfil " . getenv("GATE_RELEASE_PROFILE");
if (($r["decision"] ?? "") !== "APPROVED") {
    $errors[] = "decisão do gate: " . ($r["decision"] ?? "ausente");
    foreach ($r["blocking"] ?? [] as $b) $errors[] = "  P0 {$b["id"]} ({$b["status"]}): {$b["reason"]}";
}
if ($errors) { fwrite(STDERR, "  ❌ RELEASE BLOQUEADA:\n  - " . implode("\n  - ", $errors) . "\n"); exit(1); }
echo "  ✓ Gate APPROVED para o commit $head\n";
' "$GATE_REPORT"

echo "==> [3/3] Gerando manifesto a partir das evidências (RELEASE_INFO.json)..."
mkdir -p "$RELEASE_DIR"
MANIFEST_FILE="$RELEASE_DIR/RELEASE_INFO.json"
php -r '
$r = json_decode(file_get_contents($argv[1]), true);
$manifest = [
    "product" => "ClyoNutri II",
    "version" => $argv[2],
    "build_timestamp" => $argv[3],
    "git_commit" => $r["source"]["commit"],
    "content_sha256" => $r["source"]["content_sha256"],
    "release_gate" => [
        "source" => "bin/homologation_gate.sh (NI2-409)",
        "release_profile" => $r["release_profile"],
        "decision" => $r["decision"],
        "is_production_approved" => $r["is_production_approved"],
        "fail_closed_enforced" => true,
        "generated_at" => $r["generated_at"],
        "checks" => array_map(fn($c) => ["id" => $c["id"], "priority" => $c["priority"], "status" => $c["status"], "detail" => $c["detail"]], $r["checks"]),
        "features_not_homologated" => $r["features_not_homologated"],
        "findings" => $r["findings"],
        "evidence_report" => $argv[1],
    ],
    "schema" => $r["schema"],
    "environment" => $r["environment"],
    "modules_included" => array_values(array_filter(["app", "src", "public", "views", "config", "database", "tests", "bin", "mobile", "docs", "vendor", "replatform", "artisan"], "file_exists")),
    "runtime_requirements" => ["php" => ">=8.1", "mysql" => ">=8.0", "extensions" => ["pdo", "pdo_mysql", "mbstring", "fileinfo", "curl", "json", "openssl"]],
];
file_put_contents($argv[4], json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
' "$GATE_REPORT" "$VERSION" "$BUILD_ID" "$MANIFEST_FILE"
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
# Copiar código-fonte mobile excluindo node_modules e caches transitórios
mkdir -p "$TEMP_STAGE/mobile"
rsync -a --exclude='node_modules' --exclude='.expo' --exclude='.test-build' mobile/ "$TEMP_STAGE/mobile/"
cp -R docs "$TEMP_STAGE/"
cp -R vendor "$TEMP_STAGE/"
# Replataforma Laravel 13 (experimental) só entra se existir com dependências instaladas.
if [ -d replatform/laravel13/vendor ]; then cp -R replatform "$TEMP_STAGE/"; fi
if [ -f artisan ]; then cp artisan "$TEMP_STAGE/"; fi
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

php -r "require '$TEST_EXTRACT_DIR/vendor/autoload.php'; echo '    ✓ Autoloader executado com sucesso no pacote isolado.', PHP_EOL;"
# A replataforma Laravel 13 é experimental e exige PHP >= 8.4.1; não roda em produção (public/index.php).
if [ -f "$TEST_EXTRACT_DIR/replatform/laravel13/artisan" ] && php -r 'exit(PHP_VERSION_ID >= 80401 ? 0 : 1);'; then
    php "$TEST_EXTRACT_DIR/replatform/laravel13/artisan" --version > /dev/null && echo "    ✓ Laravel 13 artisan executa a partir do pacote extraído."
else
    echo "    ℹ Replataforma Laravel 13 não verificada (ausente ou PHP < 8.4.1); não faz parte do runtime de produção."
fi
rm -rf "$TEST_EXTRACT_DIR"

echo "========================================================================"
echo "   ✅ RELEASE GERADA COM SUCESSO!"
echo "========================================================================"
echo " Arquivo : $RELEASE_DIR/$PACKAGE_NAME"
echo " Tamanho : $PACKAGE_SIZE"
echo " Versão  : $VERSION"
echo " Status  : APPROVED pelo gate de homologação (NI2-409) — ver release_gate em RELEASE_INFO.json"
echo " Evidência: $GATE_REPORT"
echo "========================================================================"
