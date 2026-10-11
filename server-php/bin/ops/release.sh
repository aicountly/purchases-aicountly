#!/usr/bin/env bash
# release.sh — staged, schema-before-code release ON THE SERVER (E8-OPS: F096, F159, F160, A23).
#
# CANONICAL COPY: console-react-app/ops/estate/kit/bin/ops/release.sh — identical in every product at
# server-php/bin/ops/release.sh. Per-product settings live in bin/ops/release.conf beside it.
#
# The deploy workflow rsyncs the new API into a STAGE folder outside the document root, then runs:
#
#   bash <stage>/bin/ops/release.sh --stage=<stage> --live=<docroot>/api --revision=<sha> \
#        [--backup-dir=~/aicountly-backups/<app>-<env>] [--snapshot=required|skip] [--jobs=install|skip] \
#        [--php=/path/to/php]
#
# Product settings (release.conf): APP, ENTRY, ENV_FILE, MIGRATE or MIGRATE_STEPS (php arguments, run in
# order), SNAPSHOT, PROTECT (runtime folders never deleted), PREPARE_SH (shell, in the stage, before the
# snapshot), POST_SWITCH (php first runs), POST_SWITCH_SH (shell hooks in the live folder after the switch).
#
# Order — the live code is not touched until the schema it needs is in place:
#   1. checks   stage has the front controller; live api/.env exists (first deploy: create it first)
#   2. php      a PHP CLI that satisfies release.conf (version, pdo_pgsql)
#   3. env      the live .env is copied into the stage (0600) for the duration, removed on exit
#   4. snapshot pg_dump of the live database through the app's own connection (bin/db-snapshot.php),
#               BEFORE any migration. --snapshot=required (default) makes a failed snapshot fatal.
#   5. migrate  the NEW migrations, from the stage, against the live database — expand-only by policy,
#               so the old code still serving keeps working against the new schema
#   6. switch   rsync stage -> live with --delete, keeping .env, uploads and other runtime folders
#               (release.conf: PROTECT), then REVISION is written (health reports it)
#   7. first    one run of each background job (release.conf: POST_SWITCH) so health has a heartbeat
#   8. jobs     bin/ops/install-jobs.sh --apply: the product's scheduled jobs (bin/ops/jobs.txt)
#
# Exit codes (the workflow turns them into messages):
#   0 released · 10 stage incomplete · 11 live api/.env missing · 12 no usable PHP · 13 snapshot failed
#   14 migration failed (live code untouched) · 15 an applied migration was edited (untouched)
#   16 migration lock held by another run (untouched) · 17 switch failed · 18 jobs install failed
#   19 stage preparation failed (untouched) · 20 post-switch hook failed (new code live)
set -uo pipefail

stage="" live="" revision="" backup_dir="" snapshot=required jobs=install php_bin=""
for arg in "$@"; do
  case "$arg" in
    --stage=*) stage="${arg#--stage=}" ;;
    --live=*) live="${arg#--live=}" ;;
    --revision=*) revision="${arg#--revision=}" ;;
    --backup-dir=*) backup_dir="${arg#--backup-dir=}" ;;
    --snapshot=*) snapshot="${arg#--snapshot=}" ;;
    --jobs=*) jobs="${arg#--jobs=}" ;;
    --php=*) php_bin="${arg#--php=}" ;;
    *) echo "release: unknown option $arg" >&2; exit 2 ;;
  esac
done
[ -n "$stage" ] && [ -n "$live" ] && [ -n "$revision" ] || { echo "release: --stage, --live and --revision are required" >&2; exit 2; }
case "$snapshot" in required | skip) ;; *) echo "release: --snapshot must be required or skip" >&2; exit 2 ;; esac
case "$jobs" in install | skip) ;; *) echo "release: --jobs must be install or skip" >&2; exit 2 ;; esac

