<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Follow-up to 2026_09_18_120503_remap_ledger_references_to_stakeholder_ids
     * — that migration correctly updated `ledgers.reference_id` from the
     * old supplier/customer id to the new stakeholder id, but left `code`
     * untouched. `FinanceService::generateCode()` builds a ledger's code
     * as `{groupCode}-{ownerId}` at creation time only, so a ledger
     * created before the repoint still carries a code string baked from
     * the OLD id (e.g. "CRED-1" for old supplier id 1, now stakeholder id
     * 7) — stale, and worse, a real collision risk: if the stakeholder
     * that now happens to hold the old id (1) is ever given its own
     * ledger, `generateCode()` computes that exact same "CRED-1" string
     * for a *different* real-world entity, and the unique constraint on
     * `ledgers.code` rejects it. Confirmed live via
     * `PurchaseOrderService::ensureSupplierLedger()` failing on exactly
     * this collision during Phase D testing. Fixed by regenerating every
     * remapped ledger's code from its now-correct reference_id.
     */
    public function up(): void
    {
        $ledgers = DB::table('ledgers')
            ->whereIn('reference_type', ['App\\Modules\\Supplier\\Models\\Supplier', 'App\\Modules\\Customer\\Models\\Customer'])
            ->whereNotNull('reference_id')
            ->get();

        foreach ($ledgers as $ledger) {
            $groupCode = DB::table('ledger_groups')->where('id', $ledger->ledger_group_id)->value('code');
            if ($groupCode === null) {
                continue;
            }

            DB::table('ledgers')->where('id', $ledger->id)->update([
                'code' => sprintf('%s-%d', $groupCode, $ledger->reference_id),
            ]);
        }
    }

    public function down(): void
    {
        // Not reversible — the original stale codes weren't recorded
        // anywhere before this ran. Harmless: down() is a no-op rather
        // than reintroducing the collision bug this migration exists to
        // fix.
    }
};
