<?php

namespace App\Console\Commands;

use App\Services\P2p\TradeEscrow;
use Illuminate\Console\Command;

/**
 * Close unpaid trades whose payment window has passed.
 *
 * Runs from the scheduler every minute. Without it the escrowed USDT on an
 * abandoned order is stuck: the buyer is gone, and before this the seller had
 * no endpoint to cancel with either.
 */
class ExpireTrades extends Command
{
    protected $signature = 'trades:expire
        {--limit=200 : Maximum number of trades to close in one run}';

    protected $description = 'Cancel unpaid P2P trades past their payment window and refund the escrow';

    public function handle(TradeEscrow $escrow): int
    {
        $limit = max(1, (int) $this->option('limit'));

        $stats = $escrow->cancelExpired($limit);

        // Quiet on a no-op run — this fires every minute and an empty sweep is
        // the normal case, so only report when something actually happened.
        if ($stats['cancelled'] > 0 || $stats['errored'] > 0) {
            $this->info(sprintf(
                'Expired %d trade(s); %d skipped, %d errored.',
                $stats['cancelled'],
                $stats['skipped'],
                $stats['errored']
            ));
        }

        if ($stats['errored'] > 0) {
            // Cancel refunds escrow, so a row that keeps failing is a trade the
            // seller cannot get their USDT back from. Fail the run so it shows
            // up in the scheduler's output rather than scrolling past.
            return self::FAILURE;
        }

        return self::SUCCESS;
    }
}
