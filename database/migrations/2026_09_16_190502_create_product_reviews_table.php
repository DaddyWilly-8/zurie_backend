<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('product_reviews', function (Blueprint $table) {
            $table->id();
            // Cross-module reference into Product — no FK, same convention
            // as inventory.product_id.
            $table->unsignedBigInteger('product_id');
            // Real FK — a review belongs to one customer's account.
            $table->foreignId('customer_id')->constrained('customers')->cascadeOnDelete();
            $table->unsignedTinyInteger('rating');
            $table->text('comment')->nullable();
            // Moderation queue — a new review never appears publicly until
            // an admin approves it, same "don't trust user input as
            // publicly visible by default" posture as everywhere else.
            $table->string('status')->default('pending');
            $table->timestamps();

            $table->index('product_id');
            $table->index('status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('product_reviews');
    }
};
