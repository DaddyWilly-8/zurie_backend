<?php

namespace App\Modules\Finance\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['date', 'narration', 'reference_type', 'reference_id', 'created_by', 'currency_id', 'exchange_rate'])]
class JournalEntry extends Model
{
    protected function casts(): array
    {
        return [
            'date' => 'date',
            'exchange_rate' => 'double',
        ];
    }

    public function lines(): HasMany
    {
        return $this->hasMany(JournalEntryLine::class);
    }

    /**
     * The cost center(s) this whole entry is tagged to — see
     * cost_center_journal_entry's migration for why this lives at the
     * entry level, not per line.
     */
    public function costCenters(): BelongsToMany
    {
        return $this->belongsToMany(CostCenter::class, 'cost_center_journal_entry');
    }
}
