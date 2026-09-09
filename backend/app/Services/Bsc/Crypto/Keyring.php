<?php

namespace App\Services\Bsc\Crypto;

use App\Services\Bsc\NetworkConfig;
use RuntimeException;

/**
 * Deterministic per-user keys derived from the platform's master seed.
 *
 * The master seed is a plain 32-byte hex string (gitignored file). Child keys
 * are HMAC-SHA512(seed, network:index) truncated to 32 bytes — deterministic
 * and namespaced per network so testnet and mainnet never share addresses.
 * (Not BIP32-standard, so keys aren't importable to an external HD wallet; a
 * BIP32/BIP39 path can be layered on later without changing anything else.)
 */
class Keyring
{
    public static function seedHex(): string
    {
        $file = NetworkConfig::seedFile();
        if (!is_file($file)) {
            throw new RuntimeException(
                'No BSC master seed found at ' . $file . '. Run `php artisan bsc:init` to generate one, ' .
                'or point BSC_SEED_FILE at a file containing a 64-char hex seed (openssl rand -hex 32).'
            );
        }

        $seed = strtolower(trim((string) file_get_contents($file)));
        if (!preg_match('/^[0-9a-f]{64,128}$/', $seed)) {
            throw new RuntimeException(
                'BSC seed file must contain a raw hex seed (32–64 bytes). It cannot be a mnemonic yet.'
            );
        }

        return $seed;
    }

    /** Private key (64 hex chars) for a user's deposit address. */
    public static function derivePrivateKey(string $network, int $index): string
    {
        $seed = hex2bin(self::seedHex());
        $key = hash_hmac('sha512', $network . ':' . $index, $seed, true);

        return bin2hex(substr($key, 0, 32));
    }

    /** The account used to fund & sign withdrawals. */
    public static function treasuryPrivateKey(): string
    {
        return self::derivePrivateKey(NetworkConfig::network(), NetworkConfig::treasuryIndex());
    }

    public static function addressForPrivateKey(string $privateKeyHex): string
    {
        return EthereumCrypto::addressFromPrivateKey($privateKeyHex);
    }

    public static function treasuryAddress(): string
    {
        return self::addressForPrivateKey(self::treasuryPrivateKey());
    }
}
