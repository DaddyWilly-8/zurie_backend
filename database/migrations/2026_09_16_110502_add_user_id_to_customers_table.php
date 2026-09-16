<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('customers', function (Blueprint $table) {
            // Nullable = guest/walk-in customer with no login. A real id
            // links this customer record to their account without
            // conflating "can this person log in" (users table) with "is
            // this person a customer with purchase history" (customers
            // table) — see Zurie_V2_Architecture_Design (2).md, "Customer
            // Architecture" revision / §36. Deleting the linked user
            // doesn't delete purchase history — it just falls back to
            // guest-like (user_id null), same as "one source of truth,
            // never delete transaction history" (§28 Principle 1).
            $table->foreignId('user_id')->nullable()->after('id')->constrained('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('customers', function (Blueprint $table) {
            $table->dropConstrainedForeignId('user_id');
        });
    }
};
