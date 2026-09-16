<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // `provider` is a string, not a `google_id`/`facebook_id` column on
        // `users` — a future provider (Facebook, LinkedIn) needs a new row
        // here, never a new migration on `users` itself. Same reasoning as
        // inventory_movements.type/journal_entries.reference_type (see
        // Extensibility Constitution, Rule 3).
        Schema::create('oauth_identities', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('provider');
            $table->string('provider_id');
            $table->timestamps();

            $table->unique(['provider', 'provider_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('oauth_identities');
    }
};
