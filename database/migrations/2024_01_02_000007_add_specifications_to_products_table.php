<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * "specifications" appears in zurie-api-contract.md's product detail
     * payload as a free-text bullet list, but has no backing column in the
     * original products table. Modeled as JSON rather than a child table —
     * unlike colors/sizes, it's never filtered or queried on, it's just
     * display copy, so it doesn't need the same real-table treatment.
     */
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->json('specifications')->nullable()->after('material');
        });
    }

    public function down(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->dropColumn('specifications');
        });
    }
};
