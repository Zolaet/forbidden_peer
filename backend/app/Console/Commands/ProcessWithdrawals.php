<?php

namespace App\Console\Commands;

use App\Services\Bsc\WithdrawalService;
use Illuminate\Console\Command;

class ProcessWithdrawals extends Command
{
    protected $signature = 'bsc:process-withdrawals';

    protected $description = 'Broadcast / finish withdrawals left pending by a previous run';

    public function handle(WithdrawalService $service): int
    {
        $counts = $service->processPending();

        $this->info(sprintf(
            'Pending processed — requested: %d, sent: %d, failed: %d, needs review: %d, still pending: %d.',
            $counts['requested'],
            $counts['sent'],
            $counts['failed'],
            $counts['needs_review'],
            $counts['still_pending']
        ));

        // A row needing review may already be on chain with the user's money
        // debited for it. That is not a run to report as healthy.
        return $counts['needs_review'] > 0 ? self::FAILURE : self::SUCCESS;
    }
}
