<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Lets a product be sold in a different unit than it's stocked
        // in — e.g. stock tracked in "each" but sold in "box of 12"
        // (conversion_factor = 12). Not used by anything yet (no
        // module reads this table this phase); the table exists so
        // Product's own unit assignment isn't blocked on the secondary-
        // unit UI being built too.
        Schema::create('product_secondary_units', function (Blueprint $table) {
            $table->id();
            $table->foreignId('product_id')->constrained()->cascadeOnDelete();
            $table->foreignId('measurement_unit_id')->constrained()->restrictOnDelete();
            $table->double('conversion_factor');
            $table->timestamps();

            $table->unique(['product_id', 'measurement_unit_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('product_secondary_units');
    }
};
