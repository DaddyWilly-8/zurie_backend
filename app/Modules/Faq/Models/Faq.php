<?php

namespace App\Modules\Faq\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Spatie\Activitylog\Models\Concerns\LogsActivity;
use Spatie\Activitylog\Support\LogOptions;

#[Fillable(['question', 'answer', 'sort_order', 'visible'])]
class Faq extends Model
{
    use LogsActivity;

    protected $table = 'faqs';

    /**
     * Simple single-save CRUD, same shape as Category — the trait handles
     * logging directly rather than manual activity() calls (reserved for
     * multi-step transactions like Order/Purchase where a trait would log
     * every intermediate save as a separate noisy entry).
     */
    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->useLogName('faq')
            ->logOnly(['question', 'visible', 'sort_order'])
            ->logOnlyDirty()
            ->dontLogEmptyChanges()
            ->setDescriptionForEvent(fn (string $event): string => "FAQ '{$this->question}' {$event}");
    }

    protected function casts(): array
    {
        return [
            'visible' => 'boolean',
            'sort_order' => 'integer',
        ];
    }
}
