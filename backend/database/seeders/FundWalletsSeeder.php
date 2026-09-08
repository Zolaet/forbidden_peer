<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;

/**
 * Demo helper: credit every existing user's USDT wallet so they can
 * post sell offers and exercise the escrow flow.
 *
 *   php artisan db:seed --class=FundWalletsSeeder
 *
 * It is ADDITIVE — running it again deposits another AMOUNT per user.
 */
class FundWalletsSeeder extends Seeder
{
    /** Amount of USDT deposited into each user's available balance. */
    public const AMOUNT = 5000;

    public function run(): void
    {
        $credited = 0;

        User::query()->each(function (User $user) use (&$credited) {
            $wallet = $user->wallet()->firstOrCreate(
                ['currency' => 'USDT'],
                ['available_balance' => 0, 'escrow_balance' => 0]
            );

            $wallet->increment('available_balance', self::AMOUNT);
            $credited++;
        });

        $this->command->info(
            sprintf('Credited %s USDT to %d user wallet(s).', number_format(self::AMOUNT, 2), $credited)
        );
    }
}
