<?php

namespace App\Services\Bsc;

/**
 * Resolves which BSC network we're on and its presets, straight from
 * config/bsc.php (which reads .env).
 */
class NetworkConfig
{
    public static function network(): string
    {
        return (string) config('bsc.network', 'testnet');
    }

    public static function preset(?string $network = null): array
    {
        return (array) (config('bsc.networks.' . ($network ?? self::network())) ?? []);
    }

    public static function chainId(): int
    {
        return (int) (self::preset()['chain_id'] ?? 0);
    }

    public static function rpcUrls(): array
    {
        return array_values((array) (self::preset()['rpc_urls'] ?? []));
    }

    /** Lower-cased token contract for the current network ('' if unset). */
    public static function usdtContract(): string
    {
        return strtolower((string) (self::preset()['usdt_contract'] ?? ''));
    }

    public static function explorerTx(): string
    {
        return (string) (self::preset()['explorer_tx'] ?? '');
    }

    public static function tokenLabel(): string
    {
        return (string) (self::preset()['token_label'] ?? 'USDT');
    }

    public static function treasuryIndex(): int
    {
        return (int) config('bsc.treasury_index', 0);
    }

    public static function minConfirmations(): int
    {
        return (int) config('bsc.min_confirmations', 15);
    }

    public static function scanLookback(): int
    {
        return (int) config('bsc.scan_lookback', 200);
    }

    public static function decimals(): int
    {
        return (int) config('bsc.decimals', 18);
    }

    public static function seedFile(): string
    {
        return (string) config('bsc.seed_file');
    }

    public static function withdrawalFee(): float
    {
        return (float) config('bsc.withdrawal_fee', 0);
    }

    public static function withdrawalMin(): float
    {
        return (float) config('bsc.withdrawal_min', 1);
    }

    public static function transferGasLimit(): int
    {
        return (int) config('bsc.transfer_gas_limit', 100000);
    }
}
