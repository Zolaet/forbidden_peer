<?php

namespace App\Support;

use InvalidArgumentException;

/**
 * Exact decimal arithmetic for money — strings in, strings out, never floats.
 *
 * Every balance column in the ledger is decimal(18,8). PHP floats cannot hold
 * those exactly, so `$balance += $amount` drifts: `0.1 + 0.2 !== 0.3`, and
 * after enough trades a wallet's escrow total stops equalling the sum of the
 * trades holding it. That is the kind of discrepancy that only shows up when
 * someone is owed money, so every balance movement goes through here instead.
 *
 * Amounts are plain decimal strings ("12.5", "0.00000001"). The canonical form
 * is exactly SCALE decimal places, matching the wallet columns. Arithmetic
 * runs on scaled integers held as digit strings, multiplied nine digits at a
 * time so every intermediate product stays inside a 64-bit int — the same
 * reason the chain-side WeiMath never touches a float.
 *
 * Fractions longer than SCALE are truncated (floored toward zero), never
 * rounded up: crediting a user more than they actually sent is the wrong
 * direction to be wrong in. This matches WeiMath::fromWeiFloor.
 */
final class Money
{
    /** Decimal places held by every wallet/ledger column. */
    public const SCALE = 8;

    /** Total digits in decimal(18,8) — the widest column the ledger uses. */
    private const PRECISION = 18;

    /** Integer digits available in decimal(18,8). */
    private const MAX_INT_DIGITS = 10;

    private const LIMB_BASE = 1000000000;
    private const LIMB_DIGITS = 9;

    private function __construct()
    {
    }

    public static function zero(): string
    {
        return '0.' . str_repeat('0', self::SCALE);
    }

    /**
     * Canonicalize an amount to exactly SCALE decimal places.
     *
     * @throws InvalidArgumentException on anything that isn't a plain decimal
     *                                  (scientific notation included — a float
     *                                  that reached this far is a bug at the
     *                                  call site, and silently accepting it is
     *                                  how precision gets lost).
     */
    public static function of(string|int|float $amount): string
    {
        return self::format(self::scaled($amount));
    }

    /** Exact sum of any number of amounts. */
    public static function sum(string|int|float ...$amounts): string
    {
        $total = self::zero();

        foreach ($amounts as $amount) {
            $total = self::add($total, $amount);
        }

        return $total;
    }

    public static function add(string|int|float $a, string|int|float $b): string
    {
        return self::format(self::addScaled(self::scaled($a), self::scaled($b)));
    }

    public static function sub(string|int|float $a, string|int|float $b): string
    {
        return self::format(self::addScaled(self::scaled($a), self::negateScaled(self::scaled($b))));
    }

    public static function negate(string|int|float $a): string
    {
        return self::format(self::negateScaled(self::scaled($a)));
    }

    /** Absolute value — e.g. for a ledger delta whose sign is carried elsewhere. */
    public static function abs(string|int|float $a): string
    {
        return self::format(ltrim(self::scaled($a), '-'));
    }

    /**
     * Multiply two amounts, rounding the result half-up to `$scale` places.
     *
     * Used for `fiat_total = crypto_amount * unit_price`, where the exact
     * product carries more precision than the fiat column can hold.
     */
    public static function mul(string|int|float $a, string|int|float $b, int $scale = self::SCALE): string
    {
        if ($scale < 0 || $scale > self::SCALE) {
            throw new InvalidArgumentException('Scale must be between 0 and ' . self::SCALE . '.');
        }

        $aScaled = self::scaled($a);
        $bScaled = self::scaled($b);

        $aNegative = str_starts_with($aScaled, '-');
        $bNegative = str_starts_with($bScaled, '-');
        $aMagnitude = ltrim($aNegative ? substr($aScaled, 1) : $aScaled, '0');
        $bMagnitude = ltrim($bNegative ? substr($bScaled, 1) : $bScaled, '0');

        $product = self::mulMagnitudes($aMagnitude, $bMagnitude);

        // Both operands were scaled by SCALE, so the product is scaled by
        // SCALE*2. Bring it back to the requested scale.
        $shift = (2 * self::SCALE) - $scale;
        [$quotient, $remainder] = self::splitAt($product, $shift);

        if ($remainder !== '0' && self::compareMagnitudes($remainder, self::halfAt($shift)) >= 0) {
            $quotient = self::addMagnitudes($quotient, '1');
        }

        $negative = ($aNegative !== $bNegative) && ltrim($quotient, '0') !== '';

        // $quotient is now scaled by $scale, but format() reads SCALE. Repad so
        // the value keeps its meaning: a 2-dp fiat total of 201 stays 201.00,
        // rather than coming back as 0.00020100.
        $repadded = $quotient . str_repeat('0', self::SCALE - $scale);

        // A narrower scale leaves more room above the point: `fiat_amount` is
        // decimal(18,2), so it holds six more integer digits than a
        // decimal(18,8) balance does. Checking it against the 8-dp limit would
        // reject sums that the column can hold perfectly well.
        return self::format(($negative ? '-' : '') . $repadded, self::PRECISION - $scale);
    }

