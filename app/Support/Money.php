<?php

namespace App\Support;

/**
 * Represents a monetary amount as an exact integer count of minor units
 * (cents), never as a float — the standard approach in real payment
 * systems (Stripe's API represents every `amount` as an integer in the
 * currency's minor unit; see https://docs.stripe.com/currencies), and the
 * "give money its own type" principle from Fowler's Money pattern
 * (https://martinfowler.com/eaaCatalog/money.html). PHP floats are binary
 * fractions and cannot represent most decimal amounts exactly (0.1 + 0.2
 * !== 0.3 in IEEE 754) — every add/subtract done in float drifts a little,
 * and a ledger balance that accumulates thousands of postings over a
 * business's lifetime eventually drifts by real money, not a rounding
 * curiosity. Working in integer cents makes every operation here exact,
 * with the DB's own DECIMAL(14,2) columns (also exact, unlike float) as
 * the storage boundary.
 *
 * Scope: introduced for FinanceService's ledger-balance arithmetic (the
 * one place every module's money movement already funnels through — see
 * CLAUDE.md "cross-module coupling" — so fixing precision here fixes it
 * for the whole system's *accumulated* balances without having to rewrite
 * every module's line-total calculation). Upstream per-line calculations
 * (VAT %, discounts, unit price * quantity) still compute in float and
 * hand a final amount into postEntry(), which now rounds it into an exact
 * Money the moment it arrives — see docs/ARCHITECTURE_GUIDE.md §14b for
 * what remains out of scope and why.
 */
final class Money
{
    private function __construct(private readonly int $cents) {}

    public static function zero(): self
    {
        return new self(0);
    }

    public static function fromCents(int $cents): self
    {
        return new self($cents);
    }

    /**
     * The one place a float/string decimal amount is allowed to enter
     * this class — rounded to the nearest cent immediately, so no float
     * value survives past this boundary. Accepts the string a Laravel
     * `decimal:2` cast returns (preferred — exact) as well as a plain
     * float/int (from request input or older float-based call sites).
     */
    public static function fromDecimal(string|float|int $amount): self
    {
        return new self((int) round(((float) $amount) * 100));
    }

    public function add(self $other): self
    {
        return new self($this->cents + $other->cents);
    }

    public function subtract(self $other): self
    {
        return new self($this->cents - $other->cents);
    }

    public function negate(): self
    {
        return new self(-$this->cents);
    }

    public function isZero(): bool
    {
        return $this->cents === 0;
    }

    public function isNegative(): bool
    {
        return $this->cents < 0;
    }

    public function equals(self $other): bool
    {
        return $this->cents === $other->cents;
    }

    public function cents(): int
    {
        return $this->cents;
    }

    /**
     * For persisting to a DECIMAL(x,2) column, or comparing against one —
     * exact string formatting, no float round-trip.
     */
    public function toDecimalString(): string
    {
        $sign = $this->cents < 0 ? '-' : '';
        $absCents = abs($this->cents);

        return sprintf('%s%d.%02d', $sign, intdiv($absCents, 100), $absCents % 100);
    }

    /**
     * Display/JSON-response output ONLY. Never feed this back into
     * arithmetic — that's exactly the float drift this class exists to
     * avoid. Every calculation should stay in Money (or its ->cents())
     * until the moment it's handed to a view/response.
     */
    public function toFloat(): float
    {
        return $this->cents / 100;
    }
}
