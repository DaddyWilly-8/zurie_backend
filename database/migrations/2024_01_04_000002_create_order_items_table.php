<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('order_items', function (Blueprint $table) {
            $table->id();

            // Same-module FK — order_items belongs to Order module along with orders.
            $table->foreignId('order_id')->constrained('orders')->cascadeOnDelete();

            // Cross-module reference into Product — no FK constraint, indexed.
            $table->unsignedBigInteger('product_id');

            // Snapshot at order time.
            $table->string('product_name');
            $table->decimal('unit_buying_price', 12, 2);
            $table->decimal('unit_selling_price', 12, 2);

            $table->unsignedInteger('quantity');
            $table->decimal('line_total', 12, 2);

            $table->timestamps();

            $table->index('product_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('order_items');
    }
};
