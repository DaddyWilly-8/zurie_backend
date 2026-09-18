<?php

namespace App\Modules\ProformaInvoice\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ProformaInvoiceResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'proformaNumber' => $this->proforma_number,
            'proformaDate' => $this->proforma_date?->toDateString(),
            'expiryDate' => $this->expiry_date?->toDateString(),
            'salesOutletId' => $this->sales_outlet_id,
            'stakeholderId' => $this->stakeholder_id,
            'currencyId' => $this->currency_id,
            'exchangeRate' => (float) $this->exchange_rate,
            'totalAmount' => (float) $this->total_amount,
            'isActive' => (bool) $this->is_active,
            'notes' => $this->notes,
            'items' => $this->whenLoaded('items', fn () => $this->items->map(fn ($item) => [
                'id' => $item->id,
                'productId' => $item->product_id,
                'quantity' => (float) $item->quantity,
                'unitPrice' => (float) $item->unit_price,
                'lineTotal' => (float) $item->line_total,
            ])),
            'createdAt' => $this->created_at?->toISOString(),
        ];
    }
}
