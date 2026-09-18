<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('currencies', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('name_plural');
            $table->string('code', 5)->unique();
            $table->string('symbol', 5);
            $table->string('symbol_native', 5);
            $table->unsignedTinyInteger('decimal_digits')->default(2);
            // Exactly one row should ever be true — enforced in
            // CurrencyService, not the DB (a partial unique index on a
            // boolean is backend-specific and not worth the portability
            // cost for a single-admin-managed table).
            $table->boolean('is_base')->default(false);
            $table->foreignId('created_by')->nullable()->constrained('users')->restrictOnDelete();
            // "Delete" deactivates rather than removing the row — a hard
            // delete would orphan every historical Order/Purchase/
            // JournalEntry that references this currency by id.
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('currencies');
    }
};
