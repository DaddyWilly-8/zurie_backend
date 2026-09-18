<?php

namespace App\Modules\Transaction\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class JournalVoucherResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'voucherNumber' => $this->voucher_number,
            'transactionDate' => $this->transaction_date?->toDateString(),
            'reference' => $this->reference,
            'narration' => $this->narration,
            'totalAmount' => (float) $this->total_amount,
            'items' => $this->whenLoaded('items', fn () => $this->items->map(fn ($item) => [
                'id' => $item->id,
                'debitLedgerId' => $item->debit_ledger_id,
                'creditLedgerId' => $item->credit_ledger_id,
                'amount' => (float) $item->amount,
                'journalEntryId' => $item->journal_entry_id,
            ])),
            'createdAt' => $this->created_at?->toISOString(),
        ];
    }
}
