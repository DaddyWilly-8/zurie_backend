<?php

namespace App\Modules\Support\Models;

use App\Modules\Auth\Models\User;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Append-only audit trail — the ticket keeps only the current handler
 * (support_tickets.attended_by_user_id); this is the full history of how
 * it got there. Never updated or deleted.
 */
#[Fillable(['ticket_id', 'from_user_id', 'to_user_id', 'reassigned_by', 'reason'])]
class TicketReassignment extends Model
{
    public $timestamps = false;

    protected function casts(): array
    {
        return [
            'created_at' => 'datetime',
        ];
    }

    public function ticket(): BelongsTo
    {
        return $this->belongsTo(SupportTicket::class, 'ticket_id');
    }

    public function fromUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'from_user_id');
    }

    public function toUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'to_user_id');
    }

    public function reassignedByUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reassigned_by');
    }
}
