<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('inventory', function (Blueprint $table) {
            $table->id();

            // Cross-module reference into Product — no FK constraint, indexed. Inventory is its own module.
            $table->unsignedBigInteger('product_id')->unique(); // 1:1

            $table->unsignedInteger('quantity')->default(0);
            $table->enum('stock_status', ['IN_STOCK', 'LOW_STOCK', 'OUT_OF_STOCK'])->default('OUT_OF_STOCK');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('inventory');
    }
};
