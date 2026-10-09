#!/bin/sh
set -eu
cd "$(dirname "$0")/.."
evidence="$PWD/docs/validation/NI2-299/evidence"
mkdir -p "$evidence"
for source in src/Application/Controllers/DashboardController.php src/Domain/Anamnesis/AnamnesisService.php views/anamnesis/version.php; do php -l "$source"; done > "$evidence/php-lint.log" 2>&1
node tests/ni2_299_real_http_test.cjs > "$evidence/http.log" 2>&1
php -d date.timezone=America/Sao_Paulo tests/ni2_6_prontuario_e_anamnese_nutricional_test.php > "$evidence/ni2_6_prontuario_e_anamnese_nutricional.log" 2>&1
for suite in meal_plan_and_prescription_lifecycle canonical_first_consultation_e2e_journey; do
 regression_db="$(mktemp /tmp/ni2-299-regression-XXXXXX).sqlite"
 export DB_CONNECTION=sqlite DB_DATABASE="$regression_db" APP_TIMEZONE=America/Sao_Paulo
 php -r 'require "vendor/autoload.php"; $db=ClyoNutri\Core\Database\Connection::getInstance(); (new ClyoNutri\Core\Database\Migrator($db,"database/migrations"))->run(); (new ClyoNutri\Core\Database\CatalogSeeder($db))->run();' > "$evidence/$suite-setup.log" 2>&1
 php -d date.timezone=America/Sao_Paulo "tests/${suite}_test.php" > "$evidence/$suite.log" 2>&1
 rm -f "$regression_db" "${regression_db%.sqlite}"
done
printf 'NI2-299 verification completed. Evidence: %s\n' "$evidence"
