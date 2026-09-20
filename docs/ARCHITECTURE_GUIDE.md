# Zuriè Backend — Architecture Guide (for a software engineer taking ownership)

Everything below was checked against the actual code. Paths are relative to the
backend repo root (`zurie_backend/`) unless they start with `frontend:`.
Read sections 1–4 first (30 minutes); the rest is reference you come back to.

---

## 1. The 60-second mental model

- **Laravel 13 JSON API** only. No Blade, no server-rendered pages. The Next.js app
  (`zurie/`, separate repo) is the only client.
- **Not classic Laravel layout.** Instead of `app/Models`, `app/Http/Controllers`,
  everything lives in **feature modules**: `app/Modules/{Domain}/`. `app/Http/Controllers/Controller.php`
  is an empty base class; nothing real lives in the default tree.
- **A request flows one direction, always:**

```
HTTP  →  routes/api.php  →  Modules/X/routes.php  →  middleware (auth, permission)
      →  Controller  →  FormRequest (validation)  →  Service (ALL business logic)
      →  Model (Eloquent)  →  Resource (JSON shape)  →  ApiResponse envelope
```

- **Controllers are thin, Services are fat.** If you are hunting for "where does X
  actually happen", it is almost always `app/Modules/X/Services/XService.php`.
- **Money is double-entry.** Every sale, purchase, payment posts balanced journal
  entries into a ledger. Reports are *derived* from ledgers/orders live — there are
  no manually-maintained totals tables.

## 2. Anatomy of a module

```
app/Modules/Delivery/
├── Controllers/     HTTP in/out only. Calls a Service, wraps result in a Resource.
├── Requests/        FormRequest classes = validation rules (camelCase field names).
├── Services/        Business logic. Transactions, invariants, cross-module calls.
├── Models/          Eloquent. Table mapping, relations, casts. Little logic.
├── Resources/       Shape of the JSON the client receives (snake_case DB → camelCase JSON).
├── Providers/       {Domain}ModuleServiceProvider — DI bindings / event listeners.
└── routes.php       This module's routes (only file that declares its URLs).
```

Pattern to open any feature: **routes.php → Controller method → Service method**.
Three hops and you are at the real logic.

### Registering a module touches exactly 3 files outside its folder

1. `bootstrap/providers.php` — add the `XModuleServiceProvider`.
2. `routes/api.php` — one `require __DIR__.'/../app/Modules/X/routes.php';` line.
3. `database/seeders/PermissionSeeder.php` — add permission keys.

Migrations need no registration (Laravel auto-discovers `database/migrations/`).
`apiPrefix: 'api/v1'` is set once centrally (`bootstrap/app.php`) — never write `v1` in a route file.

### The Extensibility Constitution (project rules — see `CLAUDE.md`)

1. New module = only those 3 outside touches.
2. **Cross-module calls go through Services, not another module's Models.**
3. Categories that grow are *rows*, not enums/columns
   (e.g. `inventory_movements.type` is a string; `journal_entries.reference_type/reference_id` is polymorphic).

**Honest note on rule 2:** it is relaxed in a few *read-only* lookups, deliberately, and
documented at the call site: `GrnService` reads `Supplier`, `DeliveryService` reads
`Order`/`OrderItem`, `TransactionService::receiptsAppliedByStakeholder()` raw-joins `orders`.
Writes across modules always go through the other module's Service.

## 3. Cross-cutting plumbing (where "magic" lives)

| Concern | Where | What to know |
|---|---|---|
| Response shape | `app/Support/Http/ApiResponse.php` | `ok() / created() / paginated() / fail()`. Controllers never call `response()->json()`. |
| Error → JSON mapping | `bootstrap/app.php`, `$exceptions->render(...)` | Validation, 401/403/404, `InsufficientStockException`, `InvalidOrderTransitionException`, SQLSTATE 23000 all mapped **here, once**. A new domain exception needing a custom status = add an `if` branch here. |
| Auth | Sanctum **cookie/session SPA auth** | Login = `POST /api/v1/auth/login` (`Modules/Auth/routes.php`). No bearer token is ever returned. Needs CSRF cookie first (`GET /sanctum/csrf-cookie`). |
| Authorization | `Modules/Auth/Middleware/EnsurePermission.php` | Route-level: `->middleware('permission:report_view')`. The key string must exist in the `permissions` table (column `key`) or **everyone gets 403**. |
| Rate limits | `AppServiceProvider` + `throttle:login` etc. | Login 5/min per email+IP; register/forgot/reset are throttled too. |
| Audit log | spatie activitylog, `activity('x')->log(...)` | Written manually inside services. Viewable in Admin → Activity. |
| Config knobs | `config/zurie.php` | e.g. `default_vat_percentage`. VAT is server-computed, never trusted from the client. |

