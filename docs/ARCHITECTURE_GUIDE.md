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
| Audit log | spatie activitylog, `activity('x')->log(...)` | Written manually inside services, not automatic — a module with no `activity()` call leaves no trail. Viewable in Admin → Activity. Covers auth events, Order/GRN/PurchaseOrder/Purchase/Delivery/InventoryTransfer/Expense, RoleService/UserService's escalation-guard actions, and (as of this pass) every Transaction subtype create+delete, Finance's Ledger/LedgerGroup/CostCenter CRUD, and `finance:reconcile --fix`'s corrections. When adding a module that creates, deletes, or silently corrects a money or stock record, add an `activity()` call — it was previously easy to ship a whole module (Transaction had zero logging until this pass) without one. |
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

### Two-factor authentication (TOTP, optional per account)

`Modules/Auth/Services/TwoFactorService.php` — RFC 6238 (the standard Google Authenticator/Authy/1Password
all implement), via `pragmarx/google2fa`. Any account can enable it (not yet mandated for any role — a
future decision, not a gap in the code); `users.two_factor_secret`/`two_factor_recovery_codes` are
`encrypted` casts (APP_KEY), never plaintext.

```
 Setup (authenticated):
   POST /auth/two-factor/enable   → { secret, qrCodeUrl }         (does NOT protect the account yet)
   POST /auth/two-factor/confirm  { code } → { recoveryCodes[] }  (proves the app actually works — THIS activates it)
   POST /auth/two-factor/disable  { password }                    (password reconfirmation required)
   GET  /auth/two-factor/status   → { enabled }

 Login, when the account has 2FA confirmed:
   POST /auth/login { email, password }
     → password correct, 2FA confirmed: Auth::guard('web')->logout() immediately (never leaves a full
       session), stores { two_factor_user_id, two_factor_expires_at (+5 min) } in the (regenerated) session
     → responds { twoFactorRequired: true } — NOT the user object
   POST /auth/two-factor/challenge { code }     (throttle:two-factor — 5/min by session-id+IP)
     → verifies a live TOTP code OR a single-use recovery code against the pending marker
     → only NOW: Auth::guard('web')->login($user), session regenerate
```

`AuthService::attempt()`'s return type changed from `User` to
`array{status: 'authenticated', user: User}|array{status: 'two_factor_required'}` — check both call sites
(`AuthController::login()`) if you touch this. `challengeTwoFactor()` fails closed: an expired or
already-consumed pending marker forces the caller back through `attempt()` with their password again,
never leaves a long-lived half-authenticated window. Verified with 11 tests in `tests/Feature/Auth/
TwoFactorTest.php` (including the full login → pending → wrong-code-rejected → correct-code-authenticates
round trip) plus a second pass over real HTTP with a live-generated TOTP code.

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
- Every amount is converted to `App\Support\Money` (integer cents, never float) the moment it enters
  `postEntry()`, and stays Money through the balance check and `applyToLedgerBalance()` — see §14b's
  "Money precision" row for why and how this was verified.

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

## 9a. The customer/staff split

Two completely independent logins share this backend, on two Auth guards. `Modules/Auth/Models/User` is
**staff-only** — `'web'` guard, RBAC (roles/permissions), created by an existing admin
(`UserController::store()`), never self-registered. `Modules/Auth/Models/CustomerAccount` (table
`customer_accounts`) is the **storefront login** — `'customer'` guard, no roles/permissions at all,
self-registers via `POST /customer/auth/register`.

```
 users               ──'web' guard───▶  /auth/*            (staff: login, 2FA, RBAC-gated admin/* routes)
 customer_accounts   ──'customer' guard▶ /customer/auth/*   (storefront: login, register, Google)
                                          + account/*, /products/{id}/reviews POST  (self-service, customer-only)
```

- **Same session cookie, independent logins.** Both guards are `driver: session` (`config/auth.php`) and share
  the one cookie the api group's `statefulApi()` middleware makes stateful — but Laravel keys each guard's
  login state separately within that session, so being logged into one implies nothing about the other. This
  is what fixed two real bugs: an admin browsing the storefront no longer appears logged in as a customer, and
  a customer session can never reach an admin (`permission:xxx`) route — not because of an extra check, but
  because `EnsurePermission`/every admin controller resolve `$request->user()` (the **'web'** guard implicitly),
  and a customer was never logged into 'web' at all.
