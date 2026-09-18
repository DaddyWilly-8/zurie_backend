<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Phase E (VAT/Tax, see Zurie_V3_ProsERP_Adaptation_Plan.md). Nullable/
     * default-zero everywhere so every existing row (and every existing
     * caller that doesn't yet pass VAT data) keeps behaving exactly as
     * before — matching this codebase's "migrations create schema, they
     * don't change behavior" convention, same as Phase B's currency
     * columns.
     */
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->boolean('vat_exempted')->default(false)->after('measurement_unit_id');
        });

        Schema::table('order_items', function (Blueprint $table) {
            $table->double('vat_percentage')->default(0)->after('line_total');
            $table->double('vat_amount')->default(0)->after('vat_percentage');
        });

        Schema::table('orders', function (Blueprint $table) {
            $table->double('vat_amount')->default(0)->after('discount_amount');
        });

        Schema::table('purchase_items', function (Blueprint $table) {
            $table->double('vat_percentage')->default(0)->after('line_total');
            $table->double('vat_amount')->default(0)->after('vat_percentage');
        });

        Schema::table('purchases', function (Blueprint $table) {
            $table->double('vat_amount')->default(0)->after('total_amount');
        });

        Schema::table('purchase_orders', function (Blueprint $table) {
            $table->double('vat_amount')->default(0)->after('total_amount');
        });
    }

    public function down(): void
    {
        Schema::table('products', fn (Blueprint $table) => $table->dropColumn('vat_exempted'));
        Schema::table('order_items', fn (Blueprint $table) => $table->dropColumn(['vat_percentage', 'vat_amount']));
        Schema::table('orders', fn (Blueprint $table) => $table->dropColumn('vat_amount'));
        Schema::table('purchase_items', fn (Blueprint $table) => $table->dropColumn(['vat_percentage', 'vat_amount']));
        Schema::table('purchases', fn (Blueprint $table) => $table->dropColumn('vat_amount'));
        Schema::table('purchase_orders', fn (Blueprint $table) => $table->dropColumn('vat_amount'));
    }
};
