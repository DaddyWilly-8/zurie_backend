<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Phase D (Purchase Order -> GRN split, see
     * Zurie_V3_ProsERP_Adaptation_Plan.md). `status` is stored (not purely
     * computed on read, as the plan's earlier draft considered) but is
     * always recomputed and re-persisted by PurchaseOrderService whenever
     * anything that affects it changes (a GRN is received, close()/
     * reopen()/cancel() is called) — never trusted as authoritative input
     * from a client request. `stakeholder_id` has no FK constraint,
     * matching Order.customer_id's own established pattern (a
     * snapshot-style reference, nullable to represent the reference doc's
     * "Cash Purchase" case where no stakeholder is involved at all).
     */
    public function up(): void
    {
        Schema::create('purchase_orders', function (Blueprint $table) {
            $table->id();
            $table->string('po_number')->nullable()->unique();
            $table->date('order_date');
            $table->dateTime('date_required');
            $table->unsignedBigInteger('stakeholder_id')->nullable();
            $table->foreignId('currency_id')->constrained()->restrictOnDelete();
            $table->double('exchange_rate')->default(1);
            $table->string('status')->default('pending');
            $table->double('total_amount')->default(0);
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->index('stakeholder_id');
            $table->index('status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('purchase_orders');
    }
};
