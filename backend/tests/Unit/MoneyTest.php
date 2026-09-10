<?php

namespace Tests\Unit;

use App\Support\Money;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

/**
 * Money is the floor everything else stands on: if add() drifts or mul()
 * rounds the wrong way, every balance, escrow total and fiat amount computed
 * from it is wrong in a way that only surfaces when someone is owed money.
 */
class MoneyTest extends TestCase
{
    public function test_it_canonicalises_to_eight_decimal_places(): void
    {
        $this->assertSame('1.00000000', Money::of('1'));
        $this->assertSame('1.50000000', Money::of('1.5'));
        $this->assertSame('0.00000001', Money::of('0.00000001'));
        $this->assertSame('0.00000000', Money::of('0'));
        $this->assertSame('0.00000000', Money::of(0));
        $this->assertSame('1000.00000000', Money::of('1000.000000000'));
    }

    public function test_it_truncates_below_the_scale_rather_than_rounding_up(): void
    {
        // 1e-9 of a USDT does not exist on our ledger. Flooring means we never
        // credit a user more than they actually sent.
        $this->assertSame('0.00000000', Money::of('0.000000001'));
        $this->assertSame('0.12345678', Money::of('0.123456789'));
    }

    public function test_the_classic_float_trap_is_exact(): void
    {
        // 0.1 + 0.2 === 0.30000000000000004 in floats. On a balance that
        // discrepancy compounds until escrow stops matching its trades.
        $this->assertSame('0.30000000', Money::add('0.1', '0.2'));
        $this->assertTrue(Money::eq(Money::add('0.1', '0.2'), '0.3'));
    }

    public function test_addition_does_not_drift_over_many_movements(): void
    {
        $total = Money::zero();

        for ($i = 0; $i < 10000; $i++) {
            $total = Money::add($total, '0.1');
        }

        $this->assertSame('1000.00000000', $total);
    }

    public function test_subtraction_is_the_inverse_of_addition(): void
    {
        $this->assertSame('0.00000000', Money::sub('0.3', '0.3'));
        $this->assertSame('0.00000001', Money::sub('1.00000001', '1'));
        $this->assertSame('-1.00000000', Money::sub('1', '2'));
        $this->assertSame('-0.50000000', Money::sub('0.5', '1'));
    }

    public function test_negate_and_abs(): void
    {
        $this->assertSame('-1.50000000', Money::negate('1.5'));
        $this->assertSame('1.50000000', Money::negate('-1.5'));
        // Negating zero must not produce "-0.00000000".
        $this->assertSame('0.00000000', Money::negate('0'));
        $this->assertSame('1.50000000', Money::abs('-1.5'));
    }

    public function test_multiplication_rounds_half_up_at_the_requested_scale(): void
    {
        // 2 USDT at 100.50 = 201.00 fiat. This is the fiat total written to
        // every trade, so the scale handling has to be exact.
        $this->assertSame('201.00000000', Money::mul('2', '100.50', 2));

        // 1 at 0.005 → 0.005, which rounds half-up to 0.01 at two places.
        $this->assertSame('0.01000000', Money::mul('1', '0.005', 2));

        // 1 at 0.004 → 0.004, which rounds down to 0.00.
        $this->assertSame('0.00000000', Money::mul('1', '0.004', 2));

        // A fractional crypto amount against a whole price.
        $this->assertSame('50.25000000', Money::mul('0.5', '100.50', 2));
    }

    public function test_multiplication_at_full_scale_keeps_full_precision(): void
    {
        $this->assertSame('0.30000000', Money::mul('0.5', '0.6'));
        $this->assertSame('1.00000000', Money::mul('0.00000001', '100000000'));
    }

    public function test_multiplication_carries_the_sign(): void
    {
        $this->assertSame('-201.00000000', Money::mul('-2', '100.50', 2));
        $this->assertSame('-201.00000000', Money::mul('2', '-100.50', 2));
        $this->assertSame('201.00000000', Money::mul('-2', '-100.50', 2));
        // A negative product that rounds to nothing must not be "-0.00".
        $this->assertSame('0.00000000', Money::mul('-0.0001', '0.0001', 2));
    }

