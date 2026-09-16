<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('wishlist_items', function (Blueprint $table) {
            $table->id();
            // Real FK — personal/reference data belonging to one customer,
            // not a transactional snapshot like Order->Customer.
            $table->foreignId('customer_id')->constrained('customers')->cascadeOnDelete();
            // Cross-module reference into Product — no FK, same convention
            // as inventory.product_id.
            $table->unsignedBigInteger('product_id');
            $table->timestamps();

            $table->unique(['customer_id', 'product_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('wishlist_items');
    }
};