    /** -1, 0 or 1. */
    public static function cmp(string|int|float $a, string|int|float $b): int
    {
        return self::compareSigned(self::scaled($a), self::scaled($b));
    }

    public static function eq(string|int|float $a, string|int|float $b): bool
    {
        return self::cmp($a, $b) === 0;
    }

    public static function gt(string|int|float $a, string|int|float $b): bool
    {
        return self::cmp($a, $b) > 0;
    }

    public static function gte(string|int|float $a, string|int|float $b): bool
    {
        return self::cmp($a, $b) >= 0;
    }

    public static function lt(string|int|float $a, string|int|float $b): bool
    {
        return self::cmp($a, $b) < 0;
    }

    public static function lte(string|int|float $a, string|int|float $b): bool
    {
        return self::cmp($a, $b) <= 0;
    }

    public static function isZero(string|int|float $a): bool
    {
        return ltrim(self::scaled($a), '-0') === '';
    }

    public static function isPositive(string|int|float $a): bool
    {
        return !str_starts_with(self::scaled($a), '-') && !self::isZero($a);
    }

    public static function isNegative(string|int|float $a): bool
    {
        return str_starts_with(self::scaled($a), '-') && !self::isZero($a);
    }

    /**
     * The largest amount a decimal(18,8) column can hold, as a canonical
     * string. Used to reject an amount before the database silently drops it.
     */
    public static function maxAmount(): string
    {
        return str_repeat('9', self::MAX_INT_DIGITS) . '.' . str_repeat('9', self::SCALE);
    }

    /* ---------------------------------------------------------------------
     | Scaled-integer representation
     |
     | An amount becomes a signed digit string with the point removed:
     | "1.5" -> "150000000". Every routine below works on those, and `format`
     | is the single place that puts the point back.
     --------------------------------------------------------------------- */

    private static function scaled(string|int|float $amount): string
    {
        $raw = trim((string) $amount);

        if ($raw === '' || $raw === '-' || $raw === '+') {
            throw new InvalidArgumentException('Not a money amount: "' . $raw . '".');
        }

        // A float large or small enough to stringify in scientific notation is
        // a float that should not have been passed at all. Fail loudly.
        if (stripos($raw, 'e') !== false) {
            throw new InvalidArgumentException(
                'Money amounts must be plain decimals — got "' . $raw
                . '". Convert the value to a string at the source instead of passing a float.'
            );
        }

        if (!preg_match('/^[+-]?\d*(?:\.\d*)?$/', $raw) || !preg_match('/\d/', $raw)) {
            throw new InvalidArgumentException('Not a money amount: "' . $raw . '".');
        }

        $negative = str_starts_with($raw, '-');
        $raw = ltrim($raw, '+-');

        [$integer, $fraction] = array_pad(explode('.', $raw, 2), 2, '');

        // Truncate below SCALE (dust we cannot represent) and pad to width.
        $integer = ltrim($integer, '0');
        $fraction = str_pad(substr($fraction, 0, self::SCALE), self::SCALE, '0');

        $digits = ltrim($integer . $fraction, '0');

        if ($digits === '') {
            return '0';
        }

        return $negative ? '-' . $digits : $digits;
    }

