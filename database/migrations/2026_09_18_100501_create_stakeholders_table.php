<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Phase C, Step 1 of the Stakeholder merge (see
     * Zurie_V3_ProsERP_Adaptation_Plan.md) — creates the new tables only.
     * No data moves yet, no existing code reads/writes these, nothing
     * behaves any differently after this migration than before it. That's
     * deliberate: this cutover is staged across several separately
     * verified migrations/deploys specifically so a problem found partway
     * through can stop without having already broken Customer/Supplier.
     */
    public function up(): void
    {
        Schema::create('stakeholders', function (Blueprint $table) {
            $table->id();

            // Preserves Customer's login-link capability — same nullable
            // FK, same reasoning as customers.user_id's own migration
            // (guest/walk-in has no login; deleting the linked user falls
            // back to guest-like, never deletes history).
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();

            $table->string('name');

            // NOT unique, deliberately deviating from the reference doc's
            // "name (unique)" rule — Supplier has never enforced any
            // uniqueness on name, and forcing it here would risk the data
            // migration failing outright if any two existing Supplier (or
            // Supplier/Customer) rows happen to share a name. Customer's
            // real uniqueness has always been on phone, not name — see
            // customers.phone below.
            $table->string('phone')->nullable();

            // Legal/business entity type (Individual, Sole Proprietor,
            // Private Limited, etc.) — a new concept neither Customer nor
            // Supplier tracked before. Nullable since the merge can't
            // infer this for existing rows; an admin fills it in later if
            // it matters for a given stakeholder.
            $table->string('type')->nullable();

            $table->string('tin', 20)->nullable();
            $table->string('vrn', 20)->nullable();
            $table->string('address')->nullable();
            $table->string('email')->nullable();
            $table->string('website')->nullable();
            $table->string('remarks')->nullable();
            $table->string('whatsapp_number')->nullable();

            // "Delete" deactivates rather than removing the row — same
            // convention as every other reference-data module this
            // session; a hard delete would orphan every historical
            // Order/Purchase/Ledger that references this stakeholder.
            $table->boolean('is_active')->default(true);

            $table->timestamps();

            $table->index('phone');
        });

        // A stakeholder's receivable and/or payable ledger — N–N because
        // a single stakeholder acting as both a customer (receivable) and
        // a supplier (payable) holds two ledger links on the same row,
        // not two separate stakeholder records.
        Schema::create('ledger_stakeholder', function (Blueprint $table) {
            $table->foreignId('ledger_id')->constrained()->cascadeOnDelete();
            $table->foreignId('stakeholder_id')->constrained()->cascadeOnDelete();

            $table->primary(['ledger_id', 'stakeholder_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ledger_stakeholder');
        Schema::dropIfExists('stakeholders');
    }
};
