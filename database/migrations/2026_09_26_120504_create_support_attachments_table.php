<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('support_attachments', function (Blueprint $table) {
            $table->id();
            // Denormalized alongside message_id purely so an authorization
            // check (is this requester a participant on this ticket?) is a
            // single-column lookup, not a join through support_messages
            // every time an attachment is downloaded.
            $table->foreignId('ticket_id')->constrained('support_tickets')->cascadeOnDelete();
            $table->foreignId('message_id')->constrained('support_messages')->cascadeOnDelete();
            $table->string('uploaded_by_type');
            $table->unsignedBigInteger('uploaded_by_id');
            $table->string('filename');
            $table->string('mime_type');
            $table->unsignedInteger('size');
            // Always a private disk (e.g. 'local') — never 'public' or an
            // S3 bucket served with a public URL, unlike MediaService's
            // product images. See SupportAttachmentService's docblock.
            $table->string('disk');
            $table->string('path');
            $table->timestamps();

            $table->index('ticket_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('support_attachments');
    }
};
