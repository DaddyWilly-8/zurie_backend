<?php

namespace App\Modules\Finance\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class LedgerGroupResource extends JsonResource
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
            'nature' => $this->nature,
            'isSystem' => $this->is_system,
            'children' => LedgerGroupResource::collection($this->whenLoaded('children')),
            'ledgers' => LedgerResource::collection($this->whenLoaded('ledgers')),
        ];
    }
}
