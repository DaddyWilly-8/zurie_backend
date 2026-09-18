<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Phase C, Step 6 — the critical part. `Customer`/`Supplier` were
     * repointed to read/write `stakeholders` directly (see their models'
     * docblocks), but `wishlist_items.customer_id`, `product_reviews.
     * customer_id`, `price_lists.customer_id`, and `purchases.supplier_id`
     * still carried live FK constraints against the old `customers`/
     * `suppliers` tables. Since those tables receive zero new writes now,
     * any stakeholder created after the repoint has no matching row there
     * — inserting a wishlist item, review, price list, or purchase for a
     * newly-created customer/supplier would 500 on the FK constraint.
     * Confirmed live via a real insert against a freshly-created
     * stakeholder before writing this migration. This repoints each FK at
     * `stakeholders` instead, preserving each column's original
     * on-delete behavior.
     */
    public function up(): void
    {
        Schema::table('wishlist_items', function (Blueprint $table) {
            $table->dropForeign(['customer_id']);
            $table->foreign('customer_id')->references('id')->on('stakeholders')->cascadeOnDelete();
        });

        Schema::table('product_reviews', function (Blueprint $table) {
            $table->dropForeign(['customer_id']);
            $table->foreign('customer_id')->references('id')->on('stakeholders')->cascadeOnDelete();
        });

        Schema::table('price_lists', function (Blueprint $table) {
            $table->dropForeign(['customer_id']);
            $table->foreign('customer_id')->references('id')->on('stakeholders')->nullOnDelete();
        });

        Schema::table('purchases', function (Blueprint $table) {
            $table->dropForeign(['supplier_id']);
            $table->foreign('supplier_id')->references('id')->on('stakeholders')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('wishlist_items', function (Blueprint $table) {
            $table->dropForeign(['customer_id']);
            $table->foreign('customer_id')->references('id')->on('customers')->cascadeOnDelete();
        });

        Schema::table('product_reviews', function (Blueprint $table) {
            $table->dropForeign(['customer_id']);
            $table->foreign('customer_id')->references('id')->on('customers')->cascadeOnDelete();
        });

        Schema::table('price_lists', function (Blueprint $table) {
            $table->dropForeign(['customer_id']);
            $table->foreign('customer_id')->references('id')->on('customers')->nullOnDelete();
        });

        Schema::table('purchases', function (Blueprint $table) {
            $table->dropForeign(['supplier_id']);
            $table->foreign('supplier_id')->references('id')->on('suppliers')->restrictOnDelete();
        });
    }
};