    /**
     * Put the decimal point back, refusing values the target column cannot hold.
     *
     * $maxIntDigits is how many digits may sit above the point: ten for a
     * decimal(18,8) balance, more for a narrower scale such as the 2-dp fiat
     * columns. Overflowing silently would let the database truncate real money.
     */
    private static function format(string $scaled, ?int $maxIntDigits = null): string
    {
        $maxIntDigits ??= self::MAX_INT_DIGITS;

        $negative = str_starts_with($scaled, '-');
        $digits = ltrim($negative ? substr($scaled, 1) : $scaled, '0');

        if ($digits === '') {
            return self::zero();
        }

        if (strlen($digits) > self::SCALE + $maxIntDigits) {
            throw new InvalidArgumentException(
                'Amount exceeds the decimal(18,' . self::SCALE . ') column range: ' . $scaled . '.'
            );
        }

        $digits = str_pad($digits, self::SCALE + 1, '0', STR_PAD_LEFT);

        return ($negative ? '-' : '')
            . substr($digits, 0, -self::SCALE)
            . '.'
            . substr($digits, -self::SCALE);
    }

    /* ---------------------------------------------------------------------
     | Signed digit-string arithmetic (magnitudes, no point)
     --------------------------------------------------------------------- */

    private static function negateScaled(string $scaled): string
    {
        if ($scaled === '0') {
            return '0';
        }

        return str_starts_with($scaled, '-') ? substr($scaled, 1) : '-' . $scaled;
    }

    private static function addScaled(string $a, string $b): string
    {
        $aNegative = str_starts_with($a, '-');
        $bNegative = str_starts_with($b, '-');
        $aMagnitude = ltrim($aNegative ? substr($a, 1) : $a, '0');
        $bMagnitude = ltrim($bNegative ? substr($b, 1) : $b, '0');

        if ($aMagnitude === '' && $bMagnitude === '') {
            return '0';
        }
        if ($aMagnitude === '') {
            return $bNegative ? '-' . $bMagnitude : $bMagnitude;
        }
        if ($bMagnitude === '') {
            return $aNegative ? '-' . $aMagnitude : $aMagnitude;
        }

        // Same sign: add magnitudes and keep the sign.
        if ($aNegative === $bNegative) {
            return ($aNegative ? '-' : '') . self::addMagnitudes($aMagnitude, $bMagnitude);
        }

        // Opposite signs: the larger magnitude wins, the smaller is taken off it.
        $cmp = self::compareMagnitudes($aMagnitude, $bMagnitude);

        if ($cmp === 0) {
            return '0';
        }

        if ($cmp > 0) {
            return ($aNegative ? '-' : '') . self::subMagnitudes($aMagnitude, $bMagnitude);
        }

        return ($bNegative ? '-' : '') . self::subMagnitudes($bMagnitude, $aMagnitude);
    }

    private static function compareSigned(string $a, string $b): int
    {
        $aNegative = str_starts_with($a, '-');
        $bNegative = str_starts_with($b, '-');

        if ($aNegative !== $bNegative) {
            return $aNegative ? -1 : 1;
        }

        $cmp = self::compareMagnitudes(ltrim($a, '-0'), ltrim($b, '-0'));

        return $aNegative ? -$cmp : $cmp;
    }

    /** Both operands unsigned. */
    private static function compareMagnitudes(string $a, string $b): int
    {
        $a = ltrim($a, '0');
        $b = ltrim($b, '0');

        if (strlen($a) !== strlen($b)) {
            return strlen($a) <=> strlen($b);
        }

        // strcmp, not <=>: PHP would compare two integer-like strings
        // numerically, and beyond PHP_INT_MAX that goes through a float.
        return strcmp($a, $b) <=> 0;
    }

    /** Both operands unsigned. */
    private static function addMagnitudes(string $a, string $b): string
    {
        $a = ltrim($a, '0');
        $b = ltrim($b, '0');

        if ($a === '') {
            return $b === '' ? '0' : $b;
        }
        if ($b === '') {
            return $a;
        }

        $aLen = strlen($a);
        $bLen = strlen($b);
        $length = max($aLen, $bLen);

        $carry = 0;
        $result = '';

        for ($i = 0; $i < $length; $i++) {
            $sum = ($i < $aLen ? (int) $a[$aLen - 1 - $i] : 0)
                + ($i < $bLen ? (int) $b[$bLen - 1 - $i] : 0)
                + $carry;

            $result = ($sum % 10) . $result;
            $carry = intdiv($sum, 10);
        }

        return $carry > 0 ? $carry . $result : $result;
    }

