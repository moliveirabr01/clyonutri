#!/usr/bin/env bash
set -euo pipefail
cd "$(dirname "$0")/../../.."
php tests/ni2_481_transactional_emails_e2e_test.php
