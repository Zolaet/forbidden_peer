<?php

namespace App\Console\Commands;

use App\Services\Bsc\Crypto\EthTxSigner;
use App\Services\Bsc\Crypto\EthereumCrypto;
use App\Services\Bsc\Crypto\Keyring;
use App\Services\Bsc\WeiMath;
use Illuminate\Console\Command;

/**
 * Offline sanity check for the whole crypto stack: required PHP extensions,
 * installed packages, and known-good vectors for keccak, secp256k1 addresses
 * and EIP-155 signing. Run this before wiring up real deposits/withdrawals.
 */
class BscDoctor extends Command
{
    protected $signature = 'bsc:doctor';

    protected $description = 'Verify the BSC crypto stack (keccak, secp256k1, EIP-155, seed)';

    private int $failures = 0;

    public function handle(): int
    {
        $this->checkExtensions();
        $this->checkPackages();
        $this->checkKeccak();
        $this->checkAddressDerivation();
        $this->checkEip155Signing();
        $this->checkWeiMath();
        $this->checkSeed();

        if ($this->failures === 0) {
            $this->newLine();
            $this->info('✓ bsc:doctor — all checks passed.');
            return self::SUCCESS;
        }

        $this->newLine();
        $this->error('✗ bsc:doctor — ' . $this->failures . ' check(s) failed.');
        return self::FAILURE;
    }

    protected function checkExtensions(): void
    {
        $need = ['openssl'];
        $missing = [];
        foreach ($need as $ext) {
            if (!extension_loaded($ext)) {
                $missing[] = $ext;
            }
        }

        $bigmath = extension_loaded('gmp') || extension_loaded('bcmath');
        if (!$bigmath) {
            $missing[] = 'gmp OR bcmath';
        }

        $this->passFail('Required PHP extensions', $missing === [], $missing ? 'missing: ' . implode(', ', $missing) : 'openssl, gmp/bcmath present');
    }

    protected function checkPackages(): void
    {
        $ok = class_exists(\Elliptic\EC::class) && class_exists(\kornrunner\Keccak::class);
        $this->passFail(
            'Crypto packages installed',
            $ok,
            'expected simplito/elliptic-php + kornrunner/keccak'
        );
    }

    protected function checkKeccak(): void
    {
        try {
            // keccak256("") — the canonical Ethereum empty hash.
            $expected = 'c5d2460186f7233c927e7db2dcc703c0e500b653ca82273b7bfad8045d85a470';
            $actual = EthereumCrypto::keccakHex('');
            $this->passFail(
                'keccak256("") vector',
                $actual === $expected,
                $actual === $expected ? $actual : 'expected ' . $expected . ', got ' . $actual
            );
        } catch (\Throwable $e) {
            $this->passFail('keccak256("") vector', false, $e->getMessage());
        }
    }

    protected function checkAddressDerivation(): void
    {
        try {
            // privkey = 1 → the well-known first secp256k1 account.
            $priv = str_pad('1', 64, '0', STR_PAD_LEFT);
            $expected = '0x7e5f4552091a69125d5dfcb7b8c2659029395bdf';
            $actual = EthereumCrypto::addressFromPrivateKey($priv);
            $this->passFail(
                'Address derivation (privkey=1)',
                $actual === $expected,
                $actual === $expected ? $actual : 'expected ' . $expected . ', got ' . $actual
            );
        } catch (\Throwable $e) {
            $this->passFail('Address derivation (privkey=1)', false, $e->getMessage());
        }
    }

    protected function checkEip155Signing(): void
    {
        try {
            $priv = str_repeat('46', 32);
            $raw = EthTxSigner::signTransfer(
                $priv,
                9,
                '20000000000',
                21000,
                '0x3535353535353535353535353535353535353535',
                '1000000000000000000', // 1 ETH value (arbitrary — vector only needs the signature)
                '',
                1
            );

            $expected = '0xf86c098504a817c800825208943535353535353535353535353535353535353535'
                . '880de0b6b3a764000080'
                . '25'
                . 'a028ef61340bd939bc2195fe537567866003e1a15d3c71ff63e1590620aa636276'
                . 'a067cbe9d8997f761aecb703304b3800ccf555c9f3dc64214b297fb1966a3b6d83';

            $this->passFail(
                'EIP-155 legacy signing vector',
                $raw === $expected,
                $raw === $expected ? $raw : 'expected ' . $expected . ' got ' . $raw
            );
        } catch (\Throwable $e) {
            $this->passFail('EIP-155 legacy signing vector', false, $e->getMessage());
        }
    }

    protected function checkWeiMath(): void
    {
        $ok = true;
        $detail = [];

        $checks = [
            'toWei("1.5")' => WeiMath::toWei('1.5', 18) === '1500000000000000000',
            'fromWeiFloor(1.5e18)' => WeiMath::fromWeiFloor('1500000000000000000', 8, 18) === '1.5',
            'decToHex(1.5e18)' => WeiMath::decToHex('1500000000000000000') === '14d1120d7b160000',
            'hexToDec roundtrip' => WeiMath::hexToDec('0x14d1120d7b160000') === '1500000000000000000',
            'decimal dust floor' => WeiMath::fromWeiFloor('1', 8, 18) === '0.00000000' || WeiMath::fromWeiFloor('1', 8, 18) === '0',
        ];

        foreach ($checks as $label => $passed) {
            $ok = $ok && $passed;
            if (!$passed) {
                $detail[] = $label;
            }
        }

        $this->passFail('WeiMath string arithmetic', $ok, $ok ? 'all vectors matched' : implode(', ', $detail));
    }

    protected function checkSeed(): void
    {
        try {
            $seed = Keyring::seedHex();
            $this->passFail('Master seed present', true, 'seed file configured (do NOT print it)');
            $this->passFail('Treasury derivable from seed', true, Keyring::treasuryAddress());
        } catch (\Throwable $e) {
            $this->passFail('Master seed present', false, $e->getMessage());
        }
    }

    protected function passFail(string $label, bool $ok, string $detail = ''): void
    {
        if ($ok) {
            $this->line('  <info>✓</info> ' . $label . ($detail !== '' ? ' — ' . $detail : ''));
        } else {
            $this->failures++;
            $this->line('  <error>✗</error> ' . $label . ($detail !== '' ? ' — ' . $detail : ''));
        }
    }
}
