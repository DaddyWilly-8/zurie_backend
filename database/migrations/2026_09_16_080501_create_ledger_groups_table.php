<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ledger_groups', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('code')->unique();
            // Self-referencing — lets groups nest (Assets > Current Assets),
            // mirroring standard chart-of-accounts hierarchy (Tally-style
            // Groups/Sub-Groups). Nullable = top-level group.
            $table->foreignId('parent_id')->nullable()->constrained('ledger_groups')->nullOnDelete();
            $table->enum('nature', ['asset', 'liability', 'income', 'expense', 'equity']);
            // Protects the seeded default hierarchy from accidental deletion
            // via the admin UI — a system group can't be removed, only a
            // user-created one can.
            $table->boolean('is_system')->default(false);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ledger_groups');
    }
};
