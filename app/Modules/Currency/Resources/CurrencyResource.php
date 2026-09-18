<?php

namespace App\Modules\Currency\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class CurrencyResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'namePlural' => $this->name_plural,
            'code' => $this->code,
            'symbol' => $this->symbol,
            'symbolNative' => $this->symbol_native,
            'decimalDigits' => $this->decimal_digits,
            'isBase' => $this->is_base,
            'isActive' => $this->is_active,
        ];
    }
}
