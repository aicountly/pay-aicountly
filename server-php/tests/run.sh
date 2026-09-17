#!/usr/bin/env bash
# Run the Pay integration tests against a throwaway PostgreSQL database and a
# local stub standing in for Manage and the source apps.
#
#   server-php/tests/run.sh
#
# Requires: php with pdo_pgsql, and a reachable PostgreSQL.
set -euo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"

DB_NAME="${TEST_DB_NAME:-pay_test}"
DB_USER="${TEST_DB_USER:-pay_test}"
DB_PASS="${TEST_DB_PASS:-pay_test}"
DB_HOST="${TEST_DB_HOST:-127.0.0.1}"
DB_PORT="${TEST_DB_PORT:-5432}"
STUB_PORT="${STUB_PORT:-8794}"

cat > "$ROOT/.env" <<ENVEOF
APP_ENV=local
APP_PRODUCT_KEY=pay
DB_HOST=$DB_HOST
DB_PORT=$DB_PORT
DB_NAME=$DB_NAME
DB_USER=$DB_USER
DB_PASS=$DB_PASS
MANAGE_API_BASE=http://127.0.0.1:$STUB_PORT
BOOKS_API_BASE=http://127.0.0.1:$STUB_PORT
BILLING_API_BASE=http://127.0.0.1:$STUB_PORT
SALES_API_BASE=http://127.0.0.1:$STUB_PORT
POS_API_BASE=http://127.0.0.1:$STUB_PORT
BOOKS_SERVICE_KEY=test-books-key
BILLING_SERVICE_KEY=test-billing-key
SALES_SERVICE_KEY=test-sales-key
POS_SERVICE_KEY=test-pos-key
PAY_CALLBACK_SIGNING_SECRET=test-callback-secret
# A throwaway 32-byte key. Never a real one, and never committed anywhere else.
PAY_ENCRYPTION_KEY=1:3q2+796tvu/erb7v3q2+796tvu/erb7v3q2+796tvu8=
# The mock provider exists only for these tests and refuses to run in production.
MOCK_PROVIDER_ENABLED=1
ENVEOF

php "$ROOT/bin/migrate.php" > /dev/null

php -S "127.0.0.1:$STUB_PORT" "$ROOT/tests/stub/router.php" > /dev/null 2>&1 &
STUB_PID=$!
trap 'kill $STUB_PID 2>/dev/null || true' EXIT

# Wait for the stub rather than sleeping a guessed amount.
for _ in $(seq 1 40); do
  if curl -fsS --noproxy '*' "http://127.0.0.1:$STUB_PORT/api/companyinfo?comp_id=55" > /dev/null 2>&1; then break; fi
  sleep 0.25
done

php "$ROOT/tests/integration.php"
