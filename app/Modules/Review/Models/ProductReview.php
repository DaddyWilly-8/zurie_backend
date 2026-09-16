<?php

namespace App\Modules\Review\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Spatie\Activitylog\Models\Concerns\LogsActivity;
use Spatie\Activitylog\Support\LogOptions;

#[Fillable(['product_id', 'customer_id', 'rating', 'comment', 'status'])]
class ProductReview extends Model
{
    use LogsActivity;

    protected function casts(): array
    {
        return [
            'rating' => 'integer',
        ];
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->useLogName('review')
            ->logOnly(['status'])
            ->logOnlyDirty()
            ->dontLogEmptyChanges()
            ->setDescriptionForEvent(fn (string $event): string => $event === 'created'
                ? "New review submitted for product #{$this->product_id}"
                : "Review #{$this->id} {$event} (status: {$this->status})");
    }
}
