<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Phase C, completing Step 4 properly — Customer and Supplier are
     * about to be repointed onto this same `stakeholders` table (one
     * physical row per real-world entity, matching the reference doc's
     * philosophy that role is a fact about how a stakeholder is *used*,
     * not a separate record). That introduces one real correctness risk
     * that has to be closed before the repoint, not after: with no
     * discriminator, `CustomerService::paginateAdmin()`'s admin
     * "Customers" list would return every stakeholder ever created,
     * including ones that only ever existed as a Supplier, and vice
     * versa for the Suppliers list.
     *
     * Two plain booleans, not a growing enum — "is this row used as a
     * customer" and "is this row used as a supplier" are exactly two
     * fixed roles, not the kind of open-ended category the Extensibility
     * Constitution's Rule 3 is about. Both can be true on the same row —
     * that's the whole point of unifying the table.
     */
    public function up(): void
    {
        Schema::table('stakeholders', function (Blueprint $table) {
            $table->boolean('is_customer_role')->default(false)->after('is_active');
            $table->boolean('is_supplier_role')->default(false)->after('is_customer_role');
        });

        DB::table('stakeholders')->whereIn('id', function ($query) {
            $query->select('stakeholder_id')->from('stakeholder_migration_map')->where('source_type', 'customer');
        })->update(['is_customer_role' => true]);

        DB::table('stakeholders')->whereIn('id', function ($query) {
            $query->select('stakeholder_id')->from('stakeholder_migration_map')->where('source_type', 'supplier');
        })->update(['is_supplier_role' => true]);
    }

    public function down(): void
    {
        Schema::table('stakeholders', function (Blueprint $table) {
            $table->dropColumn(['is_customer_role', 'is_supplier_role']);
        });
    }
};
