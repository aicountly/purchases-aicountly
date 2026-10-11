#!/usr/bin/env bash
# E8-OPS ops kit test for purchases: migration lock, staged release (snapshot -> migrate -> switch -> jobs),
# restore drill, health over HTTP and the receiver preflight — on a real PostgreSQL. See ops-kit-test.sh.
#   DB_ADMIN_URL=postgresql://postgres:postgres@127.0.0.1:5432/postgres bash server-php/tests/ops/run.sh
set -euo pipefail
export APP=purchases MIGTABLE=purchase_sql_migrations PORT=${PORT:-33813}
export COMMANDS_TABLE=purchase_integration_commands
export ROUTE_OK="GET /api/v1/purchase-orders"
export HEALTH_READY_FILTER='.usable == true and .monitor.state == "running" and .commands.readable == true'
exec bash "$(dirname "${BASH_SOURCE[0]}")/ops-kit-test.sh"
