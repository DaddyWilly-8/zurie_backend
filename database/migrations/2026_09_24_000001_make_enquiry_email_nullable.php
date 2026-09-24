<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Customers who reach out by WhatsApp or phone often leave no email; the
 * enquiry is still recorded as long as there's a phone to reply on (see
 * StoreEnquiryRequest).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('enquiries', function (Blueprint $table) {
            $table->string('email')->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('enquiries', function (Blueprint $table) {
            $table->string('email')->nullable(false)->change();
        });
    }
};
