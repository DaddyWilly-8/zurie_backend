<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ledgers', function (Blueprint $table) {
            // A contra ledger (e.g. Sales Discounts under Income) carries
            // the OPPOSITE normal balance of its group's nature — a debit
            // to a normal Income ledger decreases it, but a debit to a
            // contra-Income ledger increases it (since it exists to be
            // subtracted from its parent category, per Net Sales = Sales
            // Account - Sales Discounts). This is a property of the
            // individual ledger, not the whole group (Sales Account and
            // Sales Discounts share the "Direct Income" group but have
            // opposite normal balances), so it lives here, not on
            // ledger_groups.nature. Bug found in testing: without this,
            // FinanceService::applyToLedgerBalance() posted a debit to
            // Sales Discounts as a *decrease*, making discounted orders
            // show inflated net sales instead of reduced.
            $table->boolean('is_contra')->default(false)->after('is_system');
        });
    }

    public function down(): void
    {
        Schema::table('ledgers', function (Blueprint $table) {
            $table->dropColumn('is_contra');
        });
    }
};
