<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Delivery — the outbound tracking record the Order detail view's
     * Delivery tab needs, from the "instant sale vs. delivery customer"
     * brainstorm (see the planning discussion). Deliberately NOT a
     * GRN mirror in the sense of moving inventory: Zuriè's Order already
     * decrements stock at checkout/sale time, not at hand-over, so a
     * Delivery here has zero inventory or ledger effect — it's purely a
     * dispatch record ("this batch of order lines physically left on this
     * date"), letting a "ready for delivery" order be fulfilled in more
     * than one trip and letting an admin see the dispatch history. It's
     * also deliberately decoupled from `orders.status` — creating a
     * Delivery never auto-transitions an order's status; that stays the
     * existing PATCH /admin/orders/{order} flow's job, unchanged.
     *
     * `order_id`/`order_item_id` have no FK — cross-module reference,
     * same established pattern as `receipt_order.order_id` (Transaction
     * module) and `Order.customer_id`.
     */
    public function up(): void
    {
        Schema::create('deliveries', function (Blueprint $table) {
            $table->id();
            $table->string('delivery_number')->nullable()->unique();
            $table->unsignedBigInteger('order_id');
            $table->date('date_dispatched');
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->index('order_id');
        });

        Schema::create('delivery_order_item', function (Blueprint $table) {
            $table->id();
            $table->foreignId('delivery_id')->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('order_item_id');
            $table->integer('quantity_dispatched');
            $table->timestamps();

            $table->index('order_item_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('delivery_order_item');
        Schema::dropIfExists('deliveries');
    }
};
