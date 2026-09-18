<?php

namespace App\Modules\MeasurementUnit\Services;

use App\Modules\MeasurementUnit\Models\MeasurementUnit;
use Illuminate\Database\Eloquent\Collection;

class MeasurementUnitService
{
    /** Every unit, active or not — admin list needs to show (and reactivate) deactivated ones too. */
    public function all(): Collection
    {
        return MeasurementUnit::query()->orderBy('name')->get();
    }

    public function findOrFail(int $id): MeasurementUnit
    {
        return MeasurementUnit::query()->findOrFail($id);
    }

    /**
     * @param  array<string, mixed>  $data  name, symbol, description?
     */
    public function create(array $data): MeasurementUnit
    {
        // is_active set explicitly rather than left to the DB column
        // default — Eloquent's create() doesn't reload DB-applied
        // defaults into the in-memory model (same bug class fixed
        // repeatedly elsewhere in this codebase).
        return MeasurementUnit::create([
            'name' => $data['name'],
            'symbol' => $data['symbol'],
            'description' => $data['description'] ?? null,
            'is_active' => true,
        ]);
    }

    /**
     * @param  array<string, mixed>  $data  name?, symbol?, description?
     */
    public function update(MeasurementUnit $unit, array $data): MeasurementUnit
    {
        $unit->update([
            'name' => $data['name'] ?? $unit->name,
            'symbol' => $data['symbol'] ?? $unit->symbol,
            'description' => array_key_exists('description', $data) ? $data['description'] : $unit->description,
        ]);

        return $unit;
    }

    /**
     * "Delete" deactivates rather than removing the row — a hard delete
     * would orphan every historical product referencing this unit by id.
     */
    public function setActive(MeasurementUnit $unit, bool $isActive): MeasurementUnit
    {
        $unit->update(['is_active' => $isActive]);

        return $unit;
    }
}
