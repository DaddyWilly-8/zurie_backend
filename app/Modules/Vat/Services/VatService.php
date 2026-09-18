<?php

namespace App\Modules\Vat\Services;

use App\Modules\Vat\Models\VatTransaction;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Model;

/**
 * No dedicated store/update/delete — every VatTransaction is a byproduct
 * record created inline by the parent document's own flow (Order,
 * Purchase, Grn), matching the reference doc's explicit rule and the same
 * reasoning InventoryMovement already has no CRUD of its own. This is the
 * one deliberate, documented exception to the Extensibility Constitution's
 * "every module ships full CRUD" rule — see
 * Zurie_V3_ProsERP_Adaptation_Plan.md, Phase E.
 */
class VatService
{
    /**
     * Called by OrderService/PurchaseService/GrnService right after
     * posting the corresponding ledger lines — never called with a zero
     * amount (callers skip it entirely when a line is VAT-exempt or the
     * rate is 0, same "don't write a meaningless row" posture as
     * FinanceService::postEntry()'s zero-lines guard).
     */
    public function record(Model $vatable, string $type, float $amount): VatTransaction
    {
        return VatTransaction::create([
            'type' => $type,
            'vatable_type' => $vatable::class,
            'vatable_id' => $vatable->getKey(),
            'amount' => $amount,
        ]);
    }

    /**
     * VAT summary report's backing query — net payable = output - input,
     * over any date range the caller wants (Report module passes it
     * through, same cross-module Service-only pattern as everywhere else).
     *
     * @return array{input: float, output: float, net: float}
     */
    public function summary(?string $from = null, ?string $to = null): array
    {
        $query = VatTransaction::query();
        if ($from !== null) {
            $query->where('created_at', '>=', $from);
        }
        if ($to !== null) {
            $query->where('created_at', '<=', $to);
        }

        $input = (float) (clone $query)->where('type', 'input')->sum('amount');
        $output = (float) (clone $query)->where('type', 'output')->sum('amount');

        return ['input' => $input, 'output' => $output, 'net' => $output - $input];
    }

    public function paginate(?string $type, int $page, int $pageSize): LengthAwarePaginator
    {
        return VatTransaction::query()
            ->when($type !== null, fn ($query) => $query->where('type', $type))
            ->orderByDesc('id')
            ->paginate($pageSize, ['*'], 'page', $page);
    }
}