- **`auth:customer`, not `auth:sanctum`, protects customer-only routes.** Laravel's plain `Authenticate`
  middleware pointed at the 'customer' guard — deliberately not Sanctum's own guard-cycling (`config/sanctum.php`
  `'guard' => ['web']` is untouched), so this addition changes nothing about how any existing staff route
  resolves `auth:sanctum`. The api group's `statefulApi()` has already made every request session-aware
  regardless of which guard checks it afterward, so `auth:customer` needs no CSRF/session setup of its own.
- **The link to a real Customer/stakeholder record is one-directional and new**: `customer_accounts.stakeholder_id`
  (nullable, unique). The pre-split `stakeholders.user_id` column (→ `users`) still exists but is deliberately
  **left untouched, orphaned for any pre-split account** — see the `customer_accounts` migration's docblock.
  `CustomerService::findByUserId()` is `@deprecated`; self-service code uses `findById()` with a
  CustomerAccount's own `stakeholder_id` instead. Admin-facing `CustomerResource.isRegistered` is computed via
  a `EXISTS(...)` subquery against `customer_accounts` (`CustomerService::withAccountFlag()`), not the stale
  `user_id` check.
- **Notifications became polymorphic** (`notifiable_type`/`notifiable_id`, not a plain `user_id`) — a
  notification for `customer_accounts` id 5 and one for `users` id 5 would otherwise collide on the same
  integer. `NotificationService::notify(Model $notifiable, ...)` takes either model directly.
- **Password resets need to know which guard.** `config/auth.php` `passwords` has two brokers
  (`users` → `password_reset_tokens`, `customer_accounts` → `customer_password_reset_tokens`, a
  separate table so the same email existing in both tables can never collide on one token row). The reset
  *email link* itself is built once, globally, by `AuthModuleServiceProvider`'s `ResetPassword::createUrlUsing()`
  — it branches on the notifiable's class to send staff to `/admin/reset-password` (frontend) and customers to
  `/reset-password`, since nothing else about the notification event says which broker fired it.
- **Google login is customer-only** — `CustomerAuthController`/`CustomerAccountService::findOrCreateForSocialite()`.
  Simplified from the pre-split version: matches by email directly rather than also tracking an
  `oauth_identities` row first (Google's verified-email guarantee makes the case that extra indirection existed
  for vanishingly unlikely) — see `findOrCreateForSocialite()`'s docblock if that behavior is ever needed again.
- **Frontend**: `services/auth/auth.service.ts` (staff) and `services/auth/customer-auth.service.ts` (customer)
  are separate files calling separate endpoint groups (`API_ENDPOINTS.auth` vs `.customerAuth`) — never share
  one service. `providers/customer-auth-provider.tsx` and `providers/admin-auth-provider.tsx` are similarly
  independent; the admin guard's existing "redirect to `/admin/login` if `GET /auth/user` fails" logic is what
  now correctly locks a customer session out of `/admin/*`, since that call genuinely 401s for a customer.
- **Testing gotcha**: a test driving several requests against *two different guards* in one method needs
  `config(['session.driver' => 'database'])` in `setUp()` — the default `array` driver (phpunit.xml) backs
  onto one in-memory Store shared oddly across simulated requests within a test and produces flaky,
  order-dependent 401s that don't reflect real multi-request browser behavior at all (reproduced and worked
  around in `tests/Feature/CustomerAuth/CustomerStaffSplitTest.php` — the one "both guards logged in at once"
  scenario too failed this way in every driver tried, so it's verified by real curl transcript in this file's
  history instead of an automated test).

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

## 10b. How the modules connect (dependency map)

Generated from each Service's constructor injections (cross-module only), so it shows who really calls whom.

