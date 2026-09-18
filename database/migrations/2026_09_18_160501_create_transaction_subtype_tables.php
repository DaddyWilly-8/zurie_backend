<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Phase G (Transaction subtypes, see
     * Zurie_V3_ProsERP_Adaptation_Plan.md) — four documents sharing one
     * shape (transaction_date/reference/narration + items[]), each item
     * producing its own JournalEntry via FinanceService::postSimpleEntry()
     * (or postEntry() for Journal Voucher's own per-line pair). Every
     * *_items table carries a `journal_entry_id` FK (restrict-on-delete —
     * deleting the header/item must go through TransactionService, which
     * reverses the entry first via FinanceService::deleteEntry(); the DB
     * itself refuses a delete that would silently orphan a journal entry).
     */
    public function up(): void
    {
        Schema::create('payments', function (Blueprint $table) {
            $table->id();
            $table->string('payment_number')->nullable()->unique();
            $table->date('transaction_date');
            $table->string('reference')->nullable();
            $table->text('narration')->nullable();
            $table->foreignId('credit_ledger_id')->constrained('ledgers')->restrictOnDelete();
            $table->double('total_amount')->default(0);
            $table->timestamps();
        });

        Schema::create('payment_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('payment_id')->constrained()->cascadeOnDelete();
            $table->foreignId('debit_ledger_id')->constrained('ledgers')->restrictOnDelete();
            $table->double('amount');
            $table->foreignId('journal_entry_id')->constrained()->restrictOnDelete();
            $table->timestamps();
        });

        Schema::create('receipts', function (Blueprint $table) {
            $table->id();
            $table->string('receipt_number')->nullable()->unique();
            $table->date('transaction_date');
            $table->string('reference')->nullable();
            $table->text('narration')->nullable();
            $table->foreignId('debit_ledger_id')->constrained('ledgers')->restrictOnDelete();
            $table->double('total_amount')->default(0);
            $table->timestamps();
        });

        Schema::create('receipt_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('receipt_id')->constrained()->cascadeOnDelete();
            $table->foreignId('credit_ledger_id')->constrained('ledgers')->restrictOnDelete();
            $table->double('amount');
            $table->foreignId('journal_entry_id')->constrained()->restrictOnDelete();
            $table->timestamps();
        });

        // N-N — a receipt can settle one or more Orders' AR balance.
        // order_id has no FK, matching Order's own cross-module-reference
        // convention elsewhere (e.g. Order.customer_id) rather than
        // reaching into Order's table with a real constraint.
        Schema::create('receipt_order', function (Blueprint $table) {
            $table->id();
            $table->foreignId('receipt_id')->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('order_id');
            $table->double('amount_applied');
            $table->timestamps();

            $table->index('order_id');
        });

        Schema::create('journal_vouchers', function (Blueprint $table) {
            $table->id();
            $table->string('voucher_number')->nullable()->unique();
            $table->date('transaction_date');
            $table->string('reference')->nullable();
            $table->text('narration')->nullable();
            $table->double('total_amount')->default(0);
            $table->timestamps();
        });

        Schema::create('journal_voucher_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('journal_voucher_id')->constrained()->cascadeOnDelete();
            $table->foreignId('debit_ledger_id')->constrained('ledgers')->restrictOnDelete();
            $table->foreignId('credit_ledger_id')->constrained('ledgers')->restrictOnDelete();
            $table->double('amount');
            $table->foreignId('journal_entry_id')->constrained()->restrictOnDelete();
            $table->timestamps();
        });

        Schema::create('fund_transfers', function (Blueprint $table) {
            $table->id();
            $table->string('transfer_number')->nullable()->unique();
            $table->date('transaction_date');
            $table->string('reference')->nullable();
            $table->text('narration')->nullable();
            $table->foreignId('credit_ledger_id')->constrained('ledgers')->restrictOnDelete();
            $table->double('total_amount')->default(0);
            $table->timestamps();
        });

        Schema::create('fund_transfer_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('fund_transfer_id')->constrained()->cascadeOnDelete();
            $table->foreignId('debit_ledger_id')->constrained('ledgers')->restrictOnDelete();
            $table->double('amount');
            $table->foreignId('journal_entry_id')->constrained()->restrictOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('fund_transfer_items');
        Schema::dropIfExists('fund_transfers');
        Schema::dropIfExists('journal_voucher_items');
        Schema::dropIfExists('journal_vouchers');
        Schema::dropIfExists('receipt_order');
        Schema::dropIfExists('receipt_items');
        Schema::dropIfExists('receipts');
        Schema::dropIfExists('payment_items');
        Schema::dropIfExists('payments');
    }
};
