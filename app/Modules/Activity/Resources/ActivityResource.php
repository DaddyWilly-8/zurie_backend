<?php

namespace App\Modules\Activity\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Str;

/**
 * Wraps spatie/laravel-activitylog's own Activity model — Activity has no
 * table of its own beyond what the package ships (activity_log), so this
 * resource is the only place its column names get translated to the app's
 * usual camelCase response shape.
 */
class ActivityResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'logName' => $this->log_name,
            'event' => $this->event,
            'description' => $this->description,
            // Short class basename, not the fully-qualified class string —
            // "Product", not "App\Modules\Product\Models\Product".
            'subjectType' => $this->subject_type ? Str::afterLast($this->subject_type, '\\') : null,
            'subjectId' => $this->subject_id,
            'causerId' => $this->causer_id,
            // Falls back to null for causedByAnonymous() entries (guest
            // checkout, failed login attempts) — never assume a causer exists.
            'causerName' => $this->causer?->name,
            'createdAt' => $this->created_at?->toISOString(),
        ];
    }
}
