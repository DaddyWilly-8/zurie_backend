<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            // A string, not an enum — a future channel (e.g. "marketplace")
            // needs no migration to add. See Extensibility Constitution,
            // Rule 3. Default 'website' so every pre-existing order (and
            // any request that omits it) resolves to the channel that
            // already existed before this column.
            $table->string('source')->default('website')->after('status');

            // Cross-module reference, no FK — same convention as
            // customer_id on this same table. Nullable only so the column
            // can be added to a table that may already have rows;
            // OrderService always sets a real value going forward
            // (defaulting to OutletService::defaultOnlineOutlet() for
            // website checkout).
            $table->unsignedBigInteger('outlet_id')->nullable()->after('source');

            $table->index('source');
            $table->index('outlet_id');
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->dropColumn(['source', 'outlet_id']);
        });
    }
};
