<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sales_outlets', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->enum('type', ['physical', 'online']);
            $table->string('address')->nullable();
            // Companion/reference data, not a transactional snapshot (unlike
            // Order->Customer) — a real FK is appropriate here since an
            // outlet's cost center is structural, not a point-in-time fact.
            $table->foreignId('cost_center_id')->nullable()->constrained('cost_centers')->nullOnDelete();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sales_outlets');
    }
};