    public function test_multiplication_is_exact_for_large_amounts(): void
    {
        // 1,000,000 USDT at 1,000,000.00 — the product overflows a float's
        // exact integer range but must still be right to the last digit.
        $this->assertSame('1000000000000.00000000', Money::mul('1000000', '1000000.00', 2));
    }

    public function test_comparisons(): void
    {
        $this->assertSame(0, Money::cmp('1.5', '1.5'));
        $this->assertSame(0, Money::cmp('1.50000000', '1.5'));
        $this->assertSame(1, Money::cmp('1.6', '1.5'));
        $this->assertSame(-1, Money::cmp('-1.6', '1.5'));
        $this->assertSame(-1, Money::cmp('-1.6', '-1.5'));

        $this->assertTrue(Money::gt('2', '1.99999999'));
        $this->assertTrue(Money::gte('1.5', '1.5'));
        $this->assertTrue(Money::lt('0.00000001', '0.00000002'));
        $this->assertTrue(Money::lte('1.5', '1.5'));
        $this->assertTrue(Money::eq('10', '10.00000000'));
    }

    public function test_comparisons_are_exact_beyond_float_precision(): void
    {
        // These differ in the 17th significant digit — a float comparison
        // would call them equal.
        $this->assertTrue(Money::gt('10000000000.00000001', '10000000000.00000000'));
        $this->assertTrue(Money::lt('10000000000.00000000', '10000000000.00000001'));
    }

    public function test_sign_helpers(): void
    {
        $this->assertTrue(Money::isZero('0'));
        $this->assertTrue(Money::isZero('0.00000000'));
        $this->assertFalse(Money::isZero('0.00000001'));
        $this->assertFalse(Money::isZero('-0.00000001'));

        $this->assertTrue(Money::isPositive('0.00000001'));
        $this->assertFalse(Money::isPositive('0'));
        $this->assertFalse(Money::isPositive('-1'));

        $this->assertTrue(Money::isNegative('-0.00000001'));
        $this->assertFalse(Money::isNegative('0'));
    }

    public function test_is_positive_is_false_for_a_negative_that_rounds_to_zero(): void
    {
        // A dust amount we cannot represent must not read as a positive credit.
        $this->assertFalse(Money::isPositive('-0.000000001'));
        $this->assertFalse(Money::isPositive('0.000000001'));
    }

    public function test_sum_of_many_amounts(): void
    {
        $this->assertSame('6.00000000', Money::sum('1', '2', '3'));
        $this->assertSame('0.30000000', Money::sum('0.1', '0.2'));
        $this->assertSame('0.00000000', Money::sum());
        $this->assertSame('100.00000000', Money::sum('50', '-50', '100'));
    }

    public function test_it_rejects_scientific_notation_loudly(): void
    {
        // A float that got this far lost precision before it arrived, and
        // silently accepting it is exactly how that goes unnoticed.
        $this->expectException(InvalidArgumentException::class);
        Money::of('1.0E-8');
    }

    public function test_it_rejects_values_beyond_the_column_range(): void
    {
        // decimal(18,8) holds ten integer digits; the eleventh has nowhere to
        // go, and a silent truncation would move real money.
        $this->expectException(InvalidArgumentException::class);
        Money::of('99999999999');
    }

    public function test_it_rejects_garbage(): void
    {
        $this->expectException(InvalidArgumentException::class);
        Money::of('abc');
    }

    public function test_max_amount_is_the_column_limit(): void
    {
        $this->assertSame('9999999999.99999999', Money::maxAmount());
        $this->assertSame(Money::maxAmount(), Money::of(Money::maxAmount()));
    }

    public function test_scale_outside_range_is_rejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        Money::mul('1', '1', 9);
    }

    public function test_escrow_arithmetic_balances_back_to_zero(): void
    {
        // The shape every trade takes: lock, release, and the wallet returns
        // to where it started with nothing left over.
        $available = '100.00000000';
        $escrow = Money::zero();
        $amount = '33.33333333';

        $available = Money::sub($available, $amount);
        $escrow = Money::add($escrow, $amount);

        $escrow = Money::sub($escrow, $amount);
        $available = Money::add($available, $amount);

        $this->assertSame('100.00000000', $available);
        $this->assertTrue(Money::isZero($escrow));
    }
}