### RBAC — the #1 source of "why 403?"

- Tables: `roles`, `permissions`, pivots `role_permissions`, `user_roles`. Models in `Modules/Auth/Models`.
- **Permissions are seeded, not auto-granted.** `PermissionSeeder` creates keys.
  `RoleSeeder` then gives the **`admin`** role *every* permission (`sync(Permission::pluck('id'))`).
  **`super_admin` gets none** in that seeder (it is only a label today).
- Therefore, after you add a new permission key you must re-seed:
  `php artisan db:seed --class=PermissionSeeder && php artisan db:seed --class=RoleSeeder`
  otherwise the route 403s for everybody, including admins.
- The `permissions` array sent to the UI is cosmetic (hide buttons). **The middleware is the security boundary.**
- Privilege-escalation guards (added in the security pass): `RoleService::assertCanGrantPermission()`
  (you can only grant permissions you hold) and `UserService::assertNotActingOnSelf()`
  (you cannot change your own roles).

## 4. The money core — read this carefully, it's the heart

Files: `app/Modules/Finance/{Models,Services}`.

### 4.1 Chart of accounts

`LedgerGroup` (tree via `parent_id`, has `nature`: asset/liability/income/expense/equity)
→ `Ledger` (an account, e.g. Cash, Sales, a supplier's payable).
System ledgers are seeded by `database/seeders/ChartOfAccountsSeeder.php` and fetched by code:
`FinanceService::systemLedger('CASH' | 'INV-ASSET' | 'SALES' | 'COGS' | 'VAT-IN' | 'VAT-OUT' | 'INV-WRITEOFF' ...)`.

Per-record ledgers (a Supplier's payable ledger) are created eagerly and located via
`Ledger.reference_type/reference_id` (polymorphic) — `FinanceService::ledgerFor($owner)`.
Code format: `{GROUP_CODE}-{owner id}` (e.g. `CRED-7`). It's unique-constrained, which bit us once
during the stakeholder merge (stale codes collided after ids were remapped).

### 4.2 The one posting primitive

`FinanceService::postEntry($lines, narration, referenceType, referenceId, ...)`

- `$lines = [['ledger_id'=>, 'type'=>'debit'|'credit', 'amount'=>], ...]`
- **Validates debits == credits *before* writing anything**, else throws `UnbalancedJournalEntryException`.
- Every module posts through this. Nobody writes `journal_entries`/`ledgers` directly.
- `postSimpleEntry()` = 2-line convenience wrapper. `deleteEntry()` = the ONLY safe way to hard-delete
  a posted entry (see 4.3).

### 4.3 THE sign convention (this trips everybody)

`ledgers.current_balance` is **not** "debits minus credits". It's a running total stored in the
**ledger's own normal direction** (`FinanceService::applyToLedgerBalance()`):

- asset/expense ledgers: debit **+**, credit **−**
- liability/income/equity ledgers: credit **+**, debit **−**
- `is_contra = true` flips the ledger's normal direction (e.g. Sales Discounts sits in an income group but behaves debit-normal).

Consequences you must remember:

1. A healthy liability (you owe a supplier) shows a **positive** `current_balance`.
2. `current_balance` is updated **incrementally, never recomputed**. If you delete a journal entry
   with raw SQL, the balance is now wrong forever. Use `FinanceService::deleteEntry()`, which reverses the
   effect on the ledger balance and then deletes the entry.
3. Trial balance (`FinanceService::trialBalance()`) just splits each balance into a debit/credit column.
   Balance sheet (`balanceSheet()`) uses balances as-is; **no closing entries exist**, so undistributed profit
   (income − expense) is folded into Equity as "Retained Earnings (Current Period)". `isBalanced` = A = L + E.
   (I originally double-negated here and got liabilities negative — the fix was realising the sign is already normalised.)

### 4.4 Concurrency

Anything that changes stock or a balance does `->lockForUpdate()` **inside `DB::transaction()`**
(`InventoryService::decrementForOrder()`, `applyToLedgerBalance()`). `lockForUpdate()` outside a
transaction is a silent no-op — the pattern only works as a pair.

## 5. Inventory (`Modules/Inventory`)

- `inventory` has one row per **(product_id, sales_outlet_id)** (unique together). `Store == SalesOutlet`; there is no separate Store concept.
- `inventory_movements` is an append-only log (type is a free string, polymorphic reference).
- Every `InventoryService` method takes optional `?int $outletId`. **Write methods** resolve to one outlet
  (default: the online outlet via `resolveOutletId()`); **aggregate reads** (`countInStock`, `lowStock`, …) sum
  across all outlets when omitted.
- Methods to know: `decrementForOrder` (sale), `restockForOrder` (cancel), `receivePurchase` (GRN),
  `reversePurchaseReceipt` (un-receive; throws `InsufficientStockException` if that stock was already sold),
  `decrementForTransfer / incrementForTransfer`, `allStockRows` (reports).

## 6. Walkthrough: what happens when a sale is made

`OrderService::createOrder()` (`Modules/Order/Services/OrderService.php`), shared by web checkout and POS
(`checkout()` vs `posSale()` only choose source/status/payment-ledger). One `DB::transaction`:

1. Resolve/create the customer (`Customer` is now a `stakeholders` row — see §9).
2. Pick currency + exchange rate (`CurrencyService`).
3. Create `Order`; `order_number` derives from the row id (no counter table needed).
4. **For each line:** `InventoryService::decrementForOrder` (row-locked, throws if short → whole tx rolls back);
   `PriceListService::resolvePrice` (customer/outlet-aware pricing); compute cost from `buying_price` and VAT
   from `config('zurie.default_vat_percentage')` unless the product is `vat_exempted`. **Prices/VAT are always
   server-computed** — client values are ignored.
5. Coupon: validate against gross subtotal, compute discount, `redeem()` inside the same tx.
6. `postSaleToLedger()` → balanced entry: payment ledger (Cash for POS) / Sales / Discounts / COGS / Inventory / VAT-Output.
7. `VatService::record($order,'output',...)`; activity log written manually.

If anything throws, stock, order and ledger all roll back together. That single transaction is the reason
the books can't drift from stock.

## 7. Purchasing: PO → GRN (`Modules/Procurement`)

- `PurchaseOrder` = **intent** (no stock or ledger effect). `Grn` (goods received note) = **reality**
  (moves stock, posts ledger, records input VAT). Status (`pending / partially_received / fully_received / closed / canceled`) is
  recomputed by `PurchaseOrderService::recomputeStatus()`.
- `GrnService::create()` validates each line ≤ remaining quantity, calls `InventoryService::receivePurchase`,
  posts: Dr Inventory (+ Dr VAT-Input) / Cr Supplier payable (or Cash if no supplier).
- **Instant Receive:** `PurchaseOrderController::store()` wraps `PurchaseOrderService::create()` + `GrnService::create()`
  in one transaction when `instantReceive: true`. It's orchestrated in the *controller* because `GrnService`
  already depends on `PurchaseOrderService` — putting it inside the PO service would be a circular dependency.
- **Un-receive:** `GrnService::delete()` reverses stock → `deleteEntry` → deletes the `VatTransaction` → deletes GRN → recomputes PO status.
- The older `Modules/Purchase` (direct purchase) still exists in the backend; the sidebar only exposes Purchase Orders.

## 8. Transactions (`Modules/Transaction`)

Payment, Receipt, Journal Voucher, Fund Transfer — all are "post a balanced entry + keep a business record".
Linkage tables tie money to documents: `receipt_order` (Receipt ↔ Order) and `payment_purchase_order`
(Payment ↔ PO). `TransactionService::receiptsForOrder()`, `paymentsForPurchaseOrder()`.
Deleting any of these goes through `FinanceService::deleteEntry()` (balance-safe reversal).

## 9. Other modules worth knowing

- **Stakeholder** — one `stakeholders` table for customers *and* suppliers (Phase C merge; legacy `customers`/`suppliers`
  tables were dropped; `Customer`/`Supplier` models are typed views over it). `orders.stakeholder_id` is the link.
- **Delivery** — dispatch tracking only. **No stock or ledger effect** (Order already decremented stock at sale time),
  and it deliberately does not auto-change `orders.status`.
- **InventoryTransfer** — `internal` (outlet A→B, no ledger), `external` (write-off: Dr `INV-WRITEOFF` / Cr `INV-ASSET`
  at qty × buying price), `cost_center_change` (audit record only — inventory has no per-line cost-center dimension).
- **Vat** — `vat_transactions` polymorphic (`vatable_type/id`), direction `input|output`.
- **ProformaInvoice** — quote-like document with line items; no stock/ledger effect, and there is no convert-to-order step in the code today.
- **Report** — see §10.

## 10. Reports = live derivation

`Modules/Report/Services/ReportService.php` owns no data. It calls other Services:

| Endpoint (`GET /api/v1/admin/reports/...`, perm `report_view`) | Derived from |
|---|---|
| `sales-by-channel` | `OrderService::sumBySource()` (returns an **object keyed by source**, not an array) |
| `low-stock` | `InventoryService::lowStock()` + `ProductService::namesFor()` |
| `revenue-summary` | system ledgers `SALES`, `SALES-DISC`, `COGS` + sum of group `IND-EXP` |
| `balance-sheet`, `trial-balance` | `FinanceService` |
| `inventory-value[?outletId]` | `InventoryService::allStockRows()` × `ProductService::buyingPricesFor()` |
| `debtors` | `OrderService::totalsByStakeholder()` − `TransactionService::receiptsAppliedByStakeholder()` (computed; customers have no receivable ledger) |
| `creditors` | `FinanceService::payableBalancesBySupplier()` (suppliers *do* have payable ledgers) |
| `purchase-summary` | `PurchaseOrderService::totalsByStatus()` + `GrnService::count()` |
| `store-stock/{outletId}` | `InventoryService::allStockRows($outletId)` |

## 11. Where do I find X? (cheat sheet)

| I want to change… | Open |
|---|---|
| The URL/permission of an endpoint | `app/Modules/{X}/routes.php` |
| Validation rules | `Modules/{X}/Requests/*.php` |
| JSON field names sent to the UI | `Modules/{X}/Resources/*.php` |
| How a sale posts to the books | `OrderService::postSaleToLedger()` |
| Stock rules | `Modules/Inventory/Services/InventoryService.php` |
| How money is posted | `Modules/Finance/Services/FinanceService.php` |
| Default chart of accounts | `database/seeders/ChartOfAccountsSeeder.php` |
| Permissions list / who gets what | `PermissionSeeder.php`, `RoleSeeder.php` |
| Error → HTTP mapping | `bootstrap/app.php` |
| VAT default / app-specific config | `config/zurie.php` |
| Rate limits, global bindings | `app/Providers/AppServiceProvider.php` |
| Login / session behaviour | `Modules/Auth/Services/AuthService.php`, `config/session.php`, `config/sanctum.php` |

Frontend mapping (`zurie/`): route page `app/(admin)/admin/{x}/page.tsx` →
`features/admin/{x}/{x}-client.tsx` (state + TanStack Query) → `services/{x}/{x}.service.ts` (HTTP) →
paths in `services/api/endpoints.ts`. Sidebar is data in `components/admin/admin-nav.ts`.
**The API contract is camelCase; the DB is snake_case; Resources translate.** Most "field is undefined" UI
bugs are a Resource/type mismatch (this is exactly what broke `/admin/reports`).

## 12. Debugging playbook (real failures we hit)

| Symptom | Cause | Fix |
|---|---|---|
| `403 This action is unauthorized` on a brand-new endpoint | Permission key not in DB, or role not synced | Re-run `PermissionSeeder` then `RoleSeeder`; check the key string matches the route exactly. |
| `SQLSTATE[42S02] Base table not found` for a pivot model | Eloquent guesses plural (`receipt_orders`); our pivot migrations are singular (`receipt_order`) | Add `protected $table = 'receipt_order';` to the model. Hit 3 times. |
| `Route [login] not defined` (500) on an API call | Request was **unauthenticated** and Laravel tried to redirect | Fix your session/CSRF; it's not a routing bug. |
| `Session store not set on request` | curl without `Origin: http://localhost:3000` (Sanctum stateful domain check) | Send the Origin header; fetch `/sanctum/csrf-cookie` first; URL-decode `XSRF-TOKEN` into `X-XSRF-TOKEN`. |
| Ledger balance looks wrong / off by a deleted entry | Someone hard-deleted a `journal_entries` row | Never; use `FinanceService::deleteEntry()`. Balances are incremental. |
| Liability shows negative in your own report code | You re-negated a normalised balance | See §4.3 — `current_balance` is already own-direction. |
| `InsufficientStockException` on un-receive | Received goods were already sold | Intended; you can't remove stock that no longer exists. |
| Frontend `x.map is not a function` | Type in `services/*.ts` doesn't match the real JSON (object vs array) | `curl` the endpoint, fix the TS type. |
| Next dev `Cannot find module './5611.js'` | Ran `next build` while `next dev` shared `.next` | Kill server, `rm -rf .next`, restart. Use `npx tsc --noEmit` for typechecks while dev runs. |
| `php artisan serve` "can't find public/index.php" | Wrong PHP version on PATH | `vendor/` was built on PHP 8.5; run with `/opt/homebrew/Cellar/php/8.5.1_2/bin/php`. |
| Config file crash `Target class [env] does not exist` | Used `app()` inside `config/*.php` | Config files may only use `env()`. |

**Fastest way to learn/verify anything:** `php artisan tinker`, resolve the service
(`app(\App\Modules\Report\Services\ReportService::class)->balanceSheet()`) and call it. Then `curl` the route.
Logs: `storage/logs/laravel.log`. List routes: `php artisan route:list --path=admin/reports`.

## 13. Recipe: add a new module ("Widget")

1. `app/Modules/Widget/{Controllers,Models,Services,Requests,Resources,Providers}` + `routes.php`.
2. Migration in `database/migrations/` (**explicit `protected $table`** if you use a singular/pivot-style name).
3. Service holds logic; wrap multi-table writes in `DB::transaction`; money → `FinanceService::postEntry`; stock → `InventoryService`.
4. Controller: `use ApiResponse;` return `$this->ok()/created()`.
5. The 3 registration touches (§2) + permission keys → re-seed.
6. Standing requirement: **full CRUD** (create/read/update/deactivate). Documented exceptions are append-only
   financial records (Order, GRN, Delivery, InventoryTransfer…), which get create/list/show (+ delete only where a
   safe reversal exists).
7. Verify with tinker → curl → UI. Clean up test data.

## 14. Known weak spots (be aware)

- **No automated test suite** (there is no `tests/` directory at all). Everything was verified by hand via tinker/curl/Playwright. Adding
  feature tests around `FinanceService::postEntry`, `OrderService::createOrder` and `GrnService` first would buy the most safety.
- Rule-2 relaxations (§2) are intentional but are places where a schema change in one module can break another silently.
- `PurchaseOrderItemResource` computes received quantity per item (N+1) — accepted for now.
- One High PostCSS advisory remains on the frontend (needs a Next 15→16 major upgrade; deliberately deferred).
- Balance sheet relies on the "no closing entries" assumption; if you later add period closing, change `balanceSheet()`.

## 14b. Open work — partly done, come back to these

| Item | Done so far | Still to do |
|---|---|---|
| Money precision | Lines rounded to cents before the balance check; reconcile compares in cents | Move to whole-cent integers (or a decimal library) end to end; remove the 1-cent tolerance in `postEntry` |
| Report growth | Indexes on orders / journal date / stakeholders / inventory movements | Date-range filters on reports; cache heavy aggregates; paginate the ~24 unbounded `get()` calls |
| Operations | Nightly backup, nightly `finance:reconcile`, idempotency-key pruning, logging notes in `.env.example` | Admin 2FA, error monitoring, log rotation on the server, restore test of a backup |
| Infrastructure | Nothing (server work) | Redis for sessions/cache/queue, object storage for uploads, real mail provider, queue worker, load test on staging |
| Idempotency | Backend supports all money POSTs; storefront checkout and POS sale send the key | Send the key from payments, receipts, purchase orders, GRNs, transfers, expenses screens |
| Test coverage | Ledger posting, checkout, GRN receive/un-receive, reconcile, idempotency | Payments/receipts, transfers, delivery, auth and RBAC boundaries |
| Customer/staff split | Designed (`customer_accounts` table + `customer` guard), not started | Build it: tests first, then schema, guard, endpoints, frontend, live boundary tests |

## 15. Suggested reading order (≈ half a day)

1. `CLAUDE.md`, this file.
2. `bootstrap/app.php` → `routes/api.php` → `Modules/Order/routes.php`.
3. `Modules/Order/Controllers/OrderController.php` → `OrderService::createOrder()` (§6).
4. `Modules/Finance/Services/FinanceService.php` (postEntry, applyToLedgerBalance, deleteEntry, trialBalance, balanceSheet).
5. `Modules/Inventory/Services/InventoryService.php`.
6. `Modules/Procurement/Services/{PurchaseOrderService,GrnService}.php`.
7. `Zurie_V3_ProsERP_Adaptation_Plan.md` (in the frontend repo) for the *why* behind phases C–G.
