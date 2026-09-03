<?php

namespace App\Modules\Settings\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Spatie\Activitylog\Models\Concerns\LogsActivity;
use Spatie\Activitylog\Support\LogOptions;

/**
 * One row per category (brand/contact/homepage/policies) — see the
 * migration for why this replaced the original 3-table plan. `value` is the
 * whole category's JSON blob; SettingsService is the only thing that reads/
 * writes this model, always via updateOrCreate() (exactly one save() per
 * write, unlike Order — so unlike Order, the LogsActivity trait fits here
 * without the multi-fire problem that made Order's logging manual).
 */
#[Fillable(['category', 'value'])]
class Setting extends Model
{
    use LogsActivity;

    protected function casts(): array
    {
        return [
            'value' => 'array',
        ];
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->useLogName('settings')
            ->logOnly(['value'])
            ->logOnlyDirty()
            ->dontLogEmptyChanges()
            ->setDescriptionForEvent(fn (string $event): string => ucfirst($this->category)." settings {$event}");
    }
}
