<?php

namespace App\Console\Commands;

use App\Services\Bsc\DepositIndexer;
use Illuminate\Console\Command;

class ScanDeposits extends Command
{
    protected $signature = 'bsc:scan-deposits';

    protected $description = 'Scan the chain for inbound USDT (BEP-20) deposits and credit wallets';

    public function handle(DepositIndexer $indexer): int
    {
        $stats = $indexer->run();

        $this->info(sprintf(
            'Scan complete — %d block(s) scanned, %d new deposit(s), %d confirmed & credited.',
            $stats['scanned'],
            $stats['new'],
            $stats['confirmed']
        ));

        // The indexer swallows its own exceptions so a bad block doesn't stop
        // the whole sweep, which means a failing scan used to exit 0 and look
        // healthy to cron mail and to any `|| alert` wrapper while deposits
        // went uncredited. It reports the failures in $stats instead; this is
        // what turns them into a non-zero exit.
        if (($stats['errors'] ?? 0) > 0) {
            $this->error(sprintf(
                'Scan reported %d error(s) — see the log. Deposits may be uncredited.',
                $stats['errors']
            ));

            return self::FAILURE;
        }

        return self::SUCCESS;
    }
}
