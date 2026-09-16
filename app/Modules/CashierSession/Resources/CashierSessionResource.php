<?php

namespace App\Modules\CashierSession\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class CashierSessionResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'outletId' => $this->outlet_id,
            'openedBy' => $this->opened_by,
            'openingBalance' => (float) $this->opening_balance,
            'closedBy' => $this->closed_by,
            'closingBalance' => $this->closing_balance !== null ? (float) $this->closing_balance : null,
            'expectedClosingBalance' => $this->expected_closing_balance !== null ? (float) $this->expected_closing_balance : null,
            'variance' => $this->variance !== null ? (float) $this->variance : null,
            'status' => $this->status,
            'openedAt' => $this->opened_at?->toISOString(),
            'closedAt' => $this->closed_at?->toISOString(),
        ];
    }
}
