# Zuriè Backend — Scaling Notes

Area 2 of the ops/scalability review: Redis readiness, an N+1 audit of the
hot read paths, an audit of every unbounded query, and a concurrency/
latency check. See [`RUNBOOK.md`](RUNBOOK.md) for how to actually operate
the deployed app; this document is about capacity and correctness at load.

## Redis: already wired, one .env change

Cache, session, and queue drivers were already `env()`-driven with a
database fallback (standard Laravel skeleton config,
`config/cache.php`/`config/session.php`/`config/queue.php`), and
`predis/predis` is already a dependency. No code change was needed —
this was **verified for real**, not just read off the config:

1. Installed `redis-server` locally (wasn't present), started it.
2. Set `CACHE_STORE=redis` and `SESSION_DRIVER=redis` in `.env`.
3. `Cache::put()` a test key, confirmed it round-tripped through
   `Cache::get()`, then confirmed with `redis-cli` directly that the key
   physically exists in Redis DB 1 (the cache store's own DB — see
   `config/database.php`'s `redis.cache.database`, defaulting to
   `REDIS_CACHE_DB=1`, separate from the general `redis.default`
   connection's DB 0).
4. Hit `GET /sanctum/csrf-cookie` and confirmed the session landed in
   Redis DB 0 the same way.
5. Reverted `.env` back to `database` afterward — this repo's local dev
   default stays on the database driver; only production needs Redis.

**To switch a real environment to Redis:**

```
CACHE_STORE=redis
SESSION_DRIVER=redis
QUEUE_CONNECTION=redis        # optional — queue jobs benefit the same way
REDIS_CLIENT=predis
REDIS_HOST=127.0.0.1
REDIS_PORT=6379
REDIS_PASSWORD=null           # set a real password in production
```

That's the whole change — no code, no migration, no redeploy logic beyond
the normal `./deploy.sh` (which already does `config:cache` on every
deploy, so the new driver takes effect immediately). Falling back to
`database` is just as simple: flip both values back and redeploy.

## N+1 audit

Checked every hot read path (product list, order list, checkout, reports)
by measuring actual query counts with `DB::enableQueryLog()` against
seeded data, not by code review alone.

| Path | Result |
|---|---|
| `ProductService::paginatePublic()` / `paginateAdmin()` | Already clean — flat 6–7 queries regardless of page size (5 vs 60 products tested). Stock status/quantity are batched via `attachStockStatuses()`, not per-product. |
| `OrderService::paginateAdmin()` | Already clean — 2 queries. `OrderListResource` deliberately excludes `items`, so no join/eager-load is needed. |
| Checkout (`OrderService::createOrder()`) | Loops once per line item (`findActiveForOrder`, `decrementForOrder`), but this is correctness-driven, not a fixable N+1 — each line needs its own row-locked read+write for stock safety under concurrency (see §6.3 of the main `CLAUDE.md`). Lock acquisition itself is already batched up front via `lockStockRows()`. Left as-is. |
| **`ReportService::debtors()` / `creditors()`** | **Real N+1, fixed.** `attachStakeholderNames()` called `StakeholderService::findOrFail()` once per row inside `array_map()` — a report listing N stakeholders ran N+few queries. Added `StakeholderService::namesFor(array $ids)` (batched `whereIn()->pluck()`, same convention as `ProductService::namesFor()`) and switched `attachStakeholderNames()` to one batched call. |
| `reconcileLedgers()` / `ledgerMovementInPeriod()` / `groupMovementInPeriod()` | Already clean — use SQL `SUM()`/`GROUP BY` aggregates, never load raw `JournalEntryLine` rows into PHP. |

### Before/after — the fix, measured

Seeded 23 suppliers with real payable balances (via `FinanceService::postEntry()`,
not a raw balance edit) and measured `ReportService::creditors()`:

```
BEFORE fix: 26 queries for 23 rows   (scales ~1:1 with row count)
AFTER fix:   4 queries for 23 rows   (flat, regardless of row count)
```

Regression test: `tests/Feature/Report/ReportQueryCountTest.php` — seeds 15
suppliers with real GRN-style payable entries and asserts the query count
stays under 10 regardless of row count. Verified it actually catches the
regression: reverting the fix and re-running it fails with *"creditors()
ran 16 queries for 15 suppliers — looks like the N+1 is back"*; with the
fix, it passes at 4 queries.

## Unbounded query audit

Every `Controller::index()` across all modules was checked for a
pagination signal. All 26 admin list endpoints already clamp `pageSize` to
100 (`ApiResponse::pageSize()`, from the earlier hardening pass — see
`git log` for "Harden for load and abuse"). The handful of endpoints with
no explicit `paginate()` call were individually confirmed safe, not just
assumed:

- **Scoped to one parent, never the whole table**: `DeliveryController::index()`
  (one order's deliveries), `WishlistController::index()` (one customer's
  wishlist) — bounded by the parent record, not global row count.
- **Small, admin-curated master data, not user-scale growth**: categories,
  sales outlets, price lists, FAQs, targets, cost centers, measurement
  units, currencies. These "grow by adding rows" per the Extensibility
  Constitution, but in practice stay in the dozens-to-low-hundreds — a
  business doesn't accumulate thousands of currencies or cost centers the
  way it accumulates orders.
- **`InventoryMovement`** (the append-only audit log named explicitly in
  `CLAUDE.md` §6.2): confirmed there is **no listing endpoint for it at
  all** currently — it's write-only from the app's own operations
  (`InventoryService::recordMovement`-style calls), never read back in
  bulk. Nothing to bound because nothing reads it unboundedly today; flag
  this if a "movement history" screen is ever added later.
- **Activity log**: already paginated (`ActivityService::paginateAdmin()`).
- **Journal lines**: never bulk-loaded — every read path uses SQL
  aggregates (`SUM()`/`GROUP BY`), covered above in the N+1 audit.

No endpoint found that can be asked to load and serialize an entire table.

## Concurrency / latency

Correctness under concurrent load (no oversell, no coupon
over-redemption, idempotent checkout) was already proven in the prior
hardening pass — see `tests/Feature/*` for `CouponRedemptionTest` and the
stock-locking tests, and the commit that fixed the coupon race (a 5-use
coupon redeemed 13 times under concurrent checkouts, now correctly capped
at 5 via a `lockForUpdate()` read inside the checkout transaction).

This pass added real throughput/latency numbers, measured against a local
`php artisan serve` instance with real concurrent `curl` requests (macOS's
bundled `ab` turned out to be broken in this environment — it reported
"Write errors: 200" and 0 bytes transferred, so its numbers were
discarded rather than reported):

| Endpoint | Requests | Concurrency | p50 | p95 | p99 |
|---|---|---|---|---|---|
| `GET /api/v1/products` | 200 | ~20 | 198ms | 348ms | 382ms |
| `POST /api/v1/auth/login` (failed attempts) | 60 | ~15 | 233ms | 1467ms | 1505ms |

**These numbers describe `artisan serve`, not production.** It's a
single-process PHP development server — it services one request at a
time, so latency under concurrency mostly reflects queueing behind that
one process, not the app's actual per-request cost (each individual
request completes in 20–30ms when run sequentially with no contention).
Production runs PHP-FPM with multiple worker processes, which parallelizes
real concurrent load the dev server can't. The login endpoint's long tail
is consistent with `password_verify()`'s bcrypt cost genuinely serializing
behind a single PHP process — in production, with several FPM workers,
concurrent logins run in parallel instead of queueing behind each other.

Take these as a proof that the app behaves correctly and doesn't error
under concurrent load, not as production capacity planning — a real
capacity number needs a load test against the actual PHP-FPM/nginx
deployment, which this environment doesn't have.
