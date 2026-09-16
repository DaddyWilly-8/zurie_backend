<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('expenses', function (Blueprint $table) {
            $table->id();
            // Free-text category (e.g. "Rent", "Salaries") — the ledger
            // under Indirect Expenses this posts to is created on demand
            // per unique category name, same on-demand pattern as
            // Supplier's payable ledger. A string, not an enum: a new
            // category needs no migration. See Extensibility Constitution,
            // Rule 3.
            $table->string('category');
            $table->decimal('amount', 14, 2);
            $table->string('payment_method')->default('cash');
            $table->string('description')->nullable();
            $table->foreignId('cost_center_id')->nullable()->constrained('cost_centers')->nullOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('expenses');
    }
};
