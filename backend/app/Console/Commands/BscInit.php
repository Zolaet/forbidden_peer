<?php

namespace App\Console\Commands;

use App\Services\Bsc\Crypto\Keyring;
use App\Services\Bsc\WeiMath;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;

/**
 * Generate a master seed and print the derived treasury address.
 * Writes storage/bsc/seed.txt (0600) unless BSC_SEED_FILE points elsewhere.
 */
class BscInit extends Command
{
    protected $signature = 'bsc:init
        {--print-address : Print the treasury address after seeding}';

    protected $description = 'Generate the BSC master seed file (run once per deployment)';

    public function handle(): int
    {
        $file = (string) config('bsc.seed_file');

        if (is_file($file) && trim((string) file_get_contents($file)) !== '') {
            $this->warn('A seed already exists at ' . $file . '. It was NOT overwritten.');
            $this->line('Keep this file safe and back it up — it controls every deposit address and the treasury.');
        } else {
            File::ensureDirectoryExists(dirname($file));
            $seed = bin2hex(random_bytes(32));
            File::put($file, $seed . PHP_EOL);
            @chmod($file, 0600);
            $this->info('Seed written to ' . $file);
            $this->line('Back this file up. Anyone with it can spend deposited USDT.');
        }

        if ($this->option('print-address')) {
            try {
                $this->info('Treasury address: ' . Keyring::treasuryAddress());
            } catch (\Throwable $e) {
                $this->error('Could not derive treasury address: ' . $e->getMessage());
                return self::FAILURE;
            }
        }

        return self::SUCCESS;
    }
}
