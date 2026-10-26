#!/usr/bin/env bash
# atomic-entry-test.sh — this product's REAL front controller across two releases, as ATOMIC=1 deploys it.
#
#   bash server-php/tests/ops/atomic-entry-test.sh        (php and curl; no database needed)
#
# With ATOMIC=1 (bin/ops/release.conf) <docroot>/api is a symlink to a release folder, switched by
# bin/ops/atomic-switch.sh. For up to ~2 minutes after a switch a PHP worker can still open the release it
# resolved last; the entry guard at the top of index.php (bin/ops/atomic-entry.php.txt) then hands the
# request to the CURRENT release's index.php. That only works when index.php declares nothing at compile
# time: a top-level function, class, interface, trait or enum would be declared twice ("Cannot
# redeclare", an empty HTTP 500 on every such request). The helpers live in src/front_helpers.php.
#
#   1. index.php declares no top-level function/class/interface/trait/enum (and still carries the guard);
#      api/.htaccess serves no real file but index.php, so src/front_helpers.php is never run directly
#   2. the stale worker: SCRIPT_FILENAME through the api symlink (release 2), the file PHP opened from
#      release 1 -> 200 from /api/health, answered by release 2 (its REVISION)
#   3. a fresh worker (opened through the symlink) -> 200 from release 2
#   4. an unhandled error is answered as JSON with a sentence and the caller's X-Request-Id as its
#      reference, never the exception text, and the log line carries the same reference, the exception
#      class, file:line, method and path; an unusable X-Request-Id gets a generated 32-hex reference
set -uo pipefail
HERE="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
API="$(cd "$HERE/../.." && pwd)"
PORT="${PORT:-33990}"
WORK="$(mktemp -d "${TMPDIR:-/tmp}/atomic-entry.XXXXXX")"
pass=0 fail=0
ok() { echo "PASS $*"; pass=$((pass + 1)); }
no() { echo "FAIL $*"; fail=$((fail + 1)); }
PID=""
cleanup() { [ -n "$PID" ] && kill "$PID" 2>/dev/null; rm -rf "${WORK:?}"; }
trap cleanup EXIT
APP="$(sed -n 's/^APP=//p' "$API/bin/ops/release.conf" | head -n 1)"

# ---- 1. nothing declared at compile time in the entry file
decl="$(php -r '
  $t = token_get_all(file_get_contents($argv[1])); $depth = 0; $out = [];
  foreach ($t as $i => $tok) {
    if ($tok === "{") { $depth++; continue; } if ($tok === "}") { $depth--; continue; }
    if (is_array($tok) && in_array($tok[0], [T_CURLY_OPEN, T_DOLLAR_OPEN_CURLY_BRACES], true)) { $depth++; continue; }
    if ($depth === 0 && is_array($tok) && in_array($tok[0], [T_FUNCTION, T_CLASS, T_INTERFACE, T_TRAIT, T_ENUM], true)) {
      // "static fn", "function (" closures and ::class are not declarations
      for ($j = $i + 1; isset($t[$j]) && is_array($t[$j]) && $t[$j][0] === T_WHITESPACE; $j++);
      if (isset($t[$j]) && is_array($t[$j]) && $t[$j][0] === T_STRING) { $out[] = $tok[1] . " " . $t[$j][1] . " (line " . $tok[2] . ")"; }
    }
  }
  echo implode("; ", $out);' "$API/index.php")"
[ -z "$decl" ] && ok "index.php declares no top-level function/class (helpers in src/front_helpers.php)" || no "index.php declares at compile time: $decl"
grep -q '^// >>> aicountly atomic entry (bin/ops/atomic-entry.php.txt) >>>$' "$API/index.php" && ok "index.php carries the atomic entry guard" || no "index.php has no atomic entry guard"
# src/front_helpers.php (like every other file of the API) must not be servable: api/.htaccess answers
# 404 for any real file or folder but index.php.
grep -Eq '^[[:space:]]*RewriteRule !\^\(index\\\.php(\|[^)]*)?\)\?\$ - \[R=404,L\]' "$API/.htaccess" \
  && ok "api/.htaccess serves no real file but index.php (src/front_helpers.php is a 404)" || no "api/.htaccess does not refuse real files other than index.php"

# ---- two releases of this very code, api/ -> release 2
DOC="$WORK/home/public_html/site.example"
mkdir -p "$DOC"
for n in 1 2; do
  rel="$WORK/releases/r$n"
  mkdir -p "$rel"
  ( cd "$API" && tar -cf - --exclude=./tests --exclude=./.env --exclude=./REVISION --exclude=./writable . ) | ( cd "$rel" && tar -xf - )
  # An unreachable database: /api/health still answers 200 (it reports database.reachable=false).
  printf 'APP_ENV=local\nDB_HOST=127.0.0.1\nDB_PORT=1\nDB_NAME=none\nDB_USER=none\nDB_PASS=none\n%s\n' "${EXTRA_ENV:-}" > "$rel/.env"
