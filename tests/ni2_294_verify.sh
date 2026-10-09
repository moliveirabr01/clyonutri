#!/bin/sh
# Run from any directory; never uses the production database.
set -eu
cd "$(dirname "$0")/.."
evidence="$PWD/docs/validation/NI2-294/evidence"
mkdir -p "$evidence"
(cd mobile && npm run typecheck) > "$evidence/typecheck.log" 2>&1
(cd mobile && npm run test:auth) > "$evidence/http-client.log" 2>&1
(cd mobile && EXPO_PUBLIC_API_URL=/ ./node_modules/.bin/expo export --platform web) > "$evidence/web-build.log" 2>&1
php -l public/index.php > "$evidence/php-lint.log" 2>&1
for suite in canonical_first_consultation_e2e_journey meal_plan_and_prescription_lifecycle; do
  regression_db="$(mktemp /tmp/ni2-294-regression-XXXXXX).sqlite"
  export DB_CONNECTION=sqlite DB_DATABASE="$regression_db"
  php -r 'require "vendor/autoload.php"; $db=ClyoNutri\Core\Database\Connection::getInstance(); (new ClyoNutri\Core\Database\Migrator($db,"database/migrations"))->run(); (new ClyoNutri\Core\Database\CatalogSeeder($db))->run();' > "$evidence/$suite-setup.log" 2>&1
  php "tests/${suite}_test.php" > "$evidence/$suite.log" 2>&1
  rm -f "$regression_db" "${regression_db%.sqlite}"
done
printf 'NI2-294 automated verification completed. Evidence: %s\n' "$evidence"