```
                    ┌──────────── READERS (derive, own no data) ────────────┐
                    │  Report · Dashboard · Target · CashierSession         │
                    └───────────────▲───────────────────────────────────────┘
                                    │ read via Services
    ┌───────────── BUSINESS FLOWS (orchestrate; the "verbs") ───────────────┐
    │  Order (sale)   Procurement (PO/GRN)   Purchase   InventoryTransfer   │
    │  Transaction (payments/receipts…)   Expense   Delivery   Proforma     │
    └───────▲──────────────▲─────────────────▲──────────────────▲───────────┘
            │              │                 │                  │
    ┌───────┴──────────────┴─────────────────┴──────────────────┴───────────┐
    │  CORE ENGINES (the "nouns" everything posts into)                      │
    │  Finance (ledger)   Inventory (stock)   Vat   Currency                 │
    └───────▲──────────────▲──────────────────────────────────────────────────┘
            │              │
    ┌───────┴──────────────┴─────────────────────────────────────────────────┐
    │  MASTER DATA (things being sold/bought/from)                            │
    │  Product · Customer/Supplier/Stakeholder · Outlet · PriceList · Coupon  │
    └────────────────────────────────────────────────────────────────────────┘
```

Calls go **down** (or sideways into the engines), never up. Master data and engines never call flows.

