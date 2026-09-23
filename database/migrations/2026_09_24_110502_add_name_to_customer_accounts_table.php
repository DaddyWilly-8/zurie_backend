<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * A lightweight, non-authoritative display name — only meaningful
     * before a Google-signed-up account has linked a Customer/stakeholder
     * record (Google supplies a name, never a phone number, so no
     * stakeholder can be created yet — see CustomerAccountService::
     * findOrCreateForSocialite()). Once a stakeholder is linked, the
     * stakeholder's own `name` is the real one; this column is never
     * updated again after creation.
     */
    public function up(): void
    {
        Schema::table('customer_accounts', function (Blueprint $table) {
            $table->string('name')->nullable()->after('id');
        });
    }

    public function down(): void
    {
        Schema::table('customer_accounts', function (Blueprint $table) {
            $table->dropColumn('name');
        });
    }
};
