<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('support_messages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('ticket_id')->constrained('support_tickets')->cascadeOnDelete();
            // Polymorphic — unlike the ticket's own customer/staff FKs, a
            // message really can come from either side, so this mirrors
            // AppNotification's notifiable_type/notifiable_id shape rather
            // than a plain sender_id fk users (see the implementation
            // prompt's reference design, corrected for Zurie's two-guard
            // auth split).
            $table->string('sender_type');
            $table->unsignedBigInteger('sender_id');
            // 'message' or 'system' — a string per Rule 3, validated in
            // the service layer.
            $table->string('type')->default('message');
            $table->text('body');
            $table->dateTime('sent_at');
            // Deliberately no receiver column — the recipient is always
            // derived from the ticket's current state (see
            // SupportTicketService), so a reassignment mid-thread can
            // never leave a message pointing at the wrong person.
            $table->timestamp('read_at')->nullable();
            $table->timestamps();

            $table->index(['ticket_id', 'id']);
            $table->index(['sender_type', 'sender_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('support_messages');
    }
};
