<?php

namespace App\Modules\Transaction\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class PaymentResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'paymentNumber' => $this->payment_number,
            'transactionDate' => $this->transaction_date?->toDateString(),
            'reference' => $this->reference,
            'narration' => $this->narration,
            'creditLedgerId' => $this->credit_ledger_id,
            'totalAmount' => (float) $this->total_amount,
            'items' => $this->whenLoaded('items', fn () => $this->items->map(fn ($item) => [
                'id' => $item->id,
                'debitLedgerId' => $item->debit_ledger_id,
                'amount' => (float) $item->amount,
                'journalEntryId' => $item->journal_entry_id,
            ])),
            'purchaseOrders' => $this->whenLoaded('purchaseOrders', fn () => $this->purchaseOrders->map(fn ($link) => [
                'purchaseOrderId' => $link->purchase_order_id,
                'amountApplied' => (float) $link->amount_applied,
            ])),
            'createdAt' => $this->created_at?->toISOString(),
        ];
    }
}
