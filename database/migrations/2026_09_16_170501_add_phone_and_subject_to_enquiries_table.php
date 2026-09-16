<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('enquiries', function (Blueprint $table) {
            // The public contact form collects these too, even though the
            // doc's POST /contact example body only shows name/email/
            // message — both nullable so the minimal documented payload
            // still works unchanged.
            $table->string('phone')->nullable()->after('email');
            $table->string('subject')->nullable()->after('phone');
        });
    }

    public function down(): void
    {
        Schema::table('enquiries', function (Blueprint $table) {
            $table->dropColumn(['phone', 'subject']);
        });
    }
};
