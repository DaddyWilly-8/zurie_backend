<?php

namespace App\Modules\Currency\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class CurrencyExchangeRateResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'currencyId' => $this->currency_id,
            'rateDatetime' => $this->rate_datetime?->toISOString(),
            'rateToBaseCurrency' => (float) $this->rate_to_base_currency,
        ];
    }
}
