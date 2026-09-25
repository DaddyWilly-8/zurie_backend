# Zuriè Backend — Observability & Correctness Guarantees

Area 5 of the ops review: tracing a failed money/stock action end to end,
catching drift automatically, and knowing who touched what.

## Structured logging

`FinanceService::postEntry()` is the single posting primitive every
money-moving flow in the app goes through (checkout, GRN receive,
payments, receipts, journal vouchers, fund transfers — see `CLAUDE.md`
§6.1). Rather than adding logging separately to each of those callers,
it's added once, at the primitive itself, so nothing can post money
without being traced and no future caller can forget to log its own
posting:

- **Every successful post** logs `Log::info('Journal entry posted', [...])`
  with the entry id, reference type/id (which Order/Grn/Payment/etc. this
  came from), narration, line count, and every ledger id touched.
- **Every rejected unbalanced entry** logs `Log::error('Unbalanced journal
  entry rejected', [...])` with the same reference context before the
  exception is thrown — this is a caller-side bug by construction (see
  `postEntry()`'s own docblock), so the log line is what turns "checkout
  is throwing 500s" into "checkout is throwing 500s because *this specific
  order's* posting was unbalanced by *this amount*."

**Verified live**, not just written: ran a real checkout, GRN receive,
payment, receipt, journal voucher, and fund transfer via the test suite
and `tinker`, and confirmed every single one produced its own structured
log line in `storage/logs/laravel.log` — a support question like "what
happened to order #1234's ledger postings?" is now answerable by grepping
`reference_id":1234` in the log, without reconstructing it from the
`journal_entries` table by hand.

## Scheduled integrity checks

`finance:reconcile` (ledger balances) was **already** scheduled nightly
with `Log::critical()` alerting from an earlier hardening pass — no
change needed there, confirmed still in place.

**New**: `inventory:reconcile`, the same safety net applied to stock.
`InventoryService::reconcileStock()` recomputes every `(product, outlet)`
row's quantity from scratch by summing its `inventory_movements` rows
(signed deltas, always starting from 0 — see
`InventoryMovement`'s own docblock) and compares it against the stored
`inventory.quantity`. Every quantity-changing path in `InventoryService`
(`decrementForOrder`, `restockForOrder`, `receivePurchase`,
`reversePurchaseReceipt`, the transfer pair, and `update()`'s own
"adjustment" entry) already writes a movement row, so real drift only
means one of two things: a bug in one of those paths, or a write that
reached the `inventory` table directly, bypassing the Service entirely.

Both commands are scheduled in `routes/console.php`:

```
00:00  backup:database        (nightly dump)
00:30  finance:reconcile      (ledger drift -> Log::critical on failure)
00:45  inventory:reconcile    (stock drift  -> Log::critical on failure)  <- new
01:00  prune idempotency keys
02:00  backup:verify-restore  (Sunday only  -> Log::critical on failure)
```

Both reconcile commands are read-only and exit non-zero on drift, so a
production log-monitoring/alerting tool watching for `CRITICAL` lines (or
a cron wrapper checking exit codes) gets notified the same way for either
kind of drift, money or stock.

**Verified for real**: ran `inventory:reconcile` against the local dev
database and it genuinely caught real drift — a product's stock had been
directly reset via raw SQL several times during this session's own
testing (bypassing `InventoryService`, exactly the bug class this command
exists to catch), leaving `inventory.quantity` ahead of what its recorded
movements accounted for. Fixed by recording the missing compensating
movement, re-ran clean. New regression test
(`tests/Feature/Inventory/StockReconcileTest.php`) proves the command
stays clean after normal `InventoryService` writes and catches drift from
exactly this kind of raw bypass.

## Audit-log coverage (money/stock-affecting models)

Every create/update/delete on a money- or stock-related record must name
who did it (`Spatie\Activitylog`, surfaced at Admin > Activity). Audited
current coverage against every module in `app/Modules`:

| Model | Status |
|---|---|
| Product, Category, PriceList/PriceListItem | already covered |
| Coupon | already covered (an earlier pass added this — the original brief for this review named it as a gap, but it's already fixed) |
| Supplier | already covered |
| **Customer** | **was the one real gap — fixed this pass** |
| Currency, CurrencyExchangeRate, Outlet, Faq, MeasurementUnit, Target, Setting, Media, Role, CashierSession, ProductReview, Enquiry | already covered |
| Stakeholder (the unified read-model over the same `stakeholders` table Customer/Supplier map onto) | **no trait needed** — confirmed via a full-codebase grep that nothing ever calls `save()`/`update()`/`create()` on this model; `StakeholderService` is a pure read model (`all()`/`findOrFail()` only). Adding `LogsActivity` here would be dead weight, never triggered. |

**Customer fix**: added `LogsActivity` to `Customer` (same table as
`Supplier`, but a distinct Eloquent class and a distinct `useLogName()` —
`'customer'` vs `'supplier'` — matching how the admin UI already treats
them as separate screens, and how `Supplier`'s own
`getActivitylogOptions()` was already written). New test
(`tests/Feature/Customer/CustomerActivityLoggingTest.php`) creates and
updates a `Customer` via `CustomerService::linkAccount()` (a real,
existing write path — guest-checkout accounts get linked this way when a
customer later registers) and asserts the activity log entry is actually
written with the right description, not just that the trait is present
on the class.

## Verification summary

- Full backend suite: 143/143 (was 141 before this pass's 2 new tests).
- `Pint --test`: clean on every file this pass touched.
- `finance:reconcile` / `inventory:reconcile`: both clean against the
  local dev database as of this pass.
