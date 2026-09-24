<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Records, per order, whether its prices were VAT-inclusive when it was
     * sold (config zurie.prices_include_vat at that moment). Cancelling an
     * order must reverse exactly what the sale posted, and the two modes
     * split the same price differently between Sales and VAT Output — so
     * this is a fact of the sale, like exchange_rate, not something to
     * re-read from config later. Existing orders were posted with VAT on
     * top of the price, hence the false default.
     */
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->boolean('prices_include_vat')->default(false)->after('vat_amount');
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->dropColumn('prices_include_vat');
        });
    }
};
