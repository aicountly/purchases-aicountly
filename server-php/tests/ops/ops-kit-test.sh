#!/usr/bin/env bash
# ops-kit-test.sh — the E8-OPS ops kit, end to end, on a real PostgreSQL and the app's real code.
#
# CANONICAL COPY: console-react-app/ops/estate/kit/tests/ops-kit-test.sh (identical in each product at
# server-php/tests/ops/ops-kit-test.sh). The product's server-php/tests/ops/run.sh sets the variables
# below and runs it. Never touches the product's own .env: it works on two COPIES of server-php (a
# "live" one at the previous commit's schema and a "stage" one), in a temporary folder.
#
# Proves, against PostgreSQL and the product's own migrate/health/jobs code:
#   1. migrate.php holds an advisory lock: a held lock makes a second run exit 3 changing nothing, and two
#      concurrent runs on a fresh database both end 0 with every file applied exactly once
#   2. release.sh: snapshot BEFORE migrate (manifest with exact row counts), migrate from the stage while
#      the live copy still has the old code, switch (live gets the new files, keeps .env, REVISION written),
#      a first run of the background job, and the crontab block installed (fake crontab) — idempotently
#   3. a failing migration stops the release with exit 14 and leaves the live code untouched
#   4. restore-drill.php restores the snapshot into a NEW database and every table's rows match
#   5. /api/health over real HTTP (php -S on the live copy) reports revision, readiness and job state
#   6. preflight-receivers.sh against the app's own real server: an existing route passes, a missing
#      route and an unreachable receiver fail
#
# Env: APP (slug), MIGTABLE, DB_ADMIN_URL (postgresql://postgres:postgres@127.0.0.1:5432/postgres),
#      PORT (free 127.0.0.1 port), HEALTH_READY_FILTER (jq filter that must hold once the job has run),
#      ROUTE_OK ("METHOD /api/path" served by this app), DB_PREFIX (default e8op_), EXTRA_ENV (more
#      .env lines, e.g. a throwaway encryption key).
set -uo pipefail
: "${APP:?}" "${MIGTABLE:?}" "${PORT:?}" "${ROUTE_OK:?}"
DB_ADMIN_URL="${DB_ADMIN_URL:-postgresql://postgres:postgres@127.0.0.1:5432/postgres}"
DB_PREFIX="${DB_PREFIX:-e8op_}"
HEALTH_READY_FILTER="${HEALTH_READY_FILTER:-.usable == true}"
SRC="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"   # server-php
REPO="$(cd "$SRC/.." && pwd)"
case "$DB_PREFIX" in e8op_* | test_* | ci_*) ;; *) echo "refusing DB_PREFIX=$DB_PREFIX" >&2; exit 2 ;; esac
DB="${DB_PREFIX}${APP}_kit"
ROLE="${DB_PREFIX}${APP}_kit"
PASS="kit-$(date +%s)"
WORK="$(mktemp -d "${TMPDIR:-/tmp}/${DB_PREFIX}${APP}_kit.XXXXXX")"
pass=0 fail=0
ok() { echo "PASS $*"; pass=$((pass + 1)); }
no() { echo "FAIL $*"; fail=$((fail + 1)); }
psql_admin() { psql "$DB_ADMIN_URL" -v ON_ERROR_STOP=1 -qAt "$@"; }
dburl() { echo "postgresql://${ROLE}:${PASS}@127.0.0.1:5432/$1"; }
SERVER_PID=""
cleanup() {
  [ -n "$SERVER_PID" ] && kill "$SERVER_PID" 2>/dev/null
  for d in "$DB" "${DB}2" "${DB_PREFIX}${APP}_restore_drill"; do psql_admin -c "DROP DATABASE IF EXISTS \"$d\"" >/dev/null 2>&1; done
  psql_admin -c "DROP ROLE IF EXISTS \"$ROLE\"" >/dev/null 2>&1
  rm -rf "${WORK:?}"
}
trap cleanup EXIT

psql_admin -c "DROP DATABASE IF EXISTS \"$DB\"" -c "DROP DATABASE IF EXISTS \"${DB}2\"" >/dev/null
psql_admin -c "DROP ROLE IF EXISTS \"$ROLE\"" >/dev/null
psql_admin -c "CREATE ROLE \"$ROLE\" LOGIN PASSWORD '$PASS' CREATEDB" >/dev/null
psql_admin -c "CREATE DATABASE \"$DB\" OWNER \"$ROLE\"" -c "CREATE DATABASE \"${DB}2\" OWNER \"$ROLE\"" >/dev/null

