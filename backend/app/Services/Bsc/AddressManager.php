<?php

namespace App\Services\Bsc;

use App\Models\CryptoAddress;
use App\Models\User;
use App\Services\Bsc\Crypto\Keyring;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Hands each user their own stable, unique USDT (BEP-20) deposit address.
 *
 * Addresses are HD children of the platform's master seed — the platform holds
 * the keys server-side, exactly like an exchange. One address per
 * (user, network) is created lazily on first request and reused forever.
 * Treasury is derivation index 0; user deposit addresses start at index 1.
 */
class AddressManager
{
    /**
     * Return the user's deposit address for the current network, creating it
     * if needed. Unique-violation safe under concurrent requests.
     */
    public function ensure(User $user): CryptoAddress
    {
        $network = NetworkConfig::network();
        $currency = 'USDT';

        $existing = CryptoAddress::query()
            ->where('user_id', $user->id)
            ->where('network', $network)
            ->where('currency', $currency)
            ->first();

        if ($existing) {
            return $existing;
        }

        // Pick the next free global-per-network index. Small retry loop covers
        // two racing "first deposit page load" requests.
        for ($attempt = 0; $attempt < 5; $attempt++) {
            $index = (int) CryptoAddress::query()->where('network', $network)->max('derivation_index') + 1;
            if ($index < 1) {
                $index = 1; // index 0 is reserved for the treasury
            }

            $privateKey = Keyring::derivePrivateKey($network, $index);
            $address = Keyring::addressForPrivateKey($privateKey);

            try {
                return CryptoAddress::query()->create([
                    'user_id' => $user->id,
                    'network' => $network,
                    'currency' => $currency,
                    'address' => $address,
                    'derivation_index' => $index,
                ]);
            } catch (QueryException $e) {
                // Unique (user,network,currency)/(network,address)/(network,index)
                // collision → someone else won the index; retry with a fresh one.
                if (!$this->isUniqueViolation($e)) {
                    throw $e;
                }
            }
        }

        throw new RuntimeException('Could not allocate a unique BSC deposit address. Please retry.');
    }

    /** Treasury receives swept deposits and signs all withdrawals. */
    public function treasury(): CryptoAddress
    {
        return new CryptoAddress([
            'user_id' => 0,
            'network' => NetworkConfig::network(),
            'currency' => 'USDT',
            'address' => Keyring::treasuryAddress(),
            'derivation_index' => NetworkConfig::treasuryIndex(),
        ]);
    }

    protected function isUniqueViolation(QueryException $e): bool
    {
        return str_contains($e->getMessage(), 'Duplicate entry')
            || str_contains($e->getMessage(), 'UNIQUE constraint failed');
    }
}
