<?php

namespace App\Modules\Finance\Services;

use App\Modules\Finance\Models\LedgerGroup;
use Illuminate\Validation\ValidationException;

/**
 * Separate from FinanceService — that one owns ledger/journal posting and
 * the read-only chartOfAccounts() tree, this one owns admin CRUD over the
 * Ledger Group structure itself. Kept as its own service so FinanceService
 * doesn't grow unrelated CRUD responsibilities (same reasoning as
 * CostCenterService's split).
 */
class LedgerGroupService
{
    /**
     * @param  array<string, mixed>  $data  name, code, nature, parentId?
     */
    public function create(array $data): LedgerGroup
    {
        $group = LedgerGroup::create([
            'name' => $data['name'],
            'code' => $data['code'],
            'nature' => $data['nature'],
            'parent_id' => $data['parentId'] ?? null,
            'is_system' => false,
        ]);

        activity('finance')->performedOn($group)->event('created')->log("Ledger group '{$group->name}' ({$group->code}) created");

        return $group;
    }

    public function findOrFail(int $id): LedgerGroup
    {
        return LedgerGroup::query()->findOrFail($id);
    }

    /**
     * @param  array<string, mixed>  $data  name?, code?, nature?, parentId?
     *
     * @throws ValidationException  if the group is seeded/system, or parentId would make it its own parent
     */
    public function update(LedgerGroup $group, array $data): LedgerGroup
    {
        if ($group->is_system) {
            throw ValidationException::withMessages([
                'group' => ['A seeded system ledger group cannot be edited.'],
            ]);
        }

        $parentId = array_key_exists('parentId', $data) ? $data['parentId'] : $group->parent_id;

        if ($parentId === $group->id) {
            throw ValidationException::withMessages([
                'parentId' => ['A ledger group cannot be its own parent.'],
            ]);
        }

        $group->update([
            'name' => $data['name'] ?? $group->name,
            'code' => $data['code'] ?? $group->code,
            'nature' => $data['nature'] ?? $group->nature,
            'parent_id' => $parentId,
        ]);

        activity('finance')->performedOn($group)->event('updated')->log("Ledger group '{$group->name}' ({$group->code}) updated");

        return $group;
    }

    /**
     * Hard delete — safe only when nothing depends on this group. A
     * system (seeded) group is never deletable; a group holding child
     * groups or ledgers is never deletable, since either would orphan
     * real chart-of-accounts structure or leave ledgers pointing at a
     * ledger_group_id that no longer exists (the FK is restrictOnDelete,
     * so the DB would refuse it anyway — this check gives a clear
     * message instead of a raw SQL error).
     *
     * @throws ValidationException
     */
    public function delete(LedgerGroup $group): void
    {
        if ($group->is_system) {
            throw ValidationException::withMessages([
                'group' => ['A seeded system ledger group cannot be deleted.'],
            ]);
        }

        if ($group->children()->exists()) {
            throw ValidationException::withMessages([
                'group' => ['This group still has sub-groups — remove them first.'],
            ]);
        }

        if ($group->ledgers()->exists()) {
            throw ValidationException::withMessages([
                'group' => ['This group still has ledgers — remove or reassign them first.'],
            ]);
        }

        $name = $group->name;
        $code = $group->code;

        $group->delete();

        activity('finance')->event('deleted')->log("Ledger group '{$name}' ({$code}) deleted");
    }
}
