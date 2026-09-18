<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Carries the quantity actually received against one PurchaseOrderItem
     * on one GRN — a PurchaseOrderItem can be received across several
     * GRNs (partial deliveries), and (in principle) one GRN could receive
     * lines from more than one PurchaseOrderItem, hence the pivot rather
     * than a single FK column on either side.
     */
    public function up(): void
    {
        Schema::create('grn_purchase_order_item', function (Blueprint $table) {
            $table->id();
            $table->foreignId('grn_id')->constrained()->cascadeOnDelete();
            $table->foreignId('purchase_order_item_id')->constrained()->cascadeOnDelete();
            $table->double('quantity_received');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('grn_purchase_order_item');
    }
};
