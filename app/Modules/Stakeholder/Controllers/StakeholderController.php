<?php

namespace App\Modules\Stakeholder\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Stakeholder\Resources\StakeholderResource;
use App\Modules\Stakeholder\Services\StakeholderService;
use App\Support\Http\ApiResponse;

/**
 * Read-only — a merged view across every stakeholder regardless of role,
 * distinct from the role-scoped Customer/Suppliers admin screens which
 * remain the place to create/edit either one. Customer and Supplier are
 * both mapped onto the same `stakeholders` table now (see their models'
 * docblocks); store/update/delete stay out of this controller since a
 * stakeholder's actual identity fields are always edited through
 * whichever of those two screens created it.
 */
class StakeholderController extends Controller
{
    use ApiResponse;

    public function __construct(private readonly StakeholderService $stakeholderService) {}

    public function index()
    {
        $stakeholders = $this->stakeholderService->all();

        return $this->paginated(
            StakeholderResource::collection($stakeholders->items()),
            [
                'count' => $stakeholders->total(),
                'page' => $stakeholders->currentPage(),
                'pageSize' => $stakeholders->perPage(),
            ]
        );
    }

    public function show(int $id)
    {
        return $this->ok(new StakeholderResource($this->stakeholderService->findOrFail($id)));
    }
}
