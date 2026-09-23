<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * TOTP-based 2FA (RFC 6238 — the same standard Google Authenticator,
     * Authy, 1Password etc. all implement), optional per account. Secret
     * and recovery codes are stored via Laravel's `encrypted` cast (see
     * User model), never plaintext — a stolen database backup alone must
     * not be enough to derive a valid code. `two_factor_confirmed_at`
     * being null is what distinguishes "secret generated, not yet
     * verified" from "actually protecting this login" — see
     * TwoFactorService::enable()/confirm()'s docblocks.
     */
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->text('two_factor_secret')->nullable()->after('password');
            $table->text('two_factor_recovery_codes')->nullable()->after('two_factor_secret');
            $table->timestamp('two_factor_confirmed_at')->nullable()->after('two_factor_recovery_codes');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['two_factor_secret', 'two_factor_recovery_codes', 'two_factor_confirmed_at']);
        });
    }
};