write_env() { # dir db
  cat > "$1/.env" <<EOF
APP_ENV=local
APP_PRODUCT_KEY=${APP}
DB_HOST=127.0.0.1
DB_PORT=5432
DB_NAME=$2
DB_USER=${ROLE}
DB_PASS=${PASS}
${EXTRA_ENV:-}
EOF
  chmod 600 "$1/.env"
}

copy_src() { # dest
  mkdir -p "$1"
  (cd "$SRC" && tar --exclude='./.env' --exclude='./tests' --exclude='./vendor' -cf - .) | (cd "$1" && tar -xf -)
}

# ---------------------------------------------------------------- 1. migration lock
copy_src "$WORK/m"
write_env "$WORK/m" "${DB}2"
( cd "$WORK/m" && php bin/migrate.php >/dev/null 2>&1 ) &
a=$!
( cd "$WORK/m" && php bin/migrate.php >/dev/null 2>&1 ) &
b=$!
wait $a; ra=$?; wait $b; rb=$?
files=$(ls "$SRC"/database/migrations/*.sql | wc -l)
rows=$(psql "$(dburl "${DB}2")" -qAt -c "SELECT COUNT(*), COUNT(DISTINCT filename) FROM ${MIGTABLE}")
if [ "$ra" = 0 ] && [ "$rb" = 0 ] && [ "$rows" = "${files}|${files}" ]; then ok "two concurrent migrate runs both exit 0; ${files} files applied once each"; else no "concurrent migrate: exits $ra/$rb rows $rows files $files"; fi

coproc HOLD { psql "$(dburl "${DB}2")" -qAt; }
echo "SELECT pg_advisory_lock(hashtext('aicountly-${APP}-migrate')); SELECT 'held';" >&"${HOLD[1]}"
read -r -t 10 _ <&"${HOLD[0]}"; read -r -t 10 _ <&"${HOLD[0]}"
( cd "$WORK/m" && MIGRATE_LOCK_WAIT_SECONDS=2 php bin/migrate.php >"$WORK/lock.out" 2>&1 ); rc=$?
if [ "$rc" = 3 ] && grep -q LOCKED "$WORK/lock.out"; then ok "a held migration lock makes the second run exit 3 (LOCKED)"; else no "held lock: exit $rc $(cat "$WORK/lock.out")"; fi
echo "SELECT pg_advisory_unlock_all();" >&"${HOLD[1]}"; exec {HOLD[1]}>&-; wait "$HOLD_PID" 2>/dev/null
( cd "$WORK/m" && php bin/migrate.php >/dev/null 2>&1 ); rc=$?
[ "$rc" = 0 ] && ok "after the lock is released migrate runs (exit 0)" || no "after release exit $rc"

# ---------------------------------------------------------------- 2. release
# "live" = the API at the schema before the newest migration; "stage" = this commit.
copy_src "$WORK/live"
newest="$(ls "$SRC"/database/migrations/*.sql | sort | tail -n 1)"
rm -f "$WORK/live/database/migrations/$(basename "$newest")"
write_env "$WORK/live" "$DB"
( cd "$WORK/live" && php bin/migrate.php >/dev/null ) || no "live migrate"
psql "$(dburl "$DB")" -qAt -c "CREATE TABLE kit_payload (id serial primary key, v text); INSERT INTO kit_payload (v) SELECT 'row ' || g FROM generate_series(1, 25) g" >/dev/null
echo "old" > "$WORK/live/kit-old-file.txt"
copy_src "$WORK/stage"
CRON="$WORK/crontab.txt"; : > "$CRON"
cat > "$WORK/fakecrontab" <<EOF
#!/usr/bin/env bash
if [ "\${1:-}" = "-l" ]; then cat "$CRON"; elif [ "\${1:-}" = "-" ]; then cat > "$CRON"; fi
EOF
chmod +x "$WORK/fakecrontab"
export CRONTAB_BIN="$WORK/fakecrontab"
REV="$(git -C "$REPO" rev-parse HEAD 2>/dev/null || echo 0123456789abcdef0123456789abcdef01234567)"
HOME_SAVE="$HOME"; export HOME="$WORK/home"; mkdir -p "$HOME"
bash "$WORK/stage/bin/ops/release.sh" --stage="$WORK/stage" --live="$WORK/live" --revision="$REV" \
  --backup-dir="$WORK/backups" --php="$(command -v php)" >"$WORK/release.out" 2>&1; rc=$?
if [ "$rc" = 0 ]; then ok "release.sh exit 0"; else no "release.sh exit $rc: $(tail -n 20 "$WORK/release.out")"; fi
manifest="$(ls "$WORK"/backups/"$APP"-*.manifest.json 2>/dev/null | head -n 1)"
if [ -n "$manifest" ] && [ "$(jq -r '.tables["public.kit_payload"]' "$manifest")" = 25 ] && [ "$(jq -r '.migrations.rows' "$manifest")" = "$((files - 1))" ]; then
  ok "snapshot taken BEFORE migrate: manifest has 25 payload rows and $((files - 1)) migrations (the newest not yet applied)"
else no "snapshot manifest: $(cat "$manifest" 2>/dev/null | head -c 400)"; fi
[ "$(stat -c %a "$(dirname "$manifest")")" = 700 ] && ok "backup folder is 0700" || no "backup folder mode"
grep -q 'snapshot (before any migration)' "$WORK/release.out" && [ "$(grep -n 'snapshot (before' "$WORK/release.out" | cut -d: -f1)" -lt "$(grep -n '^--- migrate' "$WORK/release.out" | cut -d: -f1)" ] \
  && ok "release order: snapshot, then migrate" || no "release order"
applied=$(psql "$(dburl "$DB")" -qAt -c "SELECT COUNT(*) FROM ${MIGTABLE}")
[ "$applied" = "$files" ] && ok "every migration applied ($files)" || no "applied $applied of $files"
[ -f "$WORK/live/database/migrations/$(basename "$newest")" ] && [ ! -f "$WORK/live/kit-old-file.txt" ] && ok "switch: live has the new files, stale files removed" || no "switch contents"
[ -f "$WORK/live/.env" ] && grep -q "DB_NAME=$DB" "$WORK/live/.env" && ok "switch kept the live .env" || no "live .env lost"
[ ! -f "$WORK/stage/.env" ] && ok "the stage no longer holds a copy of .env" || no "stage .env left behind"
[ "$(cat "$WORK/live/REVISION")" = "$REV" ] && ok "REVISION written ($REV)" || no "REVISION"
grep -q "# >>> aicountly-${APP} jobs: $WORK/live >>>" "$CRON" && ok "crontab block installed" || no "crontab block: $(cat "$CRON")"
bash "$WORK/live/bin/ops/install-jobs.sh" --check --php="$(command -v php)" --api-dir="$WORK/live" >/dev/null && ok "install-jobs --check: installed block matches jobs.txt" || no "install-jobs --check"
before="$(cat "$CRON")"
# Snapshot names carry the second; a second release within the same second as the first would reuse the
# name and leave one dump (a flake of this test, not of release.sh).
sleep 1
bash "$WORK/stage/bin/ops/release.sh" --stage="$WORK/stage" --live="$WORK/live" --revision="$REV" \
  --backup-dir="$WORK/backups" --php="$(command -v php)" >"$WORK/release2.out" 2>&1 || no "second release"
[ "$before" = "$(cat "$CRON")" ] && [ "$(grep -c '>>> aicountly-' "$CRON")" = 1 ] && ok "second release: crontab unchanged, one block" || no "crontab not idempotent"
[ "$(ls "$WORK"/backups/"$APP"-*.dump | wc -l)" = 2 ] && ok "second release took its own snapshot" || no "snapshots: $(ls "$WORK"/backups)"

# ---------------------------------------------------------------- 3. failing migration leaves live untouched
cp -r "$WORK/stage" "$WORK/stage-bad"
echo "SELECT * FROM kit_table_that_does_not_exist;" > "$WORK/stage-bad/database/migrations/999_kit_broken.sql"
echo "marker" > "$WORK/live/kit-marker.txt"
bash "$WORK/stage-bad/bin/ops/release.sh" --stage="$WORK/stage-bad" --live="$WORK/live" --revision=deadbeefdeadbeef \
  --backup-dir="$WORK/backups" --php="$(command -v php)" >"$WORK/release3.out" 2>&1; rc=$?
if [ "$rc" = 14 ] && [ "$(cat "$WORK/live/REVISION")" = "$REV" ] && [ -f "$WORK/live/kit-marker.txt" ] && [ ! -f "$WORK/live/database/migrations/999_kit_broken.sql" ]; then
  ok "a failing migration stops the release (exit 14) and the live code is untouched"
else no "failing migration: exit $rc"; fi
rm -f "$WORK/live/kit-marker.txt"

# ---------------------------------------------------------------- 4. restore drill
first_manifest="$(ls "$WORK"/backups/"$APP"-*.manifest.json | sort | head -n 1)"
RESTORE_DRILL_ADMIN_URL="$DB_ADMIN_URL" php "$WORK/live/bin/ops/restore-drill.php" --manifest="$first_manifest" \
  --target-db="${DB_PREFIX}${APP}_restore_drill" --evidence="$WORK/drill.json" >"$WORK/drill.out" 2>&1; rc=$?
if [ "$rc" = 0 ] && [ "$(jq -r .result "$WORK/drill.json")" = passed ] && [ "$(jq -r '[.checks[] | select(.ok)] | length' "$WORK/drill.json")" -ge 4 ]; then
  ok "restore drill passed: $(jq -r '.checks[] | .check' "$WORK/drill.json" | tr '\n' ',' ) $(jq -r .restored_rows "$WORK/drill.json") rows"
else no "restore drill exit $rc: $(tail -n 15 "$WORK/drill.out")"; fi
RESTORE_DRILL_ADMIN_URL="$DB_ADMIN_URL" php "$WORK/live/bin/ops/restore-drill.php" --manifest="$first_manifest" --target-db="$DB" >/dev/null 2>&1; rc=$?
[ "$rc" = 2 ] && ok "restore drill refuses a target that is not a drill database" || no "drill target guard exit $rc"

# ---------------------------------------------------------------- 5. health over HTTP
# As deployed: the API is the api/ folder of a document root (index.php strips its /api mount point).
mkdir -p "$WORK/docroot" && ln -s "$WORK/live" "$WORK/docroot/api"
( cd "$WORK/docroot" && exec php -S "127.0.0.1:${PORT}" -t "$WORK/docroot" "$WORK/docroot/api/index.php" >"$WORK/server.log" 2>&1 ) &
SERVER_PID=$!
for _ in $(seq 1 40); do curl -fsS --noproxy '*' "http://127.0.0.1:${PORT}/api/health" -o "$WORK/health.json" 2>/dev/null && break; sleep 0.25; done
if jq -e ".revision == \"$REV\"" "$WORK/health.json" >/dev/null 2>&1; then ok "health reports revision $REV"; else no "health revision: $(head -c 300 "$WORK/health.json")"; fi
if jq -e "$HEALTH_READY_FILTER" "$WORK/health.json" >/dev/null 2>&1; then ok "health: $HEALTH_READY_FILTER"; else no "health filter $HEALTH_READY_FILTER: $(head -c 600 "$WORK/health.json")"; fi
if jq -e '.ready == false' "$WORK/health.json" >/dev/null 2>&1; then ok "health is honestly not ready (the cross-app settings are unset in this test)"; else no "health.ready should be false without the settings: $(jq -c '{ready, config, books_delivery}' "$WORK/health.json")"; fi

# ---------------------------------------------------------------- 6. receiver preflight against the real app
read -r rm rp <<< "$ROUTE_OK"
printf 'self | http://127.0.0.1:%s | http://127.0.0.1:%s | route %s %s\n' "$PORT" "$PORT" "$rm" "$rp" > "$WORK/recv-ok.txt"
bash "$REPO/scripts/ops/preflight-receivers.sh" sandbox "$WORK/recv-ok.txt" >"$WORK/pf1.out" 2>&1 && ok "preflight: an existing route passes ($ROUTE_OK)" || no "preflight ok: $(cat "$WORK/pf1.out")"
printf 'self | http://127.0.0.1:%s | http://127.0.0.1:%s | route GET /api/v1/__kit_missing_route__\n' "$PORT" "$PORT" > "$WORK/recv-missing.txt"
bash "$REPO/scripts/ops/preflight-receivers.sh" sandbox "$WORK/recv-missing.txt" >"$WORK/pf2.out" 2>&1; rc=$?
[ "$rc" = 1 ] && grep -q 'does not serve' "$WORK/pf2.out" && ok "preflight: a missing route stops the deploy (exit 1)" || no "preflight missing: exit $rc $(cat "$WORK/pf2.out")"
printf 'self | http://127.0.0.1:1 | http://127.0.0.1:1 | route %s %s\n' "$rm" "$rp" > "$WORK/recv-down.txt"
bash "$REPO/scripts/ops/preflight-receivers.sh" sandbox "$WORK/recv-down.txt" >"$WORK/pf3.out" 2>&1; rc=$?
[ "$rc" = 1 ] && ok "preflight: an unreachable receiver stops the deploy (exit 1)" || no "preflight down: exit $rc"
printf 'self | http://127.0.0.1:%s | http://127.0.0.1:%s | health /api/health .revision == "%s"\n' "$PORT" "$PORT" "$REV" > "$WORK/recv-health.txt"
bash "$REPO/scripts/ops/preflight-receivers.sh" production "$WORK/recv-health.txt" >/dev/null 2>&1 && ok "preflight: a health line passes on the receiver's real revision" || no "preflight health line"
# ---------------------------------------------------------------- 7. stale cross-app commands (F262)
if [ -n "${COMMANDS_TABLE:-}" ]; then
  # One FAILED command untouched for an hour; every NOT NULL column without a default gets a typed dummy.
  php -r '
    $pdo = new PDO("pgsql:host=127.0.0.1;port=5432;dbname=" . $argv[1], $argv[2], $argv[3], [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    $cols = $pdo->query("SELECT column_name, data_type FROM information_schema.columns WHERE table_name = " . $pdo->quote($argv[4]) . " AND is_nullable = '"'"'NO'"'"' AND column_default IS NULL")->fetchAll(PDO::FETCH_ASSOC);
    $names = []; $vals = [];
    foreach ($cols as $c) {
      $names[] = $c["column_name"];
      $vals[] = match (true) {
        $c["column_name"] === "status" => "'"'"'FAILED'"'"'",
        str_contains($c["data_type"], "int") || $c["data_type"] === "numeric" => "1",
        str_contains($c["data_type"], "json") => "'"'"'{}'"'"'",
        str_contains($c["data_type"], "timestamp") => "NOW() - INTERVAL '"'"'1 hour'"'"'",
        $c["data_type"] === "boolean" => "false",
        $c["data_type"] === "uuid" => "gen_random_uuid()",
        default => "'"'"'kit-" . bin2hex(random_bytes(4)) . "'"'"'",
      };
    }
    if (!in_array("status", $names, true)) { $names[] = "status"; $vals[] = "'"'"'FAILED'"'"'"; }
    $pdo->exec("INSERT INTO " . $argv[4] . " (" . implode(",", $names) . ") VALUES (" . implode(",", $vals) . ")");
    $pdo->exec("UPDATE " . $argv[4] . " SET updated_at = NOW() - INTERVAL '"'"'1 hour'"'"'");
  ' "$DB" "$ROLE" "$PASS" "$COMMANDS_TABLE" 2>"$WORK/insert.err" || no "could not insert a test command: $(cat "$WORK/insert.err")"
  ( cd "$WORK/live" && php bin/command-monitor.php --json >"$WORK/monitor.json" 2>&1 ); rc=$?
  if [ "$rc" = 1 ] && jq -e '.stale == 1 and .stale_by_status.FAILED == 1' "$WORK/monitor.json" >/dev/null 2>&1; then
    ok "command monitor: a FAILED command untouched for an hour is reported stale (exit 1)"
  else no "command monitor: exit $rc $(head -c 400 "$WORK/monitor.json")"; fi
  curl -fsS --noproxy '*' "http://127.0.0.1:${PORT}/api/health" -o "$WORK/health2.json" 2>/dev/null
  jq -e '.commands.stale == 1 and .monitor.last_ok == false and (tostring | test("kit-") | not)' "$WORK/health2.json" >/dev/null 2>&1 \
    && ok "health: commands.stale = 1, monitor.last_ok = false, no command identifiers exposed" || no "health after stale: $(jq -c '{commands, monitor}' "$WORK/health2.json")"
fi

for f in "$REPO/scripts/ops/receivers.txt"; do
  awk -F'|' '!/^#/ && NF>=4 {n++} END {exit n>0?0:1}' "$f" && ok "receivers.txt lists at least one requirement" || no "receivers.txt empty"
done
export HOME="$HOME_SAVE"

echo "ops kit (${APP}): ${pass} passed, ${fail} failed"
[ "$fail" = 0 ]
