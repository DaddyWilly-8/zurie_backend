<?php

namespace App\Modules\Finance\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Finance\Models\LedgerGroup;
use App\Modules\Finance\Requests\StoreLedgerGroupRequest;
use App\Modules\Finance\Requests\UpdateLedgerGroupRequest;
use App\Modules\Finance\Resources\LedgerGroupResource;
use App\Modules\Finance\Services\LedgerGroupService;
use App\Support\Http\ApiResponse;

class LedgerGroupController extends Controller
{
    use ApiResponse;

    public function __construct(private readonly LedgerGroupService $ledgerGroupService) {}

    public function show(LedgerGroup $ledgerGroup)
    {
        return $this->ok(new LedgerGroupResource($ledgerGroup->load(['children.ledgers', 'ledgers'])));
    }

    public function store(StoreLedgerGroupRequest $request)
    {
        $group = $this->ledgerGroupService->create($request->validated());

        return $this->created(new LedgerGroupResource($group));
    }

    public function update(UpdateLedgerGroupRequest $request, LedgerGroup $ledgerGroup)
    {
        $group = $this->ledgerGroupService->update($ledgerGroup, $request->validated());

        return $this->ok(new LedgerGroupResource($group));
    }

    public function destroy(LedgerGroup $ledgerGroup)
    {
        $this->ledgerGroupService->delete($ledgerGroup);

        return $this->ok(['deleted' => true]);
    }
}
