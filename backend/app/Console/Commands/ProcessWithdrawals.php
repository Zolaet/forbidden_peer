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
            'Pending processed — requested: %d, sent: %d, failed: %d, still pending: %d.',
            $counts['requested'],
            $counts['sent'],
            $counts['failed'],
            $counts['still_pending']
        ));

        return self::SUCCESS;
    }
}
