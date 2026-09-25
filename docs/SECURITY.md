# Zuriè Backend — Security Review

Area 4 of the ops/security review. See the frontend's own
`docs/SECURITY.md` for the browser-side half of this pass (security
headers, CSP, npm audit).

## Fixed: unauthenticated requests returned 500, not 401

Every API request with no valid session — a plain `curl` call, a bot, a
misconfigured client, or literally the first probe of an authorization
sweep — returned a raw 500 Internal Server Error instead of a clean 401,
**unless** it happened to send `Accept: application/json`.

Laravel's default `Authenticate` middleware only skips its
redirect-to-login behavior when `$request->expectsJson()` is true (an
`Accept: application/json` header, or an XHR/pjax request). Any other
request falls into the redirect path, which tries to generate a URL for a
named `login` route — one that doesn't exist in this API-only app (no
`web.php` login route, no Blade views at all) — and throws
`RouteNotFoundException`. `CLAUDE.md`'s own cheatsheet already documented
this exact symptom as an accepted gotcha:

> `Route [login] not defined` (500) | request was unauthenticated (bad
> session/CSRF), not a routing bug

...but "known and explained" isn't the same as "fixed." A raw 500 on
every unauthenticated request that doesn't send the right Accept header
is a real problem for anything other than a well-behaved browser SPA —
including the exact kind of raw HTTP client an actual authorization sweep
or pentest tool would use.

**Fixed** in `bootstrap/app.php`:

```php
\Illuminate\Auth\Middleware\Authenticate::redirectUsing(fn () => null);
```

Forces every unauthenticated request, regardless of headers, to always
throw `AuthenticationException` — already mapped in this same file's
`$exceptions->render()` to the standard `{ success: false, message:
"Unauthenticated." }` 401 JSON envelope every other error path uses.

**Verified live**, not just reasoned through:

```
GET /api/v1/admin/orders    (no auth) -> 401 {"success":false,"message":"Unauthenticated."}
GET /api/v1/admin/users     (no auth) -> 401 {"success":false,"message":"Unauthenticated."}
GET /api/v1/account/profile (no auth) -> 401 {"success":false,"message":"Unauthenticated."}
```

All three returned raw 500s before the fix. Full test suite still green
after the change (139/139).

## IDOR audit

- **Customer-scoped endpoints** (`GET /account/profile`,
  `GET /account/orders`): both derive the customer's identity entirely
  from `$request->user('customer')->stakeholder_id` — the authenticated
  session, never a client-supplied ID anywhere in the request. There is no
  `GET /account/orders/{id}` (single order by ID) endpoint at all for
  customers — only the paginated list scoped to their own account — so
  there's no ID to guess or enumerate in the first place.
- **Admin-gated routes**: every `admin/*` route requires an explicit
  `permission:*` middleware (per-route, declared in each module's
  `routes.php` — see `CLAUDE.md` §3's RBAC table). Spot-checked several
  with zero authentication (`admin/orders`, `admin/users`,
  `admin/finance/chart-of-accounts`) and got the 401 above in every case,
  never the resource itself.

No further IDOR surface found — this is consistent with the module system
already routing every cross-record read through an authenticated,
identity-scoped Service method rather than trusting a client-supplied ID
directly against another user's data.

## Login / forgot-password enumeration

Verified live with curl, both guards:

| Request | Real account | Nonexistent account | Same response? |
|---|---|---|---|
| `POST /auth/forgot-password` (admin) | `{"success":true}` | `{"success":true}` | ✅ identical |
| `POST /auth/login` (admin), wrong password | `"These credentials do not match our records."` | same message | ✅ identical |
| `POST /customer/auth/login`, wrong password | same message | same message | ✅ identical |

No endpoint reveals whether an email address has an account.

## Dependency scanning

`composer audit`: 0 advisories. Wired into
`.github/workflows/security-audit.yml`, running on every PR, every push
to `main`, and weekly (dependencies can gain new advisories with no code
change on this side).

## Other checks performed

- **XSS / injection**: no raw `DB::raw()` or string-concatenated SQL with
  unescaped user input found — every query goes through Eloquent's query
  builder (parameterized by default). No `dangerouslySetInnerHTML`-
  equivalent output path on this side (this is a JSON API; there's no
  server-rendered HTML to inject into).
- **File upload abuse**: extension-spoofing (uploading `page.html` as an
  "image" and having it served back under that extension — stored XSS on
  the API origin) was already found and fixed in the prior hardening pass
  (`596c0c0`, "Harden for load and abuse") — files are now stored under
  the extension of their actual content, not the client-supplied one.
  Confirmed still in place, not re-broken by anything in this pass.
- **CSRF**: Sanctum's cookie-session SPA auth requires a matching
  `X-XSRF-TOKEN` on every state-changing request from a stateful domain —
  already the standing auth model (`CLAUDE.md` §3), not something this
  pass changed.
- **Rate limiting**: `throttle:api` (global), `throttle:checkout` (public
  checkout), and `throttle:coupon` (coupon preview, a code-guessing
  surface) already exist from the prior hardening pass. Not re-audited
  line-by-line in this pass beyond confirming they still apply (hit them
  incidentally while load-testing in area 2).
- **Error info leakage**: `bootstrap/app.php`'s exception renderer already
  gates the raw exception message behind `config('app.debug')` for
  uncaught exceptions and `UnbalancedJournalEntryException` — production
  (`APP_DEBUG=false`) never leaks internals. Confirmed the config default
  and that nothing in this pass's changes bypasses it.

## Not verified in this pass

- **Secret rotation**: this session cannot see what secrets were pasted
  into chat in earlier sessions or verify whether `APP_KEY`, the Google
  OAuth client secret, or database passwords have since been rotated on
  the live server. **Flagging this explicitly rather than silently
  assuming it's handled** — if any production secret was ever shared in a
  chat transcript, screenshot, or ticket, it should be rotated regardless
  of whether this review can confirm it, since a leaked secret's exposure
  window doesn't shrink just because no one's checked.
