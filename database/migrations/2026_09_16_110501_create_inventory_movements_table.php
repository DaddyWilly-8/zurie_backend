<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('inventory_movements', function (Blueprint $table) {
            $table->id();
            // Cross-module reference into Product — no FK, same convention
            // as inventory.product_id.
            $table->unsignedBigInteger('product_id');
            // A string, not an enum — a future movement type (e.g. "transfer"
            // once multi-warehouse exists) needs no migration to add. See
            // Extensibility Constitution, Rule 3.
            $table->string('type');
            // Signed delta, matching the design doc's own worked example
            // (Purchase +20, POS sale -2, Damaged -1) — never store an
            // absolute quantity here, only the change.
            $table->integer('quantity');
            $table->string('reason')->nullable();
            // Polymorphic — Order, Purchase, or null for a manual admin
            // adjustment via InventoryService::update().
            $table->string('reference_type')->nullable();
            $table->unsignedBigInteger('reference_id')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('created_at')->useCurrent();

            $table->index('product_id');
            $table->index(['reference_type', 'reference_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('inventory_movements');
    }
};
