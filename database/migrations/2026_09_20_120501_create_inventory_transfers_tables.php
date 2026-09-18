<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Inventory Transfers — the multi-store initiative's second piece
     * (after the Store foundation migration). Three types, from the
     * planning discussion:
     *
     * - "internal": Store A -> Store B, both ours. Moves stock between
     *   two outlets, no ledger effect (same asset, different location).
     *   destination_outlet_id is required for this type.
     * - "external": stock leaving the business entirely (to a
     *   franchisee, disposal, etc.). destination_outlet_id is null —
     *   there's no receiving outlet inside this business — and it DOES
     *   post a ledger entry (debit Inventory Write-off, credit
     *   Inventory Asset), valued at the product's buying price.
     * - "cost_center_change": deliberately scoped down from a full
     *   per-line cost-center reassignment — Zuriè's Inventory has no
     *   per-product cost-center dimension to actually move (cost
     *   centers attach to a whole SalesOutlet, not individual stock),
     *   so this type is a pure audit-trail record (which cost center a
     *   given quantity is now attributed to, for reporting) with zero
     *   stock movement and zero ledger effect — source_outlet_id and
     *   destination_outlet_id are the same store for this type.
     *   `source_cost_center_id`/`destination_cost_center_id` are only
     *   populated for this type.
     */
    public function up(): void
    {
        Schema::create('inventory_transfers', function (Blueprint $table) {
            $table->id();
            $table->string('transfer_number')->nullable()->unique();
            $table->string('type'); // internal | external | cost_center_change — polymorphic-in-spirit string, not an enum, per Extensibility Constitution rule 3
            $table->foreignId('source_outlet_id')->constrained('sales_outlets')->restrictOnDelete();
            $table->foreignId('destination_outlet_id')->nullable()->constrained('sales_outlets')->restrictOnDelete();
            $table->foreignId('source_cost_center_id')->nullable()->constrained('cost_centers')->restrictOnDelete();
            $table->foreignId('destination_cost_center_id')->nullable()->constrained('cost_centers')->restrictOnDelete();
            $table->date('transfer_date');
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->index('type');
        });

        Schema::create('inventory_transfer_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('inventory_transfer_id')->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('product_id'); // no FK — cross-module reference, established convention
            $table->integer('quantity');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('inventory_transfer_items');
        Schema::dropIfExists('inventory_transfers');
    }
};
