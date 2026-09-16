<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ledgers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('ledger_group_id')->constrained('ledger_groups')->restrictOnDelete();
            $table->string('name');
            $table->string('code')->unique();
            $table->decimal('opening_balance', 14, 2)->default(0);
            // Cached running balance — kept in sync by FinanceService as
            // journal entries post, so reads never have to sum every
            // journal_entry_line for a ledger just to show its balance.
            $table->decimal('current_balance', 14, 2)->default(0);
            // Links a ledger to the record that owns it — e.g. a Supplier's
            // auto-created payable ledger, or an Expense category's ledger.
            // Nullable: system ledgers (Cash, Sales Account, COGS) have none.
            $table->string('reference_type')->nullable();
            $table->unsignedBigInteger('reference_id')->nullable();
            $table->boolean('is_system')->default(false);
            $table->timestamps();

            $table->index(['reference_type', 'reference_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ledgers');
    }
};
