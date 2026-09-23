<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The customer/staff split — see docs/ARCHITECTURE_GUIDE.md's "Customer
     * vs staff identity" section. A storefront login is now a row in THIS
     * table, authenticated against a dedicated 'customer' Auth guard
     * (config/auth.php) — never the `users` table, which is staff-only
     * from this migration onward. `stakeholder_id` is the forward link to
     * the customer's actual business record (Modules/Customer's `Customer`
     * model, itself a view over `stakeholders` — see Phase C); nullable
     * because a Google sign-up has no phone number yet (CustomerAccount
     * ::register()'s docblock) and `stakeholders.phone` is a real,
     * meaningful field, never a placeholder.
     *
     * Deliberately a NEW table rather than repointing `stakeholders.user_id`
     * — that column still exists, still points at `users`, and is left
     * untouched (orphaned for any pre-split test account, which is fine:
     * this codebase has no real customer data yet, see the "why now not
     * later" discussion this split originated from). Keeping the two
     * tables' relationship one-directional (customer_accounts →
     * stakeholders only) keeps this migration's blast radius to one new
     * table instead of touching `stakeholders`' existing FK.
     */
    public function up(): void
    {
        Schema::create('customer_accounts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('stakeholder_id')->nullable()->unique()->constrained('stakeholders')->nullOnDelete();
            $table->string('email')->unique();
            $table->string('password');
            $table->timestamp('email_verified_at')->nullable();
            $table->rememberToken();
            $table->timestamps();
        });

        // Mirrors the default `password_reset_tokens` table shape exactly
        // — a SEPARATE table (not shared with staff resets) so the same
        // email string existing in both `users` and `customer_accounts`
        // (a staff member who is also a customer) can never collide on
        // one reset token row belonging to the wrong account type.
        Schema::create('customer_password_reset_tokens', function (Blueprint $table) {
            $table->string('email')->primary();
            $table->string('token');
            $table->timestamp('created_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('customer_password_reset_tokens');
        Schema::dropIfExists('customer_accounts');
    }
};
