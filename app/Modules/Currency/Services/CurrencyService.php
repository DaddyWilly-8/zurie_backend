<?php

namespace App\Modules\Currency\Services;

use App\Modules\Currency\Models\Currency;
use App\Modules\Currency\Models\CurrencyExchangeRate;
use App\Modules\Finance\Models\JournalEntry;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class CurrencyService
{
    /** Every currency, active or not — admin list needs to show (and reactivate) deactivated ones too. */
    public function all(): Collection
    {
        return Currency::query()->orderBy('code')->get();
    }

    public function findOrFail(int $id): Currency
    {
        return Currency::query()->findOrFail($id);
    }

    /**
     * The single currency every amount is implicitly denominated in when
     * no currencyId is supplied — the cross-module lookup Order/Purchase/
     * FinanceService use instead of querying Currency directly (per the
     * Extensibility Constitution, Rule 2).
     */
    public function base(): Currency
    {
        return Currency::where('is_base', true)->firstOrFail();
    }

    /**
     * @param  array<string, mixed>  $data  name, namePlural, code, symbol, symbolNative, decimalDigits?, isBase?, createdBy?
     *
     * @throws ValidationException  if isBase is true and a base currency already exists
     */
    public function create(array $data): Currency
    {
        $isBase = (bool) ($data['isBase'] ?? false);

        if ($isBase && Currency::where('is_base', true)->exists()) {
            throw ValidationException::withMessages([
                'isBase' => ['A base currency already exists — use the designate-base action to switch it instead.'],
            ]);
        }

        // is_active set explicitly rather than left to the DB column
        // default — Eloquent's create() doesn't reload DB-applied
        // defaults into the in-memory model (same bug class fixed
        // repeatedly elsewhere in this codebase).
        return Currency::create([
            'name' => $data['name'],
            'name_plural' => $data['namePlural'],
            'code' => strtoupper($data['code']),
            'symbol' => $data['symbol'],
            'symbol_native' => $data['symbolNative'],
            'decimal_digits' => $data['decimalDigits'] ?? 2,
            'is_base' => $isBase,
            'created_by' => $data['createdBy'] ?? null,
            'is_active' => true,
        ]);
    }

    /**
     * @param  array<string, mixed>  $data  name?, namePlural?, code?, symbol?, symbolNative?, decimalDigits?
     */
    public function update(Currency $currency, array $data): Currency
    {
        $currency->update([
            'name' => $data['name'] ?? $currency->name,
            'name_plural' => $data['namePlural'] ?? $currency->name_plural,
            'code' => isset($data['code']) ? strtoupper($data['code']) : $currency->code,
            'symbol' => $data['symbol'] ?? $currency->symbol,
            'symbol_native' => $data['symbolNative'] ?? $currency->symbol_native,
            'decimal_digits' => $data['decimalDigits'] ?? $currency->decimal_digits,
        ]);

        return $currency;
    }

    /**
     * "Delete" deactivates rather than removing the row — a hard delete
     * would orphan every historical Order/Purchase/JournalEntry
     * referencing this currency by id.
     *
     * @throws ValidationException  if deactivating the base currency, or one ever referenced by a JournalEntry
     */
    public function setActive(Currency $currency, bool $isActive): Currency
    {
        if (! $isActive) {
            if ($currency->is_base) {
                throw ValidationException::withMessages([
                    'isActive' => ['The base currency cannot be deactivated.'],
                ]);
            }

            if (JournalEntry::where('currency_id', $currency->id)->exists()) {
                throw ValidationException::withMessages([
                    'isActive' => ['This currency has been used in journal entries and cannot be deactivated.'],
                ]);
            }
        }

        $currency->update(['is_active' => $isActive]);

        return $currency;
    }

    /**
     * Switches which currency is base — unsets the previous one, sets the
     * new one, atomically. There is deliberately no "unset base with no
     * replacement" action; exactly one currency is base at all times.
     */
    public function designateBase(Currency $currency): Currency
    {
        DB::transaction(function () use ($currency): void {
            Currency::where('is_base', true)->update(['is_base' => false]);
            $currency->update(['is_base' => true]);
        });

        return $currency->refresh();
    }

    /**
     * @throws ValidationException  if $currency is the base currency (rates aren't tracked against itself)
     */
    public function addExchangeRate(Currency $currency, float $rateToBase, ?string $rateDatetime = null): CurrencyExchangeRate
    {
        if ($currency->is_base) {
            throw ValidationException::withMessages([
                'currency' => ['Exchange rates cannot be added against the base currency.'],
            ]);
        }

        return CurrencyExchangeRate::create([
            'currency_id' => $currency->id,
            'rate_datetime' => $rateDatetime ?? now(),
            'rate_to_base_currency' => $rateToBase,
        ]);
    }

    public function exchangeRatesFor(Currency $currency): Collection
    {
        return $currency->exchangeRates()->orderByDesc('rate_datetime')->get();
    }

    /**
     * The rate a caller should actually use right now for this currency —
     * its most recent exchange rate, or 1.0 if it's the base currency (or
     * has no rate history yet, which shouldn't normally happen for a
     * non-base currency but fails safe rather than throwing mid-checkout).
     */
    public function latestRateFor(Currency $currency): float
    {
        if ($currency->is_base) {
            return 1.0;
        }

        $latest = $currency->exchangeRates()->orderByDesc('rate_datetime')->first();

        return $latest !== null ? (float) $latest->rate_to_base_currency : 1.0;
    }
}
