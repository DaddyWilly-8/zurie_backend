<?php

namespace App\Modules\Activity\Services;

use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Spatie\Activitylog\Models\Activity;

/**
 * Read-only — Activity never writes its own rows directly; every entry
 * comes from either a model's LogsActivity trait or an explicit activity()
 * call elsewhere in the app (Order, Inventory, Auth's pivot syncs, the auth
 * event listeners in this module). See §12.
 */
class ActivityService
{
    public function paginateAdmin(int $page, int $pageSize): LengthAwarePaginator
    {
        return Activity::query()
            ->with('causer')
            ->orderByDesc('id')
            ->paginate($pageSize, ['*'], 'page', $page);
    }
}
