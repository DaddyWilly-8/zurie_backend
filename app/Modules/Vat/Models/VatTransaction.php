<?php

namespace App\Modules\Vat\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphTo;

#[Fillable(['tin', 'vrn', 'organization_name', 'reference', 'type', 'vatable_type', 'vatable_id', 'amount'])]
class VatTransaction extends Model
{
    protected function casts(): array
    {
        return ['amount' => 'double'];
    }

    public function vatable(): MorphTo
    {
        return $this->morphTo();
    }
}
