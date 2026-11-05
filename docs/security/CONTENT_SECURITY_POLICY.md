# Content-Security-Policy

The policy lives in one place — `web/public/.htaccess` — and is **enforced**, not report-only.
This file accounts for every origin in it. `web/scripts/csp-sweep.mjs` (`npm run csp-sweep`, and
a CI step) proves it.

## What changed, and why

Until 2026-11 Purchases sent only:

```
Content-Security-Policy: frame-ancestors 'self' https://*.aicountly.com
```

No `script-src`, no `connect-src`, no `default-src` — so the app had **no XSS mitigation at all**:
any injected `<script>`, from any origin, would have run. Smart Books, on the same estate and the
same stack, has shipped an enforced policy for months.

`web/index.html` carries no inline script, so `script-src` needs neither a hash nor `'unsafe-inline'`.

## The policy, directive by directive

| Directive | Value | Why |
|---|---|---|
| `default-src` | `'self'` | The floor. Anything not named below may only come from this origin. |
| `script-src` | `'self'`, the two Connect origins, `https://*.googletagmanager.com` | The app's own bundles; AICOUNTLY Connect's `embed/v1/loader.js` and the `widget.js` it fetches from its own directory; gtag, which `utils/analytics.ts` loads once consent is given (the measurement ID is set at deploy time, so a local build without it does not show this origin — the deployed bundle does). |
| `style-src` | `'self' 'unsafe-inline'`, the two Connect origins | The app's stylesheet; React components set `style` attributes throughout, so `'unsafe-inline'` is required (it also covers the `style-src-attr 'unsafe-inline'` Connect's contract asks for). Connect's `widget.css` is linked **inside the widget's shadow root** and fetched from the Connect origin. |
| `img-src` | `'self' data: blob:`, `https://console.aicountly.org`, Google analytics/tag/doubleclick | Product icons come from Console; `data:` for inline icons; `blob:` for the profile photo and for Connect's attachment previews. |
| `font-src` | `'self' data:` | |
| `connect-src` | `'self'`, the two Connect origins, `https://my.aicountly.com`, `https://sandbox.aicountly.com`, the two Manage origins, Google analytics | `'self'` carries this app's own API: `server-php` deploys into `<docroot>/api`, so it is same-origin. The portal mints and refreshes `ses_key`; Manage answers the company registry; Connect's API carries conversations, call signalling and the pinned AI Pulse thread. |
| `frame-src`, `child-src` | `'self'` | Nothing here frames a `blob:`; downloads go through an `<a download>`, which CSP does not govern. |
| `worker-src`, `media-src` | `'self' blob:` | Connect's call surface. |
| `object-src` | `'none'` | No plugins, ever. |
| `base-uri` | `'self'` | A `<base>` injection cannot repoint every relative URL. |
| `form-action` | `'self'` | No screen in this app posts a form to another origin. |
| `frame-ancestors` | `'self' https://*.aicountly.com` | Unchanged from what this app already shipped: a sibling AICOUNTLY product may frame it, nobody else. |
| `upgrade-insecure-requests` | — | |

The same file ships to production and to the sandbox, so both hosts' origins are listed.

### Origins deliberately NOT in the policy

- **`https://books.aicountly.com` and the other sibling products** — the app launcher opens them
  as navigations, and CSP does not govern where a user navigates.
- **`https://cdn.jsdelivr.net`, `https://cdnjs.cloudflare.com`** — nothing here loads from either.

## Permissions-Policy and calling

The embedded Connect widget runs in this page's origin, so Connect's calling needs `camera` and
`microphone` delegated to `self` (connect-aicountly `docs/EMBED_SDK.md`, step 9). A `Permissions-Policy` now ships, and it delegates
both to `self`:

```
camera=(self), microphone=(self), display-capture=(self), geolocation=(), payment=(),
usb=(), serial=(), bluetooth=(), midi=(), hid=(), idle-detection=()
```

It came from the parallel audit on `main` and is kept as it stands: every powerful feature this
app does not use is denied outright — to it and to anything it frames — rather than left to the
browser's default, and `web/src/securityHeaders.test.ts` pins that. `display-capture` is granted
to `self` for Connect's screen share. An empty allowlist denies a feature to **every** origin
including self, so denying `camera` here would hide the widget's call buttons while it went on
polling for calls nobody could answer.

## Reconciled with the parallel audit on `main`

A second audit (`claude/great-pascal-nfonmz`) wrote a policy for this app's `.htaccess` within
days of this one. The shipped policy is the **union** of the two, so neither pass can break the
other:

| From `main` | Why it is kept |
|---|---|
| `connect-src https://console.aicountly.org` | The product-icon **manifest** is fetched before those icons are set as `<img>` srcs. |
| `connect-src blob:` | A `fetch()` of an object URL. |
| `connect-src https://www.google.com`, `https://www.google.co.in` | gtag's conversion endpoints. |
| `frame-src blob:`, `child-src blob:` | A PDF or preview opened into a hidden iframe; without it, Print falls back to a download. |
| The `Permissions-Policy` above | Longer and stricter than naming camera and microphone alone. |

Nothing from this pass was given up, and no origin was admitted that neither audit could account
for in the app's own source.

## How the sweep proves it

Purchases signs in through the AICOUNTLY portal, so a sweep cannot reach the screens behind it — and
the first attempt at one showed exactly why that matters: signed out, the app sends the browser to
the portal, the page ended up on **another origin with no policy of its own**, and every check
passed whatever it was asked. The sweep now does two things instead:

1. **Boots the app under the policy** and records every `securitypolicyviolation`. That covers what
   every visitor's first second does: the bundles, the stylesheets, the app launcher's
   icons, gtag and the Connect loader. A cross-origin navigation is refused so the measurement
   stays on this app's own document, and the sweep **fails** if the page is not still there.
2. **Probes every capability the policy declares** on a bare page served from this app's own origin
   with these same headers — a script from the Connect origin, a fetch to Manage and to the portal,
   a `data:` image, an inline style — and asserts each is ALLOWED. It then asserts that
   a script and a fetch from an origin the policy does not name, and an inline script, are
   **BLOCKED**. Those three negative controls are the point: a policy that permits everything passes
   an app-boot sweep, and fails this one.

Violations are reported to the test runner as they happen rather than read back from the page
afterwards, because the document that held them may be gone by then. A probe whose resource
reached the local route handler counts as allowed whatever the stub then served it — CSP refuses
in the renderer, before a request is made, so anything the handler is asked for is something the
policy allowed.

Nothing leaves the machine: every request is answered locally.

## Adding an origin

1. Add it to the right directive in `web/public/.htaccess` — and to **both** environments' hosts.
2. Add a row above saying what loads from it and which file reaches for it.
3. Add a probe for it in `web/scripts/csp-sweep.mjs`, so the next change cannot quietly remove it.
4. Run `npm run csp-sweep`. It must report no violation and no failed probe.

If a sweep reports a violation for something the app genuinely needs, widen the policy in that one
directive — never by adding `'unsafe-inline'` to `script-src`, which would give up the whole point
of the policy.

## One thing to watch

`PROD_API_BASE_URL` (Actions → Variables) is an optional override for the API's base URL. Left
unset — which is the documented default, and what the live `/api/health` confirms — the app calls
its own origin, which `connect-src 'self'` covers. **If it is ever set to another origin, that
origin must be added to `connect-src`**, or every API call will be refused. The sweep fails when
`VITE_API_BASE_URL` names an origin the policy does not carry.
