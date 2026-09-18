<?php

namespace App\Modules\MeasurementUnit\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\MeasurementUnit\Models\MeasurementUnit;
use App\Modules\MeasurementUnit\Requests\StoreMeasurementUnitRequest;
use App\Modules\MeasurementUnit\Requests\UpdateMeasurementUnitRequest;
use App\Modules\MeasurementUnit\Resources\MeasurementUnitResource;
use App\Modules\MeasurementUnit\Services\MeasurementUnitService;
use App\Support\Http\ApiResponse;

class MeasurementUnitController extends Controller
{
    use ApiResponse;

    public function __construct(private readonly MeasurementUnitService $measurementUnitService) {}

    public function index()
    {
        return $this->ok(MeasurementUnitResource::collection($this->measurementUnitService->all()));
    }

    public function show(MeasurementUnit $measurementUnit)
    {
        return $this->ok(new MeasurementUnitResource($measurementUnit));
    }

    public function store(StoreMeasurementUnitRequest $request)
    {
        $unit = $this->measurementUnitService->create($request->validated());

        return $this->created(new MeasurementUnitResource($unit));
    }

    /**
     * PATCH /admin/measurement-units/{measurementUnit} — field edits and
     * the isActive toggle ("delete" in the admin UI) in one call, same
     * shape as Cost Centers/Outlets/Suppliers/Price Lists.
     */
    public function update(UpdateMeasurementUnitRequest $request, MeasurementUnit $measurementUnit)
    {
        $data = $request->validated();

        $unit = $this->measurementUnitService->update($measurementUnit, $data);

        if (array_key_exists('isActive', $data)) {
            $unit = $this->measurementUnitService->setActive($unit, $data['isActive']);
        }

        return $this->ok(new MeasurementUnitResource($unit));
    }
}