stage="$(cd "$stage" 2>/dev/null && pwd)" || exit 10
conf="$stage/bin/ops/release.conf"
[ -f "$conf" ] || { echo "release: $conf is missing"; exit 10; }
# Defaults, overridden by release.conf (a file from the repository, not from the server).
APP="" MIN_PHP_ID=80100 ENTRY="index.php" ENV_FILE=".env" MIGRATE="bin/migrate.php" SNAPSHOT="bin/db-snapshot.php"
PROTECT=() POST_SWITCH=() PREPARE_SH=() MIGRATE_STEPS=() POST_SWITCH_SH=()
# shellcheck source=/dev/null
. "$conf"
[ -n "$APP" ] || { echo "release: release.conf sets no APP"; exit 10; }
[ -f "$stage/$ENTRY" ] || { echo "release: $stage/$ENTRY is missing — the upload is incomplete"; exit 10; }
mkdir -p "$live" 2>/dev/null || true
live="$(cd "$live" 2>/dev/null && pwd)" || exit 11
[ -f "$live/$ENV_FILE" ] || { echo "release: $live/$ENV_FILE does not exist — create it from .env.example first"; exit 11; }
[ -n "$backup_dir" ] || backup_dir="aicountly-backups/${APP}"
case "$backup_dir" in /*) ;; *) backup_dir="${HOME}/${backup_dir}" ;; esac

php_ok() {
  [ -x "$1" ] || return 1
  local v; v=$("$1" -r 'echo PHP_VERSION_ID;' 2>/dev/null) || return 1
  case "$v" in '' | *[!0-9]*) return 1 ;; esac
  [ "$v" -ge "$MIN_PHP_ID" ] || return 1
  # Captured first: with pipefail, `php -m | grep -q` fails at random when grep exits on the match
  # while php is still writing (SIGPIPE, exit 141) — which read as "no PHP CLI found".
  local m; m=$("$1" -m 2>/dev/null) || return 1
  case $'\n'"${m,,}"$'\n' in *$'\n'pdo_pgsql$'\n'*) return 0 ;; *) return 1 ;; esac
}
PHP=""
if [ -n "$php_bin" ]; then
  php_ok "$php_bin" && PHP="$php_bin"
else
  for c in /opt/cpanel/ea-php84/root/usr/bin/php /opt/cpanel/ea-php83/root/usr/bin/php \
           /opt/cpanel/ea-php82/root/usr/bin/php /opt/cpanel/ea-php81/root/usr/bin/php \
           /usr/local/bin/php /usr/bin/php "$(command -v php 2>/dev/null || true)"; do
    if [ -n "$c" ] && php_ok "$c"; then PHP="$c"; break; fi
  done
fi
[ -n "$PHP" ] || { echo "release: no PHP CLI (>= $MIN_PHP_ID with pdo_pgsql) found; set --php"; exit 12; }
echo "release: ${APP} ${revision} using $PHP ($("$PHP" -r 'echo PHP_VERSION;'))"

# The stage gets the live configuration only while this script runs.
umask 077
cp "$live/$ENV_FILE" "$stage/$ENV_FILE" || exit 11
cleanup() { rm -f "$stage/$ENV_FILE"; }
trap cleanup EXIT
umask 022

cd "$stage" || exit 10
export STAGE="$stage" LIVE="$live" PHP REVISION="$revision"
# Product preparation of the stage (release.conf PREPARE_SH), e.g. Books brings vendor/ in step with
# composer.lock. Runs in the stage with STAGE, LIVE, PHP and REVISION set; nothing live has changed yet.
for cmd in "${PREPARE_SH[@]}"; do
  echo "--- prepare: $cmd ---"
  bash -c "$cmd" || { echo "release: preparing the stage failed ($cmd); nothing live has changed"; exit 19; }
done
if [ "$snapshot" = required ]; then
  echo "--- snapshot (before any migration) ---"
  if ! "$PHP" "$SNAPSHOT" --dir="$backup_dir" --revision="$revision"; then
    echo "release: the pre-migration snapshot failed; nothing was migrated and the live code is untouched"
    exit 13
  fi
else
  echo "::warning::release: pre-migration snapshot SKIPPED by the operator for ${revision}"
fi

echo "--- migrate (from the stage, before the code switch) ---"
[ "${#MIGRATE_STEPS[@]}" -gt 0 ] || MIGRATE_STEPS=("$MIGRATE")
for step in "${MIGRATE_STEPS[@]}"; do
  echo "> php $step"
  # shellcheck disable=SC2086 # a step carries its arguments
  "$PHP" $step
  rc=$?
  case "$rc" in
    0) ;;
    2) exit 15 ;;
    3) exit 16 ;;
    *) exit 14 ;;
  esac
done

echo "--- switch ---"
rm -f "$stage/$ENV_FILE"
# Never deleted from the live folder: its .env (and .env.* other than the shipped .env.example), REVISION,
# git metadata and the runtime folders release.conf names in PROTECT (e.g. /writable/).
is_protected() { # ./relative/path
  local p="${1#./}" pr
  case "$p" in
    "$ENV_FILE" | .env.* | REVISION | REVISION.tmp | .git*) [ "$p" = .env.example ] || return 0 ;;
  esac
  for pr in "${PROTECT[@]}"; do
    pr="${pr#/}"; pr="${pr%/}"
    case "$p" in "$pr" | "$pr"/*) return 0 ;; esac
  done
  return 1
}
switch_ok=1
if command -v rsync >/dev/null 2>&1; then
  filters=(--exclude="/$ENV_FILE" --include='/.env.example' --exclude='/.env.*' --exclude='.git*' --exclude='/REVISION')
  for p in "${PROTECT[@]}"; do filters+=(--filter="P $p"); done
  rsync -a --delete "${filters[@]}" "$stage/" "$live/" || switch_ok=0
else
  # No rsync on this host: copy with tar, then remove what the stage no longer has (deepest first).
  ( cd "$stage" && tar -cf - --exclude="./$ENV_FILE" . ) | ( cd "$live" && tar -xf - ) || switch_ok=0
  if [ "$switch_ok" = 1 ]; then
    while IFS= read -r -d '' rel; do
      is_protected "$rel" && continue
      [ -e "$stage/${rel#./}" ] || [ -L "$stage/${rel#./}" ] || rm -rf -- "${live:?}/${rel#./}"
    done < <(cd "$live" && find . -mindepth 1 -depth -print0)
  fi
fi
if [ "$switch_ok" != 1 ]; then
  echo "release: the switch (stage -> live) failed; the schema is migrated (expand-only), the code may be mixed: re-run the deploy"
  exit 17
fi
printf '%s\n' "$revision" > "$live/REVISION.tmp" && mv -f "$live/REVISION.tmp" "$live/REVISION"
chmod 644 "$live/REVISION" 2>/dev/null || true
echo "release: live code is now ${revision}"

# One pass of each background job now, so health has a first heartbeat before the post-deploy check.
# Not fatal: a job reporting work to do (exit 1) is the job working.
for s in "${POST_SWITCH[@]}"; do
  echo "--- first run: $s ---"
  # shellcheck disable=SC2086
  ( cd "$live" && timeout 300 "$PHP" $s ) || echo "release: first run of $s exited $? (see its output above)"
done

# Product hooks on the live folder (release.conf POST_SWITCH_SH), e.g. Books' cpanel-post-deploy-api.sh.
for cmd in "${POST_SWITCH_SH[@]}"; do
  echo "--- post-switch: $cmd ---"
  ( cd "$live" && bash -c "$cmd" ) || { echo "release: the post-switch hook failed ($cmd); the new code IS live"; exit 20; }
done

if [ "$jobs" = install ] && [ -f "$live/bin/ops/jobs.txt" ]; then
  echo "--- scheduled jobs ---"
  bash "$live/bin/ops/install-jobs.sh" --apply --php="$PHP" --api-dir="$live" || exit 18
elif [ "$jobs" = skip ]; then
  echo "::warning::release: scheduled jobs NOT installed (jobs=skip)"
fi
exit 0
