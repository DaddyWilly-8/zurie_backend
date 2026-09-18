<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Phase F (Proforma Invoices, see Zurie_V3_ProsERP_Adaptation_Plan.md).
     * `is_active` is this codebase's established deactivate-instead-of-
     * delete pattern (Extensibility Constitution rule 4), doing double
     * duty here as the doc's "an admin withdraws it" action — a withdrawn
     * proforma is exactly a deactivated one. No `status` enum is added on
     * top of that; a quote is either live or withdrawn, matching what the
     * doc actually needs, not a growing category rule 3 would apply to.
     */
    public function up(): void
    {
        Schema::create('proforma_invoices', function (Blueprint $table) {
            $table->id();
            $table->string('proforma_number')->nullable()->unique();
            $table->date('proforma_date');
            $table->date('expiry_date')->nullable();
            $table->foreignId('sales_outlet_id')->constrained()->restrictOnDelete();
            $table->unsignedBigInteger('stakeholder_id');
            $table->foreignId('currency_id')->constrained()->restrictOnDelete();
            $table->double('exchange_rate')->default(1);
            $table->double('total_amount')->default(0);
            $table->boolean('is_active')->default(true);
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->index('stakeholder_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('proforma_invoices');
    }
};
