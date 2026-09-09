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

        return self::SUCCESS;
    }
}
