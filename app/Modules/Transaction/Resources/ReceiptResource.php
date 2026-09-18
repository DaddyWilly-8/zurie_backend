<?php

namespace App\Modules\Transaction\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ReceiptResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'receiptNumber' => $this->receipt_number,
            'transactionDate' => $this->transaction_date?->toDateString(),
            'reference' => $this->reference,
            'narration' => $this->narration,
            'debitLedgerId' => $this->debit_ledger_id,
            'totalAmount' => (float) $this->total_amount,
            'items' => $this->whenLoaded('items', fn () => $this->items->map(fn ($item) => [
                'id' => $item->id,
                'creditLedgerId' => $item->credit_ledger_id,
                'amount' => (float) $item->amount,
                'journalEntryId' => $item->journal_entry_id,
            ])),
            'sales' => $this->whenLoaded('sales', fn () => $this->sales->map(fn ($sale) => [
                'orderId' => $sale->order_id,
                'amountApplied' => (float) $sale->amount_applied,
            ])),
            'createdAt' => $this->created_at?->toISOString(),
        ];
    }
}
