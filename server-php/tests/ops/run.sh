#!/usr/bin/env bash
# E8-OPS ops kit test for purchases: migration lock, staged release (snapshot -> migrate -> switch -> jobs),
# restore drill, health over HTTP and the receiver preflight — on a real PostgreSQL. See ops-kit-test.sh.
#   DB_ADMIN_URL=postgresql://postgres:postgres@127.0.0.1:5432/postgres bash server-php/tests/ops/run.sh
set -euo pipefail
export APP=purchases MIGTABLE=purchase_sql_migrations PORT=${PORT:-33813}
export COMMANDS_TABLE=purchase_integration_commands
export ROUTE_OK="GET /api/v1/purchase-orders"
export HEALTH_READY_FILTER='.usable == true and .monitor.state == "running" and .commands.readable == true'
here="$(dirname "${BASH_SOURCE[0]}")"
rc=0
bash "$here/ops-kit-test.sh" || rc=1
# The real index.php across two releases behind the atomic entry guard (no database needed).
PORT=$((PORT + 1)) bash "$here/atomic-entry-test.sh" || rc=1
exit "$rc"
