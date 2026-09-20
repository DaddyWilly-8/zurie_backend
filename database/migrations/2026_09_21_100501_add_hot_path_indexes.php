<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Indexes for the queries that grow with data volume: the admin order
     * list and monthly/period totals (ordered/filtered by created_at and
     * status), journal entries by date (period reports), stakeholder
     * lookups by role, and GRN lookups by their polymorphic parent.
     */
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->index('created_at', 'orders_created_at_index');
            $table->index(['status', 'created_at'], 'orders_status_created_at_index');
        });

        Schema::table('journal_entries', function (Blueprint $table) {
            $table->index('date', 'journal_entries_date_index');
        });

        Schema::table('stakeholders', function (Blueprint $table) {
            $table->index('is_customer_role', 'stakeholders_is_customer_role_index');
            $table->index('email', 'stakeholders_email_index');
        });

        Schema::table('inventory_movements', function (Blueprint $table) {
            $table->index('created_at', 'inventory_movements_created_at_index');
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->dropIndex('orders_created_at_index');
            $table->dropIndex('orders_status_created_at_index');
        });
        Schema::table('journal_entries', fn (Blueprint $t) => $t->dropIndex('journal_entries_date_index'));
        Schema::table('stakeholders', function (Blueprint $table) {
            $table->dropIndex('stakeholders_is_customer_role_index');
            $table->dropIndex('stakeholders_email_index');
        });
        Schema::table('inventory_movements', fn (Blueprint $t) => $t->dropIndex('inventory_movements_created_at_index'));
    }
};
