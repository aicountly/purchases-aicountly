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
API_PORT="${API_PORT:-8793}"
# Purchases' own AI Pulse gateway key, for the test API's .env; the stub Pulse insists on it.
PULSE_KEY="test-pulse-gateway-key"


# E8-OPS guard (L07-137): this script OVERWRITES server-php/.env and truncates tables. Never on a server:
# refuse when an existing .env is not a local/test one (an unset APP_ENV counts as production here).
if [ -f "$ROOT/.env" ] && [ "${TEST_ALLOW_ENV_OVERWRITE:-0}" != 1 ]; then
  existing_env="$(sed -n 's/^[[:space:]]*APP_ENV[[:space:]]*=[[:space:]]*["'"'"']\{0,1\}\([A-Za-z]*\).*/\1/p' "$ROOT/.env" | tail -n 1 | tr 'A-Z' 'a-z')"
  case "$existing_env" in
    local | test | testing | development | dev) ;;
    *) echo "Refusing to run: $ROOT/.env is not a local/test configuration (APP_ENV='${existing_env:-unset}'). These tests overwrite it." >&2; exit 2 ;;
  esac
fi

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
INVENTORY_SERVICE_KEY=test-inventory-key
PULSE_SERVICE_KEY=$PULSE_KEY
SERVICE_KEYS=books:test-books-service-key-0001,insights:test-insights-service-key-01
BOOKS_EVENTS_SIGNING_SECRET=test-books-events-secret-0123456789
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
# The Purchases API itself, through its real front controller, for the routes other products call
# over HTTP (Books' voucher events, E8-BK-POST A16).
PHP_CLI_SERVER_WORKERS=2 php -S "127.0.0.1:$API_PORT" -t "$ROOT" "$ROOT/index.php" > /dev/null 2>&1 &
API_PID=$!
cleanup() {
  kill $STUB_PID $API_PID 2>/dev/null || true
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
PURCHASES_E2E_BASE="http://127.0.0.1:$API_PORT" BOOKS_EVENTS_SIGNING_SECRET_FOR_TESTS=test-books-events-secret-0123456789 php "$ROOT/tests/integration.php" || status=$?
# Company lifecycle (docs/COMPANY_LIFECYCLE.md): the reviewed purge manifest on this migrated schema, the
# allow-list, Manage's signed events, the 423 write gate and the purge worker's signed ack.
php "$ROOT/tests/company_lifecycle.php" || status=$?
php "$ROOT/tests/ai_gateway.php" || status=$?
# E8-ID: environment from configuration only (never Host), unreadable 2xx = outcome unknown.
php "$ROOT/tests/environment.php" || status=$?
# E8-ID: portal/Manage outage semantics over real HTTP through index.php (fault proxy in front of the stub).
STUB_PULSE_SERVICE_KEY="$PULSE_KEY" E8ID_API_PORT="${E8ID_API_PORT:-$((STUB_PORT+20))}" E8ID_IDENTITY_FAULT_PORT="${E8ID_IDENTITY_FAULT_PORT:-$((STUB_PORT+21))}" php "$ROOT/tests/identity_outage.php" || status=$?
php "$ROOT/tests/remediation.php" || status=$?
# Launch readiness 2026-10 (E8-PUR): one check per finding, each failing before its correction.
php "$ROOT/tests/launch_readiness.php" || status=$?
# Launch readiness phase 2: health `ready` with the documented settings, the GST purchase order, a new
# supplier from the order editor, sticky removal of a workspace member, headers and the manual CI.
php "$ROOT/tests/launch_readiness_p2.php" || status=$?
# Register filters: GST quick filters and amount / supplier on the list screens, as SQL on real rows.
php "$ROOT/tests/register_filters.php" || status=$?
# The fleet alignment: plan limits from companyinfo's plan, workspace-wide access seated on first entry,
# and Manage's signed seat route (POST internal/manage/seats), on real rows and the stub's Manage.
php "$ROOT/tests/plans_and_seats.php" || status=$?
# The HTTP gate: the real front controller under php -S, every route, every caller (F219).
# Its own port: the API started above keeps API_PORT for the whole run.
API_PORT="${HTTP_GATE_PORT:-$((STUB_PORT + 2))}" php "$ROOT/tests/http_gate.php" || status=$?
# Against a REAL Contacts when CONTACTS_CONTRACT_BASE is set (the e2e harness); skips otherwise.
php "$ROOT/tests/contacts_contract.php" || status=$?
# Against the REAL Inventory (its own front controller on a disposable database) when
# INVENTORY_CONTRACT_ROOT and INVENTORY_CONTRACT_DB are set; skips otherwise (E8-PUR F222/F223).
php "$ROOT/tests/contract_receivers.php" || status=$?
# The same-origin relays to Manage's company registry (/api/manage/v1/*) and the portal's logout:
# the allowlist against web/src/company-access/companyRegistryClient.ts, the refusals, and real HTTP
# through index.php against a fake Manage (headers, body, status, 504 when Manage does not answer).
MANAGE_RELAY_API_PORT="${MANAGE_RELAY_API_PORT:-$((STUB_PORT+40))}" php "$ROOT/tests/manage_relay.php" || status=$?
exit $status
