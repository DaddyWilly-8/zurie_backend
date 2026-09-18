<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table) {
            // Nullable, not required — this column is being added to a
            // table that already has real product rows with no unit
            // assigned. New products should set one going forward (the
            // frontend form gates on it), but existing rows aren't forced
            // through a backfill migration guessing at units for them.
            $table->foreignId('measurement_unit_id')
                ->nullable()
                ->after('sku')
                ->constrained('measurement_units')
                ->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->dropConstrainedForeignId('measurement_unit_id');
        });
    }
};
