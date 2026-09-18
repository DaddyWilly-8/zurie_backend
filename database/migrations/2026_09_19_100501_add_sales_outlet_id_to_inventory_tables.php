<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Multi-store foundation — the first step of a user-requested
     * initiative (multiple duka/stores, inventory transfers, per-store
     * reports; see the planning discussion, not yet written into a
     * dedicated plan doc). `Inventory` was a single global row per
     * product with no location dimension at all; this repoints it at
     * `sales_outlets`, which becomes the unified "Store" concept — a
     * duka both sells and holds stock, one row, not two parallel
     * concepts kept in sync (deliberately not copying the reference
     * material's separate Store/Outlet split, since nothing here needs
     * that distinction yet).
     *
     * Backfill: every existing `inventory` row is assigned to the one
     * outlet that exists today (`defaultOnlineOutlet()`, id 1) — a
     * single-outlet business's stock levels are completely unchanged by
     * this migration, just now explicitly scoped to that one store
     * instead of implicitly global. `inventory_movements` gets the same
     * column, backfilled the same way, purely for future per-store
     * movement history — nothing reads it yet.
     */
    public function up(): void
    {
        Schema::table('inventory', function (Blueprint $table) {
            $table->foreignId('sales_outlet_id')->nullable()->after('product_id')->constrained('sales_outlets')->restrictOnDelete();
        });

        $defaultOutletId = DB::table('sales_outlets')->orderBy('id')->value('id');
        if ($defaultOutletId !== null) {
            DB::table('inventory')->whereNull('sales_outlet_id')->update(['sales_outlet_id' => $defaultOutletId]);
        }

        Schema::table('inventory', function (Blueprint $table) {
            $table->dropUnique(['product_id']);
            $table->unsignedBigInteger('sales_outlet_id')->nullable(false)->change();
            $table->unique(['product_id', 'sales_outlet_id']);
        });

        Schema::table('inventory_movements', function (Blueprint $table) {
            $table->foreignId('sales_outlet_id')->nullable()->after('product_id')->constrained('sales_outlets')->restrictOnDelete();
        });

        if ($defaultOutletId !== null) {
            DB::table('inventory_movements')->whereNull('sales_outlet_id')->update(['sales_outlet_id' => $defaultOutletId]);
        }
    }

    public function down(): void
    {
        Schema::table('inventory_movements', function (Blueprint $table) {
            $table->dropConstrainedForeignId('sales_outlet_id');
        });

        Schema::table('inventory', function (Blueprint $table) {
            $table->dropUnique(['product_id', 'sales_outlet_id']);
            $table->dropConstrainedForeignId('sales_outlet_id');
            $table->unique('product_id');
        });
    }
};
