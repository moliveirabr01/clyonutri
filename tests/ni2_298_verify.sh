#!/bin/sh
set -eu
cd "$(dirname "$0")/.."
evidence="$PWD/docs/validation/NI2-298/evidence"
mkdir -p "$evidence"
for source in src/Core/Time/ClinicCalendar.php src/Domain/Appointment/AppointmentService.php src/Application/Controllers/DashboardController.php; do
 php -l "$source"
done > "$evidence/php-lint.log" 2>&1
calendar_db="$(mktemp /tmp/ni2-298-calendar-XXXXXX).sqlite"
export DB_CONNECTION=sqlite DB_DATABASE="$calendar_db" APP_TIMEZONE=America/Sao_Paulo
php tests/support/ni2_298_fixture.php prepare > "$evidence/calendar-setup.json"
php tests/ni2_298_calendar_integration_test.php > "$evidence/calendar.log" 2>&1
rm -f "$calendar_db" "${calendar_db%.sqlite}"
node tests/ni2_298_real_http_test.cjs > "$evidence/http.log" 2>&1
for suite in agenda_and_consultation_lifecycle agenda_operational_and_concurrency canonical_first_consultation_e2e_journey; do
 regression_db="$(mktemp /tmp/ni2-298-regression-XXXXXX).sqlite"
 export DB_DATABASE="$regression_db"
 php -r 'require "vendor/autoload.php"; $db=ClyoNutri\Core\Database\Connection::getInstance(); (new ClyoNutri\Core\Database\Migrator($db,"database/migrations"))->run(); (new ClyoNutri\Core\Database\CatalogSeeder($db))->run();' > "$evidence/$suite-setup.log" 2>&1
 case "$suite" in
  agenda*) php tests/support/ni2_298_fixture.php bootstrap >> "$evidence/$suite-setup.log" 2>&1 ;;
 esac
 php -d date.timezone=America/Sao_Paulo "tests/${suite}_test.php" > "$evidence/$suite.log" 2>&1
 rm -f "$regression_db" "${regression_db%.sqlite}"
done
printf 'NI2-298 verification completed. Evidence: %s\n' "$evidence"
