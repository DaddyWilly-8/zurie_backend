<?php

namespace App\Modules\Enquiry\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Spatie\Activitylog\Models\Concerns\LogsActivity;
use Spatie\Activitylog\Support\LogOptions;

#[Fillable(['name', 'email', 'phone', 'subject', 'message', 'status'])]
class Enquiry extends Model
{
    use LogsActivity;

    /**
     * Creation via POST /contact is unauthenticated (no auth:sanctum on
     * that route) — the logger's default causer resolution already
     * resolves to null for a guest request, same "causerId/causerName
     * null for guest-triggered entries" case the frontend's Activity Log
     * page had to be made null-safe for (see Order's checkout, which
     * hits the same case explicitly via causedByAnonymous()).
     */
    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->useLogName('enquiry')
            ->logOnly(['name', 'email', 'status'])
            ->logOnlyDirty()
            ->dontLogEmptyChanges()
            ->setDescriptionForEvent(fn (string $event): string => $event === 'created'
                ? "New enquiry from '{$this->name}'"
                : "Enquiry from '{$this->name}' {$event}");
    }
}
