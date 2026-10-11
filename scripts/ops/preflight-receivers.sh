#!/usr/bin/env bash
# preflight-receivers.sh — before ANY file goes live, prove that every receiver this release calls
# already serves what it needs (E8-OPS: A24 deploy-order inversion). Fails closed.
#
# CANONICAL COPY: console-react-app/ops/estate/kit/scripts/ops/preflight-receivers.sh — identical in
# every product at scripts/ops/preflight-receivers.sh. The product's own list: scripts/ops/receivers.txt,
# generated from the code by console-react-app/ops/estate/contract-graph.py and reviewed by hand.
#
# Usage: scripts/ops/preflight-receivers.sh <production|sandbox> [receivers-file]
#
# receivers.txt — one requirement per line, '|'-separated; '#' comments:
#   <label> | <production origin> | <sandbox origin> | route <METHOD> <path>
#       The route must EXIST: an unauthenticated call answers 200/401/403/405/422 — never 404/410/5xx.
#       Self-validating: once per origin a deliberately missing path (/api/__aicountly_preflight_missing__)
#       must answer 404, otherwise the receiver cannot tell the two apart and the line fails (use a
#       health line for that receiver instead).
#   <label> | <production origin> | <sandbox origin> | health <path> <jq filter>
#       The receiver's public health JSON must satisfy the filter, e.g. a revision or capability flag.
#
# Environment:
#   VERIFY_SSH     command prefix that runs one command on the server ("ssh deploy-target"). Set: every
#                  probe is made from the server (the host's WAF graylists runners; same as verify-live.sh).
#   PREFLIGHT_ORIGIN_OVERRIDE  tests only: "<label>=<origin>,..." replaces the listed origins.
#   PREFLIGHT_TIMEOUT          seconds per request (default 15).
# Exit: 0 every receiver ready · 1 at least one is not (the deploy must stop) · 2 usage.
set -uo pipefail

target="${1:-}"
file="${2:-$(cd "$(dirname "$0")" && pwd)/receivers.txt}"
case "$target" in production | sandbox) ;; *) echo "usage: $0 <production|sandbox> [receivers-file]" >&2; exit 2 ;; esac
[ -f "$file" ] || { echo "preflight: no receivers file at $file" >&2; exit 2; }
timeout="${PREFLIGHT_TIMEOUT:-15}"
ssh_prefix="${VERIFY_SSH:-}"
tmp="$(mktemp -d)"
trap 'rm -rf "$tmp"' EXIT

# fetch <METHOD> <url> -> sets code; body in $tmp/body
fetch() {
  local method="$1" url="$2" out
  if [ -n "$ssh_prefix" ]; then
    # shellcheck disable=SC2086
    out="$($ssh_prefix "curl -sS --max-time ${timeout} -X ${method} -o /dev/stdout -w '\n__PF__%{http_code}' -H 'Accept: application/json' -A 'aicountly-deploy-preflight/1 (server)' $(printf '%q' "$url")" 2>/dev/null)" || true
    code="$(printf '%s' "$out" | sed -n 's/^__PF__//p' | tail -n 1)"
    printf '%s' "$out" | sed '/^__PF__/d' > "$tmp/body"
  else
    code="$(curl -sS --max-time "$timeout" -X "$method" -o "$tmp/body" -w '%{http_code}' -H 'Accept: application/json' \
      -A 'aicountly-deploy-preflight/1 (runner)' "$url" 2>/dev/null)" || code=000
  fi
  [ -n "$code" ] || code=000
}

override() { # label origin -> origin
  local l="$1" o="$2" pair
  IFS=',' read -r -a pairs <<< "${PREFLIGHT_ORIGIN_OVERRIDE:-}"
  for pair in "${pairs[@]}"; do
    [ "${pair%%=*}" = "$l" ] && { printf '%s' "${pair#*=}"; return; }
  done
  printf '%s' "$o"
}

declare -A control_ok=()
failed=0 n=0
while IFS= read -r line || [ -n "$line" ]; do
  case "$line" in '' | \#*) continue ;; esac
  IFS='|' read -r label prod sand spec <<< "$line"
  label="$(echo "$label" | xargs)" prod="$(echo "$prod" | xargs)" sand="$(echo "$sand" | xargs)"
  spec="$(echo "$spec" | sed 's/^[[:space:]]*//;s/[[:space:]]*$//')"
  origin="$prod"; [ "$target" = sandbox ] && origin="$sand"
  origin="$(override "$label" "$origin")"
  origin="${origin%/}"
  n=$((n + 1))
  read -r kind a b rest <<< "$spec"
  case "$kind" in
    route)
      method="$a" path="$b"
      if [ -z "${control_ok[$origin]+x}" ]; then
        fetch GET "${origin}/api/__aicountly_preflight_missing__"
        if [ "$code" = 404 ]; then control_ok[$origin]=1; else control_ok[$origin]=0; ccode="$code"; fi
      fi
      if [ "${control_ok[$origin]}" != 1 ]; then
        echo "::error title=Receiver preflight::${label}: ${origin} does not answer 404 for a missing path (got ${ccode:-?}), so a route probe proves nothing — unreachable, or use a health line"
        failed=$((failed + 1)); continue
      fi
      fetch "$method" "${origin}${path}"
      case "$code" in
        200 | 401 | 403 | 405 | 422) echo "${label}: OK — ${method} ${path} exists on ${origin} (HTTP ${code})" ;;
        404 | 410) echo "::error title=Receiver preflight::${label}: ${origin} does not serve ${method} ${path} (HTTP ${code}). Deploy the receiver first."; failed=$((failed + 1)) ;;
        *) echo "::error title=Receiver preflight::${label}: ${method} ${path} on ${origin} answered HTTP ${code}; not proven ready"; failed=$((failed + 1)) ;;
      esac
      ;;
    health)
      path="$a" filter="$b${rest:+ $rest}"
      fetch GET "${origin}${path}"
      if [ "$code" = 200 ] && jq -e . "$tmp/body" >/dev/null 2>&1 && jq -e "$filter" "$tmp/body" >/dev/null 2>&1; then
        echo "${label}: OK — ${path} satisfies ${filter}"
      else
        echo "::error title=Receiver preflight::${label}: ${origin}${path} (HTTP ${code}) does not satisfy ${filter}. Deploy the receiver first."
        failed=$((failed + 1))
      fi
      ;;
    *) echo "preflight: bad line: $line" >&2; exit 2 ;;
  esac
done < "$file"

if [ "$failed" -gt 0 ]; then
  echo "${failed} of ${n} receiver requirement(s) not met for ${target}: nothing was deployed."
  exit 1
fi
echo "All ${n} receiver requirement(s) met for ${target}."
