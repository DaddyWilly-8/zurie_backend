<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('suppliers', function (Blueprint $table) {
            // "Delete" a supplier deactivates it rather than removing the
            // row — a hard delete would orphan every historical Purchase
            // and the supplier's own payable ledger (Ledger.reference_id),
            // both of which reference this row by id. Same is_active
            // convention already used by CostCenter/SalesOutlet.
            $table->boolean('is_active')->default(true)->after('address');
        });
    }

    public function down(): void
    {
        Schema::table('suppliers', function (Blueprint $table) {
            $table->dropColumn('is_active');
        });
    }
};
