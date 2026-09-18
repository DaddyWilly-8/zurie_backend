<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('measurement_units', function (Blueprint $table) {
            $table->id();
            $table->string('name')->unique();
            $table->string('symbol', 10)->unique();
            $table->string('description')->nullable();
            // "Delete" deactivates rather than removing the row — a hard
            // delete would orphan every historical product/order/purchase
            // line that references this unit by id. Same convention as
            // CostCenter/Outlet/Supplier.
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('measurement_units');
    }
};
