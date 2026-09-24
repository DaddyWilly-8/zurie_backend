<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Per-category revenue/cost classification — a sale in a category with
     * these set posts to its own Income/Expense ledger instead of the
     * global SALES/COGS system ledgers (OrderService::resolveCategoryLedger()).
     * Both nullable and independent: a category can override one without
     * the other, falling back to the matching global system ledger for
     * whichever it leaves unset — so existing categories with neither set
     * behave exactly as before this migration. `nullOnDelete()` rather than
     * `restrictOnDelete()` — deleting a ledger a category still points at
     * should silently fall the category back to the global default, not
     * block the ledger's deletion (LedgerService::delete() already refuses
     * to delete a ledger with any posted activity, so this only ever fires
     * for an unused ledger anyway).
     */
    public function up(): void
    {
        Schema::table('categories', function (Blueprint $table) {
            $table->foreignId('income_ledger_id')->nullable()->after('image_url')->constrained('ledgers')->nullOnDelete();
            $table->foreignId('expense_ledger_id')->nullable()->after('income_ledger_id')->constrained('ledgers')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('categories', function (Blueprint $table) {
            $table->dropConstrainedForeignId('income_ledger_id');
            $table->dropConstrainedForeignId('expense_ledger_id');
        });
    }
};
