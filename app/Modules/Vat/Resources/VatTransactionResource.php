<?php

namespace App\Modules\Vat\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class VatTransactionResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'type' => $this->type,
            'vatableType' => $this->vatable_type,
            'vatableId' => $this->vatable_id,
            'amount' => (float) $this->amount,
            'createdAt' => $this->created_at?->toISOString(),
        ];
    }
}
