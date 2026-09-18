<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Payment -> PurchaseOrder linkage — the "Payments tab on Purchase
     * Order" piece of the "instant pay" brainstorm. Mirrors
     * `receipt_order` exactly (a Payment can settle one or more Purchase
     * Orders' payable balance, same N-N shape Receipt already has for
     * Orders). `purchase_order_id` has no FK — cross-module reference
     * (Transaction module -> Procurement module), same established
     * pattern as `receipt_order.order_id`.
     */
    public function up(): void
    {
        Schema::create('payment_purchase_order', function (Blueprint $table) {
            $table->id();
            $table->foreignId('payment_id')->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('purchase_order_id');
            $table->double('amount_applied');
            $table->timestamps();

            $table->index('purchase_order_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payment_purchase_order');
    }
};
