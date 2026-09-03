<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Replaces the original 3-table plan (brand_settings/contact_settings/
     * homepage_settings, each a key/value(json) table) with one consolidated
     * `settings` table — one row per category, each row's `value` holding
     * that whole category's JSON blob. Nothing ever needed a per-key row
     * within a category (every PUT replaces/merges the whole category at
     * once), so the extra `key` column the original tables had was unused
     * complexity. See zurie-backend-implementation-spec.md §11.
     */
    public function up(): void
    {
        Schema::dropIfExists('brand_settings');
        Schema::dropIfExists('contact_settings');
        Schema::dropIfExists('homepage_settings');

        Schema::create('settings', function (Blueprint $table) {
            $table->id();
            $table->string('category')->unique();
            $table->json('value');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('settings');
    }
};