done
echo aaaaaaa1 > "$WORK/releases/r1/REVISION"
echo bbbbbbb2 > "$WORK/releases/r2/REVISION"
ln -s "$WORK/releases/r2" "$DOC/api"
# The router stands in for Apache/LiteSpeed: SCRIPT_FILENAME is the path through the symlink; the file
# PHP opens is release 1's when STALE is set (a worker whose realpath cache still holds release 1).
cat > "$WORK/router.php" <<'EOF'
<?php
$doc = $_SERVER['DOCUMENT_ROOT'];
$_SERVER['SCRIPT_FILENAME'] = $doc . '/api/index.php';
$_SERVER['SCRIPT_NAME'] = '/api/index.php';
$_SERVER['PHP_SELF'] = '/api/index.php';
$opened = getenv('STALE') === '1' ? getenv('STALE_RELEASE') . '/index.php' : $doc . '/api/index.php';
chdir(dirname($opened));
require $opened;
EOF
start() { # stale?
  [ -n "$PID" ] && { kill "$PID" 2>/dev/null; wait "$PID" 2>/dev/null; }
  STALE="$1" STALE_RELEASE="$WORK/releases/r1" php -d error_log="$WORK/php-error.log" -d log_errors=1 -d display_errors=0 \
    -S "127.0.0.1:$PORT" -t "$DOC" "$WORK/router.php" > "$WORK/server.log" 2>&1 &
  PID=$!
  for _ in $(seq 1 50); do curl -s --noproxy '*' -o /dev/null "http://127.0.0.1:$PORT/api/health" && break; sleep 0.1; done
}
health() { curl -s --noproxy '*' -m 20 -o "$WORK/body" -w '%{http_code}' "http://127.0.0.1:$PORT/api/health"; }
revision_of_body() { php -r '$j = json_decode((string) file_get_contents($argv[1]), true); echo is_array($j) ? ($j["revision"] ?? "none") : "not-json";' "$WORK/body"; }

# ---- 2. the stale worker
start 1
code="$(health)"
if [ "$code" = 200 ] && [ "$(revision_of_body)" = bbbbbbb2 ]; then ok "stale worker: 200 from /api/health, answered by the current release (bbbbbbb2)"
else no "stale worker: HTTP $code, revision $(revision_of_body): $(head -c 300 "$WORK/body"; tail -n 5 "$WORK/php-error.log" 2>/dev/null)"; fi
grep -q 'Cannot redeclare' "$WORK/php-error.log" 2>/dev/null && no "PHP logged 'Cannot redeclare'" || ok "no 'Cannot redeclare' in the PHP log"

# ---- 3. a fresh worker
start 0
code="$(health)"
[ "$code" = 200 ] && [ "$(revision_of_body)" = bbbbbbb2 ] && ok "fresh worker: 200 from release 2" || no "fresh worker: HTTP $code, revision $(revision_of_body)"

# ---- 4. an unhandled error: JSON with a reference, the same reference in the log
cat > "$WORK/uncaught.php" <<'EOF'
<?php
declare(strict_types=1);
namespace Aicountly\Api;
$api = $argv[1];
require $api . '/src/Env.php';
require $api . '/src/Autoload.php';
require $api . '/src/front_helpers.php';
$_SERVER['HTTP_X_REQUEST_ID'] = $argv[3];
try {
    answer_uncaught($argv[2], new \LogicException('secret detail sk_live_123'), $argv[4], 'v1/things');
} catch (\Throwable $sent) {
    echo json_encode(['class' => get_class($sent), 'status' => $sent->status ?? null, 'payload' => $sent->payload ?? null]);
}
EOF
for case in "given-ref-1234567|POST|given-ref-1234567" "bad ref with spaces|GET|"; do
  sent="${case%%|*}"; rest="${case#*|}"; method="${rest%%|*}"; want="${rest#*|}"
  : > "$WORK/uncaught.log"
  out="$(php -d error_log="$WORK/uncaught.log" -d log_errors=1 "$WORK/uncaught.php" "$API" "$APP" "$sent" "$method")"
  ref="$(php -r '$j = json_decode($argv[1], true); echo $j["payload"]["request_id"] ?? "";' "$out")"
  if [ -n "$want" ]; then [ "$ref" = "$want" ] && ok "uncaught ($method): the reference is the caller's X-Request-Id" || no "uncaught: reference '$ref', expected '$want': $out"
  else printf '%s' "$ref" | grep -Eq '^[0-9a-f]{32}$' && ok "uncaught ($method): an unusable X-Request-Id gets a generated 32-hex reference" || no "uncaught: generated reference '$ref'"; fi
  php -r '$j = json_decode($argv[1], true); $p = $j["payload"] ?? [];
    exit(($j["status"] ?? 0) === 500 && ($p["error"]["code"] ?? "") === "server_error" && str_contains($p["message"] ?? "", $argv[2])
      && ($p["error"]["request_id"] ?? "") === $argv[2] && !str_contains($argv[1], "secret detail") ? 0 : 1);' "$out" "$ref" \
    && ok "uncaught ($method): 500 JSON with a code, a sentence quoting the reference, no exception text" || no "uncaught body: $out"
  line="$(cat "$WORK/uncaught.log")"
  case "$line" in *"[$ref]"*"LogicException"*|*"LogicException"*"[$ref]"*) ok "uncaught ($method): the log line carries the reference and the class" ;; *) no "uncaught log: $line" ;; esac
  case "$line" in *"$method /v1/things"*"secret detail"*"@ "*":"*) ok "uncaught ($method): the log line carries method, path, message and file:line" ;; *) no "uncaught log detail: $line" ;; esac
done

echo "atomic-entry ($APP): $pass passed, $fail failed"
[ "$fail" = 0 ]
