#!/usr/bin/env bash
# Run the Purchases integration tests against a throwaway PostgreSQL database and a
# local stub standing in for Books, Inventory, Manage and AI Pulse — then the AI
# gateway client tests, which need neither (tests/ai_gateway.php, fake transport).
#
#   server-php/tests/run.sh
#
# Requires: php with pdo_pgsql, and a reachable PostgreSQL.
set -euo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"

DB_NAME="${TEST_DB_NAME:-purchases_test}"
DB_USER="${TEST_DB_USER:-purchases_test}"
DB_PASS="${TEST_DB_PASS:-purchases_test}"
DB_HOST="${TEST_DB_HOST:-127.0.0.1}"
DB_PORT="${TEST_DB_PORT:-5432}"
STUB_PORT="${STUB_PORT:-8792}"

cat > "$ROOT/.env" <<ENVEOF
APP_ENV=local
APP_PRODUCT_KEY=purchases
DB_HOST=$DB_HOST
DB_PORT=$DB_PORT
DB_NAME=$DB_NAME
DB_USER=$DB_USER
DB_PASS=$DB_PASS
BOOKS_API_BASE=http://127.0.0.1:$STUB_PORT
INVENTORY_API_BASE=http://127.0.0.1:$STUB_PORT
MANAGE_API_BASE=http://127.0.0.1:$STUB_PORT
PULSE_API_ORIGIN=http://127.0.0.1:$STUB_PORT
CONTACTS_API_BASE=http://127.0.0.1:$STUB_PORT
BOOKS_SERVICE_KEY=test-books-key
INVENTORY_SERVICE_KEY=test-inventory-key
ENVEOF

php "$ROOT/bin/migrate.php" > /dev/null

# Several workers, so the concurrency tests reach it at the same time; the stub
# serialises its own state with a file lock, as the real services do per key.
PHP_CLI_SERVER_WORKERS=6 php -S "127.0.0.1:$STUB_PORT" "$ROOT/tests/stub/router.php" > /dev/null 2>&1 &
STUB_PID=$!
trap 'kill $STUB_PID 2>/dev/null || true' EXIT

# Wait for the stub rather than sleeping a guessed amount.
for _ in $(seq 1 40); do
  if curl -fsS --noproxy '*' "http://127.0.0.1:$STUB_PORT/api/companyinfo?comp_id=1" > /dev/null 2>&1; then break; fi
  sleep 0.25
done

# Both suites always run; the script fails if either did.
status=0
php "$ROOT/tests/integration.php" || status=$?
php "$ROOT/tests/ai_gateway.php" || status=$?
php "$ROOT/tests/remediation.php" || status=$?
exit $status
