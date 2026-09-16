# Zuriè Backend — CLAUDE.md

Laravel 13 API backend for the Zuriè frontend at `/Users/willbardmloka/projects/zurie`
(a separate Next.js repo — this backend never contains frontend code, and the
frontend never contains backend code). They talk over HTTP/JSON at
`/api/v1`, both sides holding to the shared contract documented in the
frontend's `docs/development-guide.md`.

## The module system — how this codebase is organized

This is **not** classic Laravel MVC layout. Everything lives under
`app/Modules/{Domain}/` (Auth, Product, Inventory, Customer, Order, Media,
Dashboard, Activity, Settings today), each with its own
`Controllers/ Models/ Services/ Requests/ Resources/ Providers/ routes.php`.
`app/Http/Controllers/Controller.php` is just the empty base class — nothing
real lives in the traditional tree.

**Registering a module touches exactly three files outside its own folder**,
nothing else:
1. `bootstrap/providers.php` — add its `{Domain}ModuleServiceProvider`.
2. `routes/api.php` — `require` its `routes.php` (the file has a comment
   inviting exactly this: "new modules get a single line added here").
3. `database/seeders/PermissionSeeder.php` — add its permission keys, if it
   has permission-gated routes.

Migrations need no registration (Laravel auto-discovers `database/migrations/`).

## Request lifecycle

`public/index.php` → `bootstrap/app.php` (routing, middleware, exception
rendering all configured here, once) → `routes/api.php` (`apiPrefix: 'api/v1'`
set centrally — never hardcode `v1` in a route file) → per-module
`routes.php` → Controller → Service → Model → Resource → response.

## Auth — Sanctum cookie-based SPA, matching the frontend exactly

`$middleware->statefulApi()` in `bootstrap/app.php` is what makes
`SANCTUM_STATEFUL_DOMAINS` (includes `localhost:3000` for local frontend dev)
treat the frontend's origin as a same-site session instead of requiring a
bearer token. Login (`AuthService::attempt()`) is plain
`Auth::guard('web')->attempt()` + `session()->regenerate()` (defeats session
fixation) — no token is ever returned to the client, only a session cookie.

## Authorization — hand-rolled RBAC, not spatie/permission

`Role`/`Permission` are plain Eloquent models joined by `role_permissions`/
`user_roles` pivots. Enforcement is one middleware,
`App\Modules\Auth\Middleware\EnsurePermission`, applied declaratively in
routes: `->middleware('permission:product_create')`. That string must exist
as a row in the `permissions` table (via `PermissionSeeder.php`) or the
middleware 403s everyone silently — no compile-time link between the route
string and the seeder, so keep them in sync by hand.

**The `permissions` array returned to the client (`User::permissionKeys()`)
is a UI convenience only** — hiding a button the user can't use. The actual
authorization boundary is always this server-side middleware. Never trust
the client to self-police, and never skip the middleware because "the
frontend already hides this."

## Response envelope — one trait, no exceptions

`App\Support\Http\ApiResponse` (`ok()`, `created()`, `paginated()`, `fail()`)
is the *only* place a JSON response gets shaped. No controller should ever
call `response()->json()` directly — that's how the four-envelope-shape
contract (single resource / paginated list / ack / error) stays consistent
across every endpoint without each controller having to remember the rule.

Error shaping is equally centralized in `bootstrap/app.php`'s
`$exceptions->render(...)` — validation, auth, not-found, custom domain
exceptions (`InsufficientStockException`, `InvalidOrderTransitionException`),
and integrity-constraint violations (SQLSTATE 23000) all get mapped to the
contract's `{ success: false, message, errors? }` shape in one place. A new
domain exception needing a distinct status/message gets its own `if` branch
added there — there's no auto-discovery for this.

## Cross-module coupling — Services only, never Models

A module needing another module's data injects and calls that module's
**Service** class (constructor injection — see `OrderService` depending on
`CustomerService`, `ProductService`, `InventoryService`). Never reach into
another module's Eloquent Model or query its table directly. This is what
keeps "add one feature" from requiring you to understand five other
modules' internals as the system grows.

## Concurrency safety — row locking, always inside a transaction

`InventoryService::decrementForOrder()`/`restockForOrder()` use
`->lockForUpdate()` inside a `DB::transaction()` — this is the actual
mechanism (not just the surrounding transaction) that stops two concurrent
checkouts from both reading the same pre-decrement quantity and both
committing a decrement past zero. `lockForUpdate()` only holds the row lock
inside an active transaction — calling it outside one is a silent no-op.
Follow this exact pattern for any future stock-affecting or balance-affecting
write (e.g. a future ledger balance update).

## Known local-dev gotcha

`vendor/` here was installed under **PHP 8.5.1**
(`/opt/homebrew/Cellar/php/8.5.1_2/bin/php`), but the Homebrew-linked `php`
on `PATH` may resolve to `php@8.3` (8.3.29) instead — `composer.json` says
`^8.3` but the locked platform requirement is `>=8.4.1`. If `php artisan
serve` fails with a confusing "can't find public/index.php" error, check
`php -v` first — that error is actually PHP refusing to boot the
autoloader, not a missing file. Run with the full 8.5.1 path if the global
link hasn't been fixed:
```
/opt/homebrew/Cellar/php/8.5.1_2/bin/php artisan serve --port=8000
```

## V2 direction — read this before designing any new module

Full architecture reasoning lives in the frontend repo at
`Zurie_V2_Architecture_Design (2).md` (yes, it's in the frontend repo, not
here — treat it as the shared planning doc for both sides). It covers moving
from V1 (storefront + admin CMS) to a full commerce system: POS, unified
Sales/Orders (channel-differentiated by a `source` field, not separate
business logic per channel), a stock-movement Inventory ledger, Purchases/
Suppliers, a proper double-entry Finance core (Chart of Accounts — Ledger
Groups/Ledgers, Cost Centers, Sales Outlets, journal entries), Price Lists,
and Customer accounts (nullable `customers.user_id` links a customer record
to a login without conflating the two concepts).

### The extensibility constitution — non-negotiable for every new module

The user's explicit standing requirement: adding a future module must never
become a large job or risk breaking the existing system. Every module or
schema addition — in V2's remaining phases or anything after — must satisfy:

1. **Only three files outside its own folder** get touched to register a
   new module: `bootstrap/providers.php`, `routes/api.php`,
   `PermissionSeeder.php`. Everything else is self-contained inside
   `app/Modules/{NewDomain}/`.
2. **Cross-module calls go through Services only**, never another module's
   Model or table — see "Cross-module coupling" above; this rule doesn't
   relax as the system grows, it's what prevents it from becoming spaghetti.
3. **Tables representing "a category of things that will grow" are
   polymorphic/generic, not fixed enums or hardcoded columns** — e.g.
   `inventory_movements.type` is a string (a new movement type needs no
   migration), `journal_entries.reference_type/reference_id` is polymorphic
   (any future module can post financial entries without a schema change),
   and things like Cost Centers/Sales Outlets/Price Lists grow by adding
   *rows*, never by adding *columns*.

Before implementing any new Zurie feature, check its design against these
three rules. A design that needs to alter an *existing* table's schema just
to represent a new concept, or needs more than the three touchpoints in
rule 1, should be flagged and reconsidered before being built.
