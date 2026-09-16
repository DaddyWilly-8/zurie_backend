<?php

namespace App\Modules\CashierSession\Services;

use App\Modules\CashierSession\Exceptions\CashierSessionAlreadyClosedException;
use App\Modules\CashierSession\Exceptions\CashierSessionAlreadyOpenException;
use App\Modules\CashierSession\Exceptions\NoOpenCashierSessionException;
use App\Modules\CashierSession\Models\CashierSession;
use App\Modules\Order\Services\OrderService;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;

class CashierSessionService
{
    public function __construct(private readonly OrderService $orderService) {}

    /**
     * One open session per outlet at a time — opening a second one while
     * the first is still open would make reconciliation ambiguous (which
     * session does a POS sale in that window belong to). Not a hard gate
     * on POS sales themselves (a sale can happen with no session open at
     * all) — this module tracks/reconciles cash, it doesn't block selling.
     *
     * @throws CashierSessionAlreadyOpenException  if the outlet already has an open session
     */
    public function open(int $outletId, float $openingBalance, int $openedBy): CashierSession
    {
        $existing = CashierSession::where('outlet_id', $outletId)->where('status', 'open')->first();
        if ($existing !== null) {
            throw new CashierSessionAlreadyOpenException($outletId, $existing->id);
        }

        return CashierSession::create([
            'outlet_id' => $outletId,
            'opened_by' => $openedBy,
            'opening_balance' => $openingBalance,
            'status' => 'open',
            'opened_at' => now(),
        ]);
    }

    /**
     * Reconciliation: expected = opening balance + cash POS sales recorded
     * at this outlet between opening and now. variance = actual counted
     * cash - expected — positive means more cash than expected, negative
     * means a shortfall.
     */
    public function close(CashierSession $session, float $closingBalance, int $closedBy): CashierSession
    {
        return DB::transaction(function () use ($session, $closingBalance, $closedBy) {
            if ($session->status !== 'open') {
                throw new CashierSessionAlreadyClosedException($session->id);
            }

            $closedAt = now();
            $cashSales = $this->orderService->sumPosSalesForOutlet(
                $session->outlet_id,
                $session->opened_at->toDateTimeString(),
                $closedAt->toDateTimeString(),
            );
            $expected = (float) $session->opening_balance + $cashSales;

            $session->update([
                'status' => 'closed',
                'closed_by' => $closedBy,
                'closing_balance' => $closingBalance,
                'expected_closing_balance' => $expected,
                'variance' => $closingBalance - $expected,
                'closed_at' => $closedAt,
            ]);

            return $session;
        });
    }

    /**
     * @throws NoOpenCashierSessionException  if the outlet has no open session
     */
    public function currentForOutlet(int $outletId): CashierSession
    {
        $session = CashierSession::where('outlet_id', $outletId)->where('status', 'open')->first();
        if ($session === null) {
            throw new NoOpenCashierSessionException($outletId);
        }

        return $session;
    }

    public function paginateAdmin(int $page, int $pageSize): LengthAwarePaginator
    {
        return CashierSession::query()->orderByDesc('id')->paginate($pageSize, ['*'], 'page', $page);
    }
}
