# Zuriè Backend — CLAUDE.md

Laravel 13 **JSON API** for the Zuriè frontend (`/Users/willbardmloka/projects/zurie`, a
separate Next.js repo). Neither repo contains the other's code. They talk over HTTP/JSON at
`/api/v1`, following the contract in the frontend's `docs/development-guide.md`.

> Deep-dive walkthrough with debugging playbook: [`docs/ARCHITECTURE_GUIDE.md`](docs/ARCHITECTURE_GUIDE.md).

```
┌──────────────┐   HTTP/JSON (cookie session + CSRF)   ┌──────────────────────────┐   ┌───────┐
│  Next.js app │ ────────────────────────────────────▶ │  Laravel API (this repo) │──▶│ MySQL │
│  zurie/      │ ◀──────────────────────────────────── │  app/Modules/*           │   └───────┘
└──────────────┘      { success, data | errors }        └──────────────────────────┘
```

---

## 1. Module system — how the code is organized

**Not** classic Laravel MVC. Everything lives in `app/Modules/{Domain}/`.
`app/Http/Controllers/Controller.php` is an empty base class — nothing real lives in the default tree.

```
app/Modules/{Domain}/
├── Controllers/   HTTP in/out only → calls a Service, wraps in a Resource
├── Requests/      FormRequest validation (camelCase input names)
├── Services/      ALL business logic, transactions, cross-module calls
├── Models/        Eloquent: table, relations, casts (little logic)
├── Resources/     JSON output shape (DB snake_case → API camelCase)
├── Providers/     {Domain}ModuleServiceProvider (bindings, listeners)
└── routes.php     The only place this module's URLs/permissions are declared
```

**Domains today:** Auth · Product · Inventory · InventoryTransfer · Order · Pos · CashierSession ·
Customer · Supplier · Stakeholder · Procurement (PurchaseOrder + GRN) · Purchase · Delivery ·
Finance · Transaction · Vat · Currency · Expense · Report · Outlet · PriceList · ProformaInvoice ·
Coupon · Target · Media · Settings · Activity · Dashboard · Notification · Faq · Enquiry ·
Review · Wishlist · MeasurementUnit · Account

**Registering a module touches exactly 3 files outside its folder:**

| # | File | Change |
|---|---|---|
| 1 | `bootstrap/providers.php` | add `{Domain}ModuleServiceProvider` |
| 2 | `routes/api.php` | one `require` line for its `routes.php` |
| 3 | `database/seeders/PermissionSeeder.php` | add its permission keys (if any routes are gated) |

Migrations need no registration (auto-discovered from `database/migrations/`).

---

## 2. Request lifecycle

```
 Client
   │  POST /api/v1/admin/purchase-orders
   ▼
 public/index.php
   ▼
 bootstrap/app.php ─────── routing · middleware · exception rendering (configured ONCE here)
   ▼
 routes/api.php ────────── apiPrefix 'api/v1' set centrally (never write "v1" in a route file)
   ▼
 Modules/X/routes.php ──── auth:sanctum ▸ permission:xxx_create
   ▼
 Controller ────────────── thin: no business logic
   ▼
 FormRequest ───────────── validation → 422 envelope on failure
   ▼
 Service ───────────────── business rules · DB::transaction · calls OTHER modules' Services
   ▼
 Model (Eloquent) ──────── persistence
   ▼
 Resource ──────────────── shapes the JSON
   ▼
 ApiResponse trait ─────── { success: true, data, meta? }

 Any Throwable ──▶ bootstrap/app.php $exceptions->render() ──▶ { success:false, message, errors? }
```

Rule of thumb: **routes.php → Controller method → Service method** — three hops to the real logic.

---

## 3. Auth & authorization

### Authentication — Sanctum cookie-based SPA (no tokens)

```
 GET /sanctum/csrf-cookie ──▶ sets XSRF-TOKEN cookie
 POST /api/v1/auth/login  ──▶ Auth::guard('web')->attempt() + session()->regenerate()
                              (regenerate defeats session fixation) ──▶ session cookie only
 every later call sends:  Cookie + X-XSRF-TOKEN (URL-decoded) + Origin (stateful domain)
```

`$middleware->statefulApi()` in `bootstrap/app.php` + `SANCTUM_STATEFUL_DOMAINS` (includes
`localhost:3000`) is what makes the frontend origin a same-site session.

### Authorization — hand-rolled RBAC (not spatie/permission)

