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
# Purchases' own AI Pulse gateway key, for the test API's .env; the stub Pulse insists on it.
PULSE_KEY="test-pulse-gateway-key"

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
CONTACTS_API_BASE=http://127.0.0.1:$STUB_PORT/api
PORTAL_AUTH_BASE=http://127.0.0.1:$STUB_PORT
CORS_ALLOWED_ORIGINS=http://127.0.0.1:5173
BOOKS_SERVICE_KEY=test-books-key
INVENTORY_SERVICE_KEY=test-inventory-key
PULSE_SERVICE_KEY=$PULSE_KEY
ENVEOF

# Each run keeps the stub's state (its request log, control and mode files, idempotency store,
# documents, vouchers...) in a directory of its own. The stub and the suites find it through
# sys_get_temp_dir(), which reads TMPDIR — so two runs on one host (two checkouts, two ports)
# no longer read and reset each other's files (F448). STUB_STATE_DIR names one explicitly.
STATE_DIR="${STUB_STATE_DIR:-}"
if [ -z "$STATE_DIR" ]; then
  STATE_DIR="$(mktemp -d "${TMPDIR:-/tmp}/purchases-stub.${STUB_PORT}.XXXXXX")"
  OWN_STATE_DIR=1
fi
mkdir -p "$STATE_DIR"
export TMPDIR="$STATE_DIR"

php "$ROOT/bin/migrate.php" > /dev/null

# Where the database name and username come from (Console's SaaS Database Details, or DB_NAME / DB_USER) and what is
# said when they cannot be had: the resolver, /api/health, bin/db-check.php and bin/migrate.php. Needs the migrated
# database above; the cases that take a migration record away put it back.
php "$ROOT/tests/console_database.php"

# Several workers, so the concurrency tests reach it at the same time; the stub
# serialises its own state with a file lock, as the real services do per key.
STUB_PULSE_SERVICE_KEY="$PULSE_KEY" PHP_CLI_SERVER_WORKERS=6 php -S "127.0.0.1:$STUB_PORT" "$ROOT/tests/stub/router.php" > /dev/null 2>&1 &
STUB_PID=$!
cleanup() {
  kill $STUB_PID 2>/dev/null || true
  if [ "${OWN_STATE_DIR:-0}" = 1 ] && [ -n "${STATE_DIR:-}" ]; then rm -rf "${STATE_DIR:?}"; fi
}
trap cleanup EXIT

# Wait for the stub rather than sleeping a guessed amount.
for _ in $(seq 1 40); do
  if curl -fsS --noproxy '*' "http://127.0.0.1:$STUB_PORT/api/companyinfo?comp_id=1" > /dev/null 2>&1; then break; fi
  sleep 0.25
done

# Both suites always run; the script fails if either did.
status=0
php "$ROOT/tests/integration.php" || status=$?
php "$ROOT/tests/ai_gateway.php" || status=$?
# E8-ID: environment from configuration only (never Host), unreadable 2xx = outcome unknown.
php "$ROOT/tests/environment.php" || status=$?
# E8-ID: portal/Manage outage semantics over real HTTP through index.php (fault proxy in front of the stub).
STUB_PULSE_SERVICE_KEY="$PULSE_KEY" E8ID_API_PORT="${E8ID_API_PORT:-$((STUB_PORT+20))}" E8ID_IDENTITY_FAULT_PORT="${E8ID_IDENTITY_FAULT_PORT:-$((STUB_PORT+21))}" php "$ROOT/tests/identity_outage.php" || status=$?
php "$ROOT/tests/remediation.php" || status=$?
# Launch readiness 2026-10 (E8-PUR): one check per finding, each failing before its correction.
php "$ROOT/tests/launch_readiness.php" || status=$?
# The HTTP gate: the real front controller under php -S, every route, every caller (F219).
API_PORT="${API_PORT:-$((STUB_PORT + 1))}" php "$ROOT/tests/http_gate.php" || status=$?
# Against a REAL Contacts when CONTACTS_CONTRACT_BASE is set (the e2e harness); skips otherwise.
php "$ROOT/tests/contacts_contract.php" || status=$?
# Against the REAL Inventory (its own front controller on a disposable database) when
# INVENTORY_CONTRACT_ROOT and INVENTORY_CONTRACT_DB are set; skips otherwise (E8-PUR F222/F223).
php "$ROOT/tests/contract_receivers.php" || status=$?
exit $status
