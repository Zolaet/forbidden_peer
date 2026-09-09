<?php

namespace App\Services\Bsc;

use RuntimeException;

/**
 * Exact decimal<->token-amount<->hex arithmetic done with strings, never
 * floats, so balances survive the 18-decimal jump between the internal 8-dp
 * wallet columns and the chain.
 */
class WeiMath
{
    public static function decimals(): int
    {
        return NetworkConfig::decimals();
    }

    /**
     * Convert a token amount (decimal string/number) into a wei decimal string.
     * Fractions longer than `decimals` are truncated (floored).
     */
    public static function toWei(string|int|float $amount, ?int $decimals = null): string
    {
        $decimals ??= self::decimals();

        $raw = trim((string) $amount);
        if ($raw === '' || $raw === '-') {
            throw new RuntimeException('Invalid amount: ' . $raw);
        }

        // Guard against scientific notation like "1.0E-8".
        if (stripos($raw, 'e') !== false) {
            $raw = rtrim(rtrim(sprintf('%.' . $decimals . 'F', (float) $raw), '0'), '.');
        }

        $negative = str_starts_with($raw, '-');
        $raw = ltrim($raw, '+- ');

        [$intPart, $fracPart] = array_pad(explode('.', $raw, 2), 2, '');
        $fracPart = substr($fracPart, 0, $decimals);           // truncate excess
        $fracPart = str_pad($fracPart, $decimals, '0');        // pad to 18
        $intPart = $intPart === '' ? '0' : $intPart;

        $wei = self::strAdd(self::mulPow10($intPart, $decimals), $fracPart);

        return $negative && $wei !== '0' ? '-' . $wei : $wei;
    }

    /**
     * Convert a wei decimal string to a token-amount decimal string, floored
     * to `$dp` decimal places (default 8, matching the wallet columns).
     */
    public static function fromWeiFloor(string $wei, int $dp = 8, ?int $decimals = null): string
    {
        $decimals ??= self::decimals();
        $negative = str_starts_with($wei, '-');
        $wei = ltrim($wei, '-');

        $len = strlen($wei);
        $intPart = $len > $decimals ? substr($wei, 0, $len - $decimals) : '0';
        $fracPart = $len > $decimals
            ? substr($wei, $len - $decimals)
            : str_pad($wei, $decimals, '0', STR_PAD_LEFT);

        $frac = rtrim(substr($fracPart, 0, $dp), '0');
        $out = $frac === '' ? $intPart : $intPart . '.' . $frac;

        return $negative && $out !== '0' ? '-' . $out : $out;
    }

    /** Convenience: wei -> float token amount (already floored to 8 dp). */
    public static function toTokenFloat(string $wei): float
    {
        return (float) self::fromWeiFloor($wei);
    }

    /** Decode a hex quantity (e.g. log value, block number) to a decimal string. */
    public static function hexToDec(string $hex): string
    {
        $hex = ltrim(strtolower((string) $hex), " \t\n\r\0\x0B");
        if (str_starts_with($hex, '0x')) {
            $hex = substr($hex, 2);
        }
        $hex = ltrim($hex, '0');
        if ($hex === '') {
            return '0';
        }

        $out = '0';
        foreach (str_split($hex) as $char) {
            $digit = hexdec($char);
            $out = self::strAdd(self::mulSmall($out, 16), (string) $digit);
        }

        return $out;
    }

    /** Encode a decimal string as hex (no 0x prefix, lower-case). */
    public static function decToHex(string $dec): string
    {
        $dec = ltrim($dec, '0') ?: '0';
        if ($dec === '0') {
            return '0';
        }

        $hex = '';
        $n = $dec;
        while ($n !== '0') {
            $r = (int) self::modSmall($n, 16);
            $hex = dechex($r) . $hex;
            $n = self::divSmall($n, 16);
        }

        return $hex;
    }

    /** Pad a hex quantity to `$bytes` bytes (64 hex chars for 32 bytes). */
    public static function padHex(string $hex, int $bytes): string
    {
        $hex = ltrim(strtolower((string) $hex), '0');
        return str_pad($hex, $bytes * 2, '0', STR_PAD_LEFT);
    }

    /* --------------------------- string math --------------------------- */

    private static function mulPow10(string $s, int $pow): string
    {
        if ($pow <= 0) {
            return $s;
        }
        return $s . str_repeat('0', $pow);
    }

    private static function mulSmall(string $a, int $m): string
    {
        $a = ltrim($a, '0') ?: '0';
        if ($a === '0' || $m === 0) {
            return '0';
        }
        $carry = 0;
        $res = '';
        foreach (array_reverse(str_split($a)) as $digit) {
            $prod = ((int) $digit) * $m + $carry;
            $res = ($prod % 10) . $res;
            $carry = intdiv($prod, 10);
        }
        while ($carry > 0) {
            $res = ($carry % 10) . $res;
            $carry = intdiv($carry, 10);
        }
        return $res;
    }

    private static function divSmall(string $a, int $d): string
    {
        $carry = 0;
        $res = '';
        foreach (str_split($a) as $digit) {
            $cur = $carry * 10 + (int) $digit;
            $res .= intdiv($cur, $d);
            $carry = $cur % $d;
        }
        return ltrim($res, '0') ?: '0';
    }

    private static function modSmall(string $a, int $m): int
    {
        $carry = 0;
        foreach (str_split($a) as $digit) {
            $carry = ($carry * 10 + (int) $digit) % $m;
        }
        return $carry;
    }

    private static function strAdd(string $a, string $b): string
    {
        $a = ltrim($a, '0') ?: '0';
        $b = ltrim($b, '0') ?: '0';
        $aLen = strlen($a);
        $bLen = strlen($b);
        $len = max($aLen, $bLen);

        $carry = 0;
        $res = '';
        for ($i = 0; $i < $len; $i++) {
            $ad = $i < $aLen ? (int) $a[$aLen - 1 - $i] : 0;
            $bd = $i < $bLen ? (int) $b[$bLen - 1 - $i] : 0;
            $sum = $ad + $bd + $carry;
            $res = ($sum % 10) . $res;
            $carry = intdiv($sum, 10);
        }
        if ($carry > 0) {
            $res = $carry . $res;
        }
        return ltrim($res, '0') ?: '0';
    }
}
