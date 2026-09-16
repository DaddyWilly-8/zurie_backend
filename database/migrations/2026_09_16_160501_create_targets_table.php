<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('targets', function (Blueprint $table) {
            $table->id();
            // 'YYYY-MM' — monthly only for now (doc's §18 lists
            // daily/weekly/quarterly as future). One target per period,
            // enforced by the unique index.
            $table->string('period')->unique();
            $table->decimal('target_amount', 14, 2);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('targets');
    }
};
