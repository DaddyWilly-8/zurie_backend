<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('products', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('slug')->unique();
            $table->text('description')->nullable();
            $table->string('short_description')->nullable();

            // Same-module FK — categories is owned by Product module too.
            $table->foreignId('category_id')->constrained('categories');

            $table->string('sku')->nullable()->unique();

            $table->decimal('buying_price', 12, 2); // internal cost, admin-only in responses
            $table->decimal('price', 12, 2); // required, >= 0
            $table->decimal('sale_price', 12, 2)->nullable(); // >= 0 and <= price when present

            $table->string('material')->nullable();

            $table->enum('status', ['draft', 'published', 'archived'])->default('draft');

            $table->boolean('featured')->default(false);
            $table->boolean('best_seller')->default(false);
            $table->boolean('new_arrival')->default(false);

            $table->string('seo_title')->nullable();
            $table->text('seo_description')->nullable();

            $table->timestamps();

            $table->index('status');
            $table->index('category_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('products');
    }
};
