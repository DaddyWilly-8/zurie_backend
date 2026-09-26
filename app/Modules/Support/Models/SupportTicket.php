<?php

namespace App\Modules\Support\Models;

use App\Modules\Auth\Models\CustomerAccount;
use App\Modules\Auth\Models\User;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * No LogsActivity trait — same reasoning as Order (see its docblock):
 * activate/reassign/close each touch more than one field/table at once,
 * so SupportTicketService logs one meaningful activity() line per action
 * instead of the trait's noisy per-field diff.
 */
#[Fillable(['customer_account_id', 'subject', 'organization_name', 'notes', 'status', 'attended_by_user_id', 'closed_at'])]
class SupportTicket extends Model
{
    protected function casts(): array
    {
        return [
            'closed_at' => 'datetime',
        ];
    }

    public function customerAccount(): BelongsTo
    {
        return $this->belongsTo(CustomerAccount::class);
    }

    public function attendedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'attended_by_user_id');
    }

    public function messages(): HasMany
    {
        return $this->hasMany(SupportMessage::class, 'ticket_id');
    }

    public function reassignments(): HasMany
    {
        return $this->hasMany(TicketReassignment::class, 'ticket_id');
    }

    public function attachments(): HasMany
    {
        return $this->hasMany(SupportAttachment::class, 'ticket_id');
    }
}
