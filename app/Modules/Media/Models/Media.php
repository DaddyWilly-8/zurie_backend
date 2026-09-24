<?php

namespace App\Modules\Media\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Spatie\Activitylog\Models\Concerns\LogsActivity;
use Spatie\Activitylog\Support\LogOptions;

#[Fillable(['url', 'folder', 'original_filename', 'mime_type', 'size', 'uploaded_by'])]
class Media extends Model
{
    use LogsActivity;

    protected function casts(): array
    {
        return [
            'size' => 'integer',
        ];
    }

    /**
     * Audit trail (Admin > Activity): who created/changed/deleted this
     * and which fields changed.
     */
    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->useLogName('media')
            ->logOnly(['url', 'folder', 'original_filename', 'mime_type', 'size', 'uploaded_by'])
            ->logOnlyDirty()
            ->dontLogEmptyChanges()
            ->setDescriptionForEvent(fn (string $event): string => "Media '{$this->original_filename}' {$event}");
    }
}
