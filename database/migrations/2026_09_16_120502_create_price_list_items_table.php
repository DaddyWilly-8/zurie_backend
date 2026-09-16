<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('price_list_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('price_list_id')->constrained('price_lists')->cascadeOnDelete();
            // Cross-module reference into Product — no FK, same convention
            // as inventory.product_id / inventory_movements.product_id.
            $table->unsignedBigInteger('product_id');
            $table->decimal('price', 12, 2);
            $table->decimal('sale_price', 12, 2)->nullable();
            $table->timestamps();

            $table->unique(['price_list_id', 'product_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('price_list_items');
    }
};
