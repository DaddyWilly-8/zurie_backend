<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('orders', function (Blueprint $table) {
            $table->id();

            // Cross-module reference into Customer — no FK constraint, indexed.
            $table->unsignedBigInteger('customer_id');

            // Snapshot of customer details at order time, independent of the live customer record.
            $table->string('customer_name');
            $table->string('customer_phone');
            $table->string('whatsapp_number')->nullable();
            $table->string('customer_email')->nullable();

            $table->enum('status', [
                'new', 'confirmed', 'processing', 'ready_for_delivery', 'delivered', 'cancelled',
            ])->default('new');

            $table->decimal('total_amount', 12, 2); // server-calculated only
            $table->text('notes')->nullable(); // admin-only

            $table->timestamps();

            $table->index('customer_id');
            $table->index('status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('orders');
    }
};
