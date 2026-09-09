<?php

namespace App\Services\Bsc\Crypto;

use Elliptic\EC;
use kornrunner\Keccak;
use RuntimeException;

/**
 * Thin wrapper around the pure-PHP secp256k1 (`simplito/elliptic-php`) and
 * keccak (`kornrunner/keccak`) primitives. Every low-level crypto call the
 * platform makes goes through here so it can be verified in one place
 * (`php artisan bsc:doctor`).
 */
class EthereumCrypto
{
    private static ?EC $ec = null;

    private static function ec(): EC
    {
        return self::$ec ??= new EC('secp256k1');
    }

    /** Accepts a plain 0x-prefixed 20-byte address (checksum-tolerant). */
    public static function isAddress(string $address): bool
    {
        return (bool) preg_match('/^0x[0-9a-fA-F]{40}$/', $address);
    }

    /** Lower-case the address for storage/lookups. */
    public static function normalizeAddress(string $address): string
    {
        return strtolower($address);
    }

    /** Uncompressed public key as hex ("04" . x . y, 130 chars). */
    public static function publicKeyHex(string $privateKeyHex): string
    {
        $key = self::ec()->keyFromPrivate($privateKeyHex, 'hex');
        $public = $key->getPublic('hex');

        if (!is_string($public) || strlen($public) !== 130 || !str_starts_with($public, '04')) {
            throw new RuntimeException('Unexpected public key encoding from secp256k1 library.');
        }

        return strtolower($public);
    }

    /** Derive the EIP-55-compatible address (lower-cased) from a private key. */
    public static function addressFromPrivateKey(string $privateKeyHex): string
    {
        $public = self::publicKeyHex($privateKeyHex);
        $raw = hex2bin(substr($public, 2)); // x || y (64 raw bytes)

        return '0x' . substr(self::keccakHex($raw), -40);
    }

    public static function keccakHex(string $rawBytes): string
    {
        return strtolower((string) Keccak::hash($rawBytes, 256));
    }

    /**
     * ECDSA-sign a 32-byte digest. Returns canonical r/s (64-hex each) plus
     * the recovery id (0|1) so the v value can be derived for the chain.
     */
    public static function signDigest(string $digestHex, string $privateKeyHex): array
    {
        if (strlen($digestHex) !== 64) {
            throw new RuntimeException('Digest must be exactly 32 bytes.');
        }

        $key = self::ec()->keyFromPrivate($privateKeyHex, 'hex');
        $signature = self::ec()->sign($digestHex, $key, ['canonical' => true]);

        $r = str_pad((string) $signature->r->toString(16), 64, '0', STR_PAD_LEFT);
        $s = str_pad((string) $signature->s->toString(16), 64, '0', STR_PAD_LEFT);

        return [
            'r' => $r,
            's' => $s,
            'recovery' => (int) ($signature->recoveryParam ?? 0),
        ];
    }
}
