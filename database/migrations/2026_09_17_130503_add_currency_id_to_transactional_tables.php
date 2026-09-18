<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Nullable, not backfilled — existing Order/Purchase/JournalEntry
        // rows predate multi-currency and were always implicitly TZS;
        // nothing reads these columns yet, so a null historical value is
        // harmless. Going forward, OrderService/PurchaseService/
        // FinanceService resolve a null currencyId to the base currency
        // (CurrencyService::base()) rather than the DB enforcing a default,
        // matching this codebase's existing convention (migrations create
        // schema, they don't backfill data — see measurement_unit_id's
        // migration for the same reasoning).
        Schema::table('orders', function (Blueprint $table) {
            $table->foreignId('currency_id')->nullable()->after('coupon_id')->constrained()->nullOnDelete();
            $table->double('exchange_rate')->default(1)->after('currency_id');
        });

        Schema::table('purchases', function (Blueprint $table) {
            $table->foreignId('currency_id')->nullable()->after('notes')->constrained()->nullOnDelete();
            $table->double('exchange_rate')->default(1)->after('currency_id');
        });

        Schema::table('journal_entries', function (Blueprint $table) {
            $table->foreignId('currency_id')->nullable()->after('created_by')->constrained()->nullOnDelete();
            $table->double('exchange_rate')->default(1)->after('currency_id');
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->dropConstrainedForeignId('currency_id');
            $table->dropColumn('exchange_rate');
        });

        Schema::table('purchases', function (Blueprint $table) {
            $table->dropConstrainedForeignId('currency_id');
            $table->dropColumn('exchange_rate');
        });

        Schema::table('journal_entries', function (Blueprint $table) {
            $table->dropConstrainedForeignId('currency_id');
            $table->dropColumn('exchange_rate');
        });
    }
};
