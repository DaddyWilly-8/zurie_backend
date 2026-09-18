<?php

namespace App\Modules\ProformaInvoice\Services;

use App\Modules\Currency\Services\CurrencyService;
use App\Modules\ProformaInvoice\Models\ProformaInvoice;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;

class ProformaInvoiceService
{
    public function __construct(private readonly CurrencyService $currencyService) {}

    private static function generateProformaNumber(int $id): string
    {
        return 'PF-' . str_pad((string) $id, 6, '0', STR_PAD_LEFT);
    }

    /**
     * No stock effect, no ledger posting — a proforma is a standalone
     * quote, never a transaction (see the model's docblock). Full CRUD
     * (create/read/update/deactivate) still applies because, unlike
     * Order/Purchase, this is a document an admin genuinely edits or
     * withdraws before a customer acts on it, not an append-only
     * financial record.
     *
     * @param  array<string, mixed>  $data  proformaDate?, expiryDate?, salesOutletId, stakeholderId, currencyId?, notes?, items: array<{productId, quantity, unitPrice}>
     */
    public function create(array $data): ProformaInvoice
    {
        return DB::transaction(function () use ($data) {
            $currency = isset($data['currencyId'])
                ? $this->currencyService->findOrFail($data['currencyId'])
                : $this->currencyService->base();
            $exchangeRate = $this->currencyService->latestRateFor($currency);

            $proforma = ProformaInvoice::create([
                'proforma_date' => $data['proformaDate'] ?? now()->toDateString(),
                'expiry_date' => $data['expiryDate'] ?? null,
                'sales_outlet_id' => $data['salesOutletId'],
                'stakeholder_id' => $data['stakeholderId'],
                'currency_id' => $currency->id,
                'exchange_rate' => $exchangeRate,
                'total_amount' => 0,
                'is_active' => true,
                'notes' => $data['notes'] ?? null,
            ]);

            $proforma->update(['proforma_number' => self::generateProformaNumber($proforma->id)]);

            $this->syncItems($proforma, $data['items']);

            return $proforma->load('items');
        });
    }

    /** Full delete+recreate of items, same reasoning as PurchaseOrderService::update(). */
    public function update(ProformaInvoice $proforma, array $data): ProformaInvoice
    {
        return DB::transaction(function () use ($proforma, $data) {
            $currency = isset($data['currencyId'])
                ? $this->currencyService->findOrFail($data['currencyId'])
                : $this->currencyService->findOrFail($proforma->currency_id);
            $exchangeRate = $this->currencyService->latestRateFor($currency);

            $proforma->update([
                'proforma_date' => $data['proformaDate'] ?? $proforma->proforma_date,
                'expiry_date' => array_key_exists('expiryDate', $data) ? $data['expiryDate'] : $proforma->expiry_date,
                'sales_outlet_id' => $data['salesOutletId'] ?? $proforma->sales_outlet_id,
                'stakeholder_id' => $data['stakeholderId'] ?? $proforma->stakeholder_id,
                'currency_id' => $currency->id,
                'exchange_rate' => $exchangeRate,
                'notes' => $data['notes'] ?? $proforma->notes,
            ]);

            if (! empty($data['items'])) {
                $proforma->items()->delete();
                $this->syncItems($proforma, $data['items']);
            }

            return $proforma->fresh('items');
        });
    }

    private function syncItems(ProformaInvoice $proforma, array $items): void
    {
        $totalAmount = 0.0;

        foreach ($items as $line) {
            $lineTotal = $line['quantity'] * $line['unitPrice'];
            $totalAmount += $lineTotal;

            $proforma->items()->create([
                'product_id' => $line['productId'],
                'quantity' => $line['quantity'],
                'unit_price' => $line['unitPrice'],
                'line_total' => $lineTotal,
            ]);
        }

        $proforma->update(['total_amount' => $totalAmount]);
    }

    public function paginateAdmin(int $page, int $pageSize): LengthAwarePaginator
    {
        return ProformaInvoice::query()->with('items')->orderByDesc('id')->paginate($pageSize, ['*'], 'page', $page);
    }

    public function findOrFail(int $id): ProformaInvoice
    {
        return ProformaInvoice::query()->with('items')->findOrFail($id);
    }

    /** Withdraw (isActive: false) / restore (isActive: true) — see the migration's docblock. */
    public function setActive(ProformaInvoice $proforma, bool $isActive): ProformaInvoice
    {
        $proforma->update(['is_active' => $isActive]);

        return $proforma;
    }
}
