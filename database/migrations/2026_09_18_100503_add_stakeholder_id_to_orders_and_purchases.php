<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Phase C, Step 3 of the Stakeholder merge — adds stakeholder_id
     * *alongside* the existing customer_id/supplier_id columns (neither
     * is touched or dropped here) and backfills every existing row via
     * stakeholder_migration_map. OrderService/PurchaseService don't read
     * this column yet — that cutover is Step 4, a separate deploy, so a
     * problem found here doesn't require any application code change to
     * roll back.
     */
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->foreignId('stakeholder_id')->nullable()->after('customer_id')->constrained()->nullOnDelete();
        });

        Schema::table('purchases', function (Blueprint $table) {
            $table->foreignId('stakeholder_id')->nullable()->after('supplier_id')->constrained()->nullOnDelete();
        });

        $customerMap = DB::table('stakeholder_migration_map')
            ->where('source_type', 'customer')
            ->pluck('stakeholder_id', 'source_id');

        foreach ($customerMap as $customerId => $stakeholderId) {
            DB::table('orders')
                ->where('customer_id', $customerId)
                ->update(['stakeholder_id' => $stakeholderId]);
        }

        $supplierMap = DB::table('stakeholder_migration_map')
            ->where('source_type', 'supplier')
            ->pluck('stakeholder_id', 'source_id');

        foreach ($supplierMap as $supplierId => $stakeholderId) {
            DB::table('purchases')
                ->where('supplier_id', $supplierId)
                ->update(['stakeholder_id' => $stakeholderId]);
        }
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->dropConstrainedForeignId('stakeholder_id');
        });

        Schema::table('purchases', function (Blueprint $table) {
            $table->dropConstrainedForeignId('stakeholder_id');
        });
    }
};
