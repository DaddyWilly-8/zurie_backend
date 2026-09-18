<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * `grnable_type`/`grnable_id` is polymorphic per Extensibility
     * Constitution rule 3 — today's only source is PurchaseOrder, but a
     * future GRN source (e.g. a stock transfer between locations) needs no
     * schema change. Deliberately no `store_id` — the reference doc ties a
     * GRN to a receiving warehouse, but Zuriè's Inventory is a single
     * global stock pool with no location/store concept yet (see
     * `Inventory` model — one row per product, no location dimension);
     * adding a `store_id` column with nothing behind it to differentiate
     * stock by location would be dead weight, not a faithful adaptation.
     * Revisit if Zuriè ever gains multi-location stock.
     */
    public function up(): void
    {
        Schema::create('grns', function (Blueprint $table) {
            $table->id();
            $table->string('grn_number')->nullable()->unique();
            $table->date('date_received');
            $table->double('cost_factor')->default(1);
            $table->string('grnable_type');
            $table->unsignedBigInteger('grnable_id');
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->index(['grnable_type', 'grnable_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('grns');
    }
};
