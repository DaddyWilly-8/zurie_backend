<?php

use App\Modules\Order\Models\Order;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Nullable + unique, and stays nullable — order_number can't be
        // known until after the row's own auto-increment id exists, so
        // OrderService::checkout() creates the row first (order_number
        // null) then updates it with the generated value right after.
        // MySQL allows multiple NULLs under a unique index, so this is
        // safe even with concurrent checkouts momentarily both null.
        Schema::table('orders', function (Blueprint $table) {
            $table->string('order_number')->nullable()->unique()->after('id');
        });

        // Backfill any pre-existing orders — derived from each row's own
        // id, same deterministic formula OrderService::generateOrderNumber()
        // uses for new orders, so backfilled and freshly-created numbers
        // are indistinguishable.
        Order::query()->whereNull('order_number')->orderBy('id')->each(function (Order $order) {
            $order->update(['order_number' => 'ORD-'.str_pad((string) $order->id, 6, '0', STR_PAD_LEFT)]);
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->dropColumn('order_number');
        });
    }
};
