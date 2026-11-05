# Rate limits

Two ceilings, in two places, for two different callers.

| Where | Who is counted | What it protects |
|---|---|---|
| the portal auth relay | the client **address** | The unauthenticated door. The portal sees this server's address for every relayed call, so whatever it limits per address would otherwise be one bucket for everybody. `RELAY_LIMIT_PER_MINUTE`, default 60. |
| `server-php/src/Support/RateLimit.php` | the signed-in **person** | Everything behind that door: the routes that fan out on every request, the ones that hand over a file, and the one that spends money at AI Pulse. |

Purchases had **no rate limiting of any kind** before 2026-11 — neither half. Both arrive together.

## What this is, and what it is not

It is not a defence against a determined attacker holding a stolen session — that is what the
permissions and the company gate are for. It is a ceiling that no real person reaches and a
runaway script does: a loop left running against a report, a scraper walking data out through
CSV, a client retrying a failed call forever.

**The limits are deliberately generous.** A limit a busy desk can hit during a rush is worse than
no limit at all, because it fails at the moment the desk needs it.

## The buckets

| Bucket | Default | Counted per | Routes |
|---|---|---|---|
| `dashboards` | 240 / 60s | person + company | `v1/dashboard`, `v1/dashboards/…` |
| `catalogue` | 240 / 60s | person + company | `v1/catalog/…` — global search and the pickers behind it |
| `exports` | 30 / 300s | **person only** | any path ending `/export` — a dashboard's export, the audit log |
| `ai` | 20 / 300s | **person only** | `v1/insights/ask` — `Ai\AskEngine` classifies and narrates with a model |

**Why exports and AI are counted per person rather than per company.** What is being limited
there is how much data leaves and how much money is spent — neither gets three allowances because somebody has
access to three companies. The load-related buckets are per company, because the cost of a
dashboard is a fan-out to *that* company's data.

## Configuration

One environment variable per bucket, `limit/seconds` or a bare limit (which keeps the default
window). `0` switches a bucket off.

```
PURCHASE_RATE_LIMIT_DASHBOARDS=240/60
PURCHASE_RATE_LIMIT_CATALOGUE=240/60
PURCHASE_RATE_LIMIT_EXPORTS=30/300
PURCHASE_RATE_LIMIT_AI=20/300
```

A value that cannot be read as a limit is **ignored**, and the shipped default applies: a typo
must not silently switch a ceiling off, nor set it to something nobody intended.

> A note worth keeping: `0` is the one value that turns a bucket off, and in PHP the *string*
> `'0'` is falsy. Reading the override with `getenv(...) ?: $default` therefore throws away
> exactly the value somebody meant to set. The code reads it explicitly against `false`, and a
> test pins it.

The counters live on this host's disk, because a cPanel PHP pool has no shared memory cache. File
names are a SHA-256 of the bucket key; nothing identifying and no request content is written. On a
host behind more than one web server each keeps its own count, so the effective ceiling is the
limit times the number of servers — raise nothing to compensate; the limits are already far above
what a person reaches.

## What a refusal looks like

`429` with `Retry-After` set to the time left in **this** fixed window, not a whole window from
now, so a client that honours it comes back when the allowance actually resets. The body carries
`error.code = "rate_limited"`, a sentence naming what the person was doing, and the limit and
window in `details`.

## Two deliberate choices

**Another AICOUNTLY server is not counted.** A sibling product calling with its own service key is
doing work a person asked for somewhere else, at a volume this ceiling was not set for, and a
false positive there breaks a cross-product feature rather than slowing an abuser down.

**It fails open.** A cache directory that cannot be written refuses nothing. A broken disk must
never stop the work.

## Where the check happens

In `Controllers\Controller::enter()`, for the same reason the tenant check is there: a limit
each endpoint had to remember to apply is a limit the next endpoint forgets. `Router::current()`
was added so a cross-cutting rule can name the endpoint it is guarding without every endpoint
telling it.

It runs **after** the permission check. A person who may not open an endpoint is told that at any
volume: a 429 would tell them something about an endpoint they may not open.

Routes are matched by **shape** rather than by a list, so an export added later is covered without
anybody remembering — the failure mode of a list being that the newest, least-reviewed endpoint is
the one missing from it.

## Adding a bucket

1. Add it to `RateLimit`'s bucket table with its default, window and whether it is per company.
2. Map the routes to it — prefer a shape over a list.
3. Add the route to the mapping check in `server-php/tests/rate_limit.php`.
4. Document it in the table above.
