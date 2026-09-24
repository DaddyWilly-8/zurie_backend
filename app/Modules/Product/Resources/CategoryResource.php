<?php

namespace App\Modules\Product\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class CategoryResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'slug' => $this->slug,
            'description' => $this->description,
            'imageUrl' => $this->image_url,
            'visible' => $this->visible,
            'sortOrder' => $this->sort_order,
            'incomeLedgerId' => $this->income_ledger_id,
            'expenseLedgerId' => $this->expense_ledger_id,
        ];
    }
}
