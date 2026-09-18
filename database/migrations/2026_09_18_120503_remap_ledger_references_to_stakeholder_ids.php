<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Phase C, Step 6 — a second latent bug surfaced by the repoint, found
     * by inspecting existing ledger rows rather than assumed away:
     * `ledgers.reference_id` for every Supplier-owned ledger stores the
     * *old* `suppliers.id` (set by `FinanceService::findOrCreateLedgerFor()`
     * at the time each ledger was created, before the repoint). Now that
     * `Supplier::findOrFail()` resolves against `stakeholders`,
     * `FinanceService::ledgerFor($supplier)` looks up
     * `reference_id = $supplier->id` using the NEW stakeholder id — which
     * doesn't match the old id stored on any ledger created before this
     * migration, silently breaking ledger lookup for every pre-existing
     * supplier. Verified directly: ledger id 10 (Willbard Beatus Mloka)
     * had reference_id=1 (old supplier id) while its supplier's new
     * stakeholder id is 7; ledger id 12 (Happiness) had reference_id=3
     * vs. new id 8.
     *
     * Fixed by translating every Supplier/Customer-referencing ledger's
     * reference_id through `stakeholder_migration_map`, the same
     * permanent translation table Step 3 used for orders/purchases.
     */
    public function up(): void
    {
        foreach (['App\\Modules\\Supplier\\Models\\Supplier' => 'supplier', 'App\\Modules\\Customer\\Models\\Customer' => 'customer'] as $referenceType => $sourceType) {
            $mappings = DB::table('stakeholder_migration_map')->where('source_type', $sourceType)->get();

            foreach ($mappings as $mapping) {
                DB::table('ledgers')
                    ->where('reference_type', $referenceType)
                    ->where('reference_id', $mapping->source_id)
                    ->update(['reference_id' => $mapping->stakeholder_id]);
            }
        }
    }

    public function down(): void
    {
        foreach (['App\\Modules\\Supplier\\Models\\Supplier' => 'supplier', 'App\\Modules\\Customer\\Models\\Customer' => 'customer'] as $referenceType => $sourceType) {
            $mappings = DB::table('stakeholder_migration_map')->where('source_type', $sourceType)->get();

            foreach ($mappings as $mapping) {
                DB::table('ledgers')
                    ->where('reference_type', $referenceType)
                    ->where('reference_id', $mapping->stakeholder_id)
                    ->update(['reference_id' => $mapping->source_id]);
            }
        }
    }
};
