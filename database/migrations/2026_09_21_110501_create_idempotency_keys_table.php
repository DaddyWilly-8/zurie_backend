<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('idempotency_keys', function (Blueprint $table) {
            $table->id();
            // sha256 of (caller identity | method | path | client key) — one
            // row per logical request; the unique index is what makes two
            // simultaneous submits race safely (only one insert wins).
            $table->string('scope_hash', 64)->unique();
            // sha256 of the request body — same key with a different body is a client bug.
            $table->string('request_hash', 64);
            // Null while the first request is still being processed.
            $table->unsignedSmallInteger('response_status')->nullable();
            $table->longText('response_body')->nullable();
            $table->timestamps();

            $table->index('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('idempotency_keys');
    }
};