| Module | Injects (other modules' Services) |
|---|---|
| Order | Coupon, Currency, Customer, Finance, Inventory, Notification, Outlet, PriceList, Product, Vat |
| Procurement | Currency, Finance, Inventory, Product, Vat |
| Purchase | Currency, Finance, Inventory, Product, Supplier, Vat |
| InventoryTransfer | Finance, Inventory, Outlet, Product |
| Transaction | Finance |
| Expense | Finance |
| Report | Finance, Inventory, Order, Procurement, Product, Stakeholder, Transaction |
| Dashboard | Customer, Inventory, Order, Product |
| Target, CashierSession | Order |
| Finance | Currency |
| Inventory | Outlet |
| Product | Inventory, Media |
| Supplier | Finance |
| ProformaInvoice | Currency |
| Auth | Customer |

Two rules the map obeys:

1. **Flows post into engines.** Every business event ends in two questions: did stock change (Inventory), did money change (Finance)? Nothing else writes to those.
2. **Cross-module links are ids and polymorphic references, not foreign keys** (e.g. `journal_entries.reference_type/id` points back at the document that caused the entry).

Using it to design a new module: (1) which layer is it — flow, reader or master data; (2) which engines does it touch; (3) what Services would it inject — a long list means it does too much; (4) who calls it — if only Report, it stays at the top. Two flows must never call each other (the known case, `GrnService` ↔ `PurchaseOrderService`, is resolved by orchestrating in the controller). Example: a Stock Take module injects only `InventoryService`, `FinanceService`, `Outlet` and `Product`.

Regenerate the table if it drifts: read the constructor of each `app/Modules/*/Services/*Service.php` and list injected Services from other modules.

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
| Money precision | Done for the ledger core: `App\Support\Money` (integer-cents value object, Fowler's Money pattern + Stripe's minor-unit convention — see its docblock for citations) is now used by every arithmetic step in `FinanceService`: `postEntry()`'s balance check, `applyToLedgerBalance()` (the one running total every posting for the business's lifetime adds to), `deleteEntry()`, `reconcileLedgers()`, `balanceSheet()`'s bucket totals, and the period-movement helpers. Proven exact with a dedicated `MoneyTest` (10,000 accumulated postings stay exact to the cent — floats measurably drift at that volume) plus a live stress test (2,000 real postings through `postEntry`, then 5 real HTTP checkouts) both matched expected totals to the cent and left `finance:reconcile` clean. The genuine 1-cent tolerance in `postEntry()`'s balance check is kept (see its comment) — it's a real business tolerance for independently-rounded VAT lines, not float slack, since the sums either side of it are now exact | Upstream per-line calculations (Order/GRN/PurchaseOrder line totals, VAT %, discounts) still compute in plain float before handing a final amount into `postEntry()` — deliberately out of scope: `postEntry()` is the one place every module's money already funnels through (Extensibility Constitution), so fixing precision there fixes the *accumulated* ledger balances for the whole system without a full-codebase rewrite. A stray float bug could still make one line's amount wrong before it reaches `postEntry()`; extending `Money` to those modules' calculators is the next increment if warranted. Scale note: `Money` is native-int arithmetic (cheaper than the float ops it replaced, no bcmath/GMP dependency) and adds zero queries — it doesn't change the real scaling bottleneck already documented in §6.3/6.4 (lock contention on shared ledgers under concurrent checkouts). Security note: this is a pure internal-correctness fix with no new input surface — it closes a data-integrity gap (silent, permanent balance drift going undetected for months) rather than an attacker-reachable one. |
| Report growth | Indexes on orders / journal date / stakeholders / inventory movements. `from`/`to` date filters added to Sales by Channel, Revenue Summary, Purchase Summary (`FinanceService::ledgerMovementInPeriod()`/`groupMovementInPeriod()` recompute from journal lines in the window, since `current_balance` is never date-partitioned). Trial Balance, Balance Sheet, Debtors, Creditors, Inventory Value now cached 60s (`Cache::remember`, plain TTL, no invalidation) | Debtors/Creditors/Trial Balance/Balance Sheet/Inventory Value are point-in-time balances — a date range doesn't apply to them without a bigger "period snapshot" project, deliberately not attempted. Still haven't paginated the ~24 unbounded `get()` calls elsewhere in the app. Cache means a manager can see a figure up to 60s stale after something posts. Move `CACHE_STORE` to Redis before this matters at real scale (`database` store means every cache hit is itself a query) |
| Operations | **2FA** (TOTP, RFC 6238, `PragmaRX\Google2FA`): optional per account via `POST /auth/two-factor/{enable,confirm,disable}`, login gated by `POST /auth/two-factor/challenge` when confirmed (see §3a). Single-use recovery codes, disable requires password reconfirmation. Verified with 11 feature tests (including a real login → pending → wrong-code-rejected → correct-code-authenticates round trip) plus a second pass over real HTTP with a live TOTP code. Frontend: `app/(auth)/admin/login/page.tsx` now handles the password → pending-challenge → code steps, and a self-service `/admin/account/security` page (features/admin/account-security) lets any admin enable/disable it with a client-side-rendered QR code (the `qrcode` npm package — the TOTP secret never leaves the browser via a third-party QR-image service). **Error monitoring**: Sentry wired via `Sentry\Laravel\Integration::handles()` in `bootstrap/app.php` — a documented no-op until `SENTRY_LARAVEL_DSN` is set (`.env.example`), verified the app still boots/serves correctly with it wired in and no DSN configured. **Log rotation**: `daily` channel already existed in `config/logging.php` (14-day retention) — `.env.example` now says explicitly to set `LOG_STACK=daily` in production. **Backup restore test**: `php artisan backup:verify-restore` — restores the latest dump into a disposable scratch database, compares every table's row count against live, and runs `finance:reconcile` against the restored copy too; scheduled weekly. Verified for real: ran a live restore of the actual local backup (76/76 tables matched, restored ledgers reconciled), then verified the failure path by deliberately truncating a dump (correctly caught, clean exit code, scratch DB still dropped) | Nothing structural left — the remaining Operations work is server-side execution: actually get a Sentry DSN and confirm a real event arrives, confirm `LOG_STACK=daily` on the live server, and let `backup:verify-restore` run for real weeks in a row |
| Infrastructure | **Redis**: `predis/predis` installed (pure-PHP client — this app's shared-hosting target can't install the `phpredis` extension) and verified end-to-end against a real local Redis instance this session — cache put/get, and a queue push/pop, both round-tripped correctly through `REDIS_CLIENT=predis`. Nothing else needs to change in code; `.env.example` documents the switch. **Object storage**: `config/filesystems.php` already had an `s3` disk reading `AWS_*` vars, but `MediaService` (the only place in the system that writes a file) was hardcoded to the `'public'` disk name — fixed to read a new `media_disk` config key (`MEDIA_DISK` env var, defaults to `'public'`, unchanged behavior), verified with a real local upload+delete round trip plus 4 feature tests including one that overrides the disk and confirms the file actually lands there. **Mail**: `config/mail.php` already supports smtp/ses/postmark/resend natively — `.env.example` now says explicitly which var to change, no code needed | Queue **worker** (a process actually consuming `QUEUE_CONNECTION=redis`, e.g. supervisor running `php artisan queue:work`) is genuinely server ops, can't be verified from here. Load test on staging — same, needs a real staging environment. Nobody has actually set `MAIL_MAILER`/`AWS_*`/`SENTRY_LARAVEL_DSN`/`REDIS_*` to real production values yet — everything above is proven to *work*, not yet turned on |
| Idempotency | Backend supports all money POSTs; frontend now sends the key from checkout, POS sale, payments, receipts, journal vouchers, fund transfers, purchase orders, GRNs, inventory transfers, purchases and delivery dispatch | Expense has no admin frontend yet, so its create call doesn't send the key — add it when that screen is built |
| Test coverage | 50 tests: ledger posting, checkout, GRN receive/un-receive, reconcile, idempotency, payments/receipts, journal vouchers/fund transfers, inventory transfers (all 3 types), delivery dispatch, RBAC escalation guards + a real HTTP 401/403/200 boundary, and the audit-log assertions below | Frontend component/e2e tests, the customer/staff split once built |
| Audit trail | Was auth-only, plus a handful of manual `activity()` calls in Order/GRN/PurchaseOrder/Purchase/Delivery/InventoryTransfer/Expense/RoleService/UserService. Now also covers every Transaction subtype (Payment/Receipt/JournalVoucher/FundTransfer) create+delete, Finance's LedgerGroup/Ledger/CostCenter CRUD, and `finance:reconcile --fix` corrections (with the before/after values in `properties`) — all verified via `assertDatabaseHas('activity_log', ...)` in `tests/Feature/Finance/AuditTrailTest.php` and the Transaction tests | Still nothing logs Product/Category/Supplier/Stakeholder/PriceList/Coupon/Outlet CRUD, or CashierSession open/close — audit each module the same way (does creating/deleting/correcting it matter if nobody can see who did it?) before treating this as complete |
| Customer/staff split | **Done** — see §9a for the full design. `customer_accounts` table + `customer` Auth guard, fully independent of staff `users`/`web`. Backend: `CustomerAuthController`, `CustomerAccountService`, polymorphic notifications, dual password brokers, Google login moved. Account/Wishlist/Review(store)/Notification re-pointed to the customer guard; admin-only Review moderation untouched. Frontend: separate `customerAuthService`/`authService`, separate providers, new `/admin/reset-password` page. Verified: 5 reliable automated tests (`CustomerStaffSplitTest`) covering register→login→self-service, customer-can't-reach-admin (401, not just 403), staff-can't-reach-customer-routes, phone-collision rejection, and per-customer data isolation (wishlist/notifications) — plus a live curl transcript proving both guards work simultaneously in one browser and that logging the customer out leaves the staff session untouched (the exact bug `CustomerAuthService::logout()`'s `regenerate()`-not-`invalidate()` fix targets). Full 83-test suite and `finance:reconcile` still clean after this change | Nothing structural — this was the last item on the original pending list. Follow-ups if they come up in practice: an actual staff-notifications feature (the polymorphic model already supports it, nothing consumes it yet); reconsidering whether `oauth_identities`/pre-split `stakeholders.user_id` should eventually be cleaned up once confirmed nothing legacy depends on them |

## 15. Suggested reading order (≈ half a day)

1. `CLAUDE.md`, this file.
2. `bootstrap/app.php` → `routes/api.php` → `Modules/Order/routes.php`.
3. `Modules/Order/Controllers/OrderController.php` → `OrderService::createOrder()` (§6).
4. `Modules/Finance/Services/FinanceService.php` (postEntry, applyToLedgerBalance, deleteEntry, trialBalance, balanceSheet).
5. `Modules/Inventory/Services/InventoryService.php`.
6. `Modules/Procurement/Services/{PurchaseOrderService,GrnService}.php`.
7. `Zurie_V3_ProsERP_Adaptation_Plan.md` (in the frontend repo) for the *why* behind phases C–G.
