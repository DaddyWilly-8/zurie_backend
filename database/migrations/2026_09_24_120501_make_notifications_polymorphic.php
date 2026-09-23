<?php

use App\Modules\Auth\Models\User;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Customer/staff split — `notifications.user_id` assumed every
     * recipient was a `users` row, which stopped being true the moment
     * CustomerAccount became a separate table/guard (a notification for
     * customer_account id 5 and one for user id 5 would otherwise
     * collide on the same integer). Made polymorphic instead
     * (notifiable_type/notifiable_id) — Extensibility Constitution Rule
     * 3: "who can receive a notification" is exactly the kind of growing
     * category that should never need a schema change again (a future
     * SupplierAccount, if one's ever built, needs zero migration here).
     * Every existing row today is a User (only staff/legacy accounts
     * have ever received one — see NotificationService's docblock), so
     * the backfill is unambiguous.
     */
    public function up(): void
    {
        Schema::table('notifications', function (Blueprint $table) {
            $table->string('notifiable_type')->nullable()->after('id');
            $table->unsignedBigInteger('notifiable_id')->nullable()->after('notifiable_type');
        });

        DB::table('notifications')->whereNull('notifiable_type')->update([
            'notifiable_type' => User::class,
            'notifiable_id' => DB::raw('user_id'),
        ]);

        Schema::table('notifications', function (Blueprint $table) {
            $table->dropForeign(['user_id']);
            $table->dropColumn('user_id');
            $table->string('notifiable_type')->nullable(false)->change();
            $table->unsignedBigInteger('notifiable_id')->nullable(false)->change();
            $table->index(['notifiable_type', 'notifiable_id', 'read_at']);
        });
    }

    public function down(): void
    {
        Schema::table('notifications', function (Blueprint $table) {
            $table->dropIndex(['notifiable_type', 'notifiable_id', 'read_at']);
            $table->foreignId('user_id')->nullable()->after('id')->constrained('users')->cascadeOnDelete();
        });

        DB::table('notifications')->update(['user_id' => DB::raw('notifiable_id')]);

        Schema::table('notifications', function (Blueprint $table) {
            $table->dropColumn(['notifiable_type', 'notifiable_id']);
            $table->foreignId('user_id')->nullable(false)->change();
        });
    }
};