```
 users ──user_roles──▶ roles ──role_permissions──▶ permissions (column: key)
                                    │
 route: ->middleware('permission:product_create')
                                    ▼
 Modules/Auth/Middleware/EnsurePermission  ── has it? ──▶ next    else 403
```

| Fact | Consequence |
|---|---|
| The route string must exist as a `permissions.key` row | A typo or unseeded key **403s everyone, silently** — no compile-time link, keep in sync by hand |
| `RoleSeeder` syncs **every** permission to the `admin` role only (`super_admin` gets none) | After adding a key: re-run `PermissionSeeder` **then** `RoleSeeder` |
| `User::permissionKeys()` sent to the client | **UI convenience only** (hide buttons). The middleware is the real boundary — never skip it because "the frontend hides it" |
| Privilege-escalation guards | `RoleService::assertCanGrantPermission()` (can't grant what you don't hold), `UserService::assertNotActingOnSelf()` |

---

## 4. Response envelope — one trait, no exceptions

`App\Support\Http\ApiResponse` is the *only* place a JSON response is shaped. Controllers never call
`response()->json()`.

| Shape | Helper | Body |
|---|---|---|
| Single resource | `ok()` / `created()` | `{ success: true, data }` |
| Paginated list | `paginated()` | `{ success: true, data: [...], meta }` |
| Ack | `ok(['deleted'=>true])` | `{ success: true, data }` |
| Error | `fail()` / global handler | `{ success: false, message, errors? }` |

Error mapping is centralized in `bootstrap/app.php` → `$exceptions->render(...)`:

| Thrown | Becomes |
|---|---|
| `ValidationException` | 422 + field `errors` |
| Unauthenticated / unauthorized | 401 / 403 |
| `ModelNotFoundException` | 404 |
| `InsufficientStockException`, `InvalidOrderTransitionException` | domain-specific status/message |
| SQLSTATE 23000 (integrity) | mapped message |

A new domain exception needing its own status → add an `if` branch there (no auto-discovery).

---

## 5. Cross-module coupling — Services only, never Models

```
   ✅  OrderService ──▶ CustomerService · ProductService · InventoryService · FinanceService
                          (constructor injection)

   ❌  OrderService ──▶ Inventory\Models\Inventory      (never reach into another module's Model/table)
```

This keeps "add one feature" from requiring knowledge of five other modules' internals.

**Documented, deliberate exceptions** (read-only lookups, commented at the call site):
`GrnService` reads `Supplier` · `DeliveryService` reads `Order`/`OrderItem` ·
`TransactionService::receiptsAppliedByStakeholder()` raw-joins `orders`. **Writes** across modules
always go through the other module's Service.

Avoid circular Service dependencies (e.g. `GrnService → PurchaseOrderService`, so the "instant receive"
orchestration lives in `PurchaseOrderController`, not in `PurchaseOrderService`).

---

## 6. Money & stock core

### 6.1 Double-entry ledger (`Modules/Finance`)

```
 LedgerGroup (tree, nature: asset|liability|income|expense|equity)
     └── Ledger (account: Cash, Sales, a supplier's payable …)
              ▲
 FinanceService::postEntry(lines[])  ← THE single posting primitive
     • validates debits == credits BEFORE writing (else UnbalancedJournalEntryException)
     • writes JournalEntry + lines, updates ledger balances
 FinanceService::deleteEntry()       ← the ONLY safe way to delete a posted entry
```

**Sign convention (the classic trap):** `ledgers.current_balance` is stored in the ledger's *own normal
direction*, updated **incrementally, never recomputed**:

| Ledger nature | Debit | Credit |
|---|---|---|
| asset / expense | **+** | − |
| liability / income / equity | − | **+** |
| `is_contra = true` | flips the row above | |

So a supplier you owe shows a **positive** balance. Never hard-delete journal rows or edit
`current_balance` directly — use `deleteEntry()`. No closing entries exist, so the Balance Sheet folds
undistributed profit into Equity.

### 6.2 Inventory (`Modules/Inventory`)

- One row per **(product_id, sales_outlet_id)**; *Store ≡ SalesOutlet*.
- Every `InventoryService` method takes optional `?int $outletId`: **writes** resolve to one outlet
  (default: online outlet), **aggregate reads** sum across all outlets when omitted.
- `inventory_movements` = append-only log (`type` is a free string, polymorphic reference).

### 6.3 Concurrency — row locking, always inside a transaction

