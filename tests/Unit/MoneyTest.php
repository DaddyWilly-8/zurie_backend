<?php

namespace Tests\Unit;

use App\Support\Money;
use PHPUnit\Framework\TestCase;

class MoneyTest extends TestCase
{
    public function test_from_decimal_and_to_decimal_round_trip_exactly(): void
    {
        $this->assertSame('1234.56', Money::fromDecimal('1234.56')->toDecimalString());
        $this->assertSame('0.01', Money::fromDecimal('0.01')->toDecimalString());
        $this->assertSame('-50.00', Money::fromDecimal('-50')->toDecimalString());
        $this->assertSame('0.00', Money::zero()->toDecimalString());
    }

    /**
     * The exact bug this class exists to prevent — see App\Support\Money's
     * docblock and https://martinfowler.com/eaaCatalog/money.html. In raw
     * PHP float arithmetic, 0.1 + 0.2 !== 0.3 because neither is exactly
     * representable in binary floating point. Money must never reproduce
     * this.
     */
    public function test_repeated_addition_never_drifts_the_way_raw_floats_do(): void
    {
        $this->assertNotEquals(0.3, 0.1 + 0.2, 'sanity check: floats really do drift this way');

        $sum = Money::fromDecimal('0.1')->add(Money::fromDecimal('0.2'));
        $this->assertSame('0.30', $sum->toDecimalString());
        $this->assertTrue($sum->equals(Money::fromDecimal('0.3')));
    }

    public function test_many_small_additions_stay_exact(): void
    {
        // 10,000 postings of 0.01 — a plausible lifetime volume for one
        // ledger. In float this drifts measurably; in Money it must not.
        $total = Money::zero();
        for ($i = 0; $i < 10000; $i++) {
            $total = $total->add(Money::fromDecimal('0.01'));
        }

        $this->assertSame('100.00', $total->toDecimalString());
        $this->assertSame(10000, $total->cents());
    }

    public function test_subtraction_and_negative_amounts(): void
    {
        $result = Money::fromDecimal('10.00')->subtract(Money::fromDecimal('15.00'));

        $this->assertTrue($result->isNegative());
        $this->assertSame('-5.00', $result->toDecimalString());
        $this->assertSame('5.00', $result->negate()->toDecimalString());
    }

    public function test_equals_and_is_zero(): void
    {
        $this->assertTrue(Money::fromDecimal('5.00')->equals(Money::fromDecimal('5.00')));
        $this->assertFalse(Money::fromDecimal('5.00')->equals(Money::fromDecimal('5.01')));
        $this->assertTrue(Money::fromDecimal('0.00')->isZero());
        $this->assertTrue(Money::fromDecimal('5.00')->subtract(Money::fromDecimal('5.00'))->isZero());
    }

    public function test_from_decimal_rounds_to_the_nearest_cent(): void
    {
        $this->assertSame('33.34', Money::fromDecimal(33.335)->toDecimalString());
        $this->assertSame('33.33', Money::fromDecimal(33.334)->toDecimalString());
    }

    public function test_from_decimal_accepts_the_string_a_laravel_decimal_cast_returns(): void
    {
        // Laravel's `decimal:2` cast returns a string like "1234.50", not
        // a float — this is the exact input shape every ledger-column
        // read hands to Money.
        $this->assertSame('1234.50', Money::fromDecimal('1234.50')->toDecimalString());
    }

    public function test_to_float_is_only_for_display_and_matches_the_decimal_value(): void
    {
        $this->assertSame(1234.56, Money::fromDecimal('1234.56')->toFloat());
    }

    public function test_immutability_original_instance_is_never_mutated(): void
    {
        $original = Money::fromDecimal('100.00');
        $original->add(Money::fromDecimal('50.00'));

        $this->assertSame('100.00', $original->toDecimalString(), 'add() must return a new instance, not mutate the receiver');
    }
}
