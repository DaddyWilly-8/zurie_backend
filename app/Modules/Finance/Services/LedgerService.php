<?php

namespace App\Modules\Finance\Services;

use App\Modules\Finance\Models\JournalEntryLine;
use App\Modules\Finance\Models\Ledger;
use Illuminate\Validation\ValidationException;

/**
 * Admin CRUD over individual Ledger rows a user creates directly (a new
 * expense/income category the auto-provisioning helpers on FinanceService
 * don't cover). Auto-managed ledgers (a Supplier's payable ledger, system
 * ledgers like Cash/Sales) keep going through
 * FinanceService::findOrCreateLedgerFor()/findOrCreateLedgerByName() —
 * this service is for the admin chart-of-accounts screen only.
 */
class LedgerService
{
    /**
     * @param  array<string, mixed>  $data  ledgerGroupId, name, code, openingBalance?, isContra?
     */
    public function create(array $data): Ledger
    {
        $openingBalance = (float) ($data['openingBalance'] ?? 0);

        $ledger = Ledger::create([
            'ledger_group_id' => $data['ledgerGroupId'],
            'name' => $data['name'],
            'code' => $data['code'],
            'opening_balance' => $openingBalance,
            'current_balance' => $openingBalance,
            'is_system' => false,
            'is_contra' => $data['isContra'] ?? false,
        ]);

        activity('finance')->performedOn($ledger)->event('created')->log("Ledger '{$ledger->name}' ({$ledger->code}) created with opening balance {$openingBalance}");

        return $ledger;
    }

    public function findOrFail(int $id): Ledger
    {
        return Ledger::query()->findOrFail($id);
    }

    /**
     * Name/code/group only — opening_balance and current_balance are
     * deliberately not editable here. current_balance is an incrementally
     * -adjusted running total that FinanceService::applyToLedgerBalance()
     * maintains from posted journal entries; letting an admin edit it
     * directly would desync it from the entries that are supposed to
     * explain it, the same integrity concern FinanceService::deleteEntry()
     * exists to protect against.
     *
     * @param  array<string, mixed>  $data  ledgerGroupId?, name?, code?
     *
     * @throws ValidationException  if the ledger is a system ledger
     */
    public function update(Ledger $ledger, array $data): Ledger
    {
        if ($ledger->is_system) {
            throw ValidationException::withMessages([
                'ledger' => ['A system ledger cannot be edited.'],
            ]);
        }

        $ledger->update([
            'ledger_group_id' => $data['ledgerGroupId'] ?? $ledger->ledger_group_id,
            'name' => $data['name'] ?? $ledger->name,
            'code' => $data['code'] ?? $ledger->code,
        ]);

        activity('finance')->performedOn($ledger)->event('updated')->log("Ledger '{$ledger->name}' ({$ledger->code}) updated");

        return $ledger;
    }

    /**
     * Hard delete — safe only when nothing has ever posted against this
     * ledger. A system ledger, or one with a nonzero balance, or one with
     * any JournalEntryLine referencing it, is never deletable; deleting a
     * referenced ledger would break every historical journal entry that
     * points at it via ledger_id.
     *
     * @throws ValidationException
     */
    public function delete(Ledger $ledger): void
    {
        if ($ledger->is_system) {
            throw ValidationException::withMessages([
                'ledger' => ['A system ledger cannot be deleted.'],
            ]);
        }

        if ((float) $ledger->current_balance !== 0.0) {
            throw ValidationException::withMessages([
                'ledger' => ['A ledger with a nonzero balance cannot be deleted.'],
            ]);
        }

        if (JournalEntryLine::where('ledger_id', $ledger->id)->exists()) {
            throw ValidationException::withMessages([
                'ledger' => ['This ledger has posted journal entries — it cannot be deleted.'],
            ]);
        }

        $name = $ledger->name;
        $code = $ledger->code;

        $ledger->delete();

        activity('finance')->event('deleted')->log("Ledger '{$name}' ({$code}) deleted");
    }
}
