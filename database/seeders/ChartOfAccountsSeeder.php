<?php

namespace Database\Seeders;

use App\Modules\Finance\Models\Ledger;
use App\Modules\Finance\Models\LedgerGroup;
use Illuminate\Database\Seeder;

/**
 * Default chart of accounts — see Zurie_V2_Architecture_Design (2).md §30.1.
 * All rows here are `is_system => true`: fixed, never deletable via the
 * admin UI. Per-supplier/per-expense-category ledgers are created later,
 * on demand, by FinanceService::findOrCreateLedgerFor() — not seeded here.
 */
class ChartOfAccountsSeeder extends Seeder
{
    public function run(): void
    {
        $assets = $this->group('Assets', 'ASSET', 'asset');
        $currentAssets = $this->group('Current Assets', 'CUR-ASSET', 'asset', $assets->id);
        $this->ledger($currentAssets, 'Cash in Hand', 'CASH');
        $this->ledger($currentAssets, 'Bank Account', 'BANK');
        $this->ledger($currentAssets, 'Mobile Money', 'MOMO');
        $this->ledger($currentAssets, 'Inventory Asset', 'INV-ASSET');
        // Website checkout doesn't collect payment at order time (there's
        // no payment gateway yet — see the doc's checkout flow) — revenue
        // is still recognized at order placement for simplicity, but the
        // debit lands here instead of Cash until the order's eventually
        // settled. POS sales collect payment immediately, so they debit
        // Cash directly instead. See Phase 3 / §35.
        $this->ledger($currentAssets, 'Accounts Receivable', 'AR');
        // Phase E (VAT/Tax) — VAT paid to suppliers on purchases, reclaimable
        // from the government; an asset (normal debit balance), same
        // reasoning as Accounts Receivable being a claim on money owed to
        // this business rather than an expense.
        $this->ledger($currentAssets, 'VAT Input', 'VAT-IN');

        $liabilities = $this->group('Liabilities', 'LIAB', 'liability');
        // No ledgers seeded here — one Sundry Creditor ledger is created
        // per Supplier on demand (Phase 2) via findOrCreateLedgerFor(),
        // using this group's code ("CRED") as their code prefix, e.g.
        // "CRED-14" for supplier id 14 — see FinanceService::generateCode().
        $this->group('Sundry Creditors', 'CRED', 'liability', $liabilities->id);
        // Phase E (VAT/Tax) — VAT collected from customers on sales, owed
        // to the government; a liability (normal credit balance).
        $this->ledger($liabilities, 'VAT Output', 'VAT-OUT');

        $income = $this->group('Income', 'INCOME', 'income');
        $directIncome = $this->group('Direct Income', 'DIR-INCOME', 'income', $income->id);
        $this->ledger($directIncome, 'Sales Account', 'SALES');
        // Contra-revenue — carries the OPPOSITE normal balance of its
        // "Direct Income" group (a debit here INCREASES it, unlike Sales
        // Account), since it exists to be subtracted from Sales Account,
        // never added to it. See Ledger.is_contra / FinanceService::
        // applyToLedgerBalance().
        $this->ledger($directIncome, 'Sales Discounts', 'SALES-DISC', isContra: true);

        $expenses = $this->group('Expenses', 'EXPENSE', 'expense');
        $directExpenses = $this->group('Direct Expenses', 'DIR-EXP', 'expense', $expenses->id);
        $this->ledger($directExpenses, 'Cost of Goods Sold', 'COGS');
        // Inventory Transfers (multi-store initiative) — an "external"
        // transfer takes stock permanently out of the business (to a
        // franchisee, another legal entity, disposal), unlike an
        // "internal" transfer between two of our own outlets, which has
        // no ledger effect at all (same asset, different location).
        $this->ledger($directExpenses, 'Inventory Write-off', 'INV-WRITEOFF');
        // Indirect Expenses sub-group exists but starts empty — one ledger
        // per expense category (Rent, Salaries, Utilities) is created
        // on demand in Phase 5 (V2.1), same on-demand pattern as suppliers.
        $this->group('Indirect Expenses', 'IND-EXP', 'expense', $expenses->id);

        $equity = $this->group('Equity', 'EQUITY', 'equity');
        $this->ledger($equity, "Owner's Capital", 'CAPITAL');
    }

    private function group(string $name, string $code, string $nature, ?int $parentId = null): LedgerGroup
    {
        return LedgerGroup::firstOrCreate(
            ['code' => $code],
            ['name' => $name, 'nature' => $nature, 'parent_id' => $parentId, 'is_system' => true]
        );
    }

    private function ledger(LedgerGroup $group, string $name, string $code, bool $isContra = false): Ledger
    {
        return Ledger::firstOrCreate(
            ['code' => $code],
            ['ledger_group_id' => $group->id, 'name' => $name, 'is_system' => true, 'is_contra' => $isContra]
        );
    }
}
