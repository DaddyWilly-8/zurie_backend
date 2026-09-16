<?php

namespace App\Modules\Target\Services;

use App\Modules\Order\Services\OrderService;
use App\Modules\Target\Models\Target;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Carbon;

class TargetService
{
    public function __construct(private readonly OrderService $orderService) {}

    /**
     * @param  array<string, mixed>  $data  period ('YYYY-MM'), targetAmount
     */
    public function create(array $data): Target
    {
        return Target::updateOrCreate(['period' => $data['period']], ['target_amount' => $data['targetAmount']]);
    }

    public function all(): Collection
    {
        return Target::query()->orderByDesc('period')->get();
    }

    /**
     * Achievement is always computed live from Orders (Architecture
     * Principle 4 — no manually-maintained totals), never stored on the
     * Target row itself. Returns null current/percentage fields if no
     * target has been set for the requested period.
     *
     * @return array{period: string, targetAmount: float|null, currentAmount: float, achievementPercentage: float|null, remainingAmount: float|null}
     */
    public function achievementFor(?string $period = null): array
    {
        $period ??= Carbon::now()->format('Y-m');
        $target = Target::where('period', $period)->first();
        $current = $this->orderService->sumRevenueForPeriod($period);

        return [
            'period' => $period,
            'targetAmount' => $target ? (float) $target->target_amount : null,
            'currentAmount' => $current,
            'achievementPercentage' => $target && (float) $target->target_amount > 0
                ? round(($current / (float) $target->target_amount) * 100, 1)
                : null,
            'remainingAmount' => $target ? max(0.0, (float) $target->target_amount - $current) : null,
        ];
    }
}