```
 DB::transaction(function () {
     $row = Inventory::where(...)->lockForUpdate()->first();   // ← the real guard
     ... decrement / adjust balance ...
 });
```

`lockForUpdate()` **outside** a transaction is a silent no-op. Use this exact pattern for any
stock-affecting or balance-affecting write.

### 6.4 Flow map — who posts what

```
 SALE   (OrderService::createOrder, ONE transaction)
        Order ▸ lock+decrement stock ▸ price-list price ▸ VAT (server-computed) ▸ coupon
        ▸ postSaleToLedger  Dr Cash/AR · Cr Sales/VAT-Out · Dr COGS · Cr Inventory

 PURCHASE  PurchaseOrder (intent only, no stock/ledger)
             └─ GRN (reality)  stock in ▸ Dr Inventory (+VAT-In) · Cr Supplier payable
                 └─ "Instant Receive" = PO + GRN in one transaction
                 └─ "Un-receive"      = reverse stock ▸ deleteEntry ▸ delete VAT row ▸ recompute PO status

 PAYMENT / RECEIPT / JOURNAL VOUCHER / FUND TRANSFER  → balanced entries + linkage tables
        (receipt_order, payment_purchase_order)

 DELIVERY            dispatch tracking only — NO stock/ledger effect, doesn't change order status
 INVENTORY TRANSFER  internal (outlet→outlet) · external (write-off: Dr INV-WRITEOFF / Cr INV-ASSET)
                     · cost_center_change (audit record only)
```

### 6.5 Reports = live derivation

`Modules/Report/Services/ReportService` owns **no data**; every number is derived from other Services
(no manually-maintained totals). Endpoints under `GET /api/v1/admin/reports/*`, permission `report_view`.

---

## 7. Extensibility constitution — non-negotiable for every module

| # | Rule |
|---|---|
| 1 | **Only 3 files outside the module folder** get touched (table in §1). |
| 2 | **Cross-module calls go through Services**, never another module's Model/table (§5). |
| 3 | **"Growing categories" are rows, not columns/enums** — e.g. `inventory_movements.type` is a string, `journal_entries.reference_type/reference_id` is polymorphic; Cost Centers/Outlets/Price Lists grow by adding *rows*. |

Before building anything new, check the design against all three. A design that must alter an
*existing* table just to represent a new concept, or needs more than the 3 touchpoints, gets flagged and
reconsidered **before** it's built.

Direction/planning docs live in the **frontend** repo: `Zurie_V2_Architecture_Design (2).md` and
`Zurie_V3_ProsERP_Adaptation_Plan.md` (shared by both sides).

---

## 8. Standing working rules

| Rule | Detail |
|---|---|
| **Full CRUD from day one** | Every admin module ships create/read/update/deactivate. Documented exceptions: append-only financial/transactional records (Order, Purchase, GRN, Delivery, InventoryTransfer) → create/list/show (+ delete only where a safe reversal exists). |
| **Never commit or push unasked** | Leave changes in the working tree; each commit/push needs an explicit ask in that turn (both repos). |
| **Verify for real** | tinker + curl (or Playwright), not code review alone. Clean up test data afterward. |
| **Table naming gotcha** | Pivot-style migrations use singular names (`receipt_order`); give such models an explicit `protected $table`. |

---

## 9. Local-dev cheatsheet

```bash
# PHP: vendor/ was built on 8.5.1 — the PATH php may be 8.3 and fail with a confusing
# "can't find public/index.php". Check `php -v`, or use the full path:
/opt/homebrew/Cellar/php/8.5.1_2/bin/php artisan serve --port=8000

php artisan route:list --path=admin/reports          # what URLs exist
php artisan tinker                                   # app(SomeService::class)->method()
php artisan db:seed --class=PermissionSeeder && php artisan db:seed --class=RoleSeeder   # after new permission keys
tail -f storage/logs/laravel.log
```

| Symptom | Likely cause |
|---|---|
| 403 on a new endpoint | permission key not seeded / `RoleSeeder` not re-run |
| `Route [login] not defined` (500) | request was unauthenticated (bad session/CSRF), not a routing bug |
| `Session store not set on request` (curl) | missing `Origin: http://localhost:3000` header |
| `SQLSTATE[42S02]` on a pivot model | missing explicit `protected $table` (singular name) |
| Ledger balance off | a journal row was deleted outside `FinanceService::deleteEntry()` |
| `Target class [env] does not exist` | `app()` used inside a `config/*.php` file — use `env()` only |