    /** Unsigned. Caller must guarantee $a >= $b. */
    private static function subMagnitudes(string $a, string $b): string
    {
        $a = ltrim($a, '0');
        $b = ltrim($b, '0');

        if ($b === '') {
            return $a === '' ? '0' : $a;
        }

        $aLen = strlen($a);
        $bLen = strlen($b);

        $borrow = 0;
        $result = '';

        for ($i = 0; $i < $aLen; $i++) {
            $digit = (int) $a[$aLen - 1 - $i]
                - ($i < $bLen ? (int) $b[$bLen - 1 - $i] : 0)
                - $borrow;

            if ($digit < 0) {
                $digit += 10;
                $borrow = 1;
            } else {
                $borrow = 0;
            }

            $result = $digit . $result;
        }

        return ltrim($result, '0') ?: '0';
    }

    /**
     * Schoolbook multiplication of two unsigned digit strings.
     *
     * Both operands are chunked into 9-digit limbs first, so the widest
     * intermediate is 9 digits × 9 digits ≈ 1e18 — under PHP_INT_MAX (≈9.2e18)
     * even after the running carry is added.
     */
    private static function mulMagnitudes(string $a, string $b): string
    {
        $a = ltrim($a, '0');
        $b = ltrim($b, '0');

        if ($a === '' || $b === '') {
            return '0';
        }

        $aLimbs = self::toLimbs($a);
        $bLimbs = self::toLimbs($b);
        $bCount = count($bLimbs);

        $out = array_fill(0, count($aLimbs) + $bCount, 0);

        foreach ($aLimbs as $i => $x) {
            $carry = 0;

            foreach ($bLimbs as $j => $y) {
                $current = $out[$i + $j] + $x * $y + $carry;
                $out[$i + $j] = $current % self::LIMB_BASE;
                $carry = intdiv($current, self::LIMB_BASE);
            }

            for ($k = $i + $bCount; $carry > 0; $k++) {
                $current = $out[$k] + $carry;
                $out[$k] = $current % self::LIMB_BASE;
                $carry = intdiv($current, self::LIMB_BASE);
            }
        }

        return self::fromLimbs($out);
    }

    /** Least-significant limb first. */
    private static function toLimbs(string $digits): array
    {
        $limbs = [];

        for ($i = strlen($digits); $i > 0; $i -= self::LIMB_DIGITS) {
            $start = max(0, $i - self::LIMB_DIGITS);
            $limbs[] = (int) substr($digits, $start, $i - $start);
        }

        return $limbs;
    }

    private static function fromLimbs(array $limbs): string
    {
        $last = count($limbs) - 1;
        $out = '';

        for ($i = $last; $i >= 0; $i--) {
            $out .= $i === $last
                ? (string) $limbs[$i]
                : str_pad((string) $limbs[$i], self::LIMB_DIGITS, '0', STR_PAD_LEFT);
        }

        return ltrim($out, '0') ?: '0';
    }

    /**
     * Split an unsigned digit string at `$shift` digits from the right.
     * Returns [digits above the split, the low `$shift` digits zero-padded].
     */
    private static function splitAt(string $digits, int $shift): array
    {
        $digits = ltrim($digits, '0');

        if ($shift <= 0) {
            return [$digits === '' ? '0' : $digits, '0'];
        }

        if ($digits === '' || strlen($digits) <= $shift) {
            return ['0', str_pad($digits, $shift, '0', STR_PAD_LEFT)];
        }

        return [
            substr($digits, 0, -$shift),
            substr($digits, -$shift),
        ];
    }

    /** The half-way value for a split at `$shift`: 5 followed by zeros. */
    private static function halfAt(int $shift): string
    {
        return $shift <= 0 ? '0' : '5' . str_repeat('0', $shift - 1);
    }
}
