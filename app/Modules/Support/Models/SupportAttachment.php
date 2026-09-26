<?php

namespace App\Modules\Support\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['ticket_id', 'message_id', 'uploaded_by_type', 'uploaded_by_id', 'filename', 'mime_type', 'size', 'disk', 'path'])]
class SupportAttachment extends Model
{
    public function ticket(): BelongsTo
    {
        return $this->belongsTo(SupportTicket::class, 'ticket_id');
    }

    public function message(): BelongsTo
    {
        return $this->belongsTo(SupportMessage::class, 'message_id');
    }
}
