<?php

namespace App\Console\Commands;

use App\Models\Deposit;
use App\Models\User;
use App\Models\Wallet;
use App\Models\WalletTransaction;
use App\Services\Bsc\AddressManager;
use App\Services\Bsc\NetworkConfig;
use App\Services\Bsc\WeiMath;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Dev/test helper: insert a deposit the way the indexer would and run the
 * credit path, WITHOUT touching the chain. Lets the wallet/API/UI flow be
 * tested offline on testnet.
 */
class SimulateDeposit extends Command
{
    protected $signature = 'bsc:simulate-deposit
        {user : The user id receiving the deposit}
        {amount : USDT amount, e.g. 25.5}
        {--pending : Create the row as pending without crediting}';

    protected $description = 'Simulate an inbound deposit (offline, for testing)';

    public function handle(AddressManager $addressManager): int
    {
        $user = User::find($this->argument('user'));
        if (!$user) {
            $this->error('User #' . $this->argument('user') . ' not found.');
            return self::FAILURE;
        }

        $amount = (float) $this->argument('amount');
        $network = NetworkConfig::network();
        $valueRaw = WeiMath::toWei(sprintf('%.8F', $amount));

        // A real deposit lands on the user's own derived address.
        try {
            $cryptoAddress = $addressManager->ensure($user);
        } catch (\Throwable $e) {
            $this->error('No master seed — run `php artisan bsc:init` first. (' . $e->getMessage() . ')');
            return self::FAILURE;
        }

        $txHash = '0x' . bin2hex(random_bytes(32));
        $amount8 = (float) WeiMath::fromWeiFloor($valueRaw, 8);

        DB::transaction(function () use ($user, $network, $cryptoAddress, $amount8, $valueRaw, $txHash, $amount) {
            $deposit = Deposit::create([
                'user_id' => $user->id,
                'network' => $network,
                'deposit_address' => $cryptoAddress->address,
                'from_address' => '0x' . str_repeat('0', 40), // synthetic
                'tx_hash' => $txHash,
                'block_number' => 0,
                'amount' => $amount8,
                'value_raw' => $valueRaw,
                'confirmations' => NetworkConfig::minConfirmations(),
                'status' => Deposit::PENDING,
                'detected_at' => now(),
            ]);

            if ($this->option('pending')) {
                return;
            }

            $wallet = Wallet::firstOrCreate(
                ['user_id' => $user->id, 'currency' => 'USDT'],
                ['available_balance' => 0, 'escrow_balance' => 0]
            );
            $wallet->credit((float) $deposit->amount, WalletTransaction::TYPE_DEPOSIT, $deposit);

            $deposit->update([
                'status' => Deposit::CONFIRMED,
                'credited_at' => now(),
            ]);
        });

        $this->info('Simulated ' . $amount8 . ' USDT deposit for user #' . $user->id
            . ($this->option('pending') ? ' (pending — run bsc:scan-deposits to confirm)' : ' (confirmed & credited)'));
        $this->line('tx_hash: ' . $txHash);

        return self::SUCCESS;
    }
}
