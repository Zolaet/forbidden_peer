<?php

namespace App\Console\Commands;

use App\Services\Bsc\SweepService;
use Illuminate\Console\Command;

class SweepDeposits extends Command
{
    protected $signature = 'bsc:sweep {--limit=25 : Max deposit addresses to check}';

    protected $description = 'Forward USDT from deposit addresses into the treasury (needs BNB gas per address)';

    public function handle(SweepService $service): int
    {
        $stats = $service->run((int) $this->option('limit'));

        $this->info(sprintf(
            'Sweep complete — swept: %d, empty: %d, errors: %d.',
            $stats['swept'],
            $stats['empty'],
            $stats['errors']
        ));

        if ($stats['swept'] === 0 && $stats['errors'] === 0) {
            $this->line('No balances to sweep (or nothing has been deposited yet).');
        }

        return self::SUCCESS;
    }
}
