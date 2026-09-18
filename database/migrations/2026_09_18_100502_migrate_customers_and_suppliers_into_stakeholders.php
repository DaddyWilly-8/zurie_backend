<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Phase C, Step 2 of the Stakeholder merge — copies every existing
     * Customer and Supplier row into `stakeholders`, recording the
     * old-id -> new-stakeholder-id mapping (kept per source type, since a
     * Customer id and a Supplier id are different entities that can share
     * the same number) in a temporary table for Step 3 to consume when
     * backfilling orders.stakeholder_id/purchases.stakeholder_id.
     *
     * Deliberately additive only — customers/suppliers rows are copied,
     * never deleted or altered, and nothing yet reads stakeholders. Fully
     * reversible by dropping the two new rows this creates; down() does
     * exactly that rather than trying to reconstruct original customer/
     * supplier state (which never changed).
     */
    public function up(): void
    {
        Schema::create('stakeholder_migration_map', function (Blueprint $table) {
            $table->id();
            $table->string('source_type'); // 'customer' | 'supplier'
            $table->unsignedBigInteger('source_id');
            $table->foreignId('stakeholder_id')->constrained()->cascadeOnDelete();

            $table->unique(['source_type', 'source_id']);
        });

        DB::transaction(function (): void {
            $customers = DB::table('customers')->get();
            foreach ($customers as $customer) {
                $stakeholderId = DB::table('stakeholders')->insertGetId([
                    'user_id' => $customer->user_id,
                    'name' => $customer->name,
                    'phone' => $customer->phone,
                    'email' => $customer->email,
                    'whatsapp_number' => $customer->whatsapp_number,
                    'is_active' => true,
                    'created_at' => $customer->created_at,
                    'updated_at' => $customer->updated_at,
                ]);

                DB::table('stakeholder_migration_map')->insert([
                    'source_type' => 'customer',
                    'source_id' => $customer->id,
                    'stakeholder_id' => $stakeholderId,
                ]);
            }

            $suppliers = DB::table('suppliers')->get();
            foreach ($suppliers as $supplier) {
                $stakeholderId = DB::table('stakeholders')->insertGetId([
                    'name' => $supplier->name,
                    'phone' => $supplier->phone,
                    'email' => $supplier->email,
                    'address' => $supplier->address,
                    'is_active' => $supplier->is_active,
                    'created_at' => $supplier->created_at,
                    'updated_at' => $supplier->updated_at,
                ]);

                DB::table('stakeholder_migration_map')->insert([
                    'source_type' => 'supplier',
                    'source_id' => $supplier->id,
                    'stakeholder_id' => $stakeholderId,
                ]);
            }
        });
    }

    public function down(): void
    {
        $stakeholderIds = DB::table('stakeholder_migration_map')->pluck('stakeholder_id');
        DB::table('stakeholders')->whereIn('id', $stakeholderIds)->delete();
        Schema::dropIfExists('stakeholder_migration_map');
    }
};
