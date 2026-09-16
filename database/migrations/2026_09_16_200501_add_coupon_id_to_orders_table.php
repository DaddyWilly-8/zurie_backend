<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            // Cross-module reference, no FK — same convention as
            // outlet_id/customer_id on this same table. Needed so
            // OrderService::cancel() can un-redeem the coupon (decrement
            // Coupon::used_count) that a cancelled order consumed — without
            // this, a cancelled order permanently burns a maxUses-limited
            // coupon's use even though the customer never got the benefit.
            // discount_amount alone isn't enough for that: it tells you how
            // much was discounted, not which coupon (if any) caused it.
            $table->unsignedBigInteger('coupon_id')->nullable()->after('discount_amount');
            $table->index('coupon_id');
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->dropColumn('coupon_id');
        });
    }
};
