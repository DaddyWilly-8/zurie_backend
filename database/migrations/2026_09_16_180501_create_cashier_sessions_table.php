<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('cashier_sessions', function (Blueprint $table) {
            $table->id();
            // Cross-module reference, no FK — a transactional log entry
            // tied to a point in time, same convention as orders.outlet_id,
            // not companion/reference data like sales_outlets.cost_center_id.
            $table->unsignedBigInteger('outlet_id');
            $table->foreignId('opened_by')->constrained('users')->restrictOnDelete();
            $table->decimal('opening_balance', 14, 2);
            $table->foreignId('closed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->decimal('closing_balance', 14, 2)->nullable();
            // opening_balance + cash POS sales recorded during the session
            // window — computed at close time, not stored redundantly
            // elsewhere. variance = closing_balance - expected, flags
            // over/under at reconciliation.
            $table->decimal('expected_closing_balance', 14, 2)->nullable();
            $table->decimal('variance', 14, 2)->nullable();
            $table->string('status')->default('open');
            $table->timestamp('opened_at');
            $table->timestamp('closed_at')->nullable();
            $table->timestamps();

            $table->index(['outlet_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('cashier_sessions');
    }
};
