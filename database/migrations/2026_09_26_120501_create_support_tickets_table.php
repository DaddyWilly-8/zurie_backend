<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('support_tickets', function (Blueprint $table) {
            $table->id();
            // Always a storefront login — the opener of a ticket is never
            // staff, so this is a plain FK, not the polymorphic shape a
            // single unified users table would need (see docs/support-
            // module-implementation-prompt.md's reference design, adapted:
            // Zurie's two-guard split makes opener/handler unambiguous).
            $table->foreignId('customer_account_id')->constrained('customer_accounts')->cascadeOnDelete();
            $table->string('subject');
            $table->string('organization_name')->nullable();
            $table->text('notes')->nullable();
            // A string, not a DB enum — a future status needs no migration
            // (Extensibility Constitution, Rule 3). Application-level
            // validity is enforced in SupportTicketService, not here.
            $table->string('status')->default('new');
            $table->foreignId('attended_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('closed_at')->nullable();
            $table->timestamps();

            $table->index('status');
            $table->index('attended_by_user_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('support_tickets');
    }
};
