<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            // Needed so cancel()'s ledger reversal can reconstruct the
            // original gross revenue (total_amount + discount_amount) and
            // the exact Sales Discounts amount to reverse — total_amount
            // alone (net of discount) isn't enough once a coupon is
            // involved. Default 0, not nullable: every order has a
            // discount, even if it's zero.
            $table->decimal('discount_amount', 12, 2)->default(0)->after('total_amount');
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->dropColumn('discount_amount');
        });
    }
};
