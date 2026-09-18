<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Phase E — a byproduct record, always created inline by the parent
     * document's own flow (Order/Purchase/GRN), never through a dedicated
     * store endpoint. `vatable_type/id` is polymorphic per Extensibility
     * Constitution rule 3, matching how InventoryMovement already has no
     * dedicated CRUD either.
     */
    public function up(): void
    {
        Schema::create('vat_transactions', function (Blueprint $table) {
            $table->id();
            $table->string('tin')->nullable();
            $table->string('vrn')->nullable();
            $table->string('organization_name')->nullable();
            $table->string('reference')->nullable();
            $table->enum('type', ['input', 'output']);
            $table->string('vatable_type');
            $table->unsignedBigInteger('vatable_id');
            $table->double('amount');
            $table->timestamps();

            $table->index(['vatable_type', 'vatable_id']);
            $table->index('type');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('vat_transactions');
    }
};
