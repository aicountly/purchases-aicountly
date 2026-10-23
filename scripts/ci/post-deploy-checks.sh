#!/usr/bin/env bash
# post-deploy-checks.sh — is the live Purchases really this app, and is it the build just deployed?
#
# Run by the deploy workflows after a deploy, and by verify-live.yml to check the live app without
# deploying anything. Every check goes through verify-live.sh, which passes only on the app's real
# answer, never on the host's anti-bot page, and repeats the request from the server (VERIFY_SSH)
# when the runner is shown that page.
#
# Usage: scripts/ci/post-deploy-checks.sh <production|sandbox>
#
# Environment:
#   VERIFY_SSH         command prefix that runs one command on the server ("ssh deploy-target"
#                      in the workflows); see verify-live.sh. Set: the must-not-be-served probes
#                      are asked from the server only.
#   EXPECTED_ENTRY     the hashed entry script of the build just deployed, e.g.
#                      assets/index-C5tx8mVh.js from web/dist/index.html. Empty (checking without
#                      a deploy): the page's <title> is checked instead.
#   EXPECTED_REVISION  the commit just deployed; the API reports it (REVISION, written by
#                      bin/ops/release.sh) and it must match (E8-OPS, F162).
#   VERIFY_BASE_URL    tests only: check this origin (e.g. http://127.0.0.1:18777) instead of the
#                      environment's real one.
set -uo pipefail

target="${1:-}"
case "$target" in
  production) origin="https://purchase.aicountly.com" ;;
  sandbox) origin="https://purchase.gh.aicountly.com" ;;
  *) echo "usage: $0 <production|sandbox>" >&2; exit 2 ;;
esac
base="${VERIFY_BASE_URL:-$origin}"
verify="$(cd "$(dirname "$0")" && pwd)/verify-live.sh"
entry="${EXPECTED_ENTRY:-}"

echo "Checking Purchases ${target} at ${base}"

failed=0
# check <verify-live.sh arguments...>: one check; a failure is counted, the rest still run.
check() {
  bash "$verify" "$@" || failed=$((failed + 1))
}

# It is the Purchases API (fatal). usable:false means the database is unreachable or its schema is
# missing: configuration on the server, not this deploy, so it only warns. A console_* reason in
# .database.reason means the database name and username could not be had from Console
# (CONSOLE_API_URL / CONSOLE_DB_DETAILS_KEY in api/.env): run php bin/db-check.php in api/ on the host.
check json "Purchases API (${target})" "${base}/api/health" \
  '.app == "Purchases"' \
  '.usable == true'

# E8-OPS (F162): the API reports the commit bin/ops/release.sh wrote to REVISION; it must be this one.
if [ -n "${EXPECTED_REVISION:-}" ]; then
  check json "Purchases revision (${target})" "${base}/api/health" ".revision == \"${EXPECTED_REVISION}\""
fi

# E8-OPS (F005/F128/F199/F262): in PRODUCTION a deploy is not done until the app is usable, the
# settings its cross-app journeys need are present (health: config / books_delivery, booleans only)
# and its background job has run (bin/ops/release.sh installs it and runs it once). Fatal there;
# a warning on sandbox, whose configuration may legitimately lag.
# `ready` needs exactly the settings in OpsStatus::REQUIRED_CONFIG (config.items: INVENTORY_SERVICE_KEY,
# BOOKS_EVENTS_SIGNING_SECRET, a books entry in SERVICE_KEYS, LIFECYCLE_WEBHOOK_SECRET, MANAGE_API_URL) —
# docs/launch-readiness-2026-10/OPERATOR_RUNBOOK.md. There is no BOOKS_SERVICE_KEY to set.
if [ "$target" = production ]; then
  check json "Purchases ready (${target})" "${base}/api/health" '.usable == true and .ready == true'
else
  check json "Purchases ready (${target})" "${base}/api/health" '.app == "Purchases"' '.ready == true'
fi
# Optional settings (config.warnings: PULSE_SERVICE_KEY, MANAGE_PRODUCT_SERVICE_KEY) are reported and
# never fail a deploy: AI falls back to rules; a purge runs without Manage's reversal check.
bash "$verify" json "Purchases optional settings (${target}, warning only)" "${base}/api/health" \
  '(.config.warnings // []) == []' \
  || echo "WARNING: optional settings missing on Purchases ${target} (health config.warnings); see OPERATOR_RUNBOOK.md."

# The web root serves the build just deployed, or at least the Purchases page (fatal).
if [ -n "$entry" ]; then
  check page "Purchases web (${target})" "${base}/" "$entry"
else
  check page "Purchases web (${target})" "${base}/" '<title>Purchases · Aicountly</title>'
fi

# Must never be served: what the deploy leaves under the document root that is a secret, a log,
# SQL, tests, scripts or dependency manifests. Read-only GETs of the first 64 KB, never of a .php
# file under tests/, bin/ or scripts/ (a GET would run it); a failure logs the status, type and
# size of what was served, never its content. /.git/HEAD may instead get the SPA's own page.
# With VERIFY_SSH they are asked from the server itself, never from the runner (verify-live.sh,
# absent): the host's WAF graylists a runner that asks for these, and its SSH with it.
for path in /api/.env /api/.env.example /api/error_log \
  /api/database/migrations/001_purchase_core.sql /api/tests/run.sh /api/bin/; do
  check absent "Purchases ${path} must not be served (${target})" "${base}${path}"
done
check absent "Purchases /.git/HEAD must not be served (${target})" "${base}/.git/HEAD" \
  '<title>Purchases · Aicountly</title>'

if [ "$failed" -gt 0 ]; then
  echo "${failed} post-deploy check(s) failed for Purchases ${target}."
  exit 1
fi
echo "All post-deploy checks passed for Purchases ${target}."
