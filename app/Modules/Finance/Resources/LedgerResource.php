<?php

namespace App\Modules\Finance\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class LedgerResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'code' => $this->code,
            'openingBalance' => (float) $this->opening_balance,
            'currentBalance' => (float) $this->current_balance,
            'isSystem' => $this->is_system,
        ];
    }
}
