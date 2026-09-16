<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('purchases', function (Blueprint $table) {
            $table->id();
            // Nullable — PurchaseService::create() creates the row first
            // (purchase_number depends on the row's own auto-increment id,
            // which doesn't exist until the INSERT happens), then a
            // follow-up update() sets it. Same two-step pattern as
            // orders.order_number.
            $table->string('purchase_number')->nullable()->unique();
            // Real FK, unlike Order->Customer's deliberate no-FK snapshot —
            // a Purchase is a record about an ongoing business relationship
            // with a Supplier, not a point-in-time customer snapshot, same
            // reasoning as SalesOutlet->CostCenter. restrictOnDelete: a
            // supplier with purchase history can't be deleted out from
            // under it.
            $table->foreignId('supplier_id')->constrained('suppliers')->restrictOnDelete();
            $table->decimal('total_amount', 14, 2)->default(0);
            // How much of total_amount was paid at receipt — 0 means fully
            // on credit (the ABC Bags Ltd example in the design doc), equal
            // to total_amount means fully paid, anything between is a
            // split payment. Drives PurchaseService's journal posting.
            $table->decimal('amount_paid', 14, 2)->default(0);
            $table->text('notes')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('purchases');
    }
};
