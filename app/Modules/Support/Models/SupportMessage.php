<?php

namespace App\Modules\Support\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * sender_type/sender_id, not a plain sender_id fk users — either a staff
 * User or a customer CustomerAccount can send one, same polymorphic shape
 * as AppNotification's notifiable_type/notifiable_id (see that model's
 * docblock). Deliberately no receiver column: the recipient is always
 * derived from the parent ticket's current state (customer_account_id vs
 * attended_by_user_id), never stored here — see SupportTicketService's
 * recipientFor().
 */
#[Fillable(['ticket_id', 'sender_type', 'sender_id', 'type', 'body', 'sent_at', 'read_at'])]
class SupportMessage extends Model
{
    protected function casts(): array
    {
        return [
            'sent_at' => 'datetime',
            'read_at' => 'datetime',
        ];
    }

    public function ticket(): BelongsTo
    {
        return $this->belongsTo(SupportTicket::class, 'ticket_id');
    }

    public function attachments(): HasMany
    {
        return $this->hasMany(SupportAttachment::class, 'message_id');
    }

    public function sender(): \Illuminate\Database\Eloquent\Model|null
    {
        return $this->sender_type::find($this->sender_id);
    }
}
