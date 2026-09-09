<?php

/*
|--------------------------------------------------------------------------
| BSC (BNB Smart Chain) — USDT/BEP-20 deposit & withdrawal config
|--------------------------------------------------------------------------
|
| Everything chain-related is network-driven. Switch BSC_NETWORK to 'mainnet'
| when the platform is ready for real funds — RPC URLs, chain id, contract and
| explorer all follow, so no code changes are required.
*/

return [

    // 'testnet' | 'mainnet'
    'network' => env('BSC_NETWORK', 'testnet'),

    'networks' => [
        'testnet' => [
            'chain_id'      => 97,
            'rpc_urls'      => array_values(array_filter(array_map('trim', explode(',', (string) env(
                'BSC_TESTNET_RPC_URLS',
                'https://bsc-testnet-rpc.publicnode.com,https://data-seed-prebsc-1-s1.binance.org:8545/,https://data-seed-prebsc-2-s1.binance.org:8545/'
            ))))),
            // Must match the test USDT you actually fund from a faucet.
            'usdt_contract' => strtolower((string) env('BSC_TESTNET_USDT_CONTRACT', '')),
            'explorer_tx'   => env('BSC_TESTNET_EXPLORER', 'https://testnet.bscscan.com/tx/'),
            'token_label'   => env('BSC_TESTNET_TOKEN_LABEL', 'USDT (testnet)'),
        ],

        'mainnet' => [
            'chain_id'      => 56,
            'rpc_urls'      => array_values(array_filter(array_map('trim', explode(',', (string) env(
                'BSC_MAINNET_RPC_URLS',
                'https://bsc-dataseed1.binance.org,https://bsc-dataseed2.binance.org,https://bsc-rpc.publicnode.com'
            ))))),
            // Binance-Pegged USDT (BEP-20).
            'usdt_contract' => strtolower((string) env('BSC_MAINNET_USDT_CONTRACT', '0x55d398326f99059ff775485246999027b3197955')),
            'explorer_tx'   => env('BSC_MAINNET_EXPLORER', 'https://bscscan.com/tx/'),
            'token_label'   => env('BSC_MAINNET_TOKEN_LABEL', 'USDT'),
        ],
    ],

    // Raw hex seed (32 bytes recommended: `openssl rand -hex 32`), written by
    // `php artisan bsc:init` into a gitignored file — never commit it. Kept out
    // of .env so a shared .env can't accidentally carry a production seed.
    // (`?:` — an empty BSC_SEED_FILE falls back to the storage default.)
    'seed_file' => env('BSC_SEED_FILE') ?: storage_path('bsc/seed.txt'),

    // HD index of the account that holds USDT + BNB and pays withdrawals.
    'treasury_index' => (int) env('BSC_TREASURY_INDEX', 0),

    // Confirmations before a detected deposit is credited to a wallet.
    'min_confirmations' => (int) env('BSC_MIN_CONFIRMATIONS', 15),

    // Blocks to re-scan from when the indexer has no resume point yet.
    'scan_lookback' => (int) env('BSC_SCAN_LOOKBACK', 200),

    'withdrawal_min' => (float) env('WITHDRAWAL_MIN_USDT', 1),
    'withdrawal_fee' => (float) env('WITHDRAWAL_FEE_USDT', 0),

    // BEP-20 transfer() needs ~50–80k gas; leave headroom.
    'transfer_gas_limit' => (int) env('BSC_TRANSFER_GAS_LIMIT', 100000),

    'decimals' => 18, // Binance-Pegged USDT is an 18-decimal token
];
