<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Phase C, Step 6 (final) — `customers` and `suppliers` are now fully
     * orphaned: `Customer`/`Supplier` read/write `stakeholders` directly,
     * every FK that pointed at these two tables was repointed at
     * `stakeholders` in an earlier migration in this same batch, and
     * every `ledgers.reference_id` referencing a supplier/customer was
     * translated to the new stakeholder id. Nothing reads these two
     * tables any more, so keeping them around would only risk someone
     * mistakenly treating them as a live data source later. down()
     * restores them from `stakeholders` + `stakeholder_migration_map`
     * (which is deliberately left in place, not dropped, as the
     * permanent record of the old-id -> new-id translation) so this is
     * still a fully reversible step, not a destructive dead end.
     */
    public function up(): void
    {
        Schema::dropIfExists('customers');
        Schema::dropIfExists('suppliers');
    }

    public function down(): void
    {
        Schema::create('customers', function ($table) {
            $table->id();
            $table->unsignedBigInteger('user_id')->nullable();
            $table->string('name');
            $table->string('phone')->unique();
            $table->string('whatsapp_number')->nullable();
            $table->string('email')->nullable();
            $table->timestamps();
        });

        Schema::create('suppliers', function ($table) {
            $table->id();
            $table->string('name');
            $table->string('phone')->nullable();
            $table->string('email')->nullable();
            $table->string('address')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        $mappings = DB::table('stakeholder_migration_map')->get();

        foreach ($mappings as $mapping) {
            $stakeholder = DB::table('stakeholders')->find($mapping->stakeholder_id);
            if ($stakeholder === null) {
                continue;
            }

            if ($mapping->source_type === 'customer') {
                DB::table('customers')->insert([
                    'id' => $mapping->source_id,
                    'user_id' => $stakeholder->user_id,
                    'name' => $stakeholder->name,
                    'phone' => $stakeholder->phone,
                    'whatsapp_number' => $stakeholder->whatsapp_number,
                    'email' => $stakeholder->email,
                    'created_at' => $stakeholder->created_at,
                    'updated_at' => $stakeholder->updated_at,
                ]);
            } else {
                DB::table('suppliers')->insert([
                    'id' => $mapping->source_id,
                    'name' => $stakeholder->name,
                    'phone' => $stakeholder->phone,
                    'email' => $stakeholder->email,
                    'address' => $stakeholder->address,
                    'is_active' => $stakeholder->is_active,
                    'created_at' => $stakeholder->created_at,
                    'updated_at' => $stakeholder->updated_at,
                ]);
            }
        }
    }
};
